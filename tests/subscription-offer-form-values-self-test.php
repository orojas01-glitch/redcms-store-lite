<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$root = dirname(__DIR__) . '/package';
require_once $root . '/src/SubscriptionOfferFormValues.php';

$offer = [
    'id' => 'membership-monthly', 'productId' => 'membership',
    'variantId' => null, 'title' => 'Membership', 'summary' => null,
    'currency' => 'USD', 'priceMinor' => 2900,
    'billingPeriod' => 'monthly', 'state' => 'draft',
    'availability' => 'unavailable', 'buttonLabel' => 'Subscribe',
];
$values = [
    'id' => 'membership-monthly', 'product-id' => 'membership',
    'variant-id' => null, 'title' => 'Membership', 'summary' => null,
    'currency' => 'USD', 'price-minor' => 2900,
    'billing-period' => 'monthly', 'state' => 'draft',
    'availability' => 'unavailable', 'button-label' => 'Subscribe',
];

try {
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer_Form_Values::fromOffer($offer)
            === $values,
        'offer to form projection failed'
    );
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer_Form_Values::toOffer(
            $values, 'USD', 'membership-monthly'
        ) === $offer,
        'form to offer projection failed'
    );
    $variant = $values;
    $variant['variant-id'] = 'annual';
    $variant['billing-period'] = 'yearly';
    $variant['price-minor'] = 29000;
    $projected = RED_CMS_Store_Lite_Subscription_Offer_Form_Values::toOffer(
        $variant, 'USD', 'membership-monthly'
    );
    $assert(
        ($projected['variantId'] ?? null) === 'annual'
            && ($projected['billingPeriod'] ?? null) === 'yearly',
        'variant yearly form failed'
    );
    foreach ([
        'changed-id' => ['id' => 'other'],
        'string-price' => ['price-minor' => '2900'],
        'currency' => ['currency' => 'COP'],
        'unknown' => ['unknown' => true],
    ] as $name => $change) {
        $invalid = array_replace($values, $change);
        $assert(
            RED_CMS_Store_Lite_Subscription_Offer_Form_Values::toOffer(
                $invalid, 'USD', 'membership-monthly'
            ) === null,
            'invalid ' . $name . ' form accepted'
        );
    }
    $source = (string) file_get_contents(
        $root . '/src/SubscriptionOfferFormValues.php'
    );
    $assert(
        !preg_match('/\b(?:mysqli|PDO|curl|\$_(?:GET|POST|SERVER|SESSION))\b/', $source),
        'form values gained runtime dependencies'
    );
    echo 'Store Lite subscription form values passed: '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
