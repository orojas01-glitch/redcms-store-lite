<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_checkout_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_checkout_configuration(): array
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

function red_store_lite_checkout_readiness(): array
{
    return [
        'pay_on_receipt' => true,
        'stripe_checkout' => true,
        'paypal' => false,
        'zelle_manual' => false,
        'nequi' => false,
    ];
}

function red_store_lite_checkout_pickup_input(): array
{
    return [
        'customer-name' => 'Taylor Customer',
        'customer-email' => 'taylor@example.com',
        'customer-phone' => '',
        'fulfillment-method' => 'pickup',
        'delivery-line1' => '',
        'delivery-line2' => '',
        'delivery-city' => '',
        'delivery-region' => '',
        'delivery-postal-code' => '',
        'delivery-country-code' => '',
        'delivery-instructions' => '',
        'payment-method' => 'pay_on_receipt',
    ];
}

function red_store_lite_checkout_delivery_input(): array
{
    return [
        'customer-name' => 'Morgan Customer',
        'customer-email' => 'morgan@example.com',
        'customer-phone' => '+1 (202) 555-0144',
        'fulfillment-method' => 'delivery',
        'delivery-line1' => '100 Main Street',
        'delivery-line2' => 'Apartment 4',
        'delivery-city' => 'Arlington',
        'delivery-region' => 'VA',
        'delivery-postal-code' => '22201',
        'delivery-country-code' => 'US',
        'delivery-instructions' => 'Leave with the front desk.',
        'payment-method' => 'stripe_checkout',
    ];
}

function red_store_lite_checkout_cart(): array
{
    return [
        'stateSha256' => str_repeat('a', 64),
        'currency' => 'USD',
        'lines' => [[
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

require_once $packageRoot . '/src/GuestCheckoutCommand.php';
require_once $packageRoot . '/src/PublicGuestCheckoutPresenter.php';
require_once $packageRoot . '/src/GuestOrderSnapshot.php';

try {
    foreach ([
        'GuestCheckoutCommand.php',
        'PublicGuestCheckoutPresenter.php',
    ] as $sourceFile) {
        $source = file_get_contents($packageRoot . '/src/' . $sourceFile);
        red_store_lite_checkout_assert(
            is_string($source)
                && !preg_match(
                    '/\b(?:mysqli|PDO|curl|file_put_contents|echo|print)\b|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION)/',
                    $source
                ),
            $sourceFile . ' has no database, request, output, write, or network path'
        );
    }
    red_store_lite_checkout_assert(
        RED_CMS_Store_Lite_Guest_Checkout_Command::bounds() === [
            'maxFields' => 12,
            'maxBodyBytes' => 4096,
            'maxNameLength' => 120,
            'maxEmailLength' => 254,
            'maxPhoneLength' => 32,
            'maxAddressLength' => 160,
            'maxPostalCodeLength' => 32,
            'maxInstructionsLength' => 500,
        ],
        'checkout publishes the exact field, byte, and PII bounds'
    );

    $availability =
        RED_CMS_Store_Lite_Guest_Checkout_Command::availability(
            red_store_lite_checkout_configuration(),
            red_store_lite_checkout_readiness()
        );
    red_store_lite_checkout_assert(
        $availability === [
            'currency' => 'USD',
            'fulfillmentMethods' => ['pickup', 'delivery'],
            'paymentMethods' => ['pay_on_receipt', 'stripe_checkout'],
            'deliveryFeeMinor' => 700,
        ],
        'availability intersects configured methods with current server readiness'
    );

    $presentation =
        RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter::present(
            red_store_lite_checkout_configuration(),
            red_store_lite_checkout_readiness()
        );
    red_store_lite_checkout_assert(
        is_array($presentation)
            && array_keys($presentation) === [
                'form', 'title', 'submitLabel', 'encoding', 'maxBodyBytes',
                'fields',
            ]
            && $presentation['form'] === 'redcms.store-lite/guest-checkout'
            && $presentation['title'] === 'Checkout'
            && $presentation['submitLabel'] === 'Place order'
            && $presentation['encoding']
                === 'application/x-www-form-urlencoded'
            && $presentation['maxBodyBytes'] === 4096,
        'presenter returns one bounded data-only checkout model without an action'
    );
    red_store_lite_checkout_assert(
        array_column($presentation['fields'], 'key') === array_keys(
            red_store_lite_checkout_pickup_input()
        )
            && count($presentation['fields']) === 12,
        'presentation fields exactly match the closed decoder field order'
    );
    $fulfillmentField = $presentation['fields'][3];
    $paymentField = $presentation['fields'][11];
    red_store_lite_checkout_assert(
        $fulfillmentField['options'] === [[
            'value' => 'pickup',
            'label' => 'Pickup',
            'feeMinor' => 0,
            'currency' => 'USD',
        ], [
            'value' => 'delivery',
            'label' => 'Delivery',
            'feeMinor' => 700,
            'currency' => 'USD',
        ]]
            && $paymentField['options'] === [[
                'value' => 'pay_on_receipt',
                'label' => 'Pay on receipt',
            ], [
                'value' => 'stripe_checkout',
                'label' => 'Card or wallet (Stripe Checkout)',
            ]],
        'presenter exposes server fees and only currently ready payment choices'
    );
    $allReadyPresentation =
        RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter::present(
            red_store_lite_checkout_configuration(),
            array_fill_keys([
                'pay_on_receipt', 'stripe_checkout', 'paypal',
                'zelle_manual', 'nequi',
            ], true)
        );
    red_store_lite_checkout_assert(
        array_column(
            $allReadyPresentation['fields'][11]['options'],
            'value'
        ) === [
            'pay_on_receipt', 'stripe_checkout', 'paypal', 'zelle_manual',
            'nequi',
        ]
            && array_column(
                $allReadyPresentation['fields'][11]['options'],
                'label'
            ) === [
                'Pay on receipt', 'Card or wallet (Stripe Checkout)',
                'PayPal', 'Zelle', 'Nequi',
            ],
        'each approved payment method has one distinct readiness-gated label'
    );
    red_store_lite_checkout_assert(
        $presentation['fields'][2]['requiredWhen'] === [
            'field' => 'fulfillment-method',
            'equals' => 'delivery',
        ]
            && $presentation['fields'][4]['requiredWhen']
                === $presentation['fields'][2]['requiredWhen']
            && $presentation['fields'][5]['visibleWhen']
                === $presentation['fields'][2]['requiredWhen']
            && $presentation['fields'][10]['visibleWhen']
                === $presentation['fields'][2]['requiredWhen'],
        'phone and address controls carry exact delivery-only conditions'
    );
    red_store_lite_checkout_assert(
        !array_key_exists('route', $presentation)
            && !array_key_exists('mutation', $presentation)
            && !array_key_exists('action', $presentation)
            && !str_contains(serialize($presentation), 'csrf')
            && !str_contains(serialize($presentation), 'idempotency'),
        'pure model cannot invent a route, mutation, action, or browser evidence'
    );
    $entrypoint = file_get_contents($packageRoot . '/addon.php');
    red_store_lite_checkout_assert(
        is_string($entrypoint)
            && !str_contains($entrypoint, 'GuestCheckoutCommand')
            && !str_contains($entrypoint, 'PublicGuestCheckoutPresenter')
            && !str_contains(
                $entrypoint,
                'redcms.store-lite/guest-checkout'
            ),
        'checkout decoder and presenter remain absent from runtime registration'
    );

    $pickup = RED_CMS_Store_Lite_Guest_Checkout_Command::decode(
        red_store_lite_checkout_pickup_input(),
        red_store_lite_checkout_configuration(),
        red_store_lite_checkout_readiness()
    );
    red_store_lite_checkout_assert(
        $pickup === [
            'valid' => true,
            'reason' => 'valid',
            'checkout' => [
                'customer' => [
                    'name' => 'Taylor Customer',
                    'email' => 'taylor@example.com',
                    'phone' => null,
                ],
                'fulfillmentMethod' => 'pickup',
                'deliveryAddress' => null,
                'paymentMethod' => 'pay_on_receipt',
            ],
        ],
        'pickup browser scalars decode to the exact address-free checkout intent'
    );
    $pickupSnapshot = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
        red_store_lite_checkout_cart(),
        $pickup['checkout'],
        red_store_lite_checkout_configuration()
    );
    red_store_lite_checkout_assert(
        $pickupSnapshot['valid'] === true
            && $pickupSnapshot['snapshot']['fulfillment']['feeMinor'] === 0
            && $pickupSnapshot['snapshot']['fulfillment']['deliveryAddress']
                === null
            && $pickupSnapshot['initialState']['paymentStatus']
                === 'due_on_receipt',
        'decoded pickup feeds the immutable snapshot without translation drift'
    );

    $delivery = RED_CMS_Store_Lite_Guest_Checkout_Command::decode(
        red_store_lite_checkout_delivery_input(),
        red_store_lite_checkout_configuration(),
        red_store_lite_checkout_readiness()
    );
    red_store_lite_checkout_assert(
        $delivery['valid'] === true
            && $delivery['checkout']['customer'] === [
                'name' => 'Morgan Customer',
                'email' => 'morgan@example.com',
                'phone' => '+1 (202) 555-0144',
            ]
            && $delivery['checkout']['deliveryAddress'] === [
                'line1' => '100 Main Street',
                'line2' => 'Apartment 4',
                'city' => 'Arlington',
                'region' => 'VA',
                'postalCode' => '22201',
                'countryCode' => 'US',
                'instructions' => 'Leave with the front desk.',
            ]
            && $delivery['checkout']['paymentMethod'] === 'stripe_checkout',
        'delivery browser scalars decode to the exact closed address and hosted intent'
    );
    $deliverySnapshot = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
        red_store_lite_checkout_cart(),
        $delivery['checkout'],
        red_store_lite_checkout_configuration()
    );
    red_store_lite_checkout_assert(
        $deliverySnapshot['valid'] === true
            && $deliverySnapshot['snapshot']['fulfillment']['feeMinor'] === 700
            && $deliverySnapshot['snapshot']['totalMinor'] === 3199
            && $deliverySnapshot['initialState']['paymentStatus'] === 'pending',
        'decoded delivery feeds server fee and pending provider intent into the snapshot'
    );

    $unreadyPayPal = red_store_lite_checkout_delivery_input();
    $unreadyPayPal['payment-method'] = 'paypal';
    red_store_lite_checkout_assert(
        RED_CMS_Store_Lite_Guest_Checkout_Command::decode(
            $unreadyPayPal,
            red_store_lite_checkout_configuration(),
            red_store_lite_checkout_readiness()
        ) === [
            'valid' => false,
            'reason' => 'invalid_intent',
            'checkout' => null,
        ],
        'configured but currently unready provider cannot become checkout intent'
    );

    $allUnready = array_fill_keys([
        'pay_on_receipt', 'stripe_checkout', 'paypal', 'zelle_manual', 'nequi',
    ], false);
    red_store_lite_checkout_assert(
        RED_CMS_Store_Lite_Guest_Checkout_Command::availability(
            red_store_lite_checkout_configuration(),
            $allUnready
        ) === null
            && RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter::present(
                red_store_lite_checkout_configuration(),
                $allUnready
            ) === null,
        'checkout is absent when no configured payment method is currently ready'
    );

    $invalidCases = [];
    $reordered = red_store_lite_checkout_pickup_input();
    $first = array_shift($reordered);
    $reordered['customer-name'] = $first;
    $invalidCases['reordered'] = $reordered;
    $extra = red_store_lite_checkout_pickup_input();
    $extra['total'] = '1';
    $invalidCases['browser-total'] = $extra;
    $providerReference = red_store_lite_checkout_pickup_input();
    $providerReference['provider-reference'] = 'browser-claim';
    $invalidCases['provider-reference'] = $providerReference;
    $missing = red_store_lite_checkout_pickup_input();
    unset($missing['customer-email']);
    $invalidCases['missing'] = $missing;
    $nested = red_store_lite_checkout_pickup_input();
    $nested['customer-name'] = ['Taylor'];
    $invalidCases['nested'] = $nested;
    $whitespace = red_store_lite_checkout_pickup_input();
    $whitespace['customer-name'] = ' Taylor ';
    $invalidCases['whitespace'] = $whitespace;
    $badEmail = red_store_lite_checkout_pickup_input();
    $badEmail['customer-email'] = 'not-an-email';
    $invalidCases['email'] = $badEmail;
    $pickupAddress = red_store_lite_checkout_pickup_input();
    $pickupAddress['delivery-city'] = 'Arlington';
    $invalidCases['pickup-address'] = $pickupAddress;
    $missingPhone = red_store_lite_checkout_delivery_input();
    $missingPhone['customer-phone'] = '';
    $invalidCases['delivery-phone'] = $missingPhone;
    $shortPhone = red_store_lite_checkout_delivery_input();
    $shortPhone['customer-phone'] = '555';
    $invalidCases['short-phone'] = $shortPhone;
    $missingAddress = red_store_lite_checkout_delivery_input();
    $missingAddress['delivery-line1'] = '';
    $invalidCases['delivery-address'] = $missingAddress;
    $lowerCountry = red_store_lite_checkout_delivery_input();
    $lowerCountry['delivery-country-code'] = 'us';
    $invalidCases['country'] = $lowerCountry;
    $longInstructions = red_store_lite_checkout_delivery_input();
    $longInstructions['delivery-instructions'] = str_repeat('x', 501);
    $invalidCases['instructions'] = $longInstructions;
    $largeBody = red_store_lite_checkout_delivery_input();
    $largeBody['customer-name'] = str_repeat('%', 120);
    $largeBody['delivery-line1'] = str_repeat('%', 160);
    $largeBody['delivery-line2'] = str_repeat('%', 160);
    $largeBody['delivery-city'] = str_repeat('%', 160);
    $largeBody['delivery-region'] = str_repeat('%', 160);
    $largeBody['delivery-postal-code'] = str_repeat('%', 32);
    $largeBody['delivery-instructions'] = str_repeat('%', 500);
    $invalidCases['encoded-body-limit'] = $largeBody;

    foreach ($invalidCases as $name => $input) {
        red_store_lite_checkout_assert(
            RED_CMS_Store_Lite_Guest_Checkout_Command::decode(
                $input,
                red_store_lite_checkout_configuration(),
                red_store_lite_checkout_readiness()
            ) === [
                'valid' => false,
                'reason' => 'invalid_intent',
                'checkout' => null,
            ],
            $name . ' fails uniformly without partial PII or commercial data'
        );
    }

    foreach ([
        [],
        array_merge(red_store_lite_checkout_readiness(), ['unknown' => true]),
        array_merge(
            red_store_lite_checkout_readiness(),
            ['stripe_checkout' => 1]
        ),
    ] as $invalidReadiness) {
        red_store_lite_checkout_assert(
            RED_CMS_Store_Lite_Guest_Checkout_Command::availability(
                red_store_lite_checkout_configuration(),
                $invalidReadiness
            ) === null,
            'payment readiness requires the exact five-method boolean shape'
        );
    }

    $invalidConfigurations = [];
    $badCurrency = red_store_lite_checkout_configuration();
    $badCurrency['currency'] = 'usd';
    $invalidConfigurations[] = $badCurrency;
    $duplicateFulfillment = red_store_lite_checkout_configuration();
    $duplicateFulfillment['fulfillmentMethods'][] = 'pickup';
    $invalidConfigurations[] = $duplicateFulfillment;
    $pickupWithFee = red_store_lite_checkout_configuration();
    $pickupWithFee['fulfillmentMethods'] = ['pickup'];
    $invalidConfigurations[] = $pickupWithFee;
    $unknownConfiguration = red_store_lite_checkout_configuration();
    $unknownConfiguration['provider-secret'] = 'forbidden';
    $invalidConfigurations[] = $unknownConfiguration;
    foreach ($invalidConfigurations as $invalidConfiguration) {
        red_store_lite_checkout_assert(
            RED_CMS_Store_Lite_Guest_Checkout_Command::availability(
                $invalidConfiguration,
                red_store_lite_checkout_readiness()
            ) === null
                && RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter::present(
                    $invalidConfiguration,
                    red_store_lite_checkout_readiness()
                ) === null,
            'malformed or expanded installation configuration fails closed'
        );
    }

    echo 'Store Lite guest checkout contract passed ' . $assertions
        . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
