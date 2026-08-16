<?php

declare(strict_types=1);

require_once __DIR__ . '/PaymentEventTransition.php';

/**
 * Caller-transaction-owned Store Lite payment-event persistence.
 *
 * This class never begins, commits, or rolls back a transaction. It accepts
 * only the closed P3B order projection and normalized event, locks the current
 * Store Lite order, rechecks replay evidence, and performs at most one header
 * update plus one value-free history append.
 */
final class RED_CMS_Store_Lite_Payment_Event_Persistence
{
    public const TABLES = [
        'RED_Addon_StoreLite_Orders',
        'RED_Addon_StoreLite_Order_Status_History',
    ];

    public static function applyWithinTransaction(
        mysqli $connection,
        array $expectedOrder,
        array $event
    ): array {
        $proposed = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
            $expectedOrder,
            $event
        );
        if (empty($proposed['valid'])
            || !is_array($proposed['transition'] ?? null)
            || !self::validSha256($proposed['planSha256'] ?? null)
        ) {
            return self::result('transition_refused');
        }
        if (!self::transactionActive($connection)) {
            return self::result('invalid');
        }
        if (!self::tablesAvailable($connection)) {
            return self::result('storage_unavailable');
        }

        try {
            $locked = self::lockedOrder(
                $connection,
                (string) ($expectedOrder['orderId'] ?? '')
            );
            if (($locked['status'] ?? '') !== 'found') {
                return self::result(
                    ($locked['status'] ?? '') === 'not_found'
                        ? 'order_not_found'
                        : 'storage_unavailable'
                );
            }
            $orderRecordId = (int) ($locked['recordId'] ?? 0);
            $currentOrder = $locked['order'] ?? null;
            if ($orderRecordId < 1 || !is_array($currentOrder)) {
                return self::result('storage_unavailable');
            }

            $seen = self::seenEvidence(
                $connection,
                (string) ($event['eventEvidenceSha256'] ?? '')
            );
            if ($seen === false) {
                return self::result('storage_unavailable');
            }
            if (is_array($seen)) {
                return self::replayMatches(
                    $orderRecordId,
                    $currentOrder,
                    $expectedOrder,
                    $event,
                    $proposed,
                    $seen
                )
                    ? self::success(
                        'replayed',
                        $proposed,
                        $proposed['transition']['targetState'],
                        false
                    )
                    : self::result('replay_conflict');
            }

            if ($currentOrder !== $expectedOrder) {
                return self::result('stale_order');
            }
            $lockedPlan = RED_CMS_Store_Lite_Payment_Event_Transition::plan(
                $currentOrder,
                $event
            );
            if ($lockedPlan !== $proposed) {
                return self::result('transition_refused');
            }
            $transition = $lockedPlan['transition'];
            if (($transition['decision'] ?? null) === 'none') {
                return self::success(
                    'unchanged',
                    $lockedPlan,
                    $transition['targetState'],
                    false
                );
            }
            if (($transition['decision'] ?? null) !== 'transition'
                || !is_string($transition['eventName'] ?? null)
                || $transition['eventName'] === ''
                || !is_array($transition['currentState'] ?? null)
                || !is_array($transition['targetState'] ?? null)
            ) {
                return self::result('transition_refused');
            }
            if (!self::updateOrder(
                $connection,
                $orderRecordId,
                $currentOrder,
                $transition['targetState']
            ) || !self::appendHistory(
                $connection,
                $orderRecordId,
                $currentOrder['snapshotSha256'],
                $transition,
                $lockedPlan['planSha256']
            )) {
                return self::result('write_failed');
            }

            $storedOrder = self::lockedOrder(
                $connection,
                $currentOrder['orderId']
            );
            $storedEvidence = self::seenEvidence(
                $connection,
                $transition['eventEvidenceSha256']
            );
            if (($storedOrder['status'] ?? '') !== 'found'
                || (int) ($storedOrder['recordId'] ?? 0) !== $orderRecordId
                || !is_array($storedOrder['order'] ?? null)
                || !self::sameImmutableOrder(
                    $storedOrder['order'],
                    $currentOrder
                )
                || self::state($storedOrder['order'])
                    !== $transition['targetState']
                || !is_array($storedEvidence)
                || !self::replayMatches(
                    $orderRecordId,
                    $storedOrder['order'],
                    $currentOrder,
                    $event,
                    $lockedPlan,
                    $storedEvidence
                )
            ) {
                return self::result('postcondition_failed');
            }
            if (!self::transactionActive($connection)) {
                return self::result('transaction_lost');
            }
            return self::success(
                'applied',
                $lockedPlan,
                $transition['targetState'],
                true
            );
        } catch (Throwable $throwable) {
            return self::result('write_failed');
        }
    }

    private static function lockedOrder(
        mysqli $connection,
        string $orderId
    ): array {
        if (preg_match('/\Aord_[a-f0-9]{32}\z/D', $orderId) !== 1) {
            return ['status' => 'storage_unavailable'];
        }
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID, OrderID,
                    LOWER(HEX(SnapshotSHA256)) AS SnapshotSha256,
                    PaymentMethod, PaymentKind, Currency, TotalMinor,
                    OrderStatus, PaymentStatus, FulfillmentStatus
             FROM RED_Addon_StoreLite_Orders
             WHERE OrderID=? LIMIT 1 FOR UPDATE'
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$orderId])) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return ['status' => 'storage_unavailable'];
        }
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        if (!is_array($row)) {
            return ['status' => 'not_found'];
        }
        $projection = [
            'orderId' => $row['OrderID'] ?? null,
            'snapshotSha256' => $row['SnapshotSha256'] ?? null,
            'paymentMethod' => $row['PaymentMethod'] ?? null,
            'paymentKind' => $row['PaymentKind'] ?? null,
            'currency' => $row['Currency'] ?? null,
            'totalMinor' => (int) ($row['TotalMinor'] ?? -1),
            'orderStatus' => $row['OrderStatus'] ?? null,
            'paymentStatus' => $row['PaymentStatus'] ?? null,
            'fulfillmentStatus' => $row['FulfillmentStatus'] ?? null,
        ];
        return [
            'status' => 'found',
            'recordId' => (int) ($row['RecordID'] ?? 0),
            'order' => $projection,
        ];
    }

    private static function seenEvidence(
        mysqli $connection,
        string $eventEvidenceSha256
    ): array|false|null {
        if (!self::validSha256($eventEvidenceSha256)) {
            return false;
        }
        $statement = mysqli_prepare(
            $connection,
            'SELECT history.OrderRecordID, history.EventName,
                    history.OrderStatus, history.PaymentStatus,
                    history.FulfillmentStatus, history.ActorType,
                    history.ActorRecordID,
                    LOWER(HEX(history.SnapshotSHA256)) AS SnapshotSha256,
                    LOWER(HEX(history.EventEvidenceSHA256)) AS EventEvidenceSha256,
                    LOWER(HEX(history.TransitionSHA256)) AS TransitionSha256,
                    history.EventOccurredAt
             FROM RED_Addon_StoreLite_Order_Status_History AS history
             WHERE history.EventEvidenceSHA256=UNHEX(?)
             LIMIT 1 FOR UPDATE'
        );
        if (!$statement
            || !mysqli_stmt_execute($statement, [$eventEvidenceSha256])
        ) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return false;
        }
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return is_array($row) ? $row : null;
    }

    private static function replayMatches(
        int $orderRecordId,
        array $storedOrder,
        array $expectedOrder,
        array $event,
        array $plan,
        array $history
    ): bool {
        $transition = $plan['transition'] ?? null;
        $target = is_array($transition)
            ? ($transition['targetState'] ?? null)
            : null;
        return is_array($transition)
            && is_array($target)
            && self::sameImmutableOrder($storedOrder, $expectedOrder)
            && (int) ($history['OrderRecordID'] ?? 0) === $orderRecordId
            && ($history['EventName'] ?? null)
                === ($transition['eventName'] ?? null)
            && ($history['OrderStatus'] ?? null)
                === ($target['orderStatus'] ?? null)
            && ($history['PaymentStatus'] ?? null)
                === ($target['paymentStatus'] ?? null)
            && ($history['FulfillmentStatus'] ?? null)
                === ($target['fulfillmentStatus'] ?? null)
            && ($history['ActorType'] ?? null) === 'service'
            && (int) ($history['ActorRecordID'] ?? -1) === 0
            && ($history['SnapshotSha256'] ?? null)
                === ($expectedOrder['snapshotSha256'] ?? null)
            && ($history['EventEvidenceSha256'] ?? null)
                === ($event['eventEvidenceSha256'] ?? null)
            && ($history['TransitionSha256'] ?? null)
                === ($plan['planSha256'] ?? null)
            && (int) ($history['EventOccurredAt'] ?? 0)
                === ($event['occurredAt'] ?? null);
    }

    private static function updateOrder(
        mysqli $connection,
        int $orderRecordId,
        array $currentOrder,
        array $targetState
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'UPDATE RED_Addon_StoreLite_Orders
             SET OrderStatus=?, PaymentStatus=?, FulfillmentStatus=?
             WHERE RecordID=? AND OrderID=?
               AND SnapshotSHA256=UNHEX(?)
               AND PaymentMethod=? AND PaymentKind=?
               AND Currency=? AND TotalMinor=?
               AND OrderStatus=? AND PaymentStatus=? AND FulfillmentStatus=?'
        );
        if (!$statement) {
            return false;
        }
        $written = mysqli_stmt_execute($statement, [
            $targetState['orderStatus'],
            $targetState['paymentStatus'],
            $targetState['fulfillmentStatus'],
            $orderRecordId,
            $currentOrder['orderId'],
            $currentOrder['snapshotSha256'],
            $currentOrder['paymentMethod'],
            $currentOrder['paymentKind'],
            $currentOrder['currency'],
            $currentOrder['totalMinor'],
            $currentOrder['orderStatus'],
            $currentOrder['paymentStatus'],
            $currentOrder['fulfillmentStatus'],
        ]);
        $changed = $written ? mysqli_stmt_affected_rows($statement) : 0;
        mysqli_stmt_close($statement);
        return $changed === 1;
    }

    private static function appendHistory(
        mysqli $connection,
        int $orderRecordId,
        string $snapshotSha256,
        array $transition,
        string $planSha256
    ): bool {
        $target = $transition['targetState'];
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Order_Status_History
             (OrderRecordID, EventName, OrderStatus, PaymentStatus,
              FulfillmentStatus, ActorType, ActorRecordID, SnapshotSHA256,
              EventEvidenceSHA256, TransitionSHA256, EventOccurredAt)
             VALUES (?, ?, ?, ?, ?, \'service\', 0, UNHEX(?), UNHEX(?),
                     UNHEX(?), ?)'
        );
        if (!$statement) {
            return false;
        }
        $written = mysqli_stmt_execute($statement, [
            $orderRecordId,
            $transition['eventName'],
            $target['orderStatus'],
            $target['paymentStatus'],
            $target['fulfillmentStatus'],
            $snapshotSha256,
            $transition['eventEvidenceSha256'],
            $planSha256,
            $transition['occurredAt'],
        ]);
        mysqli_stmt_close($statement);
        return $written;
    }

    private static function sameImmutableOrder(
        array $left,
        array $right
    ): bool {
        foreach ([
            'orderId', 'snapshotSha256', 'paymentMethod', 'paymentKind',
            'currency', 'totalMinor',
        ] as $key) {
            if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private static function state(array $order): array
    {
        return [
            'orderStatus' => $order['orderStatus'] ?? null,
            'paymentStatus' => $order['paymentStatus'] ?? null,
            'fulfillmentStatus' => $order['fulfillmentStatus'] ?? null,
        ];
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            if (!mysqli_query(
                $connection,
                'SAVEPOINT redcms_store_lite_payment_event_guard'
            )) {
                return false;
            }
            return mysqli_query(
                $connection,
                'RELEASE SAVEPOINT redcms_store_lite_payment_event_guard'
            ) === true;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function tablesAvailable(mysqli $connection): bool
    {
        try {
            $quoted = [];
            foreach (self::TABLES as $table) {
                $quoted[] = "'" . mysqli_real_escape_string(
                    $connection,
                    $table
                ) . "'";
            }
            $query = mysqli_query(
                $connection,
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND ENGINE=\'InnoDB\'
                   AND TABLE_NAME IN (' . implode(',', $quoted) . ')'
            );
            $row = $query ? mysqli_fetch_row($query) : null;
            if ($query) {
                mysqli_free_result($query);
            }
            return (int) ($row[0] ?? 0) === count(self::TABLES);
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function validSha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function success(
        string $status,
        array $plan,
        array $state,
        bool $stateChanged
    ): array {
        $transition = $plan['transition'];
        return [
            'status' => $status,
            'orderId' => $transition['orderId'],
            'eventEvidenceSha256' => $transition['eventEvidenceSha256'],
            'planSha256' => $plan['planSha256'],
            'stateChanged' => $stateChanged,
            'fulfillmentBlocked' =>
                ($state['fulfillmentStatus'] ?? null) === 'blocked',
            'orderStatus' => $state['orderStatus'],
            'paymentStatus' => $state['paymentStatus'],
            'fulfillmentStatus' => $state['fulfillmentStatus'],
        ];
    }

    private static function result(string $status): array
    {
        return [
            'status' => $status,
            'orderId' => '',
            'eventEvidenceSha256' => '',
            'planSha256' => '',
            'stateChanged' => false,
            'fulfillmentBlocked' => false,
            'orderStatus' => '',
            'paymentStatus' => '',
            'fulfillmentStatus' => '',
        ];
    }
}
