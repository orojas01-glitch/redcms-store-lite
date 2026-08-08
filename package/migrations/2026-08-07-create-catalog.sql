CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Products` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ProductID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ProductType` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `Title` varchar(160) NOT NULL,
  `Summary` varchar(1000) DEFAULT NULL,
  `Currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `State` varchar(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `Availability` varchar(11) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unavailable',
  `ImageReference` varchar(70) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `SKU` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `PriceMinor` int unsigned DEFAULT NULL,
  `Stock` int unsigned DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_product_id` (`ProductID`),
  UNIQUE KEY `uq_storelite_product_record` (`RecordID`, `ProductID`),
  CONSTRAINT `chk_storelite_product_id` CHECK (`ProductID` REGEXP '^[a-z][a-z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_product_type` CHECK (`ProductType` IN ('simple', 'variable')),
  CONSTRAINT `chk_storelite_product_title` CHECK (CHAR_LENGTH(`Title`) BETWEEN 1 AND 160),
  CONSTRAINT `chk_storelite_product_summary` CHECK (`Summary` IS NULL OR CHAR_LENGTH(`Summary`) BETWEEN 1 AND 1000),
  CONSTRAINT `chk_storelite_product_currency` CHECK (`Currency` REGEXP '^[A-Z]{3}$'),
  CONSTRAINT `chk_storelite_product_state` CHECK (`State` IN ('draft', 'published', 'archived')),
  CONSTRAINT `chk_storelite_product_availability` CHECK (`Availability` IN ('available', 'unavailable')),
  CONSTRAINT `chk_storelite_product_image` CHECK (`ImageReference` IS NULL OR `ImageReference` REGEXP '^media:[a-z][a-z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_product_sku` CHECK (`SKU` IS NULL OR `SKU` REGEXP '^[A-Z0-9][A-Z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_product_price` CHECK (`PriceMinor` IS NULL OR `PriceMinor` <= 999999999),
  CONSTRAINT `chk_storelite_product_stock` CHECK (`Stock` IS NULL OR `Stock` <= 1000000000),
  CONSTRAINT `chk_storelite_product_sellable` CHECK (
    (`ProductType` = 'simple' AND `SKU` IS NOT NULL AND `PriceMinor` IS NOT NULL)
    OR
    (`ProductType` = 'variable' AND `SKU` IS NULL AND `PriceMinor` IS NULL AND `Stock` IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Product_Options` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ProductRecordID` bigint unsigned NOT NULL,
  `OptionKey` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `Label` varchar(160) NOT NULL,
  `Position` tinyint unsigned NOT NULL,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_option_key` (`ProductRecordID`, `OptionKey`),
  UNIQUE KEY `uq_storelite_option_position` (`ProductRecordID`, `Position`),
  UNIQUE KEY `uq_storelite_option_record` (`ProductRecordID`, `RecordID`),
  CONSTRAINT `fk_storelite_option_product` FOREIGN KEY (`ProductRecordID`)
    REFERENCES `RED_Addon_StoreLite_Products` (`RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_option_key` CHECK (`OptionKey` REGEXP '^[a-z][a-z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_option_label` CHECK (CHAR_LENGTH(`Label`) BETWEEN 1 AND 160),
  CONSTRAINT `chk_storelite_option_position` CHECK (`Position` BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Product_Option_Values` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ProductRecordID` bigint unsigned NOT NULL,
  `OptionRecordID` bigint unsigned NOT NULL,
  `ValueID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `Label` varchar(160) NOT NULL,
  `Position` tinyint unsigned NOT NULL,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_value_id` (`OptionRecordID`, `ValueID`),
  UNIQUE KEY `uq_storelite_value_position` (`OptionRecordID`, `Position`),
  UNIQUE KEY `uq_storelite_value_record` (`ProductRecordID`, `OptionRecordID`, `RecordID`),
  CONSTRAINT `fk_storelite_value_option` FOREIGN KEY (`ProductRecordID`, `OptionRecordID`)
    REFERENCES `RED_Addon_StoreLite_Product_Options` (`ProductRecordID`, `RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_value_id` CHECK (`ValueID` REGEXP '^[a-z][a-z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_value_label` CHECK (CHAR_LENGTH(`Label`) BETWEEN 1 AND 160),
  CONSTRAINT `chk_storelite_value_position` CHECK (`Position` BETWEEN 1 AND 16)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Product_Variants` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ProductRecordID` bigint unsigned NOT NULL,
  `VariantID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `SKU` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `OptionTupleSHA256` binary(32) NOT NULL,
  `Position` tinyint unsigned NOT NULL,
  `PriceMinor` int unsigned NOT NULL,
  `Availability` varchar(11) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unavailable',
  `Stock` int unsigned DEFAULT NULL,
  `ImageReference` varchar(70) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_variant_id` (`ProductRecordID`, `VariantID`),
  UNIQUE KEY `uq_storelite_variant_sku` (`ProductRecordID`, `SKU`),
  UNIQUE KEY `uq_storelite_variant_tuple` (`ProductRecordID`, `OptionTupleSHA256`),
  UNIQUE KEY `uq_storelite_variant_position` (`ProductRecordID`, `Position`),
  UNIQUE KEY `uq_storelite_variant_record` (`ProductRecordID`, `RecordID`),
  CONSTRAINT `fk_storelite_variant_product` FOREIGN KEY (`ProductRecordID`)
    REFERENCES `RED_Addon_StoreLite_Products` (`RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_variant_id` CHECK (`VariantID` REGEXP '^[a-z][a-z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_variant_sku` CHECK (`SKU` REGEXP '^[A-Z0-9][A-Z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_variant_position` CHECK (`Position` BETWEEN 1 AND 128),
  CONSTRAINT `chk_storelite_variant_price` CHECK (`PriceMinor` <= 999999999),
  CONSTRAINT `chk_storelite_variant_availability` CHECK (`Availability` IN ('available', 'unavailable')),
  CONSTRAINT `chk_storelite_variant_stock` CHECK (`Stock` IS NULL OR `Stock` <= 1000000000),
  CONSTRAINT `chk_storelite_variant_image` CHECK (`ImageReference` IS NULL OR `ImageReference` REGEXP '^media:[a-z][a-z0-9._-]{0,63}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Product_Variant_Selections` (
  `ProductRecordID` bigint unsigned NOT NULL,
  `VariantRecordID` bigint unsigned NOT NULL,
  `OptionRecordID` bigint unsigned NOT NULL,
  `OptionValueRecordID` bigint unsigned NOT NULL,
  PRIMARY KEY (`VariantRecordID`, `OptionRecordID`),
  KEY `idx_storelite_selection_value` (`ProductRecordID`, `OptionRecordID`, `OptionValueRecordID`),
  CONSTRAINT `fk_storelite_selection_variant` FOREIGN KEY (`ProductRecordID`, `VariantRecordID`)
    REFERENCES `RED_Addon_StoreLite_Product_Variants` (`ProductRecordID`, `RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_selection_value` FOREIGN KEY (`ProductRecordID`, `OptionRecordID`, `OptionValueRecordID`)
    REFERENCES `RED_Addon_StoreLite_Product_Option_Values` (`ProductRecordID`, `OptionRecordID`, `RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
