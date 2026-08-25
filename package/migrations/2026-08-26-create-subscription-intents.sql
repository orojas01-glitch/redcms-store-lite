CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Subscription_Placements` (
  `ContentRecordID` int unsigned NOT NULL,
  `OfferRecordID` bigint unsigned NOT NULL,
  PRIMARY KEY (`ContentRecordID`),
  KEY `idx_storelite_subscription_placement_offer` (`OfferRecordID`),
  CONSTRAINT `fk_storelite_subscription_placement_content`
    FOREIGN KEY (`ContentRecordID`) REFERENCES `RED_Articles` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_subscription_placement_offer`
    FOREIGN KEY (`OfferRecordID`)
    REFERENCES `RED_Addon_StoreLite_Subscription_Offers` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RED_Addon_StoreLite_Subscription_Intents` (
  `RecordID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `SubjectRecordID` int unsigned NOT NULL,
  `OfferRecordID` bigint unsigned NOT NULL,
  `OfferStateSHA256` binary(32) NOT NULL,
  `Status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
    DEFAULT 'requested',
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`RecordID`),
  UNIQUE KEY `uq_storelite_subscription_intent_subject_offer`
    (`SubjectRecordID`, `OfferRecordID`),
  KEY `idx_storelite_subscription_intent_offer`
    (`OfferRecordID`, `RecordID`),
  CONSTRAINT `fk_storelite_subscription_intent_subject`
    FOREIGN KEY (`SubjectRecordID`)
    REFERENCES `RED_Addon_Public_Mutation_Subjects` (`RecordID`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_storelite_subscription_intent_offer`
    FOREIGN KEY (`OfferRecordID`)
    REFERENCES `RED_Addon_StoreLite_Subscription_Offers` (`RecordID`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_storelite_subscription_intent_status`
    CHECK (`Status` = 'requested')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
