<?php

declare(strict_types=1);

require_once __DIR__ . '/CatalogPersistence.php';

/**
 * Read-only Store Lite product administration model and write preflight.
 *
 * The class is not registered by the package foundation and exposes no route.
 * Every operation requires RED-CMS to have loaded its fresh database-backed
 * capability helper; Owner or lifecycle access never substitutes for the
 * exact Store Lite product-management permission.
 */
final class RED_CMS_Store_Lite_Catalog_Administration
{
    private const PACKAGE_ID = 'redcms.store-lite';
    private const PERMISSION = 'store.products.manage';
    private const MAX_PAGE_SIZE = 100;

    public static function listProducts(
        mysqli $connection,
        int $actorRecordId,
        string $installationCurrency,
        int $limit = 50,
        ?string $afterProductId = null
    ): array {
        $result = self::listResult($actorRecordId, 'permission_denied');
        if (!self::authorized($connection, $actorRecordId)) {
            return $result;
        }
        $result['authorized'] = true;
        if (!self::validCurrency($installationCurrency)
            || $limit < 1
            || $limit > self::MAX_PAGE_SIZE
            || ($afterProductId !== null
                && !self::validProductId($afterProductId))
        ) {
            $result['reason'] = 'invalid_request';
            return $result;
        }

        try {
            $queryLimit = $limit + 1;
            if ($afterProductId === null) {
                $statement = mysqli_prepare(
                    $connection,
                    'SELECT ProductID
                     FROM RED_Addon_StoreLite_Products
                     WHERE Currency=?
                     ORDER BY ProductID ASC
                     LIMIT ?'
                );
                if (!$statement) {
                    throw new RuntimeException('storage_unavailable');
                }
                mysqli_stmt_bind_param(
                    $statement,
                    'si',
                    $installationCurrency,
                    $queryLimit
                );
            } else {
                $statement = mysqli_prepare(
                    $connection,
                    'SELECT ProductID
                     FROM RED_Addon_StoreLite_Products
                     WHERE Currency=? AND ProductID>?
                     ORDER BY ProductID ASC
                     LIMIT ?'
                );
                if (!$statement) {
                    throw new RuntimeException('storage_unavailable');
                }
                mysqli_stmt_bind_param(
                    $statement,
                    'ssi',
                    $installationCurrency,
                    $afterProductId,
                    $queryLimit
                );
            }
            if (!mysqli_stmt_execute($statement)) {
                mysqli_stmt_close($statement);
                throw new RuntimeException('storage_unavailable');
            }
            $query = mysqli_stmt_get_result($statement);
            if (!$query) {
                mysqli_stmt_close($statement);
                throw new RuntimeException('storage_unavailable');
            }
            $productIds = [];
            while ($row = mysqli_fetch_assoc($query)) {
                $productId = (string) ($row['ProductID'] ?? '');
                if (!self::validProductId($productId)) {
                    throw new RuntimeException('storage_unavailable');
                }
                $productIds[] = $productId;
            }
            mysqli_free_result($query);
            mysqli_stmt_close($statement);

            $hasMore = count($productIds) > $limit;
            if ($hasMore) {
                array_pop($productIds);
            }
            $items = [];
            foreach ($productIds as $productId) {
                $stored = RED_CMS_Store_Lite_Catalog_Persistence::read(
                    $connection,
                    $productId,
                    $installationCurrency
                );
                if (($stored['status'] ?? '') !== 'found'
                    || !is_array($stored['product'] ?? null)
                ) {
                    throw new RuntimeException('storage_unavailable');
                }
                $items[] = self::summary(
                    $stored['product'],
                    (string) ($stored['stateSha256'] ?? '')
                );
            }
            $nextCursor = $hasMore && $items !== []
                ? $items[count($items) - 1]['id']
                : null;
            $encoded = json_encode(
                [
                    'schema' => 1,
                    'currency' => $installationCurrency,
                    'items' => $items,
                    'nextCursor' => $nextCursor,
                ],
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );

            $result['loaded'] = true;
            $result['items'] = $items;
            $result['nextCursor'] = $nextCursor;
            $result['catalogStateSha256'] = hash('sha256', $encoded);
            $result['reason'] = 'loaded';
            return $result;
        } catch (Throwable $throwable) {
            $result['items'] = [];
            $result['nextCursor'] = null;
            $result['catalogStateSha256'] = '';
            $result['reason'] = 'storage_unavailable';
            return $result;
        }
    }

    public static function editModel(
        mysqli $connection,
        int $actorRecordId,
        string $productId,
        string $installationCurrency
    ): array {
        $result = self::editResult(
            $actorRecordId,
            $productId,
            'permission_denied'
        );
        if (!self::authorized($connection, $actorRecordId)) {
            return $result;
        }
        $result['authorized'] = true;
        if (!self::validProductId($productId)
            || !self::validCurrency($installationCurrency)
        ) {
            $result['reason'] = 'invalid_request';
            return $result;
        }
        $stored = RED_CMS_Store_Lite_Catalog_Persistence::read(
            $connection,
            $productId,
            $installationCurrency
        );
        if (($stored['status'] ?? '') !== 'found') {
            $result['reason'] = ($stored['status'] ?? '') === 'not_found'
                ? 'not_found'
                : 'storage_unavailable';
            return $result;
        }

        $result['loaded'] = true;
        $result['product'] = $stored['product'];
        $result['stateSha256'] = $stored['stateSha256'];
        $result['reason'] = 'loaded';
        return $result;
    }

    public static function createPreflight(
        mysqli $connection,
        int $actorRecordId,
        array $input,
        string $installationCurrency
    ): array {
        return self::writePreflight(
            $connection,
            'create',
            $actorRecordId,
            $input,
            $installationCurrency,
            null
        );
    }

    public static function replacePreflight(
        mysqli $connection,
        int $actorRecordId,
        array $input,
        string $installationCurrency,
        string $expectedStateSha256
    ): array {
        return self::writePreflight(
            $connection,
            'replace',
            $actorRecordId,
            $input,
            $installationCurrency,
            $expectedStateSha256
        );
    }

    private static function writePreflight(
        mysqli $connection,
        string $mode,
        int $actorRecordId,
        array $input,
        string $installationCurrency,
        ?string $expectedStateSha256
    ): array {
        $result = self::preflightResult(
            $mode,
            $actorRecordId,
            'permission_denied'
        );
        if (!self::authorized($connection, $actorRecordId)) {
            return $result;
        }
        $result['authorized'] = true;
        if (!self::validCurrency($installationCurrency)
            || ($mode === 'replace'
                && !self::validSha256($expectedStateSha256))
        ) {
            $result['reason'] = 'invalid_request';
            return $result;
        }
        $normalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
            $input,
            $installationCurrency
        );
        if (empty($normalized['valid'])
            || !is_array($normalized['product'] ?? null)
        ) {
            $result['reason'] = 'invalid_product';
            return $result;
        }

        $product = $normalized['product'];
        $result['productId'] = $product['id'];
        $targetStateSha256 =
            RED_CMS_Store_Lite_Catalog_Persistence::normalizedStateSha256(
                $product,
                $installationCurrency
            );
        if (!self::validSha256($targetStateSha256)) {
            $result['reason'] = 'invalid_product';
            return $result;
        }
        $stored = RED_CMS_Store_Lite_Catalog_Persistence::read(
            $connection,
            $product['id'],
            $installationCurrency
        );
        if ($mode === 'create') {
            if (($stored['status'] ?? '') === 'found') {
                $result['reason'] = 'already_exists';
                return $result;
            }
            if (($stored['status'] ?? '') !== 'not_found') {
                $result['reason'] = 'storage_unavailable';
                return $result;
            }
            $previousStateSha256 = '';
        } else {
            if (($stored['status'] ?? '') === 'not_found') {
                $result['reason'] = 'not_found';
                return $result;
            }
            if (($stored['status'] ?? '') !== 'found'
                || !self::validSha256($stored['stateSha256'] ?? null)
            ) {
                $result['reason'] = 'storage_unavailable';
                return $result;
            }
            $previousStateSha256 = $stored['stateSha256'];
            if (!hash_equals(
                (string) $expectedStateSha256,
                $previousStateSha256
            )) {
                $result['reason'] = 'stale_state';
                return $result;
            }
        }

        $plan = [
            'schema' => 1,
            'package' => self::PACKAGE_ID,
            'mode' => $mode,
            'actorRecordId' => (string) $actorRecordId,
            'permission' => self::PERMISSION,
            'productId' => $product['id'],
            'previousStateSha256' => $previousStateSha256,
            'targetStateSha256' => $targetStateSha256,
        ];
        $encoded = json_encode(
            $plan,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );

        $result['ready'] = true;
        $result['unchanged'] = $mode === 'replace'
            && hash_equals($previousStateSha256, $targetStateSha256);
        $result['product'] = $product;
        $result['previousStateSha256'] = $previousStateSha256;
        $result['targetStateSha256'] = $targetStateSha256;
        $result['planSha256'] = hash('sha256', $encoded);
        $result['reason'] = $result['unchanged'] ? 'unchanged' : 'ready';
        return $result;
    }

    private static function summary(array $product, string $stateSha256): array
    {
        $prices = [];
        if ($product['type'] === 'simple') {
            $prices[] = $product['priceMinor'];
        } else {
            foreach ($product['variants'] as $variant) {
                $prices[] = $variant['priceMinor'];
            }
        }
        return [
            'id' => $product['id'],
            'type' => $product['type'],
            'title' => $product['title'],
            'state' => $product['state'],
            'availability' => $product['availability'],
            'currency' => $product['currency'],
            'variantCount' => count($product['variants']),
            'minimumPriceMinor' => min($prices),
            'maximumPriceMinor' => max($prices),
            'stateSha256' => $stateSha256,
        ];
    }

    private static function authorized(
        mysqli $connection,
        int $actorRecordId
    ): bool {
        return $actorRecordId >= 1
            && $actorRecordId <= 2147483647
            && function_exists(
                'red_addon_component_editor_actor_has_permission'
            )
            && red_addon_component_editor_actor_has_permission(
                $connection,
                $actorRecordId,
                self::PERMISSION
            );
    }

    private static function validProductId(?string $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) === 1;
    }

    private static function validCurrency(string $value): bool
    {
        return preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }

    private static function validSha256(?string $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function listResult(int $actorRecordId, string $reason): array
    {
        return [
            'authorized' => false,
            'loaded' => false,
            'actorRecordId' => $actorRecordId >= 1 ? $actorRecordId : 0,
            'permission' => self::PERMISSION,
            'items' => [],
            'nextCursor' => null,
            'catalogStateSha256' => '',
            'reason' => $reason,
        ];
    }

    private static function editResult(
        int $actorRecordId,
        string $productId,
        string $reason
    ): array {
        return [
            'authorized' => false,
            'loaded' => false,
            'actorRecordId' => $actorRecordId >= 1 ? $actorRecordId : 0,
            'permission' => self::PERMISSION,
            'productId' => self::validProductId($productId) ? $productId : '',
            'product' => null,
            'stateSha256' => '',
            'reason' => $reason,
        ];
    }

    private static function preflightResult(
        string $mode,
        int $actorRecordId,
        string $reason
    ): array {
        return [
            'authorized' => false,
            'ready' => false,
            'unchanged' => false,
            'mode' => in_array($mode, ['create', 'replace'], true) ? $mode : '',
            'actorRecordId' => $actorRecordId >= 1 ? $actorRecordId : 0,
            'permission' => self::PERMISSION,
            'productId' => '',
            'product' => null,
            'previousStateSha256' => '',
            'targetStateSha256' => '',
            'planSha256' => '',
            'reason' => $reason,
        ];
    }
}
