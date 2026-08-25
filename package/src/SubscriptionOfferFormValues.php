<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionOffer.php';

final class RED_CMS_Store_Lite_Subscription_Offer_Form_Values
{
    private const KEYS = [
        'id', 'product-id', 'variant-id', 'title', 'summary', 'currency',
        'price-minor', 'billing-period', 'state', 'availability',
        'button-label',
    ];

    public static function fromOffer(array $offer): ?array
    {
        $currency = $offer['currency'] ?? null;
        if (!is_string($currency)) {
            return null;
        }
        $normalized = RED_CMS_Store_Lite_Subscription_Offer::normalize(
            $offer,
            $currency
        );
        if (($normalized['valid'] ?? null) !== true) {
            return null;
        }
        $offer = $normalized['offer'];
        return [
            'id' => $offer['id'],
            'product-id' => $offer['productId'],
            'variant-id' => $offer['variantId'],
            'title' => $offer['title'],
            'summary' => $offer['summary'],
            'currency' => $offer['currency'],
            'price-minor' => $offer['priceMinor'],
            'billing-period' => $offer['billingPeriod'],
            'state' => $offer['state'],
            'availability' => $offer['availability'],
            'button-label' => $offer['buttonLabel'],
        ];
    }

    public static function toOffer(
        array $values,
        string $installationCurrency,
        string $expectedOfferId
    ): ?array {
        $keys = array_keys($values);
        $expected = self::KEYS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected
            || !is_string($values['id'])
            || !hash_equals($expectedOfferId, $values['id'])
            || !is_string($values['product-id'])
            || !self::nullableString($values['variant-id'])
            || !is_string($values['title'])
            || !self::nullableString($values['summary'])
            || !is_string($values['currency'])
            || !is_int($values['price-minor'])
            || !is_string($values['billing-period'])
            || !is_string($values['state'])
            || !is_string($values['availability'])
            || !is_string($values['button-label'])
        ) {
            return null;
        }
        $offer = [
            'id' => $values['id'],
            'productId' => $values['product-id'],
            'variantId' => self::emptyToNull($values['variant-id']),
            'title' => $values['title'],
            'summary' => self::emptyToNull($values['summary']),
            'currency' => $values['currency'],
            'priceMinor' => $values['price-minor'],
            'billingPeriod' => $values['billing-period'],
            'state' => $values['state'],
            'availability' => $values['availability'],
            'buttonLabel' => $values['button-label'],
        ];
        $normalized = RED_CMS_Store_Lite_Subscription_Offer::normalize(
            $offer,
            $installationCurrency
        );
        return ($normalized['valid'] ?? null) === true
            ? $normalized['offer']
            : null;
    }

    private static function nullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    private static function emptyToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
