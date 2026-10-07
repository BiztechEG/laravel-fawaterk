<?php

namespace BiztechEG\Fawaterk;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Checkout\CheckoutResult;
use BiztechEG\Fawaterk\Checkout\CheckoutService;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Methods\MethodResolver;
use BiztechEG\Fawaterk\Results\ResultUrls;
use BiztechEG\Fawaterk\Support\Callbacks;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The package's entry point (also available as the Fawaterk facade).
 */
final class Fawaterk
{
    public function __construct(private readonly Container $app) {}

    /**
     * Start a payment (a link or a reference code) for a payable model.
     */
    public function checkout(Model $payable, CheckoutContext|string|null $context = null): CheckoutResult
    {
        return $this->app->make(CheckoutService::class)->checkout(
            $payable,
            is_string($context) ? CheckoutContext::profile($context) : ($context ?? new CheckoutContext),
        );
    }

    public function client(): FawaterkClient
    {
        return $this->app->make(FawaterkClient::class);
    }

    public function methods(): MethodResolver
    {
        return $this->app->make(MethodResolver::class);
    }

    /**
     * @param  class-string<FawaterkPayment>  $class
     */
    public function usePaymentModel(string $class): void
    {
        Ledger::usePaymentModel($class);
    }

    /**
     * The signed result page URL of a payment, valid for
     * fawaterk.results.valid_days from now (or from $from). Null when the app
     * has not registered Route::fawaterk().
     */
    public function resultUrl(FawaterkPayment $payment, ?DateTimeInterface $from = null): ?string
    {
        return $this->app->make(ResultUrls::class)->for($payment->uuid, $from);
    }

    /**
     * Where the result page's "Back to the site" button goes, per payment.
     * Return null to fall back to the profile's return_urls.back, then
     * fawaterk.results.back_url. Only https URLs and paths are used.
     *
     * @param  (Closure(FawaterkPayment): ?string)|null  $callback
     */
    public function resultBackUrlUsing(?Closure $callback): void
    {
        $this->app->make(Callbacks::class)->resultBackUrl = $callback;
    }

    /**
     * Send the payer to your own page after the result page has re-read the
     * payment (JSON requests still get JSON). Return null to show the
     * package's page. Only https URLs and paths are used. Your page must
     * check who may see the payment.
     *
     * @param  (Closure(FawaterkPayment): ?string)|null  $callback
     */
    public function resultRedirectUsing(?Closure $callback): void
    {
        $this->app->make(Callbacks::class)->resultRedirect = $callback;
    }

    /**
     * Who gets the anomaly mail, per event, instead of fawaterk.notifications.mail.
     * Return a notifiable (or a list of them), an address or a list of
     * addresses, or null to send nothing.
     *
     * @param  (Closure(object): mixed)|null  $callback
     */
    public function routeNotificationsUsing(?Closure $callback): void
    {
        $this->app->make(Callbacks::class)->notificationRouter = $callback;
    }

    /**
     * Replace the API with an in-memory fake for tests. Refused when
     * FAWATERK_ENV=live or APP_ENV=production: a fake answers whatever it is
     * told, and every paid re-read would trust it.
     *
     * @throws LogicException on live or in production
     */
    public function fake(): FawaterkFake
    {
        // Read gently: a fake must still work while the rest of the config is broken.
        $environment = $this->app->make('config')->get('fawaterk.environment');
        $live = is_string($environment) && strtolower(trim($environment)) === Environment::Live->value;

        if ($live || ($this->app instanceof Application && $this->app->environment('production'))) {
            throw new LogicException('Fawaterk::fake() is refused when FAWATERK_ENV=live or APP_ENV=production.');
        }

        $fake = new FawaterkFake;

        $this->app->instance(FawaterkClient::class, $fake);

        return $fake;
    }
}
