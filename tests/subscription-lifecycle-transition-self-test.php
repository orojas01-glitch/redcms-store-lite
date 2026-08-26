<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/package';
require_once $root . '/src/SubscriptionLifecycleTransition.php';
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$current = static fn (
    string $subscription = 'pending',
    string $entitlement = 'inactive',
    ?string $provider = null,
    ?int $period = null
): array => [
    'intentReference' => 'sint_' . str_repeat('1', 32),
    'offerStateSha256' => str_repeat('2', 64),
    'subscriptionStatus' => $subscription,
    'entitlementStatus' => $entitlement,
    'providerSubscriptionRefSha256' => $provider,
    'currentPeriodEndEpoch' => $period,
];
$event = static fn (
    string $outcome,
    ?string $provider = null,
    ?int $period = null
): array => [
    'verification' => 'verified',
    'replayStatus' => 'unseen',
    'intentReference' => 'sint_' . str_repeat('1', 32),
    'offerStateSha256' => str_repeat('2', 64),
    'outcome' => $outcome,
    'providerSubscriptionRefSha256' => $provider,
    'currentPeriodEndEpoch' => $period,
    'eventEvidenceSha256' => hash('sha256', $outcome . (string) $period),
    'occurredAt' => 1787630400,
];

try {
    $source = (string) file_get_contents(
        $root . '/src/SubscriptionLifecycleTransition.php'
    );
    $assert(
        !preg_match(
            '/\b(?:mysqli|PDO|curl|fopen|file_put_contents|getenv)\b|\$_(?:GET|POST|SERVER|SESSION)/',
            $source
        ),
        'transition has no runtime, database, request, filesystem, or network path'
    );
    $provider = str_repeat('3', 64);
    $activated = RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
        $current(),
        $event('activated', $provider, 1790308800)
    );
    $assert(($activated['valid'] ?? false) === true, 'activation is valid');
    $assert(
        $activated['transition']['targetState'] === [
            'subscriptionStatus' => 'active',
            'entitlementStatus' => 'active',
            'providerSubscriptionRefSha256' => $provider,
            'currentPeriodEndEpoch' => 1790308800,
        ],
        'activation grants entitlement only with provider and period agreement'
    );
    $renewed = RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
        $current('active', 'active', $provider, 1790308800),
        $event('renewed', $provider, 1792987200)
    );
    $assert(
        ($renewed['transition']['eventName'] ?? '') === 'subscription.renewed'
            && $renewed['transition']['targetState']['entitlementStatus']
                === 'active',
        'renewal extends active entitlement'
    );
    $pastDue = RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
        $current('active', 'active', $provider, 1790308800),
        $event('past_due', $provider, 1790308800)
    );
    $assert(
        $pastDue['transition']['targetState']['subscriptionStatus']
            === 'past_due'
            && $pastDue['transition']['targetState']['entitlementStatus']
                === 'revoked',
        'past due revokes entitlement conservatively'
    );
    $recovered = RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
        $current('past_due', 'revoked', $provider, 1790308800),
        $event('activated', $provider, 1792987200)
    );
    $assert(
        $recovered['transition']['targetState']['entitlementStatus'] === 'active',
        'verified recovery restores entitlement'
    );
    $canceled = RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
        $current('active', 'active', $provider, 1790308800),
        $event('canceled', $provider, 1790308800)
    );
    $assert(
        $canceled['transition']['targetState']['subscriptionStatus']
            === 'canceled'
            && $canceled['transition']['targetState']['entitlementStatus']
                === 'revoked',
        'cancellation revokes entitlement'
    );
    $expired = RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
        $current(),
        $event('expired')
    );
    $assert(
        $expired['transition']['targetState'] === [
            'subscriptionStatus' => 'expired',
            'entitlementStatus' => 'inactive',
            'providerSubscriptionRefSha256' => null,
            'currentPeriodEndEpoch' => null,
        ],
        'expired Checkout leaves entitlement inactive'
    );
    $cases = [];
    $replayed = $event('activated', $provider, 1790308800);
    $replayed['replayStatus'] = 'replayed';
    $cases[] = [$current(), $replayed];
    $stale = $event('activated', $provider, 1790308800);
    $stale['offerStateSha256'] = str_repeat('f', 64);
    $cases[] = [$current(), $stale];
    $cases[] = [$current(), $event('activated', null, 1790308800)];
    $cases[] = [$current(), $event('activated', $provider, 1787630300)];
    $cases[] = [
        $current('active', 'active', $provider, 1790308800),
        $event('renewed', $provider, 1790308700),
    ];
    $cases[] = [
        $current('active', 'active', $provider, 1790308800),
        $event('canceled', str_repeat('4', 64), 1790308800),
    ];
    $cases[] = [
        $current('canceled', 'revoked', $provider, 1790308800),
        $event('activated', $provider, 1792987200),
    ];
    foreach ($cases as [$state, $candidate]) {
        $assert(
            RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
                $state,
                $candidate
            )['valid'] === false,
            'invalid, replayed, stale, foreign, or terminal transition is refused'
        );
    }
    $assert(
        $activated === RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
            $current(),
            $event('activated', $provider, 1790308800)
        ),
        'identical lifecycle facts produce identical evidence'
    );
    echo 'Store Lite subscription lifecycle transition passed '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
