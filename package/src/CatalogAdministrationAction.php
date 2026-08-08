<?php

declare(strict_types=1);

require_once __DIR__ . '/CatalogAdministration.php';

/**
 * Internal reauthorizing execution of one preflighted product mutation.
 *
 * This class reads no request globals and exposes no route. A later core-owned
 * endpoint must authenticate the administrator and validate CSRF before it may
 * call this runner with the exact read-only preflight evidence.
 */
final class RED_CMS_Store_Lite_Catalog_Administration_Action
{
    public static function executeCreate(
        mysqli $connection,
        int $actorRecordId,
        array $input,
        string $installationCurrency,
        string $expectedPlanSha256
    ): array {
        return self::execute(
            $connection,
            'create',
            $actorRecordId,
            $input,
            $installationCurrency,
            null,
            $expectedPlanSha256
        );
    }

    public static function executeReplace(
        mysqli $connection,
        int $actorRecordId,
        array $input,
        string $installationCurrency,
        string $expectedStateSha256,
        string $expectedPlanSha256
    ): array {
        return self::execute(
            $connection,
            'replace',
            $actorRecordId,
            $input,
            $installationCurrency,
            $expectedStateSha256,
            $expectedPlanSha256
        );
    }

    private static function execute(
        mysqli $connection,
        string $mode,
        int $actorRecordId,
        array $input,
        string $installationCurrency,
        ?string $expectedStateSha256,
        string $expectedPlanSha256
    ): array {
        $result = self::result($mode, $actorRecordId, 'invalid_request');
        if (!self::validSha256($expectedPlanSha256)
            || ($mode === 'replace'
                && !self::validSha256($expectedStateSha256))
        ) {
            return $result;
        }
        if (self::transactionActive($connection)) {
            $result['reason'] = 'transaction_active';
            return $result;
        }
        if (!self::activityStorageAvailable($connection)) {
            $result['reason'] = 'storage_unavailable';
            return $result;
        }

        $initial = self::preflight(
            $connection,
            $mode,
            $actorRecordId,
            $input,
            $installationCurrency,
            $expectedStateSha256
        );
        $result['authorized'] = ($initial['authorized'] ?? false) === true;
        $result['productId'] = is_string($initial['productId'] ?? null)
            ? $initial['productId']
            : '';
        $result['previousStateSha256'] = is_string(
            $initial['previousStateSha256'] ?? null
        ) ? $initial['previousStateSha256'] : '';
        $result['targetStateSha256'] = is_string(
            $initial['targetStateSha256'] ?? null
        ) ? $initial['targetStateSha256'] : '';
        $result['planSha256'] = is_string($initial['planSha256'] ?? null)
            ? $initial['planSha256']
            : '';
        if (empty($initial['ready'])) {
            $result['reason'] = self::preflightReason($initial);
            return $result;
        }
        if (!hash_equals($expectedPlanSha256, $initial['planSha256'])) {
            $result['reason'] = 'plan_changed';
            return $result;
        }

        $transactionGuard = static function (
            mysqli $guardConnection,
            array $context
        ) use (
            $mode,
            $actorRecordId,
            $input,
            $installationCurrency,
            $expectedStateSha256,
            $expectedPlanSha256
        ): string {
            $locked = self::preflight(
                $guardConnection,
                $mode,
                $actorRecordId,
                $input,
                $installationCurrency,
                $expectedStateSha256
            );
            if (empty($locked['authorized'])) {
                return 'permission_denied';
            }
            if (empty($locked['ready'])) {
                return 'preflight_failed';
            }
            if (!hash_equals(
                $expectedPlanSha256,
                (string) ($locked['planSha256'] ?? '')
            ) || ($locked['productId'] ?? null) !== ($context['productId'] ?? null)
                || ($locked['previousStateSha256'] ?? null)
                    !== ($context['previousStateSha256'] ?? null)
                || ($locked['targetStateSha256'] ?? null)
                    !== ($context['targetStateSha256'] ?? null)
            ) {
                return 'plan_changed';
            }
            return 'authorized';
        };

        $activityRecordId = 0;
        $activityRecorder = static function (
            mysqli $activityConnection,
            array $context
        ) use ($actorRecordId, &$activityRecordId): bool {
            $activityRecordId = self::recordActivity(
                $activityConnection,
                $actorRecordId,
                $context
            );
            return $activityRecordId > 0;
        };

        $written = $mode === 'create'
            ? RED_CMS_Store_Lite_Catalog_Persistence::createGuarded(
                $connection,
                $input,
                $installationCurrency,
                $transactionGuard,
                $activityRecorder
            )
            : RED_CMS_Store_Lite_Catalog_Persistence::replaceGuarded(
                $connection,
                $input,
                $installationCurrency,
                (string) $expectedStateSha256,
                $transactionGuard,
                $activityRecorder
            );
        $status = is_string($written['status'] ?? null)
            ? $written['status']
            : 'write_failed';
        $result['previousStateSha256'] = is_string(
            $written['previousStateSha256'] ?? null
        ) ? $written['previousStateSha256'] : $result['previousStateSha256'];
        $result['stateSha256'] = is_string($written['stateSha256'] ?? null)
            ? $written['stateSha256']
            : '';
        if (in_array($status, ['created', 'updated'], true)) {
            if ($activityRecordId < 1
                || !self::validSha256($result['stateSha256'])
            ) {
                $result['reason'] = 'postcondition_failed';
                return $result;
            }
            $result['executed'] = true;
            $result['activityRecordId'] = $activityRecordId;
            $result['reason'] = $status;
            return $result;
        }
        if ($status === 'unchanged') {
            $result['unchanged'] = true;
            $result['reason'] = 'unchanged';
            return $result;
        }
        $result['reason'] = in_array(
            $status,
            [
                'permission_denied',
                'plan_changed',
                'preflight_failed',
                'stale_state',
                'already_exists',
                'not_found',
                'transaction_active',
                'activity_failed',
                'postcondition_failed',
                'transaction_lost',
                'rollback_failed',
                'write_failed',
            ],
            true
        ) ? $status : 'write_failed';
        return $result;
    }

    private static function preflight(
        mysqli $connection,
        string $mode,
        int $actorRecordId,
        array $input,
        string $installationCurrency,
        ?string $expectedStateSha256
    ): array {
        return $mode === 'create'
            ? RED_CMS_Store_Lite_Catalog_Administration::createPreflight(
                $connection,
                $actorRecordId,
                $input,
                $installationCurrency
            )
            : RED_CMS_Store_Lite_Catalog_Administration::replacePreflight(
                $connection,
                $actorRecordId,
                $input,
                $installationCurrency,
                (string) $expectedStateSha256
            );
    }

    private static function preflightReason(array $preflight): string
    {
        $reason = is_string($preflight['reason'] ?? null)
            ? $preflight['reason']
            : 'preflight_failed';
        return in_array(
            $reason,
            [
                'permission_denied',
                'invalid_request',
                'invalid_product',
                'already_exists',
                'not_found',
                'stale_state',
                'storage_unavailable',
            ],
            true
        ) ? $reason : 'preflight_failed';
    }

    private static function recordActivity(
        mysqli $connection,
        int $actorRecordId,
        array $context
    ): int {
        if (array_keys($context) !== [
            'mode',
            'productId',
            'previousStateSha256',
            'targetStateSha256',
        ]) {
            return 0;
        }
        $mode = $context['mode'];
        $productId = $context['productId'];
        $previous = $context['previousStateSha256'];
        $state = $context['targetStateSha256'];
        if (!in_array($mode, ['create', 'replace'], true)
            || !is_string($productId)
            || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $productId) !== 1
            || ($mode === 'create' && $previous !== '')
            || ($mode === 'replace' && !self::validSha256($previous))
            || !self::validSha256($state)
        ) {
            return 0;
        }
        $eventName = $mode === 'create'
            ? 'product.created'
            : 'product.updated';
        try {
            $statement = mysqli_prepare(
                $connection,
                'INSERT INTO RED_Addon_StoreLite_Product_Activity
                    (EventName, ProductID, ActorAdminRecordID,
                     PreviousStateSHA256, StateSHA256)
                 VALUES (?, ?, ?, IF(?=\'\', NULL, UNHEX(?)), UNHEX(?))'
            );
            if (!$statement) {
                return 0;
            }
            mysqli_stmt_bind_param(
                $statement,
                'ssisss',
                $eventName,
                $productId,
                $actorRecordId,
                $previous,
                $previous,
                $state
            );
            $inserted = mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
            $recordId = $inserted ? (int) mysqli_insert_id($connection) : 0;
            if ($recordId < 1) {
                return 0;
            }

            $statement = mysqli_prepare(
                $connection,
                'SELECT EventName, ProductID, ActorAdminRecordID,
                        LOWER(HEX(PreviousStateSHA256)) AS PreviousStateSHA256,
                        LOWER(HEX(StateSHA256)) AS StateSHA256
                 FROM RED_Addon_StoreLite_Product_Activity
                 WHERE RecordID=? LIMIT 1 FOR UPDATE'
            );
            if (!$statement) {
                return 0;
            }
            mysqli_stmt_bind_param($statement, 'i', $recordId);
            $executed = mysqli_stmt_execute($statement);
            $query = $executed ? mysqli_stmt_get_result($statement) : false;
            $row = $query ? mysqli_fetch_assoc($query) : null;
            if ($query) {
                mysqli_free_result($query);
            }
            mysqli_stmt_close($statement);
            return is_array($row)
                && ($row['EventName'] ?? null) === $eventName
                && ($row['ProductID'] ?? null) === $productId
                && (int) ($row['ActorAdminRecordID'] ?? 0) === $actorRecordId
                && (($row['PreviousStateSHA256'] ?? null) === (
                    $mode === 'create' ? null : $previous
                ))
                && ($row['StateSHA256'] ?? null) === $state
                    ? $recordId
                    : 0;
        } catch (Throwable $throwable) {
            return 0;
        }
    }

    private static function activityStorageAvailable(
        mysqli $connection
    ): bool {
        try {
            $query = mysqli_query(
                $connection,
                "SELECT CONCAT_WS(
                    ':',
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA=DATABASE()
                       AND TABLE_NAME='RED_Addon_StoreLite_Product_Activity'
                       AND ENGINE='InnoDB'),
                    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE()
                       AND TABLE_NAME='RED_Addon_StoreLite_Product_Activity'
                       AND COLUMN_NAME IN (
                         'EventName', 'ProductID', 'ActorAdminRecordID',
                         'PreviousStateSHA256', 'StateSHA256'
                       ))
                 ) AS StorageState"
            );
            $row = $query ? mysqli_fetch_assoc($query) : null;
            if ($query) {
                mysqli_free_result($query);
            }
            return ($row['StorageState'] ?? null) === '1:5';
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            if (!mysqli_query(
                $connection,
                'SAVEPOINT redcms_store_lite_action_guard'
            )) {
                return false;
            }
            return mysqli_query(
                $connection,
                'RELEASE SAVEPOINT redcms_store_lite_action_guard'
            ) === true;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function validSha256(?string $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function result(
        string $mode,
        int $actorRecordId,
        string $reason
    ): array {
        return [
            'authorized' => false,
            'executed' => false,
            'unchanged' => false,
            'mode' => in_array($mode, ['create', 'replace'], true) ? $mode : '',
            'actorRecordId' => $actorRecordId >= 1 ? $actorRecordId : 0,
            'productId' => '',
            'previousStateSha256' => '',
            'targetStateSha256' => '',
            'stateSha256' => '',
            'planSha256' => '',
            'activityRecordId' => 0,
            'reason' => $reason,
        ];
    }
}
