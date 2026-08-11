<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/package/src/GuestOrderSnapshot.php';

$assertions = 0;

function red_store_lite_order_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_order_configuration(): array
{
    return [
        'currency' => 'USD',
        'fulfillmentMethods' => ['pickup', 'delivery'],
        'paymentMethods' => [
            'pay_on_receipt',
            'stripe_checkout',
            'paypal',
            'zelle_manual',
            'nequi',
        ],
        'deliveryFeeMinor' => 700,
    ];
}

function red_store_lite_order_cart(): array
{
    return [
        'stateSha256' => str_repeat('a', 64),
        'currency' => 'USD',
        'lines' => [[
            'productId' => 'banana-pack',
            'variantId' => null,
            'sku' => 'BANANA-6',
            'title' => 'Banana six-pack',
            'optionLabels' => [],
            'quantity' => 2,
            'unitPriceMinor' => 399,
            'currency' => 'USD',
            'lineTotalMinor' => 798,
        ], [
            'productId' => 'classic-tshirt',
            'variantId' => 'small-red',
            'sku' => 'TSHIRT-S-RED',
            'title' => 'Classic T-shirt',
            'optionLabels' => ['Size: Small', 'Color: Red'],
            'quantity' => 1,
            'unitPriceMinor' => 2499,
            'currency' => 'USD',
            'lineTotalMinor' => 2499,
        ]],
    ];
}

function red_store_lite_order_pickup(string $payment = 'pay_on_receipt'): array
{
    return [
        'customer' => [
            'name' => 'Taylor Customer',
            'email' => 'taylor@example.com',
            'phone' => null,
        ],
        'fulfillmentMethod' => 'pickup',
        'deliveryAddress' => null,
        'paymentMethod' => $payment,
    ];
}

function red_store_lite_order_delivery(string $payment = 'stripe_checkout'): array
{
    return [
        'customer' => [
            'name' => 'Morgan Customer',
            'email' => 'morgan@example.com',
            'phone' => '+1 (202) 555-0144',
        ],
        'fulfillmentMethod' => 'delivery',
        'deliveryAddress' => [
            'line1' => '100 Main Street',
            'line2' => 'Apartment 4',
            'city' => 'Arlington',
            'region' => 'VA',
            'postalCode' => '22201',
            'countryCode' => 'US',
            'instructions' => 'Leave with the front desk.',
        ],
        'paymentMethod' => $payment,
    ];
}

try {
    $pickup = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
        red_store_lite_order_cart(),
        red_store_lite_order_pickup(),
        red_store_lite_order_configuration()
    );
    red_store_lite_order_assert(
        $pickup['valid']
            && $pickup['snapshot']['fulfillment'] === [
                'method' => 'pickup',
                'feeMinor' => 0,
                'deliveryAddress' => null,
            ],
        'pickup creates a zero-fee address-free fulfillment snapshot'
    );
    red_store_lite_order_assert(
        $pickup['snapshot']['quantityTotal'] === 3
            && $pickup['snapshot']['subtotalMinor'] === 3297
            && $pickup['snapshot']['totalMinor'] === 3297,
        'pickup totals are derived only from immutable integer line facts'
    );
    red_store_lite_order_assert(
        $pickup['snapshot']['payment'] === [
            'method' => 'pay_on_receipt',
            'kind' => 'deferred',
        ]
            && $pickup['initialState'] === [
                'orderStatus' => 'pending',
                'paymentStatus' => 'due_on_receipt',
                'fulfillmentStatus' => 'unfulfilled',
            ],
        'pay on receipt remains separate from order and fulfillment state'
    );
    red_store_lite_order_assert(
        $pickup['sourceCartStateSha256'] === str_repeat('a', 64)
            && preg_match('/\A[a-f0-9]{64}\z/D', $pickup['snapshotSha256']) === 1,
        'successful result binds source cart state and immutable snapshot hash'
    );

    $delivery = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
        red_store_lite_order_cart(),
        red_store_lite_order_delivery(),
        red_store_lite_order_configuration()
    );
    red_store_lite_order_assert(
        $delivery['valid']
            && $delivery['snapshot']['fulfillment']['method'] === 'delivery'
            && $delivery['snapshot']['fulfillment']['feeMinor'] === 700
            && $delivery['snapshot']['totalMinor'] === 3997,
        'delivery snapshots the configured server-side fee and address'
    );
    red_store_lite_order_assert(
        $delivery['snapshot']['payment'] === [
            'method' => 'stripe_checkout',
            'kind' => 'hosted',
        ]
            && $delivery['initialState']['paymentStatus'] === 'pending',
        'Stripe is a hosted intent and never an immediate paid transition'
    );
    red_store_lite_order_assert(
        $delivery['snapshot']['lines'][1]['variantId'] === 'small-red'
            && $delivery['snapshot']['lines'][1]['sku'] === 'TSHIRT-S-RED'
            && $delivery['snapshot']['lines'][1]['optionLabels']
                === ['Size: Small', 'Color: Red'],
        'variable-product SKU and selected labels remain in the snapshot'
    );
    $repeat = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
        red_store_lite_order_cart(),
        red_store_lite_order_delivery(),
        red_store_lite_order_configuration()
    );
    red_store_lite_order_assert(
        hash_equals($delivery['snapshotSha256'], $repeat['snapshotSha256']),
        'identical facts produce a deterministic snapshot hash'
    );
    $changedDelivery = red_store_lite_order_delivery();
    $changedDelivery['deliveryAddress']['line1'] = '102 Main Street';
    $changed = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
        red_store_lite_order_cart(),
        $changedDelivery,
        red_store_lite_order_configuration()
    );
    red_store_lite_order_assert(
        $changed['valid']
            && !hash_equals(
                $delivery['snapshotSha256'],
                $changed['snapshotSha256']
            ),
        'a changed delivery fact changes the immutable snapshot identity'
    );

    foreach ([
        'paypal' => 'hosted',
        'zelle_manual' => 'manual_transfer',
        'nequi' => 'hosted',
    ] as $method => $kind) {
        $result = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
            red_store_lite_order_cart(),
            red_store_lite_order_delivery($method),
            red_store_lite_order_configuration()
        );
        red_store_lite_order_assert(
            $result['valid']
                && $result['snapshot']['payment']['kind'] === $kind
                && $result['initialState']['paymentStatus'] === 'pending',
            $method . ' remains pending under its approved payment kind'
        );
    }

    foreach (['venmo', 'apple_pay', 'google_pay'] as $delegatedMethod) {
        $configuration = red_store_lite_order_configuration();
        $configuration['paymentMethods'][] = $delegatedMethod;
        $result = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
            red_store_lite_order_cart(),
            red_store_lite_order_pickup($delegatedMethod),
            $configuration
        );
        red_store_lite_order_assert(
            !$result['valid']
                && $result['snapshot'] === null
                && in_array('payment_methods_invalid', $result['errors'], true),
            $delegatedMethod . ' is not misrepresented as a separate adapter'
        );
    }

    $invalidCases = [];
    $configuration = red_store_lite_order_configuration();
    $configuration['fulfillmentMethods'] = ['pickup'];
    $configuration['deliveryFeeMinor'] = 0;
    $invalidCases['disabled-delivery'] = [
        red_store_lite_order_cart(), red_store_lite_order_delivery(), $configuration,
    ];
    $pickupWithAddress = red_store_lite_order_pickup();
    $pickupWithAddress['deliveryAddress'] = red_store_lite_order_delivery()['deliveryAddress'];
    $invalidCases['pickup-address'] = [
        red_store_lite_order_cart(), $pickupWithAddress,
        red_store_lite_order_configuration(),
    ];
    $deliveryWithoutPhone = red_store_lite_order_delivery();
    $deliveryWithoutPhone['customer']['phone'] = null;
    $invalidCases['delivery-phone'] = [
        red_store_lite_order_cart(), $deliveryWithoutPhone,
        red_store_lite_order_configuration(),
    ];
    $deliveryWithoutAddress = red_store_lite_order_delivery();
    $deliveryWithoutAddress['deliveryAddress'] = null;
    $invalidCases['delivery-address'] = [
        red_store_lite_order_cart(), $deliveryWithoutAddress,
        red_store_lite_order_configuration(),
    ];
    $emptyCart = red_store_lite_order_cart();
    $emptyCart['lines'] = [];
    $invalidCases['empty-cart'] = [
        $emptyCart, red_store_lite_order_pickup(),
        red_store_lite_order_configuration(),
    ];
    $badState = red_store_lite_order_cart();
    $badState['stateSha256'] = strtoupper($badState['stateSha256']);
    $invalidCases['cart-state'] = [
        $badState, red_store_lite_order_pickup(),
        red_store_lite_order_configuration(),
    ];
    $floatPrice = red_store_lite_order_cart();
    $floatPrice['lines'][0]['unitPriceMinor'] = 3.99;
    $invalidCases['float-price'] = [
        $floatPrice, red_store_lite_order_pickup(),
        red_store_lite_order_configuration(),
    ];
    $forgedTotal = red_store_lite_order_cart();
    $forgedTotal['lines'][0]['lineTotalMinor'] = 1;
    $invalidCases['forged-total'] = [
        $forgedTotal, red_store_lite_order_pickup(),
        red_store_lite_order_configuration(),
    ];
    $duplicateLine = red_store_lite_order_cart();
    $duplicateLine['lines'][] = $duplicateLine['lines'][0];
    $invalidCases['duplicate-line'] = [
        $duplicateLine, red_store_lite_order_pickup(),
        red_store_lite_order_configuration(),
    ];
    $missingVariantOptions = red_store_lite_order_cart();
    $missingVariantOptions['lines'][1]['optionLabels'] = [];
    $invalidCases['missing-variant-options'] = [
        $missingVariantOptions, red_store_lite_order_pickup(),
        red_store_lite_order_configuration(),
    ];
    $unknownCheckout = red_store_lite_order_pickup();
    $unknownCheckout['providerReference'] = 'browser-owned';
    $invalidCases['unknown-checkout-field'] = [
        red_store_lite_order_cart(), $unknownCheckout,
        red_store_lite_order_configuration(),
    ];
    $duplicateConfiguration = red_store_lite_order_configuration();
    $duplicateConfiguration['paymentMethods'][] = 'paypal';
    $invalidCases['duplicate-payment-method'] = [
        red_store_lite_order_cart(), red_store_lite_order_pickup(),
        $duplicateConfiguration,
    ];

    foreach ($invalidCases as $name => [$cart, $checkout, $configuration]) {
        $result = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
            $cart,
            $checkout,
            $configuration
        );
        red_store_lite_order_assert(
            !$result['valid']
                && $result['snapshot'] === null
                && $result['snapshotSha256'] === null
                && $result['sourceCartStateSha256'] === null
                && $result['initialState'] === null
                && $result['errors'] !== [],
            $name . ' fails closed without partial order data'
        );
    }

    $source = file_get_contents(
        dirname(__DIR__) . '/package/src/GuestOrderSnapshot.php'
    );
    red_store_lite_order_assert(
        is_string($source)
            && !str_contains($source, 'mysqli')
            && !str_contains($source, '$_GET')
            && !str_contains($source, '$_POST')
            && !str_contains($source, '$_COOKIE')
            && !str_contains($source, 'curl_')
            && !str_contains($source, 'providerReference'),
        'contract contains no database, request, network, or provider reference path'
    );

    echo 'Store Lite guest order snapshot passed ' . $assertions
        . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
