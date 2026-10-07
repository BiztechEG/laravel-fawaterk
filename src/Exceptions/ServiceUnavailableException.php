<?php

namespace BiztechEG\Fawaterk\Exceptions;

/**
 * Fawaterk could not be reached, timed out, or answered with a server error.
 *
 * When $outcomeUnknown is true the request may have reached Fawaterk and been
 * processed (a read timeout, a dropped connection, a 5xx). After a
 * createTransaction that means an intent may exist whose link or code was
 * never returned to you. When it is false the request was not processed
 * (the host was not reached, the TLS handshake failed, or a 429).
 */
class ServiceUnavailableException extends ApiException
{
    public function __construct(string $message, int $status, public readonly bool $outcomeUnknown = true)
    {
        parent::__construct($message, $status);
    }
}
