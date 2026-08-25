<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionOfferPersistence.php';
require_once __DIR__ . '/PublicSubscriptionButtonPresenter.php';

/** Runtime and editor binding for one Subscription component placement. */
final class RED_CMS_Store_Lite_Subscription_Component_Bridge
{
    public const COMPONENT = 'redcms.store-lite/subscription';
    public const TABLE = 'RED_Addon_StoreLite_Subscription_Placements';
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
                'Store Lite Subscription component context is invalid.'
            );
        }

        $connection = self::runtimeConnection();
        try {
            $binding = self::placementOffer(
                $connection,
                $context['placement']['recordId']
            );
            if ($binding === null) {
                throw new RuntimeException(
                    'Store Lite Subscription component binding is unavailable.'
                );
            }
            $stored = RED_CMS_Store_Lite_Subscription_Offer_Persistence::read(
                $connection,
                $binding['offerId'],
                $binding['currency']
            );
            $view = ($stored['status'] ?? '') === 'found'
                && is_array($stored['offer'] ?? null)
                    ? RED_CMS_Store_Lite_Public_Subscription_Button_Presenter::
                        present($stored['offer'], $binding['currency'])
                    : null;
            if (!is_array($view)) {
                throw new RuntimeException(
                    'Store Lite Subscription component is not publicly available.'
                );
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
        $binding = self::placementOffer($connection, $contentRecordId);
        if ($binding === null) {
            throw new RuntimeException(
                'Store Lite Subscription placement is unavailable.'
            );
        }
        return ['offer-id' => $binding['offerId']];
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
                $values['offer-id']
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
                $values['offer-id']
            );
    }

    public static function delete(mysqli $connection, array $context): bool
    {
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
                'Store Lite Subscription editor context is invalid.'
            );
        }
        return $context['contentRecordId'];
    }

    private static function validValues(array $values): bool
    {
        return array_keys($values) === ['offer-id']
            && self::identifier($values['offer-id']);
    }

    private static function insertPlacement(
        mysqli $connection,
        int $contentRecordId,
        string $offerId
    ): bool {
        $offerRecordId = self::offerRecordId($connection, $offerId);
        if ($offerRecordId < 1) {
            return false;
        }
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO `' . self::TABLE . '` '
                . '(ContentRecordID, OfferRecordID) VALUES (?, ?)'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param(
            $statement,
            'ii',
            $contentRecordId,
            $offerRecordId
        );
        mysqli_stmt_execute($statement);
        $created = mysqli_stmt_affected_rows($statement) === 1;
        mysqli_stmt_close($statement);
        return $created;
    }

    private static function updatePlacement(
        mysqli $connection,
        int $contentRecordId,
        string $offerId
    ): bool {
        $offerRecordId = self::offerRecordId($connection, $offerId);
        if ($offerRecordId < 1) {
            return false;
        }
        $statement = mysqli_prepare(
            $connection,
            'UPDATE `' . self::TABLE . '` SET OfferRecordID=? '
                . 'WHERE ContentRecordID=?'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param(
            $statement,
            'ii',
            $offerRecordId,
            $contentRecordId
        );
        mysqli_stmt_execute($statement);
        $changed = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);
        if ($changed === 1) {
            return true;
        }
        $binding = self::placementOffer($connection, $contentRecordId);
        return $binding !== null
            && hash_equals($offerId, $binding['offerId']);
    }

    private static function offerRecordId(
        mysqli $connection,
        string $offerId
    ): int {
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID FROM RED_Addon_StoreLite_Subscription_Offers '
                . 'WHERE OfferID=? LIMIT 1'
        );
        if (!$statement) {
            return 0;
        }
        mysqli_stmt_bind_param($statement, 's', $offerId);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($statement);
        return is_array($row) ? (int) ($row['RecordID'] ?? 0) : 0;
    }

    private static function placementOffer(
        mysqli $connection,
        int $contentRecordId
    ): ?array {
        $statement = mysqli_prepare(
            $connection,
            'SELECT offer.OfferID, offer.Currency '
                . 'FROM `' . self::TABLE . '` AS placement '
                . 'INNER JOIN RED_Addon_StoreLite_Subscription_Offers AS offer '
                . 'ON offer.RecordID=placement.OfferRecordID '
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
            || !self::identifier($row['OfferID'] ?? null)
            || !self::currency($row['Currency'] ?? null)
        ) {
            return null;
        }
        return [
            'offerId' => $row['OfferID'],
            'currency' => $row['Currency'],
        ];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $value) === 1;
    }

    private static function currency(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            if (!mysqli_query(
                $connection,
                'SAVEPOINT redcms_store_lite_subscription_component_guard'
            )) {
                return false;
            }
            return mysqli_query(
                $connection,
                'RELEASE SAVEPOINT '
                    . 'redcms_store_lite_subscription_component_guard'
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
