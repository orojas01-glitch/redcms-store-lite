<?php

declare(strict_types=1);

require_once __DIR__ . '/ProductNormalizer.php';

/**
 * Pure server-authoritative resolution for one proposed Store Lite cart line.
 *
 * The caller must load the current product from package storage. This class
 * accepts no browser-owned price, currency, SKU, stock, option-label, or total
 * value and performs no database, request, runtime, filesystem, or network
 * work.
 */
final class RED_CMS_Store_Lite_Cart_Line_Resolver
{
    private const MAX_QUANTITY = 100;

    public static function bounds(): array
    {
        return [
            'minQuantity' => 1,
            'maxQuantity' => self::MAX_QUANTITY,
            'maxLineTotalMinor' => 99999999900,
        ];
    }

    public static function resolve(
        array $currentProduct,
        string $installationCurrency,
        array $intent
    ): array {
        $intentKeys = array_keys($intent);
        sort($intentKeys, SORT_STRING);
        if ($intentKeys !== ['product', 'quantity']
            && $intentKeys !== ['product', 'quantity', 'variant']
        ) {
            return self::refusal('invalid_intent');
        }
        if (!self::identifier($intent['product'] ?? null)
            || !is_int($intent['quantity'] ?? null)
            || $intent['quantity'] < 1
            || $intent['quantity'] > self::MAX_QUANTITY
            || (array_key_exists('variant', $intent)
                && !self::identifier($intent['variant']))
        ) {
            return self::refusal('invalid_intent');
        }

        $normalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
            $currentProduct,
            $installationCurrency
        );
        if (empty($normalized['valid'])
            || !is_array($normalized['product'] ?? null)
        ) {
            return self::refusal('product_unavailable');
        }
        $product = $normalized['product'];
        if (!hash_equals($product['id'], $intent['product'])
            || $product['state'] !== 'published'
            || $product['availability'] !== 'available'
        ) {
            return self::refusal('product_unavailable');
        }

        $variantId = null;
        $sku = null;
        $unitPriceMinor = null;
        $stock = null;
        $optionLabels = [];
        if ($product['type'] === 'simple') {
            if (array_key_exists('variant', $intent)) {
                return self::refusal('variant_unavailable');
            }
            $sku = $product['sku'];
            $unitPriceMinor = $product['priceMinor'];
            $stock = $product['stock'];
        } elseif ($product['type'] === 'variable') {
            if (!array_key_exists('variant', $intent)) {
                return self::refusal('variant_required');
            }
            $variant = self::variant($product['variants'], $intent['variant']);
            if (!is_array($variant)
                || $variant['availability'] !== 'available'
            ) {
                return self::refusal('variant_unavailable');
            }
            $variantId = $variant['id'];
            $sku = $variant['sku'];
            $unitPriceMinor = $variant['priceMinor'];
            $stock = $variant['stock'];
            $optionLabels = self::optionLabels(
                $product['options'],
                $variant['options']
            );
            if ($optionLabels === null) {
                return self::refusal('variant_unavailable');
            }
        } else {
            return self::refusal('product_unavailable');
        }

        $quantity = $intent['quantity'];
        if (!is_int($unitPriceMinor)
            || $unitPriceMinor < 0
            || $unitPriceMinor > 999999999
            || !is_string($sku)
            || $sku === ''
        ) {
            return self::refusal('product_unavailable');
        }
        if (is_int($stock) && $quantity > $stock) {
            return self::refusal('insufficient_stock');
        }
        if ($unitPriceMinor > intdiv(PHP_INT_MAX, $quantity)) {
            return self::refusal('product_unavailable');
        }
        $lineTotalMinor = $unitPriceMinor * $quantity;
        if ($lineTotalMinor > self::bounds()['maxLineTotalMinor']) {
            return self::refusal('product_unavailable');
        }

        $stateJson = json_encode(
            $product,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($stateJson)) {
            return self::refusal('product_unavailable');
        }

        return [
            'resolved' => true,
            'reason' => 'resolved',
            'line' => [
                'productId' => $product['id'],
                'variantId' => $variantId,
                'sku' => $sku,
                'title' => $product['title'],
                'optionLabels' => $optionLabels,
                'quantity' => $quantity,
                'unitPriceMinor' => $unitPriceMinor,
                'currency' => $product['currency'],
                'lineTotalMinor' => $lineTotalMinor,
                'stockTracked' => is_int($stock),
                'stockAvailable' => is_int($stock) ? $stock : null,
                'productStateSha256' => hash('sha256', $stateJson),
            ],
        ];
    }

    private static function variant(array $variants, string $variantId): ?array
    {
        foreach ($variants as $variant) {
            if (is_array($variant)
                && is_string($variant['id'] ?? null)
                && hash_equals($variant['id'], $variantId)
            ) {
                return $variant;
            }
        }
        return null;
    }

    private static function optionLabels(
        array $groups,
        array $selection
    ): ?array {
        $labels = [];
        foreach ($groups as $group) {
            $groupKey = $group['key'] ?? null;
            $valueId = is_string($groupKey)
                ? ($selection[$groupKey] ?? null)
                : null;
            if (!is_string($groupKey) || !is_string($valueId)) {
                return null;
            }
            $valueLabel = null;
            foreach ($group['values'] ?? [] as $value) {
                if (is_array($value)
                    && is_string($value['id'] ?? null)
                    && hash_equals($value['id'], $valueId)
                ) {
                    $valueLabel = $value['label'] ?? null;
                    break;
                }
            }
            if (!is_string($valueLabel)) {
                return null;
            }
            $labels[] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'valueId' => $valueId,
                'valueLabel' => $valueLabel,
            ];
        }
        return $labels;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) === 1;
    }

    private static function refusal(string $reason): array
    {
        return [
            'resolved' => false,
            'reason' => $reason,
            'line' => null,
        ];
    }
}
