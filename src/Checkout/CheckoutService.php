<?php

namespace BiztechEG\Fawaterk\Checkout;

use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Contracts\Payable;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentLink;
use BiztechEG\Fawaterk\Data\PaymentData\ReferenceCode;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Data\RedirectionUrls;
use BiztechEG\Fawaterk\Data\TransactionIntent;
use BiztechEG\Fawaterk\Exceptions\AlreadyPaidException;
use BiztechEG\Fawaterk\Exceptions\CheckoutInProgressException;
use BiztechEG\Fawaterk\Exceptions\CheckoutInTransactionException;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Exceptions\FawaterkException;
use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use BiztechEG\Fawaterk\Exceptions\ValidationException;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Fingerprint;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Methods\MethodResolver;
use BiztechEG\Fawaterk\Results\ResultUrls;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Webhooks\WebhookType;
use BiztechEG\Fawaterk\Webhooks\WebhookUrls;
use Closure;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Starts a payment for a payable: Fawaterk::checkout($order, $context).
 *
 * Under a lock on (payable, purpose) it reuses a live link or code when the
 * rules allow, refuses when the payable is already paid, or inserts
 * a ledger row, creates the Fawaterk transaction (outside any DB transaction)
 * and fills the row in.
 */
final class CheckoutService
{
    /**
     * @param  Closure(): FawaterkClient  $client
     * @param  Closure(): MethodResolver  $methods
     * @param  array<string, mixed>  $config  the "fawaterk" config array
     */
    public function __construct(
        private readonly Closure $client,
        private readonly Closure $methods,
        private readonly PaymentRecorder $recorder,
        private readonly CacheRepository $cache,
        private readonly Credentials $credentials,
        private readonly WebhookUrls $webhookUrls,
        private readonly ResultUrls $resultUrls,
        private readonly array $config,
    ) {}

    public function checkout(Model $payable, CheckoutContext $context = new CheckoutContext): CheckoutResult
    {
        if (! $payable instanceof Payable) {
            throw new InvalidRequestException('The payable must implement '.Payable::class.'.');
        }

        if (! $payable->exists) {
            throw new InvalidRequestException('Save the payable before starting a checkout.');
        }

        // A rolled-back transaction would leave the customer a live link or code
        // the ledger no longer knows. The fake creates nothing real, so tests
        // that wrap everything in a transaction are exempt.
        $inTransaction = Ledger::newPayment()->getConnection()->transactionLevel() > 0
            || $payable->getConnection()->transactionLevel() > 0;

        if ($inTransaction && ! $this->isFake()) {
            throw new CheckoutInTransactionException;
        }

        $profile = $this->profile($context);
        $store = $this->cache->getStore();

        // null grants every lock, and array locks only work inside one process.
        // The fake creates nothing real, so apps' tests may use either.
        $useless = ($store instanceof NullStore || $store instanceof ArrayStore) && ! $this->isFake();

        if (! $store instanceof LockProvider || $useless) {
            throw new ConfigurationException('The Fawaterk cache store must support locks across processes: use redis, database, memcached or dynamodb (file on a single server).');
        }

        $key = implode(':', ['fawaterk:checkout', $this->credentials->account, $this->credentials->environment->value, $payable->getMorphClass(), (string) $payable->getKey(), $context->purpose]);

        try {
            // Longer than the slowest checkout: the method list, a token, the create
            // (with one 401 retry) and one re-read, each up to the HTTP timeout.
            $ttl = max(120, 6 * $this->credentials->timeout + 30);

            return $store->lock($key, $ttl)->block(max(1, (int) ($this->config['checkout_lock_wait'] ?? 15)), fn () => $this->locked($payable, $context, $profile));
        } catch (LockTimeoutException) {
            throw new CheckoutInProgressException;
        }
    }

    private function locked(Model&Payable $payable, CheckoutContext $context, Profile $profile): CheckoutResult
    {
        // Both sides of the fingerprint comparison are loaded from the database
        // (the primary: a lagging replica could show an old order).
        $fresh = $payable->newQueryWithoutScopes()->useWritePdo()->whereKey($payable->getKey())->first();

        if (! $fresh instanceof Payable) {
            throw new InvalidRequestException('The payable no longer exists.');
        }

        if (method_exists($fresh, 'trashed') && $fresh->trashed()) {
            throw new InvalidRequestException('The payable is deleted.');
        }

        $fingerprint = Fingerprint::of($fresh);
        $request = $fresh->toFawaterkCheckout($context);
        $method = $profile->method === null ? null : ($this->methods)()->resolve($profile->method);
        $rows = $this->rows($fresh, $context->purpose);

        $paid = $rows->first(fn (FawaterkPayment $row) => $row->status->isPaid());
        if ($paid !== null) {
            throw new AlreadyPaidException($paid);
        }

        if ($profile->reuse) {
            $reusable = $this->reusable($rows, $profile, $method, $request->cartTotalMinor, $fingerprint);

            if ($reusable !== null) {
                return CheckoutResult::fromPayment($reusable, true);
            }
        }

        return $this->create($fresh, $context, $profile, $method, $request, $fingerprint);
    }

    private function create(
        Model&Payable $payable,
        CheckoutContext $context,
        Profile $profile,
        ?PaymentMethod $method,
        CreateTransaction $request,
        string $fingerprint,
    ): CheckoutResult {
        // The uuid is known before the row exists: the result URL sent to Fawaterk carries it.
        $uuid = (string) Str::uuid();
        $final = $this->finalRequest($request, $context, $profile, $method, $uuid);

        $row = Ledger::newPayment();
        $row->forceFill([
            'uuid' => $uuid,
            'account' => $this->credentials->account,
            'environment' => $this->credentials->environment->value,
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'purpose' => $context->purpose,
            'profile' => $profile->name,
            'payment_method_id' => $method?->id,
            'amount_minor' => $final->cartTotalMinor,
            'currency' => $final->currency,
            'status' => PaymentStatus::Created,
            'order_fingerprint' => $fingerprint,
        ])->save();

        try {
            $intent = ($this->client)()->createTransaction($final);
        } catch (ValidationException $e) {
            // A rejected method id may mean the cached list is stale.
            ($this->methods)()->forget();
            $this->failCreate($row, 'create_rejected');

            throw $e;
        } catch (ServiceUnavailableException $e) {
            $this->failCreate($row, $e->outcomeUnknown ? 'create_outcome_unknown' : 'create_failed');

            throw $e;
        } catch (UnexpectedResponseException $e) {
            $this->failCreate($row, 'unexpected_response');

            throw $e;
        } catch (FawaterkException $e) {
            $this->failCreate($row, 'create_failed');

            throw $e;
        }

        $this->fill($row, $intent, $final, $profile);

        if ($intent->paymentData instanceof ReferenceCode) {
            // A direct dispatch already has a transaction id: capture it now.
            $this->captureTransactionId($row);
        }

        return CheckoutResult::fromPayment($row, false);
    }

    private function fill(FawaterkPayment $row, TransactionIntent $intent, CreateTransaction $request, Profile $profile): void
    {
        $data = $intent->paymentData;

        if (! $data instanceof PaymentLink && ! $data instanceof ReferenceCode) {
            // Wallet requests arrive in v1.1; nothing here asks for one.
            $row->forceFill(['intent_key' => $intent->intentKey])->save();
            $this->failCreate($row, 'unexpected_response');

            throw new UnexpectedResponseException('Fawaterk returned a payment kind this version does not handle.');
        }

        $now = Carbon::now();
        $referenceExpiry = $data instanceof ReferenceCode && $data->expiresAt !== null ? self::appTime($data->expiresAt) : null;
        $due = $request->dueDate === null ? null : self::appTime($request->dueDate);
        $page = $intent->expiresIn === null ? null : $now->copy()->addSeconds($intent->expiresIn);

        if ($data instanceof ReferenceCode) {
            // expires_in (two hours whatever the due date, on the staging API) is Fawaterk's page, not the code: the
            // code is paid at the outlet. It counts only when nothing else is known.
            $expiries = $profile->codeValidity === Profile::CODE_EXPIRY
                ? array_filter([$referenceExpiry ?? $due])
                : array_filter([$due, $referenceExpiry]);
            $expiries = $expiries === [] ? array_filter([$page]) : $expiries;
        } else {
            $expiries = array_filter([$due, $page]);
        }

        $row->forceFill([
            'intent_key' => $intent->intentKey,
            'checkout_url' => $data instanceof PaymentLink ? $data->url : null,
            'reference' => $data instanceof ReferenceCode ? $data->referenceNumber : null,
            'reference_expires_at' => $referenceExpiry,
            'expires_at' => $expiries === [] ? null : min($expiries),
            'next_check_at' => $now->copy()->addMinutes(5),
        ])->save();
    }

    /**
     * Eloquent stores the wall time it is given, so dates in Fawaterk's
     * timezone are converted to the app's first.
     */
    private static function appTime(\DateTimeInterface $date): Carbon
    {
        return Carbon::instance($date)->setTimezone(date_default_timezone_get());
    }

    private function captureTransactionId(FawaterkPayment $row): void
    {
        try {
            $this->recorder->applyReRead($row, ($this->client)()->getTransaction((string) $row->intent_key, 8));
        } catch (FawaterkException) {
            // Reconcile captures it later.
        }
    }

    private function failCreate(FawaterkPayment $row, string $reason): void
    {
        $row->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => $reason])->save();
    }

    /**
     * The package decides the method, the redirect behaviour, the URLs and
     * the due date; the payable decides the amount, the items and the customer.
     */
    private function finalRequest(CreateTransaction $request, CheckoutContext $context, Profile $profile, ?PaymentMethod $method, string $uuid): CreateTransaction
    {
        $urls = $profile->returnUrls;
        $dueDate = $profile->dueAfter === null ? $request->dueDate : new DateTimeImmutable('+'.$profile->dueAfter.' minutes');

        // With Route::fawaterk() registered, the payer comes back to the signed
        // result page, unless the profile names its own page.
        $result = $this->resultUrls->for($uuid, $dueDate);

        return $request->with([
            'paymentMethodId' => $method?->id,
            'redirectOption' => $method === null ? null : $method->redirect,
            'mobileWalletNumber' => null,
            'lang' => $profile->lang ?? (in_array($context->locale, ['ar', 'en'], true) ? $context->locale : $request->lang),
            'listStyle' => $profile->listStyle ?? $request->listStyle,
            'dueDate' => $dueDate,
            'sendEmail' => $profile->sendEmail,
            'sendSms' => $profile->sendSms,
            'redirectionUrls' => new RedirectionUrls(
                successUrl: $urls['success'] ?? $result,
                failUrl: $urls['fail'] ?? $result,
                pendingUrl: $urls['pending'] ?? $result,
                backUrl: $urls['back'] ?? $result,
                webhookUrl: $this->webhookUrls->for(WebhookType::Paid, $this->credentials->account),
            ),
        ]);
    }

    /**
     * Links are reused only while created, unexpired, for the same
     * amount and order, with no failure report. Codes also need time left, a
     * fresh "not paid" re-read and no pending cancel report.
     *
     * @param  Collection<int, FawaterkPayment>  $rows  newest first
     */
    private function reusable(Collection $rows, Profile $profile, ?PaymentMethod $method, int $amountMinor, string $fingerprint): ?FawaterkPayment
    {
        $now = Carbon::now();
        $minutes = max(0, (int) ($this->config['reuse']['min_remaining_minutes'] ?? 60));

        foreach ($rows as $row) {
            if ($row->intent_key === null
                || $row->hasFlag(Flag::FailureReported)
                || $row->profile !== $profile->name
                || $row->payment_method_id !== $method?->id
                || $row->amount_minor !== $amountMinor
                || ! hash_equals($row->order_fingerprint, $fingerprint)) {
                continue;
            }

            if ($row->checkout_url !== null) {
                if ($row->status === PaymentStatus::Created && ($row->expires_at === null || $row->expires_at->gt($now->copy()->addMinutes(5))) && $this->resultUrlLasts($row, $now)) {
                    return $row;
                }

                continue;
            }

            // The validity recorded at checkout (code_validity), not the outlet's own expiry: past it a new code is made.
            $expiry = $row->expires_at ?? $row->reference_expires_at;

            if ($row->reference !== null
                && in_array($row->status, [PaymentStatus::Created, PaymentStatus::Pending], true)
                && ! $row->hasFlag(Flag::CancelReported)
                && $expiry !== null && $expiry->gte($now->copy()->addMinutes($minutes))) {
                // One re-read at most: the newest candidate, or a new code.
                return $this->stillUnpaid($row) ? $row : null;
            }
        }

        return null;
    }

    /**
     * The result URL sent to Fawaterk with a link must still work when the
     * payer comes back: it lasts valid_days after the checkout at least, and a
     * link is reused only while a day of that is left.
     */
    private function resultUrlLasts(FawaterkPayment $row, Carbon $now): bool
    {
        if ($row->created_at === null || ! $this->resultUrls->registered()) {
            return true;
        }

        return $row->created_at->copy()->addDays($this->resultUrls->validDays())->gt($now->copy()->addDay());
    }

    private function stillUnpaid(FawaterkPayment $row): bool
    {
        try {
            $row = $this->recorder->applyReRead($row, ($this->client)()->getTransaction((string) $row->intent_key, 8));
        } catch (FawaterkException) {
            return false; // unsure: issue a new code instead
        }

        if ($row->status->isPaid()) {
            throw new AlreadyPaidException($row);
        }

        return true;
    }

    /**
     * This environment's rows for (payable, purpose), from the primary: a
     * lagging replica could hide a paid row or a reusable link.
     *
     * @return Collection<int, FawaterkPayment>
     */
    private function rows(Model $payable, string $purpose): Collection
    {
        return Ledger::newPayment()->newQuery()
            ->useWritePdo()
            ->where('account', $this->credentials->account)
            ->where('environment', $this->credentials->environment->value)
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->where('purpose', $purpose)
            ->orderByDesc('id')
            ->get();
    }

    private function isFake(): bool
    {
        return ($this->client)() instanceof FawaterkFake;
    }

    private function profile(CheckoutContext $context): Profile
    {
        $name = $context->profile ?? (string) ($this->config['default_profile'] ?? 'hosted');
        $profiles = (array) ($this->config['profiles'] ?? []);

        if (! isset($profiles[$name]) || ! is_array($profiles[$name])) {
            throw new ConfigurationException("Payment profile [{$name}] is not configured in fawaterk.profiles.");
        }

        $hosts = array_values(array_unique(array_filter([
            ...array_map(fn ($host) => strtolower((string) $host), (array) ($this->config['return_url_hosts'] ?? [])),
            $this->webhookUrls->host(),
        ])));

        // fawaterk.code_validity is every profile's default; a profile and a call can each change it.
        $defaults = ['code_validity' => $this->config['code_validity'] ?? Profile::DUE_DATE];

        return Profile::fromConfig($name, array_filter($profiles[$name], fn ($value) => $value !== null) + $defaults, $context->overrides, $hosts);
    }
}
