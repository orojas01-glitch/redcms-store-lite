# RED-CMS Store Lite

Store Lite is an optional, separately distributed first-party package for
RED-CMS 5.1. It is not part of the clean RED-CMS starter and must be deployed
independently for each client installation.

## Current status

Gate 25 established the separate package foundation. Gate 26A adds immutable
catalog migrations for products, option groups, option values, explicit variants,
and variant selections. Gate 26B adds a package-owned pure normalizer that
enforces the approved simple/variable product contract before any write. Gate
26C adds the internal package-owned catalog reader and atomic create/replace
writer. Gate 26D adds a read-only, permission-scoped product administration
model with bounded cursor pagination, exact edit loading, and deterministic
create/replace preflight. Gate 26E adds an internal reauthorizing product action
runner and a package-owned, value-free product activity ledger. Gate 26F adds a
pure browser-submission decoder for exact create/replace evidence and bounded
simple or variable product fields. The schema, normalizer, persistence boundary,
and administration model support both simple products and bounded variable
products while keeping every business table beneath the
`RED_Addon_StoreLite_` namespace. Gate 26G is defined in
[`docs/PRODUCT-FORM-BRIDGE-CONTRACT.md`](docs/PRODUCT-FORM-BRIDGE-CONTRACT.md).
Its first implementation is a pure typed value adapter for the existing core
form graph. It reserves the bridge for an already-existing product's numeric
package `RecordID` and does not treat a public `ProductID` as a target. Package
0.1.9 also declares a target-free Add product action and registers the
initial-value loader and atomic creator used by the core-owned Create bridge.
New records start as unavailable drafts and must satisfy the same
simple/variable product contract before creation. Normal richer-package
activation and public commerce behavior remain unavailable.

Package 0.1.11 adds the Product component relationship and runtime bridge. One
package-owned placement row binds one core `RED_Articles` parent to one existing
catalog product, with restrictive foreign keys on both references. The package
loader/creator/writer/deleter callbacks operate only inside the core-owned
transaction boundary, while the public handler opens a separate read-only path,
reloads the complete normalized product, and returns the 0.1.10 presenter's
closed title, summary, price, availability, and option facts. It emits no HTML
and creates no cart, order, inventory, or payment mutation. The exact public
presentation boundary remains documented in
[`docs/PUBLIC-PRODUCT-PRESENTER-CONTRACT.md`](docs/PUBLIC-PRODUCT-PRESENTER-CONTRACT.md).

Package 0.1.12 adds the first pure server-authoritative cart-line resolver.
It accepts only a public product identifier, integer quantity from 1 through
100, and one required variant identifier for variable products. The caller
supplies the current complete server-loaded product and installation currency;
the resolver repeats normalization and derives the exact SKU, option labels,
integer unit price and total, currency, stock evidence, and product-state hash.
Browser-owned commercial values and every stale, unavailable, mismatched, or
out-of-stock selection fail closed without a partial line. The resolver is not
registered as `commerce.cart`. Its exact pure-calculation boundary is in
[`docs/CART-LINE-RESOLVER-CONTRACT.md`](docs/CART-LINE-RESOLVER-CONTRACT.md).

Package 0.1.13 adds internal cart persistence for a future core transaction
runner. One core-issued numeric anonymous-subject relation owns one package
cart; raw browser tokens and cookies are never stored. The caller supplies an
active transaction and expected cart-state hash. Store Lite locks current cart,
line, product, and selected-variant rows, re-resolves every commercial fact from
current server storage, verifies the postcondition, and records one value-free
activity fact without beginning, committing, or rolling back. Cart lines
restrict product and variant deletion and cascade only with their cart. The
class remains unregistered and non-routable: no public control, cookie, order,
checkout, inventory mutation, or payment behavior is added. Its exact boundary
is in
[`docs/CART-PERSISTENCE-CONTRACT.md`](docs/CART-PERSISTENCE-CONTRACT.md).

Package 0.1.22 declares and registers separate typed set-quantity and remove-
line mutations beside Add to cart. Each operation owns a unique static POST
route, closed form fields, core-issued anonymous subject, CSRF, idempotency,
rate-limit, no-store, table, audit, and server-postcondition requirements. The
bridge verifies the route/mutation pair again, uses subject-cart currency for
existing lines, and maps a true quantity no-op to the core `unchanged` outcome.
No Cart browser control is rendered by this gate. See
[`docs/CART-MUTATION-BRIDGE-CONTRACT.md`](docs/CART-MUTATION-BRIDGE-CONTRACT.md).

Package 0.1.23 adds a pure, data-only Cart control presenter. For one current
server-derived line identity and quantity, it returns separate closed
set-quantity and remove-line form models using the existing declared mutation
pairs and complete public line handle. It emits no HTML and supplies no action,
subject, CSRF, idempotency, price, stock, total, or authorization evidence. The
models remain unregistered until a later core-owned bounded per-row form
contract can compose and render them. See
[`docs/PUBLIC-CART-CONTROL-PRESENTER-CONTRACT.md`](docs/PUBLIC-CART-CONTROL-PRESENTER-CONTRACT.md).

Package 0.1.24 completes the package-owned Cart row binding. The read model
projects each stored lowercase line identity only after verifying it against
the current public product and optional variant identity. The Cart presenter
uses that value and the current quantity to attach the approved quantity and
remove presentation models to every non-empty collection row. The raw SHA is
not displayed, and the package still supplies no browser evidence, action,
HTML, dispatch, response, or write authority; those remain core-owned gates.

Package 0.1.25 begins the guest-order gate with a pure immutable snapshot
contract. Given only a complete server-derived cart, closed guest checkout
intent, and current installation configuration, it supports pickup, delivery,
or both; snapshots the configured flat delivery fee and required delivery
address; and keeps order, payment, and fulfillment states separate. The
provider-neutral payment taxonomy reserves pay on receipt, Stripe Checkout,
PayPal, manual Zelle, and Nequi. Apple Pay and Google Pay remain Stripe funding
methods, and Venmo remains a PayPal funding method rather than separate Store
Lite adapters. This gate opens no database or request, stores no order, invokes
no provider, and cannot mark a payment paid. See
[`docs/GUEST-ORDER-SNAPSHOT-CONTRACT.md`](docs/GUEST-ORDER-SNAPSHOT-CONTRACT.md).

Package 0.1.26 adds the internal atomic persistence boundary for that exact
snapshot. It locks and revalidates the current subject cart against current
catalog products and variants, rebuilds the 0.1.25 snapshot, generates an
opaque server order ID, stores the immutable header/lines/option labels plus
one initial status-history fact, and consumes the source cart inside the same
caller-owned transaction. A SHA-only idempotency key replays only the exact
stored snapshot and refuses changed facts. The class remains unregistered and
non-routable: checkout input, core dispatch, inventory mutation, provider
calls, paid-state transitions, and order administration remain later gates.
See
[`docs/ORDER-PERSISTENCE-CONTRACT.md`](docs/ORDER-PERSISTENCE-CONTRACT.md).

Package 0.1.27 defines the pure guest-checkout browser boundary without linking
it to the runtime. One closed twelve-field decoder accepts only bounded contact,
one enabled fulfillment choice, delivery-only address facts, and one payment
method that is both configured and explicitly server-ready. Its data-only
presenter exposes the configured pickup/delivery fee facts and only ready
payment choices, but supplies no route, action, mutation, evidence, or markup.
Current core mutation forms cannot yet render the required text, email, phone,
textarea, and conditional controls, so the model remains unregistered and is
not attached to the Cart component. See
[`docs/GUEST-CHECKOUT-CONTRACT.md`](docs/GUEST-CHECKOUT-CONTRACT.md).

Package 0.1.28 links that closed browser boundary to the existing core-owned
public-mutation runner. A non-empty Cart now carries one core-rendered checkout
form for pickup or delivery and pay on receipt. Five required non-secret
installation settings bind currency, pickup, delivery, delivery fee, and
pay-on-receipt readiness inside the locked transaction. The typed bridge
decodes the twelve declared strings, locks and rebuilds the current server
cart, persists the immutable order, and consumes the cart atomically. Core
retains subject, CSRF, rate-limit, idempotency, transaction, replay, audit,
response, and browser-evidence authority. Hosted payments, inventory decrement,
order administration, and customer notification remain later gates.

Package 0.1.29 is a migration-only recovery rehearsal. Two append-only
package-owned migrations add exact fulfillment-status and payment-status
indexes to the existing order header for the already-declared read-only Orders
workspace. They do not add a route, handler, payment adapter, order mutation,
customer notification, inventory change, or starter state. The RED-CMS
Release C2 rehearsal proves that a forced failure after the first index leaves
0.1.28 identity and business rows intact, then resumes only the second
migration and commits the 0.1.29 identity while the package remains disabled.

Package 0.1.30 is a database-compatibility correction for fresh installation.
The existing media-reference migration now applies its column expansion on
Percona/MySQL 5.7 without attempting unsupported `DROP CHECK` syntax, while
version-gated MySQL 8 and MariaDB branches replace their enforced named checks.
It adds no route, runtime handler, business mutation, client data, or starter
state.

Package 0.1.31 completes that fresh-install compatibility correction for the
cart-activity event expansion. Percona/MySQL 5.7 receives an exact no-drift
`EventName` column declaration, while MySQL 8 and MariaDB replace the enforced
event allowlist through their version-gated clauses. All ten migrations are
rehearsed through the same `mysqli_multi_query()` execution path used by the
RED-CMS installer before hosted deployment resumes.

Package 0.1.32 begins P3B with a pure provider-neutral payment-event transition
decision. It accepts only an exact current hosted-payment order projection and
an already-verified, unseen P0 event whose order, immutable snapshot, payment
method, amount, and currency match. Paid, confirmed full refund, and reversal
have distinct closed targets; a reversal blocks fulfillment without inventing
a refund or cancellation, while failed, cancelled, and expired events cannot
change the order. The class is integrity-listed but unregistered: it opens no
database, writes no history, exposes no route, resolves no secret, and invokes
no provider. See
[`docs/PAYMENT-EVENT-TRANSITION-CONTRACT.md`](docs/PAYMENT-EVENT-TRANSITION-CONTRACT.md).

Package 0.1.14 binds that persistence to RED-CMS's internal atomic
public-mutation runner. It declares one closed Add-to-cart POST contract with
only product, integer quantity, and optional variant fields; registers one
fail-closed route callback, handler, and state loader; and exposes only a
cart-state hash plus line count to the core postcondition. The core still owns
subject, CSRF, idempotency, rate limit, transaction, replay, audit, and response
authority. There is no public endpoint, browser form/cookie, cart display,
checkout, or operational `commerce.cart` service. See
[`docs/CART-MUTATION-BRIDGE-CONTRACT.md`](docs/CART-MUTATION-BRIDGE-CONTRACT.md).

Package 0.1.15 adds a pure data-only public cart-form presenter. It derives
only the declared product, quantity, and (for variable products) one bounded
sellable-variant selector from a current complete product record. It supplies
no browser security evidence or commercial facts and is not yet invoked by the
public Product component. The exact presentation boundary is in
[`docs/PUBLIC-CART-FORM-PRESENTER-CONTRACT.md`](docs/PUBLIC-CART-FORM-PRESENTER-CONTRACT.md).

Package 0.1.16 binds that presenter to the public Product component return
model. A currently sellable simple or variable product now carries one exact
`mutationForm` description beside the existing title, summary, and facts. An
unavailable product keeps its display model without a mutation presentation.
The package still emits no HTML and supplies no subject, CSRF, idempotency,
cookie, endpoint, response, script, or client state; RED-CMS core remains the
only authority allowed to validate, bootstrap, and render the future control.

Package 0.1.17 adds the pure read-only Cart presenter. It accepts only an
already server-derived installation-currency projection, verifies exact
integer quantities and totals, and returns the core-owned bounded collection
model for an empty cart or up to twenty-four simple/variant lines. It opens no
database, reads no browser identity, emits no markup, and adds no component,
route, mutation, checkout, order, or payment behavior. See
[`docs/PUBLIC-CART-PRESENTER-CONTRACT.md`](docs/PUBLIC-CART-PRESENTER-CONTRACT.md).

Package 0.1.18 adds the internal read-only Cart projection. Given one
core-resolved numeric anonymous subject and the installation currency, it
loads only that subject's cart, current product titles, ordered variant option
labels, stored quantities, and integer money. It caps the public projection at
twenty-four lines and fails closed on currency, relationship, label, total, or
storage drift. It does not read a cookie or request, write state, register a
component or service, or expose database identities. See
[`docs/CART-READ-MODEL-CONTRACT.md`](docs/CART-READ-MODEL-CONTRACT.md).

Package 0.1.19 makes that projection available as the optional placeable Cart
component. One package-owned placement row stores only the core content parent
and a bounded public heading. At render time the package derives one exact
catalog currency, asks RED-CMS core for the current anonymous subject, loads
only that subject's cart, applies the pure presenter, and returns the generic
core collection model. A visitor without a current subject receives the same
empty-cart model and no package identity is created. The component performs no
cart update, line removal, checkout, order, inventory, or payment mutation and
emits no HTML. See
[`docs/CART-COMPONENT-CONTRACT.md`](docs/CART-COMPONENT-CONTRACT.md).

Package 0.1.20 defines the pure command boundary for later editable cart
lines. It uses one complete 69-byte public line handle that exposes no database
record identifier and remains scoped by the core-owned anonymous subject.
Setting quantity accepts only that handle plus an integer from 1 through 100;
removing a line is a separate handle-only intent, never quantity zero. The
contract performs no lookup, write, route registration, or browser rendering.
See
[`docs/CART-LINE-COMMAND-CONTRACT.md`](docs/CART-LINE-COMMAND-CONTRACT.md).

Package 0.1.21 binds those two commands to caller-owned transactional package
storage. Set quantity locks the current subject cart and product, replaces the
quantity rather than incrementing it, and refreshes current server price,
stock, total, and product-state evidence. Remove line resolves only inside the
current subject cart and remains available when the product is no longer
sellable. Both require fresh cart-state evidence and write value-free activity;
same quantity plus unchanged commercial state is an activity-free no-op. This
gate adds no public route, bridge registration, or browser control. See
[`docs/CART-PERSISTENCE-CONTRACT.md`](docs/CART-PERSISTENCE-CONTRACT.md).

Catalog creation refuses an existing product ID. Replacement requires the exact
SHA-256 of the current normalized product state and refuses stale input. Each
write owns its transaction, reloads the complete stored product graph before
commit, and rolls back on a partial write or mismatched postcondition. It does
not expose SQL diagnostics or accept a caller-owned transaction.

The administration model requires a fresh exact `store.products.manage`
capability decision from RED-CMS. Owner or add-on lifecycle access does not
substitute for that grant. It returns no partial catalog on authorization,
storage, cursor, or reconstruction failure. Its create/replace plans bind the
actor, product identity, current state, and normalized target state but never
write; a later core-owned, CSRF-protected endpoint must pass the exact plan to
the internal action runner.

The internal action runner consumes the exact preflight plan, repeats the
product capability and plan decision inside the writer-owned transaction, and
records one `product.created` or `product.updated` fact before the same commit.
A changed plan, revoked grant, failed activity insert, or mismatched stored
postcondition rolls back the entire product mutation. Activity rows contain
only product identity, actor identity, event type, state hashes, and time; they
do not copy product titles, descriptions, SKUs, prices, stock, or option data.

The submission decoder accepts no request globals or CSRF token. Core must
authenticate the administrator and consume CSRF before passing the exact
remaining field map. The decoder converts canonical decimal browser strings to
integer minor-unit prices and stock, normalizes the complete product contract,
and refuses unknown fields, ambiguous numbers, recursive structures, and
payloads beyond fixed byte, node, or depth bounds. It does not render a form,
open a database, or invoke the action runner.

The manifest declares the Product and read-only Cart components, planned
commerce services, and read-only Products and Orders administrator tools, but normal package
activation remains blocked. Products is operational only for listing, creating,
and editing products through core-owned authenticated/CSRF controls in an
explicitly prepared enabled installation. The Product component and core-owned
Add/Place workflow are operational only in that same acceptance-only enabled
installation. The Cart component resolves an existing
core-owned anonymous subject without creating one. The Orders administrator
tool and commerce services remain fail-closed placeholders. Public subject/token
bootstrap and Add-to-cart
dispatch remain core-owned acceptance paths. Quantity update and line removal
now have pure input, transactional storage, typed mutation-bridge contracts,
package-owned data-only form presentations, and completed core-owned browser
dispatch QA. The guest-order snapshot, pay-on-receipt checkout form, typed
checkout bridge, and atomic order persistence are fixed. Inventory mutation,
assets, provider calls, payment transitions, order administration, customer
notification, and configured-method presentation filtering remain later gates.

The RED-CMS core rehearsal stages this package outside the starter in one
fresh disposable schema and records an acceptance-only enabled installation.
Its internal setup first proves accepted/replayed/conflicting simple-product
cart intent, exact variable-product variant intent, and invalid-variant
rollback through the real core runner. Its authenticated desktop/mobile path
then proves Products -> Add product -> Create
-> reload plus existing target -> Edit -> Save -> reload while preserving a
variable product graph. It also binds one published product to a homepage
component through the package callback and verifies the public semantic fact
card before login at both viewports. It then removes the server, schema, grant,
and staged package. This does not weaken the normal Owner enablement blockers.

## Distribution boundary

- `package/` is the exact package payload.
- Deploy that payload beneath a client's
  `addons/redcms/store-lite/` directory.
- Never copy this repository into the RED-CMS clean starter.
- Install package migrations only in that client's database.
- Keep products, orders, settings, secrets, media, and other business state in
  the client installation, never in this source repository.
- Disabling Store Lite must retain package data. Purge is not available in this
  foundation.

## Foundation verification

Set `RED_CMS_CORE_ROOT` when the RED-CMS checkout is not the default sibling
directory, then run:

```sh
php tests/package-foundation-self-test.php
php tests/product-normalizer-self-test.php
php tests/cart-line-resolver-self-test.php
php tests/cart-line-command-self-test.php
php tests/guest-order-snapshot-self-test.php
php tests/payment-event-transition-self-test.php
php tests/guest-checkout-contract-self-test.php
php tests/product-form-values-self-test.php
php tests/public-product-presenter-self-test.php
php tests/public-cart-form-presenter-self-test.php
php tests/public-cart-control-presenter-self-test.php
php tests/public-cart-presenter-self-test.php
php tests/catalog-administration-submission-self-test.php
php tests/catalog-migration-self-test.php
php tests/catalog-persistence-self-test.php
```

The foundation test stages the payload under a disposable project root,
validates it with the RED-CMS 5.1 non-executing manifest contract, proves the
richer package remains activation-blocked, and confirms the core checkout still
has no deployed `addons/` directory.

The normalizer test has no database, request, runtime, filesystem, or network
dependency. It proves canonical simple/variable records and fail-closed refusal
of malformed, stale, duplicate, unbounded, or mixed product data.

The cart-line resolver test is also dependency-free. It proves that the
current normalized server product is the only source of SKU, option labels,
price, currency, stock sufficiency, product-state evidence, and integer line
total. The browser-shaped intent contains only product, quantity, and optional
variant; invalid or unavailable selections return no partial line.

The cart-line command test is dependency-free. It proves the complete bounded
public line handle, separate set-quantity and remove-line inputs, integer
quantity bounds, uniform refusal, and the absence of subject, storage, route,
request, write, or network behavior.

The guest-order snapshot test is dependency-free. It proves pickup and
delivery, a server-derived flat delivery fee, immutable simple and exact
Size/Color line facts, distinct order/payment/fulfillment initial states,
provider-neutral payment intent, delegated wallet refusal, deterministic
hashes, closed PII/address fields, integer totals, and fail-closed invalid
inputs without persistence or provider access.

The payment-event transition test is dependency-free. Its 30 assertions prove
exact paid, full-refund, reversal, and non-transition decisions; reversal-only
fulfillment blocking; provider-neutral hosted-method reuse; deterministic
value-free evidence; replay, state, identity, amount, currency, and raw-field
refusal; and the absence of database, request, registration, secret-resolution,
or network behavior.

The guest-checkout contract test is dependency-free. It proves the exact
twelve-field and byte bounds, configured-plus-ready payment intersection,
data-only presentation, exact core mutation-form model, conditional delivery
rules, pickup and delivery decoding into the immutable snapshot,
unready-provider refusal, closed browser input, bounded PII, and absence of
external side effects.

The submission test is also dependency-free. It proves exact create/replace
evidence, canonical browser-scalar conversion, normalized simple/variable
products, value-free validation errors, CSRF-field separation, and fixed
payload bounds without reading request globals or mutating state.

The catalog test uses a uniquely named disposable MySQL database. It grants the
configured application account access only to that database, applies the exact
ordered manifest migrations, proves the simple and variable product constraints
plus exact cart ownership, line, activity, order header, immutable order-child,
and foreign-key shapes, and removes the database and grant. The configured
primary database is fingerprinted before and after and must remain unchanged.

The persistence test requires a PHP CLI runtime with `mysqli`. It creates a
second uniquely named disposable database, exercises exact simple and variable
product reloads, stale-state refusal, atomic replacement, forced rollback,
caller-owned transaction refusal, exact Product placement create/load/write/
delete participation in a caller-owned transaction, exact product permission
isolation, bounded
catalog pages, full edit loading, non-writing create/replace preflight, and
reauthorizing action execution with atomic value-free activity, immediate grant
revocation, plan-substitution refusal, and activity-failure rollback. It also
proves caller-owned cart transactions, simple and variant line persistence,
server-derived money, fresh/stale state, anonymous-subject isolation, stock and
unknown-input refusal, late cart-activity rollback, and restrictive product
references. It additionally proves absolute quantity setting, current-product
repricing, a true no-op, cross-subject line-handle refusal, removal of an
unavailable product, and removal-activity rollback. It also proves the internal
order boundary against an exact simple-plus-variant delivery snapshot and an
address-free pickup/pay-on-receipt snapshot, caller-owned transaction refusal,
current-product stale-cart refusal, forced late-history rollback, atomic cart
consumption, immutable line/option/history readback, exact idempotent replay,
changed-snapshot conflict, and second-order refusal after cart consumption. It
also proves the typed pickup and delivery pay-on-receipt bridge, configured
delivery fee, opaque execution evidence, hosted-provider refusal, and atomic
cart consumption. It then
removes the database and scoped grant. The configured primary database is again
fingerprinted before and after and must remain unchanged.
