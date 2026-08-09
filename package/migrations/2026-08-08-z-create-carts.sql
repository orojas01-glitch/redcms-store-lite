CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Carts` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `SubjectRecordID` int unsigned NOT NULL,
  `Currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_cart_subject` (`SubjectRecordID`),
  CONSTRAINT `chk_storelite_cart_subject` CHECK (`SubjectRecordID` BETWEEN 1 AND 4294967295),
  CONSTRAINT `chk_storelite_cart_currency` CHECK (`Currency` REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Cart_Lines` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `CartRecordID` bigint unsigned NOT NULL,
  `ProductRecordID` bigint unsigned NOT NULL,
  `VariantRecordID` bigint unsigned DEFAULT NULL,
  `LineIdentitySHA256` binary(32) NOT NULL,
  `Quantity` tinyint unsigned NOT NULL,
  `UnitPriceMinor` int unsigned NOT NULL,
  `Currency` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `LineTotalMinor` bigint unsigned NOT NULL,
  `ProductStateSHA256` binary(32) NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_cart_line_identity` (`CartRecordID`, `LineIdentitySHA256`),
  KEY `idx_storelite_cart_line_product` (`ProductRecordID`, `VariantRecordID`),
  CONSTRAINT `fk_storelite_cart_line_cart` FOREIGN KEY (`CartRecordID`)
    REFERENCES `RED_Addon_StoreLite_Carts` (`RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_cart_line_product` FOREIGN KEY (`ProductRecordID`)
    REFERENCES `RED_Addon_StoreLite_Products` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_cart_line_variant` FOREIGN KEY (`ProductRecordID`, `VariantRecordID`)
    REFERENCES `RED_Addon_StoreLite_Product_Variants` (`ProductRecordID`, `RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_cart_line_quantity` CHECK (`Quantity` BETWEEN 1 AND 100),
  CONSTRAINT `chk_storelite_cart_line_price` CHECK (`UnitPriceMinor` <= 999999999),
  CONSTRAINT `chk_storelite_cart_line_currency` CHECK (`Currency` REGEXP '^[A-Z]{3}$'),
  CONSTRAINT `chk_storelite_cart_line_total` CHECK (
    `LineTotalMinor` = (`UnitPriceMinor` * `Quantity`)
    AND `LineTotalMinor` <= 99999999900
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Cart_Activity` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `EventName` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `CartRecordID` bigint unsigned NOT NULL,
  `SubjectRecordID` int unsigned NOT NULL,
  `LineIdentitySHA256` binary(32) NOT NULL,
  `PreviousStateSHA256` binary(32) NOT NULL,
  `StateSHA256` binary(32) NOT NULL,
  `OccurredAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  KEY `idx_storelite_cart_activity` (`CartRecordID`, `RecordID`),
  CONSTRAINT `chk_storelite_cart_activity_event` CHECK (
    `EventName` IN ('cart.line.created', 'cart.line.updated')
  ),
  CONSTRAINT `chk_storelite_cart_activity_cart` CHECK (`CartRecordID` > 0),
  CONSTRAINT `chk_storelite_cart_activity_subject` CHECK (`SubjectRecordID` BETWEEN 1 AND 4294967295),
  CONSTRAINT `chk_storelite_cart_activity_state` CHECK (
    `PreviousStateSHA256` <> `StateSHA256`
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
