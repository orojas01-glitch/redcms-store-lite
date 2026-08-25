<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionOffer.php';

/** Caller-transaction-owned persistence for provider-neutral offers. */
final class RED_CMS_Store_Lite_Subscription_Offer_Persistence
{
    private const TABLE = 'RED_Addon_StoreLite_Subscription_Offers';

    public static function read(
        mysqli $connection,
        string $offerId,
        string $installationCurrency
    ): array {
        if (!self::identifier($offerId)
            || !self::currency($installationCurrency)
            || !self::tablesAvailable($connection)
        ) {
            return self::readResult('invalid');
        }
        return self::readStored(
            $connection,
            $offerId,
            $installationCurrency,
            false
        );
    }

    public static function createWithinTransaction(
        mysqli $connection,
        array $input,
        string $installationCurrency
    ): array {
        $result = self::writeResult('invalid');
        $normalized = RED_CMS_Store_Lite_Subscription_Offer::normalize(
            $input,
            $installationCurrency
        );
        if (($normalized['valid'] ?? null) !== true
            || !is_array($normalized['offer'] ?? null)
            || !self::tablesAvailable($connection)
            || !self::transactionActive($connection)
        ) {
            return $result;
        }
        $offer = $normalized['offer'];
        $result['offerId'] = $offer['id'];
        $result['targetStateSha256'] = self::stateSha256($offer);
        try {
            $current = self::readStored(
                $connection,
                $offer['id'],
                $installationCurrency,
                true
            );
            if (($current['status'] ?? null) === 'found') {
                $result['status'] = 'already_exists';
                return $result;
            }
            if (($current['status'] ?? null) !== 'not_found') {
                $result['status'] = 'storage_unavailable';
                return $result;
            }
            $target = self::targetRecords($connection, $offer, true);
            if ($target === null) {
                $result['status'] = 'target_unavailable';
                return $result;
            }
            $statement = mysqli_prepare(
                $connection,
                'INSERT INTO `' . self::TABLE . '` (
                    OfferID, ProductRecordID, VariantRecordID, Title, Summary,
                    Currency, PriceMinor, BillingPeriod, State, Availability,
                    ButtonLabel
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$statement || !mysqli_stmt_execute($statement, [
                $offer['id'], $target['productRecordId'],
                $target['variantRecordId'], $offer['title'], $offer['summary'],
                $offer['currency'], $offer['priceMinor'],
                $offer['billingPeriod'], $offer['state'],
                $offer['availability'], $offer['buttonLabel'],
            ])) {
                if ($statement) {
                    mysqli_stmt_close($statement);
                }
                $result['status'] = 'write_failed';
                return $result;
            }
            $recordId = (int) mysqli_insert_id($connection);
            mysqli_stmt_close($statement);
            $post = self::readStored(
                $connection,
                $offer['id'],
                $installationCurrency,
                true
            );
            if (($post['status'] ?? null) !== 'found'
                || ($post['recordId'] ?? null) !== $recordId
                || ($post['offer'] ?? null) !== $offer
                || !hash_equals(
                    $result['targetStateSha256'],
                    (string) ($post['stateSha256'] ?? '')
                )
            ) {
                $result['status'] = 'postcondition_failed';
                return $result;
            }
            $result['status'] = 'created';
            $result['recordId'] = $recordId;
            $result['stateSha256'] = $post['stateSha256'];
            return $result;
        } catch (Throwable $throwable) {
            $result['status'] = 'write_failed';
            return $result;
        }
    }

    public static function replaceWithinTransaction(
        mysqli $connection,
        array $input,
        string $installationCurrency,
        string $expectedStateSha256
    ): array {
        $result = self::writeResult('invalid');
        $normalized = RED_CMS_Store_Lite_Subscription_Offer::normalize(
            $input,
            $installationCurrency
        );
        if (($normalized['valid'] ?? null) !== true
            || !is_array($normalized['offer'] ?? null)
            || !self::sha256($expectedStateSha256)
            || !self::tablesAvailable($connection)
            || !self::transactionActive($connection)
        ) {
            return $result;
        }
        $offer = $normalized['offer'];
        $result['offerId'] = $offer['id'];
        $result['targetStateSha256'] = self::stateSha256($offer);
        try {
            $current = self::readStored(
                $connection,
                $offer['id'],
                $installationCurrency,
                true
            );
            if (($current['status'] ?? null) !== 'found') {
                $result['status'] = 'not_found';
                return $result;
            }
            $result['recordId'] = $current['recordId'];
            $result['previousStateSha256'] = $current['stateSha256'];
            if (!hash_equals(
                $expectedStateSha256,
                (string) $current['stateSha256']
            )) {
                $result['status'] = 'state_conflict';
                return $result;
            }
            if (hash_equals(
                $result['targetStateSha256'],
                (string) $current['stateSha256']
            )) {
                $result['status'] = 'unchanged';
                $result['stateSha256'] = $current['stateSha256'];
                return $result;
            }
            $target = self::targetRecords($connection, $offer, true);
            if ($target === null) {
                $result['status'] = 'target_unavailable';
                return $result;
            }
            $statement = mysqli_prepare(
                $connection,
                'UPDATE `' . self::TABLE . '` SET
                    ProductRecordID=?, VariantRecordID=?, Title=?, Summary=?,
                    Currency=?, PriceMinor=?, BillingPeriod=?, State=?,
                    Availability=?, ButtonLabel=?
                 WHERE RecordID=? AND OfferID=?'
            );
            if (!$statement || !mysqli_stmt_execute($statement, [
                $target['productRecordId'], $target['variantRecordId'],
                $offer['title'], $offer['summary'], $offer['currency'],
                $offer['priceMinor'], $offer['billingPeriod'], $offer['state'],
                $offer['availability'], $offer['buttonLabel'],
                $current['recordId'], $offer['id'],
            ])) {
                if ($statement) {
                    mysqli_stmt_close($statement);
                }
                $result['status'] = 'write_failed';
                return $result;
            }
            mysqli_stmt_close($statement);
            $post = self::readStored(
                $connection,
                $offer['id'],
                $installationCurrency,
                true
            );
            if (($post['status'] ?? null) !== 'found'
                || ($post['recordId'] ?? null) !== $current['recordId']
                || ($post['offer'] ?? null) !== $offer
                || !hash_equals(
                    $result['targetStateSha256'],
                    (string) ($post['stateSha256'] ?? '')
                )
            ) {
                $result['status'] = 'postcondition_failed';
                return $result;
            }
            $result['status'] = 'updated';
            $result['stateSha256'] = $post['stateSha256'];
            return $result;
        } catch (Throwable $throwable) {
            $result['status'] = 'write_failed';
            return $result;
        }
    }

    private static function readStored(
        mysqli $connection,
        string $offerId,
        string $installationCurrency,
        bool $forUpdate
    ): array {
        $statement = mysqli_prepare(
            $connection,
            'SELECT offers.RecordID, offers.OfferID, products.ProductID,
                    variants.VariantID, offers.Title, offers.Summary,
                    offers.Currency, offers.PriceMinor, offers.BillingPeriod,
                    offers.State, offers.Availability, offers.ButtonLabel
             FROM `' . self::TABLE . '` AS offers
             INNER JOIN RED_Addon_StoreLite_Products AS products
               ON products.RecordID=offers.ProductRecordID
              AND products.Currency=offers.Currency
             LEFT JOIN RED_Addon_StoreLite_Product_Variants AS variants
               ON variants.ProductRecordID=offers.ProductRecordID
              AND variants.RecordID=offers.VariantRecordID
             WHERE offers.OfferID=? AND offers.Currency=?
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        if (!$statement || !mysqli_stmt_execute(
            $statement,
            [$offerId, $installationCurrency]
        )) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return self::readResult('storage_unavailable');
        }
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        if (!is_array($row)) {
            return self::readResult('not_found');
        }
        $variantId = $row['VariantID'] ?? null;
        if ($variantId !== null && !is_string($variantId)) {
            return self::readResult('storage_unavailable');
        }
        $offer = [
            'id' => (string) $row['OfferID'],
            'productId' => (string) $row['ProductID'],
            'variantId' => $variantId,
            'title' => (string) $row['Title'],
            'summary' => $row['Summary'] === null
                ? null
                : (string) $row['Summary'],
            'currency' => (string) $row['Currency'],
            'priceMinor' => (int) $row['PriceMinor'],
            'billingPeriod' => (string) $row['BillingPeriod'],
            'state' => (string) $row['State'],
            'availability' => (string) $row['Availability'],
            'buttonLabel' => (string) $row['ButtonLabel'],
        ];
        $normalized = RED_CMS_Store_Lite_Subscription_Offer::normalize(
            $offer,
            $installationCurrency
        );
        if (($normalized['valid'] ?? null) !== true
            || ($normalized['offer'] ?? null) !== $offer
        ) {
            return self::readResult('storage_unavailable');
        }
        return [
            'loaded' => true,
            'status' => 'found',
            'recordId' => (int) $row['RecordID'],
            'offer' => $offer,
            'stateSha256' => self::stateSha256($offer),
        ];
    }

    private static function targetRecords(
        mysqli $connection,
        array $offer,
        bool $forUpdate
    ): ?array {
        $statement = mysqli_prepare(
            $connection,
            'SELECT products.RecordID AS ProductRecordID,
                    variants.RecordID AS VariantRecordID
             FROM RED_Addon_StoreLite_Products AS products
             LEFT JOIN RED_Addon_StoreLite_Product_Variants AS variants
               ON variants.ProductRecordID=products.RecordID
              AND variants.VariantID=?
             WHERE products.ProductID=? AND products.Currency=?
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        if (!$statement || !mysqli_stmt_execute($statement, [
            $offer['variantId'], $offer['productId'], $offer['currency'],
        ])) {
            if ($statement) {
                mysqli_stmt_close($statement);
            }
            return null;
        }
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        if (!is_array($row)
            || ($offer['variantId'] !== null
                && $row['VariantRecordID'] === null)
        ) {
            return null;
        }
        return [
            'productRecordId' => (int) $row['ProductRecordID'],
            'variantRecordId' => $row['VariantRecordID'] === null
                ? null
                : (int) $row['VariantRecordID'],
        ];
    }

    private static function tablesAvailable(mysqli $connection): bool
    {
        $query = mysqli_query(
            $connection,
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME IN (
                 'RED_Addon_StoreLite_Products',
                 'RED_Addon_StoreLite_Product_Variants',
                 '" . self::TABLE . "'
               ) AND ENGINE='InnoDB'"
        );
        $row = $query ? mysqli_fetch_row($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        return (int) ($row[0] ?? 0) === 3;
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            mysqli_query(
                $connection,
                'SAVEPOINT redcms_store_lite_subscription_guard'
            );
            mysqli_query(
                $connection,
                'RELEASE SAVEPOINT redcms_store_lite_subscription_guard'
            );
            return true;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function stateSha256(array $offer): string
    {
        return hash(
            'sha256',
            json_encode(
                $offer,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            )
        );
    }

    private static function identifier(string $value): bool
    {
        return strlen($value) <= 64
            && preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $value) === 1;
    }

    private static function currency(string $value): bool
    {
        return preg_match('/\A[A-Z]{3}\z/D', $value) === 1;
    }

    private static function sha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function readResult(string $status): array
    {
        return [
            'loaded' => false,
            'status' => $status,
            'recordId' => 0,
            'offer' => null,
            'stateSha256' => '',
        ];
    }

    private static function writeResult(string $status): array
    {
        return [
            'status' => $status,
            'recordId' => 0,
            'offerId' => '',
            'previousStateSha256' => '',
            'targetStateSha256' => '',
            'stateSha256' => '',
        ];
    }
}
