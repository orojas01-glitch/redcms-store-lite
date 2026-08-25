<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionOfferPersistence.php';
require_once __DIR__ . '/SubscriptionOfferFormValues.php';

final class RED_CMS_Store_Lite_Subscription_Offer_Form_Bridge
{
    public const TOOL = 'redcms.store-lite/subscriptions';
    public const FORM = 'redcms.store-lite/subscription-offer-editor';
    public const SETTING_CURRENCY = 'catalog.currency';
    public const TABLES = [
        'RED_Addon_StoreLite_Product_Variants',
        'RED_Addon_StoreLite_Products',
        'RED_Addon_StoreLite_Subscription_Activity',
        'RED_Addon_StoreLite_Subscription_Offers',
    ];

    public static function tool(RED_Addon_Admin_Tool_Request $request): RED_Addon_Admin_Tool_Result
    {
        if ($request->tool() !== self::TOOL) {
            throw new RuntimeException('Subscription tool binding is invalid.');
        }
        return RED_Addon_Admin_Tool_Result::view(
            'Subscriptions',
            'Create and edit monthly or yearly offers. Public subscription checkout remains disabled until its adapter and webhook gates pass.'
        );
    }

    public static function targets(mysqli $connection, RED_Addon_Admin_Tool_Form_Target_Request $request): RED_Addon_Admin_Tool_Form_Targets
    {
        if ($request->tool() !== self::TOOL || $request->form() !== self::FORM) {
            throw new RuntimeException('Subscription targets are invalid.');
        }
        $currency = self::currency($request->runtimeSettings());
        $cursor = $request->cursor();
        $after = is_string($cursor) && preg_match('/\A[1-9][0-9]*\z/D', $cursor) === 1
            ? (int) $cursor : 0;
        $limit = $request->limit();
        $statement = mysqli_prepare(
            $connection,
            'SELECT offers.RecordID, offers.OfferID, offers.Title,
                    offers.PriceMinor, offers.BillingPeriod, offers.State,
                    offers.Availability, products.ProductID, variants.VariantID
             FROM RED_Addon_StoreLite_Subscription_Offers AS offers
             INNER JOIN RED_Addon_StoreLite_Products AS products
               ON products.RecordID=offers.ProductRecordID
             LEFT JOIN RED_Addon_StoreLite_Product_Variants AS variants
               ON variants.ProductRecordID=offers.ProductRecordID
              AND variants.RecordID=offers.VariantRecordID
             WHERE offers.Currency=? AND offers.RecordID>?
             ORDER BY offers.RecordID LIMIT ?'
        );
        mysqli_stmt_execute($statement, [$currency, $after, $limit + 1]);
        $query = mysqli_stmt_get_result($statement);
        $items = [];
        while ($query && ($row = mysqli_fetch_assoc($query))) {
            $items[] = [
                'targetRecordId' => (int) $row['RecordID'],
                'label' => (string) $row['Title'],
                'description' => ucfirst((string) $row['State']) . ' · '
                    . ucfirst((string) $row['Availability']),
                'facts' => [
                    ['label' => 'Offer ID', 'value' => (string) $row['OfferID']],
                    ['label' => 'Product', 'value' => (string) $row['ProductID']],
                    ['label' => 'Billing', 'value' => $currency . ' '
                        . number_format(((int) $row['PriceMinor']) / 100, 2, '.', ',')
                        . ' · ' . (string) $row['BillingPeriod']],
                ],
            ];
        }
        if ($query) {
            mysqli_free_result($query);
        }
        mysqli_stmt_close($statement);
        $next = null;
        if (count($items) > $limit) {
            array_pop($items);
            $next = (string) $items[count($items) - 1]['targetRecordId'];
        }
        return RED_Addon_Admin_Tool_Form_Targets::page($items, $next);
    }

    public static function load(mysqli $connection, RED_Addon_Admin_Tool_Form_Value_Request $request): RED_Addon_Admin_Tool_Form_Values
    {
        $current = self::current($connection, $request->targetRecordId(), self::currency($request->runtimeSettings()));
        $values = is_array($current) ? RED_CMS_Store_Lite_Subscription_Offer_Form_Values::fromOffer($current['offer']) : null;
        if (!is_array($values)) {
            throw new RuntimeException('Subscription offer is unavailable.');
        }
        return RED_Addon_Admin_Tool_Form_Values::current($values);
    }

    public static function initial(mysqli $connection, RED_Addon_Admin_Tool_Form_Initial_Value_Request $request): RED_Addon_Admin_Tool_Form_Initial_Values
    {
        $currency = self::currency($request->runtimeSettings());
        return RED_Addon_Admin_Tool_Form_Initial_Values::draft([
            'id' => '', 'product-id' => '', 'variant-id' => null,
            'title' => '', 'summary' => null, 'currency' => $currency,
            'price-minor' => null, 'billing-period' => 'monthly',
            'state' => 'draft', 'availability' => 'unavailable',
            'button-label' => 'Subscribe',
        ]);
    }

    public static function create(mysqli $connection, RED_Addon_Admin_Tool_Form_Create_Request $request): RED_Addon_Admin_Tool_Form_Created_Record
    {
        $currency = self::currency($request->runtimeSettings());
        $values = $request->values();
        $id = is_string($values['id'] ?? null) ? $values['id'] : '';
        $offer = RED_CMS_Store_Lite_Subscription_Offer_Form_Values::toOffer($values, $currency, $id);
        $created = is_array($offer)
            ? RED_CMS_Store_Lite_Subscription_Offer_Persistence::createWithinTransaction($connection, $offer, $currency)
            : [];
        if (($created['status'] ?? '') !== 'created'
            || !self::activity($connection, $request->actorRecordId(), 'subscription.created', $id, null, $created['stateSha256'] ?? '')
        ) {
            throw new RuntimeException('Subscription offer creation failed.');
        }
        return RED_Addon_Admin_Tool_Form_Created_Record::created((int) $created['recordId']);
    }

    public static function write(mysqli $connection, RED_Addon_Admin_Tool_Form_Write_Request $request): bool
    {
        try {
            $currency = self::currency($request->runtimeSettings());
            $current = self::current($connection, $request->targetRecordId(), $currency);
            if (!is_array($current)) {
                return false;
            }
            $offer = RED_CMS_Store_Lite_Subscription_Offer_Form_Values::toOffer(
                $request->values(), $currency, $current['offer']['id']
            );
            if (!is_array($offer)) {
                return false;
            }
            $written = RED_CMS_Store_Lite_Subscription_Offer_Persistence::replaceWithinTransaction(
                $connection, $offer, $currency, $current['stateSha256']
            );
            if (($written['status'] ?? '') === 'unchanged') {
                return true;
            }
            return ($written['status'] ?? '') === 'updated'
                && self::activity(
                    $connection, $request->actorRecordId(),
                    'subscription.updated', $offer['id'],
                    $written['previousStateSha256'], $written['stateSha256']
                );
        } catch (Throwable $throwable) {
            return false;
        }
    }

    private static function current(mysqli $connection, int $recordId, string $currency): ?array
    {
        $statement = mysqli_prepare($connection, 'SELECT OfferID FROM RED_Addon_StoreLite_Subscription_Offers WHERE RecordID=? LIMIT 1');
        mysqli_stmt_execute($statement, [$recordId]);
        $query = mysqli_stmt_get_result($statement);
        $row = $query ? mysqli_fetch_assoc($query) : null;
        if ($query) mysqli_free_result($query);
        mysqli_stmt_close($statement);
        if (!is_array($row)) return null;
        $current = RED_CMS_Store_Lite_Subscription_Offer_Persistence::read($connection, (string) $row['OfferID'], $currency);
        return ($current['status'] ?? '') === 'found' ? $current : null;
    }

    private static function currency(RED_Addon_Admin_Tool_Form_Runtime_Settings $settings): string
    {
        $currency = $settings->value(self::SETTING_CURRENCY);
        if (!is_string($currency) || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new RuntimeException('Subscription currency is invalid.');
        }
        return $currency;
    }

    private static function activity(mysqli $connection, int $actor, string $event, string $id, ?string $previous, string $state): bool
    {
        if ($actor < 1 || !in_array($event, ['subscription.created', 'subscription.updated'], true)
            || preg_match('/\A[a-f0-9]{64}\z/D', $state) !== 1
            || ($previous !== null && preg_match('/\A[a-f0-9]{64}\z/D', $previous) !== 1)
        ) return false;
        $statement = mysqli_prepare(
            $connection,
            'INSERT INTO RED_Addon_StoreLite_Subscription_Activity
             (EventName, OfferID, ActorAdminRecordID, PreviousStateSHA256, StateSHA256)
             VALUES (?, ?, ?, UNHEX(?), UNHEX(?))'
        );
        $ok = $statement && mysqli_stmt_execute($statement, [$event, $id, $actor, $previous, $state]);
        if ($statement) mysqli_stmt_close($statement);
        return $ok;
    }
}
