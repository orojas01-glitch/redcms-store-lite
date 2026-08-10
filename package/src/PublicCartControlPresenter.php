<?php

declare(strict_types=1);

require_once __DIR__ . '/CartLineCommand.php';

/**
 * Pure Store Lite presentation models for one current cart line's controls.
 *
 * Core still owns form composition, browser evidence, markup, dispatch,
 * response handling, and every commercial write.
 */
final class RED_CMS_Store_Lite_Public_Cart_Control_Presenter
{
    public const SET_QUANTITY_ROUTE =
        'redcms.store-lite/cart-line-quantity';
    public const SET_QUANTITY_MUTATION =
        'redcms.store-lite/set-cart-line-quantity';
    public const REMOVE_ROUTE = 'redcms.store-lite/cart-line-remove';
    public const REMOVE_MUTATION = 'redcms.store-lite/remove-cart-line';

    public static function present(array $line): ?array
    {
        if (array_keys($line) !== ['lineIdentitySha256', 'quantity']
            || !is_int($line['quantity'] ?? null)
        ) {
            return null;
        }
        $bounds = RED_CMS_Store_Lite_Cart_Line_Command::bounds();
        if ($line['quantity'] < $bounds['minQuantity']
            || $line['quantity'] > $bounds['maxQuantity']
        ) {
            return null;
        }
        $handle = RED_CMS_Store_Lite_Cart_Line_Command::publicHandle(
            $line['lineIdentitySha256'] ?? null
        );
        if ($handle === null) {
            return null;
        }

        return [
            'quantityForm' => [
                'route' => self::SET_QUANTITY_ROUTE,
                'mutation' => self::SET_QUANTITY_MUTATION,
                'submitLabel' => 'Update quantity',
                'fields' => [[
                    'key' => 'line',
                    'control' => 'hidden',
                    'value' => $handle,
                ], [
                    'key' => 'quantity',
                    'control' => 'number',
                    'label' => 'Quantity',
                    'value' => $line['quantity'],
                ]],
            ],
            'removeForm' => [
                'route' => self::REMOVE_ROUTE,
                'mutation' => self::REMOVE_MUTATION,
                'submitLabel' => 'Remove item',
                'fields' => [[
                    'key' => 'line',
                    'control' => 'hidden',
                    'value' => $handle,
                ]],
            ],
        ];
    }
}
