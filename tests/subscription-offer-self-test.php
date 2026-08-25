<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};
$packageRoot = dirname(__DIR__) . '/package';
require_once $packageRoot . '/src/SubscriptionOffer.php';

$monthly = [
    'id' => 'studio-membership-monthly',
    'productId' => 'studio-membership',
    'variantId' => null,
    'title' => 'Studio membership',
    'summary' => 'One month of member access.',
    'currency' => 'USD',
    'priceMinor' => 2900,
    'billingPeriod' => 'monthly',
    'state' => 'published',
    'availability' => 'available',
    'buttonLabel' => 'Subscribe monthly',
];

try {
    $source = (string) file_get_contents(
        $packageRoot . '/src/SubscriptionOffer.php'
    );
    $assert(
        !preg_match(
            '/\b(?:mysqli|PDO|curl|file_put_contents|\$_(?:GET|POST|COOKIE|REQUEST|SERVER|SESSION))\b/',
            $source
        ),
        'subscription contract has no database, request, write, or network dependency'
    );
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer::bounds() === [
            'maxIdentifierLength' => 64,
            'maxTitleLength' => 160,
            'maxSummaryLength' => 1000,
            'maxButtonLabelLength' => 80,
            'maxPriceMinor' => 999999999,
        ],
        'subscription contract exposes exact bounds'
    );

    $normalized = RED_CMS_Store_Lite_Subscription_Offer::normalize(
        $monthly,
        'USD'
    );
    $assert(
        ($normalized['valid'] ?? null) === true
            && ($normalized['offer'] ?? null) === $monthly
            && ($normalized['errors'] ?? null) === [],
        'published monthly offer normalizes exactly'
    );
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer::buttonPreview($monthly) === [
            'label' => 'Subscribe monthly',
            'price' => 'USD 29.00',
            'period' => 'monthly',
            'offerId' => 'studio-membership-monthly',
            'productId' => 'studio-membership',
            'variantId' => null,
            'checkoutEnabled' => false,
            'status' => 'subscription_adapter_required',
        ],
        'public button preview is explicit and fail-closed before adapter work'
    );

    $yearly = $monthly;
    $yearly['id'] = 'studio-membership-yearly';
    $yearly['variantId'] = 'annual';
    $yearly['priceMinor'] = 29000;
    $yearly['billingPeriod'] = 'yearly';
    $yearly['buttonLabel'] = 'Subscribe yearly';
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer::normalize(
            $yearly,
            'USD'
        )['valid'] === true,
        'yearly variant-bound offer is supported'
    );

    foreach ([
        'currency' => ['currency' => 'COP'],
        'float-price' => ['priceMinor' => 29.00],
        'weekly' => ['billingPeriod' => 'weekly'],
        'unknown' => ['providerPriceId' => 'price_hidden'],
        'bad-product' => ['productId' => 'Studio Membership'],
        'bad-variant' => ['variantId' => 'Annual Plan'],
        'bad-label' => ['buttonLabel' => "Subscribe\nnow"],
    ] as $name => $change) {
        $invalid = array_replace($monthly, $change);
        $result = RED_CMS_Store_Lite_Subscription_Offer::normalize(
            $invalid,
            'USD'
        );
        $assert(
            ($result['valid'] ?? null) === false
                && ($result['offer'] ?? null) === null
                && ($result['errors'] ?? null) !== [],
            'invalid ' . $name . ' offer fails closed'
        );
    }

    $draft = $monthly;
    $draft['state'] = 'draft';
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer::buttonPreview($draft) === null,
        'draft offers expose no button preview'
    );
    $unavailable = $monthly;
    $unavailable['availability'] = 'unavailable';
    $assert(
        RED_CMS_Store_Lite_Subscription_Offer::buttonPreview($unavailable)
            === null,
        'unavailable offers expose no button preview'
    );
    $assert(
        !str_contains($source, 'stripe')
            && !str_contains($source, 'webhook')
            && !str_contains($source, 'checkout.session')
            && !str_contains($source, 'subscription.create'),
        'provider, webhook, and execution behavior remain outside this contract'
    );

    echo 'Store Lite subscription-offer contract passed: '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
