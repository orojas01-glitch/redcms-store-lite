<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionLifecyclePersistence.php';

/** Enabled-package service for subscription checkout and verified events. */
final class RED_CMS_Store_Lite_Subscription_Lifecycle_Service
{
    public const SERVICE = 'commerce.subscriptions';
    public const PREPARE = 'subscription.checkout.prepare';
    public const LOAD = 'subscription.checkout.load';
    public const LOAD_CURRENT = 'subscription.lifecycle.load';
    public const APPLY = 'subscription.event.apply';

    public static function handle(
        RED_Addon_Service_Request $request
    ): RED_Addon_Service_Result {
        if ($request->service() !== self::SERVICE
            || !in_array(
                $request->operation(),
                [self::LOAD, self::LOAD_CURRENT, self::PREPARE, self::APPLY],
                true
            )
        ) {
            return RED_Addon_Service_Result::failure(
                'subscription_operation_unavailable'
            );
        }
        $input = $request->input();
        if ($request->operation() === self::LOAD) {
            return self::load($input);
        }
        if ($request->operation() === self::LOAD_CURRENT) {
            return self::loadCurrent($input);
        }
        $expectedKeys = $request->operation() === self::PREPARE
            ? ['intent', 'checkout']
            : ['current', 'event'];
        if (array_keys($input) !== $expectedKeys
            || !is_array($input[$expectedKeys[0]] ?? null)
            || !is_array($input[$expectedKeys[1]] ?? null)
        ) {
            return RED_Addon_Service_Result::failure('subscription_invalid');
        }

        $connection = null;
        try {
            $connection = self::runtimeConnection();
            if (!mysqli_begin_transaction($connection)) {
                throw new RuntimeException('subscription transaction unavailable');
            }
            $result = $request->operation() === self::PREPARE
                ? RED_CMS_Store_Lite_Subscription_Lifecycle_Persistence::
                    prepareWithinTransaction(
                        $connection,
                        $input['intent'],
                        $input['checkout']
                    )
                : RED_CMS_Store_Lite_Subscription_Lifecycle_Persistence::
                    applyEventWithinTransaction(
                        $connection,
                        $input['current'],
                        $input['event']
                    );
            if (!in_array(
                $result['status'] ?? '',
                ['prepared', 'applied', 'replayed'],
                true
            )) {
                mysqli_rollback($connection);
                return RED_Addon_Service_Result::failure(
                    self::error((string) ($result['status'] ?? ''))
                );
            }
            if (!mysqli_commit($connection)) {
                throw new RuntimeException('subscription commit unavailable');
            }
            $current = $result['current'];
            return RED_Addon_Service_Result::success([
                'status' => $result['status'],
                'intentReference' => $current['intentReference'],
                'offerStateSha256' => $current['offerStateSha256'],
                'subscriptionStatus' => $current['subscriptionStatus'],
                'entitlementStatus' => $current['entitlementStatus'],
                'providerSubscriptionRefSha256' =>
                    $current['providerSubscriptionRefSha256'],
                'currentPeriodEndEpoch' => $current['currentPeriodEndEpoch'],
                'checkoutSessionRefSha256' =>
                    $result['checkoutSessionRefSha256'],
                'lastEventEvidenceSha256' =>
                    $result['lastEventEvidenceSha256'],
            ]);
        } catch (Throwable $throwable) {
            if ($connection instanceof mysqli) {
                try { mysqli_rollback($connection); } catch (Throwable $ignored) {}
            }
            return RED_Addon_Service_Result::failure(
                'subscription_storage_unavailable'
            );
        } finally {
            if ($connection instanceof mysqli) mysqli_close($connection);
        }
    }

    private static function load(array $input): RED_Addon_Service_Result
    {
        if (array_keys($input) !== ['subjectRecordId', 'offerId']
            || !is_int($input['subjectRecordId'] ?? null)
            || $input['subjectRecordId'] < 1
            || !is_string($input['offerId'] ?? null)
            || preg_match(
                '/\A[a-z0-9][a-z0-9._-]{0,63}\z/D',
                $input['offerId']
            ) !== 1
        ) {
            return RED_Addon_Service_Result::failure('subscription_invalid');
        }
        $connection = null;
        try {
            $connection = self::runtimeConnection();
            $statement = mysqli_prepare(
                $connection,
                'SELECT Currency FROM RED_Addon_StoreLite_Subscription_Offers
                 WHERE OfferID=? LIMIT 1'
            );
            if (!$statement
                || !mysqli_stmt_execute($statement, [$input['offerId']])
            ) {
                if ($statement) mysqli_stmt_close($statement);
                throw new RuntimeException('subscription offer unavailable');
            }
            $query = mysqli_stmt_get_result($statement);
            $row = $query ? mysqli_fetch_assoc($query) : null;
            if ($query) mysqli_free_result($query);
            mysqli_stmt_close($statement);
            $currency = is_array($row) ? ($row['Currency'] ?? null) : null;
            if (!is_string($currency)) {
                return RED_Addon_Service_Result::failure(
                    'subscription_not_found'
                );
            }
            $intent = RED_CMS_Store_Lite_Subscription_Intent_Persistence::read(
                $connection,
                $input['subjectRecordId'],
                $input['offerId'],
                $currency
            );
            $offer = RED_CMS_Store_Lite_Subscription_Offer_Persistence::read(
                $connection,
                $input['offerId'],
                $currency
            );
            if (($intent['status'] ?? '') !== 'requested'
                || ($offer['status'] ?? '') !== 'found'
                || !is_array($offer['offer'] ?? null)
                || ($offer['offer']['state'] ?? '') !== 'published'
                || ($offer['offer']['availability'] ?? '') !== 'available'
            ) {
                return RED_Addon_Service_Result::failure(
                    'subscription_not_found'
                );
            }
            return RED_Addon_Service_Result::success([
                'intent' => [
                    'subjectRecordId' => $input['subjectRecordId'],
                    'offerId' => $input['offerId'],
                    'intentStateSha256' => $intent['intentStateSha256'],
                    'offerStateSha256' => $intent['offerStateSha256'],
                    'status' => 'requested',
                ],
                'offer' => $offer['offer'],
            ]);
        } catch (Throwable $throwable) {
            return RED_Addon_Service_Result::failure(
                'subscription_storage_unavailable'
            );
        } finally {
            if ($connection instanceof mysqli) mysqli_close($connection);
        }
    }

    private static function loadCurrent(
        array $input
    ): RED_Addon_Service_Result {
        if (array_keys($input) !== ['intentReference']
            || !is_string($input['intentReference'] ?? null)
            || preg_match(
                '/\Asint_[a-f0-9]{32}\z/D',
                $input['intentReference']
            ) !== 1
        ) {
            return RED_Addon_Service_Result::failure(
                'subscription_invalid'
            );
        }
        $connection = null;
        try {
            $connection = self::runtimeConnection();
            $loaded = RED_CMS_Store_Lite_Subscription_Lifecycle_Persistence::
                read($connection, $input['intentReference']);
            $current = $loaded['current'] ?? null;
            if (($loaded['status'] ?? '') !== 'found'
                || !is_array($current)
            ) {
                return RED_Addon_Service_Result::failure(
                    'subscription_not_found'
                );
            }
            return RED_Addon_Service_Result::success([
                'status' => 'found',
                'intentReference' => $current['intentReference'],
                'offerStateSha256' => $current['offerStateSha256'],
                'subscriptionStatus' => $current['subscriptionStatus'],
                'entitlementStatus' => $current['entitlementStatus'],
                'providerSubscriptionRefSha256' =>
                    $current['providerSubscriptionRefSha256'],
                'currentPeriodEndEpoch' =>
                    $current['currentPeriodEndEpoch'],
                'checkoutSessionRefSha256' =>
                    $loaded['checkoutSessionRefSha256'],
                'lastEventEvidenceSha256' =>
                    $loaded['lastEventEvidenceSha256'],
            ]);
        } catch (Throwable $throwable) {
            return RED_Addon_Service_Result::failure(
                'subscription_storage_unavailable'
            );
        } finally {
            if ($connection instanceof mysqli) mysqli_close($connection);
        }
    }

    private static function runtimeConnection(): mysqli
    {
        foreach (['DBHOST', 'DBUSER', 'DBPASS', 'DBNAME'] as $constant) {
            if (!defined($constant)) {
                throw new RuntimeException('subscription database unavailable');
            }
        }
        $hostPort = (string) constant('DBHOST');
        $host = $hostPort;
        $port = 3306;
        if (preg_match('/\A([^:]+):([0-9]{1,5})\z/D', $hostPort, $parts) === 1) {
            $host = $parts[1];
            $port = (int) $parts[2];
        }
        $connection = mysqli_init();
        if (!$connection instanceof mysqli) {
            throw new RuntimeException('subscription database unavailable');
        }
        mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        mysqli_real_connect(
            $connection,
            $host,
            (string) constant('DBUSER'),
            (string) constant('DBPASS'),
            (string) constant('DBNAME'),
            $port
        );
        if (!mysqli_set_charset($connection, 'utf8mb4')) {
            mysqli_close($connection);
            throw new RuntimeException('subscription database unavailable');
        }
        return $connection;
    }

    private static function error(string $status): string
    {
        return match ($status) {
            'intent_unavailable', 'subscription_not_found' =>
                'subscription_not_found',
            'checkout_conflict', 'state_conflict', 'replay_conflict' =>
                'subscription_state_conflict',
            'transition_refused', 'invalid' => 'subscription_refused',
            default => 'subscription_storage_unavailable',
        };
    }
}
