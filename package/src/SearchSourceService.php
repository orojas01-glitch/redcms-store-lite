<?php

declare(strict_types=1);

/**
 * Bounded public-product document provider for optional search packages.
 *
 * The service exposes no price, stock, currency, availability value, cart,
 * order, administrator, or database identity. Product publication state and
 * hierarchy eligibility are used only to decide whether a document exists.
 */
final class RED_CMS_Store_Lite_Search_Source_Service
{
    public const SERVICE = 'content.search-source.store-lite';
    public const OPERATION = 'documents.list';
    public const MAX_BATCH = 8;

    public static function handle(
        RED_Addon_Service_Request $request
    ): RED_Addon_Service_Result {
        if ($request->service() !== self::SERVICE
            || $request->operation() !== self::OPERATION
        ) {
            return RED_Addon_Service_Result::failure(
                'search_source_operation_unavailable'
            );
        }
        $input = $request->input();
        if (array_keys($input) !== ['cursor', 'limit']
            || !is_int($input['cursor'] ?? null)
            || $input['cursor'] < 0
            || !is_int($input['limit'] ?? null)
            || $input['limit'] < 1
            || $input['limit'] > self::MAX_BATCH
        ) {
            return RED_Addon_Service_Result::failure(
                'search_source_request_invalid'
            );
        }

        $connection = null;
        try {
            $connection = self::runtimeConnection();
            if (!mysqli_begin_transaction(
                $connection,
                MYSQLI_TRANS_START_READ_ONLY
            )) {
                throw new RuntimeException(
                    'Store Lite search snapshot is unavailable.'
                );
            }
            $documents = self::documents(
                $connection,
                $input['cursor'],
                $input['limit']
            );
            mysqli_rollback($connection);
            return RED_Addon_Service_Result::success($documents);
        } catch (Throwable $throwable) {
            if ($connection instanceof mysqli) {
                try {
                    mysqli_rollback($connection);
                } catch (Throwable $rollbackFailure) {
                    // The typed failure remains value-free.
                }
            }
            return RED_Addon_Service_Result::failure(
                'search_source_storage_unavailable'
            );
        } finally {
            if ($connection instanceof mysqli) {
                mysqli_close($connection);
            }
        }
    }

    public static function projectDocument(array $row): ?array
    {
        $placementId = filter_var(
            $row['ContentRecordID'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
        );
        $productId = is_string($row['ProductID'] ?? null)
            ? $row['ProductID']
            : '';
        $language = strtolower(trim((string) ($row['Language'] ?? '')));
        $title = self::excerpt(self::plainText($row['Title'] ?? ''), 160);
        $summary = self::excerpt(
            self::plainText($row['Summary'] ?? ''),
            500
        );
        $publicUrl = self::publicUrl($row);
        if ($placementId === false
            || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $productId) !== 1
            || preg_match('/\A[a-z]{2}\z/D', $language) !== 1
            || $title === ''
            || $publicUrl === '/'
        ) {
            return null;
        }
        return [
            'placementCursor' => (int) $placementId,
            'sourceType' => 'store-lite-product',
            'sourceRecordId' => $productId,
            'language' => $language,
            'title' => $title,
            'summary' => $summary,
            'keywords' => self::excerpt(
                self::plainText($productId . ' ' . $title),
                500
            ),
            'publicUrl' => $publicUrl,
            'sourceUpdatedAt' => (string) ($row['SourceUpdatedAt'] ?? ''),
        ];
    }

    private static function documents(
        mysqli $connection,
        int $cursor,
        int $limit
    ): array {
        $queryLimit = $limit + 1;
        $statement = mysqli_prepare(
            $connection,
            "SELECT placement.ContentRecordID, product.ProductID,
                    product.Title, product.Summary, article.Language,
                    article.Sections, article.Categories,
                    article.SubCategories, article.Alias,
                    GREATEST(product.UpdatedAt, article.Updated)
                      AS SourceUpdatedAt
             FROM RED_Addon_StoreLite_Product_Placements AS placement
             INNER JOIN RED_Addon_StoreLite_Products AS product
               ON product.RecordID=placement.ProductRecordID
             INNER JOIN RED_Articles AS article
               ON article.RecordID=placement.ContentRecordID
             WHERE placement.ContentRecordID>?
               AND product.State='published'
               AND product.Availability='available'
               AND article.Active='Y'
               AND article.Component='redcms.store-lite/product'
               AND article.Alias<>''
               AND (YEAR(article.StartDate)=0 OR article.StartDate<=NOW())
               AND (YEAR(article.ExpDate)=0 OR article.ExpDate>=NOW())
               AND EXISTS (
                 SELECT 1 FROM RED_Sections AS source_section
                 WHERE source_section.Sections=article.Sections
                   AND source_section.Language=article.Language
                   AND source_section.Active='Y'
               )
               AND (
                 article.Categories=''
                 OR EXISTS (
                   SELECT 1
                   FROM RED_Categories AS source_category
                   INNER JOIN RED_Sections AS category_section
                     ON category_section.RecordID=source_category.SectionRecordID
                    AND category_section.Language=source_category.Language
                   WHERE source_category.Categories=article.Categories
                     AND source_category.Language=article.Language
                     AND source_category.Active='Y'
                     AND category_section.Sections=article.Sections
                     AND category_section.Active='Y'
                 )
               )
               AND (
                 article.SubCategories=''
                 OR EXISTS (
                   SELECT 1
                   FROM RED_SubCategories AS source_subcategory
                   INNER JOIN RED_Categories AS subcategory_category
                     ON subcategory_category.RecordID=source_subcategory.CategoryRecordID
                    AND subcategory_category.Language=source_subcategory.Language
                   INNER JOIN RED_Sections AS subcategory_section
                     ON subcategory_section.RecordID=subcategory_category.SectionRecordID
                    AND subcategory_section.Language=subcategory_category.Language
                   WHERE source_subcategory.SubCategories=article.SubCategories
                     AND source_subcategory.Language=article.Language
                     AND source_subcategory.Active='Y'
                     AND subcategory_category.Categories=article.Categories
                     AND subcategory_category.Active='Y'
                     AND subcategory_section.Sections=article.Sections
                     AND subcategory_section.Active='Y'
                 )
               )
             ORDER BY placement.ContentRecordID ASC
             LIMIT ?"
        );
        if (!$statement) {
            throw new RuntimeException(
                'Store Lite search query is unavailable.'
            );
        }
        mysqli_stmt_bind_param($statement, 'ii', $cursor, $queryLimit);
        if (!mysqli_stmt_execute($statement)) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('Store Lite search query failed.');
        }
        $result = mysqli_stmt_get_result($statement);
        $rows = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $document = self::projectDocument($row);
            if (!is_array($document)) {
                mysqli_free_result($result);
                mysqli_stmt_close($statement);
                throw new RuntimeException(
                    'Store Lite search projection is invalid.'
                );
            }
            $rows[] = $document;
        }
        if ($result) {
            mysqli_free_result($result);
        }
        mysqli_stmt_close($statement);
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        $nextCursor = $cursor;
        $documents = [];
        foreach ($rows as $row) {
            $nextCursor = $row['placementCursor'];
            unset($row['placementCursor']);
            $documents[] = $row;
        }
        return [
            'documents' => $documents,
            'nextCursor' => $nextCursor,
            'more' => $more,
        ];
    }

    private static function publicUrl(array $row): string
    {
        $segments = [];
        $section = trim((string) ($row['Sections'] ?? ''));
        if ($section !== '' && strtolower($section) !== 'home') {
            $segments[] = $section;
        }
        foreach (['Categories', 'SubCategories', 'Alias'] as $key) {
            $segment = trim((string) ($row[$key] ?? ''));
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }
        return '/' . implode('/', array_map('rawurlencode', $segments));
    }

    private static function plainText($value): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return '';
        }
        $text = html_entity_decode(
            strip_tags($value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $text = preg_replace('/\s+/u', ' ', trim($text));
        return is_string($text) ? $text : '';
    }

    private static function excerpt(string $value, int $limit): string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters) || count($characters) <= $limit) {
            return $value;
        }
        return rtrim(implode('', array_slice($characters, 0, $limit - 1))) . '…';
    }

    private static function runtimeConnection(): mysqli
    {
        foreach (['DBHOST', 'DBUSER', 'DBPASS', 'DBNAME'] as $constant) {
            if (!defined($constant)) {
                throw new RuntimeException(
                    'Store Lite search database configuration is unavailable.'
                );
            }
        }
        $hostPort = (string) constant('DBHOST');
        $host = $hostPort;
        $port = 3306;
        if (preg_match('/\A([^:]+):([0-9]{1,5})\z/D', $hostPort, $parts) === 1) {
            $host = $parts[1];
            $port = (int) $parts[2];
        }
        $connection = mysqli_init();
        if (!$connection instanceof mysqli) {
            throw new RuntimeException(
                'Store Lite search database connection is unavailable.'
            );
        }
        mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        mysqli_real_connect(
            $connection,
            $host,
            (string) constant('DBUSER'),
            (string) constant('DBPASS'),
            (string) constant('DBNAME'),
            $port
        );
        if (!mysqli_set_charset($connection, 'utf8mb4')) {
            mysqli_close($connection);
            throw new RuntimeException(
                'Store Lite search database connection is unavailable.'
            );
        }
        return $connection;
    }
}

