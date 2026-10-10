# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.1] - 2026-10-10

### Fixed

- `fawaterk:doctor` now fails a Basata method in link mode (`redirect=true`), as it does for Fawry, Aman and Masary.
  Its name pattern said `basta`, so a Basata method that returns a link instead of a code passed the check.

## [1.0.0] - 2026-10-07

First release.

### Added

- Package skeleton: service provider, configuration without secret defaults, test setup, CI.
- API v3 client: `createTransaction`, `getTransaction`, `getPaymentMethods` and `refundPage`, with:
  - OAuth tokens cached encrypted, for at most 24 hours
  - no redirects followed, and no retries except one after a 401
  - typed exceptions that never keep the underlying HTTP exception, and `outcomeUnknown` when a request may have
    been processed
- Money in integer minor units; request DTOs that validate before sending; payment data (link, reference code,
  wallet request) parsed by shape.
- `MethodResolver`: payment methods by an id per environment, or by an exact English name.
- `Fawaterk::fake()` with assertions. It needs no credentials.
- Webhooks (`Route::fawaterkWebhooks()`), opt-in, CSRF-free, throttled:
  - the signature is checked over the raw body (JSON or form, at most 200 form fields)
  - a paid webhook is only a reason to re-read the payment, at most once per intent every 10 seconds
  - a failed or cancel webhook only schedules a re-check; it never changes the status (`PaymentFailureReported`,
    `PaymentCancelReported`)
  - a refund is watched until Fawaterk's refund list confirms it; amounts come only from that list, and one still
    unconfirmed when its window ends is flagged and reported once (`PaymentRefundReported`)
  - legacy invoice payloads are answered 200
  - the webhook log holds metadata of verified webhooks only; rejected ones are counted per day in the cache
- Payments ledger (publishable migration; configurable connection, table prefix and payable key type):
  - every change runs under a row lock, and events are sent after commit (once, even when a commit is retried)
  - blocking flags: amount mismatch, paid twice, order changed, payable missing; the order is checked again before
    every `PaymentPaid`
  - commission modes: merchant, customer, auto
  - at-least-once fulfilment with `markFulfilled()`, or exactly-once delivery with `fulfilOnce()`
  - alerts are committed with what they report, and sent again by reconcile after a crash
  - decision reads use the primary database, and rows of the other environment (staging or live) are never touched
  - works with `Date::use(CarbonImmutable::class)`
- Checkout (`Fawaterk::checkout()`, `HasFawaterkPayments`):
  - payment profiles: hosted page, reference codes, card-preselected link
  - live links and codes are reused (never after a failure report), and an already-paid payable is refused
  - refused inside an open DB transaction, or with an `array` or `null` cache store, when the real client is used
- `fawaterk:reconcile`: re-checks with back-off, expiry (then a daily re-read for a week, for late payments), refund
  checks, alerts left unsent, re-sends `PaymentPaid` until fulfilled, a time budget per run, heartbeat.
- `fawaterk:simulate`: runs a signed webhook in-process with the fake. Refused on live, in production and for rows of
  the other environment; asks first.
- `Testing\SignedWebhook` for app tests.
- Result pages (`Route::fawaterk()`), opt-in:
  - the URL is signed in its path with `FAWATERK_RESULT_KEY` (or a key derived from `APP_KEY`) and expires; the
    query string Fawaterk appends is ignored
  - checkouts send it to Fawaterk as the success, fail, pending and back URL, unless a profile sets its own
  - each visit re-reads the payment (at most every 10 seconds) and applies the answer
  - only the state, the amount and the reference are shown; JSON on request; English and Arabic (right to left);
    `no-store`, `no-referrer` and a strict Content-Security-Policy
  - `Fawaterk::resultBackUrlUsing()`, `Fawaterk::resultRedirectUsing()` and `Fawaterk::resultUrl()`
- Anomaly mail (`PaymentAnomalyNotification`) for the blocking and operations events, to `FAWATERK_ALERT_MAIL` or
  through `Fawaterk::routeNotificationsUsing()`. It is sent after the response (or queued), holds no customer data
  and never fails the caller.
- `fawaterk:install`: publishes the config and the migration (once), and prints the `.env` keys, routes and schedule.
- `fawaterk:doctor`: checks the config, routes, cache locks, tables and key type, the reconcile heartbeat, rejected
  webhooks, payments that need a person, the account's methods and commission, and whether Fawaterk accepts the
  vendor API key (read-only v2 method list; a wrong key otherwise shows only as every webhook refused).
  `--offline`, `--probe`; exits 1 on a failure; never prints secrets.
- Tests against real MySQL and MariaDB with concurrent worker processes (`--testsuite MySql`).
- What the API really answers, checked on staging and live accounts:
  - intent keys are short opaque strings (for example `kd7rwmxqoltbv3ezsa`), not the UUIDs of the API reference;
    both are accepted: a UUID is lowercased, any other key (8 to 36 letters, digits, `_` or `-`) is kept as it came
  - a reference code's expiry comes as `08 Oct 2026, 04:42 PM` or `2021-07-06 15:53:41`; impossible dates are
    refused
  - an older account shape of the method list (`paymentId`, no `commission_on_customer`) is read too
- Reference-code validity (`code_validity`): until the due date asked for, never past the code's own expiry
  (`due_date`, the default), or until the code's own expiry (`code_expiry`); set globally, per profile or per
  checkout. Fawaterk's `expires_in` describes its payment page and is not used for codes.
- Refunds whose webhook is lost or reaches another URL: a signed refund body at the paid, failed or cancel URL is
  reported once (`RefundWebhookMisrouted`, and a `fawaterk:doctor` warning), and reconcile reads the refund list once
  a day (`reconcile.refund_list_scan_hours`) for approved refunds of your payments that no webhook announced. Only
  transaction refunds count; an unreadable entry of the list is skipped, never the whole page.
- `Fawaterk::fake()` is refused when `FAWATERK_ENV=live` or `APP_ENV=production`, and `markFulfilled()` refuses a
  payment with a blocking flag, like `fulfilOnce()`.

[Unreleased]: https://github.com/BiztechEG/laravel-fawaterk/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/BiztechEG/laravel-fawaterk/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/BiztechEG/laravel-fawaterk/releases/tag/v1.0.0
