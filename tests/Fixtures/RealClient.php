<?php

namespace BiztechEG\Fawaterk\Tests\Fixtures;

use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Data\TransactionIntent;
use BiztechEG\Fawaterk\Testing\FawaterkFake;

/**
 * Behaves like the fake but is not one, so the package treats it as the real
 * client (for checks that exempt Fawaterk::fake()).
 */
final class RealClient implements FawaterkClient
{
    public function __construct(private readonly FawaterkFake $inner) {}

    public function createTransaction(CreateTransaction $request): TransactionIntent
    {
        return $this->inner->createTransaction($request);
    }

    public function getTransaction(string $intentKey, ?int $timeout = null): TransactionData
    {
        return $this->inner->getTransaction($intentKey, $timeout);
    }

    public function getPaymentMethods(): array
    {
        return $this->inner->getPaymentMethods();
    }

    public function refundPage(int $page = 1): RefundPage
    {
        return $this->inner->refundPage($page);
    }
}
