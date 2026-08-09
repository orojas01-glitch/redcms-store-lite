CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Product_Placements` (
  `ContentRecordID` int unsigned NOT NULL,
  `ProductRecordID` bigint unsigned NOT NULL,
  PRIMARY KEY (`ContentRecordID`),
  KEY `idx_storelite_placement_product` (`ProductRecordID`),
  CONSTRAINT `fk_storelite_placement_content` FOREIGN KEY (`ContentRecordID`)
    REFERENCES `RED_Articles` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_placement_product` FOREIGN KEY (`ProductRecordID`)
    REFERENCES `RED_Addon_StoreLite_Products` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
