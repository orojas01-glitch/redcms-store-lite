<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_normalizer_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_normalizer_simple(): array
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

function red_store_lite_normalizer_variable(): array
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
                'id' => 'classic-tshirt-s-blue',
                'sku' => 'TSHIRT-S-BLUE',
                'options' => ['size' => 's', 'color' => 'blue'],
                'priceMinor' => 2499,
                'availability' => 'available',
                'stock' => 3,
            ],
            [
                'id' => 'classic-tshirt-m-red',
                'sku' => 'TSHIRT-M-RED',
                'options' => ['size' => 'm', 'color' => 'red'],
                'priceMinor' => 2599,
                'availability' => 'available',
                'stock' => 7,
            ],
            [
                'id' => 'classic-tshirt-m-blue',
                'sku' => 'TSHIRT-M-BLUE',
                'options' => ['size' => 'm', 'color' => 'blue'],
                'priceMinor' => 2599,
                'availability' => 'available',
                'stock' => 6,
            ],
        ],
    ];
}

require_once $packageRoot . '/src/ProductNormalizer.php';

try {
    $source = file_get_contents($packageRoot . '/src/ProductNormalizer.php');
    red_store_lite_normalizer_assert(
        is_string($source)
            && !preg_match(
                '/\b(?:mysqli|PDO|curl|file_get_contents|file_put_contents|include|require|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION))\b/',
                $source
            ),
        'normalizer has no database, filesystem, request, runtime, or network dependency'
    );
    red_store_lite_normalizer_assert(
        RED_CMS_Store_Lite_Product_Normalizer::bounds() === [
            'maxIdentifierLength' => 64,
            'maxSkuLength' => 64,
            'maxTitleLength' => 160,
            'maxSummaryLength' => 1000,
            'maxOptionGroups' => 3,
            'maxOptionValues' => 16,
            'maxVariants' => 128,
            'maxPriceMinor' => 999999999,
            'maxStock' => 1000000000,
        ],
        'package normalizer exposes the approved fixed bounds'
    );

    $simple = RED_CMS_Store_Lite_Product_Normalizer::normalize(
        red_store_lite_normalizer_simple(),
        'USD'
    );
    red_store_lite_normalizer_assert(
        $simple['valid']
            && $simple['errors'] === []
            && $simple['product']['type'] === 'simple'
            && $simple['product']['sku'] === 'BANANA-6'
            && $simple['product']['priceMinor'] === 399
            && $simple['product']['options'] === []
            && $simple['product']['variants'] === [],
        'simple banana-style product normalizes to one sellable record'
    );

    $variable = RED_CMS_Store_Lite_Product_Normalizer::normalize(
        red_store_lite_normalizer_variable(),
        'USD'
    );
    red_store_lite_normalizer_assert(
        $variable['valid']
            && count($variable['product']['options']) === 2
            && count($variable['product']['variants']) === 4
            && $variable['product']['sku'] === null
            && $variable['product']['priceMinor'] === null
            && $variable['product']['stock'] === null,
        'variable shirt normalizes explicit options and separate sellable variants'
    );
    $reordered = red_store_lite_normalizer_variable();
    $reordered['variants'][0]['options'] = ['color' => 'red', 'size' => 's'];
    $reorderedResult = RED_CMS_Store_Lite_Product_Normalizer::normalize(
        $reordered,
        'USD'
    );
    red_store_lite_normalizer_assert(
        $reorderedResult['valid']
            && array_keys($reorderedResult['product']['variants'][0]['options'])
                === ['size', 'color'],
        'variant option selections are canonicalized by declared group order'
    );
    $longMedia = red_store_lite_normalizer_simple();
    $longMedia['imageRef'] = 'media:' . str_repeat('a', 120);
    red_store_lite_normalizer_assert(
        RED_CMS_Store_Lite_Product_Normalizer::normalize($longMedia, 'USD')['valid'],
        'approved 120-character media identifiers normalize'
    );

    $invalidCases = [];
    $invalid = red_store_lite_normalizer_simple();
    $invalid['currency'] = 'COP';
    $invalidCases['currency'] = $invalid;
    $invalid = red_store_lite_normalizer_simple();
    $invalid['priceMinor'] = 3.99;
    $invalidCases['float-price'] = $invalid;
    $invalid = red_store_lite_normalizer_simple();
    $invalid['variants'] = [['id' => 'forbidden']];
    $invalidCases['simple-variants'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['sku'] = 'FORBIDDEN';
    $invalidCases['variable-parent-sku'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['options'][] = [
        'key' => 'material', 'label' => 'Material',
        'values' => [['id' => 'cotton', 'label' => 'Cotton']],
    ];
    $invalidCases['fourth-option-group'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['options'][0]['values'] = [];
    $invalidCases['empty-option-values'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['variants'][1]['options']['size'] = 'xl';
    $invalidCases['unknown-option-value'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['variants'][1]['options'] = ['size' => 's'];
    $invalidCases['missing-option-group'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['variants'][1]['id'] = $invalid['variants'][0]['id'];
    $invalidCases['duplicate-variant-id'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['variants'][1]['sku'] = $invalid['variants'][0]['sku'];
    $invalidCases['duplicate-sku'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['variants'][1]['options'] = $invalid['variants'][0]['options'];
    $invalidCases['duplicate-option-tuple'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['unknown'] = true;
    $invalidCases['unknown-field'] = $invalid;
    $invalid = red_store_lite_normalizer_variable();
    $invalid['options'] = ['size' => $invalid['options'][0]];
    $invalidCases['non-list-options'] = $invalid;
    $invalid = red_store_lite_normalizer_simple();
    $invalid['imageRef'] = 'media:UPPERCASE';
    $invalidCases['invalid-media'] = $invalid;

    foreach ($invalidCases as $name => $product) {
        $result = RED_CMS_Store_Lite_Product_Normalizer::normalize($product, 'USD');
        red_store_lite_normalizer_assert(
            !$result['valid'] && $result['product'] === null && $result['errors'] !== [],
            'invalid ' . $name . ' product fails closed without partial data'
        );
    }

    $tooManyValues = red_store_lite_normalizer_variable();
    $tooManyValues['options'][0]['values'] = [];
    for ($index = 0; $index < 17; $index++) {
        $tooManyValues['options'][0]['values'][] = [
            'id' => 'value' . $index,
            'label' => 'Value ' . $index,
        ];
    }
    red_store_lite_normalizer_assert(
        in_array(
            'option_value_count_invalid',
            RED_CMS_Store_Lite_Product_Normalizer::normalize($tooManyValues, 'USD')['errors'],
            true
        ),
        'option values are bounded at sixteen per group'
    );
    $tooManyVariants = red_store_lite_normalizer_variable();
    for ($index = 4; $index < 129; $index++) {
        $tooManyVariants['variants'][] = [
            'id' => 'variant-' . $index,
            'sku' => 'TSHIRT-' . $index,
            'options' => ['size' => 's', 'color' => 'red'],
            'priceMinor' => 2499,
            'availability' => 'available',
        ];
    }
    red_store_lite_normalizer_assert(
        in_array(
            'variant_count_invalid',
            RED_CMS_Store_Lite_Product_Normalizer::normalize($tooManyVariants, 'USD')['errors'],
            true
        ),
        'explicit variants are bounded at 128 per product parent'
    );

    echo 'Store Lite product normalizer passed ' . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
