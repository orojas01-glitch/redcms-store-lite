<?php

declare(strict_types=1);

/**
 * Pure Store Lite payment-event transition decision contract.
 *
 * The caller supplies one current server-derived order projection and one
 * already-verified provider-neutral event. This class performs no database,
 * request, runtime, filesystem, secret, provider, or network work.
 */
final class RED_CMS_Store_Lite_Payment_Event_Transition
{
    private const HOSTED_PAYMENT_METHODS = [
        'stripe_checkout',
        'paypal',
        'nequi',
    ];

    private const OUTCOMES = [
        'paid',
        'refund_confirmed',
        'reversal_reported',
        'failed',
        'cancelled',
        'expired',
    ];

    private const NO_TRANSITION_OUTCOMES = [
        'failed',
        'cancelled',
        'expired',
    ];

    public static function plan(array $order, array $event): array
    {
        $normalizedOrder = self::order($order);
        if ($normalizedOrder === null) {
            return self::invalid(['order_invalid']);
        }
        $normalizedEvent = self::event($event);
        if ($normalizedEvent === null) {
            return self::invalid(['event_invalid']);
        }
        if ($normalizedEvent['replayStatus'] !== 'unseen') {
            return self::invalid(['event_replayed']);
        }
        if (!hash_equals(
            $normalizedOrder['orderId'],
            $normalizedEvent['orderId']
        ) || !hash_equals(
            $normalizedOrder['snapshotSha256'],
            $normalizedEvent['orderSnapshotSha256']
        )) {
            return self::invalid(['order_identity_mismatch']);
        }
        if (!hash_equals(
            $normalizedOrder['paymentMethod'],
            $normalizedEvent['paymentMethod']
        )) {
            return self::invalid(['payment_method_mismatch']);
        }
        if ($normalizedOrder['currency'] !== $normalizedEvent['currency']) {
            return self::invalid(['currency_mismatch']);
        }
        if ($normalizedOrder['totalMinor'] !== $normalizedEvent['amountMinor']) {
            return self::invalid(['amount_mismatch']);
        }

        $outcome = $normalizedEvent['outcome'];
        $currentState = self::state($normalizedOrder);
        $targetState = null;
        $eventName = null;
        $decision = 'transition';
        $fulfillmentBlocked = false;

        if ($outcome === 'paid') {
            if ($currentState !== self::pendingState()) {
                return self::invalid(['transition_not_allowed']);
            }
            $eventName = 'payment.paid';
            $targetState = [
                'orderStatus' => 'paid',
                'paymentStatus' => 'paid',
                'fulfillmentStatus' => 'unfulfilled',
            ];
        } elseif ($outcome === 'refund_confirmed') {
            if ($currentState !== self::paidState()) {
                return self::invalid(['transition_not_allowed']);
            }
            $eventName = 'payment.refund_confirmed';
            $targetState = [
                'orderStatus' => 'refunded',
                'paymentStatus' => 'refunded',
                'fulfillmentStatus' => 'unfulfilled',
            ];
        } elseif ($outcome === 'reversal_reported') {
            if ($currentState !== self::paidState()) {
                return self::invalid(['transition_not_allowed']);
            }
            $eventName = 'payment.reversal_reported';
            $targetState = [
                'orderStatus' => 'paid',
                'paymentStatus' => 'reversal_reported',
                'fulfillmentStatus' => 'blocked',
            ];
            $fulfillmentBlocked = true;
        } elseif (in_array($outcome, self::NO_TRANSITION_OUTCOMES, true)) {
            if ($currentState !== self::pendingState()) {
                return self::invalid(['transition_not_allowed']);
            }
            $decision = 'none';
            $targetState = $currentState;
        }

        if (!is_array($targetState)) {
            return self::invalid(['transition_not_allowed']);
        }
        $transition = [
            'decision' => $decision,
            'orderId' => $normalizedOrder['orderId'],
            'outcome' => $outcome,
            'eventName' => $eventName,
            'currentState' => $currentState,
            'targetState' => $targetState,
            'eventEvidenceSha256' =>
                $normalizedEvent['eventEvidenceSha256'],
            'occurredAt' => $normalizedEvent['occurredAt'],
            'stateChanged' => $decision === 'transition',
            'fulfillmentBlocked' => $fulfillmentBlocked,
        ];
        try {
            $encoded = json_encode(
                $transition,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $throwable) {
            return self::invalid(['transition_encoding_failed']);
        }
        return [
            'valid' => true,
            'transition' => $transition,
            'planSha256' => hash('sha256', $encoded),
            'errors' => [],
        ];
    }

    private static function order(array $order): ?array
    {
        if (array_keys($order) !== [
            'orderId', 'snapshotSha256', 'paymentMethod', 'paymentKind',
            'currency', 'totalMinor', 'orderStatus', 'paymentStatus',
            'fulfillmentStatus',
        ]
            || !self::orderId($order['orderId'] ?? null)
            || !self::sha256($order['snapshotSha256'] ?? null)
            || !is_string($order['paymentMethod'] ?? null)
            || !in_array(
                $order['paymentMethod'],
                self::HOSTED_PAYMENT_METHODS,
                true
            )
            || ($order['paymentKind'] ?? null) !== 'hosted'
            || !self::currency($order['currency'] ?? null)
            || !is_int($order['totalMinor'] ?? null)
            || $order['totalMinor'] < 0
            || $order['totalMinor'] > 2400999997599
            || !is_string($order['orderStatus'] ?? null)
            || !is_string($order['paymentStatus'] ?? null)
            || !is_string($order['fulfillmentStatus'] ?? null)
        ) {
            return null;
        }
        return $order;
    }

    private static function event(array $event): ?array
    {
        if (array_keys($event) !== [
            'verification', 'replayStatus', 'outcome', 'orderId',
            'orderSnapshotSha256', 'paymentMethod', 'amountMinor', 'currency',
            'eventEvidenceSha256', 'occurredAt',
        ]
            || ($event['verification'] ?? null) !== 'verified'
            || !is_string($event['replayStatus'] ?? null)
            || !in_array($event['replayStatus'], ['unseen', 'replayed'], true)
            || !is_string($event['outcome'] ?? null)
            || !in_array($event['outcome'], self::OUTCOMES, true)
            || !self::orderId($event['orderId'] ?? null)
            || !self::sha256($event['orderSnapshotSha256'] ?? null)
            || !is_string($event['paymentMethod'] ?? null)
            || !in_array(
                $event['paymentMethod'],
                self::HOSTED_PAYMENT_METHODS,
                true
            )
            || !is_int($event['amountMinor'] ?? null)
            || $event['amountMinor'] < 0
            || $event['amountMinor'] > 2400999997599
            || !self::currency($event['currency'] ?? null)
            || !self::sha256($event['eventEvidenceSha256'] ?? null)
            || !is_int($event['occurredAt'] ?? null)
            || $event['occurredAt'] < 1
            || $event['occurredAt'] > 4102444800
        ) {
            return null;
        }
        return $event;
    }

    private static function state(array $order): array
    {
        return [
            'orderStatus' => $order['orderStatus'],
            'paymentStatus' => $order['paymentStatus'],
            'fulfillmentStatus' => $order['fulfillmentStatus'],
        ];
    }

    private static function pendingState(): array
    {
        return [
            'orderStatus' => 'pending',
            'paymentStatus' => 'pending',
            'fulfillmentStatus' => 'unfulfilled',
        ];
    }

    private static function paidState(): array
    {
        return [
            'orderStatus' => 'paid',
            'paymentStatus' => 'paid',
            'fulfillmentStatus' => 'unfulfilled',
        ];
    }

    private static function orderId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\Aord_[a-f0-9]{32}\z/D', $value) === 1;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function currency(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }

    private static function invalid(array $errors): array
    {
        return [
            'valid' => false,
            'transition' => null,
            'planSha256' => '',
            'errors' => array_values(array_unique($errors)),
        ];
    }
}
