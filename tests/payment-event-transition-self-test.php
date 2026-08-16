<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/package/src/PaymentEventTransition.php';

$assertions = 0;

function red_store_lite_payment_event_assert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_payment_event_order(
    string $orderStatus = 'pending',
    string $paymentStatus = 'pending',
    string $fulfillmentStatus = 'unfulfilled',
    string $paymentMethod = 'stripe_checkout'
): array {
    return [
        'orderId' => 'ord_' . str_repeat('a', 32),
        'snapshotSha256' => str_repeat('b', 64),
        'paymentMethod' => $paymentMethod,
        'paymentKind' => 'hosted',
        'currency' => 'USD',
        'totalMinor' => 3997,
        'orderStatus' => $orderStatus,
        'paymentStatus' => $paymentStatus,
        'fulfillmentStatus' => $fulfillmentStatus,
    ];
}

function red_store_lite_payment_event(
    string $outcome = 'paid',
    string $paymentMethod = 'stripe_checkout'
): array {
    return [
        'verification' => 'verified',
        'replayStatus' => 'unseen',
        'outcome' => $outcome,
        'orderId' => 'ord_' . str_repeat('a', 32),
        'orderSnapshotSha256' => str_repeat('b', 64),
        'paymentMethod' => $paymentMethod,
        'amountMinor' => 3997,
        'currency' => 'USD',
        'eventEvidenceSha256' => str_repeat('c', 64),
        'occurredAt' => 1786842000,
    ];
}

try {
    $paid = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
        red_store_lite_payment_event_order(),
        red_store_lite_payment_event()
    );
    red_store_lite_payment_event_assert(
        $paid['valid']
            && $paid['transition']['decision'] === 'transition'
            && $paid['transition']['eventName'] === 'payment.paid'
            && $paid['transition']['targetState'] === [
                'orderStatus' => 'paid',
                'paymentStatus' => 'paid',
                'fulfillmentStatus' => 'unfulfilled',
            ],
        'verified paid event proposes only the exact paid state'
    );
    red_store_lite_payment_event_assert(
        $paid['transition']['stateChanged'] === true
            && $paid['transition']['fulfillmentBlocked'] === false
            && $paid['transition']['eventEvidenceSha256']
                === str_repeat('c', 64)
            && preg_match('/\A[a-f0-9]{64}\z/D', $paid['planSha256']) === 1,
        'paid plan is bounded and does not claim fulfillment'
    );
    $repeatPaid = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
        red_store_lite_payment_event_order(),
        red_store_lite_payment_event()
    );
    red_store_lite_payment_event_assert(
        $repeatPaid === $paid,
        'identical order and event facts produce identical evidence'
    );

    foreach (['paypal', 'nequi'] as $hostedMethod) {
        $hostedPaid = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
            red_store_lite_payment_event_order(
                'pending',
                'pending',
                'unfulfilled',
                $hostedMethod
            ),
            red_store_lite_payment_event('paid', $hostedMethod)
        );
        red_store_lite_payment_event_assert(
            $hostedPaid['valid']
                && $hostedPaid['transition']['outcome'] === 'paid',
            $hostedMethod . ' uses the same provider-neutral hosted contract'
        );
    }

    $refund = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
        red_store_lite_payment_event_order('paid', 'paid'),
        red_store_lite_payment_event('refund_confirmed')
    );
    red_store_lite_payment_event_assert(
        $refund['valid']
            && $refund['transition']['eventName']
                === 'payment.refund_confirmed'
            && $refund['transition']['targetState'] === [
                'orderStatus' => 'refunded',
                'paymentStatus' => 'refunded',
                'fulfillmentStatus' => 'unfulfilled',
            ],
        'confirmed full refund remains distinct from paid and reversal'
    );

    $reversal = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
        red_store_lite_payment_event_order('paid', 'paid'),
        red_store_lite_payment_event('reversal_reported')
    );
    red_store_lite_payment_event_assert(
        $reversal['valid']
            && $reversal['transition']['eventName']
                === 'payment.reversal_reported'
            && $reversal['transition']['targetState'] === [
                'orderStatus' => 'paid',
                'paymentStatus' => 'reversal_reported',
                'fulfillmentStatus' => 'blocked',
            ],
        'reversal reports risk without inventing refund or cancellation'
    );
    red_store_lite_payment_event_assert(
        $reversal['transition']['fulfillmentBlocked'] === true
            && $reversal['transition']['targetState']['orderStatus'] !== 'refunded'
            && $reversal['transition']['targetState']['orderStatus'] !== 'cancelled',
        'reversal explicitly blocks automatic fulfillment only'
    );

    foreach (['failed', 'cancelled', 'expired'] as $outcome) {
        $noTransition = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
            red_store_lite_payment_event_order(),
            red_store_lite_payment_event($outcome)
        );
        red_store_lite_payment_event_assert(
            $noTransition['valid']
                && $noTransition['transition']['decision'] === 'none'
                && $noTransition['transition']['eventName'] === null
                && $noTransition['transition']['stateChanged'] === false
                && $noTransition['transition']['targetState']
                    === $noTransition['transition']['currentState'],
            $outcome . ' cannot create a payment or order transition'
        );
    }

    foreach ([
        'pay-on-receipt' => [
            red_store_lite_payment_event_order(
                'pending', 'due_on_receipt', 'unfulfilled', 'pay_on_receipt'
            ),
            red_store_lite_payment_event('paid', 'pay_on_receipt'),
        ],
        'manual-zelle' => [
            red_store_lite_payment_event_order(
                'pending', 'pending', 'unfulfilled', 'zelle_manual'
            ),
            red_store_lite_payment_event('paid', 'zelle_manual'),
        ],
    ] as $label => [$order, $event]) {
        $result = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
            $order,
            $event
        );
        red_store_lite_payment_event_assert(
            !$result['valid'] && $result['transition'] === null,
            $label . ' cannot impersonate a hosted adapter event'
        );
    }

    $invalidCases = [];
    $event = red_store_lite_payment_event();
    $event['verification'] = 'unverified';
    $invalidCases['unverified'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['replayStatus'] = 'replayed';
    $invalidCases['replayed'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['orderId'] = 'ord_' . str_repeat('d', 32);
    $invalidCases['order-id'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['orderSnapshotSha256'] = str_repeat('d', 64);
    $invalidCases['snapshot'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['paymentMethod'] = 'paypal';
    $invalidCases['method'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['amountMinor'] = 3996;
    $invalidCases['amount'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['currency'] = 'COP';
    $invalidCases['currency'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['eventEvidenceSha256'] = 'raw-event-id';
    $invalidCases['evidence'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['occurredAt'] = 0;
    $invalidCases['time'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    $event['rawBody'] = '{"paid":true}';
    $invalidCases['raw-body'] = [red_store_lite_payment_event_order(), $event];
    $event = red_store_lite_payment_event();
    unset($event['eventEvidenceSha256']);
    $invalidCases['missing-field'] = [
        red_store_lite_payment_event_order(), $event,
    ];
    foreach ($invalidCases as $label => [$order, $event]) {
        $result = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
            $order,
            $event
        );
        red_store_lite_payment_event_assert(
            !$result['valid']
                && $result['transition'] === null
                && $result['planSha256'] === ''
                && $result['errors'] !== [],
            $label . ' mismatch fails closed without a partial transition'
        );
    }

    foreach ([
        'paid-from-paid' => [
            red_store_lite_payment_event_order('paid', 'paid'),
            red_store_lite_payment_event('paid'),
        ],
        'refund-before-paid' => [
            red_store_lite_payment_event_order(),
            red_store_lite_payment_event('refund_confirmed'),
        ],
        'reversal-before-paid' => [
            red_store_lite_payment_event_order(),
            red_store_lite_payment_event('reversal_reported'),
        ],
        'failed-after-paid' => [
            red_store_lite_payment_event_order('paid', 'paid'),
            red_store_lite_payment_event('failed'),
        ],
    ] as $label => [$order, $event]) {
        $result = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
            $order,
            $event
        );
        red_store_lite_payment_event_assert(
            !$result['valid']
                && $result['errors'] === ['transition_not_allowed'],
            $label . ' is refused by the closed state map'
        );
    }

    $partialRefund = red_store_lite_payment_event('refund_confirmed');
    $partialRefund['amountMinor'] = 1000;
    $partialResult = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
        red_store_lite_payment_event_order('paid', 'paid'),
        $partialRefund
    );
    red_store_lite_payment_event_assert(
        !$partialResult['valid']
            && $partialResult['errors'] === ['amount_mismatch'],
        'partial refund cannot be mistaken for the supported full refund'
    );

    $source = file_get_contents(
        dirname(__DIR__) . '/package/src/PaymentEventTransition.php'
    );
    red_store_lite_payment_event_assert(
        is_string($source)
            && !preg_match(
                '/mysqli|\bPDO\b|\$_(?:GET|POST|SERVER|COOKIE|SESSION|FILES)|\$_ENV|php:\/\/input|curl_|fsockopen|file_get_contents|fopen|getenv|registerService|registerRoute|runtime_secret|resolve_secret|resolveSecret|secretValue|signature|checkoutUrl|providerError/i',
                $source
            ),
        'contract has no database, request, registration, secret, or network path'
    );

    echo 'Store Lite P3B-1 payment-event transition self-test passed: ' .
        $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}

?>
