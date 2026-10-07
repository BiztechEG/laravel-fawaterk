<?php

namespace BiztechEG\Fawaterk\Exceptions;

/**
 * Fawaterk answered with something the package does not recognise (a redirect,
 * invalid JSON, a missing field, an unknown payment_data shape). The package
 * fails closed instead of guessing.
 */
class UnexpectedResponseException extends FawaterkException {}
