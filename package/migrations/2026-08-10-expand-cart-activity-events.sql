ALTER TABLE `RED_Addon_StoreLite_Cart_Activity`
  DROP CHECK `chk_storelite_cart_activity_event`,
  ADD CONSTRAINT `chk_storelite_cart_activity_event` CHECK (
    `EventName` IN (
      'cart.line.created',
      'cart.line.updated',
      'cart.line.quantity-set',
      'cart.line.removed'
    )
  );
