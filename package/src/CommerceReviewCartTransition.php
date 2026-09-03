<?php

declare(strict_types=1);

/**
 * Pure, fail-closed state transition contract for review-cart payment state.
 * Technical implementation remains a separate onboarding state.
 */
final class RED_CMS_Store_Lite_Commerce_Review_Cart_Transition
{
    private const STATES = [
        'draft',
        'shared',
        'checkout_pending',
        'paid',
        'expired',
        'canceled',
        'payment_failed',
    ];

    private const EVENTS = [
        'cart.shared',
        'checkout.started',
        'checkout.expired',
        'payment.failed',
        'payment.paid',
        'cart.expired',
        'cart.canceled',
    ];

    public static function plan(array $current, array $event): array
    {
        if (!self::current($current) || !self::event($event)) {
            return self::invalid('commerce_cart_transition_input_refused');
        }
        if ($current['lastEventEvidenceSha256'] !== ''
            && hash_equals(
                $current['lastEventEvidenceSha256'],
                $event['evidenceSha256']
            )
        ) {
            return [
                'valid' => true,
                'status' => 'replayed',
                'stateChanged' => false,
                'eventName' => $event['type'],
                'targetState' => $current,
                'transitionSha256' => self::hash([$current, $event, 'replayed']),
                'errors' => [],
            ];
        }

        $state = $current['state'];
        $targetState = null;
        $targetOnboarding = $current['onboardingStatus'];
        switch ($event['type']) {
            case 'cart.shared':
                if ($state === 'draft'
                    && $event['occurredAtEpoch'] < $current['expiresAtEpoch']
                ) {
                    $targetState = 'shared';
                }
                break;
            case 'checkout.started':
                if (in_array($state, ['shared', 'payment_failed'], true)
                    && $event['occurredAtEpoch'] < $current['expiresAtEpoch']
                ) {
                    $targetState = 'checkout_pending';
                }
                break;
            case 'checkout.expired':
                if ($state === 'checkout_pending') {
                    $targetState = $event['occurredAtEpoch'] >= $current['expiresAtEpoch']
                        ? 'expired'
                        : 'shared';
                }
                break;
            case 'payment.failed':
                if ($state === 'checkout_pending') {
                    $targetState = 'payment_failed';
                }
                break;
            case 'payment.paid':
                if (in_array(
                    $state,
                    ['checkout_pending', 'payment_failed'],
                    true
                )) {
                    $targetState = 'paid';
                    $targetOnboarding = 'pending';
                }
                break;
            case 'cart.expired':
                if (in_array(
                    $state,
                    ['draft', 'shared', 'payment_failed'],
                    true
                ) && $event['occurredAtEpoch'] >= $current['expiresAtEpoch']) {
                    $targetState = 'expired';
                }
                break;
            case 'cart.canceled':
                if (!in_array($state, ['paid', 'expired', 'canceled'], true)) {
                    $targetState = 'canceled';
                }
                break;
        }
        if ($targetState === null) {
            return self::invalid('commerce_cart_transition_refused');
        }

        $target = $current;
        $target['state'] = $targetState;
        $target['onboardingStatus'] = $targetOnboarding;
        $target['lastEventEvidenceSha256'] = $event['evidenceSha256'];
        $target['version']++;
        return [
            'valid' => true,
            'status' => 'applied',
            'stateChanged' => true,
            'eventName' => $event['type'],
            'targetState' => $target,
            'transitionSha256' => self::hash([$current, $event, $target]),
            'errors' => [],
        ];
    }

    private static function current(array $value): bool
    {
        return self::exactKeys($value, [
            'cartId',
            'state',
            'onboardingStatus',
            'expiresAtEpoch',
            'lastEventEvidenceSha256',
            'version',
        ])
            && is_string($value['cartId'] ?? null)
            && preg_match('/\Acart_[a-f0-9]{32}\z/D', $value['cartId']) === 1
            && in_array($value['state'] ?? null, self::STATES, true)
            && in_array(
                $value['onboardingStatus'] ?? null,
                ['not_started', 'pending', 'in_progress', 'complete', 'canceled'],
                true
            )
            && self::timestamp($value['expiresAtEpoch'] ?? null)
            && (($value['lastEventEvidenceSha256'] ?? null) === ''
                || self::sha256($value['lastEventEvidenceSha256']))
            && is_int($value['version'] ?? null)
            && $value['version'] >= 1;
    }

    private static function event(array $value): bool
    {
        return self::exactKeys(
            $value,
            ['type', 'evidenceSha256', 'occurredAtEpoch']
        )
            && in_array($value['type'] ?? null, self::EVENTS, true)
            && self::sha256($value['evidenceSha256'] ?? null)
            && self::timestamp($value['occurredAtEpoch'] ?? null);
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }

    private static function timestamp(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 4102444800;
    }

    private static function exactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        return $keys === $expected;
    }

    private static function hash(array $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    private static function invalid(string $reason): array
    {
        return [
            'valid' => false,
            'status' => 'refused',
            'stateChanged' => false,
            'eventName' => '',
            'targetState' => null,
            'transitionSha256' => '',
            'errors' => [$reason],
        ];
    }
}
