<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionIntentPersistence.php';
require_once __DIR__ . '/SubscriptionLifecycleTransition.php';

/** Caller-transaction-owned subscription and entitlement persistence. */
final class RED_CMS_Store_Lite_Subscription_Lifecycle_Persistence
{
    public const TABLE = 'RED_Addon_StoreLite_Subscriptions';
    public const HISTORY = 'RED_Addon_StoreLite_Subscription_Status_History';
    public const TABLES = [
        'RED_Addon_StoreLite_Products',
        'RED_Addon_StoreLite_Product_Variants',
        'RED_Addon_StoreLite_Subscription_Offers',
        'RED_Addon_StoreLite_Subscription_Intents',
        self::TABLE,
        self::HISTORY,
    ];

    public static function prepareWithinTransaction(
        mysqli $connection,
        array $intent,
        array $checkout
    ): array {
        if (!self::prepareInput($intent, $checkout)
            || !self::transactionActive($connection)
            || !self::tablesAvailable($connection)
        ) {
            return self::result('invalid');
        }
        try {
            $currency = self::offerCurrency(
                $connection,
                $intent['offerId']
            );
            if ($currency === null) {
                return self::result('intent_unavailable');
            }
            $storedIntent =
                RED_CMS_Store_Lite_Subscription_Intent_Persistence::read(
                    $connection,
                    $intent['subjectRecordId'],
                    $intent['offerId'],
                    $currency
                );
            if (empty($storedIntent['loaded'])
                || ($storedIntent['status'] ?? '') !== 'requested'
                || ($storedIntent['intentRecordId'] ?? 0) < 1
                || !hash_equals(
                    $intent['intentStateSha256'],
                    (string) $storedIntent['intentStateSha256']
                )
                || !hash_equals(
                    $intent['offerStateSha256'],
                    (string) $storedIntent['offerStateSha256']
                )
            ) {
                return self::result('intent_unavailable');
            }
            $intentRecordId = (int) $storedIntent['intentRecordId'];
            $offerRecordId = (int) $storedIntent['offerRecordId'];
            $existing = self::lockedByIntentRecord(
                $connection,
                $intentRecordId
            );
            if (($existing['status'] ?? '') === 'found') {
                $same = ($existing['intentReference'] ?? '')
                        === $intent['intentReference']
                    && ($existing['checkoutSessionRefSha256'] ?? '')
                        === $checkout['checkoutSessionRefSha256']
                    && ($existing['offerStateSha256'] ?? '')
                        === $intent['offerStateSha256']
                    && ($existing['lastEventEvidenceSha256'] ?? '')
                        === $checkout['responseEvidenceSha256'];
                return $same
                    ? self::success('replayed', $existing)
                    : self::result('checkout_conflict');
            }
            if (($existing['status'] ?? '') !== 'not_found') {
                return self::result('storage_unavailable');
            }
            $statement = mysqli_prepare(
                $connection,
                'INSERT INTO `' . self::TABLE . '` (
                    IntentRecordID, IntentReference, OfferRecordID,
                    SubjectRecordID, OfferStateSHA256, Provider,
                    CheckoutSessionRefSHA256, ProviderSubscriptionRefSHA256,
                    SubscriptionStatus, EntitlementStatus,
                    CurrentPeriodEndEpoch, LastEventEvidenceSHA256,
                    CreatedAtEpoch, CheckoutExpiresAtEpoch
                 ) VALUES (?, ?, ?, ?, UNHEX(?), \'stripe_checkout\',
                    UNHEX(?), NULL, \'pending\', \'inactive\', NULL,
                    UNHEX(?), ?, ?)'
            );
            if (!$statement || !mysqli_stmt_execute($statement, [
                $intentRecordId,
                $intent['intentReference'],
                $offerRecordId,
                $intent['subjectRecordId'],
                $intent['offerStateSha256'],
                $checkout['checkoutSessionRefSha256'],
                $checkout['responseEvidenceSha256'],
                $checkout['occurredAt'],
                $checkout['expiresAtEpoch'],
            ])) {
                if ($statement) mysqli_stmt_close($statement);
                return self::result('write_failed');
            }
            $recordId = (int) mysqli_insert_id($connection);
            mysqli_stmt_close($statement);
            $transitionSha256 = self::hash([
                'intentReference' => $intent['intentReference'],
                'eventName' => 'checkout.prepared',
                'subscriptionStatus' => 'pending',
                'entitlementStatus' => 'inactive',
                'responseEvidenceSha256' =>
                    $checkout['responseEvidenceSha256'],
                'occurredAt' => $checkout['occurredAt'],
            ]);
            if (!self::appendHistory(
                $connection,
                $recordId,
                'checkout.prepared',
                'pending',
                'inactive',
                null,
                $checkout['responseEvidenceSha256'],
                $transitionSha256,
                null,
                $checkout['occurredAt']
            )) {
                return self::result('write_failed');
            }
            $stored = self::lockedByReference(
                $connection,
                $intent['intentReference']
            );
            return ($stored['status'] ?? '') === 'found'
                && ($stored['recordId'] ?? 0) === $recordId
                    ? self::success('prepared', $stored)
                    : self::result('postcondition_failed');
        } catch (Throwable $throwable) {
            return self::result('write_failed');
        }
    }

    public static function applyEventWithinTransaction(
        mysqli $connection,
        array $expected,
        array $event
    ): array {
        $planned = RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
            $expected,
            $event
        );
        if (($planned['valid'] ?? null) !== true
            || !self::sha256($planned['planSha256'] ?? null)
            || !self::transactionActive($connection)
            || !self::tablesAvailable($connection)
        ) {
            return self::result('transition_refused');
        }
        try {
            $locked = self::lockedByReference(
                $connection,
                $expected['intentReference'] ?? ''
            );
            if (($locked['status'] ?? '') !== 'found') {
                return self::result(
                    ($locked['status'] ?? '') === 'not_found'
                        ? 'subscription_not_found'
                        : 'storage_unavailable'
                );
            }
            $seen = self::seenEvidence(
                $connection,
                $event['eventEvidenceSha256'] ?? ''
            );
            if ($seen === false) {
                return self::result('storage_unavailable');
            }
            if (is_array($seen)) {
                return (int) ($seen['SubscriptionRecordID'] ?? 0)
                    === (int) $locked['recordId']
                        ? self::success('replayed', $locked)
                        : self::result('replay_conflict');
            }
            if ($locked['current'] !== $expected) {
                return self::result('state_conflict');
            }
            $lockedPlan =
                RED_CMS_Store_Lite_Subscription_Lifecycle_Transition::plan(
                    $locked['current'],
                    $event
                );
            if ($lockedPlan !== $planned) {
                return self::result('transition_refused');
            }
            $target = $planned['transition']['targetState'];
            $statement = mysqli_prepare(
                $connection,
                'UPDATE `' . self::TABLE . '` SET
                    ProviderSubscriptionRefSHA256=?, SubscriptionStatus=?,
                    EntitlementStatus=?, CurrentPeriodEndEpoch=?,
                    LastEventEvidenceSHA256=UNHEX(?)
                 WHERE RecordID=?'
            );
            $providerBinary = $target['providerSubscriptionRefSha256'] === null
                ? null
                : hex2bin($target['providerSubscriptionRefSha256']);
            if (!$statement || !mysqli_stmt_execute($statement, [
                $providerBinary,
                $target['subscriptionStatus'],
                $target['entitlementStatus'],
                $target['currentPeriodEndEpoch'],
                $event['eventEvidenceSha256'],
                $locked['recordId'],
            ])) {
                if ($statement) mysqli_stmt_close($statement);
                return self::result('write_failed');
            }
            mysqli_stmt_close($statement);
            if (!self::appendHistory(
                $connection,
                $locked['recordId'],
                $planned['transition']['eventName'],
                $target['subscriptionStatus'],
                $target['entitlementStatus'],
                $target['providerSubscriptionRefSha256'],
                $event['eventEvidenceSha256'],
                $planned['planSha256'],
                $target['currentPeriodEndEpoch'],
                $event['occurredAt']
            )) {
                return self::result('write_failed');
            }
            $stored = self::lockedByReference(
                $connection,
                $expected['intentReference']
            );
            return ($stored['status'] ?? '') === 'found'
                && $stored['current'] === array_merge(
                    ['intentReference' => $expected['intentReference'],
                     'offerStateSha256' => $expected['offerStateSha256']],
                    $target
                )
                    ? self::success('applied', $stored)
                    : self::result('postcondition_failed');
        } catch (Throwable $throwable) {
            return self::result('write_failed');
        }
    }

    public static function read(
        mysqli $connection,
        string $intentReference
    ): array {
        if (!self::intentReference($intentReference)
            || !self::tablesAvailable($connection)
        ) {
            return self::result('invalid');
        }
        $stored = self::lockedByReference($connection, $intentReference, false);
        return ($stored['status'] ?? '') === 'found'
            ? self::success('found', $stored)
            : self::result($stored['status'] ?? 'storage_unavailable');
    }

    private static function prepareInput(array $intent, array $checkout): bool
    {
        return array_keys($intent) === [
            'subjectRecordId', 'offerId', 'intentReference',
            'intentStateSha256', 'offerStateSha256',
        ]
            && is_int($intent['subjectRecordId'] ?? null)
            && $intent['subjectRecordId'] > 0
            && self::identifier($intent['offerId'] ?? null)
            && self::intentReference($intent['intentReference'] ?? null)
            && self::sha256($intent['intentStateSha256'] ?? null)
            && self::sha256($intent['offerStateSha256'] ?? null)
            && array_keys($checkout) === [
                'checkoutSessionRefSha256', 'responseEvidenceSha256',
                'expiresAtEpoch', 'occurredAt',
            ]
            && self::sha256($checkout['checkoutSessionRefSha256'] ?? null)
            && self::sha256($checkout['responseEvidenceSha256'] ?? null)
            && self::timestamp($checkout['occurredAt'] ?? null)
            && ($checkout['expiresAtEpoch'] ?? null)
                === $checkout['occurredAt'] + 1800;
    }

    private static function offerCurrency(
        mysqli $connection,
        string $offerId
    ): ?string {
        $statement = mysqli_prepare(
            $connection,
            'SELECT Currency FROM RED_Addon_StoreLite_Subscription_Offers
             WHERE OfferID=? LIMIT 1'
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$offerId])) {
            if ($statement) mysqli_stmt_close($statement);
            return null;
        }
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) mysqli_free_result($query);
        mysqli_stmt_close($statement);
        $currency = is_array($row) ? ($row['Currency'] ?? null) : null;
        return is_string($currency)
            && preg_match('/\A[A-Z]{3}\z/D', $currency) === 1
                ? $currency
                : null;
    }

    private static function lockedByIntentRecord(
        mysqli $connection,
        int $intentRecordId
    ): array {
        return self::locked($connection, 'IntentRecordID', $intentRecordId, true);
    }

    private static function lockedByReference(
        mysqli $connection,
        string $reference,
        bool $forUpdate = true
    ): array {
        if (!self::intentReference($reference)) {
            return ['status' => 'storage_unavailable'];
        }
        return self::locked($connection, 'IntentReference', $reference, $forUpdate);
    }

    private static function locked(
        mysqli $connection,
        string $column,
        int|string $value,
        bool $forUpdate
    ): array {
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID, IntentRecordID, IntentReference,
                    OfferRecordID, SubjectRecordID,
                    LOWER(HEX(OfferStateSHA256)) AS OfferStateSHA256,
                    LOWER(HEX(CheckoutSessionRefSHA256)) AS CheckoutSessionRefSHA256,
                    LOWER(HEX(ProviderSubscriptionRefSHA256)) AS ProviderSubscriptionRefSHA256,
                    SubscriptionStatus, EntitlementStatus,
                    CurrentPeriodEndEpoch,
                    LOWER(HEX(LastEventEvidenceSHA256)) AS LastEventEvidenceSHA256,
                    CreatedAtEpoch, CheckoutExpiresAtEpoch
             FROM `' . self::TABLE . '` WHERE `' . $column . '`=? LIMIT 1'
                . ($forUpdate ? ' FOR UPDATE' : '')
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$value])) {
            if ($statement) mysqli_stmt_close($statement);
            return ['status' => 'storage_unavailable'];
        }
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) mysqli_free_result($query);
        mysqli_stmt_close($statement);
        if (!is_array($row)) return ['status' => 'not_found'];
        $provider = $row['ProviderSubscriptionRefSHA256'] ?: null;
        $period = $row['CurrentPeriodEndEpoch'] === null
            ? null : (int) $row['CurrentPeriodEndEpoch'];
        return [
            'status' => 'found',
            'recordId' => (int) $row['RecordID'],
            'intentRecordId' => (int) $row['IntentRecordID'],
            'offerRecordId' => (int) $row['OfferRecordID'],
            'subjectRecordId' => (int) $row['SubjectRecordID'],
            'intentReference' => (string) $row['IntentReference'],
            'offerStateSha256' => (string) $row['OfferStateSHA256'],
            'checkoutSessionRefSha256' =>
                (string) $row['CheckoutSessionRefSHA256'],
            'lastEventEvidenceSha256' =>
                (string) $row['LastEventEvidenceSHA256'],
            'current' => [
                'intentReference' => (string) $row['IntentReference'],
                'offerStateSha256' => (string) $row['OfferStateSHA256'],
                'subscriptionStatus' => (string) $row['SubscriptionStatus'],
                'entitlementStatus' => (string) $row['EntitlementStatus'],
                'providerSubscriptionRefSha256' => $provider,
                'currentPeriodEndEpoch' => $period,
            ],
        ];
    }

    private static function seenEvidence(
        mysqli $connection,
        string $evidence
    ): array|false|null {
        if (!self::sha256($evidence)) return false;
        $statement = mysqli_prepare(
            $connection,
            'SELECT SubscriptionRecordID, EventName
             FROM `' . self::HISTORY . '`
             WHERE EventEvidenceSHA256=UNHEX(?) LIMIT 1 FOR UPDATE'
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$evidence])) {
            if ($statement) mysqli_stmt_close($statement);
            return false;
        }
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) mysqli_free_result($query);
        mysqli_stmt_close($statement);
        return is_array($row) ? $row : null;
    }

    private static function appendHistory(
        mysqli $connection,
        int $recordId,
        string $eventName,
        string $subscriptionStatus,
        string $entitlementStatus,
        ?string $providerRef,
        string $evidence,
        string $transition,
        ?int $periodEnd,
        int $occurredAt
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO `' . self::HISTORY . '` (
                SubscriptionRecordID, EventName, SubscriptionStatus,
                EntitlementStatus, ProviderSubscriptionRefSHA256,
                EventEvidenceSHA256, TransitionSHA256,
                CurrentPeriodEndEpoch, OccurredAtEpoch
             ) VALUES (?, ?, ?, ?, ?, UNHEX(?), UNHEX(?), ?, ?)'
        );
        $providerBinary = $providerRef === null ? null : hex2bin($providerRef);
        if (!$statement || !mysqli_stmt_execute($statement, [
            $recordId, $eventName, $subscriptionStatus, $entitlementStatus,
            $providerBinary, $evidence, $transition, $periodEnd, $occurredAt,
        ])) {
            if ($statement) mysqli_stmt_close($statement);
            return false;
        }
        mysqli_stmt_close($statement);
        return true;
    }

    private static function tablesAvailable(mysqli $connection): bool
    {
        $query = mysqli_query(
            $connection,
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB'
               AND TABLE_NAME IN (
                 'RED_Addon_StoreLite_Products',
                 'RED_Addon_StoreLite_Product_Variants',
                 'RED_Addon_StoreLite_Subscription_Offers',
                 'RED_Addon_StoreLite_Subscription_Intents',
                 '" . self::TABLE . "', '" . self::HISTORY . "')"
        );
        $row = $query ? mysqli_fetch_row($query) : null;
        if ($query) mysqli_free_result($query);
        return is_array($row) && (int) ($row[0] ?? 0) === 6;
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            return mysqli_query($connection, 'SAVEPOINT sl_subscription_lifecycle')
                && mysqli_query($connection, 'RELEASE SAVEPOINT sl_subscription_lifecycle');
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function success(string $status, array $stored): array
    {
        return [
            'status' => $status,
            'recordId' => (int) ($stored['recordId'] ?? 0),
            'current' => $stored['current'] ?? null,
            'checkoutSessionRefSha256' =>
                (string) ($stored['checkoutSessionRefSha256'] ?? ''),
            'lastEventEvidenceSha256' =>
                (string) ($stored['lastEventEvidenceSha256'] ?? ''),
        ];
    }

    private static function result(string $status): array
    {
        return [
            'status' => $status,
            'recordId' => 0,
            'current' => null,
            'checkoutSessionRefSha256' => '',
            'lastEventEvidenceSha256' => '',
        ];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $value) === 1;
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
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        ));
    }
}
