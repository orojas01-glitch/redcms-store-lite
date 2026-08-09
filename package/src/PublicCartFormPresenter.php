<?php

declare(strict_types=1);

require_once __DIR__ . '/ProductNormalizer.php';

/**
 * Pure Store Lite adapter for the core-owned public-mutation form UI.
 *
 * It turns one current complete product into a data-only form presentation
 * model. Core remains responsible for validating the package declaration,
 * issuing browser evidence, escaping/rendering controls, request dispatch,
 * response ownership, and every commercial write.
 */
final class RED_CMS_Store_Lite_Public_Cart_Form_Presenter
{
    public const ROUTE = 'redcms.store-lite/cart-intent';
    public const MUTATION = 'redcms.store-lite/add-to-cart';

    public static function present(
        array $input,
        string $installationCurrency
    ): ?array {
        $normalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
            $input,
            $installationCurrency
        );
        if (empty($normalized['valid'])
            || !is_array($normalized['product'] ?? null)
        ) {
            return null;
        }

        $product = $normalized['product'];
        if ($product['state'] !== 'published'
            || $product['availability'] !== 'available'
        ) {
            return null;
        }

        $fields = [[
            'key' => 'product',
            'control' => 'hidden',
            'value' => $product['id'],
        ], [
            'key' => 'quantity',
            'control' => 'number',
            'label' => 'Quantity',
            'value' => 1,
        ]];
        if ($product['type'] === 'simple') {
            return self::simpleAvailable($product)
                ? self::model($fields)
                : null;
        }

        $variant = self::variantField($product);
        if ($variant === null) {
            return null;
        }
        $fields[] = $variant;
        return self::model($fields);
    }

    private static function model(array $fields): array
    {
        return [
            'route' => self::ROUTE,
            'mutation' => self::MUTATION,
            'submitLabel' => 'Add to cart',
            'fields' => $fields,
        ];
    }

    private static function simpleAvailable(array $product): bool
    {
        return $product['stock'] === null || $product['stock'] > 0;
    }

    private static function variantField(array $product): ?array
    {
        $valueLabels = self::optionValueLabels($product['options']);
        if ($valueLabels === null) {
            return null;
        }
        $options = [];
        $seenLabels = [];
        foreach ($product['variants'] as $variant) {
            if (!self::variantAvailable($variant)) {
                continue;
            }
            $label = self::variantLabel($product['options'], $variant, $valueLabels);
            if ($label === '' || isset($seenLabels[$label])) {
                $label = $variant['sku'];
            }
            if (!self::labelValid($label) || isset($seenLabels[$label])) {
                return null;
            }
            $seenLabels[$label] = true;
            $options[] = [
                'value' => $variant['id'],
                'label' => $label,
            ];
        }
        if ($options === [] || count($options) > 128) {
            return null;
        }
        return [
            'key' => 'variant',
            'control' => 'select',
            'label' => 'Options',
            'value' => $options[0]['value'],
            'options' => $options,
        ];
    }

    private static function optionValueLabels(array $groups): ?array
    {
        $labels = [];
        foreach ($groups as $group) {
            if (!is_array($group)
                || !is_string($group['key'] ?? null)
                || !is_string($group['label'] ?? null)
                || !is_array($group['values'] ?? null)
            ) {
                return null;
            }
            $values = [];
            foreach ($group['values'] as $value) {
                if (!is_array($value)
                    || !is_string($value['id'] ?? null)
                    || !is_string($value['label'] ?? null)
                    || isset($values[$value['id']])
                ) {
                    return null;
                }
                $values[$value['id']] = $value['label'];
            }
            $labels[$group['key']] = [
                'label' => $group['label'],
                'values' => $values,
            ];
        }
        return $labels;
    }

    private static function variantAvailable(array $variant): bool
    {
        return ($variant['availability'] ?? null) === 'available'
            && (($variant['stock'] ?? null) === null
                || (is_int($variant['stock']) && $variant['stock'] > 0));
    }

    private static function variantLabel(
        array $groups,
        array $variant,
        array $valueLabels
    ): string {
        if (!is_array($variant['options'] ?? null)
            || !is_string($variant['sku'] ?? null)
        ) {
            return '';
        }
        $parts = [];
        foreach ($groups as $group) {
            $key = $group['key'] ?? null;
            $groupLabel = $valueLabels[$key]['label'] ?? null;
            $valueId = is_string($key)
                ? ($variant['options'][$key] ?? null)
                : null;
            $valueLabel = is_string($key)
                ? ($valueLabels[$key]['values'][$valueId] ?? null)
                : null;
            if (!is_string($key)
                || !is_string($groupLabel)
                || !is_string($valueId)
                || !is_string($valueLabel)
            ) {
                return '';
            }
            $parts[] = $groupLabel . ': ' . $valueLabel;
        }
        $label = implode(' · ', $parts);
        return self::labelValid($label) ? $label : $variant['sku'];
    }

    private static function labelValid(string $label): bool
    {
        return $label !== ''
            && strlen($label) <= 120
            && preg_match('//u', $label) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $label) !== 1;
    }
}
