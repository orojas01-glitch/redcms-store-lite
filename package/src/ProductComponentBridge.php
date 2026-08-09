<?php

declare(strict_types=1);

require_once __DIR__ . '/CatalogPersistence.php';
require_once __DIR__ . '/PublicProductPresenter.php';
require_once __DIR__ . '/PublicCartFormPresenter.php';

/**
 * Store Lite persistence and read-only runtime binding for one Product
 * component placement.
 */
final class RED_CMS_Store_Lite_Product_Component_Bridge
{
    public const COMPONENT = 'redcms.store-lite/product';
    public const TABLE = 'RED_Addon_StoreLite_Product_Placements';
    public const TABLES = [self::TABLE];

    public static function render(array $context): array
    {
        if (array_keys($context) !== ['component', 'placement']
            || $context['component'] !== self::COMPONENT
            || !is_array($context['placement'])
            || array_keys($context['placement']) !== [
                'recordId', 'layout', 'article', 'position',
            ]
            || !is_int($context['placement']['recordId'])
            || $context['placement']['recordId'] < 1
        ) {
            throw new RuntimeException(
                'Store Lite Product component context is invalid.'
            );
        }

        $connection = self::runtimeConnection();
        try {
            $binding = self::placementProduct(
                $connection,
                $context['placement']['recordId']
            );
            if ($binding === null) {
                throw new RuntimeException(
                    'Store Lite Product component binding is unavailable.'
                );
            }
            $stored = RED_CMS_Store_Lite_Catalog_Persistence::readByRecordId(
                $connection,
                $binding['productRecordId'],
                $binding['currency']
            );
            $view = ($stored['status'] ?? '') === 'found'
                && is_array($stored['product'] ?? null)
                    ? RED_CMS_Store_Lite_Public_Product_Presenter::present(
                        $stored['product'],
                        $binding['currency']
                    )
                    : null;
            if (!is_array($view)) {
                throw new RuntimeException(
                    'Store Lite Product component is not publicly available.'
                );
            }
            $mutationForm =
                RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present(
                    $stored['product'],
                    $binding['currency']
                );
            if (is_array($mutationForm)) {
                $view['mutationForm'] = $mutationForm;
            }
            return $view;
        } finally {
            mysqli_close($connection);
        }
    }

    public static function load(mysqli $connection, array $context): array
    {
        $contentRecordId = self::editorContext(
            $context,
            ['component', 'contentRecordId']
        );
        $binding = self::placementProduct($connection, $contentRecordId);
        if ($binding === null) {
            throw new RuntimeException(
                'Store Lite Product placement is unavailable.'
            );
        }
        return ['product-id' => $binding['productId']];
    }

    public static function create(
        mysqli $connection,
        array $context,
        array $values
    ): bool {
        $contentRecordId = self::editorContext(
            $context,
            ['component', 'contentRecordId', 'actorRecordId', 'planHash']
        );
        return self::transactionActive($connection)
            && self::validValues($values)
            && self::insertPlacement(
                $connection,
                $contentRecordId,
                $values['product-id']
            );
    }

    public static function write(
        mysqli $connection,
        array $context,
        array $values
    ): bool {
        $contentRecordId = self::editorContext(
            $context,
            [
                'component', 'contentRecordId', 'actorRecordId',
                'previousStateHash',
            ]
        );
        return self::transactionActive($connection)
            && self::validValues($values)
            && self::updatePlacement(
                $connection,
                $contentRecordId,
                $values['product-id']
            );
    }

    public static function delete(
        mysqli $connection,
        array $context
    ): bool {
        $contentRecordId = self::editorContext(
            $context,
            ['component', 'contentRecordId', 'actorRecordId', 'planHash']
        );
        if (!self::transactionActive($connection)) {
            return false;
        }
        $statement = mysqli_prepare(
            $connection,
            'DELETE FROM `' . self::TABLE . '` WHERE ContentRecordID=?'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param($statement, 'i', $contentRecordId);
        mysqli_stmt_execute($statement);
        $deleted = mysqli_stmt_affected_rows($statement) === 1;
        mysqli_stmt_close($statement);
        return $deleted;
    }

    private static function editorContext(array $context, array $keys): int
    {
        if (array_keys($context) !== $keys
            || ($context['component'] ?? null) !== self::COMPONENT
            || !is_int($context['contentRecordId'] ?? null)
            || $context['contentRecordId'] < 1
        ) {
            throw new RuntimeException(
                'Store Lite Product editor context is invalid.'
            );
        }
        return $context['contentRecordId'];
    }

    private static function validValues(array $values): bool
    {
        return array_keys($values) === ['product-id']
            && is_string($values['product-id'])
            && preg_match(
                '/\A[a-z][a-z0-9._-]{0,63}\z/D',
                $values['product-id']
            ) === 1;
    }

    private static function insertPlacement(
        mysqli $connection,
        int $contentRecordId,
        string $productId
    ): bool {
        $productRecordId = self::productRecordId($connection, $productId);
        if ($productRecordId < 1) {
            return false;
        }
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO `' . self::TABLE . '` '
                . '(ContentRecordID, ProductRecordID) VALUES (?, ?)'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param(
            $statement,
            'ii',
            $contentRecordId,
            $productRecordId
        );
        mysqli_stmt_execute($statement);
        $created = mysqli_stmt_affected_rows($statement) === 1;
        mysqli_stmt_close($statement);
        return $created;
    }

    private static function updatePlacement(
        mysqli $connection,
        int $contentRecordId,
        string $productId
    ): bool {
        $productRecordId = self::productRecordId($connection, $productId);
        if ($productRecordId < 1) {
            return false;
        }
        $statement = mysqli_prepare(
            $connection,
            'UPDATE `' . self::TABLE . '` SET ProductRecordID=? '
                . 'WHERE ContentRecordID=?'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param(
            $statement,
            'ii',
            $productRecordId,
            $contentRecordId
        );
        mysqli_stmt_execute($statement);
        $changed = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);
        if ($changed === 1) {
            return true;
        }
        $binding = self::placementProduct($connection, $contentRecordId);
        return $binding !== null
            && hash_equals($productId, $binding['productId']);
    }

    private static function productRecordId(
        mysqli $connection,
        string $productId
    ): int {
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID FROM RED_Addon_StoreLite_Products '
                . 'WHERE ProductID=? LIMIT 1'
        );
        if (!$statement) {
            return 0;
        }
        mysqli_stmt_bind_param($statement, 's', $productId);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($statement);
        return is_array($row) ? (int) ($row['RecordID'] ?? 0) : 0;
    }

    private static function placementProduct(
        mysqli $connection,
        int $contentRecordId
    ): ?array {
        $statement = mysqli_prepare(
            $connection,
            'SELECT p.RecordID, p.ProductID, p.Currency '
                . 'FROM `' . self::TABLE . '` AS placement '
                . 'INNER JOIN RED_Addon_StoreLite_Products AS p '
                . 'ON p.RecordID=placement.ProductRecordID '
                . 'WHERE placement.ContentRecordID=? LIMIT 1'
        );
        if (!$statement) {
            return null;
        }
        mysqli_stmt_bind_param($statement, 'i', $contentRecordId);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($statement);
        if (!is_array($row)
            || (int) ($row['RecordID'] ?? 0) < 1
            || !is_string($row['ProductID'] ?? null)
            || !is_string($row['Currency'] ?? null)
        ) {
            return null;
        }
        return [
            'productRecordId' => (int) $row['RecordID'],
            'productId' => $row['ProductID'],
            'currency' => $row['Currency'],
        ];
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            if (!mysqli_query(
                $connection,
                'SAVEPOINT redcms_store_lite_product_component_guard'
            )) {
                return false;
            }
            return mysqli_query(
                $connection,
                'RELEASE SAVEPOINT redcms_store_lite_product_component_guard'
            ) === true;
        } catch (Throwable $throwable) {
            return false;
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
        $connection = mysqli_connect(DBHOST, DBUSER, DBPASS, DBNAME);
        if (!$connection || !mysqli_set_charset($connection, 'utf8mb4')) {
            throw new RuntimeException(
                'Store Lite runtime database connection is unavailable.'
            );
        }
        return $connection;
    }
}
