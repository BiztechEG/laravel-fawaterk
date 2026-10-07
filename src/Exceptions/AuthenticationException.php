<?php

namespace BiztechEG\Fawaterk\Exceptions;

/**
 * Fawaterk rejected the OAuth credentials or the access token (HTTP 401),
 * even after one fresh token. Usually a configuration problem.
 */
class AuthenticationException extends ApiException {}
