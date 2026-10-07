<?php

namespace BiztechEG\Fawaterk\Http;

use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Turns a transport error (Guzzle's or Laravel's) into a typed exception.
 *
 * The original exception is dropped on purpose: Guzzle's exceptions keep the
 * request, whose body holds the client secret and whose headers hold the
 * access token.
 *
 * @internal
 */
final class TransportFailure
{
    /**
     * cURL errors raised before the request reached Fawaterk: the proxy or host
     * was not resolved, the connection was refused, or TLS failed.
     */
    private const NOT_SENT = [5, 6, 7, 35, 51, 58, 60, 77];

    public static function from(Throwable $e, string $message): ServiceUnavailableException
    {
        return new ServiceUnavailableException($message, 0, ! in_array(self::errno($e), self::NOT_SENT, true));
    }

    private static function errno(Throwable $e): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectException || $current instanceof RequestException) {
                $errno = $current->getHandlerContext()['errno'] ?? null;

                return is_int($errno) ? $errno : null;
            }
        }

        return null;
    }
}
