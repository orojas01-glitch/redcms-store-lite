<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_cart_form_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_cart_form_simple(): array
{
    return [
        'id' => 'banana-pack',
        'type' => 'simple',
        'title' => 'Bananas, six-pack',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'sku' => 'BANANA-6',
        'priceMinor' => 399,
        'stock' => 24,
        'options' => [],
        'variants' => [],
    ];
}

function red_store_lite_cart_form_variable(): array
{
    return [
        'id' => 'classic-tshirt',
        'type' => 'variable',
        'title' => 'Classic T-shirt',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'options' => [[
            'key' => 'size',
            'label' => 'Size',
            'values' => [
                ['id' => 's', 'label' => 'Small'],
                ['id' => 'm', 'label' => 'Medium'],
            ],
        ], [
            'key' => 'color',
            'label' => 'Color',
            'values' => [
                ['id' => 'red', 'label' => 'Red'],
                ['id' => 'blue', 'label' => 'Blue'],
            ],
        ]],
        'variants' => [[
            'id' => 'classic-tshirt-s-red',
            'sku' => 'TSHIRT-S-RED',
            'options' => ['size' => 's', 'color' => 'red'],
            'priceMinor' => 2499,
            'availability' => 'available',
            'stock' => 4,
        ], [
            'id' => 'classic-tshirt-m-blue',
            'sku' => 'TSHIRT-M-BLUE',
            'options' => ['size' => 'm', 'color' => 'blue'],
            'priceMinor' => 2599,
            'availability' => 'available',
            'stock' => 6,
        ]],
    ];
}

function red_store_lite_cart_form_128_variants(): array
{
    $groups = [];
    foreach ([['size', 'Size', 8], ['color', 'Color', 4], ['fit', 'Fit', 4]] as $spec) {
        [$key, $label, $count] = $spec;
        $values = [];
        for ($index = 1; $index <= $count; $index++) {
            $value = $key . $index;
            $values[] = ['id' => $value, 'label' => strtoupper($value)];
        }
        $groups[] = ['key' => $key, 'label' => $label, 'values' => $values];
    }
    $variants = [];
    $index = 1;
    foreach ($groups[0]['values'] as $size) {
        foreach ($groups[1]['values'] as $color) {
            foreach ($groups[2]['values'] as $fit) {
                $variants[] = [
                    'id' => 'variant-' . $index,
                    'sku' => 'VARIANT-' . $index,
                    'options' => [
                        'size' => $size['id'],
                        'color' => $color['id'],
                        'fit' => $fit['id'],
                    ],
                    'priceMinor' => 1000,
                    'availability' => 'available',
                    'stock' => null,
                ];
                $index++;
            }
        }
    }
    return [
        'id' => 'variant-grid',
        'type' => 'variable',
        'title' => 'Variant grid',
        'currency' => 'USD',
        'state' => 'published',
        'availability' => 'available',
        'options' => $groups,
        'variants' => $variants,
    ];
}

require_once $packageRoot . '/src/PublicCartFormPresenter.php';

try {
    $source = file_get_contents($packageRoot . '/src/PublicCartFormPresenter.php');
    red_store_lite_cart_form_assert(
        is_string($source)
            && !preg_match(
                '/\b(?:mysqli|PDO|curl|file_put_contents|echo|print|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION))\b/',
                $source
            ),
        'cart form presenter has no database, request, output, write, or network dependency'
    );

    $simple = RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present(
        red_store_lite_cart_form_simple(),
        'USD'
    );
    red_store_lite_cart_form_assert(
        $simple === [
            'route' => 'redcms.store-lite/cart-intent',
            'mutation' => 'redcms.store-lite/add-to-cart',
            'submitLabel' => 'Add to cart',
            'fields' => [[
                'key' => 'product', 'control' => 'hidden', 'value' => 'banana-pack',
            ], [
                'key' => 'quantity', 'control' => 'number', 'label' => 'Quantity', 'value' => 1,
            ]],
        ],
        'simple sellable product becomes the exact closed cart form model'
    );

    $variable = RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present(
        red_store_lite_cart_form_variable(),
        'USD'
    );
    red_store_lite_cart_form_assert(
        $variable === [
            'route' => 'redcms.store-lite/cart-intent',
            'mutation' => 'redcms.store-lite/add-to-cart',
            'submitLabel' => 'Add to cart',
            'fields' => [[
                'key' => 'product', 'control' => 'hidden', 'value' => 'classic-tshirt',
            ], [
                'key' => 'quantity', 'control' => 'number', 'label' => 'Quantity', 'value' => 1,
            ], [
                'key' => 'variant',
                'control' => 'select',
                'label' => 'Options',
                'value' => 'classic-tshirt-s-red',
                'options' => [[
                    'value' => 'classic-tshirt-s-red',
                    'label' => 'Size: Small · Color: Red',
                ], [
                    'value' => 'classic-tshirt-m-blue',
                    'label' => 'Size: Medium · Color: Blue',
                ]],
            ]],
        ],
        'variable product becomes one bounded selector over explicit variants'
    );

    $partiallySellable = red_store_lite_cart_form_variable();
    $partiallySellable['variants'][1]['stock'] = 0;
    $partiallySellableModel = RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present(
        $partiallySellable,
        'USD'
    );
    red_store_lite_cart_form_assert(
        $partiallySellableModel['fields'][2]['options'] === [[
            'value' => 'classic-tshirt-s-red',
            'label' => 'Size: Small · Color: Red',
        ]],
        'unavailable or zero-stock variants are absent from the displayed selector'
    );

    $maxModel = RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present(
        red_store_lite_cart_form_128_variants(),
        'USD'
    );
    red_store_lite_cart_form_assert(
        count($maxModel['fields'][2]['options']) === 128
            && $maxModel['fields'][2]['options'][0]['value'] === 'variant-1'
            && $maxModel['fields'][2]['options'][127]['value'] === 'variant-128',
        'the complete Store Lite maximum of 128 variants remains available to core form composition'
    );

    $fallback = red_store_lite_cart_form_variable();
    $fallback['options'][0]['label'] = str_repeat('L', 80);
    $fallback['options'][0]['values'][0]['label'] = str_repeat('V', 80);
    $fallbackModel = RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present($fallback, 'USD');
    red_store_lite_cart_form_assert(
        $fallbackModel['fields'][2]['options'][0]['label'] === 'TSHIRT-S-RED',
        'overlong tuple labels fall back to the bounded normalized SKU'
    );

    $draft = red_store_lite_cart_form_simple();
    $draft['state'] = 'draft';
    $zeroStock = red_store_lite_cart_form_simple();
    $zeroStock['stock'] = 0;
    $noSellable = red_store_lite_cart_form_variable();
    foreach ($noSellable['variants'] as &$variant) {
        $variant['availability'] = 'unavailable';
    }
    unset($variant);
    red_store_lite_cart_form_assert(
        RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present($draft, 'USD') === null
            && RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present($zeroStock, 'USD') === null
            && RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present($noSellable, 'USD') === null
            && RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present(
                red_store_lite_cart_form_simple(),
                'COP'
            ) === null,
        'draft, unavailable, non-sellable, and currency-drift products fail closed'
    );

    $malformed = red_store_lite_cart_form_simple();
    $malformed['priceMinor'] = '399';
    red_store_lite_cart_form_assert(
        RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present($malformed, 'USD') === null,
        'malformed product data never becomes a partial form model'
    );

    red_store_lite_cart_form_assert(
        !str_contains(serialize($variable), 'priceMinor')
            && !str_contains(serialize($variable), 'currency')
            && !str_contains(serialize($variable), 'stock')
            && !str_contains(serialize($variable), '2499'),
        'the form model omits commercial facts and retains only declared public fields'
    );

    printf(
        "Store Lite public cart form presenter self-test passed: %d assertions.\n",
        $assertions
    );
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . ' (after ' . $assertions . " assertions)\n");
    exit(1);
}
