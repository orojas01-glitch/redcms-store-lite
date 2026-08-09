<?php

declare(strict_types=1);

require_once __DIR__ . '/ProductNormalizer.php';

/**
 * Pure Store Lite adapter for the core-owned public fact-card renderer.
 *
 * Catalog state is normalized again before presentation. This class opens no
 * database, reads no request/runtime state, emits no markup, and creates no
 * cart or order behavior.
 */
final class RED_CMS_Store_Lite_Public_Product_Presenter
{
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
            || ($normalized['product']['state'] ?? '') !== 'published'
        ) {
            return null;
        }

        $product = $normalized['product'];
        $facts = [[
            'label' => 'Price',
            'value' => self::price($product),
        ], [
            'label' => 'Availability',
            'value' => self::available($product)
                ? 'Available'
                : 'Unavailable',
        ]];
        if ($product['type'] === 'variable') {
            foreach ($product['options'] as $option) {
                $labels = [];
                foreach ($option['values'] as $value) {
                    $labels[] = $value['label'];
                }
                $facts[] = [
                    'label' => $option['label'],
                    'value' => implode(', ', $labels),
                ];
            }
        }

        return [
            'title' => $product['title'],
            'summary' => $product['summary'] ?? '',
            'facts' => $facts,
        ];
    }

    private static function price(array $product): string
    {
        if ($product['type'] === 'simple') {
            return self::money(
                $product['priceMinor'],
                $product['currency']
            );
        }
        $prices = array_map(
            static fn (array $variant): int => $variant['priceMinor'],
            $product['variants']
        );
        $minimum = min($prices);
        $maximum = max($prices);
        $minimumLabel = self::money($minimum, $product['currency']);
        return $minimum === $maximum
            ? $minimumLabel
            : $minimumLabel . '–' . self::money(
                $maximum,
                $product['currency']
            );
    }

    private static function money(int $minor, string $currency): string
    {
        return $currency . ' ' . intdiv($minor, 100) . '.'
            . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function available(array $product): bool
    {
        if ($product['availability'] !== 'available') {
            return false;
        }
        if ($product['type'] === 'simple') {
            return $product['stock'] === null || $product['stock'] > 0;
        }
        foreach ($product['variants'] as $variant) {
            if ($variant['availability'] === 'available'
                && ($variant['stock'] === null || $variant['stock'] > 0)
            ) {
                return true;
            }
        }
        return false;
    }
}
