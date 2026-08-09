# Store Lite Public Cart Form Presenter Contract

Status: introduced in Store Lite 0.1.15 as a pure, data-only presentation
adapter and bound to the public Product component return model in 0.1.16. It
does not render HTML, issue browser authority, or register a new route.

## Purpose

`RED_CMS_Store_Lite_Public_Cart_Form_Presenter::present()` receives one
complete product-shaped record and the installation currency. It repeats the
package product normalization contract and returns either `null` or this closed
form model:

```php
[
    'route' => 'redcms.store-lite/cart-intent',
    'mutation' => 'redcms.store-lite/add-to-cart',
    'submitLabel' => 'Add to cart',
    'fields' => [/* declared public mutation fields only */],
]
```

A simple sellable product exposes the required hidden `product` identifier and
the required `quantity` number field. A sellable variable product adds one
`variant` select. The select contains only current variants whose availability
is `available` and whose stock is either unbounded or greater than zero. At
most 128 variant options can appear, matching the established Store Lite
product bound.

The preferred label is the complete option tuple, such as
`Size: Small · Color: Red`. If that label is too long or duplicates another
sellable option, the normalized unique SKU is used instead. A product with no
safe, unique variant option model fails closed.

## Authority boundary

This model provides only route/mutation identity, product and variant
identifiers, a fixed initial quantity, and human-readable option labels. It
does not provide price, currency, stock quantity, SKU (except as a bounded
label fallback), totals, image data, cart state, cookies, CSRF values,
idempotency keys, anonymous subject evidence, or HTML.

The future core dispatcher must still validate the declared form schema and
re-resolve product, variant, availability, stock, price, currency, and totals
inside the mutation transaction. A displayed choice is not an authorization or
commercial fact.

## Product component binding

The Product component first reconstructs and normalizes one current stored
product, then builds its existing semantic fact-card model. When this presenter
also returns a valid sellable-product model, the bridge appends it under the
exact `mutationForm` key. If the product is displayable but unavailable or has
no safe sellable choice, the fact-card remains available without that key.

This binding still produces only data. There is no public browser form,
subject, CSRF or idempotency evidence, cookie, endpoint, cart display,
checkout, order behavior, or payment behavior in this package gate.

The next integration gate is core-owned: it must issue the anonymous-subject,
CSRF, and idempotency evidence; combine this model with the validated core form
UI primitive; and prove generic server response behavior at desktop and mobile
viewports.
