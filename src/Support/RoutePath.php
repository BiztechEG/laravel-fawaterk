<?php

namespace BiztechEG\Fawaterk\Support;

use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use Illuminate\Routing\Route;

/**
 * A route's path with its parameters filled in, built from the route alone.
 *
 * Laravel's URL generator reads the current request (its base URL, which a
 * trusted X-Forwarded-Prefix can change). URLs sent to Fawaterk come from
 * the configured origin and this path only.
 *
 * @internal
 */
final class RoutePath
{
    /**
     * @param  array<string, string>  $parameters
     */
    public static function fill(Route $route, array $parameters): string
    {
        $path = (string) preg_replace_callback('/\{([^}?]+)(\?)?\}/', function (array $match) use ($route, $parameters) {
            if (array_key_exists($match[1], $parameters)) {
                return rawurlencode($parameters[$match[1]]);
            }

            if (($match[2] ?? '') === '?') {
                return '';
            }

            throw new ConfigurationException("The route [{$route->uri()}] has a parameter {{$match[1]}} the package cannot fill: register Fawaterk's routes outside groups with route parameters.");
        }, $route->uri());

        return '/'.trim((string) preg_replace('#/{2,}#', '/', $path), '/');
    }
}
