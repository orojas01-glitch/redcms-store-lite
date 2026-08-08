<?php

declare(strict_types=1);

/**
 * Pure Store Lite product-contract normalization.
 *
 * This class opens no database, reads no request state, performs no filesystem
 * work, and does not register package runtime behavior.
 */
final class RED_CMS_Store_Lite_Product_Normalizer
{
    public static function bounds(): array
    {
        return [
            'maxIdentifierLength' => 64,
            'maxSkuLength' => 64,
            'maxTitleLength' => 160,
            'maxSummaryLength' => 1000,
            'maxOptionGroups' => 3,
            'maxOptionValues' => 16,
            'maxVariants' => 128,
            'maxPriceMinor' => 999999999,
            'maxStock' => 1000000000,
        ];
    }

    public static function normalize(
        array $input,
        string $installationCurrency
    ): array {
        $bounds = self::bounds();
        $errors = self::unknownKeys($input, [
            'id', 'type', 'title', 'summary', 'currency', 'state',
            'availability', 'imageRef', 'sku', 'priceMinor', 'stock',
            'options', 'variants',
        ]);
        foreach (['id', 'type', 'title', 'currency', 'state', 'availability'] as $key) {
            if (!array_key_exists($key, $input)) {
                $errors[] = $key . '_missing';
            }
        }
        if ($errors !== []) {
            return self::invalid($errors);
        }

        if (!self::identifier($input['id'], $bounds['maxIdentifierLength'])) {
            $errors[] = 'id_invalid';
        }
        if (!in_array($input['type'], ['simple', 'variable'], true)) {
            $errors[] = 'type_invalid';
        }
        if (!self::text($input['title'], 1, $bounds['maxTitleLength'])) {
            $errors[] = 'title_invalid';
        }
        if (array_key_exists('summary', $input)
            && $input['summary'] !== null
            && !self::text($input['summary'], 1, $bounds['maxSummaryLength'])
        ) {
            $errors[] = 'summary_invalid';
        }
        if (preg_match('/\A[A-Z]{3}\z/D', $installationCurrency) !== 1
            || $input['currency'] !== $installationCurrency
        ) {
            $errors[] = 'currency_invalid';
        }
        if (!in_array($input['state'], ['draft', 'published', 'archived'], true)) {
            $errors[] = 'state_invalid';
        }
        if (!in_array($input['availability'], ['available', 'unavailable'], true)) {
            $errors[] = 'availability_invalid';
        }
        if (array_key_exists('imageRef', $input)
            && $input['imageRef'] !== null
            && !self::imageReference($input['imageRef'])
        ) {
            $errors[] = 'image_ref_invalid';
        }

        $product = [
            'id' => $input['id'],
            'type' => $input['type'],
            'title' => $input['title'],
            'summary' => array_key_exists('summary', $input) ? $input['summary'] : null,
            'currency' => $installationCurrency,
            'state' => $input['state'],
            'availability' => $input['availability'],
            'imageRef' => array_key_exists('imageRef', $input) ? $input['imageRef'] : null,
            'sku' => null,
            'priceMinor' => null,
            'stock' => null,
            'options' => [],
            'variants' => [],
        ];

        if ($input['type'] === 'simple') {
            self::normalizeSimple($input, $bounds, $product, $errors);
        } elseif ($input['type'] === 'variable') {
            self::normalizeVariable($input, $bounds, $product, $errors);
        }

        return $errors === []
            ? ['valid' => true, 'product' => $product, 'errors' => []]
            : self::invalid($errors);
    }

    private static function normalizeSimple(
        array $input,
        array $bounds,
        array &$product,
        array &$errors
    ): void {
        if (!array_key_exists('sku', $input)
            || !self::sku($input['sku'], $bounds['maxSkuLength'])
        ) {
            $errors[] = 'sku_invalid';
        } else {
            $product['sku'] = $input['sku'];
        }
        if (!array_key_exists('priceMinor', $input)
            || !self::integer($input['priceMinor'], 0, $bounds['maxPriceMinor'])
        ) {
            $errors[] = 'price_minor_invalid';
        } else {
            $product['priceMinor'] = $input['priceMinor'];
        }
        $product['stock'] = self::optionalInteger(
            $input,
            'stock',
            0,
            $bounds['maxStock'],
            $errors
        );
        if (array_key_exists('options', $input) && $input['options'] !== []) {
            $errors[] = 'simple_options_forbidden';
        }
        if (array_key_exists('variants', $input) && $input['variants'] !== []) {
            $errors[] = 'simple_variants_forbidden';
        }
    }

    private static function normalizeVariable(
        array $input,
        array $bounds,
        array &$product,
        array &$errors
    ): void {
        foreach (['sku', 'priceMinor', 'stock'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                $errors[] = 'variable_' . $key . '_forbidden';
            }
        }
        $groups = $input['options'] ?? null;
        if (!is_array($groups)
            || !array_is_list($groups)
            || count($groups) < 1
            || count($groups) > $bounds['maxOptionGroups']
        ) {
            $errors[] = 'option_group_count_invalid';
            $groups = [];
        }

        $groupValues = [];
        foreach ($groups as $group) {
            if (!is_array($group)
                || self::unknownKeys($group, ['key', 'label', 'values']) !== []
                || !self::identifier($group['key'] ?? null, 32)
                || !self::text($group['label'] ?? null, 1, 80)
            ) {
                $errors[] = 'option_group_invalid';
                continue;
            }
            $groupKey = $group['key'];
            if (isset($groupValues[$groupKey])) {
                $errors[] = 'option_group_key_duplicate';
                continue;
            }
            $values = $group['values'] ?? null;
            if (!is_array($values)
                || !array_is_list($values)
                || count($values) < 1
                || count($values) > $bounds['maxOptionValues']
            ) {
                $errors[] = 'option_value_count_invalid';
                continue;
            }
            $seenValues = [];
            $normalizedValues = [];
            foreach ($values as $value) {
                if (!is_array($value)
                    || self::unknownKeys($value, ['id', 'label']) !== []
                    || !self::identifier($value['id'] ?? null, 32)
                    || !self::text($value['label'] ?? null, 1, 80)
                ) {
                    $errors[] = 'option_value_invalid';
                    continue;
                }
                if (isset($seenValues[$value['id']])) {
                    $errors[] = 'option_value_duplicate';
                    continue;
                }
                $seenValues[$value['id']] = true;
                $normalizedValues[] = [
                    'id' => $value['id'],
                    'label' => $value['label'],
                ];
            }
            $groupValues[$groupKey] = $seenValues;
            $product['options'][] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'values' => $normalizedValues,
            ];
        }

        $variants = $input['variants'] ?? null;
        if (!is_array($variants)
            || !array_is_list($variants)
            || count($variants) < 1
            || count($variants) > $bounds['maxVariants']
        ) {
            $errors[] = 'variant_count_invalid';
            $variants = [];
        }
        $seenVariantIds = [];
        $seenSkus = [];
        $seenTuples = [];
        foreach ($variants as $variant) {
            self::normalizeVariant(
                $variant,
                $groupValues,
                $bounds,
                $seenVariantIds,
                $seenSkus,
                $seenTuples,
                $product['variants'],
                $errors
            );
        }
    }

    private static function normalizeVariant(
        mixed $variant,
        array $groupValues,
        array $bounds,
        array &$seenVariantIds,
        array &$seenSkus,
        array &$seenTuples,
        array &$normalizedVariants,
        array &$errors
    ): void {
        if (!is_array($variant)
            || self::unknownKeys($variant, [
                'id', 'sku', 'options', 'priceMinor', 'availability', 'stock', 'imageRef',
            ]) !== []
        ) {
            $errors[] = 'variant_invalid';
            return;
        }
        $variantId = $variant['id'] ?? null;
        $sku = $variant['sku'] ?? null;
        if (!self::identifier($variantId, $bounds['maxIdentifierLength'])) {
            $errors[] = 'variant_id_invalid';
        } elseif (isset($seenVariantIds[$variantId])) {
            $errors[] = 'variant_id_duplicate';
        } else {
            $seenVariantIds[$variantId] = true;
        }
        if (!self::sku($sku, $bounds['maxSkuLength'])) {
            $errors[] = 'variant_sku_invalid';
        } elseif (isset($seenSkus[$sku])) {
            $errors[] = 'variant_sku_duplicate';
        } else {
            $seenSkus[$sku] = true;
        }

        $selected = $variant['options'] ?? null;
        $selectedKeys = is_array($selected) ? array_keys($selected) : [];
        $expectedKeys = array_keys($groupValues);
        sort($selectedKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if (!is_array($selected) || $selectedKeys !== $expectedKeys) {
            $errors[] = 'variant_options_invalid';
            $selected = [];
        }
        $tuple = [];
        $normalizedSelected = [];
        foreach ($groupValues as $key => $values) {
            $valueId = $selected[$key] ?? null;
            if (!is_string($valueId) || !isset($values[$valueId])) {
                $errors[] = 'variant_option_value_invalid';
                continue;
            }
            $tuple[$key] = $valueId;
            $normalizedSelected[$key] = $valueId;
        }
        ksort($tuple, SORT_STRING);
        $tupleKey = json_encode($tuple, JSON_UNESCAPED_SLASHES);
        if (is_string($tupleKey) && isset($seenTuples[$tupleKey])) {
            $errors[] = 'variant_option_tuple_duplicate';
        } elseif (is_string($tupleKey)) {
            $seenTuples[$tupleKey] = true;
        }
        if (!self::integer($variant['priceMinor'] ?? null, 0, $bounds['maxPriceMinor'])) {
            $errors[] = 'variant_price_minor_invalid';
        }
        if (!in_array($variant['availability'] ?? null, ['available', 'unavailable'], true)) {
            $errors[] = 'variant_availability_invalid';
        }
        $stock = self::optionalInteger(
            $variant,
            'stock',
            0,
            $bounds['maxStock'],
            $errors
        );
        if (array_key_exists('imageRef', $variant)
            && $variant['imageRef'] !== null
            && !self::imageReference($variant['imageRef'])
        ) {
            $errors[] = 'variant_image_ref_invalid';
        }
        $normalizedVariants[] = [
            'id' => $variantId,
            'sku' => $sku,
            'options' => $normalizedSelected,
            'priceMinor' => $variant['priceMinor'] ?? null,
            'availability' => $variant['availability'] ?? null,
            'stock' => $stock,
            'imageRef' => array_key_exists('imageRef', $variant)
                ? $variant['imageRef']
                : null,
        ];
    }

    private static function invalid(array $errors): array
    {
        $errors = array_values(array_unique($errors));
        sort($errors, SORT_STRING);
        return ['valid' => false, 'product' => null, 'errors' => $errors];
    }

    private static function unknownKeys(array $input, array $allowed): array
    {
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        sort($unknown, SORT_STRING);
        return $unknown;
    }

    private static function text(mixed $value, int $minimum, int $maximum): bool
    {
        if (!is_string($value)
            || $value === ''
            || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return false;
        }
        $length = function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
        return $length >= $minimum && $length <= $maximum;
    }

    private static function identifier(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && strlen($value) <= $maximum
            && preg_match(
                '/\A[a-z][a-z0-9._-]{0,' . ($maximum - 1) . '}\z/D',
                $value
            ) === 1;
    }

    private static function sku(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && strlen($value) <= $maximum
            && preg_match(
                '/\A[A-Z0-9][A-Z0-9._-]{0,' . ($maximum - 1) . '}\z/D',
                $value
            ) === 1;
    }

    private static function integer(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    private static function optionalInteger(
        array $input,
        string $key,
        int $minimum,
        int $maximum,
        array &$errors
    ): ?int {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (!self::integer($input[$key], $minimum, $maximum)) {
            $errors[] = $key . '_invalid';
            return null;
        }
        return $input[$key];
    }

    private static function imageReference(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\Amedia:[a-z0-9._-]{1,120}\z/D', $value) === 1;
    }
}
