<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionOffer.php';

/** Pure data-only Subscribe button model; no route is registered yet. */
final class RED_CMS_Store_Lite_Public_Subscription_Button_Presenter
{
    public const ROUTE = 'redcms.store-lite/subscription-intent';
    public const MUTATION = 'redcms.store-lite/create-subscription-intent';

    public static function present(array $input, string $currency): ?array
    {
        $normalized = RED_CMS_Store_Lite_Subscription_Offer::normalize(
            $input,
            $currency
        );
        if (($normalized['valid'] ?? null) !== true
            || ($normalized['offer']['state'] ?? null) !== 'published'
            || ($normalized['offer']['availability'] ?? null) !== 'available'
        ) {
            return null;
        }
        $offer = $normalized['offer'];
        return [
            'title' => $offer['title'],
            'summary' => $offer['summary'] ?? '',
            'facts' => [[
                'label' => 'Subscription',
                'value' => $currency . ' '
                    . number_format($offer['priceMinor'] / 100, 2, '.', ',')
                    . ' / ' . ($offer['billingPeriod'] === 'monthly'
                        ? 'month' : 'year'),
            ]],
            'mutationForm' => [
                'route' => self::ROUTE,
                'mutation' => self::MUTATION,
                'submitLabel' => $offer['buttonLabel'],
                'fields' => [[
                    'key' => 'offer',
                    'control' => 'hidden',
                    'value' => $offer['id'],
                ]],
            ],
        ];
    }
}
