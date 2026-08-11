<?php

return [

    'loading' => 'Working…',

    // Currency code rather than a symbol. A symbol table is a per-locale problem
    // this package would get wrong, and "GBP 19.99" is never ambiguous about
    // which of the four dollars it means.
    'money' => ':currency :amount',

    'pay' => [
        'heading' => 'Payment',
        'due' => ':amount to pay.',
        'button' => 'Pay :amount',
        'paying' => 'Taking your payment…',
        'reference' => 'Payment reference',

        'confirmed' => 'Payment approved.',
        // A replay is the shopper's own second click, or their reload. They see
        // their payment, not a second one and not an error.
        'replayed' => 'Payment approved. We already had this one, so you have not been charged twice.',

        'in_flight' => 'We are still processing your first attempt. Give it a moment — you have not been charged twice.',
        'in_flight_retry' => 'Check again',

        'conflict' => 'The amount owed changed while you were paying, so we did not take anything. Go back and start the payment again.',

        'declined' => 'Your payment was not approved. Nothing has been taken. Try a different payment method.',
        'decline_code' => 'The reason given was “:code”.',

        'already' => 'This order has already been paid. Nothing has been taken again.',
        'already_paid' => 'This order is paid.',

        'closed' => 'This payment can no longer be taken. Go back and start again.',

        // Said to a shopper, because it is not their fault and there is nothing
        // for them to fix. The deployment's problem is in docs/runbook.md.
        'unconfigured' => 'This shop cannot take payments yet.',

        // The one place a card number could reach this package: a payment form
        // wired to post the field instead of the token it was given.
        'instrument_refused' => 'Your card details cannot be sent to this page. Use the payment form above, which sends them straight to our payment provider.',

        'capture_deferred' => 'Your payment is approved. Taking it is being finished separately.',

        'no_widget' => 'The payment form has not loaded.',
    ],

    'receipt' => [
        'heading' => 'Your payment',
        'reference' => 'Reference',
        'made_on' => 'Made on',
        'instrument' => 'Paid with',
        'status' => 'Status',
        'amount' => 'Amount',
        'captured' => 'Taken',
        'refunded' => 'Refunded',
        'refundable' => 'Still refundable',
        'history' => 'What has happened',
        'history_empty' => 'Nothing has happened to this payment yet.',
        'failure_code' => 'Reason: :code',
    ],

    // The domain's vocabulary, in a shopper's words. `authorized` is the one
    // that matters: "reserved" is what actually happened, and a customer who
    // reads "authorized" reasonably concludes they have been charged.
    'status' => [
        'pending' => 'Not yet paid',
        'authorized' => 'Reserved, not yet taken',
        'partially_captured' => 'Partly taken',
        'captured' => 'Paid',
        'partially_refunded' => 'Partly refunded',
        'refunded' => 'Refunded',
        'voided' => 'Cancelled, nothing taken',
        'expired' => 'Expired, nothing taken',
        'failed' => 'Not approved',
    ],

    'kind' => [
        'authorized' => 'Reserved',
        'captured' => 'Taken',
        'voided' => 'Reservation cancelled',
        'refunded' => 'Refunded',
        'failed' => 'Not approved',
        'expired' => 'Reservation expired',
        'settled' => 'Settled',
    ],

    'instrument' => [
        'heading' => 'Saved payment methods',
        'empty' => 'You have no saved payment methods.',
        'label' => ':brand ending :last_four',
        'unnamed' => 'Saved payment method',
        'expires' => 'Expires :expiry',
        'remove' => 'Remove :label',
        'detached' => ':label was removed.',
        'unknown' => 'That payment method is not on your account.',
        // Said once, plainly, rather than left for somebody to discover by
        // hunting for a control that is not there.
        'how_to_add' => 'A payment method is saved when you pay with it. There is nowhere here to type card details, and there never will be — they go straight to our payment provider.',
    ],

];
