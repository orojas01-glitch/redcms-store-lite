<?php

declare(strict_types=1);

require_once __DIR__ . '/PaymentEventPersistence.php';

/**
 * Enabled-package runtime binding for the Store Lite payment-event service.
 *
 * RED-CMS resolves the request-local owner before calling this handler. Store
 * Lite opens only the selected client database, owns commit/rollback, and
 * returns bounded provider-neutral evidence through the typed service result.
 */
final class RED_CMS_Store_Lite_Payment_Event_Service
{
    public const SERVICE = 'commerce.orders';
    public const OPERATION = 'payment.event.apply';

    public static function handle(
        RED_Addon_Service_Request $request
    ): RED_Addon_Service_Result {
        if ($request->service() !== self::SERVICE
            || $request->operation() !== self::OPERATION
        ) {
            return RED_Addon_Service_Result::failure(
                'payment_event_operation_unavailable'
            );
        }
        $input = $request->input();
        if (array_keys($input) !== ['order', 'event']
            || !is_array($input['order'] ?? null)
            || !is_array($input['event'] ?? null)
        ) {
            return RED_Addon_Service_Result::failure(
                'payment_event_invalid'
            );
        }

        $connection = null;
        try {
            $connection = self::runtimeConnection();
            if (!$connection || !mysqli_begin_transaction($connection)) {
                throw new RuntimeException(
                    'Store Lite payment transaction is unavailable.'
                );
            }
            $applied = RED_CMS_Store_Lite_Payment_Event_Persistence::
                applyWithinTransaction(
                    $connection,
                    $input['order'],
                    $input['event']
                );
            $status = $applied['status'] ?? '';
            if (!in_array(
                $status,
                ['applied', 'replayed', 'unchanged'],
                true
            )) {
                mysqli_rollback($connection);
                return RED_Addon_Service_Result::failure(
                    self::errorCode((string) $status)
                );
            }
            if (!mysqli_commit($connection)) {
                throw new RuntimeException(
                    'Store Lite payment commit is unavailable.'
                );
            }
            return RED_Addon_Service_Result::success([
                'status' => $status,
                'orderId' => $applied['orderId'],
                'eventEvidenceSha256' =>
                    $applied['eventEvidenceSha256'],
                'planSha256' => $applied['planSha256'],
                'stateChanged' => $applied['stateChanged'],
                'fulfillmentBlocked' =>
                    $applied['fulfillmentBlocked'],
                'orderStatus' => $applied['orderStatus'],
                'paymentStatus' => $applied['paymentStatus'],
                'fulfillmentStatus' =>
                    $applied['fulfillmentStatus'],
            ]);
        } catch (Throwable $throwable) {
            if ($connection instanceof mysqli) {
                try {
                    mysqli_rollback($connection);
                } catch (Throwable $rollbackFailure) {
                    // The typed failure remains value-free.
                }
            }
            return RED_Addon_Service_Result::failure(
                'payment_event_storage_unavailable'
            );
        } finally {
            if ($connection instanceof mysqli) {
                mysqli_close($connection);
            }
        }
    }

    private static function runtimeConnection(): mysqli
    {
        foreach (['DBHOST', 'DBUSER', 'DBPASS', 'DBNAME'] as $constant) {
            if (!defined($constant)) {
                throw new RuntimeException(
                    'Store Lite runtime database configuration is unavailable.'
                );
            }
        }
        $hostPort = (string) constant('DBHOST');
        $host = $hostPort;
        $port = 3306;
        if (preg_match(
            '/\A([^:]+):([0-9]{1,5})\z/D',
            $hostPort,
            $parts
        ) === 1) {
            $host = $parts[1];
            $port = (int) $parts[2];
        }
        $user = (string) constant('DBUSER');
        $password = (string) constant('DBPASS');
        $database = (string) constant('DBNAME');
        if ($host === '' || $port < 1 || $port > 65535
            || $user === '' || $database === ''
        ) {
            throw new RuntimeException(
                'Store Lite runtime database configuration is unavailable.'
            );
        }
        $connection = mysqli_init();
        if (!$connection instanceof mysqli) {
            throw new RuntimeException(
                'Store Lite runtime database connection is unavailable.'
            );
        }
        mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        mysqli_real_connect(
            $connection,
            $host,
            $user,
            $password,
            $database,
            $port
        );
        if (!mysqli_set_charset($connection, 'utf8mb4')) {
            mysqli_close($connection);
            throw new RuntimeException(
                'Store Lite runtime database connection is unavailable.'
            );
        }
        return $connection;
    }

    private static function errorCode(string $status): string
    {
        return match ($status) {
            'transition_refused', 'invalid' => 'payment_event_refused',
            'order_not_found' => 'payment_order_not_found',
            'stale_order' => 'payment_order_state_conflict',
            'replay_conflict' => 'payment_event_replay_conflict',
            default => 'payment_event_storage_unavailable',
        };
    }
}
