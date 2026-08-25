<?php

declare(strict_types=1);

/**
 * Pure provider-neutral Store Lite subscription-offer contract.
 *
 * Subscription offers stay separate from cart lines, stock, one-time orders,
 * provider prices, checkout sessions, customers, and entitlement state.
 */
final class RED_CMS_Store_Lite_Subscription_Offer
{
    public static function bounds(): array
    {
        return [
            'maxIdentifierLength' => 64,
            'maxTitleLength' => 160,
            'maxSummaryLength' => 1000,
            'maxButtonLabelLength' => 80,
            'maxPriceMinor' => 999999999,
        ];
    }

    public static function normalize(
        array $input,
        string $installationCurrency
    ): array {
        $bounds = self::bounds();
        $expected = [
            'id', 'productId', 'variantId', 'title', 'summary',
            'currency', 'priceMinor', 'billingPeriod', 'state',
            'availability', 'buttonLabel',
        ];
        $errors = self::unknownKeys($input, $expected);
        foreach ([
            'id', 'productId', 'title', 'currency', 'priceMinor',
            'billingPeriod', 'state', 'availability', 'buttonLabel',
        ] as $key) {
            if (!array_key_exists($key, $input)) {
                $errors[] = $key . '_missing';
            }
        }
        if ($errors !== []) {
            return self::invalid($errors);
        }

        if (!self::identifier($input['id'], $bounds['maxIdentifierLength'])) {
            $errors[] = 'id_invalid';
        }
        if (!self::identifier(
            $input['productId'],
            $bounds['maxIdentifierLength']
        )) {
            $errors[] = 'product_id_invalid';
        }
        $variantId = $input['variantId'] ?? null;
        if ($variantId !== null
            && !self::identifier($variantId, $bounds['maxIdentifierLength'])
        ) {
            $errors[] = 'variant_id_invalid';
        }
        if (!self::text($input['title'], 1, $bounds['maxTitleLength'])) {
            $errors[] = 'title_invalid';
        }
        $summary = $input['summary'] ?? null;
        if ($summary !== null
            && !self::text($summary, 1, $bounds['maxSummaryLength'])
        ) {
            $errors[] = 'summary_invalid';
        }
        if (preg_match('/\A[A-Z]{3}\z/D', $installationCurrency) !== 1
            || $input['currency'] !== $installationCurrency
        ) {
            $errors[] = 'currency_invalid';
        }
        if (!is_int($input['priceMinor'])
            || $input['priceMinor'] < 0
            || $input['priceMinor'] > $bounds['maxPriceMinor']
        ) {
            $errors[] = 'price_minor_invalid';
        }
        if (!in_array(
            $input['billingPeriod'],
            ['monthly', 'yearly'],
            true
        )) {
            $errors[] = 'billing_period_invalid';
        }
        if (!in_array(
            $input['state'],
            ['draft', 'published', 'archived'],
            true
        )) {
            $errors[] = 'state_invalid';
        }
        if (!in_array(
            $input['availability'],
            ['available', 'unavailable'],
            true
        )) {
            $errors[] = 'availability_invalid';
        }
        if (!self::text(
            $input['buttonLabel'],
            1,
            $bounds['maxButtonLabelLength']
        )) {
            $errors[] = 'button_label_invalid';
        }

        if ($errors !== []) {
            return self::invalid($errors);
        }
        return [
            'valid' => true,
            'offer' => [
                'id' => $input['id'],
                'productId' => $input['productId'],
                'variantId' => $variantId,
                'title' => $input['title'],
                'summary' => $summary,
                'currency' => $installationCurrency,
                'priceMinor' => $input['priceMinor'],
                'billingPeriod' => $input['billingPeriod'],
                'state' => $input['state'],
                'availability' => $input['availability'],
                'buttonLabel' => $input['buttonLabel'],
            ],
            'errors' => [],
        ];
    }

    public static function buttonPreview(array $offer): ?array
    {
        $currency = is_string($offer['currency'] ?? null)
            ? $offer['currency']
            : '';
        $normalized = self::normalize($offer, $currency);
        if (($normalized['valid'] ?? null) !== true
            || ($normalized['offer']['state'] ?? null) !== 'published'
            || ($normalized['offer']['availability'] ?? null) !== 'available'
        ) {
            return null;
        }
        $current = $normalized['offer'];
        return [
            'label' => $current['buttonLabel'],
            'price' => self::money(
                $current['priceMinor'],
                $current['currency']
            ),
            'period' => $current['billingPeriod'],
            'offerId' => $current['id'],
            'productId' => $current['productId'],
            'variantId' => $current['variantId'],
            'checkoutEnabled' => false,
            'status' => 'subscription_adapter_required',
        ];
    }

    private static function identifier(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && strlen($value) <= $maximum
            && preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $value) === 1;
    }

    private static function text(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value)
            && strlen($value) >= $minimum
            && strlen($value) <= $maximum
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private static function unknownKeys(array $value, array $expected): array
    {
        $unknown = array_values(array_diff(array_keys($value), $expected));
        sort($unknown, SORT_STRING);
        return array_map(
            static fn (string $key): string => 'unknown_' . $key,
            $unknown
        );
    }

    private static function money(int $minor, string $currency): string
    {
        return $currency . ' ' . number_format($minor / 100, 2, '.', ',');
    }

    private static function invalid(array $errors): array
    {
        return ['valid' => false, 'offer' => null, 'errors' => $errors];
    }
}
