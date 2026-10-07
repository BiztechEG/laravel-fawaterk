<?php

return [
    'kinds' => [
        'amount_mismatch' => [
            'subject' => 'Amount mismatch',
            'body' => 'Fawaterk collected a different total than this payment expected. It was not delivered. Check it in the Fawaterk dashboard before doing anything else.',
        ],
        'paid_twice' => [
            'subject' => 'Paid twice',
            'body' => 'A second payment arrived for something already paid. It was not delivered again. Refund one of them in the Fawaterk dashboard if it is a duplicate.',
        ],
        'order_changed' => [
            'subject' => 'Order changed after checkout',
            'body' => 'This payment was made for an order that changed after the checkout started. It was not delivered. Check what was bought before delivering it by hand.',
        ],
        'payable_missing' => [
            'subject' => 'Paid order not found',
            'body' => 'This payment was made, but the order it was for no longer exists (or is deleted). It was not delivered.',
        ],
        'unfulfilled' => [
            'subject' => 'Paid but not delivered',
            'body' => 'This payment is paid, but the app has not marked it delivered yet. Reconcile keeps sending PaymentPaid; check the app\'s listener and logs.',
        ],
        'unknown_payment_paid' => [
            'subject' => 'Payment for an unknown checkout',
            'body' => 'Fawaterk reported a paid checkout this app did not create. It may belong to another integration on the same account.',
        ],
        'refund_reported' => [
            'subject' => 'Refund not confirmed',
            'body' => 'A refund webhook arrived, but the refund list does not confirm it. Amounts were not changed. Check the refund in the Fawaterk dashboard. A replayed or duplicated refund webhook can also cause this.',
        ],
        'refund_webhook_misrouted' => [
            'subject' => 'Refund webhook at the wrong URL',
            'body' => 'A correctly signed refund webhook reached another webhook URL, so the Fawaterk dashboard\'s Refund field most likely holds the wrong URL. Fix it there. The refund was not applied from this webhook; reconcile counts it from the refund list within a day.',
        ],
        'refunded' => [
            'subject' => 'Payment refunded',
            'body' => 'A refund was confirmed in Fawaterk\'s refund list.',
        ],
        'failure_reported' => [
            'subject' => 'Payment failure reported',
            'body' => 'Fawaterk reported a failed attempt. The status did not change; the payment is checked again.',
        ],
        'cancel_reported' => [
            'subject' => 'Payment cancellation reported',
            'body' => 'Fawaterk reported a cancellation. Nothing was changed; the payment is checked again.',
        ],
        'expired' => [
            'subject' => 'Payment expired',
            'body' => 'The time to pay has run out.',
        ],
    ],

    'other' => [
        'subject' => 'Payment event: :kind',
        'body' => 'A Fawaterk payment event was raised.',
    ],

    'facts' => [
        'environment' => 'Environment',
        'payment' => 'Payment',
        'payable' => 'Payable',
        'purpose' => 'Purpose',
        'status' => 'Status',
        'amount' => 'Amount',
        'expected' => 'Expected total',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
        'refund' => 'Refund reported',
        'received_at' => 'Received at the webhook URL',
        'transaction' => 'Fawaterk transaction',
        'intent_key' => 'Checkout (intent key)',
        'flags' => 'Flags',
        'time' => 'Time',
    ],

    'footer' => 'This message has no customer data. It is sent by laravel-fawaterk.',
];
