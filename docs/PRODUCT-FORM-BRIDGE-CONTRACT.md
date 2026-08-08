# Store Lite Product Form Bridge Contract

Status: Gate 26G package bridge implemented in Store Lite 0.1.7. It defines
the first editable Product form without making Store Lite activatable,
exposing administrator navigation, or adding a public catalog, cart, order,
payment, or client data flow.

## Purpose

RED-CMS 5.1's generic administrator-form bridge accepts one exact, existing,
positive numeric target record. Store Lite therefore uses the package-owned
`RED_Addon_StoreLite_Products.RecordID` as the edit target. `ProductID` remains
the stable public product identifier; it is never accepted as a core form
target.

This slice supports only a future edit of one already-existing product. Product
creation is deliberately out of scope: it needs a separate, owner-authorized
allocation and target-discovery flow before an editor can receive a positive
target. The generic editor must not accept zero, a string identifier, or a
package-selected target in order to create products.

The form declares the package-owned `catalog.currency` setting as required
runtime state. Core resolves the configured non-secret value only for this
exact enabled package/form binding and injects it into the loader and writer.
The current catalog rows carry a currency value, but that row value is not a
safe substitute for the one installation currency contract. Missing,
malformed, or changed setting state therefore fails closed before an edit can
complete.

## Form identity and authority

- Tool: `redcms.store-lite/products`
- Form: `redcms.store-lite/product-editor`
- Permission: `store.products.manage`
- Method/transport: the existing core `POST`, header-CSRF, canonical JSON
  bridge.
- Target: one positive Store Lite product `RecordID` in the current client
  database.

The package value loader must resolve that `RecordID`, reload its complete
product graph, and return no result for a missing, malformed, cross-client, or
currency-mismatched record. It may not derive authority from Owner, lifecycle
access, a visible tool, or a caller-supplied `ProductID`.

## Typed value shape

The manifest form is closed and core-rendered. It contains only product fields
already admitted by the approved Store Lite product contract: identity, product
type, title, optional summary and image reference, state, availability, simple
SKU/price/stock fields, option groups with values, and explicit variants with
option selections.

The bridge carries native typed integers, null for empty optional scalar fields,
ordered collections, and exact field keys. A package-private adapter converts
that closed graph into the existing normalized Store Lite product record. It
must reject a simple product with option or variant values; reject a variable
product with simple SKU/price/stock values; and reject missing, duplicate,
mixed, or unknown option selections. Browser decimal-string decoding remains a
separate prior boundary and is not reused by this typed core form path.

## Atomic update requirements

The form writer must bind the current numeric target to the reloaded product
before any write. A submitted product identifier must equal the target's
current `ProductID`; changing it through this edit form is forbidden. The
writer uses only the Store Lite product, option, option-value, variant,
variant-selection, and activity tables declared by the package. It must repeat
the exact current `store.products.manage` grant and state checks under the
core-owned transaction, preserve the package target identity, reload the full
postcondition, and emit only the existing value-free mutation outcome.

The package may not commit, roll back, create an additional transaction,
produce output, change headers, access request/session globals, or add a
route. Core retains session validation, CSRF consumption, stale replay
refusal, audit fact, and result redaction.

## Explicitly deferred

- Product target list, pagination UI, product creation, and package navigation
- Installation-currency configuration UI and its immutable-after-catalog policy
- Product component creation/placement and public catalog rendering
- Cart, checkout, orders, payment, settings, media upload, and public mutation
- Package activation, client installation, or migration application

These require their own accepted core boundary and disposable-database
acceptance gates. This contract must not be used to add Store Lite files to the
RED-CMS clean starter.

## Acceptance requirements for implementation

Implementation must prove, in a uniquely named disposable database:

1. Exact tool/form and positive numeric package-record targeting.
2. Fresh permission, enabled-installation, manifest, loader, writer, and table
   ownership checks.
3. Complete simple and variable product round trips with typed core values.
4. Refusal of missing targets, changed product IDs, stale state, grant
   revocation, contract/version/table drift, invalid option tuples, partial
   writes, activity failure, output, exceptions, and caller-owned transactions.
5. Exact cleanup of the temporary package, grant, database, tables, and audit
   fixtures, while the retained primary database fingerprint remains unchanged.
