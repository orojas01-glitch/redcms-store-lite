<?php

declare(strict_types=1);

require_once __DIR__ . '/GuestCheckoutCommand.php';

/**
 * Pure data-only Store Lite guest-checkout presentation contract.
 */
final class RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter
{
    public const ROUTE = 'redcms.store-lite/guest-checkout';
    public const MUTATION = 'redcms.store-lite/create-guest-order';

    private const PAYMENT_LABELS = [
        'pay_on_receipt' => 'Pay on receipt',
        'stripe_checkout' => 'Card or wallet (Stripe Checkout)',
        'paypal' => 'PayPal',
        'zelle_manual' => 'Zelle',
        'nequi' => 'Nequi',
    ];

    public static function present(
        array $configuration,
        array $paymentReadiness
    ): ?array {
        $availability =
            RED_CMS_Store_Lite_Guest_Checkout_Command::availability(
                $configuration,
                $paymentReadiness
            );
        if ($availability === null) {
            return null;
        }

        $fulfillmentOptions = [];
        foreach ($availability['fulfillmentMethods'] as $method) {
            $fulfillmentOptions[] = [
                'value' => $method,
                'label' => $method === 'pickup' ? 'Pickup' : 'Delivery',
                'feeMinor' => $method === 'pickup'
                    ? 0
                    : $availability['deliveryFeeMinor'],
                'currency' => $availability['currency'],
            ];
        }
        $paymentOptions = [];
        foreach ($availability['paymentMethods'] as $method) {
            $paymentOptions[] = [
                'value' => $method,
                'label' => self::PAYMENT_LABELS[$method],
            ];
        }

        return [
            'form' => 'redcms.store-lite/guest-checkout',
            'title' => 'Checkout',
            'submitLabel' => 'Place order',
            'encoding' => 'application/x-www-form-urlencoded',
            'maxBodyBytes' =>
                RED_CMS_Store_Lite_Guest_Checkout_Command::bounds()
                    ['maxBodyBytes'],
            'fields' => [[
                'key' => 'customer-name',
                'control' => 'text',
                'label' => 'Name',
                'required' => true,
                'maxLength' => 120,
            ], [
                'key' => 'customer-email',
                'control' => 'email',
                'label' => 'Email',
                'required' => true,
                'maxLength' => 254,
            ], [
                'key' => 'customer-phone',
                'control' => 'tel',
                'label' => 'Phone',
                'required' => false,
                'requiredWhen' => [
                    'field' => 'fulfillment-method',
                    'equals' => 'delivery',
                ],
                'maxLength' => 32,
            ], [
                'key' => 'fulfillment-method',
                'control' => 'select',
                'label' => 'Fulfillment',
                'required' => true,
                'value' => $fulfillmentOptions[0]['value'],
                'options' => $fulfillmentOptions,
            ], [
                'key' => 'delivery-line1',
                'control' => 'text',
                'label' => 'Address',
                'required' => false,
                'requiredWhen' => self::deliveryCondition(),
                'maxLength' => 160,
            ], [
                'key' => 'delivery-line2',
                'control' => 'text',
                'label' => 'Address line 2',
                'required' => false,
                'visibleWhen' => self::deliveryCondition(),
                'maxLength' => 160,
            ], [
                'key' => 'delivery-city',
                'control' => 'text',
                'label' => 'City',
                'required' => false,
                'requiredWhen' => self::deliveryCondition(),
                'maxLength' => 160,
            ], [
                'key' => 'delivery-region',
                'control' => 'text',
                'label' => 'State or region',
                'required' => false,
                'requiredWhen' => self::deliveryCondition(),
                'maxLength' => 160,
            ], [
                'key' => 'delivery-postal-code',
                'control' => 'text',
                'label' => 'Postal code',
                'required' => false,
                'visibleWhen' => self::deliveryCondition(),
                'maxLength' => 32,
            ], [
                'key' => 'delivery-country-code',
                'control' => 'text',
                'label' => 'Country code',
                'required' => false,
                'requiredWhen' => self::deliveryCondition(),
                'minLength' => 2,
                'maxLength' => 2,
                'format' => 'iso-3166-1-alpha-2-uppercase',
            ], [
                'key' => 'delivery-instructions',
                'control' => 'textarea',
                'label' => 'Delivery instructions',
                'required' => false,
                'visibleWhen' => self::deliveryCondition(),
                'maxLength' => 500,
            ], [
                'key' => 'payment-method',
                'control' => 'select',
                'label' => 'Payment',
                'required' => true,
                'value' => $paymentOptions[0]['value'],
                'options' => $paymentOptions,
            ]],
        ];
    }

    /**
     * Current core-rendered Gate 0 form: pickup, delivery, and pay on receipt.
     * The mutation's configured runtime settings remain authoritative.
     */
    public static function mutationForm(): array
    {
        $delivery = self::deliveryCondition();
        return [
            'route' => self::ROUTE,
            'mutation' => self::MUTATION,
            'submitLabel' => 'Place order',
            'fields' => [[
                'key' => 'customer-name',
                'control' => 'text',
                'label' => 'Name',
                'required' => true,
                'maxLength' => 120,
            ], [
                'key' => 'customer-email',
                'control' => 'email',
                'label' => 'Email',
                'required' => true,
                'maxLength' => 254,
            ], [
                'key' => 'customer-phone',
                'control' => 'tel',
                'label' => 'Phone',
                'required' => false,
                'requiredWhen' => $delivery,
                'maxLength' => 32,
            ], [
                'key' => 'fulfillment-method',
                'control' => 'select',
                'label' => 'Fulfillment',
                'value' => 'pickup',
                'options' => [[
                    'value' => 'pickup',
                    'label' => 'Pickup',
                ], [
                    'value' => 'delivery',
                    'label' => 'Delivery',
                ]],
            ], [
                'key' => 'delivery-line1',
                'control' => 'text',
                'label' => 'Address',
                'required' => false,
                'requiredWhen' => $delivery,
                'visibleWhen' => $delivery,
                'maxLength' => 160,
            ], [
                'key' => 'delivery-line2',
                'control' => 'text',
                'label' => 'Address line 2',
                'required' => false,
                'visibleWhen' => $delivery,
                'maxLength' => 160,
            ], [
                'key' => 'delivery-city',
                'control' => 'text',
                'label' => 'City',
                'required' => false,
                'requiredWhen' => $delivery,
                'visibleWhen' => $delivery,
                'maxLength' => 160,
            ], [
                'key' => 'delivery-region',
                'control' => 'text',
                'label' => 'State or region',
                'required' => false,
                'requiredWhen' => $delivery,
                'visibleWhen' => $delivery,
                'maxLength' => 160,
            ], [
                'key' => 'delivery-postal-code',
                'control' => 'text',
                'label' => 'Postal code',
                'required' => false,
                'visibleWhen' => $delivery,
                'maxLength' => 32,
            ], [
                'key' => 'delivery-country-code',
                'control' => 'text',
                'label' => 'Country code',
                'required' => false,
                'requiredWhen' => $delivery,
                'visibleWhen' => $delivery,
                'minLength' => 2,
                'maxLength' => 2,
                'format' => 'iso-3166-1-alpha-2-uppercase',
            ], [
                'key' => 'delivery-instructions',
                'control' => 'textarea',
                'label' => 'Delivery instructions',
                'required' => false,
                'visibleWhen' => $delivery,
                'maxLength' => 500,
            ], [
                'key' => 'payment-method',
                'control' => 'select',
                'label' => 'Payment',
                'value' => 'pay_on_receipt',
                'options' => [[
                    'value' => 'pay_on_receipt',
                    'label' => 'Pay on receipt',
                ]],
            ]],
        ];
    }

    private static function deliveryCondition(): array
    {
        return [
            'field' => 'fulfillment-method',
            'equals' => 'delivery',
        ];
    }
}
