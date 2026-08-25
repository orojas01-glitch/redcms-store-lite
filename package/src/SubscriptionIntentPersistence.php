<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionOfferPersistence.php';

/** Client-local persistence for provider-neutral subscription intents. */
final class RED_CMS_Store_Lite_Subscription_Intent_Persistence
{
    public const TABLE = 'RED_Addon_StoreLite_Subscription_Intents';
    public const TABLES = [
        'RED_Addon_StoreLite_Products',
        'RED_Addon_StoreLite_Product_Variants',
        'RED_Addon_StoreLite_Subscription_Offers',
        self::TABLE,
    ];

    public static function read(
        mysqli $connection,
        int $subjectRecordId,
        string $offerId,
        string $installationCurrency
    ): array {
        if ($subjectRecordId < 1
            || !self::identifier($offerId)
            || !self::currency($installationCurrency)
            || !self::tablesAvailable($connection)
        ) {
            return self::readResult('invalid');
        }
        return self::readStored(
            $connection,
            $subjectRecordId,
            $offerId,
            $installationCurrency,
            false
        );
    }

    public static function requestWithinTransaction(
        mysqli $connection,
        int $subjectRecordId,
        string $offerId,
        string $installationCurrency
    ): array {
        $result = [
            'accepted' => false,
            'status' => 'invalid',
            'intentRecordId' => 0,
            'offerStateSha256' => '',
            'intentStateSha256' => '',
        ];
        if ($subjectRecordId < 1
            || !self::identifier($offerId)
            || !self::currency($installationCurrency)
            || !self::tablesAvailable($connection)
            || !self::transactionActive($connection)
        ) {
            return $result;
        }
        try {
            $current = self::readStored(
                $connection,
                $subjectRecordId,
                $offerId,
                $installationCurrency,
                true
            );
            if (empty($current['loaded'])
                || !in_array(
                    $current['status'] ?? '',
                    ['absent', 'requested'],
                    true
                )
            ) {
                $result['status'] = 'offer_unavailable';
                return $result;
            }
            $result['offerStateSha256'] = $current['offerStateSha256'];
            if (($current['status'] ?? '') === 'requested'
                && hash_equals(
                    $current['offerStateSha256'],
                    $current['intentOfferStateSha256']
                )
            ) {
                $result['accepted'] = true;
                $result['status'] = 'unchanged';
                $result['intentRecordId'] = $current['intentRecordId'];
                $result['intentStateSha256'] = $current['intentStateSha256'];
                return $result;
            }

            if (($current['status'] ?? '') === 'absent') {
                $statement = mysqli_prepare(
                    $connection,
                    'INSERT INTO `' . self::TABLE . '` '
                        . '(SubjectRecordID, OfferRecordID, '
                        . 'OfferStateSHA256, Status) '
                        . "VALUES (?, ?, UNHEX(?), 'requested')"
                );
                $parameters = [
                    $subjectRecordId,
                    $current['offerRecordId'],
                    $current['offerStateSha256'],
                ];
                $status = 'created';
            } else {
                $statement = mysqli_prepare(
                    $connection,
                    'UPDATE `' . self::TABLE . '` '
                        . "SET OfferStateSHA256=UNHEX(?), Status='requested' "
                        . 'WHERE RecordID=?'
                );
                $parameters = [
                    $current['offerStateSha256'],
                    $current['intentRecordId'],
                ];
                $status = 'updated';
            }
            if (!$statement || !mysqli_stmt_execute($statement, $parameters)) {
                if ($statement) {
                    mysqli_stmt_close($statement);
                }
                $result['status'] = 'write_failed';
                return $result;
            }
            mysqli_stmt_close($statement);
            $post = self::readStored(
                $connection,
                $subjectRecordId,
                $offerId,
                $installationCurrency,
                true
            );
            if (($post['status'] ?? '') !== 'requested'
                || !hash_equals(
                    $post['offerStateSha256'] ?? '',
                    $post['intentOfferStateSha256'] ?? ''
                )
                || !self::sha256($post['intentStateSha256'] ?? null)
            ) {
                $result['status'] = 'postcondition_failed';
                return $result;
            }
            $result['accepted'] = true;
            $result['status'] = $status;
            $result['intentRecordId'] = $post['intentRecordId'];
            $result['intentStateSha256'] = $post['intentStateSha256'];
            return $result;
        } catch (Throwable $throwable) {
            $result['status'] = 'write_failed';
            return $result;
        }
    }

    private static function readStored(
        mysqli $connection,
        int $subjectRecordId,
        string $offerId,
        string $installationCurrency,
        bool $forUpdate
    ): array {
        $offer = RED_CMS_Store_Lite_Subscription_Offer_Persistence::read(
            $connection,
            $offerId,
            $installationCurrency
        );
        if (($offer['status'] ?? '') !== 'found'
            || !is_array($offer['offer'] ?? null)
            || ($offer['offer']['state'] ?? '') !== 'published'
            || ($offer['offer']['availability'] ?? '') !== 'available'
            || !is_int($offer['recordId'] ?? null)
            || $offer['recordId'] < 1
            || !self::sha256($offer['stateSha256'] ?? null)
        ) {
            return self::readResult('offer_unavailable');
        }
        $statement = mysqli_prepare(
            $connection,
            'SELECT RecordID, HEX(OfferStateSHA256) AS OfferStateSHA256, Status '
                . 'FROM `' . self::TABLE . '` '
                . 'WHERE SubjectRecordID=? AND OfferRecordID=? LIMIT 1'
                . ($forUpdate ? ' FOR UPDATE' : '')
        );
        if (!$statement
            || !mysqli_stmt_execute(
                $statement,
                [$subjectRecordId, $offer['recordId']]
            )
        ) {
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
        if ($row === null) {
            return [
                'loaded' => true,
                'status' => 'absent',
                'offerRecordId' => $offer['recordId'],
                'offerStateSha256' => $offer['stateSha256'],
                'intentRecordId' => 0,
                'intentOfferStateSha256' => str_repeat('0', 64),
                'intentStateSha256' => self::stateSha256(
                    $subjectRecordId,
                    $offerId,
                    $offer['stateSha256'],
                    0,
                    str_repeat('0', 64),
                    'absent'
                ),
            ];
        }
        $intentOfferStateSha256 = strtolower(
            (string) ($row['OfferStateSHA256'] ?? '')
        );
        $intentRecordId = (int) ($row['RecordID'] ?? 0);
        $status = $row['Status'] ?? null;
        if ($intentRecordId < 1
            || !self::sha256($intentOfferStateSha256)
            || $status !== 'requested'
        ) {
            return self::readResult('storage_unavailable');
        }
        return [
            'loaded' => true,
            'status' => 'requested',
            'offerRecordId' => $offer['recordId'],
            'offerStateSha256' => $offer['stateSha256'],
            'intentRecordId' => $intentRecordId,
            'intentOfferStateSha256' => $intentOfferStateSha256,
            'intentStateSha256' => self::stateSha256(
                $subjectRecordId,
                $offerId,
                $offer['stateSha256'],
                $intentRecordId,
                $intentOfferStateSha256,
                'requested'
            ),
        ];
    }

    private static function stateSha256(
        int $subjectRecordId,
        string $offerId,
        string $offerStateSha256,
        int $intentRecordId,
        string $intentOfferStateSha256,
        string $status
    ): string {
        return hash('sha256', json_encode([
            'subjectRecordId' => $subjectRecordId,
            'offerId' => $offerId,
            'offerStateSha256' => $offerStateSha256,
            'intentRecordId' => $intentRecordId,
            'intentOfferStateSha256' => $intentOfferStateSha256,
            'status' => $status,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function readResult(string $status): array
    {
        return [
            'loaded' => false,
            'status' => $status,
            'offerRecordId' => 0,
            'offerStateSha256' => '',
            'intentRecordId' => 0,
            'intentOfferStateSha256' => '',
            'intentStateSha256' => '',
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
                 'RED_Addon_StoreLite_Subscription_Offers',
                 '" . self::TABLE . "'
               ) AND ENGINE='InnoDB'"
        );
        $row = $query ? mysqli_fetch_row($query) : null;
        if ($query) {
            mysqli_free_result($query);
        }
        return is_array($row) && (int) ($row[0] ?? 0) === 4;
    }

    private static function transactionActive(mysqli $connection): bool
    {
        try {
            if (!mysqli_query(
                $connection,
                'SAVEPOINT redcms_store_lite_subscription_intent_guard'
            )) {
                return false;
            }
            return mysqli_query(
                $connection,
                'RELEASE SAVEPOINT redcms_store_lite_subscription_intent_guard'
            ) === true;
        } catch (Throwable $throwable) {
            return false;
        }
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

    private static function sha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
}
