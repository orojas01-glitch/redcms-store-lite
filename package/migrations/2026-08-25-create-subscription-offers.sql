CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Subscription_Offers` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `OfferID` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ProductRecordID` bigint unsigned NOT NULL,
  `VariantRecordID` bigint unsigned DEFAULT NULL,
  `Title` varchar(160) NOT NULL,
  `Summary` varchar(1000) DEFAULT NULL,
  `Currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `PriceMinor` int unsigned NOT NULL,
  `BillingPeriod` varchar(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `State` varchar(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `Availability` varchar(11) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unavailable',
  `ButtonLabel` varchar(80) NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_subscription_offer_id` (`OfferID`),
  UNIQUE KEY `uq_storelite_subscription_offer_record` (`RecordID`, `OfferID`),
  KEY `idx_storelite_subscription_product` (`ProductRecordID`, `State`, `Availability`),
  KEY `idx_storelite_subscription_variant` (`ProductRecordID`, `VariantRecordID`),
  CONSTRAINT `fk_storelite_subscription_product` FOREIGN KEY (`ProductRecordID`)
    REFERENCES `RED_Addon_StoreLite_Products` (`RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_subscription_variant` FOREIGN KEY (`ProductRecordID`, `VariantRecordID`)
    REFERENCES `RED_Addon_StoreLite_Product_Variants` (`ProductRecordID`, `RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_subscription_offer_id` CHECK (`OfferID` REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$'),
  CONSTRAINT `chk_storelite_subscription_title` CHECK (CHAR_LENGTH(`Title`) BETWEEN 1 AND 160),
  CONSTRAINT `chk_storelite_subscription_summary` CHECK (`Summary` IS NULL OR CHAR_LENGTH(`Summary`) BETWEEN 1 AND 1000),
  CONSTRAINT `chk_storelite_subscription_currency` CHECK (`Currency` REGEXP '^[A-Z]{3}$'),
  CONSTRAINT `chk_storelite_subscription_price` CHECK (`PriceMinor` <= 999999999),
  CONSTRAINT `chk_storelite_subscription_period` CHECK (`BillingPeriod` IN ('monthly', 'yearly')),
  CONSTRAINT `chk_storelite_subscription_state` CHECK (`State` IN ('draft', 'published', 'archived')),
  CONSTRAINT `chk_storelite_subscription_availability` CHECK (`Availability` IN ('available', 'unavailable')),
  CONSTRAINT `chk_storelite_subscription_button` CHECK (CHAR_LENGTH(`ButtonLabel`) BETWEEN 1 AND 80)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
