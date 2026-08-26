<?php

declare(strict_types=1);

/** Pure provider-neutral subscription and entitlement transition contract. */
final class RED_CMS_Store_Lite_Subscription_Lifecycle_Transition
{
    public static function plan(array $current, array $event): array
    {
        if (!self::current($current) || !self::event($event)
            || $event['replayStatus'] !== 'unseen'
            || !hash_equals(
                $current['intentReference'],
                $event['intentReference']
            )
            || !hash_equals(
                $current['offerStateSha256'],
                $event['offerStateSha256']
            )
        ) {
            return self::invalid('subscription_event_refused');
        }
        $status = $current['subscriptionStatus'];
        $outcome = $event['outcome'];
        $providerRef = $event['providerSubscriptionRefSha256'];
        $periodEnd = $event['currentPeriodEndEpoch'];
        $eventName = '';
        $target = null;

        if ($outcome === 'activated'
            && in_array($status, ['pending', 'past_due'], true)
            && self::sha256($providerRef)
            && is_int($periodEnd)
            && $periodEnd > $event['occurredAt']
            && ($current['providerSubscriptionRefSha256'] === null
                || hash_equals(
                    $current['providerSubscriptionRefSha256'],
                    $providerRef
                ))
        ) {
            $eventName = 'subscription.activated';
            $target = self::state('active', 'active', $providerRef, $periodEnd);
        } elseif ($outcome === 'renewed'
            && $status === 'active'
            && self::sameProvider($current, $providerRef)
            && is_int($periodEnd)
            && $periodEnd > (int) $current['currentPeriodEndEpoch']
        ) {
            $eventName = 'subscription.renewed';
            $target = self::state('active', 'active', $providerRef, $periodEnd);
        } elseif ($outcome === 'past_due'
            && $status === 'active'
            && self::sameProvider($current, $providerRef)
            && is_int($periodEnd)
        ) {
            $eventName = 'subscription.past_due';
            $target = self::state('past_due', 'revoked', $providerRef, $periodEnd);
        } elseif ($outcome === 'canceled'
            && in_array($status, ['active', 'past_due'], true)
            && self::sameProvider($current, $providerRef)
            && ($periodEnd === null || is_int($periodEnd))
        ) {
            $eventName = 'subscription.canceled';
            $target = self::state('canceled', 'revoked', $providerRef, $periodEnd);
        } elseif ($outcome === 'expired'
            && $status === 'pending'
            && $providerRef === null
            && $periodEnd === null
        ) {
            $eventName = 'subscription.expired';
            $target = self::state('expired', 'inactive', null, null);
        }
        if ($target === null) {
            return self::invalid('subscription_transition_refused');
        }
        $transition = [
            'intentReference' => $current['intentReference'],
            'outcome' => $outcome,
            'eventName' => $eventName,
            'currentState' => [
                'subscriptionStatus' => $current['subscriptionStatus'],
                'entitlementStatus' => $current['entitlementStatus'],
                'providerSubscriptionRefSha256' =>
                    $current['providerSubscriptionRefSha256'],
                'currentPeriodEndEpoch' => $current['currentPeriodEndEpoch'],
            ],
            'targetState' => $target,
            'eventEvidenceSha256' => $event['eventEvidenceSha256'],
            'occurredAt' => $event['occurredAt'],
        ];
        return [
            'valid' => true,
            'transition' => $transition,
            'planSha256' => self::hash($transition),
            'errors' => [],
        ];
    }

    private static function current(array $value): bool
    {
        return array_keys($value) === [
            'intentReference', 'offerStateSha256', 'subscriptionStatus',
            'entitlementStatus', 'providerSubscriptionRefSha256',
            'currentPeriodEndEpoch',
        ]
            && self::intentReference($value['intentReference'] ?? null)
            && self::sha256($value['offerStateSha256'] ?? null)
            && in_array(
                $value['subscriptionStatus'] ?? null,
                ['pending', 'active', 'past_due', 'canceled', 'expired'],
                true
            )
            && in_array(
                $value['entitlementStatus'] ?? null,
                ['inactive', 'active', 'revoked'],
                true
            )
            && ($value['providerSubscriptionRefSha256'] === null
                || self::sha256($value['providerSubscriptionRefSha256']))
            && ($value['currentPeriodEndEpoch'] === null
                || self::timestamp($value['currentPeriodEndEpoch']));
    }

    private static function event(array $value): bool
    {
        return array_keys($value) === [
            'verification', 'replayStatus', 'intentReference',
            'offerStateSha256', 'outcome',
            'providerSubscriptionRefSha256', 'currentPeriodEndEpoch',
            'eventEvidenceSha256', 'occurredAt',
        ]
            && ($value['verification'] ?? null) === 'verified'
            && in_array(
                $value['replayStatus'] ?? null,
                ['unseen', 'replayed'],
                true
            )
            && self::intentReference($value['intentReference'] ?? null)
            && self::sha256($value['offerStateSha256'] ?? null)
            && in_array(
                $value['outcome'] ?? null,
                ['activated', 'renewed', 'past_due', 'canceled', 'expired'],
                true
            )
            && ($value['providerSubscriptionRefSha256'] === null
                || self::sha256($value['providerSubscriptionRefSha256']))
            && ($value['currentPeriodEndEpoch'] === null
                || self::timestamp($value['currentPeriodEndEpoch']))
            && self::sha256($value['eventEvidenceSha256'] ?? null)
            && self::timestamp($value['occurredAt'] ?? null);
    }

    private static function state(
        string $subscription,
        string $entitlement,
        ?string $providerRef,
        ?int $periodEnd
    ): array {
        return [
            'subscriptionStatus' => $subscription,
            'entitlementStatus' => $entitlement,
            'providerSubscriptionRefSha256' => $providerRef,
            'currentPeriodEndEpoch' => $periodEnd,
        ];
    }

    private static function sameProvider(array $current, mixed $provider): bool
    {
        return self::sha256($provider)
            && is_string($current['providerSubscriptionRefSha256'])
            && hash_equals(
                $current['providerSubscriptionRefSha256'],
                $provider
            );
    }

    private static function intentReference(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\Asint_[a-f0-9]{32}\z/D', $value) === 1;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function timestamp(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 4102444800;
    }

    private static function hash(array $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        ));
    }

    private static function invalid(string $error): array
    {
        return [
            'valid' => false,
            'transition' => null,
            'planSha256' => '',
            'errors' => [$error],
        ];
    }
}
