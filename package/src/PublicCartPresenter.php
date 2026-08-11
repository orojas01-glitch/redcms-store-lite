<?php

declare(strict_types=1);

require_once __DIR__ . '/PublicCartControlPresenter.php';

/**
 * Pure Store Lite adapter for the core-owned bounded collection renderer.
 */
final class RED_CMS_Store_Lite_Public_Cart_Presenter
{
    public static function present(array $cart, string $currency): ?array
    {
        if (!self::validCurrency($currency)
            || array_keys($cart) !== ['currency', 'lines']
            || ($cart['currency'] ?? null) !== $currency
            || !is_array($cart['lines'] ?? null)
            || !array_is_list($cart['lines'])
            || count($cart['lines']) > 24
        ) {
            return null;
        }

        $items = [];
        $quantityTotal = 0;
        $cartTotalMinor = 0;
        foreach ($cart['lines'] as $line) {
            $item = self::line($line, $currency);
            if ($item === null) {
                return null;
            }
            $items[] = $item['view'];
            $quantityTotal += $item['quantity'];
            $cartTotalMinor += $item['lineTotalMinor'];
            if ($quantityTotal > 2400 || $cartTotalMinor > 2399999997600) {
                return null;
            }
        }

        $view = [
            'title' => 'Your cart',
            'summary' => $items === []
                ? 'Your cart is empty.'
                : $quantityTotal . ' item' . ($quantityTotal === 1 ? '' : 's')
                    . ' · ' . self::money($cartTotalMinor, $currency),
            'facts' => [[
                'label' => 'Items',
                'value' => (string) $quantityTotal,
            ], [
                'label' => 'Total',
                'value' => self::money($cartTotalMinor, $currency),
            ]],
        ];
        if ($items !== []) {
            $view['collection'] = [
                'label' => 'Cart items',
                'items' => $items,
            ];
        }
        return $view;
    }

    private static function line(mixed $line, string $currency): ?array
    {
        if (!is_array($line)
            || array_keys($line) !== [
                'title', 'options', 'lineIdentitySha256', 'quantity',
                'unitPriceMinor', 'currency', 'lineTotalMinor',
            ]
            || !self::text($line['title'] ?? null, 160)
            || !is_array($line['options'] ?? null)
            || !array_is_list($line['options'])
            || count($line['options']) > 3
            || !is_int($line['quantity'] ?? null)
            || $line['quantity'] < 1
            || $line['quantity'] > 100
            || !is_int($line['unitPriceMinor'] ?? null)
            || $line['unitPriceMinor'] < 0
            || $line['unitPriceMinor'] > 999999999
            || ($line['currency'] ?? null) !== $currency
            || !is_int($line['lineTotalMinor'] ?? null)
            || $line['lineTotalMinor'] !== $line['unitPriceMinor'] * $line['quantity']
        ) {
            return null;
        }
        foreach ($line['options'] as $option) {
            if (!self::text($option, 160)) {
                return null;
            }
        }
        $controls =
            RED_CMS_Store_Lite_Public_Cart_Control_Presenter::present([
                'lineIdentitySha256' => $line['lineIdentitySha256'],
                'quantity' => $line['quantity'],
            ]);
        if (!is_array($controls)
            || array_keys($controls) !== ['quantityForm', 'removeForm']
        ) {
            return null;
        }
        $facts = [];
        if ($line['options'] !== []) {
            $facts[] = [
                'label' => 'Options',
                'value' => implode(' · ', $line['options']),
            ];
        }
        $facts[] = ['label' => 'Quantity', 'value' => (string) $line['quantity']];
        $facts[] = [
            'label' => 'Unit price',
            'value' => self::money($line['unitPriceMinor'], $currency),
        ];
        $facts[] = [
            'label' => 'Line total',
            'value' => self::money($line['lineTotalMinor'], $currency),
        ];
        return [
            'quantity' => $line['quantity'],
            'lineTotalMinor' => $line['lineTotalMinor'],
            'view' => [
                'title' => $line['title'],
                'facts' => $facts,
                'mutationForms' => [
                    $controls['quantityForm'],
                    $controls['removeForm'],
                ],
            ],
        ];
    }

    private static function money(int $minor, string $currency): string
    {
        return $currency . ' ' . intdiv($minor, 100) . '.'
            . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function text(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && $value !== ''
            && trim($value) === $value
            && strlen($value) <= $maximum
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private static function validCurrency(string $value): bool
    {
        return preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }
}
