<?php

declare(strict_types=1);

require_once __DIR__ . '/ProductNormalizer.php';

/**
 * Pure translation between the core-owned typed form graph and one product.
 *
 * This class has no database, request, runtime, route, or persistence
 * dependency. A later value loader binds its numeric target to a current
 * ProductID before calling toProduct().
 */
final class RED_CMS_Store_Lite_Product_Form_Values
{
    private const PRODUCT_KEYS = [
        'id', 'type', 'title', 'summary', 'currency', 'state',
        'availability', 'image-reference', 'sku', 'price-minor', 'stock',
        'options', 'variants',
    ];

    public static function fromProduct(array $product): ?array
    {
        $currency = $product['currency'] ?? null;
        if (!is_string($currency)) {
            return null;
        }
        $normalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
            $product,
            $currency
        );
        if (empty($normalized['valid'])
            || !is_array($normalized['product'] ?? null)
        ) {
            return null;
        }
        $product = $normalized['product'];
        $options = [];
        foreach ($product['options'] as $option) {
            $values = [];
            foreach ($option['values'] as $value) {
                $values[] = ['id' => $value['id'], 'label' => $value['label']];
            }
            $options[] = [
                'key' => $option['key'],
                'label' => $option['label'],
                'values' => $values,
            ];
        }
        $variants = [];
        foreach ($product['variants'] as $variant) {
            $selections = [];
            foreach ($variant['options'] as $key => $value) {
                $selections[] = ['key' => $key, 'value' => $value];
            }
            $variants[] = [
                'id' => $variant['id'],
                'sku' => $variant['sku'],
                'options' => $selections,
                'price-minor' => $variant['priceMinor'],
                'availability' => $variant['availability'],
                'stock' => $variant['stock'],
                'image-reference' => $variant['imageRef'],
            ];
        }
        return [
            'id' => $product['id'],
            'type' => $product['type'],
            'title' => $product['title'],
            'summary' => $product['summary'],
            'currency' => $product['currency'],
            'state' => $product['state'],
            'availability' => $product['availability'],
            'image-reference' => $product['imageRef'],
            'sku' => $product['sku'],
            'price-minor' => $product['priceMinor'],
            'stock' => $product['stock'],
            'options' => $options,
            'variants' => $variants,
        ];
    }

    public static function toProduct(
        array $values,
        string $installationCurrency,
        string $expectedProductId
    ): ?array {
        return self::evaluate(
            $values,
            $installationCurrency,
            $expectedProductId
        )['product'];
    }

    /**
     * Like toProduct(), but also returns the normalizer's error codes so a
     * refused save can tell the administrator which field to fix.
     *
     * @return array{product: ?array, errors: list<string>}
     */
    public static function evaluate(
        array $values,
        string $installationCurrency,
        string $expectedProductId
    ): array {
        if (!self::exactKeys($values, self::PRODUCT_KEYS)
            || !is_string($values['id'])
            || !hash_equals($expectedProductId, $values['id'])
            || !is_string($values['type'])
            || !is_string($values['title'])
            || !is_string($values['currency'])
            || !is_string($values['state'])
            || !is_string($values['availability'])
            || !self::nullableString($values['summary'])
            || !self::nullableString($values['image-reference'])
            || !self::nullableString($values['sku'])
            || !self::nullableInteger($values['price-minor'])
            || !self::nullableInteger($values['stock'])
        ) {
            return ['product' => null, 'errors' => ['values_invalid']];
        }
        $options = self::options($values['options']);
        $variants = self::variants($values['variants']);
        if ($options === null || $variants === null) {
            return [
                'product' => null,
                'errors' => [$options === null
                    ? 'option_group_invalid'
                    : 'variant_invalid'],
            ];
        }
        $input = [
            'id' => $values['id'],
            'type' => $values['type'],
            'title' => $values['title'],
            'summary' => self::emptyToNull($values['summary']),
            'currency' => $values['currency'],
            'state' => $values['state'],
            'availability' => $values['availability'],
            'imageRef' => self::emptyToNull($values['image-reference']),
            'sku' => self::emptyToNull($values['sku']),
            'priceMinor' => $values['price-minor'],
            'stock' => $values['stock'],
            'options' => $options,
            'variants' => $variants,
        ];
        $normalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
            $input,
            $installationCurrency
        );
        if (!empty($normalized['valid'])
            && is_array($normalized['product'] ?? null)
        ) {
            return ['product' => $normalized['product'], 'errors' => []];
        }
        $errors = array_values(array_filter(
            is_array($normalized['errors'] ?? null)
                ? $normalized['errors']
                : [],
            'is_string'
        ));
        return [
            'product' => null,
            'errors' => $errors === [] ? ['values_invalid'] : $errors,
        ];
    }

    /**
     * Maps normalizer error codes to administrator-facing problems keyed by
     * the product-editor field to fix. Unknown codes fall back to one
     * general message, so a refusal is never silent.
     *
     * @param list<string> $errors
     * @return list<array{field: string, message: string}>
     */
    public static function problems(array $errors, string $currency): array
    {
        $priceExample = $currency === 'COP'
            ? 'For COP enter pesos x 100, so 10.000 COP is 1000000.'
            : 'For example ' . $currency . ' 12.50 is 1250.';
        $messages = [
            'id_invalid' => ['id', 'Product ID must start with a lowercase letter and use only lowercase letters, digits, dots, dashes or underscores (for example cafe-500g).'],
            'id_missing' => ['id', 'Product ID is required.'],
            'id_taken' => ['id', 'Another product already uses this Product ID. Choose a different one.'],
            'type_invalid' => ['type', 'Choose a product type.'],
            'title_invalid' => ['title', 'Title is required (up to 160 characters).'],
            'summary_invalid' => ['summary', 'Summary can be up to 1000 characters.'],
            'currency_invalid' => ['currency', 'Installation currency must be ' . $currency . ', the store catalog currency.'],
            'state_invalid' => ['state', 'Choose a state.'],
            'availability_invalid' => ['availability', 'Choose an availability.'],
            'image_ref_invalid' => ['image-reference', 'Choose an image from the media library or leave the image empty.'],
            'sku_invalid' => ['sku', 'SKU is required for a simple product: UPPERCASE letters, digits, dots, dashes or underscores (for example CAFE-500G).'],
            'price_minor_invalid' => ['price-minor', 'Price is required for a simple product, in minor currency units. ' . $priceExample],
            'stock_invalid' => ['stock', 'Stock must be a whole number of 0 or more, or empty for unlimited.'],
            'simple_options_forbidden' => ['options', 'A simple product cannot have options. Remove them or choose "Product with variants".'],
            'simple_variants_forbidden' => ['variants', 'A simple product cannot have variants. Remove them or choose "Product with variants".'],
            'variable_sku_forbidden' => ['sku', 'Leave SKU empty for a product with variants; each variant has its own SKU.'],
            'variable_priceMinor_forbidden' => ['price-minor', 'Leave price empty for a product with variants; each variant has its own price.'],
            'variable_stock_forbidden' => ['stock', 'Leave stock empty for a product with variants; each variant has its own stock.'],
            'option_group_count_invalid' => ['options', 'A product with variants needs 1 to 3 options (for example Size).'],
            'option_group_invalid' => ['options', 'Each option needs a lowercase key (for example size) and a label.'],
            'option_group_key_duplicate' => ['options', 'Two options use the same key.'],
            'option_value_count_invalid' => ['options', 'Each option needs 1 to 16 values.'],
            'option_value_invalid' => ['options', 'Each option value needs a lowercase ID (for example small) and a label.'],
            'option_value_duplicate' => ['options', 'Two values of one option use the same ID.'],
            'variant_count_invalid' => ['variants', 'Add at least one variant (up to 128).'],
            'variant_invalid' => ['variants', 'A variant is incomplete.'],
            'variant_id_invalid' => ['variants', 'Each variant ID must start with a lowercase letter (for example cafe-500g-small).'],
            'variant_id_duplicate' => ['variants', 'Two variants use the same ID.'],
            'variant_sku_invalid' => ['variants', 'Each variant needs a SKU in UPPERCASE letters, digits, dots, dashes or underscores (for example CAFE-500G-S).'],
            'variant_sku_duplicate' => ['variants', 'Two variants use the same SKU.'],
            'variant_options_invalid' => ['variants', 'Each variant must pick exactly one value for every option.'],
            'variant_option_value_invalid' => ['variants', 'A variant picks an option value that does not exist.'],
            'variant_option_tuple_duplicate' => ['variants', 'Two variants pick the same combination of option values.'],
            'variant_price_minor_invalid' => ['variants', 'Each variant needs a price in minor currency units. ' . $priceExample],
            'variant_availability_invalid' => ['variants', 'Choose an availability for each variant.'],
            'variant_stock_invalid' => ['variants', 'Variant stock must be a whole number of 0 or more, or empty.'],
            'variant_image_ref_invalid' => ['variants', 'Choose a variant image from the media library or leave it empty.'],
        ];
        $problems = [];
        $seen = [];
        foreach ($errors as $error) {
            if (!isset($messages[$error]) || isset($seen[$error])) {
                continue;
            }
            $seen[$error] = true;
            $problems[] = [
                'field' => $messages[$error][0],
                'message' => $messages[$error][1],
            ];
        }
        if ($problems === []) {
            $problems[] = [
                'field' => 'id',
                'message' => 'Some product values are invalid. Check the required fields and formats.',
            ];
        }
        return $problems;
    }

    /**
     * Throws core's typed refusal when this core supports it, so the editor
     * shows each problem; older cores get the previous generic failure.
     *
     * @param list<string> $errors
     */
    public static function refuse(array $errors, string $currency): never
    {
        $problems = self::problems($errors, $currency);
        if (class_exists('RED_Addon_Admin_Tool_Form_Values_Refused', false)) {
            throw new RED_Addon_Admin_Tool_Form_Values_Refused($problems);
        }
        throw new RuntimeException('Store Lite product values are invalid.');
    }

    private static function options(mixed $options): ?array
    {
        if (!is_array($options) || !array_is_list($options)) {
            return null;
        }
        $result = [];
        foreach ($options as $option) {
            if (!is_array($option)
                || !self::exactKeys($option, ['key', 'label', 'values'])
                || !is_string($option['key'])
                || !is_string($option['label'])
                || !is_array($option['values'])
                || !array_is_list($option['values'])
            ) {
                return null;
            }
            $values = [];
            foreach ($option['values'] as $value) {
                if (!is_array($value)
                    || !self::exactKeys($value, ['id', 'label'])
                    || !is_string($value['id'])
                    || !is_string($value['label'])
                ) {
                    return null;
                }
                $values[] = ['id' => $value['id'], 'label' => $value['label']];
            }
            $result[] = [
                'key' => $option['key'],
                'label' => $option['label'],
                'values' => $values,
            ];
        }
        return $result;
    }

    private static function variants(mixed $variants): ?array
    {
        if (!is_array($variants) || !array_is_list($variants)) {
            return null;
        }
        $result = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)
                || !self::exactKeys($variant, [
                    'id', 'sku', 'options', 'price-minor', 'availability',
                    'stock', 'image-reference',
                ])
                || !is_string($variant['id'])
                || !is_string($variant['sku'])
                || !is_string($variant['availability'])
                || !self::nullableInteger($variant['price-minor'])
                || !self::nullableInteger($variant['stock'])
                || !self::nullableString($variant['image-reference'])
                || !is_array($variant['options'])
                || !array_is_list($variant['options'])
            ) {
                return null;
            }
            $selections = [];
            foreach ($variant['options'] as $selection) {
                if (!is_array($selection)
                    || !self::exactKeys($selection, ['key', 'value'])
                    || !is_string($selection['key'])
                    || !is_string($selection['value'])
                    || isset($selections[$selection['key']])
                ) {
                    return null;
                }
                $selections[$selection['key']] = $selection['value'];
            }
            $result[] = [
                'id' => $variant['id'],
                'sku' => $variant['sku'],
                'options' => $selections,
                'priceMinor' => $variant['price-minor'],
                'availability' => $variant['availability'],
                'stock' => $variant['stock'],
                'imageRef' => self::emptyToNull($variant['image-reference']),
            ];
        }
        return $result;
    }

    private static function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function nullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    private static function nullableInteger(mixed $value): bool
    {
        return $value === null || is_int($value);
    }

    private static function emptyToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
