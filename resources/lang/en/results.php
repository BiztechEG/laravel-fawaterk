<?php

return [
    'title' => 'Payment status',

    'states' => [
        'paid' => [
            'heading' => 'Payment received',
            'body' => 'Thank you. Your payment was received.',
        ],
        'under_review' => [
            'heading' => 'Payment received',
            'body' => 'We received your payment and are checking it. We will contact you if anything else is needed.',
        ],
        'refunded' => [
            'heading' => 'Payment refunded',
            'body' => 'This payment was refunded.',
        ],
        'processing' => [
            'heading' => 'Payment in progress',
            'body' => 'Your payment is being processed. This page updates by itself.',
        ],
        'unconfirmed' => [
            'heading' => 'Payment not confirmed yet',
            'body' => 'We have not received a confirmation for this payment yet. If you have paid, it will be confirmed shortly: check this page again later.',
        ],
        'awaiting_payment' => [
            'heading' => 'Waiting for your payment',
            'body' => 'This payment is not complete yet.',
        ],
        'awaiting_code' => [
            'heading' => 'Waiting for your payment',
            'body' => 'Pay with this reference code before it expires.',
        ],
        'not_completed' => [
            'heading' => 'Payment not completed',
            'body' => 'The payment attempt did not go through. Please start the payment again.',
        ],
        'expired' => [
            'heading' => 'Payment expired',
            'body' => 'The time to pay has run out. Please start the payment again.',
        ],
        'failed' => [
            'heading' => 'Payment could not be started',
            'body' => 'Something went wrong while starting the payment. Please try again.',
        ],
    ],

    'amount' => 'Amount',
    'reference' => 'Reference',
    'code' => 'Reference code',
    'pay_before' => 'Pay before',
    'paid_at' => 'Paid on',
    'continue' => 'Continue to payment',
    'back' => 'Back to the site',

    'invalid' => [
        'heading' => 'This link is not valid',
        'body' => 'The link may have expired. Go back to the site to see your payment.',
    ],
];
