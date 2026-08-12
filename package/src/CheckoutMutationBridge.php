<?php

declare(strict_types=1);

require_once __DIR__ . '/CartPersistence.php';
require_once __DIR__ . '/GuestCheckoutCommand.php';
require_once __DIR__ . '/OrderPersistence.php';

/**
 * Store Lite guest-checkout binding for the core-owned mutation runner.
 */
final class RED_CMS_Store_Lite_Checkout_Mutation_Bridge
{
    public const ROUTE = 'redcms.store-lite/guest-checkout';
    public const MUTATION = 'redcms.store-lite/create-guest-order';
    public const TABLES = RED_CMS_Store_Lite_Order_Persistence::TABLES;

    private const SETTING_KEYS = [
        'catalog.currency',
        'checkout.delivery-enabled',
        'checkout.delivery-fee-minor',
        'checkout.pay-on-receipt-enabled',
        'checkout.pickup-enabled',
    ];

    private const FIELD_KEYS = [
        'customer-name',
        'customer-email',
        'customer-phone',
        'fulfillment-method',
        'delivery-line1',
        'delivery-line2',
        'delivery-city',
        'delivery-region',
        'delivery-postal-code',
        'delivery-country-code',
        'delivery-instructions',
        'payment-method',
    ];

    public static function route(): never
    {
        throw new RuntimeException(
            'Store Lite guest checkout requires the core-owned dispatcher.'
        );
    }

    public static function load(
        mysqli $connection,
        RED_Addon_Public_Mutation_Command $command
    ): RED_Addon_Public_Mutation_State {
        self::operation($command);
        $configuration = self::configuration($command);
        $state = RED_CMS_Store_Lite_Cart_Persistence::read(
            $connection,
            $command->subjectRecordId(),
            $configuration['currency']
        );
        if (!in_array($state['status'] ?? '', ['empty', 'found'], true)
            || !self::validSha256($state['stateSha256'] ?? null)
            || !is_int($state['lineCount'] ?? null)
            || $state['lineCount'] < 0
        ) {
            throw new RuntimeException('Store Lite checkout state is unavailable.');
        }
        return new RED_Addon_Public_Mutation_State(
            $command->subjectRecordId(),
            [
                'cartStateSha256' => $state['stateSha256'],
                'lineCount' => $state['lineCount'],
            ]
        );
    }

    public static function execute(
        mysqli $connection,
        RED_Addon_Public_Mutation_Execution_Request $request
    ): RED_Addon_Public_Mutation_Execution_Result {
        self::operation($request);
        $configuration = self::configuration($request);
        $input = [];
        foreach (self::FIELD_KEYS as $key) {
            $value = $request->field($key);
            if (!is_string($value)) {
                throw new RuntimeException('Store Lite checkout input was refused.');
            }
            $input[$key] = $value;
        }
        $decoded = RED_CMS_Store_Lite_Guest_Checkout_Command::decode(
            $input,
            $configuration,
            self::paymentReadiness($configuration)
        );
        if (empty($decoded['valid'])
            || !is_array($decoded['checkout'] ?? null)
        ) {
            throw new RuntimeException('Store Lite checkout input was refused.');
        }

        $proposed = RED_CMS_Store_Lite_Order_Persistence::
            proposeWithinTransaction(
                $connection,
                $request->subjectRecordId(),
                $configuration,
                $decoded['checkout']
            );
        if (($proposed['status'] ?? '') !== 'proposed'
            || !is_array($proposed['proposal'] ?? null)
        ) {
            throw new RuntimeException('Store Lite order proposal was refused.');
        }
        $created = RED_CMS_Store_Lite_Order_Persistence::
            createWithinTransaction(
                $connection,
                $request->subjectRecordId(),
                $configuration,
                $proposed['proposal'],
                $request->previousStateSha256()
            );
        if (!in_array($created['status'] ?? '', ['created', 'replayed'], true)) {
            throw new RuntimeException('Store Lite order creation was refused.');
        }

        $command = new RED_Addon_Public_Mutation_Command(
            $request->packageId(),
            $request->routeId(),
            $request->mutationId(),
            $request->subjectRecordId(),
            $request->fields(),
            $request->runtimeSettings()
        );
        $state = self::load($connection, $command);
        return $created['status'] === 'replayed'
            ? RED_Addon_Public_Mutation_Execution_Result::unchanged($state)
            : RED_Addon_Public_Mutation_Execution_Result::accepted($state);
    }

    private static function operation(object $command): void
    {
        if ($command->routeId() !== self::ROUTE
            || $command->mutationId() !== self::MUTATION
        ) {
            throw new RuntimeException(
                'Store Lite checkout mutation binding is unavailable.'
            );
        }
    }

    private static function configuration(object $command): array
    {
        $settings = $command->runtimeSettings();
        if (!$settings->declared()
            || array_keys($settings->values()) !== self::SETTING_KEYS
        ) {
            throw new RuntimeException(
                'Store Lite checkout configuration is unavailable.'
            );
        }
        $currency = $settings->value('catalog.currency');
        $pickup = $settings->value('checkout.pickup-enabled');
        $delivery = $settings->value('checkout.delivery-enabled');
        $deliveryFeeMinor = $settings->value('checkout.delivery-fee-minor');
        $payOnReceipt = $settings->value(
            'checkout.pay-on-receipt-enabled'
        );
        if (!is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || !is_bool($pickup)
            || !is_bool($delivery)
            || (!$pickup && !$delivery)
            || !is_int($deliveryFeeMinor)
            || $deliveryFeeMinor < 0
            || $deliveryFeeMinor > 999999999
            || (!$delivery && $deliveryFeeMinor !== 0)
            || !is_bool($payOnReceipt)
            || !$payOnReceipt
        ) {
            throw new RuntimeException(
                'Store Lite checkout configuration is unavailable.'
            );
        }
        $methods = [];
        if ($pickup) {
            $methods[] = 'pickup';
        }
        if ($delivery) {
            $methods[] = 'delivery';
        }
        return [
            'currency' => $currency,
            'fulfillmentMethods' => $methods,
            'paymentMethods' => ['pay_on_receipt'],
            'deliveryFeeMinor' => $deliveryFeeMinor,
        ];
    }

    private static function paymentReadiness(array $configuration): array
    {
        return [
            'pay_on_receipt' => in_array(
                'pay_on_receipt',
                $configuration['paymentMethods'],
                true
            ),
            'stripe_checkout' => false,
            'paypal' => false,
            'zelle_manual' => false,
            'nequi' => false,
        ];
    }

    private static function validSha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
