<?php

namespace BiztechEG\Fawaterk\Exceptions;

/**
 * Another checkout for the same payable and purpose is running. Try again in
 * a moment; it will usually return the same link or code.
 */
final class CheckoutInProgressException extends FawaterkException
{
    public function __construct()
    {
        parent::__construct('Another checkout for this payable and purpose is in progress.');
    }
}
