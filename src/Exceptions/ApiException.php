<?php

namespace BiztechEG\Fawaterk\Exceptions;

/**
 * Fawaterk answered with an error. The message is Fawaterk's own short
 * message (or a generic one), never the response body.
 */
class ApiException extends FawaterkException
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages, when Fawaterk returned them
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }
}
