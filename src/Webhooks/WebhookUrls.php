<?php

namespace BiztechEG\Fawaterk\Webhooks;

use BiztechEG\Fawaterk\Data\RedirectionUrls;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Http\Controllers\WebhookController;
use BiztechEG\Fawaterk\Support\RoutePath;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Absolute webhook URLs, built from FAWATERK_APP_URL (or APP_URL) and the
 * route alone, never from the current request.
 *
 * The route is found by its controller, not its name, so it may be registered
 * with any name or inside a name-prefixed group, and route:cache still works.
 *
 * @internal
 */
final class WebhookUrls
{
    public const ROUTE_NAME = 'fawaterk.webhooks';

    public function __construct(
        private readonly Router $router,
        private readonly ?string $appUrl,
    ) {}

    /**
     * Null when the app has not registered Route::fawaterkWebhooks(); Fawaterk
     * then uses the dashboard's webhook URL.
     */
    public function for(WebhookType $type, string $account = 'default'): ?string
    {
        $route = $this->route();

        if ($route === null) {
            return null;
        }

        $parameters = ['type' => $type->segment()];

        if ($account !== 'default') {
            $parameters['account'] = $account;
        }

        return $this->base().RoutePath::fill($route, $parameters);
    }

    public function base(): string
    {
        $base = rtrim((string) $this->appUrl, '/');
        $path = trim((string) parse_url($base, PHP_URL_PATH), '/');

        if ($base === '' || ! RedirectionUrls::isHttps($base) || $path !== '' || parse_url($base, PHP_URL_QUERY) !== null) {
            throw new ConfigurationException('FAWATERK_APP_URL (or APP_URL) must be an https origin, such as https://shop.example.');
        }

        return $base;
    }

    public function host(): ?string
    {
        try {
            return strtolower((string) parse_url($this->base(), PHP_URL_HOST));
        } catch (ConfigurationException) {
            return null;
        }
    }

    private function route(): ?Route
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->getActionName(), WebhookController::class)) {
                return $route;
            }
        }

        return null;
    }
}
