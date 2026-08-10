<?php

declare(strict_types=1);

/**
 * Read-only Store Lite cart projection for the public Cart presenter.
 */
final class RED_CMS_Store_Lite_Cart_Read_Model
{
    public static function installationCurrency(mysqli $connection): ?string
    {
        try {
            $query = mysqli_query(
                $connection,
                'SELECT COUNT(*) AS ProductCount, MIN(Currency) AS MinimumCurrency, '
                    . 'MAX(Currency) AS MaximumCurrency '
                    . 'FROM RED_Addon_StoreLite_Products'
            );
            $row = $query ? mysqli_fetch_assoc($query) : null;
            if ($query) {
                mysqli_free_result($query);
            }
            $minimum = is_array($row) ? ($row['MinimumCurrency'] ?? null) : null;
            $maximum = is_array($row) ? ($row['MaximumCurrency'] ?? null) : null;
            return (int) ($row['ProductCount'] ?? 0) > 0
                && is_string($minimum)
                && preg_match('/\A[A-Z]{3}\z/D', $minimum) === 1
                && $minimum === $maximum
                    ? $minimum
                    : null;
        } catch (Throwable $throwable) {
            return null;
        }
    }

    public static function load(
        mysqli $connection,
        int $subjectRecordId,
        string $currency
    ): array {
        if ($subjectRecordId < 1
            || $subjectRecordId > 4294967295
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
        ) {
            return self::result('invalid_request');
        }
        try {
            $statement = mysqli_prepare(
                $connection,
                'SELECT RecordID, Currency FROM RED_Addon_StoreLite_Carts '
                    . 'WHERE SubjectRecordID=? LIMIT 1'
            );
            if (!$statement) {
                return self::result('storage_unavailable');
            }
            mysqli_stmt_bind_param($statement, 'i', $subjectRecordId);
            mysqli_stmt_execute($statement);
            $query = mysqli_stmt_get_result($statement);
            $row = $query ? mysqli_fetch_assoc($query) : null;
            if ($query) {
                mysqli_free_result($query);
            }
            mysqli_stmt_close($statement);
            if (!is_array($row)) {
                return self::loaded('empty', $currency, []);
            }
            $cartRecordId = (int) ($row['RecordID'] ?? 0);
            if ($cartRecordId < 1 || ($row['Currency'] ?? null) !== $currency) {
                return self::result('storage_unavailable');
            }

            $statement = mysqli_prepare(
                $connection,
                'SELECT cart_lines.ProductRecordID, cart_lines.VariantRecordID, '
                    . 'products.Title, products.Currency AS ProductCurrency, '
                    . 'cart_lines.Quantity, cart_lines.UnitPriceMinor, cart_lines.Currency, '
                    . 'cart_lines.LineTotalMinor '
                    . 'FROM RED_Addon_StoreLite_Cart_Lines AS cart_lines '
                    . 'INNER JOIN RED_Addon_StoreLite_Products AS products '
                    . 'ON products.RecordID=cart_lines.ProductRecordID '
                    . 'WHERE cart_lines.CartRecordID=? '
                    . 'ORDER BY cart_lines.LineIdentitySHA256 LIMIT 25'
            );
            if (!$statement) {
                return self::result('storage_unavailable');
            }
            mysqli_stmt_bind_param($statement, 'i', $cartRecordId);
            mysqli_stmt_execute($statement);
            $query = mysqli_stmt_get_result($statement);
            $rows = [];
            while ($query && ($line = mysqli_fetch_assoc($query))) {
                $rows[] = $line;
            }
            if ($query) {
                mysqli_free_result($query);
            }
            mysqli_stmt_close($statement);
            if (count($rows) > 24) {
                return self::result('display_limit_exceeded');
            }

            $lines = [];
            foreach ($rows as $row) {
                $productRecordId = (int) ($row['ProductRecordID'] ?? 0);
                $variantRecordId = $row['VariantRecordID'] === null
                    ? null
                    : (int) $row['VariantRecordID'];
                $title = $row['Title'] ?? null;
                $quantity = (int) ($row['Quantity'] ?? 0);
                $unitPriceMinor = (int) ($row['UnitPriceMinor'] ?? -1);
                $lineTotalMinor = (int) ($row['LineTotalMinor'] ?? -1);
                if ($productRecordId < 1
                    || ($variantRecordId !== null && $variantRecordId < 1)
                    || !self::text($title, 160)
                    || ($row['ProductCurrency'] ?? null) !== $currency
                    || ($row['Currency'] ?? null) !== $currency
                    || $quantity < 1
                    || $quantity > 100
                    || $unitPriceMinor < 0
                    || $unitPriceMinor > 999999999
                    || $lineTotalMinor !== $unitPriceMinor * $quantity
                ) {
                    return self::result('line_invalid');
                }
                $options = $variantRecordId === null
                    ? []
                    : self::options(
                        $connection,
                        $productRecordId,
                        $variantRecordId
                    );
                if ($options === null) {
                    return self::result('options_invalid');
                }
                $lines[] = [
                    'title' => $title,
                    'options' => $options,
                    'quantity' => $quantity,
                    'unitPriceMinor' => $unitPriceMinor,
                    'currency' => $currency,
                    'lineTotalMinor' => $lineTotalMinor,
                ];
            }
            return self::loaded('found', $currency, $lines);
        } catch (Throwable $throwable) {
            return self::result('storage_unavailable');
        }
    }

    private static function options(
        mysqli $connection,
        int $productRecordId,
        int $variantRecordId
    ): ?array {
        $statement = mysqli_prepare(
            $connection,
            'SELECT options.Label AS OptionLabel, values_table.Label AS ValueLabel '
                . 'FROM RED_Addon_StoreLite_Product_Variant_Selections AS selections '
                . 'INNER JOIN RED_Addon_StoreLite_Product_Options AS options '
                . 'ON options.ProductRecordID=selections.ProductRecordID '
                . 'AND options.RecordID=selections.OptionRecordID '
                . 'INNER JOIN RED_Addon_StoreLite_Product_Option_Values AS values_table '
                . 'ON values_table.ProductRecordID=selections.ProductRecordID '
                . 'AND values_table.OptionRecordID=selections.OptionRecordID '
                . 'AND values_table.RecordID=selections.OptionValueRecordID '
                . 'WHERE selections.ProductRecordID=? '
                . 'AND selections.VariantRecordID=? ORDER BY options.Position'
        );
        if (!$statement) {
            return null;
        }
        mysqli_stmt_bind_param(
            $statement,
            'ii',
            $productRecordId,
            $variantRecordId
        );
        mysqli_stmt_execute($statement);
        $query = mysqli_stmt_get_result($statement);
        $options = [];
        while ($query && ($row = mysqli_fetch_assoc($query))) {
            $label = $row['OptionLabel'] ?? null;
            $value = $row['ValueLabel'] ?? null;
            if (!self::text($label, 80) || !self::text($value, 80)) {
                $options = [];
                break;
            }
            $options[] = $label . ': ' . $value;
        }
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        return count($options) >= 1 && count($options) <= 3
            ? $options
            : null;
    }

    private static function text(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && $value !== ''
            && trim($value) === $value
            && strlen($value) <= $maximum
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private static function loaded(
        string $status,
        string $currency,
        array $lines
    ): array {
        return [
            'loaded' => true,
            'status' => $status,
            'cart' => ['currency' => $currency, 'lines' => $lines],
            'reason' => 'loaded',
        ];
    }

    private static function result(string $reason): array
    {
        return [
            'loaded' => false,
            'status' => 'unavailable',
            'cart' => null,
            'reason' => $reason,
        ];
    }
}
