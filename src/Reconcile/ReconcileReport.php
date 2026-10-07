<?php

namespace BiztechEG\Fawaterk\Reconcile;

final class ReconcileReport
{
    public int $checked = 0;

    public int $paid = 0;

    public int $expired = 0;

    public int $refundsChecked = 0;

    public int $alertsResent = 0;

    public int $redispatched = 0;

    public int $errors = 0;
}
