<?php

declare(strict_types=1);

/**
 * Pure Store Lite guest-order snapshot contract.
 *
 * The caller supplies a complete server-derived cart, a closed checkout
 * intent, and current installation configuration. This class performs no
 * database, request, session, filesystem, network, provider, or runtime work.
 */
final class RED_CMS_Store_Lite_Guest_Order_Snapshot
{
    private const PAYMENT_METHODS = [
        'pay_on_receipt' => 'deferred',
        'stripe_checkout' => 'hosted',
        'paypal' => 'hosted',
        'zelle_manual' => 'manual_transfer',
        'nequi' => 'hosted',
    ];

    private const FULFILLMENT_METHODS = ['pickup', 'delivery'];

    public static function bounds(): array
    {
        return [
            'maxLines' => 24,
            'maxQuantityPerLine' => 100,
            'maxQuantityTotal' => 2400,
            'maxUnitPriceMinor' => 999999999,
            'maxDeliveryFeeMinor' => 999999999,
            'maxOrderTotalMinor' => 2400999997599,
            'maxNameLength' => 120,
            'maxEmailLength' => 254,
            'maxPhoneLength' => 32,
            'maxAddressLength' => 160,
            'maxInstructionsLength' => 500,
        ];
    }

    public static function build(
        array $cart,
        array $checkout,
        array $configuration
    ): array {
        $errors = [];
        $normalizedConfiguration = self::configuration(
            $configuration,
            $errors
        );
        $normalizedCart = self::cart(
            $cart,
            $normalizedConfiguration['currency'] ?? null,
            $errors
        );
        $normalizedCheckout = self::checkout(
            $checkout,
            $normalizedConfiguration,
            $errors
        );
        if ($errors !== []
            || $normalizedConfiguration === null
            || $normalizedCart === null
            || $normalizedCheckout === null
        ) {
            return self::invalid($errors);
        }

        $fulfillmentMethod = $normalizedCheckout['fulfillmentMethod'];
        $paymentMethod = $normalizedCheckout['paymentMethod'];
        $fulfillmentFeeMinor = $fulfillmentMethod === 'delivery'
            ? $normalizedConfiguration['deliveryFeeMinor']
            : 0;
        $totalMinor = $normalizedCart['subtotalMinor'] + $fulfillmentFeeMinor;
        if ($totalMinor > self::bounds()['maxOrderTotalMinor']) {
            return self::invalid(['order_total_invalid']);
        }

        $snapshot = [
            'version' => 1,
            'currency' => $normalizedConfiguration['currency'],
            'customer' => $normalizedCheckout['customer'],
            'fulfillment' => [
                'method' => $fulfillmentMethod,
                'feeMinor' => $fulfillmentFeeMinor,
                'deliveryAddress' => $normalizedCheckout['deliveryAddress'],
            ],
            'payment' => [
                'method' => $paymentMethod,
                'kind' => self::PAYMENT_METHODS[$paymentMethod],
            ],
            'lines' => $normalizedCart['lines'],
            'quantityTotal' => $normalizedCart['quantityTotal'],
            'subtotalMinor' => $normalizedCart['subtotalMinor'],
            'totalMinor' => $totalMinor,
        ];
        try {
            $encoded = json_encode(
                $snapshot,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $throwable) {
            return self::invalid(['snapshot_encoding_failed']);
        }

        return [
            'valid' => true,
            'snapshot' => $snapshot,
            'snapshotSha256' => hash('sha256', $encoded),
            'sourceCartStateSha256' => $normalizedCart['stateSha256'],
            'initialState' => [
                'orderStatus' => 'pending',
                'paymentStatus' => $paymentMethod === 'pay_on_receipt'
                    ? 'due_on_receipt'
                    : 'pending',
                'fulfillmentStatus' => 'unfulfilled',
            ],
            'errors' => [],
        ];
    }

    private static function configuration(
        array $configuration,
        array &$errors
    ): ?array {
        if (array_keys($configuration) !== [
            'currency', 'fulfillmentMethods', 'paymentMethods',
            'deliveryFeeMinor',
        ]) {
            $errors[] = 'configuration_shape_invalid';
            return null;
        }
        $currency = $configuration['currency'];
        if (!is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
        ) {
            $errors[] = 'configuration_currency_invalid';
        }
        $fulfillmentMethods = self::allowedList(
            $configuration['fulfillmentMethods'],
            self::FULFILLMENT_METHODS,
            'fulfillment_methods_invalid',
            $errors
        );
        $paymentMethods = self::allowedList(
            $configuration['paymentMethods'],
            array_keys(self::PAYMENT_METHODS),
            'payment_methods_invalid',
            $errors
        );
        $deliveryFeeMinor = $configuration['deliveryFeeMinor'];
        if (!is_int($deliveryFeeMinor)
            || $deliveryFeeMinor < 0
            || $deliveryFeeMinor > self::bounds()['maxDeliveryFeeMinor']
            || (!in_array('delivery', $fulfillmentMethods, true)
                && $deliveryFeeMinor !== 0)
        ) {
            $errors[] = 'delivery_fee_invalid';
        }
        if ($errors !== []) {
            return null;
        }
        return [
            'currency' => $currency,
            'fulfillmentMethods' => $fulfillmentMethods,
            'paymentMethods' => $paymentMethods,
            'deliveryFeeMinor' => $deliveryFeeMinor,
        ];
    }

    private static function cart(
        array $cart,
        mixed $currency,
        array &$errors
    ): ?array {
        if (array_keys($cart) !== ['stateSha256', 'currency', 'lines']
            || !self::sha256($cart['stateSha256'] ?? null)
            || !is_string($currency)
            || ($cart['currency'] ?? null) !== $currency
            || !is_array($cart['lines'] ?? null)
            || !array_is_list($cart['lines'])
            || count($cart['lines']) < 1
            || count($cart['lines']) > self::bounds()['maxLines']
        ) {
            $errors[] = 'cart_invalid';
            return null;
        }

        $lines = [];
        $lineIdentities = [];
        $quantityTotal = 0;
        $subtotalMinor = 0;
        foreach ($cart['lines'] as $line) {
            $normalizedLine = self::line($line, $currency);
            if ($normalizedLine === null) {
                $errors[] = 'cart_line_invalid';
                return null;
            }
            $lineIdentity = $normalizedLine['productId'] . "\0"
                . ($normalizedLine['variantId'] ?? '');
            if (isset($lineIdentities[$lineIdentity])) {
                $errors[] = 'cart_line_duplicate';
                return null;
            }
            $lineIdentities[$lineIdentity] = true;
            $lines[] = $normalizedLine;
            $quantityTotal += $normalizedLine['quantity'];
            $subtotalMinor += $normalizedLine['lineTotalMinor'];
            if ($quantityTotal > self::bounds()['maxQuantityTotal']
                || $subtotalMinor > self::bounds()['maxOrderTotalMinor']
            ) {
                $errors[] = 'cart_total_invalid';
                return null;
            }
        }
        return [
            'stateSha256' => $cart['stateSha256'],
            'lines' => $lines,
            'quantityTotal' => $quantityTotal,
            'subtotalMinor' => $subtotalMinor,
        ];
    }

    private static function line(mixed $line, string $currency): ?array
    {
        if (!is_array($line)
            || array_keys($line) !== [
                'productId', 'variantId', 'sku', 'title', 'optionLabels',
                'quantity', 'unitPriceMinor', 'currency', 'lineTotalMinor',
            ]
            || !self::identifier($line['productId'] ?? null)
            || (($line['variantId'] ?? null) !== null
                && !self::identifier($line['variantId']))
            || !self::sku($line['sku'] ?? null)
            || !self::text($line['title'] ?? null, 160)
            || !is_array($line['optionLabels'] ?? null)
            || !array_is_list($line['optionLabels'])
            || count($line['optionLabels']) > 3
            || (($line['variantId'] ?? null) === null
                && $line['optionLabels'] !== [])
            || (($line['variantId'] ?? null) !== null
                && count($line['optionLabels']) < 1)
            || !is_int($line['quantity'] ?? null)
            || $line['quantity'] < 1
            || $line['quantity'] > self::bounds()['maxQuantityPerLine']
            || !is_int($line['unitPriceMinor'] ?? null)
            || $line['unitPriceMinor'] < 0
            || $line['unitPriceMinor'] > self::bounds()['maxUnitPriceMinor']
            || ($line['currency'] ?? null) !== $currency
            || !is_int($line['lineTotalMinor'] ?? null)
            || $line['lineTotalMinor']
                !== $line['unitPriceMinor'] * $line['quantity']
        ) {
            return null;
        }
        foreach ($line['optionLabels'] as $label) {
            if (!self::text($label, 160)) {
                return null;
            }
        }
        return [
            'productId' => $line['productId'],
            'variantId' => $line['variantId'],
            'sku' => $line['sku'],
            'title' => $line['title'],
            'optionLabels' => $line['optionLabels'],
            'quantity' => $line['quantity'],
            'unitPriceMinor' => $line['unitPriceMinor'],
            'currency' => $currency,
            'lineTotalMinor' => $line['lineTotalMinor'],
        ];
    }

    private static function checkout(
        array $checkout,
        ?array $configuration,
        array &$errors
    ): ?array {
        if ($configuration === null
            || array_keys($checkout) !== [
                'customer', 'fulfillmentMethod', 'deliveryAddress',
                'paymentMethod',
            ]
        ) {
            $errors[] = 'checkout_shape_invalid';
            return null;
        }
        $customer = self::customer($checkout['customer'] ?? null);
        $fulfillmentMethod = $checkout['fulfillmentMethod'] ?? null;
        $paymentMethod = $checkout['paymentMethod'] ?? null;
        if ($customer === null) {
            $errors[] = 'customer_invalid';
        }
        if (!is_string($fulfillmentMethod)
            || !in_array(
                $fulfillmentMethod,
                $configuration['fulfillmentMethods'],
                true
            )
        ) {
            $errors[] = 'fulfillment_method_unavailable';
        }
        if (!is_string($paymentMethod)
            || !in_array(
                $paymentMethod,
                $configuration['paymentMethods'],
                true
            )
        ) {
            $errors[] = 'payment_method_unavailable';
        }

        $deliveryAddress = null;
        if ($fulfillmentMethod === 'delivery') {
            $deliveryAddress = self::deliveryAddress(
                $checkout['deliveryAddress'] ?? null
            );
            if ($deliveryAddress === null) {
                $errors[] = 'delivery_address_invalid';
            }
            if (($customer['phone'] ?? null) === null) {
                $errors[] = 'delivery_phone_required';
            }
        } elseif (($checkout['deliveryAddress'] ?? null) !== null) {
            $errors[] = 'pickup_address_forbidden';
        }
        if ($errors !== []) {
            return null;
        }
        return [
            'customer' => $customer,
            'fulfillmentMethod' => $fulfillmentMethod,
            'deliveryAddress' => $deliveryAddress,
            'paymentMethod' => $paymentMethod,
        ];
    }

    private static function customer(mixed $customer): ?array
    {
        if (!is_array($customer)
            || array_keys($customer) !== ['name', 'email', 'phone']
            || !self::text(
                $customer['name'] ?? null,
                self::bounds()['maxNameLength']
            )
            || !is_string($customer['email'] ?? null)
            || strlen($customer['email']) > self::bounds()['maxEmailLength']
            || filter_var($customer['email'], FILTER_VALIDATE_EMAIL) === false
            || (($customer['phone'] ?? null) !== null
                && !self::phone($customer['phone']))
        ) {
            return null;
        }
        return [
            'name' => $customer['name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
        ];
    }

    private static function deliveryAddress(mixed $address): ?array
    {
        if (!is_array($address)
            || array_keys($address) !== [
                'line1', 'line2', 'city', 'region', 'postalCode',
                'countryCode', 'instructions',
            ]
            || !self::text(
                $address['line1'] ?? null,
                self::bounds()['maxAddressLength']
            )
            || (($address['line2'] ?? null) !== null
                && !self::text(
                    $address['line2'],
                    self::bounds()['maxAddressLength']
                ))
            || !self::text(
                $address['city'] ?? null,
                self::bounds()['maxAddressLength']
            )
            || !self::text(
                $address['region'] ?? null,
                self::bounds()['maxAddressLength']
            )
            || (($address['postalCode'] ?? null) !== null
                && !self::text($address['postalCode'], 32))
            || !is_string($address['countryCode'] ?? null)
            || preg_match('/\A[A-Z]{2}\z/D', $address['countryCode']) !== 1
            || (($address['instructions'] ?? null) !== null
                && !self::text(
                    $address['instructions'],
                    self::bounds()['maxInstructionsLength']
                ))
        ) {
            return null;
        }
        return [
            'line1' => $address['line1'],
            'line2' => $address['line2'],
            'city' => $address['city'],
            'region' => $address['region'],
            'postalCode' => $address['postalCode'],
            'countryCode' => $address['countryCode'],
            'instructions' => $address['instructions'],
        ];
    }

    private static function allowedList(
        mixed $values,
        array $allowed,
        string $error,
        array &$errors
    ): array {
        if (!is_array($values)
            || !array_is_list($values)
            || $values === []
            || count($values) !== count(array_unique($values, SORT_REGULAR))
        ) {
            $errors[] = $error;
            return [];
        }
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                $errors[] = $error;
                return [];
            }
        }
        return array_values(array_intersect($allowed, $values));
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) === 1;
    }

    private static function sku(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/D', $value) === 1;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function phone(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= self::bounds()['maxPhoneLength']
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

    private static function invalid(array $errors): array
    {
        $errors = array_values(array_unique($errors));
        sort($errors, SORT_STRING);
        return [
            'valid' => false,
            'snapshot' => null,
            'snapshotSha256' => null,
            'sourceCartStateSha256' => null,
            'initialState' => null,
            'errors' => $errors === [] ? ['invalid'] : $errors,
        ];
    }
}
