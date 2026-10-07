<?php

namespace BiztechEG\Fawaterk\Support;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Closure;

/**
 * The closures an app registers at runtime through the Fawaterk facade.
 * There are no closures in config, so config:cache keeps working.
 *
 * @internal
 */
final class Callbacks
{
    /** @var (Closure(FawaterkPayment): ?string)|null */
    public ?Closure $resultBackUrl = null;

    /** @var (Closure(FawaterkPayment): ?string)|null */
    public ?Closure $resultRedirect = null;

    /** @var (Closure(object): mixed)|null */
    public ?Closure $notificationRouter = null;
}
