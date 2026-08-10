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
    public const ADD_ROUTE = 'redcms.store-lite/cart-intent';
    public const ADD_MUTATION = 'redcms.store-lite/add-to-cart';
    public const SET_QUANTITY_ROUTE =
        'redcms.store-lite/cart-line-quantity';
    public const SET_QUANTITY_MUTATION =
        'redcms.store-lite/set-cart-line-quantity';
    public const REMOVE_ROUTE = 'redcms.store-lite/cart-line-remove';
    public const REMOVE_MUTATION = 'redcms.store-lite/remove-cart-line';

    // Compatibility aliases for the original Add-to-cart bridge contract.
    public const ROUTE = self::ADD_ROUTE;
    public const MUTATION = self::ADD_MUTATION;
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
        self::operation($command);
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
        $operation = self::operation($request);
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

        if ($operation === 'add') {
            $intent = [
                'product' => $request->field('product'),
                'quantity' => $request->field('quantity'),
            ];
            $variant = $request->field('variant');
            if ($variant !== null) {
                $intent['variant'] = $variant;
            }
            $result =
                RED_CMS_Store_Lite_Cart_Persistence::addLineWithinTransaction(
                    $connection,
                    $request->subjectRecordId(),
                    $currency,
                    $intent,
                    $current['stateSha256']
                );
            $accepted = in_array(
                $result['status'] ?? '',
                ['created', 'updated'],
                true
            );
        } elseif ($operation === 'set_quantity') {
            $result = RED_CMS_Store_Lite_Cart_Persistence::
                setLineQuantityWithinTransaction(
                    $connection,
                    $request->subjectRecordId(),
                    $currency,
                    [
                        'line' => $request->field('line'),
                        'quantity' => $request->field('quantity'),
                    ],
                    $current['stateSha256']
                );
            $accepted = ($result['status'] ?? '') === 'updated';
        } else {
            $result = RED_CMS_Store_Lite_Cart_Persistence::
                removeLineWithinTransaction(
                    $connection,
                    $request->subjectRecordId(),
                    $currency,
                    ['line' => $request->field('line')],
                    $current['stateSha256']
                );
            $accepted = ($result['status'] ?? '') === 'removed';
        }
        $unchanged = $operation === 'set_quantity'
            && ($result['status'] ?? '') === 'unchanged';
        if (!$accepted && !$unchanged) {
            throw new RuntimeException('Store Lite cart mutation was refused.');
        }

        $command = new RED_Addon_Public_Mutation_Command(
            $request->packageId(),
            $request->routeId(),
            $request->mutationId(),
            $request->subjectRecordId(),
            $request->fields()
        );
        $state = self::load($connection, $command);
        return $unchanged
            ? RED_Addon_Public_Mutation_Execution_Result::unchanged($state)
            : RED_Addon_Public_Mutation_Execution_Result::accepted($state);
    }

    private static function operation(object $command): string
    {
        $pair = $command->routeId() . "\0" . $command->mutationId();
        return match ($pair) {
            self::ADD_ROUTE . "\0" . self::ADD_MUTATION => 'add',
            self::SET_QUANTITY_ROUTE . "\0" . self::SET_QUANTITY_MUTATION
                => 'set_quantity',
            self::REMOVE_ROUTE . "\0" . self::REMOVE_MUTATION => 'remove',
            default => throw new RuntimeException(
                'Store Lite cart mutation binding is unavailable.'
            ),
        };
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

        if (self::operation($command) !== 'add') {
            throw new RuntimeException('Store Lite currency is unavailable.');
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
