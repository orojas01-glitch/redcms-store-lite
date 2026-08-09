<?php

declare(strict_types=1);

require_once __DIR__ . '/CatalogPersistence.php';
require_once __DIR__ . '/CartLineResolver.php';

/**
 * Internal Store Lite cart persistence for a future core transaction runner.
 *
 * Writes require an already-active caller-owned InnoDB transaction. This
 * class never reads a cookie/token/request, begins/commits/rolls back a
 * transaction, registers a service, or emits a response.
 */
final class RED_CMS_Store_Lite_Cart_Persistence
{
    public const TABLES = [
        'RED_Addon_StoreLite_Products',
        'RED_Addon_StoreLite_Product_Options',
        'RED_Addon_StoreLite_Product_Option_Values',
        'RED_Addon_StoreLite_Product_Variants',
        'RED_Addon_StoreLite_Product_Variant_Selections',
        'RED_Addon_StoreLite_Carts',
        'RED_Addon_StoreLite_Cart_Lines',
        'RED_Addon_StoreLite_Cart_Activity',
    ];

    public static function read(
        mysqli $connection,
        int $subjectRecordId,
        string $installationCurrency
    ): array {
        if (!self::validSubject($subjectRecordId)
            || !self::validCurrency($installationCurrency)
            || !self::tablesAvailable($connection)
        ) {
            return self::readResult('invalid');
        }
        return self::readState(
            $connection,
            $subjectRecordId,
            $installationCurrency,
            false
        );
    }

    /**
     * Adds the requested quantity to one exact product/variant line.
     *
     * The caller owns commit/rollback. Any result other than created/updated
     * requires rollback because a late failure may follow provisional writes.
     */
    public static function addLineWithinTransaction(
        mysqli $connection,
        int $subjectRecordId,
        string $installationCurrency,
        array $intent,
        string $expectedCartStateSha256
    ): array {
        $result = self::writeResult('invalid');
        if (!self::validSubject($subjectRecordId)
            || !self::validCurrency($installationCurrency)
            || !self::validSha256($expectedCartStateSha256)
            || !self::tablesAvailable($connection)
            || !self::transactionActive($connection)
        ) {
            return $result;
        }

        try {
            $current = self::readState(
                $connection,
                $subjectRecordId,
                $installationCurrency,
                true
            );
            if (!in_array($current['status'], ['empty', 'found'], true)) {
                $result['status'] = 'storage_unavailable';
                return $result;
            }
            $result['previousStateSha256'] = $current['stateSha256'];
            if (!hash_equals(
                $current['stateSha256'],
                $expectedCartStateSha256
            )) {
                $result['status'] = 'stale_state';
                return $result;
            }

            $intentProduct = is_string($intent['product'] ?? null)
                ? $intent['product']
                : '';
            $productRecordId = self::productRecordIdForUpdate(
                $connection,
                $intentProduct
            );
            if ($productRecordId < 1) {
                $result['status'] = 'product_unavailable';
                return $result;
            }
            $stored = RED_CMS_Store_Lite_Catalog_Persistence::readByRecordId(
                $connection,
                $productRecordId,
                $installationCurrency
            );
            if (($stored['status'] ?? '') !== 'found'
                || !is_array($stored['product'] ?? null)
            ) {
                $result['status'] = 'product_unavailable';
                return $result;
            }

            $initialResolution =
                RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
                    $stored['product'],
                    $installationCurrency,
                    $intent
                );
            if (empty($initialResolution['resolved'])
                || !is_array($initialResolution['line'] ?? null)
            ) {
                $result['status'] = self::resolutionFailureStatus(
                    $initialResolution
                );
                return $result;
            }
            $variantId = $initialResolution['line']['variantId'];
            $lineIdentitySha256 = self::lineIdentitySha256(
                $stored['product']['id'],
                $variantId
            );
            if (!self::validSha256($lineIdentitySha256)) {
                $result['status'] = 'product_unavailable';
                return $result;
            }
            $result['lineIdentitySha256'] = $lineIdentitySha256;

            $cartRecordId = $current['cartRecordId'];
            $existing = $cartRecordId > 0
                ? self::lineForUpdate(
                    $connection,
                    $cartRecordId,
                    $lineIdentitySha256
                )
                : null;
            if ($existing === false) {
                $result['status'] = 'storage_unavailable';
                return $result;
            }
            $addedQuantity = is_int($intent['quantity'] ?? null)
                ? $intent['quantity']
                : 0;
            $currentQuantity = is_array($existing)
                ? (int) $existing['Quantity']
                : 0;
            if ($addedQuantity < 1
                || $addedQuantity > 100
                || $currentQuantity < 0
                || $currentQuantity > 100
                || $currentQuantity + $addedQuantity > 100
            ) {
                $result['status'] = 'quantity_unavailable';
                return $result;
            }
            $resolvedIntent = [
                'product' => $stored['product']['id'],
                'quantity' => $currentQuantity + $addedQuantity,
            ];
            if ($variantId !== null) {
                $resolvedIntent['variant'] = $variantId;
            }
            $resolved = RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
                $stored['product'],
                $installationCurrency,
                $resolvedIntent
            );
            if (empty($resolved['resolved'])
                || !is_array($resolved['line'] ?? null)
            ) {
                $result['status'] = self::resolutionFailureStatus($resolved);
                return $result;
            }
            $line = $resolved['line'];
            $variantRecordId = $line['variantId'] === null
                ? null
                : self::variantRecordIdForUpdate(
                    $connection,
                    $productRecordId,
                    $line['variantId']
                );
            if ($line['variantId'] !== null
                && (!is_int($variantRecordId) || $variantRecordId < 1)
            ) {
                $result['status'] = 'variant_unavailable';
                return $result;
            }
            if (is_array($existing)
                && ((int) $existing['ProductRecordID'] !== $productRecordId
                    || self::nullableInt($existing['VariantRecordID'])
                        !== $variantRecordId)
            ) {
                $result['status'] = 'line_conflict';
                return $result;
            }

            if ($cartRecordId < 1) {
                $cartRecordId = self::createCart(
                    $connection,
                    $subjectRecordId,
                    $installationCurrency
                );
                if ($cartRecordId < 1) {
                    $result['status'] = 'write_failed';
                    return $result;
                }
            }
            $result['cartRecordId'] = $cartRecordId;
            $written = is_array($existing)
                ? self::updateLine(
                    $connection,
                    (int) $existing['RecordID'],
                    $productRecordId,
                    $variantRecordId,
                    $lineIdentitySha256,
                    $line
                )
                : self::insertLine(
                    $connection,
                    $cartRecordId,
                    $productRecordId,
                    $variantRecordId,
                    $lineIdentitySha256,
                    $line
                );
            if (!$written || !self::touchCart($connection, $cartRecordId)) {
                $result['status'] = 'write_failed';
                return $result;
            }

            $post = self::readState(
                $connection,
                $subjectRecordId,
                $installationCurrency,
                true
            );
            if ($post['status'] !== 'found'
                || $post['cartRecordId'] !== $cartRecordId
                || !self::validSha256($post['stateSha256'])
                || hash_equals($current['stateSha256'], $post['stateSha256'])
                || !self::lineMatches(
                    $connection,
                    $cartRecordId,
                    $lineIdentitySha256,
                    $productRecordId,
                    $variantRecordId,
                    $line
                )
            ) {
                $result['status'] = 'postcondition_failed';
                return $result;
            }
            $eventName = is_array($existing)
                ? 'cart.line.updated'
                : 'cart.line.created';
            if (!self::recordActivity(
                $connection,
                $eventName,
                $cartRecordId,
                $subjectRecordId,
                $lineIdentitySha256,
                $current['stateSha256'],
                $post['stateSha256']
            )) {
                $result['status'] = 'activity_failed';
                return $result;
            }
            if (!self::transactionActive($connection)) {
                $result['status'] = 'transaction_lost';
                return $result;
            }

            $result['status'] = is_array($existing) ? 'updated' : 'created';
            $result['stateSha256'] = $post['stateSha256'];
            return $result;
        } catch (Throwable $throwable) {
            $result['status'] = 'write_failed';
            return $result;
        }
    }

    private static function readState(
        mysqli $connection,
        int $subjectRecordId,
        string $currency,
        bool $lock
    ): array {
        try {
            $statement = mysqli_prepare(
                $connection,
                'SELECT RecordID, Currency
                 FROM RED_Addon_StoreLite_Carts
                 WHERE SubjectRecordID=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
            );
            if (!$statement) {
                return self::readResult('storage_unavailable');
            }
            mysqli_stmt_bind_param($statement, 'i', $subjectRecordId);
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
                $stateSha256 = self::cartStateSha256($currency, []);
                $result = self::readResult('empty');
                $result['stateSha256'] = $stateSha256;
                return $result;
            }
            $cartRecordId = (int) ($row['RecordID'] ?? 0);
            if ($cartRecordId < 1
                || !is_string($row['Currency'] ?? null)
                || !hash_equals($currency, $row['Currency'])
            ) {
                return self::readResult('storage_unavailable');
            }

            if ($lock && !self::lockCartLines($connection, $cartRecordId)) {
                return self::readResult('storage_unavailable');
            }

            $sql = 'SELECT HEX(cart_lines.LineIdentitySHA256) AS LineIdentitySHA256,
                           products.ProductID,
                           variants.VariantID,
                           cart_lines.Quantity,
                           cart_lines.UnitPriceMinor,
                           cart_lines.Currency,
                           cart_lines.LineTotalMinor,
                           HEX(cart_lines.ProductStateSHA256) AS ProductStateSHA256
                    FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
                    INNER JOIN RED_Addon_StoreLite_Products AS products
                      ON products.RecordID=cart_lines.ProductRecordID
                    LEFT JOIN RED_Addon_StoreLite_Product_Variants AS variants
                      ON variants.ProductRecordID=cart_lines.ProductRecordID
                     AND variants.RecordID=cart_lines.VariantRecordID
                    WHERE cart_lines.CartRecordID=?
                    ORDER BY cart_lines.LineIdentitySHA256';
            $statement = mysqli_prepare($connection, $sql);
            if (!$statement) {
                return self::readResult('storage_unavailable');
            }
            mysqli_stmt_bind_param($statement, 'i', $cartRecordId);
            $executed = mysqli_stmt_execute($statement);
            $query = $executed ? mysqli_stmt_get_result($statement) : false;
            $lines = [];
            while ($query && ($line = mysqli_fetch_assoc($query))) {
                $normalized = self::stateLine($line, $currency);
                if ($normalized === null) {
                    if ($query) {
                        mysqli_free_result($query);
                    }
                    mysqli_stmt_close($statement);
                    return self::readResult('storage_unavailable');
                }
                $lines[] = $normalized;
            }
            if ($query) {
                mysqli_free_result($query);
            }
            mysqli_stmt_close($statement);
            if (!$executed) {
                return self::readResult('storage_unavailable');
            }
            $stateSha256 = self::cartStateSha256($currency, $lines);
            if (!self::validSha256($stateSha256)) {
                return self::readResult('storage_unavailable');
            }
            $result = self::readResult('found');
            $result['cartRecordId'] = $cartRecordId;
            $result['lineCount'] = count($lines);
            $result['stateSha256'] = $stateSha256;
            return $result;
        } catch (Throwable $throwable) {
            return self::readResult('storage_unavailable');
        }
    }

    private static function stateLine(array $row, string $currency): ?array
    {
        $identity = strtolower((string) ($row['LineIdentitySHA256'] ?? ''));
        $productId = (string) ($row['ProductID'] ?? '');
        $variantId = $row['VariantID'] === null
            ? null
            : (string) $row['VariantID'];
        $quantity = (int) ($row['Quantity'] ?? 0);
        $unitPriceMinor = (int) ($row['UnitPriceMinor'] ?? -1);
        $lineCurrency = (string) ($row['Currency'] ?? '');
        $lineTotalMinor = (int) ($row['LineTotalMinor'] ?? -1);
        $productState = strtolower(
            (string) ($row['ProductStateSHA256'] ?? '')
        );
        if (!self::validSha256($identity)
            || !self::validIdentifier($productId)
            || ($variantId !== null && !self::validIdentifier($variantId))
            || $identity !== self::lineIdentitySha256($productId, $variantId)
            || $quantity < 1
            || $quantity > 100
            || $unitPriceMinor < 0
            || $unitPriceMinor > 999999999
            || !hash_equals($currency, $lineCurrency)
            || $lineTotalMinor !== $unitPriceMinor * $quantity
            || $lineTotalMinor > 99999999900
            || !self::validSha256($productState)
        ) {
            return null;
        }
        return [
            'lineIdentitySha256' => $identity,
            'productId' => $productId,
            'variantId' => $variantId,
            'quantity' => $quantity,
            'unitPriceMinor' => $unitPriceMinor,
            'currency' => $currency,
            'lineTotalMinor' => $lineTotalMinor,
            'productStateSha256' => $productState,
        ];
    }

    private static function productRecordIdForUpdate(
        mysqli $connection,
        string $productId
    ): int {
        if (!self::validIdentifier($productId)) {
            return 0;
        }
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID FROM RED_Addon_StoreLite_Products
             WHERE BINARY ProductID=BINARY ? LIMIT 1 FOR UPDATE'
        );
        if (!$statement) {
            return 0;
        }
        mysqli_stmt_bind_param($statement, 's', $productId);
        $executed = mysqli_stmt_execute($statement);
        $query = $executed ? mysqli_stmt_get_result($statement) : false;
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return $executed && is_array($row)
            ? (int) ($row['RecordID'] ?? 0)
            : 0;
    }

    private static function variantRecordIdForUpdate(
        mysqli $connection,
        int $productRecordId,
        string $variantId
    ): int {
        if ($productRecordId < 1 || !self::validIdentifier($variantId)) {
            return 0;
        }
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID FROM RED_Addon_StoreLite_Product_Variants
             WHERE ProductRecordID=? AND BINARY VariantID=BINARY ?
             LIMIT 1 FOR UPDATE'
        );
        if (!$statement) {
            return 0;
        }
        mysqli_stmt_bind_param($statement, 'is', $productRecordId, $variantId);
        $executed = mysqli_stmt_execute($statement);
        $query = $executed ? mysqli_stmt_get_result($statement) : false;
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return $executed && is_array($row)
            ? (int) ($row['RecordID'] ?? 0)
            : 0;
    }

    private static function lineForUpdate(
        mysqli $connection,
        int $cartRecordId,
        string $identitySha256
    ): array|false|null {
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID, ProductRecordID, VariantRecordID, Quantity
             FROM RED_Addon_StoreLite_Cart_Lines
             WHERE CartRecordID=? AND LineIdentitySHA256=UNHEX(?)
             LIMIT 1 FOR UPDATE'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param($statement, 'is', $cartRecordId, $identitySha256);
        $executed = mysqli_stmt_execute($statement);
        $query = $executed ? mysqli_stmt_get_result($statement) : false;
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return $executed ? (is_array($row) ? $row : null) : false;
    }

    private static function lockCartLines(
        mysqli $connection,
        int $cartRecordId
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID
             FROM RED_Addon_StoreLite_Cart_Lines
             WHERE CartRecordID=?
             ORDER BY RecordID FOR UPDATE'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param($statement, 'i', $cartRecordId);
        $executed = mysqli_stmt_execute($statement);
        $query = $executed ? mysqli_stmt_get_result($statement) : false;
        if ($query) {
            while (mysqli_fetch_row($query)) {
                // Fetch all locked rows before closing the statement.
            }
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return $executed;
    }

    private static function createCart(
        mysqli $connection,
        int $subjectRecordId,
        string $currency
    ): int {
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Carts
                (SubjectRecordID, Currency) VALUES (?, ?)'
        );
        if (!$statement) {
            return 0;
        }
        mysqli_stmt_bind_param($statement, 'is', $subjectRecordId, $currency);
        $executed = mysqli_stmt_execute($statement);
        $recordId = $executed ? (int) mysqli_insert_id($connection) : 0;
        mysqli_stmt_close($statement);
        return $recordId;
    }

    private static function insertLine(
        mysqli $connection,
        int $cartRecordId,
        int $productRecordId,
        ?int $variantRecordId,
        string $identitySha256,
        array $line
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Cart_Lines
                (CartRecordID, ProductRecordID, VariantRecordID,
                 LineIdentitySHA256, Quantity, UnitPriceMinor, Currency,
                 LineTotalMinor, ProductStateSHA256)
             VALUES (?, ?, ?, UNHEX(?), ?, ?, ?, ?, UNHEX(?))'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param(
            $statement,
            'iiisiisis',
            $cartRecordId,
            $productRecordId,
            $variantRecordId,
            $identitySha256,
            $line['quantity'],
            $line['unitPriceMinor'],
            $line['currency'],
            $line['lineTotalMinor'],
            $line['productStateSha256']
        );
        $executed = mysqli_stmt_execute($statement);
        $affected = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);
        return $executed && $affected === 1;
    }

    private static function updateLine(
        mysqli $connection,
        int $lineRecordId,
        int $productRecordId,
        ?int $variantRecordId,
        string $identitySha256,
        array $line
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'UPDATE RED_Addon_StoreLite_Cart_Lines
             SET ProductRecordID=?, VariantRecordID=?,
                 LineIdentitySHA256=UNHEX(?), Quantity=?, UnitPriceMinor=?,
                 Currency=?, LineTotalMinor=?, ProductStateSHA256=UNHEX(?)
             WHERE RecordID=?'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param(
            $statement,
            'iisiisisi',
            $productRecordId,
            $variantRecordId,
            $identitySha256,
            $line['quantity'],
            $line['unitPriceMinor'],
            $line['currency'],
            $line['lineTotalMinor'],
            $line['productStateSha256'],
            $lineRecordId
        );
        $executed = mysqli_stmt_execute($statement);
        $affected = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);
        return $executed && $affected === 1;
    }

    private static function touchCart(
        mysqli $connection,
        int $cartRecordId
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'UPDATE RED_Addon_StoreLite_Carts
             SET UpdatedAt=UTC_TIMESTAMP()
             WHERE RecordID=?'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param($statement, 'i', $cartRecordId);
        $executed = mysqli_stmt_execute($statement);
        $matched = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);
        return $executed && $matched >= 0;
    }

    private static function lineMatches(
        mysqli $connection,
        int $cartRecordId,
        string $identitySha256,
        int $productRecordId,
        ?int $variantRecordId,
        array $line
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'SELECT COUNT(*) AS Matches
             FROM RED_Addon_StoreLite_Cart_Lines
             WHERE CartRecordID=?
               AND ProductRecordID=?
               AND VariantRecordID <=> ?
               AND LineIdentitySHA256=UNHEX(?)
               AND Quantity=?
               AND UnitPriceMinor=?
               AND BINARY Currency=BINARY ?
               AND LineTotalMinor=?
               AND ProductStateSHA256=UNHEX(?)'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param(
            $statement,
            'iiisiisis',
            $cartRecordId,
            $productRecordId,
            $variantRecordId,
            $identitySha256,
            $line['quantity'],
            $line['unitPriceMinor'],
            $line['currency'],
            $line['lineTotalMinor'],
            $line['productStateSha256']
        );
        $executed = mysqli_stmt_execute($statement);
        $query = $executed ? mysqli_stmt_get_result($statement) : false;
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return $executed && (int) ($row['Matches'] ?? 0) === 1;
    }

    private static function recordActivity(
        mysqli $connection,
        string $eventName,
        int $cartRecordId,
        int $subjectRecordId,
        string $lineIdentitySha256,
        string $previousStateSha256,
        string $stateSha256
    ): bool {
        try {
            $statement = mysqli_prepare(
                $connection,
                'INSERT INTO RED_Addon_StoreLite_Cart_Activity
                    (EventName, CartRecordID, SubjectRecordID,
                     LineIdentitySHA256, PreviousStateSHA256, StateSHA256)
                 VALUES (?, ?, ?, UNHEX(?), UNHEX(?), UNHEX(?))'
            );
            if (!$statement) {
                return false;
            }
            mysqli_stmt_bind_param(
                $statement,
                'siisss',
                $eventName,
                $cartRecordId,
                $subjectRecordId,
                $lineIdentitySha256,
                $previousStateSha256,
                $stateSha256
            );
            $executed = mysqli_stmt_execute($statement);
            $affected = mysqli_stmt_affected_rows($statement);
            mysqli_stmt_close($statement);
            return $executed && $affected === 1;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function tablesAvailable(mysqli $connection): bool
    {
        try {
            $escaped = array_map(
                static fn(string $table): string => "'" . $table . "'",
                self::TABLES
            );
            $query = mysqli_query(
                $connection,
                'SELECT COUNT(*) AS TableCount,
                        COALESCE(SUM(ENGINE=\'InnoDB\'), 0) AS InnoDBCount
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
            if (!mysqli_query($connection, 'SAVEPOINT redcms_store_lite_cart_guard')) {
                return false;
            }
            return mysqli_query(
                $connection,
                'RELEASE SAVEPOINT redcms_store_lite_cart_guard'
            ) === true;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function cartStateSha256(
        string $currency,
        array $lines
    ): string {
        try {
            return hash('sha256', json_encode(
                ['currency' => $currency, 'lines' => $lines],
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ));
        } catch (Throwable $throwable) {
            return '';
        }
    }

    private static function lineIdentitySha256(
        string $productId,
        ?string $variantId
    ): string {
        if (!self::validIdentifier($productId)
            || ($variantId !== null && !self::validIdentifier($variantId))
        ) {
            return '';
        }
        try {
            return hash('sha256', json_encode(
                ['productId' => $productId, 'variantId' => $variantId],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        } catch (Throwable $throwable) {
            return '';
        }
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function resolutionFailureStatus(array $result): string
    {
        $reason = (string) ($result['reason'] ?? 'line_unavailable');
        return in_array($reason, [
            'invalid_intent', 'product_unavailable', 'variant_required',
            'variant_unavailable', 'insufficient_stock',
        ], true) ? $reason : 'line_unavailable';
    }

    private static function validSubject(int $value): bool
    {
        return $value >= 1 && $value <= 4294967295;
    }

    private static function validCurrency(string $value): bool
    {
        return preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }

    private static function validIdentifier(string $value): bool
    {
        return preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) === 1;
    }

    private static function validSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function readResult(string $status): array
    {
        return [
            'status' => $status,
            'cartRecordId' => 0,
            'lineCount' => 0,
            'stateSha256' => '',
        ];
    }

    private static function writeResult(string $status): array
    {
        return [
            'status' => $status,
            'cartRecordId' => 0,
            'lineIdentitySha256' => '',
            'previousStateSha256' => '',
            'stateSha256' => '',
        ];
    }
}
