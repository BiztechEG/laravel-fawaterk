<?php

use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentOrderChanged;
use BiztechEG\Fawaterk\Events\PaymentPaidTwice;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\PaymentUnfulfilled;
use BiztechEG\Fawaterk\Events\RefundWebhookMisrouted;
use BiztechEG\Fawaterk\Events\UnknownPaymentPaid;

/*
|--------------------------------------------------------------------------
| Fawaterk
|--------------------------------------------------------------------------
|
| Secrets have no defaults on purpose. Put them in your .env only. They are
| checked when first used, never at boot, so installing the package cannot
| break an app whose .env is not filled in yet.
|
*/

return [

    /*
     | "staging" (https://staging.fawaterk.com) or "live" (https://app.fawaterk.com).
     | The base URL is picked from this value and is fixed in code: there is no
     | setting that could send your credentials to another server.
     */
    'environment' => env('FAWATERK_ENV', 'staging'),

    /*
     | OAuth 2.0 client credentials (Fawaterk dashboard → Integrations →
     | OAuth client credentials). Used for every /api/v3 call.
     */
    'client_id' => env('FAWATERK_CLIENT_ID'),
    'client_secret' => env('FAWATERK_CLIENT_SECRET'),

    /*
     | Vendor API key. Fawaterk signs its webhooks with it (HMAC-SHA256).
     | Treat it as a payment credential.
     */
    'vendor_api_key' => env('FAWATERK_VENDOR_API_KEY'),

    'http' => [
        'timeout' => (int) env('FAWATERK_TIMEOUT', 20),
        'connect_timeout' => (int) env('FAWATERK_CONNECT_TIMEOUT', 5),
    ],

    /*
     | Cache store for the OAuth access token (stored encrypted, at most 24 h)
     | and the payment-method list. null = your default store.
     */
    'cache_store' => env('FAWATERK_CACHE_STORE'),

    /*
     | Log channel for metadata only (never bodies, tokens or customer data).
     | null = no logging.
     */
    'log_channel' => env('FAWATERK_LOG_CHANNEL'),

    /*
     | Payment methods used by your profiles, by a name of your choice.
     | Prefer an explicit id per environment (ids differ between staging and
     | live, so a single id is refused):
     |
     |     'fawry' => ['id' => ['staging' => 3, 'live' => 12]],
     |
     | or an exact English name from Fawaterk's method list:
     |
     |     'card' => ['name_en' => 'Visa-Mastercard'],
     |
     | A name that matches no method, or more than one, is an error.
     */
    'methods' => [],

    /*
     | How long the payment-method list is cached, in seconds.
     */
    'methods_cache_ttl' => 600,

    /*
     | Fawaterk returns some dates without a timezone (reference-code expiry).
     | They are read in this timezone.
     */
    'provider_timezone' => env('FAWATERK_PROVIDER_TIMEZONE', 'Africa/Cairo'),

    /*
     | The https origin Fawaterk reaches your app on, used to build webhook and
     | result URLs (never taken from the request's Host header). Defaults to
     | APP_URL.
     */
    'app_url' => env('FAWATERK_APP_URL'),

    /*
     | Payment profiles: how a checkout is offered.
     |
     |     'hosted' => ['kind' => 'hosted'],                               // Fawaterk's page, every enabled method
     |     'fawry'  => ['kind' => 'method', 'method' => 'fawry', 'due_after' => 2880],  // a Fawry reference code
     |     'card'   => ['kind' => 'method', 'method' => 'card'],          // Fawaterk's page, card preselected
     |
     | Options: lang (ar|en), list_style (h|v), due_after (minutes), send_email,
     | send_sms, reuse (default true), return_urls (success, fail, pending, back;
     | https, on your APP_URL host or a host in return_url_hosts), code_validity.
     | Each can also be set for one checkout: CheckoutContext overrides.
     */
    'profiles' => [
        'hosted' => ['kind' => 'hosted'],
    ],

    /*
     | How long a reference code (Fawry, Aman, Masary) counts as valid: what is
     | shown as its expiry, how long it binds what it pays for, and when asking
     | again makes a new code instead of returning it.
     |
     |     due_date     until the due date asked for (due_after), and never past
     |                  the code's own expiry
     |     code_expiry  until the code's own expiry at the outlet (Fawry gives
     |                  four days), whatever the due date: no second code while
     |                  the first can still be paid
     |
     | Fawaterk's expires_in (two hours, whatever the due date) is not used for
     | codes: it describes its payment page. A profile, or one checkout, can set
     | its own code_validity.
     */
    'code_validity' => env('FAWATERK_CODE_VALIDITY', 'due_date'),

    'default_profile' => env('FAWATERK_DEFAULT_PROFILE', 'hosted'),

    /*
     | Extra hosts that per-checkout return URLs may point at.
     */
    'return_url_hosts' => [],

    /*
     | Reference codes are reused only while at least this many minutes remain.
     */
    'reuse' => [
        'min_remaining_minutes' => 60,
    ],

    /*
     | Seconds a checkout waits for another checkout of the same payable and
     | purpose to finish before CheckoutInProgressException.
     */
    'checkout_lock_wait' => 15,

    /*
     | Who pays Fawaterk's commission, which decides the total a payment must
     | reach: merchant (you absorb it), customer (it is added), or auto (added
     | only for methods that charge it to the customer).
     */
    'commission' => env('FAWATERK_COMMISSION', 'merchant'),

    /*
     | manual: your PaymentPaid listener calls $payment->markFulfilled().
     | after_listeners: the package marks it fulfilled when every listener
     | returns without an exception (use synchronous listeners for this).
     | Until fulfilled, reconcile sends PaymentPaid again.
     */
    'fulfilment' => env('FAWATERK_FULFILMENT', 'manual'),

    /*
     | Paid webhooks are always re-read (the signature does not cover the
     | status). Failed and refund webhooks are re-read unless switched off.
     */
    'reread' => [
        'failed' => (bool) env('FAWATERK_REREAD_FAILED', true),
        'refund' => (bool) env('FAWATERK_REREAD_REFUND', true),
    ],

    'webhooks' => [
        // Requests per minute per IP for the "fawaterk-webhooks" limiter.
        'rate_limit' => 120,
        // Failed and refund webhooks for the same payment re-read at most this often.
        'cooldown_seconds' => 60,
    ],

    'reconcile' => [
        'expiry_grace_minutes' => 30,
        'alert_after_minutes' => 30,
        'alert_after_attempts' => 5,
        // PaymentPaid is first sent again after this many minutes (a queued listener may not have run yet), then
        // after 15, 30, 60 and every 180 minutes until the payment is fulfilled.
        'first_redispatch_minutes' => 10,
        'refund_scan_pages' => 5,
        // A refund webhook is checked against the refund list for this long; what is still not listed is then
        // flagged refund_unverified and reported once.
        'refund_watch_hours' => 6,
        // The refund list is also read every this many hours (newest first, refund_scan_pages pages) for refunds of
        // paid payments that no webhook announced: a lost webhook, or one sent to another webhook URL. 0 = never.
        'refund_list_scan_hours' => 24,
        // A run stops after this many seconds (and when Fawaterk is down); the rest waits for the next run.
        'max_seconds' => 240,
    ],

    /*
     | Result pages (register them with Route::fawaterk()). Fawaterk sends the
     | payer back to a URL signed in its path; the page re-reads the payment
     | and shows only its state, amount and reference.
     */
    'results' => [
        // HMAC key for result URLs (at least 32 characters). null = derived from APP_KEY, so rotating APP_KEY
        // breaks the result URLs already given out.
        'key' => env('FAWATERK_RESULT_KEY'),
        // A result URL stays valid this many days after the payment's due date (or its creation).
        'valid_days' => 30,
        // Where "Back to the site" goes when neither Fawaterk::resultBackUrlUsing() nor the profile's
        // return_urls.back gives a URL. null = your app URL.
        'back_url' => env('FAWATERK_RESULT_BACK_URL'),
        // Requests per minute per IP for the "fawaterk-results" limiter.
        'rate_limit' => 60,
        // A visit re-reads the payment at most every 10 seconds for its first this-many re-reads in a day, then at
        // most every 5 minutes (reconcile keeps checking either way).
        'fast_rereads' => 30,
        // Re-reads from result pages per minute for the whole account. Above it, pages show what the ledger knows.
        'rereads_per_minute' => 60,
        // The time zone the page shows times in (with their UTC offset). null = the app's.
        'timezone' => env('FAWATERK_RESULT_TIMEZONE'),
        // Sent with the HTML page. Loosen it if your published views load assets; null sends none.
        'content_security_policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
    ],

    /*
     | Anomaly mail (never customer data). Sent after the response (the webhook's
     | answer, the page, the end of a command or a queue job) and never allowed to
     | fail anything: a failed send is reported to your exception handler, and the
     | flag stays on the payment.
     */
    'notifications' => [
        // Comma-separated addresses. Empty = no mail (the events still fire). Fawaterk::routeNotificationsUsing()
        // replaces it at runtime.
        'mail' => env('FAWATERK_ALERT_MAIL'),
        // The events that send it.
        'events' => [
            PaymentAmountMismatch::class,
            PaymentPaidTwice::class,
            PaymentOrderChanged::class,
            PaymentUnfulfilled::class,
            UnknownPaymentPaid::class,
            PaymentRefundReported::class,
            RefundWebhookMisrouted::class,
        ],
        // null = send after the response. A queue connection name queues the mail instead (a worker must run).
        'queue' => env('FAWATERK_ALERT_QUEUE'),
    ],

    /*
     | The ledger tables (published migration). Set payable_key_type before
     | migrating: int, uuid, ulid or string, as your payable models use.
     */
    'ledger' => [
        'connection' => env('FAWATERK_DB_CONNECTION'),
        'table_prefix' => 'fawaterk_',
        'payable_key_type' => 'int',
    ],

    /*
     | Webhook log rows (metadata only) are pruned after this many days by
     | `php artisan model:prune`.
     */
    'webhook_log_days' => 30,

];
