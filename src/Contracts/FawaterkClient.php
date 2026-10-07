<?php

namespace BiztechEG\Fawaterk\Contracts;

use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Data\TransactionIntent;

/**
 * Fawaterk's API v3, as the package uses it. Bound to the real client, or to
 * Testing\FawaterkFake after Fawaterk::fake().
 */
interface FawaterkClient
{
    /**
     * Never retried: a retry after a timeout could create a second payable link.
     */
    public function createTransaction(CreateTransaction $request): TransactionIntent;

    /**
     * The only source the package trusts for "is it paid, and how much".
     *
     * @param  int|null  $timeout  seconds; webhooks use a short one
     */
    public function getTransaction(string $intentKey, ?int $timeout = null): TransactionData;

    /**
     * @return list<PaymentMethod>
     */
    public function getPaymentMethods(): array;

    /**
     * One page (10 entries) of the account's refund requests.
     */
    public function refundPage(int $page = 1): RefundPage;
}
