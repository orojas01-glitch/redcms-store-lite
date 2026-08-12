<?php

declare(strict_types=1);

/**
 * Pure browser-scalar boundary for one Store Lite guest checkout.
 *
 * The caller supplies current installation configuration and a current
 * server-side payment-readiness decision. This class reads no request state
 * and performs no cart, order, provider, storage, or runtime work.
 */
final class RED_CMS_Store_Lite_Guest_Checkout_Command
{
    private const FULFILLMENT_METHODS = ['pickup', 'delivery'];

    private const PAYMENT_METHODS = [
        'pay_on_receipt',
        'stripe_checkout',
        'paypal',
        'zelle_manual',
        'nequi',
    ];

    private const INPUT_KEYS = [
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

    public static function bounds(): array
    {
        return [
            'maxFields' => 12,
            'maxBodyBytes' => 4096,
            'maxNameLength' => 120,
            'maxEmailLength' => 254,
            'maxPhoneLength' => 32,
            'maxAddressLength' => 160,
            'maxPostalCodeLength' => 32,
            'maxInstructionsLength' => 500,
        ];
    }

    /**
     * Returns the normalized server-side choices available to this form.
     */
    public static function availability(
        array $configuration,
        array $paymentReadiness
    ): ?array {
        if (array_keys($configuration) !== [
            'currency', 'fulfillmentMethods', 'paymentMethods',
            'deliveryFeeMinor',
        ]
            || !self::currency($configuration['currency'] ?? null)
            || !self::allowedList(
                $configuration['fulfillmentMethods'] ?? null,
                self::FULFILLMENT_METHODS
            )
            || !self::allowedList(
                $configuration['paymentMethods'] ?? null,
                self::PAYMENT_METHODS
            )
            || !is_int($configuration['deliveryFeeMinor'] ?? null)
            || $configuration['deliveryFeeMinor'] < 0
            || $configuration['deliveryFeeMinor'] > 999999999
            || (!in_array(
                'delivery',
                $configuration['fulfillmentMethods'],
                true
            ) && $configuration['deliveryFeeMinor'] !== 0)
            || array_keys($paymentReadiness) !== self::PAYMENT_METHODS
        ) {
            return null;
        }
        foreach ($paymentReadiness as $ready) {
            if (!is_bool($ready)) {
                return null;
            }
        }
        $fulfillmentMethods = array_values(array_intersect(
            self::FULFILLMENT_METHODS,
            $configuration['fulfillmentMethods']
        ));
        $paymentMethods = [];
        foreach (self::PAYMENT_METHODS as $method) {
            if (in_array($method, $configuration['paymentMethods'], true)
                && $paymentReadiness[$method]
            ) {
                $paymentMethods[] = $method;
            }
        }
        if ($fulfillmentMethods === [] || $paymentMethods === []) {
            return null;
        }
        return [
            'currency' => $configuration['currency'],
            'fulfillmentMethods' => $fulfillmentMethods,
            'paymentMethods' => $paymentMethods,
            'deliveryFeeMinor' => $configuration['deliveryFeeMinor'],
        ];
    }

    public static function decode(
        array $input,
        array $configuration,
        array $paymentReadiness
    ): array {
        $availability = self::availability(
            $configuration,
            $paymentReadiness
        );
        if ($availability === null
            || array_keys($input) !== self::INPUT_KEYS
            || count($input) !== self::bounds()['maxFields']
            || !self::scalarBytesWithinBound($input)
        ) {
            return self::refusal();
        }
        foreach ($input as $value) {
            if (!is_string($value)) {
                return self::refusal();
            }
        }

        $name = $input['customer-name'];
        $email = $input['customer-email'];
        $phone = self::optional($input['customer-phone']);
        $fulfillmentMethod = $input['fulfillment-method'];
        $paymentMethod = $input['payment-method'];
        if (!self::text($name, self::bounds()['maxNameLength'])
            || !self::email($email)
            || ($phone !== null && !self::phone($phone))
            || !in_array(
                $fulfillmentMethod,
                $availability['fulfillmentMethods'],
                true
            )
            || !in_array(
                $paymentMethod,
                $availability['paymentMethods'],
                true
            )
        ) {
            return self::refusal();
        }

        $addressValues = [
            $input['delivery-line1'],
            $input['delivery-line2'],
            $input['delivery-city'],
            $input['delivery-region'],
            $input['delivery-postal-code'],
            $input['delivery-country-code'],
            $input['delivery-instructions'],
        ];
        $deliveryAddress = null;
        if ($fulfillmentMethod === 'pickup') {
            if (array_filter(
                $addressValues,
                static fn (string $value): bool => $value !== ''
            ) !== []) {
                return self::refusal();
            }
        } else {
            $deliveryAddress = self::deliveryAddress($input);
            if ($phone === null || $deliveryAddress === null) {
                return self::refusal();
            }
        }

        return [
            'valid' => true,
            'reason' => 'valid',
            'checkout' => [
                'customer' => [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                ],
                'fulfillmentMethod' => $fulfillmentMethod,
                'deliveryAddress' => $deliveryAddress,
                'paymentMethod' => $paymentMethod,
            ],
        ];
    }

    private static function deliveryAddress(array $input): ?array
    {
        $line1 = $input['delivery-line1'];
        $line2 = self::optional($input['delivery-line2']);
        $city = $input['delivery-city'];
        $region = $input['delivery-region'];
        $postalCode = self::optional($input['delivery-postal-code']);
        $countryCode = $input['delivery-country-code'];
        $instructions = self::optional($input['delivery-instructions']);
        if (!self::text($line1, self::bounds()['maxAddressLength'])
            || ($line2 !== null
                && !self::text($line2, self::bounds()['maxAddressLength']))
            || !self::text($city, self::bounds()['maxAddressLength'])
            || !self::text($region, self::bounds()['maxAddressLength'])
            || ($postalCode !== null
                && !self::text(
                    $postalCode,
                    self::bounds()['maxPostalCodeLength']
                ))
            || !is_string($countryCode)
            || preg_match('/\A[A-Z]{2}\z/D', $countryCode) !== 1
            || ($instructions !== null
                && !self::text(
                    $instructions,
                    self::bounds()['maxInstructionsLength']
                ))
        ) {
            return null;
        }
        return [
            'line1' => $line1,
            'line2' => $line2,
            'city' => $city,
            'region' => $region,
            'postalCode' => $postalCode,
            'countryCode' => $countryCode,
            'instructions' => $instructions,
        ];
    }

    private static function allowedList(mixed $values, array $allowed): bool
    {
        if (!is_array($values)
            || !array_is_list($values)
            || $values === []
            || count($values) !== count(array_unique($values, SORT_REGULAR))
        ) {
            return false;
        }
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    private static function scalarBytesWithinBound(array $input): bool
    {
        try {
            $encoded = http_build_query($input, '', '&', PHP_QUERY_RFC3986);
        } catch (Throwable $throwable) {
            return false;
        }
        return strlen($encoded) <= self::bounds()['maxBodyBytes'];
    }

    private static function optional(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function currency(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }

    private static function email(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= self::bounds()['maxEmailLength']
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function phone(string $value): bool
    {
        return strlen($value) <= self::bounds()['maxPhoneLength']
            && preg_match('/\A[0-9+(). -]+\z/D', $value) === 1
            && preg_match_all('/[0-9]/', $value) >= 7;
    }

    private static function text(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && $value !== ''
            && trim($value) === $value
            && preg_match('//u', $value) === 1
            && self::textLength($value) <= $maximum
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    private static function refusal(): array
    {
        return [
            'valid' => false,
            'reason' => 'invalid_intent',
            'checkout' => null,
        ];
    }
}
