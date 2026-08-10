# Store Lite Cart-Line Command Contract

Status: implemented as a pure, unregistered Store Lite 0.1.20 package
boundary. It does not change a cart, declare a route, or render a control.

## Purpose

This contract defines the only browser-declarable inputs for two later cart
line mutations. Quantity replacement and removal are separate intents:

```text
set quantity: line, quantity
remove line:  line
```

The public `line` value is exactly `line-` plus the complete lowercase
64-character SHA-256 identity already stored for the product/variant line. It
is 69 bytes, exposes no database record identifier, and is never truncated.
The handle is a reference, not a secret or bearer capability.

## Authorization boundary

The handle alone never selects a cart. A later caller must first obtain the
current anonymous subject from RED-CMS core and resolve the decoded line
identity only inside that subject's cart. A line absent from that cart must be
refused without disclosing whether the same handle exists for another subject.

The browser cannot declare a cart identifier, subject identifier, product,
variant, SKU, price, currency, total, stock value, or authorization decision.

## Closed commands

`setQuantity()` accepts the exact ordered fields `line, quantity`, where
quantity is an integer from 1 through 100. Zero is not removal. Its internal
command contains only:

```text
operation=set_quantity, lineIdentitySha256, quantity
```

`removeLine()` accepts only `line`. Its internal command contains only:

```text
operation=remove_line, lineIdentitySha256
```

Unknown fields, reordered fields, numeric strings, floats, booleans,
out-of-range quantities, raw hashes, uppercase hashes, shortened handles, and
expanded removal intents all produce the same `invalid_intent` refusal with no
partial command.

## Deliberate exclusions

This gate does not:

- expose a handle through the Cart read model or presenter;
- register update or removal public-mutation contracts;
- resolve a cookie, session, subject, cart, product, or line;
- check current product state, price, stock, or a requested quantity;
- open a database, transaction, request, response, filesystem, or network;
- update or delete a line, write activity, or decrement inventory; or
- add quantity controls, remove buttons, checkout, orders, or payments.

Those are separate storage, bridge, core-dispatch, and browser gates.

## Verification

`tests/cart-line-command-self-test.php` proves the exact 69-byte handle,
inclusive quantity bounds, distinct closed commands, uniform fail-closed
refusals, and the absence of database, request, write, or network behavior.
