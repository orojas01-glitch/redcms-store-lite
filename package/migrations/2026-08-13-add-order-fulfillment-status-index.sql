ALTER TABLE `RED_Addon_StoreLite_Orders`
  ADD KEY `idx_storelite_order_fulfillment_status` (`FulfillmentStatus`, `RecordID`);
