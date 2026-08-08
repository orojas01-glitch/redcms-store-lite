# RED-CMS Store Lite

Store Lite is an optional, separately distributed first-party package for
RED-CMS 5.1. It is not part of the clean RED-CMS starter and must be deployed
independently for each client installation.

## Current status

This repository contains the Gate 25 package foundation only. The manifest
declares the planned Product component, commerce services, and read-only Orders
administrator tool, but the package is intentionally not operational or
enableable yet. Product persistence, catalog editing, cart and order behavior,
public mutations, routes, assets, and payment handling remain later gates.

The entry point registers fail-closed placeholders. Any attempted invocation
throws before business behavior can occur.

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
```

The test stages the payload under a disposable project root, validates it with
the RED-CMS 5.1 non-executing manifest contract, proves the richer package
remains activation-blocked, and confirms the core checkout still has no
deployed `addons/` directory.
