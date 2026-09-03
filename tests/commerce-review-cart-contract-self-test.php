<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__) . '/package';
require_once $packageRoot . '/src/CommerceReviewCart.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$createdAt = 1788395230;
$sha = static fn (string $value): string => hash('sha256', $value);
$policy = [
    'salesAssistedTtlSeconds' => 604800,
    'configuratorTtlSeconds' => 86400,
    'maximumLines' => 24,
    'maximumQuantity' => 100,
];
$draft = [
    'cartId' => 'cart_' . str_repeat('a', 32),
    'source' => 'configurator',
    'idempotencyKeySha256' => $sha('configurator-submission-1'),
    'createdAtEpoch' => $createdAt,
    'expiresAtEpoch' => $createdAt + 86400,
    'currency' => 'USD',
    'catalogVersion' => '2026-09-02',
    'customer' => [
        'name' => 'Client Name',
        'company' => 'Client Company',
        'email' => 'client@example.com',
        'phone' => '+1 555 010 0200',
    ],
    'lines' => [[
        'itemId' => 'ai-assistant-foundation',
        'title' => 'AI Assistant Foundation',
        'quantity' => 1,
        'setupUnitMinor' => 80000,
        'recurringUnitMinor' => 5900,
        'recurringInterval' => 'month',
        'itemStateSha256' => $sha('foundation-v1'),
    ], [
        'itemId' => 'additional-language',
        'title' => 'Additional Language',
        'quantity' => 2,
        'setupUnitMinor' => 15000,
        'recurringUnitMinor' => 0,
        'recurringInterval' => null,
        'itemStateSha256' => $sha('language-v1'),
    ]],
];

try {
    $source = (string) file_get_contents(
        $packageRoot . '/src/CommerceReviewCart.php'
    );
    $assert(
        !preg_match(
            '/\b(?:mysqli|PDO|curl|fopen|file_put_contents|getenv)\b|\$_(?:GET|POST|SERVER|SESSION)/',
            $source
        ),
        'cart contract has no database, request, filesystem, environment, or network path'
    );

    $normalized = RED_CMS_Store_Lite_Commerce_Review_Cart::normalize(
        $draft,
        $policy
    );
    $assert(($normalized['valid'] ?? false) === true, 'valid draft is accepted');
    $cart = $normalized['cart'];
    $assert($cart['state'] === 'draft', 'new cart starts as draft');
    $assert(
        $cart['onboardingStatus'] === 'not_started',
        'payment cart starts without onboarding or provisioning'
    );
    $assert($cart['setupSubtotalMinor'] === 110000, 'setup subtotal is derived');
    $assert($cart['recurringSubtotalMinor'] === 5900, 'monthly subtotal is derived');
    $assert($cart['amountDueTodayMinor'] === 115900, 'due today includes setup plus first month');
    $assert($cart['futureRenewalMinor'] === 5900, 'future renewal is recurring only');
    $assert($cart['taxStatus'] === 'not_configured', 'tax remains explicitly unconfigured');
    $assert($cart['taxDueTodayMinor'] === null, 'no tax amount is invented');
    $assert($cart['taxFutureRenewalMinor'] === null, 'no renewal tax is invented');
    $assert(count($cart['lines']) === 2, 'all lines are retained');
    $assert(
        preg_match('/\A[0-9a-f]{64}\z/D', $cart['snapshotSha256']) === 1,
        'cart carries deterministic snapshot evidence'
    );
    $assert(
        RED_CMS_Store_Lite_Commerce_Review_Cart::accepted($cart),
        'normalized cart can be revalidated before persistence'
    );
    $assert(
        $cart === RED_CMS_Store_Lite_Commerce_Review_Cart::normalize(
            $draft,
            $policy
        )['cart'],
        'identical input produces identical snapshot evidence'
    );

    $salesDraft = $draft;
    $salesDraft['cartId'] = 'cart_' . str_repeat('b', 32);
    $salesDraft['source'] = 'sales_assisted';
    $salesDraft['expiresAtEpoch'] = $createdAt + 604800;
    $assert(
        RED_CMS_Store_Lite_Commerce_Review_Cart::normalize(
            $salesDraft,
            $policy
        )['valid'] === true,
        'sales-assisted cart accepts the approved seven-day lifetime'
    );

    $invalid = [];
    $browserTotal = $draft;
    $browserTotal['amountDueTodayMinor'] = 1;
    $invalid[] = $browserTotal;
    $wrongTtl = $draft;
    $wrongTtl['expiresAtEpoch']++;
    $invalid[] = $wrongTtl;
    $badQuantity = $draft;
    $badQuantity['lines'][0]['quantity'] = 0;
    $invalid[] = $badQuantity;
    $badInterval = $draft;
    $badInterval['lines'][0]['recurringInterval'] = null;
    $invalid[] = $badInterval;
    $freeLine = $draft;
    $freeLine['lines'][0]['setupUnitMinor'] = 0;
    $freeLine['lines'][0]['recurringUnitMinor'] = 0;
    $freeLine['lines'][0]['recurringInterval'] = null;
    $invalid[] = $freeLine;
    $badCustomer = $draft;
    $badCustomer['customer']['email'] = 'not-an-email';
    $invalid[] = $badCustomer;
    $unknownLineField = $draft;
    $unknownLineField['lines'][0]['stripePriceId'] = 'price_browser_owned';
    $invalid[] = $unknownLineField;

    foreach ($invalid as $candidate) {
        $assert(
            RED_CMS_Store_Lite_Commerce_Review_Cart::normalize(
                $candidate,
                $policy
            )['valid'] === false,
            'browser totals, invalid TTL, quantities, terms, contact, and provider fields fail closed'
        );
    }

    echo 'Store Lite commerce review cart contract passed '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
