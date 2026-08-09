# Public Product Presenter Contract

Status: implemented in Store Lite 0.1.10 as the read-only prerequisite for the
public Product component.

`RED_CMS_Store_Lite_Public_Product_Presenter` converts one complete package
Product record into the RED-CMS core-owned public fact-card view model. It is
a pure adapter: it opens no database, reads no request or runtime state, emits
no markup, and performs no cart, inventory, order, or payment mutation.

Before returning any public values, the presenter repeats the complete Store
Lite product normalization contract against the installation currency. Draft,
archived, malformed, and currency-drifted products return no view model.

The successful output has exactly:

```text
title, summary, facts[{label, value}]
```

Core owns escaping and semantic HTML. Store Lite supplies only bounded text:

- the normalized public title and optional summary;
- one fixed two-decimal currency price or variable-product price range;
- effective availability, including stock and variant availability; and
- each variable option-group label with its bounded value labels.

This contract intentionally omits raw HTML, media URLs, canonical URLs,
variant-selection controls, add-to-cart actions, browser identity, and mutable
commerce state. The next gate must bind an exact package-owned placement record
to one published product and invoke this presenter through the enabled Product
component. A later cart gate must still resolve selected variants, price,
currency, availability, and stock again on the server.
