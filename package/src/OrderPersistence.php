<?php

declare(strict_types=1);

require_once __DIR__ . '/CatalogPersistence.php';
require_once __DIR__ . '/CartLineResolver.php';
require_once __DIR__ . '/GuestOrderSnapshot.php';

/**
 * Internal Store Lite immutable guest-order persistence.
 *
 * The caller owns the active transaction and commit/rollback decision. This
 * class reads no request/cookie/session state, registers no runtime surface,
 * invokes no payment provider, and emits no output.
 */
final class RED_CMS_Store_Lite_Order_Persistence
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
        'RED_Addon_StoreLite_Orders',
        'RED_Addon_StoreLite_Order_Lines',
        'RED_Addon_StoreLite_Order_Line_Options',
        'RED_Addon_StoreLite_Order_Status_History',
    ];

    public static function createWithinTransaction(
        mysqli $connection,
        int $subjectRecordId,
        array $configuration,
        array $proposedResult,
        string $idempotencyKeySha256
    ): array {
        $result = self::result('invalid');
        $proposal = self::proposal($proposedResult, $configuration);
        if (!self::validSubject($subjectRecordId)
            || !self::validSha256($idempotencyKeySha256)
            || $proposal === null
            || !self::transactionActive($connection)
            || !self::tablesAvailable($connection)
        ) {
            return $result;
        }

        try {
            $existing = self::existingByIdempotency(
                $connection,
                $idempotencyKeySha256
            );
            if ($existing === false) {
                return self::result('storage_unavailable');
            }
            if (is_array($existing)) {
                if ((int) ($existing['subjectRecordId'] ?? 0)
                        !== $subjectRecordId
                    || !hash_equals(
                        $proposal['result']['snapshotSha256'],
                        (string) ($existing['result']['snapshotSha256'] ?? '')
                    )
                    || !hash_equals(
                        $proposal['result']['sourceCartStateSha256'],
                        (string) ($existing['result']['sourceCartStateSha256'] ?? '')
                    )
                ) {
                    return self::result('idempotency_conflict');
                }
                if ($existing['result'] !== $proposal['result']) {
                    return self::result('storage_unavailable');
                }
                return self::success(
                    'replayed',
                    $existing['orderId'],
                    $proposal['result']
                );
            }

            $lockedCart = self::lockedCart(
                $connection,
                $subjectRecordId,
                $configuration['currency'] ?? null
            );
            if (($lockedCart['status'] ?? '') !== 'found') {
                return self::result((string) ($lockedCart['status'] ?? 'cart_unavailable'));
            }
            if (!hash_equals(
                $proposal['result']['sourceCartStateSha256'],
                $lockedCart['cart']['stateSha256']
            )) {
                return self::result('stale_cart');
            }
            $rebuilt = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
                $lockedCart['cart'],
                $proposal['checkout'],
                $configuration
            );
            if ($rebuilt !== $proposal['result']) {
                return self::result('stale_cart');
            }

            $orderId = self::newOrderId();
            if ($orderId === '') {
                return self::result('write_failed');
            }
            $orderRecordId = self::insertOrder(
                $connection,
                $orderId,
                $lockedCart['cartRecordId'],
                $subjectRecordId,
                $idempotencyKeySha256,
                $proposal['result']
            );
            if ($orderRecordId < 1
                || !self::insertLines(
                    $connection,
                    $orderRecordId,
                    $proposal['result']['snapshot']['lines']
                )
                || !self::insertCreatedHistory(
                    $connection,
                    $orderRecordId,
                    $subjectRecordId,
                    $proposal['result']
                )
                || !self::consumeCart(
                    $connection,
                    $lockedCart['cartRecordId'],
                    $subjectRecordId
                )
            ) {
                return self::result('write_failed');
            }

            $stored = self::storedOrder($connection, $orderRecordId, true);
            if (!is_array($stored)
                || $stored['orderId'] !== $orderId
                || $stored['subjectRecordId'] !== $subjectRecordId
                || $stored['idempotencyKeySha256'] !== $idempotencyKeySha256
                || $stored['sourceCartRecordId'] !== $lockedCart['cartRecordId']
                || $stored['result'] !== $proposal['result']
                || !self::cartAbsent(
                    $connection,
                    $lockedCart['cartRecordId'],
                    $subjectRecordId
                )
            ) {
                return self::result('postcondition_failed');
            }
            if (!self::transactionActive($connection)) {
                return self::result('transaction_lost');
            }
            return self::success('created', $orderId, $proposal['result']);
        } catch (Throwable $throwable) {
            return self::result('write_failed');
        }
    }

    private static function proposal(
        array $proposedResult,
        array $configuration
    ): ?array {
        if (array_keys($proposedResult) !== [
            'valid', 'snapshot', 'snapshotSha256',
            'sourceCartStateSha256', 'initialState', 'errors',
        ]
            || ($proposedResult['valid'] ?? null) !== true
            || !is_array($proposedResult['snapshot'] ?? null)
            || !self::validSha256($proposedResult['snapshotSha256'] ?? null)
            || !self::validSha256($proposedResult['sourceCartStateSha256'] ?? null)
            || !is_array($proposedResult['initialState'] ?? null)
            || ($proposedResult['errors'] ?? null) !== []
        ) {
            return null;
        }
        $snapshot = $proposedResult['snapshot'];
        if (array_keys($snapshot) !== [
            'version', 'currency', 'customer', 'fulfillment', 'payment',
            'lines', 'quantityTotal', 'subtotalMinor', 'totalMinor',
        ]
            || !is_array($snapshot['customer'] ?? null)
            || !is_array($snapshot['fulfillment'] ?? null)
            || !is_array($snapshot['payment'] ?? null)
            || !is_array($snapshot['lines'] ?? null)
        ) {
            return null;
        }
        $checkout = [
            'customer' => $snapshot['customer'],
            'fulfillmentMethod' => $snapshot['fulfillment']['method'] ?? null,
            'deliveryAddress' =>
                $snapshot['fulfillment']['deliveryAddress'] ?? null,
            'paymentMethod' => $snapshot['payment']['method'] ?? null,
        ];
        $cart = [
            'stateSha256' => $proposedResult['sourceCartStateSha256'],
            'currency' => $snapshot['currency'] ?? null,
            'lines' => $snapshot['lines'],
        ];
        $rebuilt = RED_CMS_Store_Lite_Guest_Order_Snapshot::build(
            $cart,
            $checkout,
            $configuration
        );
        return $rebuilt === $proposedResult
            ? ['result' => $rebuilt, 'checkout' => $checkout]
            : null;
    }

    private static function lockedCart(
        mysqli $connection,
        int $subjectRecordId,
        mixed $currency
    ): array {
        if (!is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
        ) {
            return ['status' => 'cart_unavailable'];
        }
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID, Currency FROM RED_Addon_StoreLite_Carts
             WHERE SubjectRecordID=? LIMIT 1 FOR UPDATE'
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$subjectRecordId])) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return ['status' => 'storage_unavailable'];
        }
        $query = mysqli_stmt_get_result($statement);
        $cartRow = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        if (!is_array($cartRow)) {
            return ['status' => 'cart_unavailable'];
        }
        $cartRecordId = (int) ($cartRow['RecordID'] ?? 0);
        if ($cartRecordId < 1 || ($cartRow['Currency'] ?? null) !== $currency) {
            return ['status' => 'storage_unavailable'];
        }

        $statement = mysqli_prepare(
            $connection,
            'SELECT cart_lines.ProductRecordID, cart_lines.VariantRecordID,
                    LOWER(HEX(cart_lines.LineIdentitySHA256)) AS LineIdentitySha256,
                    products.ProductID, variants.VariantID,
                    cart_lines.Quantity, cart_lines.UnitPriceMinor,
                    cart_lines.Currency, cart_lines.LineTotalMinor,
                    LOWER(HEX(cart_lines.ProductStateSHA256)) AS ProductStateSha256
             FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines
             INNER JOIN RED_Addon_StoreLite_Products AS products
               ON products.RecordID=cart_lines.ProductRecordID
             LEFT JOIN RED_Addon_StoreLite_Product_Variants AS variants
               ON variants.ProductRecordID=cart_lines.ProductRecordID
              AND variants.RecordID=cart_lines.VariantRecordID
             WHERE cart_lines.CartRecordID=?
             ORDER BY cart_lines.LineIdentitySHA256 FOR UPDATE'
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$cartRecordId])) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return ['status' => 'storage_unavailable'];
        }
        $query = mysqli_stmt_get_result($statement);
        $rows = [];
        while ($query && ($row = mysqli_fetch_assoc($query))) {
            $rows[] = $row;
        }
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        if ($rows === [] || count($rows) > 24) {
            return ['status' => 'cart_unavailable'];
        }

        $stateLines = [];
        $snapshotLines = [];
        foreach ($rows as $row) {
            $productRecordId = (int) ($row['ProductRecordID'] ?? 0);
            $productId = $row['ProductID'] ?? null;
            $variantId = ($row['VariantRecordID'] ?? null) === null
                ? null
                : ($row['VariantID'] ?? null);
            $quantity = (int) ($row['Quantity'] ?? 0);
            $storedState = $row['ProductStateSha256'] ?? null;
            $storedIdentity = $row['LineIdentitySha256'] ?? null;
            if ($productRecordId < 1
                || !is_string($productId)
                || ($variantId !== null && !is_string($variantId))
                || !self::validSha256($storedState)
                || !self::validSha256($storedIdentity)
            ) {
                return ['status' => 'storage_unavailable'];
            }
            $storedProduct = RED_CMS_Store_Lite_Catalog_Persistence::readByRecordId(
                $connection,
                $productRecordId,
                $currency
            );
            if (($storedProduct['status'] ?? null) !== 'found'
                || !is_array($storedProduct['product'] ?? null)
            ) {
                return ['status' => 'cart_stale'];
            }
            $intent = ['product' => $productId, 'quantity' => $quantity];
            if ($variantId !== null) {
                $intent['variant'] = $variantId;
            }
            $resolved = RED_CMS_Store_Lite_Cart_Line_Resolver::resolve(
                $storedProduct['product'],
                $currency,
                $intent
            );
            if (empty($resolved['resolved'])
                || !is_array($resolved['line'] ?? null)
            ) {
                return ['status' => 'cart_stale'];
            }
            $line = $resolved['line'];
            $expectedIdentity = self::lineIdentitySha256(
                $line['productId'],
                $line['variantId']
            );
            if (!hash_equals($expectedIdentity, $storedIdentity)
                || !hash_equals($line['productStateSha256'], $storedState)
                || ($row['Currency'] ?? null) !== $currency
                || (int) ($row['UnitPriceMinor'] ?? -1) !== $line['unitPriceMinor']
                || (int) ($row['LineTotalMinor'] ?? -1) !== $line['lineTotalMinor']
            ) {
                return ['status' => 'cart_stale'];
            }
            $optionLabels = self::snapshotOptionLabels(
                $line['optionLabels'] ?? null
            );
            if ($optionLabels === null) {
                return ['status' => 'cart_stale'];
            }
            $stateLines[] = [
                'lineIdentitySha256' => $storedIdentity,
                'productId' => $line['productId'],
                'variantId' => $line['variantId'],
                'quantity' => $line['quantity'],
                'unitPriceMinor' => $line['unitPriceMinor'],
                'currency' => $currency,
                'lineTotalMinor' => $line['lineTotalMinor'],
                'productStateSha256' => $line['productStateSha256'],
            ];
            $snapshotLines[] = [
                'productId' => $line['productId'],
                'variantId' => $line['variantId'],
                'sku' => $line['sku'],
                'title' => $line['title'],
                'optionLabels' => $optionLabels,
                'quantity' => $line['quantity'],
                'unitPriceMinor' => $line['unitPriceMinor'],
                'currency' => $currency,
                'lineTotalMinor' => $line['lineTotalMinor'],
            ];
        }
        $stateSha256 = self::cartStateSha256($currency, $stateLines);
        if (!self::validSha256($stateSha256)) {
            return ['status' => 'storage_unavailable'];
        }
        return [
            'status' => 'found',
            'cartRecordId' => $cartRecordId,
            'cart' => [
                'stateSha256' => $stateSha256,
                'currency' => $currency,
                'lines' => $snapshotLines,
            ],
        ];
    }

    private static function insertOrder(
        mysqli $connection,
        string $orderId,
        int $cartRecordId,
        int $subjectRecordId,
        string $idempotencyKeySha256,
        array $result
    ): int {
        $snapshot = $result['snapshot'];
        $customer = $snapshot['customer'];
        $fulfillment = $snapshot['fulfillment'];
        $address = $fulfillment['deliveryAddress'];
        $payment = $snapshot['payment'];
        $initial = $result['initialState'];
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Orders
             (OrderID, SourceCartRecordID, SubjectRecordID,
              IdempotencyKeySHA256, SourceCartStateSHA256, SnapshotVersion,
              SnapshotSHA256, Currency, CustomerName, CustomerEmail,
              CustomerPhone, FulfillmentMethod, FulfillmentFeeMinor,
              DeliveryLine1, DeliveryLine2, DeliveryCity, DeliveryRegion,
              DeliveryPostalCode, DeliveryCountryCode, DeliveryInstructions,
              PaymentMethod, PaymentKind, OrderStatus, PaymentStatus,
              FulfillmentStatus, QuantityTotal, SubtotalMinor, TotalMinor)
             VALUES (?, ?, ?, UNHEX(?), UNHEX(?), ?, UNHEX(?), ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$statement) {
            return 0;
        }
        $parameters = [
            $orderId,
            $cartRecordId,
            $subjectRecordId,
            $idempotencyKeySha256,
            $result['sourceCartStateSha256'],
            $snapshot['version'],
            $result['snapshotSha256'],
            $snapshot['currency'],
            $customer['name'],
            $customer['email'],
            $customer['phone'],
            $fulfillment['method'],
            $fulfillment['feeMinor'],
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            $address['city'] ?? null,
            $address['region'] ?? null,
            $address['postalCode'] ?? null,
            $address['countryCode'] ?? null,
            $address['instructions'] ?? null,
            $payment['method'],
            $payment['kind'],
            $initial['orderStatus'],
            $initial['paymentStatus'],
            $initial['fulfillmentStatus'],
            $snapshot['quantityTotal'],
            $snapshot['subtotalMinor'],
            $snapshot['totalMinor'],
        ];
        $written = mysqli_stmt_execute($statement, $parameters);
        $recordId = $written ? (int) mysqli_insert_id($connection) : 0;
        mysqli_stmt_close($statement);
        return $recordId;
    }

    private static function insertLines(
        mysqli $connection,
        int $orderRecordId,
        array $lines
    ): bool {
        $lineStatement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Order_Lines
             (OrderRecordID, Position, ProductID, VariantID, SKU, Title,
              Quantity, UnitPriceMinor, Currency, LineTotalMinor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $optionStatement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Order_Line_Options
             (OrderLineRecordID, Position, Label) VALUES (?, ?, ?)'
        );
        if (!$lineStatement || !$optionStatement) {
            if ($lineStatement) {
                mysqli_stmt_close($lineStatement);
            }
            if ($optionStatement) {
                mysqli_stmt_close($optionStatement);
            }
            return false;
        }
        foreach ($lines as $index => $line) {
            if (!mysqli_stmt_execute($lineStatement, [
                $orderRecordId,
                $index + 1,
                $line['productId'],
                $line['variantId'],
                $line['sku'],
                $line['title'],
                $line['quantity'],
                $line['unitPriceMinor'],
                $line['currency'],
                $line['lineTotalMinor'],
            ])) {
                mysqli_stmt_close($lineStatement);
                mysqli_stmt_close($optionStatement);
                return false;
            }
            $lineRecordId = (int) mysqli_insert_id($connection);
            foreach ($line['optionLabels'] as $optionIndex => $label) {
                if (!mysqli_stmt_execute($optionStatement, [
                    $lineRecordId,
                    $optionIndex + 1,
                    $label,
                ])) {
                    mysqli_stmt_close($lineStatement);
                    mysqli_stmt_close($optionStatement);
                    return false;
                }
            }
        }
        mysqli_stmt_close($lineStatement);
        mysqli_stmt_close($optionStatement);
        return true;
    }

    private static function insertCreatedHistory(
        mysqli $connection,
        int $orderRecordId,
        int $subjectRecordId,
        array $result
    ): bool {
        $initial = $result['initialState'];
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Order_Status_History
             (OrderRecordID, EventName, OrderStatus, PaymentStatus,
              FulfillmentStatus, ActorType, ActorRecordID, SnapshotSHA256)
             VALUES (?, ?, ?, ?, ?, ?, ?, UNHEX(?))'
        );
        if (!$statement) {
            return false;
        }
        $written = mysqli_stmt_execute($statement, [
            $orderRecordId,
            'order.created',
            $initial['orderStatus'],
            $initial['paymentStatus'],
            $initial['fulfillmentStatus'],
            'anonymous',
            $subjectRecordId,
            $result['snapshotSha256'],
        ]);
        mysqli_stmt_close($statement);
        return $written;
    }

    private static function consumeCart(
        mysqli $connection,
        int $cartRecordId,
        int $subjectRecordId
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'DELETE FROM RED_Addon_StoreLite_Carts
             WHERE RecordID=? AND SubjectRecordID=?'
        );
        if (!$statement) {
            return false;
        }
        $executed = mysqli_stmt_execute(
            $statement,
            [$cartRecordId, $subjectRecordId]
        );
        $changed = $executed ? mysqli_stmt_affected_rows($statement) : 0;
        mysqli_stmt_close($statement);
        return $changed === 1;
    }

    private static function existingByIdempotency(
        mysqli $connection,
        string $idempotencyKeySha256
    ): array|false|null {
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID FROM RED_Addon_StoreLite_Orders
             WHERE IdempotencyKeySHA256=UNHEX(?) LIMIT 1 FOR UPDATE'
        );
        if (!$statement
            || !mysqli_stmt_execute($statement, [$idempotencyKeySha256])
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
        if (!is_array($row)) {
            return null;
        }
        return self::storedOrder(
            $connection,
            (int) ($row['RecordID'] ?? 0),
            true
        ) ?: false;
    }

    private static function storedOrder(
        mysqli $connection,
        int $orderRecordId,
        bool $lock
    ): ?array {
        if ($orderRecordId < 1) {
            return null;
        }
        $statement = mysqli_prepare(
            $connection,
            'SELECT OrderID, SourceCartRecordID, SubjectRecordID,
                    LOWER(HEX(IdempotencyKeySHA256)) AS IdempotencyKeySha256,
                    LOWER(HEX(SourceCartStateSHA256)) AS SourceCartStateSha256,
                    SnapshotVersion,
                    LOWER(HEX(SnapshotSHA256)) AS SnapshotSha256,
                    Currency, CustomerName, CustomerEmail, CustomerPhone,
                    FulfillmentMethod, FulfillmentFeeMinor, DeliveryLine1,
                    DeliveryLine2, DeliveryCity, DeliveryRegion,
                    DeliveryPostalCode, DeliveryCountryCode,
                    DeliveryInstructions, PaymentMethod, PaymentKind,
                    OrderStatus, PaymentStatus, FulfillmentStatus,
                    QuantityTotal, SubtotalMinor, TotalMinor
             FROM RED_Addon_StoreLite_Orders WHERE RecordID=? LIMIT 1'
                . ($lock ? ' FOR UPDATE' : '')
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$orderRecordId])) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return null;
        }
        $query = mysqli_stmt_get_result($statement);
        $header = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        if (!is_array($header)) {
            return null;
        }

        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID, Position, ProductID, VariantID, SKU, Title,
                    Quantity, UnitPriceMinor, Currency, LineTotalMinor
             FROM RED_Addon_StoreLite_Order_Lines
             WHERE OrderRecordID=? ORDER BY Position'
                . ($lock ? ' FOR UPDATE' : '')
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$orderRecordId])) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return null;
        }
        $query = mysqli_stmt_get_result($statement);
        $rows = [];
        while ($query && ($row = mysqli_fetch_assoc($query))) {
            $rows[] = $row;
        }
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        if ($rows === [] || count($rows) > 24) {
            return null;
        }
        $lines = [];
        foreach ($rows as $index => $row) {
            if ((int) ($row['Position'] ?? 0) !== $index + 1) {
                return null;
            }
            $options = self::storedOptions(
                $connection,
                (int) ($row['RecordID'] ?? 0),
                $lock
            );
            if ($options === null) {
                return null;
            }
            $lines[] = [
                'productId' => $row['ProductID'],
                'variantId' => $row['VariantID'],
                'sku' => $row['SKU'],
                'title' => $row['Title'],
                'optionLabels' => $options,
                'quantity' => (int) $row['Quantity'],
                'unitPriceMinor' => (int) $row['UnitPriceMinor'],
                'currency' => $row['Currency'],
                'lineTotalMinor' => (int) $row['LineTotalMinor'],
            ];
        }
        $address = ($header['FulfillmentMethod'] ?? null) === 'delivery'
            ? [
                'line1' => $header['DeliveryLine1'],
                'line2' => $header['DeliveryLine2'],
                'city' => $header['DeliveryCity'],
                'region' => $header['DeliveryRegion'],
                'postalCode' => $header['DeliveryPostalCode'],
                'countryCode' => $header['DeliveryCountryCode'],
                'instructions' => $header['DeliveryInstructions'],
            ]
            : null;
        $snapshot = [
            'version' => (int) $header['SnapshotVersion'],
            'currency' => $header['Currency'],
            'customer' => [
                'name' => $header['CustomerName'],
                'email' => $header['CustomerEmail'],
                'phone' => $header['CustomerPhone'],
            ],
            'fulfillment' => [
                'method' => $header['FulfillmentMethod'],
                'feeMinor' => (int) $header['FulfillmentFeeMinor'],
                'deliveryAddress' => $address,
            ],
            'payment' => [
                'method' => $header['PaymentMethod'],
                'kind' => $header['PaymentKind'],
            ],
            'lines' => $lines,
            'quantityTotal' => (int) $header['QuantityTotal'],
            'subtotalMinor' => (int) $header['SubtotalMinor'],
            'totalMinor' => (int) $header['TotalMinor'],
        ];
        try {
            $snapshotSha256 = hash('sha256', json_encode(
                $snapshot,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ));
        } catch (Throwable $throwable) {
            return null;
        }
        if (!self::validSha256($snapshotSha256)
            || !hash_equals(
                $snapshotSha256,
                (string) ($header['SnapshotSha256'] ?? '')
            )
            || !self::createdHistoryMatches(
                $connection,
                $orderRecordId,
                (int) ($header['SubjectRecordID'] ?? 0),
                (string) ($header['SnapshotSha256'] ?? ''),
                (string) ($header['OrderStatus'] ?? ''),
                (string) ($header['PaymentStatus'] ?? ''),
                (string) ($header['FulfillmentStatus'] ?? ''),
                $lock
            )
        ) {
            return null;
        }
        return [
            'orderId' => $header['OrderID'],
            'sourceCartRecordId' => (int) $header['SourceCartRecordID'],
            'subjectRecordId' => (int) $header['SubjectRecordID'],
            'idempotencyKeySha256' => $header['IdempotencyKeySha256'],
            'result' => [
                'valid' => true,
                'snapshot' => $snapshot,
                'snapshotSha256' => $snapshotSha256,
                'sourceCartStateSha256' => $header['SourceCartStateSha256'],
                'initialState' => [
                    'orderStatus' => $header['OrderStatus'],
                    'paymentStatus' => $header['PaymentStatus'],
                    'fulfillmentStatus' => $header['FulfillmentStatus'],
                ],
                'errors' => [],
            ],
        ];
    }

    private static function storedOptions(
        mysqli $connection,
        int $lineRecordId,
        bool $lock
    ): ?array {
        if ($lineRecordId < 1) {
            return null;
        }
        $statement = mysqli_prepare(
            $connection,
            'SELECT Position, Label
             FROM RED_Addon_StoreLite_Order_Line_Options
             WHERE OrderLineRecordID=? ORDER BY Position'
                . ($lock ? ' FOR UPDATE' : '')
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$lineRecordId])) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return null;
        }
        $query = mysqli_stmt_get_result($statement);
        $options = [];
        $valid = true;
        while ($query && ($row = mysqli_fetch_assoc($query))) {
            if ((int) ($row['Position'] ?? 0) !== count($options) + 1) {
                $valid = false;
                break;
            }
            $options[] = $row['Label'];
        }
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return $valid && count($options) <= 3 ? $options : null;
    }

    private static function createdHistoryMatches(
        mysqli $connection,
        int $orderRecordId,
        int $subjectRecordId,
        string $snapshotSha256,
        string $orderStatus,
        string $paymentStatus,
        string $fulfillmentStatus,
        bool $lock
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'SELECT EventName, OrderStatus, PaymentStatus,
                    FulfillmentStatus, ActorType, ActorRecordID,
                    LOWER(HEX(SnapshotSHA256)) AS SnapshotSha256
             FROM RED_Addon_StoreLite_Order_Status_History
             WHERE OrderRecordID=? ORDER BY RecordID'
                . ($lock ? ' FOR UPDATE' : '')
        );
        if (!$statement || !mysqli_stmt_execute($statement, [$orderRecordId])) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return false;
        }
        $query = mysqli_stmt_get_result($statement);
        $rows = [];
        while ($query && ($row = mysqli_fetch_assoc($query))) {
            $rows[] = $row;
        }
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return count($rows) === 1
            && ($rows[0]['EventName'] ?? null) === 'order.created'
            && ($rows[0]['OrderStatus'] ?? null) === $orderStatus
            && ($rows[0]['PaymentStatus'] ?? null) === $paymentStatus
            && ($rows[0]['FulfillmentStatus'] ?? null) === $fulfillmentStatus
            && ($rows[0]['ActorType'] ?? null) === 'anonymous'
            && (int) ($rows[0]['ActorRecordID'] ?? 0) === $subjectRecordId
            && ($rows[0]['SnapshotSha256'] ?? null) === $snapshotSha256;
    }

    private static function cartAbsent(
        mysqli $connection,
        int $cartRecordId,
        int $subjectRecordId
    ): bool {
        $statement = mysqli_prepare(
            $connection,
            'SELECT COUNT(*) AS RowCount FROM RED_Addon_StoreLite_Carts
             WHERE RecordID=? OR SubjectRecordID=?'
        );
        if (!$statement
            || !mysqli_stmt_execute($statement, [$cartRecordId, $subjectRecordId])
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
        return (int) ($row['RowCount'] ?? -1) === 0;
    }

    private static function newOrderId(): string
    {
        try {
            return 'ord_' . bin2hex(random_bytes(16));
        } catch (Throwable $throwable) {
            return '';
        }
    }

    private static function cartStateSha256(
        string $currency,
        array $lines
    ): string {
        try {
            return hash('sha256', json_encode(
                ['currency' => $currency, 'lines' => $lines],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
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
        try {
            return hash('sha256', json_encode(
                ['productId' => $productId, 'variantId' => $variantId],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        } catch (Throwable $throwable) {
            return '';
        }
    }

    private static function snapshotOptionLabels(mixed $facts): ?array
    {
        if (!is_array($facts) || !array_is_list($facts) || count($facts) > 3) {
            return null;
        }
        $labels = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)
                || array_keys($fact) !== [
                    'key', 'label', 'valueId', 'valueLabel',
                ]
                || !is_string($fact['label'] ?? null)
                || $fact['label'] === ''
                || !is_string($fact['valueLabel'] ?? null)
                || $fact['valueLabel'] === ''
            ) {
                return null;
            }
            $labels[] = $fact['label'] . ': ' . $fact['valueLabel'];
        }
        return $labels;
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            mysqli_query($connection, 'SAVEPOINT redcms_store_lite_order_check');
            mysqli_query($connection, 'RELEASE SAVEPOINT redcms_store_lite_order_check');
            return true;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function tablesAvailable(mysqli $connection): bool
    {
        try {
            $quoted = [];
            foreach (self::TABLES as $table) {
                $quoted[] = "'" . mysqli_real_escape_string($connection, $table) . "'";
            }
            $query = mysqli_query(
                $connection,
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA=DATABASE()
                   AND ENGINE=\'InnoDB\'
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

    private static function validSubject(int $value): bool
    {
        return $value >= 1 && $value <= 4294967295;
    }

    private static function validSha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function success(
        string $status,
        string $orderId,
        array $snapshotResult
    ): array {
        return [
            'status' => $status,
            'orderId' => $orderId,
            'snapshotSha256' => $snapshotResult['snapshotSha256'],
            'sourceCartStateSha256' =>
                $snapshotResult['sourceCartStateSha256'],
            'lineCount' => count($snapshotResult['snapshot']['lines']),
        ];
    }

    private static function result(string $status): array
    {
        return [
            'status' => $status,
            'orderId' => '',
            'snapshotSha256' => '',
            'sourceCartStateSha256' => '',
            'lineCount' => 0,
        ];
    }
}
