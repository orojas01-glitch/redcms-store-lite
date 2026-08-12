<?php

declare(strict_types=1);

require_once __DIR__ . '/GuestCheckoutCommand.php';

/**
 * Pure data-only Store Lite guest-checkout presentation contract.
 *
 * This model is intentionally not a current RED-CMS mutation-form model.
 * Core does not yet support its text, email, phone, textarea, or conditional
 * field controls. The class registers nothing and emits no markup.
 */
final class RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter
{
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

    private static function deliveryCondition(): array
    {
        return [
            'field' => 'fulfillment-method',
            'equals' => 'delivery',
        ];
    }
}
