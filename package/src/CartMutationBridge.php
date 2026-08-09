<?php

declare(strict_types=1);

require_once __DIR__ . '/CartPersistence.php';

/**
 * Store Lite binding for the core-owned public-mutation transaction runner.
 *
 * The bridge receives only typed core command objects and an already-active
 * transaction connection. It never reads request, cookie, session, or server
 * globals; issues browser evidence; owns a transaction; or emits a response.
 */
final class RED_CMS_Store_Lite_Cart_Mutation_Bridge
{
    public const ROUTE = 'redcms.store-lite/cart-intent';
    public const MUTATION = 'redcms.store-lite/add-to-cart';
    public const TABLES = RED_CMS_Store_Lite_Cart_Persistence::TABLES;

    public static function route(): never
    {
        throw new RuntimeException(
            'Store Lite public mutations require the core-owned dispatcher.'
        );
    }

    public static function load(
        mysqli $connection,
        RED_Addon_Public_Mutation_Command $command
    ): RED_Addon_Public_Mutation_State {
        $currency = self::currency($connection, $command);
        $state = RED_CMS_Store_Lite_Cart_Persistence::read(
            $connection,
            $command->subjectRecordId(),
            $currency
        );
        if (!in_array($state['status'] ?? '', ['empty', 'found'], true)
            || !self::validSha256($state['stateSha256'] ?? null)
            || !is_int($state['lineCount'] ?? null)
            || $state['lineCount'] < 0
        ) {
            throw new RuntimeException('Store Lite cart state is unavailable.');
        }
        return new RED_Addon_Public_Mutation_State(
            $command->subjectRecordId(),
            [
                'cartStateSha256' => $state['stateSha256'],
                'lineCount' => $state['lineCount'],
            ]
        );
    }

    public static function execute(
        mysqli $connection,
        RED_Addon_Public_Mutation_Execution_Request $request
    ): RED_Addon_Public_Mutation_Execution_Result {
        $currency = self::currency($connection, $request);
        $current = RED_CMS_Store_Lite_Cart_Persistence::read(
            $connection,
            $request->subjectRecordId(),
            $currency
        );
        if (!in_array($current['status'] ?? '', ['empty', 'found'], true)
            || !self::validSha256($current['stateSha256'] ?? null)
        ) {
            throw new RuntimeException('Store Lite cart state is unavailable.');
        }

        $intent = [
            'product' => $request->field('product'),
            'quantity' => $request->field('quantity'),
        ];
        $variant = $request->field('variant');
        if ($variant !== null) {
            $intent['variant'] = $variant;
        }
        $result = RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
            $connection,
            $request->subjectRecordId(),
            $currency,
            $intent,
            $current['stateSha256']
        );
        if (!in_array($result['status'] ?? '', ['created', 'updated'], true)) {
            throw new RuntimeException('Store Lite cart mutation was refused.');
        }

        $command = new RED_Addon_Public_Mutation_Command(
            $request->packageId(),
            $request->routeId(),
            $request->mutationId(),
            $request->subjectRecordId(),
            $request->fields()
        );
        return RED_Addon_Public_Mutation_Execution_Result::accepted(
            self::load($connection, $command)
        );
    }

    private static function currency(mysqli $connection, object $command): string
    {
        $subjectRecordId = $command->subjectRecordId();
        $statement = mysqli_prepare(
            $connection,
            'SELECT Currency
             FROM RED_Addon_StoreLite_Carts
             WHERE SubjectRecordID=? LIMIT 1'
        );
        if (!$statement) {
            throw new RuntimeException('Store Lite currency is unavailable.');
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
            throw new RuntimeException('Store Lite currency is unavailable.');
        }
        $currency = is_array($row) ? ($row['Currency'] ?? null) : null;
        if (self::validCurrency($currency)) {
            return $currency;
        }

        $productId = $command->field('product');
        $statement = mysqli_prepare(
            $connection,
            'SELECT Currency
             FROM RED_Addon_StoreLite_Products
             WHERE ProductID=? LIMIT 1'
        );
        if (!$statement) {
            throw new RuntimeException('Store Lite currency is unavailable.');
        }
        mysqli_stmt_bind_param($statement, 's', $productId);
        $executed = mysqli_stmt_execute($statement);
        $query = $executed ? mysqli_stmt_get_result($statement) : false;
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        $currency = $executed && is_array($row)
            ? ($row['Currency'] ?? null)
            : null;
        if (!self::validCurrency($currency)) {
            throw new RuntimeException('Store Lite currency is unavailable.');
        }
        return $currency;
    }

    private static function validCurrency($currency): bool
    {
        return is_string($currency)
            && preg_match('/\A[A-Z]{3}\z/D', $currency) === 1;
    }

    private static function validSha256($value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
