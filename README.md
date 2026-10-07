# Laravel Fawaterk

Security-first Laravel integration for the [Fawaterk](https://fawaterk.com) payment gateway.

> **Unofficial.** This package is not made, endorsed or supported by Fawaterk.
>
> **Status: in development.** Not ready for production. The first stable release will be `v1.0.0`.

## Why another package

A payment integration is only as good as the check that decides "this order is paid". This package is built around
that check:

- **Webhooks are verified the way Fawaterk actually signs them.** The package checks the HMAC-SHA256 `hashKey` /
  `transactionHashKey` with your vendor API key. Verification cannot be switched off.
- **A webhook is a trigger, not proof.** Fawaterk's signature does not cover the payment status or amount. So after
  the signature check, the package asks Fawaterk's API for the transaction and acts only on that answer.
- **The amount must match.** The paid total and currency are compared with what you recorded at checkout, in minor
  units (piasters), never as floats.
- **Paid once, fulfilled at least once.** Payments move through a forward-only state machine under a row lock, so a
  replayed webhook cannot pay twice. The paid event is re-sent until your app confirms it fulfilled the order, so make
  your listeners idempotent.
- **Nothing is exposed by installing it.** No routes are registered until you ask for them, and no request or webhook
  bodies, tokens or customer data are logged.
- **API v3 with OAuth 2.0.** The access token is cached encrypted. The API host is fixed to Fawaterk's own domains.

## Requirements

- PHP 8.2+
- Laravel 9.52+, 10, 11, 12 or 13
- A cache store whose locks work across processes: redis, database, memcached or dynamodb (file on a single server)
- A database with row locks for the ledger. MySQL 5.7+ and MariaDB 10.6+ are tested with concurrent processes;
  PostgreSQL should work but is not tested yet. SQLite has no row locks: use it for tests only. Multi-primary MySQL
  (Galera, Group Replication) is not supported.

## Installation

```bash
composer require biztecheg/laravel-fawaterk
php artisan fawaterk:install
```

`fawaterk:install` publishes `config/fawaterk.php` and the migration, then prints what you add by hand: the `.env`
keys, the routes and the schedule. It never writes `.env`, never migrates, and runs `fawaterk:doctor --offline` at
the end.

If your payable models do not use integer keys, set `fawaterk.ledger.payable_key_type` (`uuid`, `ulid` or
`string`) **before** migrating. Then run `php artisan migrate`.

### `.env`

```dotenv
FAWATERK_ENV=staging                 # or live
FAWATERK_CLIENT_ID=                  # Fawaterk → Integrations → OAuth client credentials
FAWATERK_CLIENT_SECRET=
FAWATERK_VENDOR_API_KEY=             # signs webhooks: treat it as a payment credential
FAWATERK_APP_URL=                    # optional, an https origin; defaults to APP_URL
FAWATERK_RESULT_KEY=                 # optional, 32+ random characters; defaults to a key derived from APP_KEY
FAWATERK_ALERT_MAIL=ops@example.com  # anomaly mail, comma-separated
FAWATERK_CACHE_STORE=redis           # optional, a store with locks
```

Secrets have no defaults in code and are checked when first used, never at boot: installing the package cannot break
an app whose `.env` is not filled in yet.

Other settings, all optional (`config/fawaterk.php` explains each one):

| Key | Default | What it sets |
|---|---|---|
| `FAWATERK_TIMEOUT`, `FAWATERK_CONNECT_TIMEOUT` | `20`, `5` | API timeouts in seconds |
| `FAWATERK_LOG_CHANNEL` | none | a log channel for metadata (never bodies, tokens or customer data) |
| `FAWATERK_PROVIDER_TIMEZONE` | `Africa/Cairo` | how Fawaterk's dates without a timezone are read |
| `FAWATERK_DEFAULT_PROFILE` | `hosted` | the payment profile a checkout uses when it names none |
| `FAWATERK_CODE_VALIDITY` | `due_date` | how long a reference code counts as valid (see "Start a checkout") |
| `FAWATERK_COMMISSION` | `merchant` | who pays Fawaterk's commission (see "Good to know") |
| `FAWATERK_FULFILMENT` | `manual` | `manual` (you call `markFulfilled()`) or `after_listeners` |
| `FAWATERK_REREAD_FAILED`, `FAWATERK_REREAD_REFUND` | `true` | re-read failed and refund webhooks (paid ones always are) |
| `FAWATERK_RESULT_BACK_URL`, `FAWATERK_RESULT_TIMEZONE` | none | the result page's back link and display timezone |
| `FAWATERK_ALERT_QUEUE` | none | queue the anomaly mail on this connection instead of sending it after the response |
| `FAWATERK_DB_CONNECTION` | default | the database connection of the ledger tables |

### Routes

Nothing is registered until you add it:

```php
// routes/api.php (Laravel 9/10) or routes/web.php (Laravel 11+): the URLs for Fawaterk's dashboard
Route::fawaterkWebhooks('fawaterk/webhooks');

// routes/web.php: the page Fawaterk sends the payer back to
Route::fawaterk('fawaterk');
```

The webhook route is CSRF-free and throttled per IP by the `fawaterk-webhooks` limiter. Both macros accept
`['domain' => …, 'middleware' => […], 'name' => …]`, and the routes work under any name prefix and with
`route:cache`. `fawaterk:doctor` prints the four webhook URLs to paste into Fawaterk's dashboard (Webhook, Failed,
Cancellation, Refund).

**Behind a proxy or Cloudflare**, configure Laravel's `TrustProxies` so the per-IP limiters see the real client IP.
Otherwise every request shares the proxy's IP and one limit.

### Schedule

```php
// routes/console.php (Laravel 11+). On Laravel 9/10 use $schedule->command(...) in app/Console/Kernel.php.
use Illuminate\Support\Facades\Schedule;

Schedule::command('fawaterk:reconcile')->everyFiveMinutes()->withoutOverlapping(15);
Schedule::command('model:prune', ['--model' => [\BiztechEG\Fawaterk\Webhooks\WebhookEvent::class]])->daily();
```

`fawaterk:reconcile` catches what webhooks miss. It re-reads open payments, expires old ones, re-sends `PaymentPaid`
until your app has delivered, confirms refunds, and re-sends alerts a crash left unsent.

## Taking a payment

### 1. Make your model payable

Any Eloquent model can be paid for. The amount always comes from your own data, on the server:

```php
use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Contracts\Payable;
use BiztechEG\Fawaterk\Data\CartItem;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\Customer;
use BiztechEG\Fawaterk\Ledger\HasFawaterkPayments;

class Order extends Model implements Payable
{
    use HasFawaterkPayments;

    public function toFawaterkCheckout(CheckoutContext $context): CreateTransaction
    {
        return new CreateTransaction(
            cartTotalMinor: $this->total_minor,          // piasters: 150.00 EGP = 15000
            customer: new Customer($this->user->first_name, $this->user->last_name, $this->user->email),
            cartItems: [new CartItem("Order {$this->number}", $this->total_minor)],
        );
    }

    public function fawaterkFingerprint(): array
    {
        // What the payment buys, and nothing your delivery changes (such as a status).
        return ['number' => $this->number, 'total' => $this->total_minor, 'user' => $this->user_id];
    }
}
```

**The fingerprint** is hashed at checkout and compared when the money arrives. If the order changed in between (a
different total, another plan), the payment is flagged `order_changed` and your app is told, instead of delivering
something that was not paid for. Put in it only what was bought. A field your own delivery changes, such as a status,
makes a re-sent `PaymentPaid` look like a changed order.

### 2. Start a checkout

```php
use BiztechEG\Fawaterk\Facades\Fawaterk;

$result = Fawaterk::checkout($order);                    // or $order->fawaterkCheckout()

return $result->isCode()
    ? view('orders.pay-code', ['code' => $result->referenceNumber, 'expires' => $result->expiresAt])
    : redirect()->away($result->url);
```

`CheckoutResult` is safe to return as JSON (`kind`, `payment_uuid`, `url`, `reference_number`, `expires_at`,
`reused`). A second checkout of the same order reuses a live link or code instead of creating another one.

**Profiles** decide how a checkout is offered (`config/fawaterk.php`):

```php
'methods' => [
    'fawry' => ['id' => ['staging' => 3, 'live' => 12]],  // ids differ per environment
    'card'  => ['name_en' => 'Visa-Mastercard'],
],
'profiles' => [
    'hosted' => ['kind' => 'hosted'],                                           // Fawaterk's page, every method
    'fawry'  => ['kind' => 'method', 'method' => 'fawry', 'due_after' => 2880], // a Fawry reference code
    'card'   => ['kind' => 'method', 'method' => 'card'],                       // Fawaterk's page, card preselected
],
```

```php
Fawaterk::checkout($order, 'fawry');
Fawaterk::checkout($order, new CheckoutContext(profile: 'hosted', purpose: 'deposit', locale: 'ar'));
Fawaterk::checkout($order, new CheckoutContext(profile: 'fawry', overrides: ['due_after' => 1440])); // this code: 24 hours
```

**How long a code counts as valid** (`code_validity`, for Fawry, Aman and Masary codes): `due_date`, the default, is
the due date asked for (`due_after`), never past the code's own expiry; `code_expiry` is the code's own expiry at the
outlet (Fawry gives four days whatever the due date), so no second code is made while the first can still be paid.
Set it in `fawaterk.code_validity`, per profile, or per checkout in `overrides`. Fawaterk's `expires_in` (two hours
whatever the due date) is not used for codes; links still end at the earlier of the due date and `expires_in`.

A **purpose** allows several payments for one payable: a deposit and the balance, instalments, top-ups. Each
`(payable, purpose)` is paid at most once. A checkout for a purpose already paid throws `AlreadyPaidException`.

Do not start a checkout inside a database transaction: a rollback would leave the payer a live link the ledger does
not know. The package refuses it (`CheckoutInTransactionException`).

### 3. Deliver on `PaymentPaid`

```php
use BiztechEG\Fawaterk\Events\PaymentPaid;

Event::listen(function (PaymentPaid $event) {
    $delivered = $event->payment->fulfilOnce(function ($payment) {
        $payment->payable->markAsPaid();   // database writes only
    });

    if ($delivered) {
        Mail::to($event->payment->payable->user)->send(new OrderPaid($event->payment->payable));
    }
});
```

`PaymentPaid` is sent after the database commit, and **sent again** by `fawaterk:reconcile` (after 10, 15, 30 and 60
minutes, then every 180 minutes) until the payment is marked fulfilled. Your listener must therefore be idempotent.
Two ways to do it:

- **`fulfilOnce(Closure)`** runs your delivery under the payment's row lock, in one database transaction with
  `fulfilled_at`, at most once per payment. Keep the closure **short and database-only**: anything outside the
  database (mail, HTTP, another database) may run again if the transaction is retried or fails. Send mail after it
  returns `true`, as above.
- **`markFulfilled()`**, when you keep your own idempotency: call it once delivery is done.

With `FAWATERK_FULFILMENT=after_listeners`, the package marks the payment fulfilled when every listener returned
without an exception. Use synchronous listeners for that, and make sure one exists: with no listener every payment
would be marked delivered with nothing delivered (`fawaterk:doctor` fails in that case). A listener that throws never
fails the webhook: the failure is reported and the event is sent again.

`$event->late` is `true` when the money arrived after the payment had expired or its checkout had failed. It is still
paid and still delivered, and you are alerted.

### Events

| Event | When |
|---|---|
| `PaymentPaid` | a re-read says paid, the amount matches and the order is unchanged |
| `PaymentPending` | Fawaterk knows a transaction for the checkout: a reference code was issued, or the payer started an attempt on a link (a declined card included) |
| `PaymentAmountMismatch` | paid, but not the expected total or currency (blocking) |
| `PaymentPaidTwice` | a second payment for a `(payable, purpose)` already paid (blocking) |
| `PaymentOrderChanged` | paid, but the fingerprint changed or the payable is gone, `payableMissing` (blocking) |
| `PaymentUnfulfilled` | paid, and not delivered after 30 minutes or 5 attempts |
| `PaymentRefunded` | a refund confirmed in Fawaterk's refund list |
| `PaymentRefundReported` | a refund webhook the refund list does not confirm (also a replayed refund webhook) |
| `PaymentFailureReported`, `PaymentCancelReported` | Fawaterk reported a failure or a cancellation; unproven, so only a re-check |
| `PaymentExpired` | the time to pay ran out and a re-read confirmed it is unpaid |
| `UnknownPaymentPaid` | a signed paid webhook for a checkout this app did not create |
| `RefundWebhookMisrouted` | a signed refund webhook reached another webhook URL (the dashboard's Refund field is wrong); not applied, and reconcile's daily read of the refund list counts the refund. Raised once per refund at each URL |

A blocking flag settles the payment: its event fires once and `PaymentPaid` is never sent for it. Events other than
`PaymentPaid` are **best-effort**: if your listener fails, the flag stays on the payment (`$payment->flags`).
`fawaterk:doctor` lists blocking flags and unconfirmed refunds of the last 30 days, paid checkouts this app did not
create, refund webhooks that reached another webhook URL, and payments still not delivered. Reconcile also reads
the refund list once a day (`reconcile.refund_list_scan_hours`, the first `refund_scan_pages` pages, newest first)
for refunds no webhook announced; it is off when `reread.refund` is off. When you upgrade, its first run counts the
approved refunds of your paid payments already in those pages, with a `PaymentRefunded` for each.

## Result pages

With `Route::fawaterk()` registered, checkouts send Fawaterk a result URL signed in its path
(`/fawaterk/result/{payment}/{expires}/{signature}`) as the success, fail, pending and back URL, unless a profile sets
its own `return_urls`. The page:

- re-reads the payment and applies the answer, so it also catches a late webhook. Anyone holding the URL can load it,
  so re-reads are bounded: every 10 seconds for a payment's first 30 re-reads in a day, then every 5 minutes, and at
  most 60 a minute for the whole account (`fawaterk.results.*`). Past that, the page shows what the ledger knows.
- shows only the state, the amount and the reference: no customer data and nothing about the order
- needs no session, ignores the query string Fawaterk appends, and sends `Referrer-Policy: no-referrer`,
  `Cache-Control: no-store` and a strict Content-Security-Policy
- answers JSON to `Accept: application/json`, for SPAs and mobile apps. Branch on `state` (`paid`, `under_review`,
  `refunded`, `processing`, `unconfirmed`, `awaiting_payment`, `not_completed`, `expired`, `failed`). `paid` is
  `true` only for the `paid` state; `received` is also `true` when the money arrived but the payment is under review or
  refunded.
- follows the app locale, with English and Arabic (right to left) included, and shows times with their UTC offset
  (`FAWATERK_RESULT_TIMEZONE` picks the zone)

```php
// Where "Back to the site" goes. Otherwise: the profile's return_urls.back, FAWATERK_RESULT_BACK_URL, then APP_URL.
Fawaterk::resultBackUrlUsing(fn (FawaterkPayment $payment) => route('orders.show', $payment->payable_id, false));

// Or send the payer to your own page after the re-read. Your page must check who may see the order.
Fawaterk::resultRedirectUsing(fn (FawaterkPayment $payment) => route('orders.thanks', $payment->payable_id, false));

// A signed result URL, for example for an email.
Fawaterk::resultUrl($payment);
```

Only paths on your site, and https URLs on your app's host or a host in `fawaterk.return_url_hosts`, are used; anything
else is ignored, so the page is never an open redirect. (A relative route, as above, keeps working behind a proxy
whatever scheme the request came in with.)

Register `Route::fawaterk()` and `Route::fawaterkWebhooks()` outside route groups with parameters (such as
`{locale}`): the package builds their URLs from `FAWATERK_APP_URL` and the route alone, and says so if it cannot.

Result URLs stay valid for `fawaterk.results.valid_days` (30) after the payment's due date, and a link is reused only
while its result URL has at least a day left. They are signed with `FAWATERK_RESULT_KEY`, or with a key derived from `APP_KEY`, in which
case rotating `APP_KEY` invalidates the result URLs already given out.

To change the page, publish the views or the translations: `php artisan vendor:publish --tag=fawaterk-views` (or
`--tag=fawaterk-lang`). Loosen `fawaterk.results.content_security_policy` if your views load assets.

## Anomaly mail

By default, `FAWATERK_ALERT_MAIL` gets a mail for the blocking events (`PaymentAmountMismatch`, `PaymentPaidTwice`,
`PaymentOrderChanged`) and the operations events (`PaymentUnfulfilled`, `UnknownPaymentPaid`,
`PaymentRefundReported`, `RefundWebhookMisrouted`). The mail holds ledger facts and ids, never customer data.

- It is sent **after the response**: after the webhook has answered Fawaterk, the page has been sent, or the command
  or queue job has ended. A slow mail server never holds up a webhook or a reconcile run.
- It never fails anything. A failed send is reported to your exception handler, and the flag stays on the payment.
  There is no mail retry. A process killed at that very moment loses the mail, not the flag: `fawaterk:doctor` still
  lists it.
- It names the payable by its type and key (`App\Models\Order #123`) and the purpose. If your keys or purposes can hold
  customer data (an email as a key), route the mail somewhere that may see it.
- `fawaterk.notifications.events` changes the list. `FAWATERK_ALERT_QUEUE` queues the mail on that connection
  instead (a worker must run).
- To route it yourself: `Fawaterk::routeNotificationsUsing(fn (object $event) => User::role('finance')->get());`.
  Return notifiables, addresses, or `null` for none.

## Refunds

Refunds are made in Fawaterk's dashboard. A refund webhook is only a trigger: the package looks the refund up in
Fawaterk's refund list, counts each refund id once and fires `PaymentRefunded`. A refund the list does not confirm
within the watch window (6 hours) is flagged `refund_unverified` and reported once, and amounts never change.

A replayed or duplicated refund webhook can also cause that report. It cannot be told from a second refund of the
same amount that is not listed yet, and a replay can mean someone holds a signed webhook.

## Commands

| Command | What it does |
|---|---|
| `fawaterk:install` | publishes the config and the migration, and prints the `.env` keys, routes and schedule. `--force` overwrites an existing `config/fawaterk.php`; `--no-doctor` skips the doctor at the end |
| `fawaterk:doctor` | checks the config, routes, cache locks and tables, a `PaymentPaid` listener, the reconcile heartbeat, today's rejected webhooks, what the anomaly mail reported and (unless `--offline`) the account's methods and commission. `--probe` also POSTs to your webhook URLs, which must answer 401. Exits 1 on any failure (warnings do not); secrets are never printed |
| `fawaterk:reconcile` | the scheduled safety net (see above). `--limit=100` caps the rows handled per section in one run |
| `fawaterk:simulate {paid\|failed\|cancel\|refund} {payment}` | runs a webhook in-process against the fake; refused on live and in production. `--amount=50.00` sets a refund's amount (default: the paid amount); `--force` does not ask first |

## Testing your app

```php
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Testing\SignedWebhook;

$fake = Fawaterk::fake();                       // no credentials, no HTTP

$result = Fawaterk::checkout($order);
$payment = $order->fawaterkPayments()->first();

$fake->markPaid($payment->intent_key);
// The path you registered: /api/fawaterk/webhooks/... when the route is in routes/api.php.
$this->postJson('/fawaterk/webhooks/'.SignedWebhook::paid($payment->intent_key)->segment(), SignedWebhook::paid($payment->intent_key)->toArray())->assertOk();

$fake->assertCreatedCount(1);
```

`SignedWebhook` signs with `fawaterk.vendor_api_key`. `->with([...])` adds unsigned fields, and `->signedWith($key)`
signs with another key. The fake also lets you script re-reads, method lists and refund pages.

`Fawaterk::fake()` throws when `FAWATERK_ENV=live` or `APP_ENV=production`: a fake answers whatever it is told, and
every "paid" re-read would trust it.

## Good to know

- **EGP only** in v1.0. `taxData` and `discountData` are not supported: put taxes and discounts into your own cart
  total.
- **Commission.** `FAWATERK_COMMISSION=merchant` means you absorb Fawaterk's commission. With `customer` it is added
  to the expected total, and with `auto` it is added only for methods that charge it to the customer.
  `fawaterk:doctor` warns about methods that do not fit your mode.
- **Laravel 9 and savepoints.** On Laravel 9, a sibling savepoint rolled back inside your own transaction can drop the
  package's after-commit callbacks. `PaymentPaid` and the alerts then come back through reconcile. Where you can,
  change payments outside your own transactions.
- **Superseded reference codes stay payable** at Fawaterk; there is no API to void them. A second payment is flagged
  `paid_twice` and mailed. Refund it in the dashboard.
- **A code can be paid after its due date.** At the outlet a Fawry or Aman code may live longer than the `due_date`
  sent (24 hours on the accounts tried), and Fawaterk takes the payment. It is still recorded as paid; when the
  payment had already expired in the ledger it gets the `late_payment` flag and `PaymentPaid::$late` is `true`.
- **Your own payment model.** `Fawaterk::usePaymentModel(MyPayment::class)` (in a service provider) makes the package
  use a subclass of `FawaterkPayment`, for example to add relations or casts.
- **Change payments only through the package**: checkout, webhooks, reconcile, `markFulfilled()` and `fulfilOnce()`.
  A status written by hand skips every check.

## Security model

What the package defends against, and how:

| Threat | Defence |
|---|---|
| A forged webhook | HMAC with the vendor key, compared in constant time, with no switch to turn it off |
| A real webhook edited or replayed | only signed ids find the payment; the status is always re-read from Fawaterk's API; transitions happen under row locks; `paid` never goes back |
| Paying less than the price | the re-read total is compared with the expected total, in minor units |
| An order edited after checkout | the fingerprint: `order_changed` instead of `PaymentPaid` |
| Credentials sent elsewhere | fixed API hosts per environment, no redirects followed, no host taken from requests |
| Secrets in logs or tools | the package's own HTTP client (no framework HTTP events for Telescope and the like), an encrypted token cache, a log allow-list, exceptions without bodies |
| Guessing result pages | an HMAC signature and an expiry in the path, and no customer data on the page |
| Webhook floods | a per-IP limiter, a size limit, single-flight re-reads per payment, and rejected webhooks counted rather than stored |

Not covered: a stolen vendor key or OAuth client (rotate them in Fawaterk's dashboard), and an attacker with write
access to your database or code.

## Security

Please report vulnerabilities privately. See [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
