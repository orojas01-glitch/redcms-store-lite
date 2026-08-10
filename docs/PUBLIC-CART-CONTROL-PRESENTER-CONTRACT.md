# Store Lite Public Cart Control Presenter Contract

Status: Store Lite 0.1.23 pure presentation boundary. This gate does not
attach controls to the Cart component or render a browser form.

## Purpose

`RED_CMS_Store_Lite_Public_Cart_Control_Presenter::present()` accepts exactly
one server-derived lowercase line-identity SHA-256 plus that line's current
integer quantity. It returns two separate, closed form presentation models:

- `quantityForm` declares the existing set-quantity route and mutation, the
  complete public line handle, and a number field initialized to the current
  quantity.
- `removeForm` declares the existing remove-line route and mutation and the
  same complete public line handle. Removal has no quantity field and quantity
  zero remains invalid.

The quantity remains bounded from 1 through 100. Unknown or reordered input,
an invalid identity, a numeric string, a float, a boolean, or an out-of-range
quantity fails closed with no partial model.

## Authority boundary

The returned arrays contain no action URL, method, HTML, anonymous subject,
cookie, CSRF token, idempotency key, rate-limit decision, cart or database
record identifier, price, currency, stock, total, or authorization result.
The `line-` handle is a public reference, not a secret or bearer capability.
Core must still resolve it only inside the current core-owned subject's cart.

Each returned form has the same package-field shape already accepted by the
core-owned public-mutation form composer. This does not authorize Store Lite to
compose or render that form: core must validate the matching manifest contract,
issue browser evidence, derive the action, escape markup, dispatch the request,
own the response, and run the mutation transaction.

## Deliberate integration pause

The generic RED-CMS collection view model currently permits facts per cart
line but not per-row forms. This presenter therefore remains pure and
unregistered in 0.1.23. A later core integration gate must define a bounded
multi-form collection contract, attach these models to server-loaded cart
lines, and prove desktop/mobile dispatch. No RED-CMS core file, hosted demo,
checkout, order, inventory decrement, or payment behavior changes here.
