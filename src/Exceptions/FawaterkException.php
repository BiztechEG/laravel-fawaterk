<?php

namespace BiztechEG\Fawaterk\Exceptions;

use RuntimeException;

/**
 * Base class for every exception the package throws. Messages never contain
 * request or response bodies, tokens or customer data.
 */
class FawaterkException extends RuntimeException {}
