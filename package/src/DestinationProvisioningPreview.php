<?php

declare(strict_types=1);

/**
 * Pure, deterministic preview for a future product-destination workflow.
 *
 * The preview describes lifecycle operations and binds them to current product
 * and destination evidence. It never opens a database, executes callbacks,
 * allocates record identifiers, or enables writes.
 */
final class RED_CMS_Store_Lite_Destination_Provisioning_Preview
{
    private const PACKAGE_ID = 'redcms.store-lite';
    private const OPERATIONS = [
        'core.article-route.create',
        'redcms.store-lite/product-component.create',
        'core.component.publish',
        'content.search.refresh',
    ];

    public static function build(
        int $productRecordId,
        array $product,
        string $productStateSha256,
        array $destination
    ): array {
        $productId = is_string($product['id'] ?? null)
            ? $product['id']
            : '';
        $productState = is_string($product['state'] ?? null)
            ? $product['state']
            : '';
        $status = is_string($destination['status'] ?? null)
            ? $destination['status']
            : '';
        $path = is_string($destination['path'] ?? null)
            ? $destination['path']
            : '';
        $pathKind = is_string($destination['pathKind'] ?? null)
            ? $destination['pathKind']
            : '';
        if ($productRecordId < 1
            || !self::validProductId($productId)
            || !in_array($productState, ['draft', 'published', 'archived'], true)
            || !self::validSha256($productStateSha256)
            || !in_array(
                $status,
                ['published', 'missing', 'route_created', 'repair_needed'],
                true
            )
            || !self::validPath($path)
            || !in_array($pathKind, ['public', 'proposed', 'expected'], true)
            || ($status === 'published' && $pathKind !== 'public')
            || ($status === 'missing' && $pathKind !== 'proposed')
            || ($status === 'route_created' && $pathKind !== 'expected')
            || ($status === 'repair_needed' && $pathKind !== 'expected')
        ) {
            throw new InvalidArgumentException(
                'Store Lite destination provisioning preview is invalid.'
            );
        }

        $intent = 'none';
        $label = 'No action needed';
        $ready = false;
        $requiresConfirmation = false;
        $operations = [];
        $blockers = [];
        $reason = 'The destination is already published.';

        if (in_array($status, ['missing', 'route_created'], true)
            && $productState === 'published'
        ) {
            $intent = 'provision';
            $label = 'Ready to provision';
            $ready = true;
            $requiresConfirmation = true;
            $operations = self::OPERATIONS;
            $reason = 'Four guarded lifecycle operations are ready for review.';
        } elseif ($status === 'missing') {
            $intent = 'blocked';
            $label = $productState === 'archived'
                ? 'Restore product first'
                : 'Publish product first';
            $blockers = ['product_not_published'];
            $reason = 'The product must be published before provisioning.';
        } elseif ($status === 'repair_needed') {
            $intent = 'repair';
            $label = 'Repair first';
            $blockers = ['destination_repair_required'];
            $reason = 'The existing route or placement needs review.';
        }

        $material = [
            'schema' => 1,
            'package' => self::PACKAGE_ID,
            'productRecordId' => (string) $productRecordId,
            'productId' => $productId,
            'productState' => $productState,
            'productStateSha256' => $productStateSha256,
            'destinationPhase' => in_array(
                $status,
                ['missing', 'route_created'],
                true
            ) ? 'provisioning' : $status,
            'destinationPath' => $path,
            'intent' => $intent,
            'operations' => $operations,
            'blockers' => $blockers,
            'writesEnabled' => false,
        ];
        $encoded = json_encode(
            $material,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );

        return [
            'intent' => $intent,
            'label' => $label,
            'ready' => $ready,
            'requiresConfirmation' => $requiresConfirmation,
            'writesEnabled' => false,
            'path' => $path,
            'operations' => $operations,
            'blockers' => $blockers,
            'planSha256' => hash('sha256', $encoded),
            'reason' => $reason,
        ];
    }

    private static function validProductId(string $value): bool
    {
        return preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) === 1;
    }

    private static function validSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function validPath(string $value): bool
    {
        return strlen($value) >= 2
            && strlen($value) <= 512
            && str_starts_with($value, '/')
            && !str_starts_with($value, '//')
            && !str_contains($value, '/./')
            && !str_contains($value, '/../')
            && !str_ends_with($value, '/.')
            && !str_ends_with($value, '/..')
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
