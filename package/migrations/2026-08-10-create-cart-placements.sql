CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Cart_Placements` (
  `ContentRecordID` int unsigned NOT NULL,
  `Title` varchar(160) NOT NULL,
  PRIMARY KEY (`ContentRecordID`),
  CONSTRAINT `fk_storelite_cart_placement_content` FOREIGN KEY (`ContentRecordID`)
    REFERENCES `RED_Articles` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_cart_placement_title` CHECK (
    CHAR_LENGTH(`Title`) BETWEEN 1 AND 160
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
