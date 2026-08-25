# Store Lite theme integration

Store Lite exposes semantic product and subscription facts plus core-owned
purchase and intent forms. Its
`operational_content_package` lifecycle profile intentionally declares no
public or administrator assets, so the active RED-CMS theme—not package
runtime code—owns typography, layout, color, and interaction styling.

The optional recipe at
`examples/themes/starter-reference/store-lite-product.css` targets only the
Store Lite Product and Subscription components and starter-reference slots
that contain them. It provides:

- a stronger product-card hierarchy and responsive facts grid;
- desktop and mobile purchase-form layouts for simple and variable products;
- a focused recurring-price card and full-width subscription button treatment;
- focus-visible, disabled, active, and reduced-motion states;
- a paired Article-introduction treatment for lifecycle-managed product
  destination pages; and
- no product, client, route, price, or catalog data.

## Client-local installation

1. Review the recipe against the client's active theme and supported browsers.
2. Copy or import it into that client's theme-owned stylesheet. Do not copy it
   into RED-CMS core CSS and do not add it to Store Lite's manifest assets.
3. Verify one simple and one variable product at desktop and mobile widths.
4. Verify keyboard focus, long option labels, unavailable/disabled controls,
   and the no-JavaScript fallback.
5. Retain the previous theme stylesheet and its checksum before deployment.

The `:has()` layout enhancement is progressive. Without it, core markup and
purchase behavior remain available; the active theme's normal slot layout is
used instead.

## Destination provisioning

A lifecycle-managed product destination contains two separate records:

1. an active `Article` route that owns the public alias; and
2. a Store Lite Product component published onto that route.

The current Products administrator screen reports one read-only destination
state for each product: **Published**, **Missing**, or **Repair needed**. It
also shows the current public path or the proposed Product-ID path. This status
check does not write content or expose record identifiers.

For a published product whose destination is missing and unclaimed, the screen
also reports **Ready to provision**. That preview binds the current product
state and destination evidence to a deterministic plan hash covering four
future operations:

1. create the core Article route;
2. create a Store Lite Product component bound to the Product ID;
3. publish the component on that Article route; and
4. refresh Site Search only after the writes commit.

Published destinations report **No action needed**. Draft or archived products
must be published or restored first, and incomplete/colliding destinations
report **Repair first**. Every preview has `writesEnabled=false`; this gate adds
no button, endpoint, record allocation, transaction, or content mutation.

The next administrator gate should automate those same core operations behind
a preview-and-confirm control. It should derive record identifiers on
the server, check alias and product-placement collisions, require the current
`store.products.manage` grant, create/publish through the revisioned component
lifecycle, refresh Site Search after commit, and return repair/unpublish state.
It must not expose direct SQL, record-id fields, package paths, or filesystem
operations to the administrator.
