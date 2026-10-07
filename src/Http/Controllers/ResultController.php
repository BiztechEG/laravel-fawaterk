<?php

namespace BiztechEG\Fawaterk\Http\Controllers;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\RedirectionUrls;
use BiztechEG\Fawaterk\Exceptions\FawaterkException;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Results\ResultPage;
use BiztechEG\Fawaterk\Results\ResultSigner;
use BiztechEG\Fawaterk\Results\ResultUrls;
use BiztechEG\Fawaterk\Support\Callbacks;
use BiztechEG\Fawaterk\Webhooks\WebhookUrls;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Throwable;

/**
 * The page Fawaterk sends the payer back to.
 *
 * The URL is signed in its path, so it needs no session and ignores the
 * query string Fawaterk adds. A visit re-reads the payment and applies the
 * answer, so the page doubles as a safety net for a late webhook. It shows
 * only the state, the amount and the reference: never customer data or
 * anything about the payable.
 *
 * Re-reads are bounded, because anyone holding a result URL can load it:
 * every 10 seconds for a payment's first burst of re-reads (a day), then
 * every 5 minutes, and never more than fawaterk.results.rereads_per_minute
 * for the whole account. Above that the page shows what the ledger knows.
 *
 * The router keeps one controller instance, so services and config are
 * resolved per request.
 */
final class ResultController
{
    private const REREAD_TIMEOUT = 8;

    private const FAST_INTERVAL = 10;

    private const SLOW_INTERVAL = 300;

    public function __construct(private readonly Container $app) {}

    public function __invoke(Request $request): BaseResponse
    {
        // Read by name: route parameters are passed to controllers by position.
        $uuid = self::parameter($request, ResultUrls::PAYMENT);
        $expires = self::parameter($request, ResultUrls::EXPIRES);
        $signature = self::parameter($request, ResultUrls::SIGNATURE);

        if (! $this->app->make(ResultSigner::class)->verify($uuid, $expires, $signature, Carbon::now()->getTimestamp())) {
            return $this->invalid($request, 403);
        }

        // Fawaterk may send the payer back with a POST. Answer with the same URL
        // as a GET, so reloading the page does not post again. The URL is built
        // from config, never from the request (a trusted X-Forwarded-Prefix).
        if ($request->isMethod('POST')) {
            $route = $request->route();

            return $route instanceof Route
                ? $this->secure(new RedirectResponse($this->app->make(ResultUrls::class)->signed($route, $uuid, (int) $expires, $signature), 303))
                : $this->invalid($request, 404);
        }

        $row = $this->find($uuid);

        if ($row === null) {
            return $this->invalid($request, 404);
        }

        [$row, $live] = $this->reRead($row);
        $page = ResultPage::of($row, $this->backUrl($row), $live);

        if ($request->wantsJson()) {
            return $this->secure(new JsonResponse($page));
        }

        $redirect = $this->redirectUrl($row);

        if ($redirect !== null) {
            return $this->secure(new RedirectResponse($redirect, 303));
        }

        return $this->html($this->app->make('view')->make('fawaterk::results.show', [
            'page' => $page,
            'rtl' => $this->rtl(),
            'timezone' => $this->timezone(),
        ])->render(), 200);
    }

    private function find(string $uuid): ?FawaterkPayment
    {
        // From the primary, and only this environment's rows (like webhooks).
        return Ledger::newPayment()->newQuery()
            ->useWritePdo()
            ->where('uuid', $uuid)
            ->where('environment', $this->credentials()->environment->value)
            ->first();
    }

    /**
     * Paid never goes back, so paid rows are not re-read. When Fawaterk cannot
     * be reached, or the budget is spent, the page shows what the ledger knows.
     *
     * @return array{0: FawaterkPayment, 1: bool} the row, and whether the page still re-reads it often
     */
    private function reRead(FawaterkPayment $row): array
    {
        if ($row->intent_key === null || $row->status->isPaid() || $row->account !== $this->credentials()->account) {
            return [$row, false];
        }

        $cache = $this->app->make('cache')->store($this->config('cache_store'));

        if (! $cache instanceof CacheRepository) {
            return [$row, false];
        }

        $prefix = "fawaterk:{$row->account}:{$row->environment}";
        $id = hash('sha256', $row->intent_key);
        $countKey = "{$prefix}:result-reads:{$id}";
        $lastKey = "{$prefix}:result-read-at:{$id}";
        $burst = max(0, (int) $this->config('results.fast_rereads'));
        $done = (int) $cache->get($countKey, 0);
        $interval = $done < $burst ? self::FAST_INTERVAL : self::SLOW_INTERVAL;
        $last = $cache->get($lastKey);
        $now = Carbon::now()->getTimestamp();

        // The interval counts from the last re-read; the flight key keeps two
        // concurrent visits from both re-reading.
        if ((is_numeric($last) && $now - (int) $last < $interval)
            || ! $cache->add("{$prefix}:cooldown:result-flight:{$id}", 1, self::FAST_INTERVAL)) {
            return [$row, $done < $burst];
        }

        if (! $this->withinAccountCap($cache, $prefix)) {
            return [$row, $done < $burst];
        }

        $cache->put($lastKey, $now, 86400);
        $cache->add($countKey, 0, 86400);
        $done = (int) $cache->increment($countKey);

        try {
            $recorder = $this->app->make(PaymentRecorder::class);
            $row = $recorder->applyReRead($row, $this->app->make(FawaterkClient::class)->getTransaction($row->intent_key, self::REREAD_TIMEOUT));

            // The payer just came back: if Fawaterk has not settled it yet,
            // reconcile looks again in 2 minutes. Only once: later visits must
            // not override reconcile's back-off.
            if ($done === 1 && ! $row->status->isPaid()) {
                $row = $recorder->recheckSoon($row, 2);
            }
        } catch (FawaterkException) {
            // Unreachable or unknown: show what the ledger knows.
        } catch (Throwable $e) {
            report($e);
        }

        return [$row, $done < $burst];
    }

    private function withinAccountCap(CacheRepository $cache, string $prefix): bool
    {
        $key = "{$prefix}:result-reads-per-minute:".Carbon::now()->format('YmdHi');
        $cache->add($key, 0, 120);

        return (int) $cache->increment($key) <= max(1, (int) $this->config('results.rereads_per_minute'));
    }

    /**
     * Fawaterk::resultBackUrlUsing(), then the profile's return_urls.back,
     * then fawaterk.results.back_url, then the app's own URL.
     */
    private function backUrl(FawaterkPayment $row): ?string
    {
        $callback = $this->app->make(Callbacks::class)->resultBackUrl;
        $profile = $this->config('profiles.'.$row->profile);
        $candidates = [
            fn () => $callback === null ? null : $callback($row),
            fn () => is_array($profile) ? ($profile['return_urls']['back'] ?? null) : null,
            fn () => $this->config('results.back_url'),
            fn () => $this->app->make(WebhookUrls::class)->base().'/',
        ];

        foreach ($candidates as $candidate) {
            try {
                $url = $this->safeUrl($candidate());
            } catch (Throwable $e) {
                if (! $e instanceof FawaterkException) {
                    report($e);
                }

                continue;
            }

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function redirectUrl(FawaterkPayment $row): ?string
    {
        $callback = $this->app->make(Callbacks::class)->resultRedirect;

        if ($callback === null) {
            return null;
        }

        try {
            return $this->safeUrl($callback($row));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * A path on this site, or an https URL on the app's host or a host in
     * fawaterk.return_url_hosts (the same rule as profiles' return URLs).
     * Anything else (other hosts, javascript:, protocol-relative, http) is
     * ignored, so the page is never an open redirect.
     */
    private function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        if (! RedirectionUrls::isHttps($url)) {
            return null;
        }

        $hosts = array_filter([
            $this->app->make(WebhookUrls::class)->host(),
            ...array_map(fn ($host) => strtolower((string) $host), (array) $this->config('return_url_hosts')),
        ]);

        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true) ? $url : null;
    }

    private function invalid(Request $request, int $status): BaseResponse
    {
        if ($request->wantsJson()) {
            return $this->secure(new JsonResponse(['error' => 'invalid_link'], $status));
        }

        return $this->html($this->app->make('view')->make('fawaterk::results.invalid', ['rtl' => $this->rtl()])->render(), $status);
    }

    private function html(string $content, int $status): BaseResponse
    {
        $response = new Response($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        $policy = $this->config('results.content_security_policy');

        if (is_string($policy) && $policy !== '') {
            $response->headers->set('Content-Security-Policy', $policy);
        }

        return $this->secure($response);
    }

    private function secure(BaseResponse $response): BaseResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Vary', 'Accept');

        return $response;
    }

    private function rtl(): bool
    {
        return in_array(substr((string) $this->app->make('translator')->getLocale(), 0, 2), ['ar', 'fa', 'he', 'ur'], true);
    }

    /**
     * The zone the page shows times in: fawaterk.results.timezone, or the app's.
     */
    private function timezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) ($this->config('results.timezone') ?: date_default_timezone_get()));
        } catch (Throwable) {
            return new DateTimeZone(date_default_timezone_get());
        }
    }

    private static function parameter(Request $request, string $name): string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : '';
    }

    private function credentials(): Credentials
    {
        return $this->app->make(AccountRepository::class)->get();
    }

    private function config(string $key): mixed
    {
        return $this->app->make('config')->get('fawaterk.'.$key);
    }
}
