<?php

namespace BiztechEG\Fawaterk\Testing;

use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\IntentKey;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentLink;
use BiztechEG\Fawaterk\Data\PaymentData\ReferenceCode;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Data\TransactionIntent;
use BiztechEG\Fawaterk\Exceptions\TransactionNotFoundException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * An in-memory Fawaterk for your tests: `$fake = Fawaterk::fake();`
 *
 * createTransaction returns a hosted link, or a reference code when a
 * payment_method_id is sent with redirectOption=false. Every created intent
 * starts unpaid; mark it paid with markPaid().
 */
final class FawaterkFake implements FawaterkClient
{
    /** @var list<CreateTransaction> */
    private array $created = [];

    /** @var array<string, TransactionData> */
    private array $transactions = [];

    /** @var list<PaymentMethod> */
    private array $methods = [];

    /** @var array<int, RefundPage> */
    private array $refundPages = [];

    private ?Closure $createUsing = null;

    private int $paymentMethodCalls = 0;

    private ?Closure $getUsing = null;

    private ?Throwable $methodsFailure = null;

    private ?Throwable $refundsFailure = null;

    private int $getTransactionCalls = 0;

    private int $refundPageCalls = 0;

    private ?CacheRepository $cache = null;

    /**
     * The cache the method resolver uses while this fake is active: its own
     * memory store, so fake data never reaches the app's real cache.
     *
     * @internal
     */
    public function cache(): CacheRepository
    {
        return $this->cache ??= new Repository(new ArrayStore);
    }

    public function createTransaction(CreateTransaction $request): TransactionIntent
    {
        $this->created[] = $request;

        $intent = $this->createUsing !== null
            ? ($this->createUsing)($request)
            : $this->defaultIntent($request);

        $this->transactions[$intent->intentKey] ??= new TransactionData(
            intentKey: $intent->intentKey,
            transactionId: 0,
            paid: false,
            totalMinor: $request->cartTotalMinor,
            currency: $request->currency,
            commissionMinor: null,
            paymentMethod: null,
            statusText: 'unpaid',
            paidAt: null,
        );

        return $intent;
    }

    public function getTransaction(string $intentKey, ?int $timeout = null): TransactionData
    {
        $this->getTransactionCalls++;

        if ($this->getUsing !== null) {
            $answer = ($this->getUsing)(IntentKey::normalize($intentKey) ?? $intentKey);

            if ($answer instanceof TransactionData) {
                return $answer;
            }
        }

        return $this->stored($intentKey);
    }

    /**
     * Script re-reads: return a TransactionData, throw (for example a
     * ServiceUnavailableException), or return null to use the stored answer.
     *
     * @param  Closure(string): ?TransactionData  $callback  receives the lowercased intent key
     */
    public function getTransactionUsing(?Closure $callback): self
    {
        $this->getUsing = $callback;

        return $this;
    }

    /**
     * How many times getTransaction() was called: a bad webhook must cause none.
     */
    public function getTransactionCalls(): int
    {
        return $this->getTransactionCalls;
    }

    private function stored(string $intentKey): TransactionData
    {
        return $this->transactions[IntentKey::normalize($intentKey) ?? $intentKey]
            ?? throw new TransactionNotFoundException('Invalid intent_key or transaction not found', 422);
    }

    public function getPaymentMethods(): array
    {
        $this->paymentMethodCalls++;

        if ($this->methodsFailure !== null) {
            throw $this->methodsFailure;
        }

        return $this->methods;
    }

    public function refundPage(int $page = 1): RefundPage
    {
        $this->refundPageCalls++;

        if ($this->refundsFailure !== null) {
            throw $this->refundsFailure;
        }

        return $this->refundPages[$page] ?? new RefundPage($page, max(1, count($this->refundPages)), []);
    }

    /**
     * Make getPaymentMethods() throw (null restores it), for example a ServiceUnavailableException.
     */
    public function failPaymentMethods(?Throwable $failure): self
    {
        $this->methodsFailure = $failure;

        return $this;
    }

    /**
     * Make refundPage() throw (null restores it).
     */
    public function failRefundPages(?Throwable $failure): self
    {
        $this->refundsFailure = $failure;

        return $this;
    }

    /**
     * @param  Closure(CreateTransaction): TransactionIntent  $callback
     */
    public function createTransactionUsing(Closure $callback): self
    {
        $this->createUsing = $callback;

        return $this;
    }

    public function setTransaction(TransactionData $transaction): self
    {
        $this->transactions[$transaction->intentKey] = $transaction;

        return $this;
    }

    /**
     * Make getTransaction() report the intent as paid.
     */
    public function markPaid(
        string $intentKey,
        ?int $totalMinor = null,
        ?int $transactionId = null,
        string $paymentMethod = 'Visa-Mastercard',
        ?int $commissionMinor = null,
    ): self {
        $current = $this->stored($intentKey);

        $this->transactions[$current->intentKey] = new TransactionData(
            intentKey: $current->intentKey,
            transactionId: $transactionId ?? random_int(1000, 999999),
            paid: true,
            totalMinor: $totalMinor ?? $current->totalMinor,
            currency: $current->currency,
            commissionMinor: $commissionMinor,
            paymentMethod: $paymentMethod,
            statusText: 'paid',
            // Fawaterk writes it in its own timezone, with no offset.
            paidAt: (new DateTimeImmutable('now', new DateTimeZone(self::providerTimezone())))->format('Y-m-d H:i:s'),
            references: $current->references,
        );

        return $this;
    }

    public function setPaymentMethods(PaymentMethod ...$methods): self
    {
        $this->methods = array_values($methods);
        $this->cache()->flush();

        return $this;
    }

    public function setRefundPage(RefundPage $page): self
    {
        $this->refundPages[$page->currentPage] = $page;

        return $this;
    }

    /**
     * @return list<CreateTransaction>
     */
    public function created(): array
    {
        return $this->created;
    }

    public function paymentMethodCalls(): int
    {
        return $this->paymentMethodCalls;
    }

    /**
     * How many refund-list pages were requested.
     */
    public function refundPageCalls(): int
    {
        return $this->refundPageCalls;
    }

    /**
     * @param  (Closure(CreateTransaction): bool)|null  $callback
     */
    public function assertTransactionCreated(?Closure $callback = null): void
    {
        $matching = $callback === null ? $this->created : array_filter($this->created, $callback);

        Assert::assertNotEmpty($matching, 'The expected Fawaterk transaction was not created.');
    }

    public function assertNothingCreated(): void
    {
        Assert::assertEmpty($this->created, 'Fawaterk transactions were created unexpectedly.');
    }

    public function assertCreatedCount(int $count): void
    {
        Assert::assertCount($count, $this->created, "Expected {$count} Fawaterk transaction(s).");
    }

    private function defaultIntent(CreateTransaction $request): TransactionIntent
    {
        // The short opaque key the API gives, not the UUID of its reference.
        $intentKey = Str::lower(Str::random(18));

        $paymentData = $request->paymentMethodId !== null && $request->redirectOption === false
            ? new ReferenceCode((string) random_int(100000000, 999999999), new DateTimeImmutable('+2 days'))
            : new PaymentLink("https://fawaterk.test/ts/{$intentKey}");

        return new TransactionIntent($intentKey, $paymentData, 2592000);
    }

    private static function providerTimezone(): string
    {
        $zone = function_exists('config') ? config('fawaterk.provider_timezone') : null;

        return is_string($zone) && $zone !== '' ? $zone : 'Africa/Cairo';
    }
}
