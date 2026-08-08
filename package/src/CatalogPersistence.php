<?php

declare(strict_types=1);

require_once __DIR__ . '/ProductNormalizer.php';

/**
 * Internal atomic persistence for one complete Store Lite product graph.
 *
 * This class registers no route or administrator surface. Callers must use a
 * package-owned mysqli connection that is not already inside a transaction.
 */
final class RED_CMS_Store_Lite_Catalog_Persistence
{
    private const TABLES = [
        'RED_Addon_StoreLite_Products',
        'RED_Addon_StoreLite_Product_Options',
        'RED_Addon_StoreLite_Product_Option_Values',
        'RED_Addon_StoreLite_Product_Variants',
        'RED_Addon_StoreLite_Product_Variant_Selections',
    ];

    public static function read(
        mysqli $connection,
        string $productId,
        string $installationCurrency
    ): array {
        if (!self::validProductId($productId)
            || !self::validCurrency($installationCurrency)
            || !self::transactionTablesAvailable($connection)
        ) {
            return self::readResult('invalid');
        }

        return self::readStored(
            $connection,
            $productId,
            $installationCurrency,
            false
        );
    }

    public static function create(
        mysqli $connection,
        array $input,
        string $installationCurrency
    ): array {
        return self::persist(
            $connection,
            'create',
            $input,
            $installationCurrency,
            null
        );
    }

    public static function replace(
        mysqli $connection,
        array $input,
        string $installationCurrency,
        string $expectedStateSha256
    ): array {
        return self::persist(
            $connection,
            'replace',
            $input,
            $installationCurrency,
            $expectedStateSha256
        );
    }

    private static function persist(
        mysqli $connection,
        string $mode,
        array $input,
        string $installationCurrency,
        ?string $expectedStateSha256
    ): array {
        $result = self::writeResult('invalid');
        $normalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
            $input,
            $installationCurrency
        );
        $callerTransactionActive = self::transactionActive($connection);
        if (empty($normalized['valid'])
            || !is_array($normalized['product'] ?? null)
            || !self::transactionTablesAvailable($connection)
            || $callerTransactionActive
        ) {
            if ($callerTransactionActive) {
                $result['status'] = 'transaction_active';
            }
            return $result;
        }
        if ($mode === 'replace' && !self::validSha256($expectedStateSha256)) {
            return $result;
        }

        $target = $normalized['product'];
        $targetStateSha256 = self::stateSha256($target);
        $result['productId'] = $target['id'];
        $result['targetStateSha256'] = $targetStateSha256;

        try {
            if (!mysqli_begin_transaction($connection)) {
                $result['status'] = 'transaction_failed';
                return $result;
            }
        } catch (Throwable $throwable) {
            $result['status'] = 'transaction_failed';
            return $result;
        }

        $reason = 'write_failed';
        try {
            $current = self::readStored(
                $connection,
                $target['id'],
                $installationCurrency,
                true
            );
            if ($mode === 'create') {
                if ($current['status'] === 'found') {
                    $reason = 'already_exists';
                    throw new RuntimeException($reason);
                }
                if ($current['status'] !== 'not_found') {
                    $reason = 'storage_unavailable';
                    throw new RuntimeException($reason);
                }
            } else {
                if ($current['status'] === 'not_found') {
                    $reason = 'not_found';
                    throw new RuntimeException($reason);
                }
                if ($current['status'] !== 'found') {
                    $reason = 'storage_unavailable';
                    throw new RuntimeException($reason);
                }
                $result['previousStateSha256'] = $current['stateSha256'];
                if (!hash_equals(
                    (string) $expectedStateSha256,
                    $current['stateSha256']
                )) {
                    $reason = 'stale_state';
                    throw new RuntimeException($reason);
                }
                if (hash_equals($targetStateSha256, $current['stateSha256'])) {
                    if (!mysqli_commit($connection)) {
                        throw new RuntimeException('commit_failed');
                    }
                    $result['status'] = 'unchanged';
                    $result['stateSha256'] = $current['stateSha256'];
                    return $result;
                }
            }

            $productRecordId = $mode === 'create'
                ? self::insertProduct($connection, $target)
                : (int) $current['recordId'];
            if ($productRecordId < 1) {
                throw new RuntimeException($reason);
            }
            if ($mode === 'replace') {
                self::updateProduct($connection, $productRecordId, $target);
                self::deleteChildren($connection, $productRecordId);
            }
            self::insertChildren($connection, $productRecordId, $target);

            $post = self::readStored(
                $connection,
                $target['id'],
                $installationCurrency,
                true
            );
            if ($post['status'] !== 'found'
                || $post['product'] !== $target
                || !hash_equals($targetStateSha256, $post['stateSha256'])
            ) {
                $reason = 'postcondition_failed';
                throw new RuntimeException($reason);
            }
            if (!mysqli_commit($connection)) {
                $reason = 'commit_failed';
                throw new RuntimeException($reason);
            }

            $result['status'] = $mode === 'create' ? 'created' : 'updated';
            $result['stateSha256'] = $post['stateSha256'];
            return $result;
        } catch (Throwable $throwable) {
            try {
                mysqli_rollback($connection);
            } catch (Throwable $rollbackFailure) {
                $reason = 'rollback_failed';
            }
            $result['status'] = $reason;
            return $result;
        }
    }

    private static function readStored(
        mysqli $connection,
        string $productId,
        string $installationCurrency,
        bool $lock
    ): array {
        try {
            $statement = mysqli_prepare(
                $connection,
                'SELECT RecordID, ProductID, ProductType, Title, Summary,
                        Currency, State, Availability, ImageReference, SKU,
                        PriceMinor, Stock
                 FROM RED_Addon_StoreLite_Products
                 WHERE ProductID=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
            );
            if (!$statement) {
                return self::readResult('storage_unavailable');
            }
            mysqli_stmt_bind_param($statement, 's', $productId);
            $executed = mysqli_stmt_execute($statement);
            $query = $executed ? mysqli_stmt_get_result($statement) : false;
            $row = $query ? mysqli_fetch_assoc($query) : null;
            if ($query) {
                mysqli_free_result($query);
            }
            mysqli_stmt_close($statement);
            if (!$executed) {
                return self::readResult('storage_unavailable');
            }
            if (!is_array($row)) {
                return self::readResult('not_found');
            }

            $recordId = (int) ($row['RecordID'] ?? 0);
            $product = [
                'id' => (string) ($row['ProductID'] ?? ''),
                'type' => (string) ($row['ProductType'] ?? ''),
                'title' => (string) ($row['Title'] ?? ''),
                'summary' => $row['Summary'] === null
                    ? null
                    : (string) $row['Summary'],
                'currency' => (string) ($row['Currency'] ?? ''),
                'state' => (string) ($row['State'] ?? ''),
                'availability' => (string) ($row['Availability'] ?? ''),
                'imageRef' => $row['ImageReference'] === null
                    ? null
                    : (string) $row['ImageReference'],
                'sku' => $row['SKU'] === null ? null : (string) $row['SKU'],
                'priceMinor' => $row['PriceMinor'] === null
                    ? null
                    : (int) $row['PriceMinor'],
                'stock' => $row['Stock'] === null ? null : (int) $row['Stock'],
                'options' => [],
                'variants' => [],
            ];
            if ($recordId < 1
                || !hash_equals($productId, $product['id'])
                || !hash_equals($installationCurrency, $product['currency'])
            ) {
                return self::readResult('storage_unavailable');
            }

            $optionMaps = self::readOptions($connection, $recordId, $product);
            self::readVariants(
                $connection,
                $recordId,
                $optionMaps,
                $product
            );
            $normalized = RED_CMS_Store_Lite_Product_Normalizer::normalize(
                $product,
                $installationCurrency
            );
            if (empty($normalized['valid'])
                || !is_array($normalized['product'] ?? null)
                || $normalized['product'] !== $product
            ) {
                return self::readResult('storage_unavailable');
            }

            return [
                'status' => 'found',
                'recordId' => $recordId,
                'product' => $product,
                'stateSha256' => self::stateSha256($product),
            ];
        } catch (Throwable $throwable) {
            return self::readResult('storage_unavailable');
        }
    }

    private static function readOptions(
        mysqli $connection,
        int $productRecordId,
        array &$product
    ): array {
        $statement = mysqli_prepare(
            $connection,
            'SELECT options.RecordID AS OptionRecordID, options.OptionKey,
                    options.Label AS OptionLabel, options.Position AS OptionPosition,
                    values_list.RecordID AS ValueRecordID, values_list.ValueID,
                    values_list.Label AS ValueLabel,
                    values_list.Position AS ValuePosition
             FROM RED_Addon_StoreLite_Product_Options AS options
             LEFT JOIN RED_Addon_StoreLite_Product_Option_Values AS values_list
               ON values_list.ProductRecordID=options.ProductRecordID
              AND values_list.OptionRecordID=options.RecordID
             WHERE options.ProductRecordID=?
             ORDER BY options.Position ASC, values_list.Position ASC'
        );
        if (!$statement) {
            throw new RuntimeException('storage_unavailable');
        }
        mysqli_stmt_bind_param($statement, 'i', $productRecordId);
        if (!mysqli_stmt_execute($statement)) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('storage_unavailable');
        }
        $query = mysqli_stmt_get_result($statement);
        if (!$query) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('storage_unavailable');
        }

        $optionRecordToKey = [];
        $valueRecords = [];
        $positions = [];
        while ($row = mysqli_fetch_assoc($query)) {
            $optionRecordId = (int) ($row['OptionRecordID'] ?? 0);
            $optionPosition = (int) ($row['OptionPosition'] ?? 0);
            $optionKey = (string) ($row['OptionKey'] ?? '');
            if ($optionRecordId < 1 || $optionPosition < 1) {
                throw new RuntimeException('storage_unavailable');
            }
            if (!isset($positions[$optionPosition])) {
                $positions[$optionPosition] = count($product['options']);
                $product['options'][] = [
                    'key' => $optionKey,
                    'label' => (string) ($row['OptionLabel'] ?? ''),
                    'values' => [],
                ];
                $optionRecordToKey[$optionRecordId] = $optionKey;
            } elseif (($optionRecordToKey[$optionRecordId] ?? '') !== $optionKey) {
                throw new RuntimeException('storage_unavailable');
            }
            if ($row['ValueRecordID'] !== null) {
                $valueRecordId = (int) $row['ValueRecordID'];
                $valueId = (string) ($row['ValueID'] ?? '');
                $index = $positions[$optionPosition];
                $product['options'][$index]['values'][] = [
                    'id' => $valueId,
                    'label' => (string) ($row['ValueLabel'] ?? ''),
                ];
                $valueRecords[$optionRecordId][$valueRecordId] = $valueId;
            }
        }
        mysqli_free_result($query);
        mysqli_stmt_close($statement);
        return [
            'optionKeys' => $optionRecordToKey,
            'valueIds' => $valueRecords,
        ];
    }

    private static function readVariants(
        mysqli $connection,
        int $productRecordId,
        array $optionMaps,
        array &$product
    ): void {
        $statement = mysqli_prepare(
            $connection,
            'SELECT variants.RecordID AS VariantRecordID, variants.VariantID,
                    variants.SKU, HEX(variants.OptionTupleSHA256) AS TupleSHA256,
                    variants.Position AS VariantPosition, variants.PriceMinor,
                    variants.Availability, variants.Stock, variants.ImageReference,
                    selections.OptionRecordID, selections.OptionValueRecordID
             FROM RED_Addon_StoreLite_Product_Variants AS variants
             LEFT JOIN RED_Addon_StoreLite_Product_Variant_Selections AS selections
               ON selections.ProductRecordID=variants.ProductRecordID
              AND selections.VariantRecordID=variants.RecordID
             LEFT JOIN RED_Addon_StoreLite_Product_Options AS options
               ON options.ProductRecordID=selections.ProductRecordID
              AND options.RecordID=selections.OptionRecordID
             WHERE variants.ProductRecordID=?
             ORDER BY variants.Position ASC, options.Position ASC'
        );
        if (!$statement) {
            throw new RuntimeException('storage_unavailable');
        }
        mysqli_stmt_bind_param($statement, 'i', $productRecordId);
        if (!mysqli_stmt_execute($statement)) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('storage_unavailable');
        }
        $query = mysqli_stmt_get_result($statement);
        if (!$query) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('storage_unavailable');
        }

        $variantIndexes = [];
        $tupleHashes = [];
        while ($row = mysqli_fetch_assoc($query)) {
            $variantRecordId = (int) ($row['VariantRecordID'] ?? 0);
            if ($variantRecordId < 1) {
                throw new RuntimeException('storage_unavailable');
            }
            if (!isset($variantIndexes[$variantRecordId])) {
                $variantIndexes[$variantRecordId] = count($product['variants']);
                $tupleHashes[$variantRecordId] = strtolower(
                    (string) ($row['TupleSHA256'] ?? '')
                );
                $product['variants'][] = [
                    'id' => (string) ($row['VariantID'] ?? ''),
                    'sku' => (string) ($row['SKU'] ?? ''),
                    'options' => [],
                    'priceMinor' => (int) ($row['PriceMinor'] ?? -1),
                    'availability' => (string) ($row['Availability'] ?? ''),
                    'stock' => $row['Stock'] === null ? null : (int) $row['Stock'],
                    'imageRef' => $row['ImageReference'] === null
                        ? null
                        : (string) $row['ImageReference'],
                ];
            }
            if ($row['OptionRecordID'] !== null) {
                $optionRecordId = (int) $row['OptionRecordID'];
                $valueRecordId = (int) ($row['OptionValueRecordID'] ?? 0);
                $optionKey = $optionMaps['optionKeys'][$optionRecordId] ?? null;
                $valueId = $optionMaps['valueIds'][$optionRecordId][$valueRecordId]
                    ?? null;
                if (!is_string($optionKey) || !is_string($valueId)) {
                    throw new RuntimeException('storage_unavailable');
                }
                $index = $variantIndexes[$variantRecordId];
                $product['variants'][$index]['options'][$optionKey] = $valueId;
            }
        }
        mysqli_free_result($query);
        mysqli_stmt_close($statement);

        foreach ($variantIndexes as $variantRecordId => $index) {
            $tuple = $product['variants'][$index]['options'];
            ksort($tuple, SORT_STRING);
            if (!hash_equals(
                self::tupleSha256($tuple),
                $tupleHashes[$variantRecordId] ?? ''
            )) {
                throw new RuntimeException('storage_unavailable');
            }
        }
    }

    private static function insertProduct(mysqli $connection, array $product): int
    {
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Products
                (ProductID, ProductType, Title, Summary, Currency, State,
                 Availability, ImageReference, SKU, PriceMinor, Stock)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$statement) {
            return 0;
        }
        mysqli_stmt_bind_param(
            $statement,
            'sssssssssii',
            $product['id'],
            $product['type'],
            $product['title'],
            $product['summary'],
            $product['currency'],
            $product['state'],
            $product['availability'],
            $product['imageRef'],
            $product['sku'],
            $product['priceMinor'],
            $product['stock']
        );
        $inserted = mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        return $inserted ? (int) mysqli_insert_id($connection) : 0;
    }

    private static function updateProduct(
        mysqli $connection,
        int $recordId,
        array $product
    ): void {
        $statement = mysqli_prepare(
            $connection,
            'UPDATE RED_Addon_StoreLite_Products
             SET ProductType=?, Title=?, Summary=?, Currency=?, State=?,
                 Availability=?, ImageReference=?, SKU=?, PriceMinor=?, Stock=?
             WHERE RecordID=? AND ProductID=?'
        );
        if (!$statement) {
            throw new RuntimeException('write_failed');
        }
        mysqli_stmt_bind_param(
            $statement,
            'ssssssssiiis',
            $product['type'],
            $product['title'],
            $product['summary'],
            $product['currency'],
            $product['state'],
            $product['availability'],
            $product['imageRef'],
            $product['sku'],
            $product['priceMinor'],
            $product['stock'],
            $recordId,
            $product['id']
        );
        $updated = mysqli_stmt_execute($statement);
        $affected = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);
        if (!$updated || $affected < 0 || $affected > 1) {
            throw new RuntimeException('write_failed');
        }
    }

    private static function deleteChildren(
        mysqli $connection,
        int $productRecordId
    ): void {
        $variantStatement = mysqli_prepare(
            $connection,
            'DELETE FROM RED_Addon_StoreLite_Product_Variants
             WHERE ProductRecordID=?'
        );
        if (!$variantStatement) {
            throw new RuntimeException('write_failed');
        }
        mysqli_stmt_bind_param($variantStatement, 'i', $productRecordId);
        $deleted = mysqli_stmt_execute($variantStatement);
        mysqli_stmt_close($variantStatement);
        if (!$deleted) {
            throw new RuntimeException('write_failed');
        }

        $optionStatement = mysqli_prepare(
            $connection,
            'DELETE FROM RED_Addon_StoreLite_Product_Options
             WHERE ProductRecordID=?'
        );
        if (!$optionStatement) {
            throw new RuntimeException('write_failed');
        }
        mysqli_stmt_bind_param($optionStatement, 'i', $productRecordId);
        $deleted = mysqli_stmt_execute($optionStatement);
        mysqli_stmt_close($optionStatement);
        if (!$deleted) {
            throw new RuntimeException('write_failed');
        }
    }

    private static function insertChildren(
        mysqli $connection,
        int $productRecordId,
        array $product
    ): void {
        if ($product['type'] === 'simple') {
            return;
        }

        $optionRecords = [];
        $valueRecords = [];
        $optionInsert = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Product_Options
                (ProductRecordID, OptionKey, Label, Position)
             VALUES (?, ?, ?, ?)'
        );
        $valueInsert = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Product_Option_Values
                (ProductRecordID, OptionRecordID, ValueID, Label, Position)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$optionInsert || !$valueInsert) {
            if ($optionInsert) {
                mysqli_stmt_close($optionInsert);
            }
            if ($valueInsert) {
                mysqli_stmt_close($valueInsert);
            }
            throw new RuntimeException('write_failed');
        }
        foreach ($product['options'] as $optionIndex => $option) {
            $position = $optionIndex + 1;
            mysqli_stmt_bind_param(
                $optionInsert,
                'issi',
                $productRecordId,
                $option['key'],
                $option['label'],
                $position
            );
            if (!mysqli_stmt_execute($optionInsert)) {
                mysqli_stmt_close($optionInsert);
                mysqli_stmt_close($valueInsert);
                throw new RuntimeException('write_failed');
            }
            $optionRecordId = (int) mysqli_insert_id($connection);
            $optionRecords[$option['key']] = $optionRecordId;
            foreach ($option['values'] as $valueIndex => $value) {
                $valuePosition = $valueIndex + 1;
                mysqli_stmt_bind_param(
                    $valueInsert,
                    'iissi',
                    $productRecordId,
                    $optionRecordId,
                    $value['id'],
                    $value['label'],
                    $valuePosition
                );
                if (!mysqli_stmt_execute($valueInsert)) {
                    mysqli_stmt_close($optionInsert);
                    mysqli_stmt_close($valueInsert);
                    throw new RuntimeException('write_failed');
                }
                $valueRecords[$option['key']][$value['id']] =
                    (int) mysqli_insert_id($connection);
            }
        }
        mysqli_stmt_close($optionInsert);
        mysqli_stmt_close($valueInsert);

        $variantInsert = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Product_Variants
                (ProductRecordID, VariantID, SKU, OptionTupleSHA256, Position,
                 PriceMinor, Availability, Stock, ImageReference)
             VALUES (?, ?, ?, UNHEX(?), ?, ?, ?, ?, ?)'
        );
        $selectionInsert = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Product_Variant_Selections
                (ProductRecordID, VariantRecordID, OptionRecordID,
                 OptionValueRecordID)
             VALUES (?, ?, ?, ?)'
        );
        if (!$variantInsert || !$selectionInsert) {
            if ($variantInsert) {
                mysqli_stmt_close($variantInsert);
            }
            if ($selectionInsert) {
                mysqli_stmt_close($selectionInsert);
            }
            throw new RuntimeException('write_failed');
        }
        foreach ($product['variants'] as $variantIndex => $variant) {
            $position = $variantIndex + 1;
            $tuple = $variant['options'];
            ksort($tuple, SORT_STRING);
            $tupleSha256 = self::tupleSha256($tuple);
            mysqli_stmt_bind_param(
                $variantInsert,
                'isssiisis',
                $productRecordId,
                $variant['id'],
                $variant['sku'],
                $tupleSha256,
                $position,
                $variant['priceMinor'],
                $variant['availability'],
                $variant['stock'],
                $variant['imageRef']
            );
            if (!mysqli_stmt_execute($variantInsert)) {
                mysqli_stmt_close($variantInsert);
                mysqli_stmt_close($selectionInsert);
                throw new RuntimeException('write_failed');
            }
            $variantRecordId = (int) mysqli_insert_id($connection);
            foreach ($variant['options'] as $optionKey => $valueId) {
                $optionRecordId = $optionRecords[$optionKey] ?? 0;
                $valueRecordId = $valueRecords[$optionKey][$valueId] ?? 0;
                mysqli_stmt_bind_param(
                    $selectionInsert,
                    'iiii',
                    $productRecordId,
                    $variantRecordId,
                    $optionRecordId,
                    $valueRecordId
                );
                if ($optionRecordId < 1
                    || $valueRecordId < 1
                    || !mysqli_stmt_execute($selectionInsert)
                ) {
                    mysqli_stmt_close($variantInsert);
                    mysqli_stmt_close($selectionInsert);
                    throw new RuntimeException('write_failed');
                }
            }
        }
        mysqli_stmt_close($variantInsert);
        mysqli_stmt_close($selectionInsert);
    }

    private static function transactionTablesAvailable(mysqli $connection): bool
    {
        try {
            $escaped = array_map(
                static fn(string $table): string => "'" . $table . "'",
                self::TABLES
            );
            $query = mysqli_query(
                $connection,
                'SELECT COUNT(*) AS TableCount,
                        SUM(ENGINE=\'InnoDB\') AS InnoDBCount
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA=DATABASE()
                   AND TABLE_NAME IN (' . implode(', ', $escaped) . ')'
            );
            $row = $query ? mysqli_fetch_assoc($query) : null;
            if ($query) {
                mysqli_free_result($query);
            }
            return is_array($row)
                && (int) ($row['TableCount'] ?? 0) === count(self::TABLES)
                && (int) ($row['InnoDBCount'] ?? 0) === count(self::TABLES);
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            if (!mysqli_query($connection, 'SAVEPOINT redcms_store_lite_guard')) {
                return false;
            }
            return mysqli_query(
                $connection,
                'RELEASE SAVEPOINT redcms_store_lite_guard'
            ) === true;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function stateSha256(array $product): string
    {
        $encoded = json_encode(
            $product,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        return hash('sha256', $encoded);
    }

    private static function tupleSha256(array $tuple): string
    {
        ksort($tuple, SORT_STRING);
        $encoded = json_encode(
            $tuple,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return hash('sha256', $encoded);
    }

    private static function validProductId(string $value): bool
    {
        return preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) === 1;
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

    private static function readResult(string $status): array
    {
        return [
            'status' => $status,
            'recordId' => 0,
            'product' => null,
            'stateSha256' => '',
        ];
    }

    private static function writeResult(string $status): array
    {
        return [
            'status' => $status,
            'productId' => '',
            'previousStateSha256' => '',
            'targetStateSha256' => '',
            'stateSha256' => '',
        ];
    }
}
