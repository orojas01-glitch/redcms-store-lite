<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$root = dirname(__DIR__) . '/package';
require_once $root . '/src/PublicSubscriptionButtonPresenter.php';
require_once $root . '/src/SubscriptionIntentCommand.php';

$offer = [
    'id' => 'membership-monthly', 'productId' => 'membership',
    'variantId' => null, 'title' => 'Studio membership',
    'summary' => 'Member access', 'currency' => 'USD',
    'priceMinor' => 2900, 'billingPeriod' => 'monthly',
    'state' => 'published', 'availability' => 'available',
    'buttonLabel' => 'Subscribe monthly',
];

try {
    $view = RED_CMS_Store_Lite_Public_Subscription_Button_Presenter::present(
        $offer,
        'USD'
    );
    $assert(
        $view === [
            'title' => 'Studio membership',
            'summary' => 'Member access',
            'facts' => [[
                'label' => 'Subscription',
                'value' => 'USD 29.00 / month',
            ]],
            'mutationForm' => [
                'route' => 'redcms.store-lite/subscription-intent',
                'mutation' => 'redcms.store-lite/create-subscription-intent',
                'submitLabel' => 'Subscribe monthly',
                'fields' => [[
                    'key' => 'offer', 'control' => 'hidden',
                    'value' => 'membership-monthly',
                ]],
            ],
        ],
        'monthly offer button model failed'
    );
    $yearly = $offer;
    $yearly['billingPeriod'] = 'yearly';
    $assert(
        str_ends_with(
            RED_CMS_Store_Lite_Public_Subscription_Button_Presenter::present(
                $yearly,
                'USD'
            )['facts'][0]['value'],
            '/ year'
        ),
        'yearly label failed'
    );
    foreach (['draft', 'archived'] as $state) {
        $invalid = $offer;
        $invalid['state'] = $state;
        $assert(
            RED_CMS_Store_Lite_Public_Subscription_Button_Presenter::present(
                $invalid,
                'USD'
            ) === null,
            $state . ' offer exposed a button'
        );
    }
    $unavailable = $offer;
    $unavailable['availability'] = 'unavailable';
    $assert(
        RED_CMS_Store_Lite_Public_Subscription_Button_Presenter::present(
            $unavailable,
            'USD'
        ) === null,
        'unavailable offer exposed a button'
    );
    $assert(
        RED_CMS_Store_Lite_Subscription_Intent_Command::decode([
            'offer' => 'membership-monthly',
        ]) === [
            'valid' => true, 'offerId' => 'membership-monthly', 'errors' => [],
        ],
        'intent decoder failed'
    );
    foreach ([[], ['offer' => 'Bad Offer'], ['offer' => 'ok', 'extra' => 'x']]
        as $invalid
    ) {
        $assert(
            RED_CMS_Store_Lite_Subscription_Intent_Command::decode(
                $invalid
            )['valid'] === false,
            'invalid intent accepted'
        );
    }
    foreach (['PublicSubscriptionButtonPresenter.php', 'SubscriptionIntentCommand.php'] as $file) {
        $source = (string) file_get_contents($root . '/src/' . $file);
        $assert(
            !preg_match('/\b(?:mysqli|PDO|curl|\$_(?:GET|POST|SERVER|SESSION))\b/', $source),
            $file . ' gained runtime behavior'
        );
    }
    echo 'Store Lite public subscription button contract passed: '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
