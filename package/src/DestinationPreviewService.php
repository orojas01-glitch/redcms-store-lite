<?php

declare(strict_types=1);

require_once __DIR__ . '/CatalogPersistence.php';
require_once __DIR__ . '/DestinationStatus.php';
require_once __DIR__ . '/DestinationProvisioningPreview.php';

/**
 * Bounded typed service for current product destination-preview evidence.
 *
 * The service opens one read-only snapshot, derives the current product and
 * destination state, and returns only the seven fields accepted by the core
 * destination coordinator. It never enables writes or exposes product facts.
 */
final class RED_CMS_Store_Lite_Destination_Preview_Service
{
    public const SERVICE = 'content.destination-preview.store-lite';
    public const OPERATION = 'destination.preview';

    public static function handle(
        RED_Addon_Service_Request $request
    ): RED_Addon_Service_Result {
        if ($request->service() !== self::SERVICE
            || $request->operation() !== self::OPERATION
        ) {
            return RED_Addon_Service_Result::failure(
                'destination_preview_operation_unavailable'
            );
        }
        $input = $request->input();
        $productId = is_string($input['productId'] ?? null)
            ? $input['productId']
            : '';
        $currency = is_string($input['currency'] ?? null)
            ? $input['currency']
            : '';
        if (array_keys($input) !== ['productId', 'currency']
            || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $productId) !== 1
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
        ) {
            return RED_Addon_Service_Result::failure(
                'destination_preview_request_invalid'
            );
        }

        $connection = null;
        try {
            $connection = self::runtimeConnection();
            if (!mysqli_begin_transaction(
                $connection,
                MYSQLI_TRANS_START_READ_ONLY
            )) {
                throw new RuntimeException(
                    'Store Lite destination snapshot is unavailable.'
                );
            }
            $stored = RED_CMS_Store_Lite_Catalog_Persistence::read(
                $connection,
                $productId,
                $currency
            );
            if (($stored['status'] ?? null) !== 'found'
                || !is_array($stored['product'] ?? null)
            ) {
                mysqli_rollback($connection);
                return RED_Addon_Service_Result::failure(
                    'destination_preview_product_unavailable'
                );
            }
            $recordId = (int) ($stored['recordId'] ?? 0);
            $product = $stored['product'];
            $stateSha256 = (string) ($stored['stateSha256'] ?? '');
            $destination = RED_CMS_Store_Lite_Destination_Status::read(
                $connection,
                $recordId,
                $productId,
                (string) ($product['state'] ?? '')
            );
            $preview = self::envelope(
                RED_CMS_Store_Lite_Destination_Provisioning_Preview::build(
                    $recordId,
                    $product,
                    $stateSha256,
                    $destination
                )
            );
            mysqli_rollback($connection);
            return RED_Addon_Service_Result::success($preview);
        } catch (Throwable $throwable) {
            if ($connection instanceof mysqli) {
                try {
                    mysqli_rollback($connection);
                } catch (Throwable $rollbackFailure) {
                    // The typed failure remains value-free.
                }
            }
            return RED_Addon_Service_Result::failure(
                'destination_preview_storage_unavailable'
            );
        } finally {
            if ($connection instanceof mysqli) {
                mysqli_close($connection);
            }
        }
    }

    public static function envelope(array $preview): array
    {
        $result = [
            'schema' => 1,
            'planSha256' => $preview['planSha256'] ?? null,
            'intent' => $preview['intent'] ?? null,
            'ready' => $preview['ready'] ?? null,
            'requiresConfirmation' =>
                $preview['requiresConfirmation'] ?? null,
            'writesEnabled' => $preview['writesEnabled'] ?? null,
            'path' => $preview['path'] ?? null,
        ];
        if (!is_string($result['planSha256'])
            || preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $result['planSha256']
            ) !== 1
            || !is_string($result['intent'])
            || !in_array(
                $result['intent'],
                ['none', 'provision', 'blocked', 'repair'],
                true
            )
            || !is_bool($result['ready'])
            || !is_bool($result['requiresConfirmation'])
            || $result['writesEnabled'] !== false
            || !is_string($result['path'])
            || strlen($result['path']) < 2
            || strlen($result['path']) > 512
            || !str_starts_with($result['path'], '/')
            || str_starts_with($result['path'], '//')
            || str_contains($result['path'], '/./')
            || str_contains($result['path'], '/../')
            || str_ends_with($result['path'], '/.')
            || str_ends_with($result['path'], '/..')
            || preg_match('//u', $result['path']) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $result['path']) === 1
        ) {
            throw new InvalidArgumentException(
                'Store Lite destination preview envelope is invalid.'
            );
        }
        return $result;
    }

    private static function runtimeConnection(): mysqli
    {
        foreach (['DBHOST', 'DBUSER', 'DBPASS', 'DBNAME'] as $constant) {
            if (!defined($constant)) {
                throw new RuntimeException(
                    'Store Lite destination database configuration is unavailable.'
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
                'Store Lite destination database configuration is unavailable.'
            );
        }
        $connection = mysqli_init();
        if (!$connection instanceof mysqli) {
            throw new RuntimeException(
                'Store Lite destination database connection is unavailable.'
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
                'Store Lite destination database connection is unavailable.'
            );
        }
        return $connection;
    }
}
