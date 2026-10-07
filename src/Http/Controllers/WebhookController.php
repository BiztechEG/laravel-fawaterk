<?php

namespace BiztechEG\Fawaterk\Http\Controllers;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Support\SafeLog;
use BiztechEG\Fawaterk\Webhooks\InvalidWebhookException;
use BiztechEG\Fawaterk\Webhooks\Outcome;
use BiztechEG\Fawaterk\Webhooks\RawPayload;
use BiztechEG\Fawaterk\Webhooks\SignatureVerifier;
use BiztechEG\Fawaterk\Webhooks\WebhookEvent;
use BiztechEG\Fawaterk\Webhooks\WebhookProcessor;
use BiztechEG\Fawaterk\Webhooks\WebhookType;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Fawaterk's webhooks:
 *
 * 1. at most 64 KB
 * 2. the raw body is parsed (JSON or form), so no middleware changes it
 * 3. a legacy invoice payload is checked with the invoice formula: 200 or 401;
 *    a refund payload at another URL with the refund formula: 200 (not applied, raised once) or 401
 * 4. the signed fields must be present and well-formed, else 401
 * 5. the signature must match, else 401; nothing from the body is kept
 * 6-8. lookup by signed ids, re-read, apply (WebhookProcessor)
 *
 * Answers never echo the request. Only verified webhooks are written to the
 * webhook log; rejected ones are counted per day in the cache, so a flood of
 * junk cannot fill the table.
 */
final class WebhookController
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly WebhookProcessor $processor,
        private readonly SafeLog $log,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * The cache key holding one day's count of rejected webhooks (read by fawaterk:doctor).
     */
    public static function rejectedCountKey(string $account, string $environment, ?string $day = null): string
    {
        return "fawaterk:{$account}:{$environment}:webhooks:rejected:".($day ?? Carbon::now()->format('Y-m-d'));
    }

    public function __invoke(Request $request, string $type, ?string $account = null): Response
    {
        $webhookType = WebhookType::fromSegment($type);
        $account ??= 'default';

        if ($webhookType === null || $account !== 'default') {
            return new Response('', 404);
        }

        $length = $request->headers->get('Content-Length');

        if (is_numeric($length) && (int) $length > RawPayload::MAX_BYTES) {
            return $this->reject($webhookType, $account, new Outcome('malformed', 413));
        }

        $body = $request->getContent();

        if (strlen($body) > RawPayload::MAX_BYTES) {
            return $this->reject($webhookType, $account, new Outcome('malformed', 413));
        }

        $payload = RawPayload::parse($body);

        if ($payload === null) {
            return $this->reject($webhookType, $account, new Outcome('malformed', 401));
        }

        $verifier = new SignatureVerifier($this->accounts->get($account));

        try {
            if ($verifier->isInvoicePayload($webhookType, $payload)) {
                $verifier->verifyInvoice($payload);

                return $this->finish($webhookType, $account, new Outcome('foreign'));
            }

            if ($verifier->isRefundPayload($webhookType, $payload)) {
                $refund = $verifier->verify(WebhookType::Refund, $payload);

                return $this->finish($webhookType, $account, $this->processor->misrouted($refund, $webhookType, $account));
            }

            $webhook = $verifier->verify($webhookType, $payload);
        } catch (InvalidWebhookException $e) {
            return $this->reject($webhookType, $account, new Outcome($e->outcome, 401));
        }

        return $this->finish($webhookType, $account, $this->processor->process($webhook, $account));
    }

    /**
     * A verified webhook: written to the webhook log.
     */
    private function finish(WebhookType $type, string $account, Outcome $outcome): Response
    {
        try {
            WebhookEvent::query()->create([
                'account' => $account,
                'environment' => $this->accounts->get($account)->environment->value,
                'type' => $type->value,
                'outcome' => $outcome->outcome,
                'intent_key' => $outcome->intentKey,
                'dedupe_key' => $outcome->dedupeKey,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->respond($type, $outcome);
    }

    /**
     * Too large, malformed or badly signed: only counted.
     */
    private function reject(WebhookType $type, string $account, Outcome $outcome): Response
    {
        try {
            $key = self::rejectedCountKey($account, $this->accounts->get($account)->environment->value);
            $this->cache->add($key, 0, 172800);
            $this->cache->increment($key);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->respond($type, $outcome);
    }

    private function respond(WebhookType $type, Outcome $outcome): Response
    {
        $this->log->info('webhook', ['type' => $type->value, 'outcome' => $outcome->outcome, 'http_status' => $outcome->status]);

        return new Response($outcome->status === 200 ? 'OK' : '', $outcome->status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
