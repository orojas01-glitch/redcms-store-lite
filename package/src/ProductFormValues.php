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
            return null;
        }
        $options = self::options($values['options']);
        $variants = self::variants($values['variants']);
        if ($options === null || $variants === null) {
            return null;
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
        return !empty($normalized['valid'])
            && is_array($normalized['product'] ?? null)
            ? $normalized['product']
            : null;
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
