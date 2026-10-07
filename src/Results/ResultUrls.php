<?php

namespace BiztechEG\Fawaterk\Results;

use BiztechEG\Fawaterk\Http\Controllers\ResultController;
use BiztechEG\Fawaterk\Support\RoutePath;
use BiztechEG\Fawaterk\Webhooks\WebhookUrls;
use DateTimeInterface;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;

/**
 * Absolute, path-signed result URLs, built from FAWATERK_APP_URL
 * (or APP_URL) and the route alone, never from the current request.
 *
 * The route is found by its controller, so the app may give it any name.
 *
 * @internal
 */
final class ResultUrls
{
    public const ROUTE_NAME = 'fawaterk.result';

    /** Route parameter names no app is likely to bind or constrain. */
    public const PAYMENT = 'fawaterk_payment';

    public const EXPIRES = 'fawaterk_expires';

    public const SIGNATURE = 'fawaterk_signature';

    public function __construct(
        private readonly Router $router,
        private readonly WebhookUrls $base,
        private readonly ResultSigner $signer,
        private readonly int $validDays,
    ) {}

    public function registered(): bool
    {
        return $this->route() !== null;
    }

    public function validDays(): int
    {
        return max(1, $this->validDays);
    }

    /**
     * Null when the app has not registered Route::fawaterk().
     *
     * @param  DateTimeInterface|null  $from  the link stays valid for fawaterk.results.valid_days after this (default: now)
     */
    public function for(string $uuid, ?DateTimeInterface $from = null): ?string
    {
        $route = $this->route();

        if ($route === null) {
            return null;
        }

        $now = Carbon::now();
        $start = $from !== null && $from > $now ? Carbon::instance($from) : $now;
        $expires = $start->copy()->addDays($this->validDays())->getTimestamp();

        return $this->signed($route, $uuid, $expires, $this->signer->sign($uuid, $expires));
    }

    /**
     * The absolute URL of an already signed triple.
     */
    public function signed(Route $route, string $uuid, int $expires, string $signature): string
    {
        return $this->base->base().RoutePath::fill($route, [
            self::PAYMENT => $uuid,
            self::EXPIRES => (string) $expires,
            self::SIGNATURE => $signature,
        ]);
    }

    private function route(): ?Route
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->getActionName(), ResultController::class)) {
                return $route;
            }
        }

        return null;
    }
}
