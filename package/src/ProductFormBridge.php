<?php

declare(strict_types=1);

require_once __DIR__ . '/CatalogPersistence.php';
require_once __DIR__ . '/ProductFormValues.php';

/**
 * Store Lite binding for the core-owned administrator product form.
 */
final class RED_CMS_Store_Lite_Product_Form_Bridge
{
    public const TOOL = 'redcms.store-lite/products';
    public const FORM = 'redcms.store-lite/product-editor';
    public const SETTING_CURRENCY = 'catalog.currency';
    public const TABLES = [
        'RED_Addon_StoreLite_Product_Activity',
        'RED_Addon_StoreLite_Product_Option_Values',
        'RED_Addon_StoreLite_Product_Options',
        'RED_Addon_StoreLite_Product_Variant_Selections',
        'RED_Addon_StoreLite_Product_Variants',
        'RED_Addon_StoreLite_Products',
    ];

    public static function load(
        mysqli $connection,
        RED_Addon_Admin_Tool_Form_Value_Request $request
    ): RED_Addon_Admin_Tool_Form_Values {
        if ($request->tool() !== self::TOOL
            || $request->form() !== self::FORM
        ) {
            throw new RuntimeException('Store Lite product form binding is invalid.');
        }
        $currency = self::currency($request->runtimeSettings());
        $current = RED_CMS_Store_Lite_Catalog_Persistence::readByRecordId(
            $connection,
            $request->targetRecordId(),
            $currency
        );
        $values = ($current['status'] ?? '') === 'found'
            && is_array($current['product'] ?? null)
                ? RED_CMS_Store_Lite_Product_Form_Values::fromProduct(
                    $current['product']
                )
                : null;
        if (!is_array($values)) {
            throw new RuntimeException('Store Lite product target is unavailable.');
        }
        return RED_Addon_Admin_Tool_Form_Values::current($values);
    }

    public static function write(
        mysqli $connection,
        RED_Addon_Admin_Tool_Form_Write_Request $request
    ): bool {
        if ($request->package() !== 'redcms.store-lite'
            || $request->tool() !== self::TOOL
            || $request->form() !== self::FORM
        ) {
            return false;
        }
        try {
            $currency = self::currency($request->runtimeSettings());
            $current = RED_CMS_Store_Lite_Catalog_Persistence::readByRecordId(
                $connection,
                $request->targetRecordId(),
                $currency
            );
            if (($current['status'] ?? '') !== 'found'
                || !is_array($current['product'] ?? null)
            ) {
                return false;
            }
            $product = RED_CMS_Store_Lite_Product_Form_Values::toProduct(
                $request->values(),
                $currency,
                (string) $current['product']['id']
            );
            if (!is_array($product)) {
                return false;
            }
            $written = RED_CMS_Store_Lite_Catalog_Persistence::replaceWithinTransaction(
                $connection,
                $request->targetRecordId(),
                $product,
                $currency
            );
            $status = $written['status'] ?? '';
            if ($status === 'unchanged') {
                return true;
            }
            return $status === 'updated'
                && self::recordActivity(
                    $connection,
                    $request->actorRecordId(),
                    (string) $written['productId'],
                    (string) $written['previousStateSha256'],
                    (string) $written['stateSha256']
                );
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function currency(
        RED_Addon_Admin_Tool_Form_Runtime_Settings $settings
    ): string {
        $currency = $settings->value(self::SETTING_CURRENCY);
        if (!is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
        ) {
            throw new RuntimeException('Store Lite installation currency is invalid.');
        }
        return $currency;
    }

    private static function recordActivity(
        mysqli $connection,
        int $actorRecordId,
        string $productId,
        string $previousStateSha256,
        string $stateSha256
    ): bool {
        if ($actorRecordId < 1
            || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $productId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $previousStateSha256) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $stateSha256) !== 1
        ) {
            return false;
        }
        try {
            $eventName = 'product.updated';
            $statement = mysqli_prepare(
                $connection,
                'INSERT INTO RED_Addon_StoreLite_Product_Activity
                    (EventName, ProductID, ActorAdminRecordID,
                     PreviousStateSHA256, StateSHA256)
                 VALUES (?, ?, ?, UNHEX(?), UNHEX(?))'
            );
            if (!$statement) {
                return false;
            }
            mysqli_stmt_bind_param(
                $statement,
                'ssiss',
                $eventName,
                $productId,
                $actorRecordId,
                $previousStateSha256,
                $stateSha256
            );
            $inserted = mysqli_stmt_execute($statement);
            $recordId = $inserted ? (int) mysqli_insert_id($connection) : 0;
            mysqli_stmt_close($statement);
            return $recordId > 0;
        } catch (Throwable $throwable) {
            return false;
        }
    }
}
