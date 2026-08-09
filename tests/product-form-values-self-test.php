<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$packageRoot = dirname(__DIR__) . '/package';

function red_store_lite_form_values_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function red_store_lite_form_values_variable(): array
{
    return [
        'id' => 'classic-tshirt', 'type' => 'variable', 'title' => 'Classic T-shirt',
        'summary' => null, 'currency' => 'USD', 'state' => 'published',
        'availability' => 'available', 'imageRef' => null, 'sku' => null,
        'priceMinor' => null, 'stock' => null,
        'options' => [[
            'key' => 'size', 'label' => 'Size',
            'values' => [['id' => 's', 'label' => 'Small']],
        ]],
        'variants' => [[
            'id' => 'classic-tshirt-s', 'sku' => 'TSHIRT-S',
            'options' => ['size' => 's'], 'priceMinor' => 2499,
            'availability' => 'available', 'stock' => 4, 'imageRef' => null,
        ]],
    ];
}

require_once $packageRoot . '/src/ProductFormValues.php';

try {
    $source = file_get_contents($packageRoot . '/src/ProductFormValues.php');
    red_store_lite_form_values_assert(
        is_string($source)
            && !preg_match('/\\b(?:mysqli|PDO|curl|file_get_contents|file_put_contents|\\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION))\\b/', $source),
        'typed product-form adapter has no database, filesystem, request, or network dependency'
    );

    $variable = red_store_lite_form_values_variable();
    $values = RED_CMS_Store_Lite_Product_Form_Values::fromProduct($variable);
    red_store_lite_form_values_assert(
        is_array($values)
            && $values['options'][0]['values'][0] === ['id' => 's', 'label' => 'Small']
            && $values['variants'][0]['options'] === [['key' => 'size', 'value' => 's']]
            && $values['variants'][0]['price-minor'] === 2499
            && array_key_exists('image-reference', $values),
        'normalized variable product converts to the closed typed core form graph'
    );
    $roundTrip = RED_CMS_Store_Lite_Product_Form_Values::toProduct(
        $values,
        'USD',
        'classic-tshirt'
    );
    red_store_lite_form_values_assert(
        $roundTrip === $variable,
        'typed variable form graph round-trips to the normalized product contract'
    );

    $simple = [
        'id' => 'banana-pack', 'type' => 'simple', 'title' => 'Banana pack',
        'summary' => null, 'currency' => 'USD', 'state' => 'draft',
        'availability' => 'available', 'imageRef' => null, 'sku' => 'BANANA-6',
        'priceMinor' => 399, 'stock' => 24, 'options' => [], 'variants' => [],
    ];
    $simpleValues = RED_CMS_Store_Lite_Product_Form_Values::fromProduct($simple);
    red_store_lite_form_values_assert(
        RED_CMS_Store_Lite_Product_Form_Values::toProduct(
            $simpleValues,
            'USD',
            'banana-pack'
        ) === $simple,
        'simple banana-style product retains native integer money and stock values'
    );

    $changedId = $values;
    $changedId['id'] = 'other-product';
    red_store_lite_form_values_assert(
        RED_CMS_Store_Lite_Product_Form_Values::toProduct(
            $changedId,
            'USD',
            'classic-tshirt'
        ) === null,
        'form values cannot change the target product identifier'
    );
    $duplicateSelection = $values;
    $duplicateSelection['variants'][0]['options'][] = ['key' => 'size', 'value' => 's'];
    red_store_lite_form_values_assert(
        RED_CMS_Store_Lite_Product_Form_Values::toProduct(
            $duplicateSelection,
            'USD',
            'classic-tshirt'
        ) === null,
        'duplicate variant option selections fail before product normalization'
    );
    $invalidSimple = $simpleValues;
    $invalidSimple['options'] = [[
        'key' => 'size', 'label' => 'Size', 'values' => [['id' => 's', 'label' => 'Small']],
    ]];
    red_store_lite_form_values_assert(
        RED_CMS_Store_Lite_Product_Form_Values::toProduct(
            $invalidSimple,
            'USD',
            'banana-pack'
        ) === null,
        'simple form values cannot introduce variable-product option data'
    );
    $unknown = $values;
    $unknown['unexpected'] = true;
    red_store_lite_form_values_assert(
        RED_CMS_Store_Lite_Product_Form_Values::toProduct(
            $unknown,
            'USD',
            'classic-tshirt'
        ) === null,
        'unknown typed form fields are refused without a partial product'
    );

    echo 'Store Lite product form values passed ' . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
