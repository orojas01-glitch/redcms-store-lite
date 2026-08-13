ALTER TABLE `RED_Addon_StoreLite_Orders`
  ADD KEY `idx_storelite_order_payment_status` (`PaymentStatus`, `RecordID`);
