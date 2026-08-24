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
        $language = is_string($input['language'] ?? null)
            ? $input['language']
            : '';
        if (array_keys($input) !== ['productId', 'currency', 'language']
            || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $productId) !== 1
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || preg_match('/\A[a-z]{2}\z/D', $language) !== 1
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
            $destination = self::continuationDestination(
                $connection,
                $recordId,
                $productId,
                $language,
                $destination
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

    private static function continuationDestination(
        mysqli $connection,
        int $productRecordId,
        string $productId,
        string $language,
        array $destination
    ): array {
        if (!in_array(
            $destination['status'] ?? null,
            ['repair_needed', 'published'],
            true
        )) {
            return $destination;
        }
        try {
            $statement = mysqli_prepare(
                $connection,
                "SELECT CONCAT_WS(':',
                    (SELECT COUNT(*)
                     FROM RED_Addon_StoreLite_Product_Placements
                     WHERE ProductRecordID=?),
                    (SELECT COUNT(*) FROM RED_Articles
                     WHERE Component='Article' AND BINARY Alias=BINARY ?),
                    (SELECT COUNT(*) FROM RED_Articles AS route
                     WHERE route.Component='Article'
                       AND BINARY route.Alias=BINARY ?
                       AND BINARY route.Language=BINARY ?
                       AND LOWER(route.Sections)='home'
                       AND route.Categories=''
                       AND route.SubCategories=''
                       AND route.Active='Y'
                       AND route.PagePosition>0
                       AND route.PagePosition<=99
                       AND (YEAR(route.StartDate)=0 OR route.StartDate<=NOW())
                       AND (YEAR(route.ExpDate)=0 OR route.ExpDate>=NOW())
                       AND EXISTS (
                         SELECT 1 FROM RED_Sections AS section_row
                         WHERE LOWER(section_row.Sections)='home'
                           AND BINARY section_row.Language=BINARY route.Language
                           AND section_row.Active='Y'
                       )),
                    (SELECT COUNT(*)
                     FROM RED_Addon_StoreLite_Product_Placements AS placement
                     INNER JOIN RED_Articles AS component
                       ON component.RecordID=placement.ContentRecordID
                      AND component.Component='redcms.store-lite/product'
                     INNER JOIN RED_Articles AS route
                       ON route.Component='Article'
                      AND BINARY route.Alias=BINARY ?
                      AND BINARY route.Language=BINARY ?
                      AND LOWER(route.Sections)='home'
                      AND route.Categories=''
                      AND route.SubCategories=''
                      AND route.Active='Y'
                      AND route.PagePosition>0
                      AND route.PagePosition<=99
                     WHERE placement.ProductRecordID=?
                       AND component.Active='N'
                       AND component.Alias=''
                       AND component.Sections=''
                       AND component.Categories=''
                       AND component.SubCategories=''
                       AND component.Article=''
                       AND component.PagePosition=0
                       AND component.PagePositionOrder=0
                       AND component.HomePosition=0
                       AND component.SectionPosition=0
                       AND component.CategoryPosition=0
                       AND component.SubCategoryPosition=0
                       AND BINARY component.Language=BINARY ?
                       AND BINARY component.Layout=BINARY route.Layout
                       AND component.StartDate='1970-01-01 00:00:00'
                       AND component.EventDate='1970-01-01 00:00:00'
                       AND component.ExpDate='9999-12-31 23:59:59'),
                    (SELECT COUNT(*)
                     FROM RED_Addon_StoreLite_Product_Placements AS placement
                     INNER JOIN RED_Articles AS component
                       ON component.RecordID=placement.ContentRecordID
                      AND component.Component='redcms.store-lite/product'
                     INNER JOIN RED_Articles AS route
                       ON route.Component='Article'
                      AND BINARY route.Alias=BINARY ?
                      AND BINARY route.Language=BINARY ?
                      AND LOWER(route.Sections)='home'
                      AND route.Categories=''
                      AND route.SubCategories=''
                      AND route.Active='Y'
                      AND route.PagePosition>0
                      AND route.PagePosition<=99
                     INNER JOIN RED_Addon_Component_Destination_Executions AS execution
                       ON execution.PackageID='redcms.store-lite'
                      AND execution.ComponentID='redcms.store-lite/product'
                      AND execution.RouteRecordID=route.RecordID
                      AND execution.ComponentRecordID=component.RecordID
                      AND execution.Stage IN (
                        'component_created', 'component_published'
                      )
                      AND execution.RouteStateSHA256 IS NOT NULL
                      AND execution.ComponentStateSHA256 IS NOT NULL
                      AND (
                        (execution.Stage='component_created'
                         AND execution.PlacementStateSHA256 IS NULL)
                        OR
                        (execution.Stage='component_published'
                         AND execution.PlacementStateSHA256 IS NOT NULL)
                      )
                      AND execution.SearchNotification='pending'
                     WHERE placement.ProductRecordID=?
                       AND component.Active='Y'
                       AND component.Alias=''
                       AND BINARY component.Sections=BINARY route.Sections
                       AND component.Categories=''
                       AND component.SubCategories=''
                       AND BINARY component.Article=BINARY ?
                       AND component.PagePosition>0
                       AND component.PagePosition<=99
                       AND BINARY component.Language=BINARY ?
                       AND BINARY component.Layout=BINARY route.Layout
                       AND component.StartDate='1970-01-01 00:00:00'
                       AND component.EventDate='1970-01-01 00:00:00'
                       AND component.ExpDate='9999-12-31 23:59:59')) AS ContinuationState"
            );
            if (!$statement) {
                throw new RuntimeException('continuation unavailable');
            }
            mysqli_stmt_bind_param(
                $statement,
                'isssssisssiss',
                $productRecordId,
                $productId,
                $productId,
                $language,
                $productId,
                $language,
                $productRecordId,
                $language,
                $productId,
                $language,
                $productRecordId,
                $productId,
                $language
            );
            if (!mysqli_stmt_execute($statement)) {
                mysqli_stmt_close($statement);
                throw new RuntimeException('continuation unavailable');
            }
            $query = mysqli_stmt_get_result($statement);
            $row = $query ? mysqli_fetch_assoc($query) : null;
            if ($query) {
                mysqli_free_result($query);
            }
            mysqli_stmt_close($statement);
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Store Lite destination continuation is unavailable.'
            );
        }
        $continuationState = (string) ($row['ContinuationState'] ?? '');
        if (!in_array(
            $continuationState,
            ['0:1:1:0:0', '1:1:1:1:0', '1:1:1:0:1'],
            true
        )) {
            return $destination;
        }
        $componentCreated = $continuationState === '1:1:1:1:0';
        $componentPublished = $continuationState === '1:1:1:0:1';
        return [
            'status' => $componentPublished
                ? 'component_published'
                : ($componentCreated ? 'component_created' : 'route_created'),
            'label' => 'Provisioning in progress',
            'path' => '/' . rawurlencode($productId),
            'pathKind' => 'expected',
            'reason' => $componentPublished
                ? 'The guarded public Product component is ready for search refresh.'
                : ($componentCreated
                    ? 'The guarded inactive Product component is ready for publication.'
                    : 'The guarded Article route is ready for component creation.'),
        ];
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
