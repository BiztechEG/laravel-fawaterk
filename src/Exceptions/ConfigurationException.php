<?php

namespace BiztechEG\Fawaterk\Exceptions;

/**
 * The package is not configured correctly (a missing secret, a base URL that
 * is not Fawaterk's, an unknown payment method).
 */
class ConfigurationException extends FawaterkException {}
