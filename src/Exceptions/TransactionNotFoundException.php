<?php

namespace BiztechEG\Fawaterk\Exceptions;

/**
 * getTransactionData answered 422: the intent key is invalid, or the
 * transaction no longer exists (for example an expired, never-paid intent).
 */
class TransactionNotFoundException extends ApiException {}
