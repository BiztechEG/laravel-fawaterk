<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use PHPUnit\Framework\TestCase;

class ResponseDataTest extends TestCase
{
    private const INTENT = '550e8400-e29b-41d4-a716-446655440000';

    public function test_transaction_data_uses_paid_as_the_only_paid_signal(): void
    {
        foreach ([1, '1', true] as $paid) {
            $this->assertTrue($this->transaction(['paid' => $paid, 'status_text' => 'unpaid'])->paid);
        }

        foreach ([0, '0', false] as $paid) {
            $this->assertFalse($this->transaction(['paid' => $paid, 'status_text' => 'paid'])->paid, 'status_text is never trusted');
        }

        foreach (['yes', 2, null, 'paid'] as $paid) {
            try {
                $this->transaction(['paid' => $paid]);
                $this->fail('An unreadable paid flag was accepted: '.var_export($paid, true));
            } catch (UnexpectedResponseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_transaction_data_reads_amounts_ids_and_references(): void
    {
        $data = $this->transaction([
            'total' => 152.5,
            'commission' => 2.5,
            'currency' => 'egp',
            'transaction_id' => '12345',
            'transaction_history' => [['reference' => '981335305'], ['reference' => '981335305'], ['reference' => null]],
        ]);

        $this->assertSame(15250, $data->totalMinor);
        $this->assertSame(250, $data->commissionMinor);
        $this->assertSame('EGP', $data->currency);
        $this->assertSame(12345, $data->transactionId);
        $this->assertSame(['981335305'], $data->references);
        $this->assertSame(0, $this->transaction(['transaction_id' => null])->transactionId, 'cache-only intents report 0');
    }

    public function test_transaction_data_rejects_a_bad_total_or_intent(): void
    {
        foreach ([['total' => 150.005], ['intent_key' => 'not a key'], ['intent_key' => 'short'], ['intent_key' => self::INTENT."\n"], ['total' => 'abc']] as $override) {
            try {
                $this->transaction($override);
                $this->fail('Accepted: '.json_encode($override));
            } catch (UnexpectedResponseException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(UnexpectedResponseException::class);
        TransactionData::fromResponse(['status' => 'success', 'data' => ['intent_key' => self::INTENT, 'paid' => 1]]);
    }

    public function test_payment_methods_are_parsed_from_fawaterk_strings(): void
    {
        $card = PaymentMethod::fromArray(['payment_method_id' => 2, 'name_en' => 'Visa-Mastercard', 'redirect' => 'true', 'commission_on_customer' => 2]);
        $fawry = PaymentMethod::fromArray(['payment_method_id' => '3', 'name_en' => 'Fawry', 'redirect' => 'false', 'commission_on_customer' => 1]);

        $this->assertTrue($card->redirect);
        $this->assertFalse($card->commissionOnCustomer);
        $this->assertSame(3, $fawry->id);
        $this->assertFalse($fawry->redirect);
        $this->assertTrue($fawry->commissionOnCustomer);
        $this->assertSame('visamastercard', PaymentMethod::normaliseName(' Visa - Mastercard '));

        $this->expectException(UnexpectedResponseException::class);
        PaymentMethod::fromArray(['name_en' => 'No id']);
    }

    public function test_the_live_accounts_older_method_shape_is_read(): void
    {
        // Seen on a live account: "paymentId" instead of "payment_method_id", and no
        // commission_on_customer.
        $aman = PaymentMethod::fromArray(['paymentId' => 12, 'name_en' => 'aman', 'name_ar' => 'امان', 'redirect' => 'false']);

        $this->assertSame(12, $aman->id);
        $this->assertSame('aman', $aman->nameEn);
        $this->assertFalse($aman->redirect);
        $this->assertNull($aman->commissionOnCustomer, 'unknown, not "on the merchant"');

        // The documented name wins when both are there.
        $this->assertSame(2, PaymentMethod::fromArray(['payment_method_id' => 2, 'paymentId' => 9, 'name_en' => 'card'])->id);

        $this->expectException(UnexpectedResponseException::class);
        PaymentMethod::fromArray(['paymentId' => 'x1', 'name_en' => 'Bad id']);
    }

    public function test_refund_pages_accept_the_paginator_shape(): void
    {
        $item = ['id' => 7, 'refundable_type' => '3', 'refundable_id' => 12345, 'refundable_amount' => 50, 'status' => 'approved'];

        $top = RefundPage::fromResponse(['current_page' => 1, 'last_page' => 2, 'data' => [$item]]);
        $nested = RefundPage::fromResponse(['status' => 'success', 'data' => ['current_page' => 2, 'last_page' => 2, 'data' => [$item]]]);

        $this->assertTrue($top->hasMore());
        $this->assertFalse($nested->hasMore());
        $this->assertSame(12345, $top->items[0]->refundableId);
        $this->assertSame(5000, $top->items[0]->amountMinor);
    }

    public function test_an_unreadable_refund_entry_is_skipped_not_the_whole_page(): void
    {
        $good = ['id' => 7, 'refundable_type' => 'Transaction', 'refundable_id' => 12345, 'refundable_amount' => 50, 'status' => 'approved'];

        $page = RefundPage::fromResponse(['current_page' => 1, 'last_page' => 1, 'data' => [
            ['refundable_amount' => null] + $good,
            ['refundable_amount' => 1.005] + $good,
            ['id' => null] + $good,
            'not an entry',
            $good,
        ]]);

        $this->assertCount(1, $page->items);
        $this->assertSame(7, $page->items[0]->id);
        $this->assertSame(4, $page->skipped);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function transaction(array $override): TransactionData
    {
        return TransactionData::fromResponse(['status' => 'success', 'data' => array_merge([
            'intent_key' => self::INTENT,
            'transaction_id' => 12345,
            'paid' => 1,
            'status_text' => 'paid',
            'total' => 100,
            'currency' => 'EGP',
        ], $override)]);
    }
}
