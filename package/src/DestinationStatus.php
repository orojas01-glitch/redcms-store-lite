<?php

declare(strict_types=1);

/**
 * Read-only projection of one Store Lite product destination.
 *
 * A destination is public only when the published product is bound to one
 * active Product component whose Article target is the exact Product ID and
 * that target resolves to one active Article route. This class never creates,
 * repairs, publishes, or removes content.
 */
final class RED_CMS_Store_Lite_Destination_Status
{
    private const COMPONENT = 'redcms.store-lite/product';

    public static function read(
        mysqli $connection,
        int $productRecordId,
        string $productId,
        string $productState
    ): array {
        if ($productRecordId < 1
            || !self::validProductId($productId)
            || !in_array($productState, ['draft', 'published', 'archived'], true)
        ) {
            throw new InvalidArgumentException(
                'Store Lite destination request is invalid.'
            );
        }

        $statement = mysqli_prepare(
            $connection,
            "SELECT component.RecordID AS ComponentRecordID,
                    component.Active='Y'
                      AND component.PagePosition>0
                      AND (YEAR(component.StartDate)=0
                           OR component.StartDate<=NOW())
                      AND (YEAR(component.ExpDate)=0
                           OR component.ExpDate>=NOW()) AS ComponentPublic,
                    route.RecordID AS RouteRecordID,
                    route.Active='Y'
                      AND route.PagePosition>0
                      AND (YEAR(route.StartDate)=0 OR route.StartDate<=NOW())
                      AND (YEAR(route.ExpDate)=0 OR route.ExpDate>=NOW())
                      AND EXISTS (
                        SELECT 1 FROM RED_Sections AS destination_section
                        WHERE destination_section.Sections=route.Sections
                          AND destination_section.Language=route.Language
                          AND destination_section.Active='Y'
                      )
                      AND (
                        route.Categories=''
                        OR EXISTS (
                          SELECT 1
                          FROM RED_Categories AS destination_category
                          INNER JOIN RED_Sections AS category_section
                            ON category_section.RecordID=
                                destination_category.SectionRecordID
                           AND category_section.Language=
                                destination_category.Language
                          WHERE destination_category.Categories=route.Categories
                            AND destination_category.Language=route.Language
                            AND destination_category.Active='Y'
                            AND category_section.Sections=route.Sections
                            AND category_section.Active='Y'
                        )
                      )
                      AND (
                        route.SubCategories=''
                        OR EXISTS (
                          SELECT 1
                          FROM RED_SubCategories AS destination_subcategory
                          INNER JOIN RED_Categories AS subcategory_category
                            ON subcategory_category.RecordID=
                                destination_subcategory.CategoryRecordID
                           AND subcategory_category.Language=
                                destination_subcategory.Language
                          INNER JOIN RED_Sections AS subcategory_section
                            ON subcategory_section.RecordID=
                                subcategory_category.SectionRecordID
                           AND subcategory_section.Language=
                                subcategory_category.Language
                          WHERE destination_subcategory.SubCategories=
                                route.SubCategories
                            AND destination_subcategory.Language=route.Language
                            AND destination_subcategory.Active='Y'
                            AND subcategory_category.Categories=route.Categories
                            AND subcategory_category.Active='Y'
                            AND subcategory_section.Sections=route.Sections
                            AND subcategory_section.Active='Y'
                        )
                      )
                        AS RoutePublic,
                    route.Sections, route.Categories, route.SubCategories,
                    route.Alias AS RouteAlias
             FROM RED_Addon_StoreLite_Product_Placements AS placement
             INNER JOIN RED_Articles AS component
               ON component.RecordID=placement.ContentRecordID
              AND component.Component=?
              AND component.Article=?
             LEFT JOIN RED_Articles AS route
               ON route.Component='Article'
              AND route.Language=component.Language
              AND route.Sections=component.Sections
              AND route.Categories=component.Categories
              AND route.SubCategories=component.SubCategories
              AND route.Alias=component.Article
             WHERE placement.ProductRecordID=?
             ORDER BY component.RecordID ASC, route.RecordID ASC"
        );
        if (!$statement) {
            throw new RuntimeException('Store Lite destination storage is unavailable.');
        }
        $component = self::COMPONENT;
        mysqli_stmt_bind_param(
            $statement,
            'ssi',
            $component,
            $productId,
            $productRecordId
        );
        if (!mysqli_stmt_execute($statement)) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('Store Lite destination storage is unavailable.');
        }
        $query = mysqli_stmt_get_result($statement);
        if (!$query) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('Store Lite destination storage is unavailable.');
        }
        $rows = [];
        while ($row = mysqli_fetch_assoc($query)) {
            $rows[] = $row;
        }
        mysqli_free_result($query);
        mysqli_stmt_close($statement);

        $collisionStatement = mysqli_prepare(
            $connection,
            "SELECT COUNT(*)
             FROM RED_Articles
             WHERE Component='Article' AND Alias=?"
        );
        if (!$collisionStatement) {
            throw new RuntimeException('Store Lite destination storage is unavailable.');
        }
        mysqli_stmt_bind_param($collisionStatement, 's', $productId);
        if (!mysqli_stmt_execute($collisionStatement)) {
            mysqli_stmt_close($collisionStatement);
            throw new RuntimeException('Store Lite destination storage is unavailable.');
        }
        mysqli_stmt_bind_result($collisionStatement, $aliasRouteCount);
        $fetched = mysqli_stmt_fetch($collisionStatement);
        mysqli_stmt_close($collisionStatement);
        if (!$fetched) {
            throw new RuntimeException('Store Lite destination storage is unavailable.');
        }

        return self::project(
            $rows,
            (int) $aliasRouteCount,
            $productId,
            $productState
        );
    }

    public static function project(
        array $rows,
        int $aliasRouteCount,
        string $productId,
        string $productState
    ): array {
        if ($aliasRouteCount < 0
            || !self::validProductId($productId)
            || !in_array($productState, ['draft', 'published', 'archived'], true)
        ) {
            throw new InvalidArgumentException(
                'Store Lite destination projection is invalid.'
            );
        }

        $components = [];
        $publicDestinations = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException(
                    'Store Lite destination row is invalid.'
                );
            }
            $componentRecordId = self::positiveInteger(
                $row['ComponentRecordID'] ?? null
            );
            if ($componentRecordId === null) {
                throw new InvalidArgumentException(
                    'Store Lite destination component is invalid.'
                );
            }
            $components[$componentRecordId] = true;

            $routeRecordId = self::positiveInteger($row['RouteRecordID'] ?? null);
            if ($routeRecordId === null) {
                continue;
            }
            $path = self::publicPath($row);
            if ($path === null) {
                throw new InvalidArgumentException(
                    'Store Lite destination route is invalid.'
                );
            }
            $routeKey = $componentRecordId . ':' . $routeRecordId;
            if (isset($publicDestinations[$routeKey])) {
                throw new InvalidArgumentException(
                    'Store Lite destination route is duplicated.'
                );
            }
            $publicDestinations[$routeKey] = [
                'public' => self::databaseBoolean($row['ComponentPublic'] ?? null)
                    && self::databaseBoolean($row['RoutePublic'] ?? null),
                'path' => $path,
            ];
        }

        $expectedPath = '/' . rawurlencode($productId);
        $public = array_values(array_filter(
            $publicDestinations,
            static fn (array $destination): bool => $destination['public']
        ));
        if ($productState === 'published'
            && count($components) === 1
            && count($publicDestinations) === 1
            && count($public) === 1
        ) {
            return [
                'status' => 'published',
                'label' => 'Published',
                'path' => $public[0]['path'],
                'pathKind' => 'public',
                'reason' => 'One public route and product placement are active.',
            ];
        }
        if ($components === [] && $aliasRouteCount === 0) {
            return [
                'status' => 'missing',
                'label' => 'Missing',
                'path' => $expectedPath,
                'pathKind' => 'proposed',
                'reason' => 'No lifecycle-managed destination exists yet.',
            ];
        }

        $reason = $productState !== 'published'
            ? 'Publish the product before its destination can be public.'
            : ($components === []
                ? 'The proposed alias is already claimed by an Article route.'
                : 'The route and product placement are incomplete or duplicated.');
        return [
            'status' => 'repair_needed',
            'label' => 'Repair needed',
            'path' => $public[0]['path'] ?? $expectedPath,
            'pathKind' => 'expected',
            'reason' => $reason,
        ];
    }

    private static function publicPath(array $row): ?string
    {
        $segments = [];
        $section = self::routeSegment($row['Sections'] ?? null, true);
        if ($section === null) {
            return null;
        }
        if ($section !== '') {
            $segments[] = $section;
        }
        foreach (['Categories', 'SubCategories', 'RouteAlias'] as $key) {
            $segment = self::routeSegment($row[$key] ?? null, false);
            if ($segment === null) {
                return null;
            }
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }
        return $segments === []
            ? null
            : '/' . implode('/', array_map('rawurlencode', $segments));
    }

    private static function routeSegment(mixed $value, bool $home): ?string
    {
        if (!is_string($value)
            || strlen($value) > 255
            || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }
        $value = trim($value);
        if (str_contains($value, '/')) {
            return null;
        }
        return $home && strtolower($value) === 'home' ? '' : $value;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
        );
        return $integer === false ? null : (int) $integer;
    }

    private static function databaseBoolean(mixed $value): bool
    {
        return $value === 1 || $value === '1';
    }

    private static function validProductId(string $value): bool
    {
        return preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) === 1;
    }
}
