<?php

declare(strict_types=1);

require_once __DIR__ . '/CartReadModel.php';
require_once __DIR__ . '/PublicCartPresenter.php';
require_once __DIR__ . '/PublicGuestCheckoutPresenter.php';

final class RED_CMS_Store_Lite_Cart_Component_Bridge
{
    public const COMPONENT = 'redcms.store-lite/cart';
    public const TABLE = 'RED_Addon_StoreLite_Cart_Placements';
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
            throw new RuntimeException('Store Lite Cart component context is invalid.');
        }

        $connection = self::runtimeConnection();
        try {
            $title = self::placementTitle(
                $connection,
                $context['placement']['recordId']
            );
            $currency = RED_CMS_Store_Lite_Cart_Read_Model::installationCurrency(
                $connection
            );
            if ($title === null || $currency === null) {
                throw new RuntimeException('Store Lite Cart component is unavailable.');
            }
            $subject = function_exists(
                'red_addon_public_mutation_page_subject_context'
            ) ? red_addon_public_mutation_page_subject_context($connection) : [];
            $projection = !empty($subject['valid'])
                && is_int($subject['subjectRecordId'] ?? null)
                ? RED_CMS_Store_Lite_Cart_Read_Model::load(
                    $connection,
                    $subject['subjectRecordId'],
                    $currency
                )
                : [
                    'loaded' => true,
                    'status' => 'empty',
                    'cart' => ['currency' => $currency, 'lines' => []],
                    'reason' => 'loaded',
                ];
            $view = !empty($projection['loaded'])
                && is_array($projection['cart'] ?? null)
                    ? RED_CMS_Store_Lite_Public_Cart_Presenter::present(
                        $projection['cart'],
                        $currency
                    )
                    : null;
            if (!is_array($view)) {
                throw new RuntimeException('Store Lite Cart view is unavailable.');
            }
            if (($projection['status'] ?? '') === 'found'
                && ($projection['cart']['lines'] ?? []) !== []
            ) {
                $view['mutationForm'] =
                    RED_CMS_Store_Lite_Public_Guest_Checkout_Presenter::
                        mutationForm();
            }
            $view['title'] = $title;
            return $view;
        } finally {
            mysqli_close($connection);
        }
    }

    public static function load(mysqli $connection, array $context): array
    {
        $recordId = self::editorContext($context, ['component', 'contentRecordId']);
        $title = self::placementTitle($connection, $recordId);
        if ($title === null) {
            throw new RuntimeException('Store Lite Cart placement is unavailable.');
        }
        return ['cart-title' => $title];
    }

    public static function create(
        mysqli $connection,
        array $context,
        array $values
    ): bool {
        $recordId = self::editorContext(
            $context,
            ['component', 'contentRecordId', 'actorRecordId', 'planHash']
        );
        if (!self::transactionActive($connection) || !self::validValues($values)) {
            return false;
        }
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO `' . self::TABLE . '` (ContentRecordID, Title) VALUES (?, ?)'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param($statement, 'is', $recordId, $values['cart-title']);
        mysqli_stmt_execute($statement);
        $created = mysqli_stmt_affected_rows($statement) === 1;
        mysqli_stmt_close($statement);
        return $created;
    }

    public static function write(
        mysqli $connection,
        array $context,
        array $values
    ): bool {
        $recordId = self::editorContext(
            $context,
            [
                'component', 'contentRecordId', 'actorRecordId',
                'previousStateHash',
            ]
        );
        if (!self::transactionActive($connection) || !self::validValues($values)) {
            return false;
        }
        $statement = mysqli_prepare(
            $connection,
            'UPDATE `' . self::TABLE . '` SET Title=? WHERE ContentRecordID=?'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param($statement, 'si', $values['cart-title'], $recordId);
        mysqli_stmt_execute($statement);
        $affected = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);
        return $affected === 1
            || ($affected === 0
                && self::placementTitle($connection, $recordId)
                    === $values['cart-title']);
    }

    public static function delete(mysqli $connection, array $context): bool
    {
        $recordId = self::editorContext(
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
        mysqli_stmt_bind_param($statement, 'i', $recordId);
        mysqli_stmt_execute($statement);
        $deleted = mysqli_stmt_affected_rows($statement) === 1;
        mysqli_stmt_close($statement);
        return $deleted;
    }

    private static function placementTitle(
        mysqli $connection,
        int $contentRecordId
    ): ?string {
        $statement = mysqli_prepare(
            $connection,
            'SELECT Title FROM `' . self::TABLE . '` WHERE ContentRecordID=? LIMIT 1'
        );
        if (!$statement) {
            return null;
        }
        mysqli_stmt_bind_param($statement, 'i', $contentRecordId);
        mysqli_stmt_execute($statement);
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        $title = is_array($row) ? ($row['Title'] ?? null) : null;
        return self::validTitle($title) ? $title : null;
    }

    private static function editorContext(array $context, array $keys): int
    {
        if (array_keys($context) !== $keys
            || ($context['component'] ?? null) !== self::COMPONENT
            || !is_int($context['contentRecordId'] ?? null)
            || $context['contentRecordId'] < 1
        ) {
            throw new RuntimeException('Store Lite Cart editor context is invalid.');
        }
        return $context['contentRecordId'];
    }

    private static function validValues(array $values): bool
    {
        return array_keys($values) === ['cart-title']
            && self::validTitle($values['cart-title']);
    }

    private static function validTitle(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && trim($value) === $value
            && strlen($value) <= 160
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            if (!mysqli_query($connection, 'SAVEPOINT redcms_store_lite_cart_component_guard')) {
                return false;
            }
            return mysqli_query(
                $connection,
                'RELEASE SAVEPOINT redcms_store_lite_cart_component_guard'
            ) === true;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function runtimeConnection(): mysqli
    {
        foreach (['DBHOST', 'DBUSER', 'DBPASS', 'DBNAME'] as $constant) {
            if (!defined($constant)) {
                throw new RuntimeException('Store Lite runtime database configuration is unavailable.');
            }
        }
        $connection = mysqli_connect(DBHOST, DBUSER, DBPASS, DBNAME);
        if (!$connection || !mysqli_set_charset($connection, 'utf8mb4')) {
            throw new RuntimeException('Store Lite runtime database connection is unavailable.');
        }
        return $connection;
    }
}
