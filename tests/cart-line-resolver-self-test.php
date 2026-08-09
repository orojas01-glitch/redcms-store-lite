<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_cart_line_assert(
    bool $condition,
    string $message
): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_cart_line_simple(): array
{
    return [
        'id' => 'banana-pack',
        'type' => 'simple',
        'title' => 'Bananas, six-pack',
        'summary' => 'A simple product sold by pack.',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'imageRef' => 'media:banana-pack',
        'sku' => 'BANANA-6',
        'priceMinor' => 399,
        'stock' => 24,
        'options' => [],
        'variants' => [],
    ];
}

function red_store_lite_cart_line_variable(): array
{
    return [
        'id' => 'classic-tshirt',
        'type' => 'variable',
        'title' => 'Classic T-shirt',
        'summary' => 'A shirt with bounded size and color choices.',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'imageRef' => 'media:classic-tshirt',
        'options' => [
            [
                'key' => 'size',
                'label' => 'Size',
                'values' => [
                    ['id' => 's', 'label' => 'Small'],
                    ['id' => 'm', 'label' => 'Medium'],
                ],
            ],
            [
                'key' => 'color',
                'label' => 'Color',
                'values' => [
                    ['id' => 'red', 'label' => 'Red'],
                    ['id' => 'blue', 'label' => 'Blue'],
                ],
            ],
        ],
        'variants' => [
            [
                'id' => 'classic-tshirt-s-red',
                'sku' => 'TSHIRT-S-RED',
                'options' => ['size' => 's', 'color' => 'red'],
                'priceMinor' => 2499,
                'availability' => 'available',
                'stock' => 4,
            ],
            [
                'id' => 'classic-tshirt-m-blue',
                'sku' => 'TSHIRT-M-BLUE',
                'options' => ['size' => 'm', 'color' => 'blue'],
                'priceMinor' => 2599,
                'availability' => 'available',
                'stock' => null,
            ],
        ],
    ];
}

require_once $packageRoot . '/src/ProductNormalizer.php';
require_once $packageRoot . '/src/CartLineResolver.php';

try {
    $source = file_get_contents($packageRoot . '/src/CartLineResolver.php');
    red_store_lite_cart_line_assert(
        is_string($source)
            && !preg_match(
                '/\b(?:mysqli|PDO|curl|file_put_contents)\b|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION)/',
                $source
            ),
        'resolver has no database, request, runtime, write, or network path'
    );
    red_store_lite_cart_line_assert(
        RED_CMS_Store_Lite_Cart_Line_Resolver::bounds() === [
            'minQuantity' => 1,
            'maxQuantity' => 100,
            'maxLineTotalMinor' => 99999999900,
        ],
        'resolver publishes the fixed quantity and total bounds'
    );

    $simple = RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
        red_store_lite_cart_line_simple(),
        'USD',
        ['product' => 'banana-pack', 'quantity' => 2]
    );
    red_store_lite_cart_line_assert(
        $simple['resolved']
            && $simple['reason'] === 'resolved'
            && $simple['line']['productId'] === 'banana-pack'
            && $simple['line']['variantId'] === null
            && $simple['line']['sku'] === 'BANANA-6'
            && $simple['line']['quantity'] === 2
            && $simple['line']['unitPriceMinor'] === 399
            && $simple['line']['currency'] === 'USD'
            && $simple['line']['lineTotalMinor'] === 798
            && $simple['line']['stockTracked']
            && $simple['line']['stockAvailable'] === 24
            && preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $simple['line']['productStateSha256']
            ) === 1,
        'simple line derives all commercial values from the current product'
    );
    red_store_lite_cart_line_assert(
        array_keys($simple['line']) === [
            'productId', 'variantId', 'sku', 'title', 'optionLabels',
            'quantity', 'unitPriceMinor', 'currency', 'lineTotalMinor',
            'stockTracked', 'stockAvailable', 'productStateSha256',
        ] && $simple['line']['optionLabels'] === [],
        'resolved line has one closed canonical shape'
    );

    $variable = RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
        red_store_lite_cart_line_variable(),
        'USD',
        [
            'product' => 'classic-tshirt',
            'variant' => 'classic-tshirt-s-red',
            'quantity' => 3,
        ]
    );
    red_store_lite_cart_line_assert(
        $variable['resolved']
            && $variable['line']['variantId'] === 'classic-tshirt-s-red'
            && $variable['line']['sku'] === 'TSHIRT-S-RED'
            && $variable['line']['unitPriceMinor'] === 2499
            && $variable['line']['lineTotalMinor'] === 7497
            && $variable['line']['stockAvailable'] === 4,
        'variable line resolves one exact current sellable variant'
    );
    red_store_lite_cart_line_assert(
        $variable['line']['optionLabels'] === [
            [
                'key' => 'size',
                'label' => 'Size',
                'valueId' => 's',
                'valueLabel' => 'Small',
            ],
            [
                'key' => 'color',
                'label' => 'Color',
                'valueId' => 'red',
                'valueLabel' => 'Red',
            ],
        ],
        'variant option labels are derived in declared group order'
    );

    $untracked = RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
        red_store_lite_cart_line_variable(),
        'USD',
        [
            'product' => 'classic-tshirt',
            'variant' => 'classic-tshirt-m-blue',
            'quantity' => 100,
        ]
    );
    red_store_lite_cart_line_assert(
        $untracked['resolved']
            && !$untracked['line']['stockTracked']
            && $untracked['line']['stockAvailable'] === null
            && $untracked['line']['lineTotalMinor'] === 259900,
        'untracked stock still respects the fixed maximum quantity'
    );

    $free = red_store_lite_cart_line_simple();
    $free['priceMinor'] = 0;
    red_store_lite_cart_line_assert(
        RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
            $free,
            'USD',
            ['product' => 'banana-pack', 'quantity' => 1]
        )['line']['lineTotalMinor'] === 0,
        'zero-price products use integer zero without float coercion'
    );

    $refusals = [];
    $draft = red_store_lite_cart_line_simple();
    $draft['state'] = 'draft';
    $refusals['draft-product'] = [
        $draft,
        ['product' => 'banana-pack', 'quantity' => 1],
        'product_unavailable',
    ];
    $unavailable = red_store_lite_cart_line_simple();
    $unavailable['availability'] = 'unavailable';
    $refusals['unavailable-product'] = [
        $unavailable,
        ['product' => 'banana-pack', 'quantity' => 1],
        'product_unavailable',
    ];
    $refusals['product-mismatch'] = [
        red_store_lite_cart_line_simple(),
        ['product' => 'different-product', 'quantity' => 1],
        'product_unavailable',
    ];
    $refusals['variant-on-simple'] = [
        red_store_lite_cart_line_simple(),
        [
            'product' => 'banana-pack',
            'variant' => 'classic-tshirt-s-red',
            'quantity' => 1,
        ],
        'variant_unavailable',
    ];
    $refusals['variant-required'] = [
        red_store_lite_cart_line_variable(),
        ['product' => 'classic-tshirt', 'quantity' => 1],
        'variant_required',
    ];
    $refusals['stale-variant'] = [
        red_store_lite_cart_line_variable(),
        [
            'product' => 'classic-tshirt',
            'variant' => 'classic-tshirt-xl-green',
            'quantity' => 1,
        ],
        'variant_unavailable',
    ];
    $variantUnavailable = red_store_lite_cart_line_variable();
    $variantUnavailable['variants'][0]['availability'] = 'unavailable';
    $refusals['unavailable-variant'] = [
        $variantUnavailable,
        [
            'product' => 'classic-tshirt',
            'variant' => 'classic-tshirt-s-red',
            'quantity' => 1,
        ],
        'variant_unavailable',
    ];
    $refusals['simple-stock'] = [
        red_store_lite_cart_line_simple(),
        ['product' => 'banana-pack', 'quantity' => 25],
        'insufficient_stock',
    ];
    $refusals['variant-stock'] = [
        red_store_lite_cart_line_variable(),
        [
            'product' => 'classic-tshirt',
            'variant' => 'classic-tshirt-s-red',
            'quantity' => 5,
        ],
        'insufficient_stock',
    ];
    $invalidCurrency = red_store_lite_cart_line_simple();
    $invalidCurrency['currency'] = 'COP';
    $refusals['currency-drift'] = [
        $invalidCurrency,
        ['product' => 'banana-pack', 'quantity' => 1],
        'product_unavailable',
    ];
    $malformed = red_store_lite_cart_line_simple();
    $malformed['priceMinor'] = '399';
    $refusals['malformed-product'] = [
        $malformed,
        ['product' => 'banana-pack', 'quantity' => 1],
        'product_unavailable',
    ];

    foreach ($refusals as $name => [$product, $intent, $reason]) {
        $result = RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
            $product,
            'USD',
            $intent
        );
        red_store_lite_cart_line_assert(
            $result === [
                'resolved' => false,
                'reason' => $reason,
                'line' => null,
            ],
            $name . ' is refused without partial line data'
        );
    }

    $invalidIntents = [
        ['product' => 'banana-pack', 'quantity' => 0],
        ['product' => 'banana-pack', 'quantity' => 101],
        ['product' => 'banana-pack', 'quantity' => '2'],
        ['product' => 'BANANA-PACK', 'quantity' => 1],
        ['product' => 'banana-pack', 'variant' => null, 'quantity' => 1],
        ['product' => 'banana-pack', 'quantity' => 1, 'price' => 399],
    ];
    foreach ($invalidIntents as $index => $intent) {
        red_store_lite_cart_line_assert(
            RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
                red_store_lite_cart_line_simple(),
                'USD',
                $intent
            ) === [
                'resolved' => false,
                'reason' => 'invalid_intent',
                'line' => null,
            ],
            'invalid intent ' . ($index + 1) . ' fails closed'
        );
    }

    $edited = red_store_lite_cart_line_simple();
    $edited['priceMinor'] = 499;
    $editedLine = RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
        $edited,
        'USD',
        ['product' => 'banana-pack', 'quantity' => 2]
    );
    red_store_lite_cart_line_assert(
        $editedLine['resolved']
            && $editedLine['line']['unitPriceMinor'] === 499
            && $editedLine['line']['lineTotalMinor'] === 998
            && !hash_equals(
                $simple['line']['productStateSha256'],
                $editedLine['line']['productStateSha256']
            ),
        'current server product edits change derived price, total, and state evidence'
    );

    echo 'Store Lite cart-line resolver self-test passed: '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
