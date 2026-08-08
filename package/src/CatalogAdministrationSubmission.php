<?php

declare(strict_types=1);

require_once __DIR__ . '/ProductNormalizer.php';

/**
 * Pure decoder for one browser-originated product administration submission.
 *
 * Core must authenticate the administrator and consume the CSRF field before
 * passing the remaining exact field map here. This class reads no globals,
 * opens no database, renders no HTML, and performs no mutation.
 */
final class RED_CMS_Store_Lite_Catalog_Administration_Submission
{
    private const MAX_ENCODED_BYTES = 262144;
    private const MAX_NODES = 4096;
    private const MAX_DEPTH = 7;

    public static function bounds(): array
    {
        return [
            'maxEncodedBytes' => self::MAX_ENCODED_BYTES,
            'maxNodes' => self::MAX_NODES,
            'maxDepth' => self::MAX_DEPTH,
        ];
    }

    public static function decode(
        array $fields,
        string $installationCurrency
    ): array {
        $result = self::result('invalid_submission');
        if (!self::validCurrency($installationCurrency)
            || !self::bounded($fields)
            || !self::exactKeys($fields, [
                'mode',
                'expectedStateSha256',
                'planSha256',
                'product',
            ])
            || !is_string($fields['mode'] ?? null)
            || !in_array($fields['mode'], ['create', 'replace'], true)
            || !is_string($fields['expectedStateSha256'] ?? null)
            || !is_string($fields['planSha256'] ?? null)
            || !self::validSha256($fields['planSha256'])
            || !is_array($fields['product'] ?? null)
        ) {
            return $result;
        }

        $mode = $fields['mode'];
        $expectedStateSha256 = $fields['expectedStateSha256'];
        if (($mode === 'create' && $expectedStateSha256 !== '')
            || ($mode === 'replace'
                && !self::validSha256($expectedStateSha256))
        ) {
            return $result;
        }

        $typed = self::typedProduct($fields['product']);
        if ($typed === null) {
            return $result;
        }
        $normalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
            $typed,
            $installationCurrency
        );
        if (empty($normalized['valid'])
            || !is_array($normalized['product'] ?? null)
        ) {
            $result['reason'] = 'invalid_product';
            $errors = is_array($normalized['errors'] ?? null)
                ? $normalized['errors']
                : [];
            $result['errors'] = self::errorCodes($errors);
            return $result;
        }

        $product = $normalized['product'];
        $result['accepted'] = true;
        $result['mode'] = $mode;
        $result['productId'] = $product['id'];
        $result['product'] = $product;
        $result['expectedStateSha256'] = $expectedStateSha256;
        $result['planSha256'] = $fields['planSha256'];
        $result['reason'] = 'accepted';
        return $result;
    }

    private static function typedProduct(array $product): ?array
    {
        if (!self::exactSubsetKeys($product, [
            'id', 'type', 'title', 'summary', 'currency', 'state',
            'availability', 'imageRef', 'sku', 'priceMinor', 'stock',
            'options', 'variants',
        ])) {
            return null;
        }

        foreach (
            ['id', 'type', 'title', 'currency', 'state', 'availability']
            as $key
        ) {
            if (!array_key_exists($key, $product)
                || !is_string($product[$key])
            ) {
                return null;
            }
        }
        foreach (['summary', 'imageRef', 'sku'] as $key) {
            if (array_key_exists($key, $product)
                && !is_string($product[$key])
            ) {
                return null;
            }
            if (($product[$key] ?? null) === '') {
                $product[$key] = null;
            }
        }

        $type = $product['type'];
        if ($type === 'simple') {
            if (!array_key_exists('sku', $product)
                || !is_string($product['sku'])
                || !array_key_exists('priceMinor', $product)
            ) {
                return null;
            }
            $price = self::browserInteger($product['priceMinor']);
            $stock = self::browserOptionalInteger($product, 'stock');
            if ($price === null || $stock === false) {
                return null;
            }
            $product['priceMinor'] = $price;
            $product['stock'] = $stock;
            if (array_key_exists('options', $product)
                && $product['options'] !== []
            ) {
                return null;
            }
            if (array_key_exists('variants', $product)
                && $product['variants'] !== []
            ) {
                return null;
            }
            $product['options'] = [];
            $product['variants'] = [];
            return $product;
        }

        if ($type !== 'variable'
            || !is_array($product['options'] ?? null)
            || !array_is_list($product['options'])
            || !is_array($product['variants'] ?? null)
            || !array_is_list($product['variants'])
        ) {
            return null;
        }
        foreach (['sku', 'priceMinor', 'stock'] as $key) {
            if (array_key_exists($key, $product)
                && $product[$key] !== ''
                && $product[$key] !== null
            ) {
                return null;
            }
            $product[$key] = null;
        }
        foreach ($product['options'] as $groupIndex => $group) {
            if (!is_array($group)
                || !self::exactKeys($group, ['key', 'label', 'values'])
                || !is_string($group['key'] ?? null)
                || !is_string($group['label'] ?? null)
                || !is_array($group['values'] ?? null)
                || !array_is_list($group['values'])
            ) {
                return null;
            }
            foreach ($group['values'] as $value) {
                if (!is_array($value)
                    || !self::exactKeys($value, ['id', 'label'])
                    || !is_string($value['id'] ?? null)
                    || !is_string($value['label'] ?? null)
                ) {
                    return null;
                }
            }
            $product['options'][$groupIndex] = $group;
        }
        foreach ($product['variants'] as $variantIndex => $variant) {
            $typedVariant = self::typedVariant($variant);
            if ($typedVariant === null) {
                return null;
            }
            $product['variants'][$variantIndex] = $typedVariant;
        }
        return $product;
    }

    private static function typedVariant(mixed $variant): ?array
    {
        if (!is_array($variant)
            || !self::exactSubsetKeys($variant, [
                'id', 'sku', 'options', 'priceMinor', 'availability',
                'stock', 'imageRef',
            ])
            || !is_string($variant['id'] ?? null)
            || !is_string($variant['sku'] ?? null)
            || !is_array($variant['options'] ?? null)
            || array_is_list($variant['options'])
            || !array_key_exists('priceMinor', $variant)
            || !is_string($variant['availability'] ?? null)
        ) {
            return null;
        }
        foreach ($variant['options'] as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                return null;
            }
        }
        if (array_key_exists('imageRef', $variant)
            && !is_string($variant['imageRef'])
        ) {
            return null;
        }
        if (($variant['imageRef'] ?? null) === '') {
            $variant['imageRef'] = null;
        }
        $price = self::browserInteger($variant['priceMinor']);
        $stock = self::browserOptionalInteger($variant, 'stock');
        if ($price === null || $stock === false) {
            return null;
        }
        $variant['priceMinor'] = $price;
        $variant['stock'] = $stock;
        return $variant;
    }

    private static function browserInteger(mixed $value): ?int
    {
        if (!is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value) !== 1
        ) {
            return null;
        }
        $integer = (int) $value;
        return (string) $integer === $value ? $integer : null;
    }

    private static function browserOptionalInteger(
        array $input,
        string $key
    ): int|null|false {
        if (!array_key_exists($key, $input) || $input[$key] === '') {
            return null;
        }
        return self::browserInteger($input[$key]) ?? false;
    }

    private static function bounded(array $fields): bool
    {
        try {
            $encoded = json_encode(
                $fields,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $throwable) {
            return false;
        }
        if (!is_string($encoded) || strlen($encoded) > self::MAX_ENCODED_BYTES) {
            return false;
        }
        $nodes = 0;
        return self::boundedValue($fields, 1, $nodes);
    }

    private static function boundedValue(
        mixed $value,
        int $depth,
        int &$nodes
    ): bool {
        $nodes++;
        if ($nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            return false;
        }
        if (!is_array($value)) {
            return is_string($value) || is_int($value) || $value === null;
        }
        foreach ($value as $child) {
            if (!self::boundedValue($child, $depth + 1, $nodes)) {
                return false;
            }
        }
        return true;
    }

    private static function errorCodes(array $errors): array
    {
        $safe = [];
        foreach ($errors as $error) {
            if (is_string($error)
                && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $error) === 1
            ) {
                $safe[$error] = true;
            }
        }
        $safe = array_keys($safe);
        sort($safe, SORT_STRING);
        return array_slice($safe, 0, 32);
    }

    private static function exactKeys(array $input, array $expected): bool
    {
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        return $keys === $expected;
    }

    private static function exactSubsetKeys(
        array $input,
        array $allowed
    ): bool {
        return array_diff(array_keys($input), $allowed) === [];
    }

    private static function validCurrency(string $value): bool
    {
        return preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }

    private static function validSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function result(string $reason): array
    {
        return [
            'accepted' => false,
            'mode' => '',
            'productId' => '',
            'product' => null,
            'expectedStateSha256' => '',
            'planSha256' => '',
            'errors' => [],
            'reason' => $reason,
        ];
    }
}
