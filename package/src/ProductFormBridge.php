<?php

declare(strict_types=1);

require_once __DIR__ . '/CatalogPersistence.php';
require_once __DIR__ . '/CatalogAdministration.php';
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

    public static function tool(
        RED_Addon_Admin_Tool_Request $request
    ): RED_Addon_Admin_Tool_Result {
        if ($request->tool() !== self::TOOL) {
            throw new RuntimeException('Store Lite product tool binding is invalid.');
        }
        return RED_Addon_Admin_Tool_Result::view(
            'Products',
            'Create or edit Store Lite products and review each public destination. Destination publishing actions are not enabled yet.'
        );
    }

    public static function targets(
        mysqli $connection,
        RED_Addon_Admin_Tool_Form_Target_Request $request
    ): RED_Addon_Admin_Tool_Form_Targets {
        if ($request->tool() !== self::TOOL
            || $request->form() !== self::FORM
        ) {
            throw new RuntimeException('Store Lite product target binding is invalid.');
        }
        $currency = self::currency($request->runtimeSettings());
        $catalog = RED_CMS_Store_Lite_Catalog_Administration::listProducts(
            $connection,
            $request->actorRecordId(),
            $currency,
            $request->limit(),
            $request->cursor()
        );
        if (($catalog['loaded'] ?? false) !== true
            || !is_array($catalog['items'] ?? null)
        ) {
            throw new RuntimeException('Store Lite product targets are unavailable.');
        }
        $items = [];
        foreach ($catalog['items'] as $product) {
            $items[] = [
                'targetRecordId' => (int) ($product['recordId'] ?? 0),
                'label' => (string) ($product['title'] ?? ''),
                'description' => self::targetDescription($product),
                'facts' => [
                    ['label' => 'Product ID', 'value' => (string) ($product['id'] ?? '')],
                    [
                        'label' => 'Price',
                        'value' => self::minorUnitPriceRange($product),
                    ],
                    [
                        'label' => 'Product state',
                        'value' => ucfirst((string) ($product['state'] ?? '')),
                    ],
                    [
                        'label' => 'Destination',
                        'value' => self::destinationSummary($product),
                    ],
                ],
            ];
        }
        $nextCursor = $catalog['nextCursor'] ?? null;
        return RED_Addon_Admin_Tool_Form_Targets::page(
            $items,
            is_string($nextCursor) ? $nextCursor : null
        );
    }

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

    public static function initial(
        mysqli $connection,
        RED_Addon_Admin_Tool_Form_Initial_Value_Request $request
    ): RED_Addon_Admin_Tool_Form_Initial_Values {
        if ($request->tool() !== self::TOOL
            || $request->form() !== self::FORM
        ) {
            throw new RuntimeException(
                'Store Lite product initial-value binding is invalid.'
            );
        }
        $currency = self::currency($request->runtimeSettings());
        return RED_Addon_Admin_Tool_Form_Initial_Values::draft([
            'id' => '',
            'type' => 'simple',
            'title' => '',
            'summary' => null,
            'currency' => $currency,
            'state' => 'draft',
            'availability' => 'unavailable',
            'image-reference' => null,
            'sku' => null,
            'price-minor' => null,
            'stock' => null,
            'options' => [],
            'variants' => [],
        ]);
    }

    public static function create(
        mysqli $connection,
        RED_Addon_Admin_Tool_Form_Create_Request $request
    ): RED_Addon_Admin_Tool_Form_Created_Record {
        if ($request->package() !== 'redcms.store-lite'
            || $request->tool() !== self::TOOL
            || $request->form() !== self::FORM
        ) {
            throw new RuntimeException(
                'Store Lite product creator binding is invalid.'
            );
        }
        $currency = self::currency($request->runtimeSettings());
        $values = $request->values();
        $productId = is_string($values['id'] ?? null)
            ? $values['id']
            : '';
        $product = RED_CMS_Store_Lite_Product_Form_Values::toProduct(
            $values,
            $currency,
            $productId
        );
        if (!is_array($product)) {
            throw new RuntimeException('Store Lite product values are invalid.');
        }
        $created =
            RED_CMS_Store_Lite_Catalog_Persistence::createWithinTransaction(
                $connection,
                $product,
                $currency
            );
        if (($created['status'] ?? '') !== 'created'
            || !is_int($created['recordId'] ?? null)
            || $created['recordId'] < 1
            || !is_string($created['stateSha256'] ?? null)
            || !self::recordActivity(
                $connection,
                $request->actorRecordId(),
                'product.created',
                $productId,
                null,
                $created['stateSha256']
            )
        ) {
            throw new RuntimeException('Store Lite product creation failed.');
        }
        return RED_Addon_Admin_Tool_Form_Created_Record::created(
            $created['recordId']
        );
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
                    'product.updated',
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

    private static function targetDescription(array $product): string
    {
        $availability = ($product['availability'] ?? '') === 'available'
            ? 'Available'
            : 'Unavailable';
        $variantCount = (int) ($product['variantCount'] ?? 0);
        return $variantCount > 0
            ? $availability . ' with ' . $variantCount .
                ($variantCount === 1 ? ' variant.' : ' variants.')
            : $availability . ' simple product.';
    }

    private static function minorUnitPriceRange(array $product): string
    {
        $currency = (string) ($product['currency'] ?? '');
        $minimum = (int) ($product['minimumPriceMinor'] ?? -1);
        $maximum = (int) ($product['maximumPriceMinor'] ?? -1);
        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || $minimum < 0
            || $maximum < $minimum
        ) {
            throw new RuntimeException('Store Lite product price is invalid.');
        }
        $format = static fn (int $minor): string =>
            $currency . ' ' . number_format($minor) . ' minor units';
        return $minimum === $maximum
            ? $format($minimum)
            : $format($minimum) . '–' . $format($maximum);
    }

    private static function destinationSummary(array $product): string
    {
        $destination = $product['destination'] ?? null;
        if (!is_array($destination)
            || !is_string($destination['label'] ?? null)
            || !is_string($destination['path'] ?? null)
            || !is_string($destination['pathKind'] ?? null)
            || !in_array(
                $destination['pathKind'],
                ['public', 'proposed', 'expected'],
                true
            )
        ) {
            throw new RuntimeException('Store Lite destination status is invalid.');
        }
        $qualifier = $destination['pathKind'] === 'public'
            ? ''
            : $destination['pathKind'] . ' ';
        return $destination['label'] . ' · ' . $qualifier . $destination['path'];
    }

    private static function recordActivity(
        mysqli $connection,
        int $actorRecordId,
        string $eventName,
        string $productId,
        ?string $previousStateSha256,
        string $stateSha256
    ): bool {
        if ($actorRecordId < 1
            || !in_array(
                $eventName,
                ['product.created', 'product.updated'],
                true
            )
            || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $productId) !== 1
            || ($eventName === 'product.created'
                && $previousStateSha256 !== null)
            || ($eventName === 'product.updated'
                && (!is_string($previousStateSha256)
                    || preg_match(
                        '/\A[a-f0-9]{64}\z/D',
                        $previousStateSha256
                    ) !== 1))
            || preg_match('/\A[a-f0-9]{64}\z/D', $stateSha256) !== 1
        ) {
            return false;
        }
        try {
            $previous = $previousStateSha256 ?? '';
            $statement = mysqli_prepare(
                $connection,
                'INSERT INTO RED_Addon_StoreLite_Product_Activity
                    (EventName, ProductID, ActorAdminRecordID,
                     PreviousStateSHA256, StateSHA256)
                 VALUES (?, ?, ?, IF(?=\'\', NULL, UNHEX(?)), UNHEX(?))'
            );
            if (!$statement) {
                return false;
            }
            mysqli_stmt_bind_param(
                $statement,
                'ssisss',
                $eventName,
                $productId,
                $actorRecordId,
                $previous,
                $previous,
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
