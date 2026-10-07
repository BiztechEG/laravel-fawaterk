<?php

namespace BiztechEG\Fawaterk\Refunds;

/**
 * @internal
 */
final class RefundResult
{
    /**
     * @param  int  $applied  verified refunds counted now for the first time
     */
    public function __construct(public readonly int $applied) {}
}
