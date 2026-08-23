# Store Lite theme integration

Store Lite exposes semantic product facts and core-owned purchase forms. Its
`operational_content_package` lifecycle profile intentionally declares no
public or administrator assets, so the active RED-CMS theme—not package
runtime code—owns typography, layout, color, and interaction styling.

The optional recipe at
`examples/themes/starter-reference/store-lite-product.css` targets only the
Store Lite Product component and a starter-reference slot that contains that
component. It provides:

- a stronger product-card hierarchy and responsive facts grid;
- desktop and mobile purchase-form layouts for simple and variable products;
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

The future administrator workflow should automate those same core operations
behind a preview-and-confirm control. It should derive record identifiers on
the server, check alias and product-placement collisions, require the current
`store.products.manage` grant, create/publish through the revisioned component
lifecycle, refresh Site Search after commit, and return repair/unpublish state.
It must not expose direct SQL, record-id fields, package paths, or filesystem
operations to the administrator.
