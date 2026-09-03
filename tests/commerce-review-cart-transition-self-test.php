<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__) . '/package';
require_once $packageRoot . '/src/CommerceReviewCartTransition.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$now = 1788395230;
$state = static fn (
    string $cartState = 'draft',
    string $onboarding = 'not_started',
    string $lastEvent = '',
    int $version = 1
): array => [
    'cartId' => 'cart_' . str_repeat('a', 32),
    'state' => $cartState,
    'onboardingStatus' => $onboarding,
    'expiresAtEpoch' => $now + 86400,
    'lastEventEvidenceSha256' => $lastEvent,
    'version' => $version,
];
$event = static fn (string $type, int $occurredAt = 0): array => [
    'type' => $type,
    'evidenceSha256' => hash('sha256', $type . ':' . ($occurredAt ?: $now)),
    'occurredAtEpoch' => $occurredAt ?: $now,
];

try {
    $shared = RED_CMS_Store_Lite_Commerce_Review_Cart_Transition::plan(
        $state(),
        $event('cart.shared')
    );
    $assert(($shared['valid'] ?? false) === true, 'draft can become shared');
    $assert($shared['targetState']['state'] === 'shared', 'share target is explicit');

    $checkout = RED_CMS_Store_Lite_Commerce_Review_Cart_Transition::plan(
        $state('shared', 'not_started', '', 2),
        $event('checkout.started')
    );
    $assert($checkout['targetState']['state'] === 'checkout_pending', 'checkout becomes pending');
    $assert($checkout['targetState']['onboardingStatus'] === 'not_started', 'checkout does not begin onboarding');

    $failed = RED_CMS_Store_Lite_Commerce_Review_Cart_Transition::plan(
        $state('checkout_pending', 'not_started', '', 3),
        $event('payment.failed')
    );
    $assert($failed['targetState']['state'] === 'payment_failed', 'verified failure remains visible');

    $paid = RED_CMS_Store_Lite_Commerce_Review_Cart_Transition::plan(
        $state('checkout_pending', 'not_started', '', 3),
        $event('payment.paid')
    );
    $assert($paid['targetState']['state'] === 'paid', 'verified paid event marks cart paid');
    $assert(
        $paid['targetState']['onboardingStatus'] === 'pending',
        'payment starts onboarding but never claims implementation complete'
    );

    $returned = RED_CMS_Store_Lite_Commerce_Review_Cart_Transition::plan(
        $state('checkout_pending', 'not_started', '', 3),
        $event('checkout.expired', $now + 1800)
    );
    $assert($returned['targetState']['state'] === 'shared', 'expired Session returns an unexpired cart to review');

    $replayEvent = $event('payment.paid');
    $replayed = RED_CMS_Store_Lite_Commerce_Review_Cart_Transition::plan(
        $state('paid', 'pending', $replayEvent['evidenceSha256'], 4),
        $replayEvent
    );
    $assert(($replayed['status'] ?? '') === 'replayed', 'exact duplicate event is idempotent');
    $assert($replayed['stateChanged'] === false, 'replay makes no state change');

    $expired = RED_CMS_Store_Lite_Commerce_Review_Cart_Transition::plan(
        $state('shared', 'not_started', '', 2),
        $event('cart.expired', $now + 86400)
    );
    $assert($expired['targetState']['state'] === 'expired', 'cart expiry is explicit at its deadline');

    $invalid = [
        [$state('paid', 'pending', '', 4), $event('payment.failed')],
        [$state('canceled', 'not_started', '', 2), $event('checkout.started')],
        [$state('shared', 'not_started', '', 2), $event('cart.expired', $now + 10)],
        [$state('draft'), $event('payment.paid')],
    ];
    foreach ($invalid as [$current, $candidate]) {
        $assert(
            RED_CMS_Store_Lite_Commerce_Review_Cart_Transition::plan(
                $current,
                $candidate
            )['valid'] === false,
            'terminal, early, and out-of-order transitions fail closed'
        );
    }

    echo 'Store Lite commerce review cart transition passed '
        . $assertions . " assertions.\n";
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
