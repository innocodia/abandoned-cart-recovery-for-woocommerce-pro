# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

"Better Recovery Pro" — the paid add-on for the free plugin
`abandoned-cart-recovery-for-woocommerce` (in the sibling folder). **PHP only**:
no React, no build step for code. npm is used only to package the zip.

## Commands

```bash
composer install          # dev tooling (PHPCS + WPCS)
composer lint             # vendor/bin/phpcs (phpcs.xml.dist)
composer lint:fix         # vendor/bin/phpcbf
npm install               # packaging tools (archiver, fs-extra, micromatch)
npm run plugin:prepare    # copy into ./build (minus package.json buildExclude) + production autoloader
npm run plugin:publish    # plugin:prepare + zip into ./abandoned-cart-recovery-for-woocommerce-pro.zip
```

The zip contains one top folder, `abandoned-cart-recovery-for-woocommerce-pro/`,
and a production-only Composer autoloader (no dev packages).

## Architecture

Same pattern as the free plugin (read its CLAUDE.md):

- `abandoned-cart-recovery-for-woocommerce-pro.php` — singleton `ACRFWP` (`acrfwp()`).
  It starts on the free plugin's `acrfw_init` action, so every free class, table
  and hook already exists. If the free plugin is missing or older than
  `ACRFWP::MIN_FREE_VERSION`, Pro does nothing and shows an admin notice.
- `dispatch_hooks()` is the single list of top-level modules: `Installer`, `App`,
  `API`, `Frontend`, `Admin` (admin only).
  - `App/`: services that plug into the free plugin's actions/filters
    (e.g. `App\Stats\FollowupStats` answers `acrfw_followup_stats`).
  - `API/`: REST controllers only (extend `WP_REST_Controller`, register routes).
  - `Frontend/`: storefront hooks. `Admin/`: wp-admin only. Each is a thin dispatcher with a static
  `init()`. To add a capability, create a class under `includes/<Module>/` with
  its own static `init()` and register it in that module's dispatcher.
- Autoload: `ACRFWP\` → `includes/` (Composer PSR-4). `includes/functions.php`
  holds global helpers (prefix `acrfwp_`), `includes/hooks.php` truly global hooks.
- `Installer::check_update()` runs per load; upgrade steps go in
  `maybe_run_migrations()` (or hook `acrfwp_migrate`).

## Extending the free plugin

Pro features plug into the free plugin through its hooks rather than editing it:

- SMS / WhatsApp delivery: mark the channel available with the
  `acrfw_followup_channels` filter and send it in `acrfw_followup_send`
  (see the free plugin's `App\Followups\Channels` and `App\Messaging\FollowupSender`).
- Follow-up stats: read the free plugin's `acrfw_followup_messages` table
  (`acrfw_table( 'followup_messages' )`).
- REST routes: use the free plugin's namespace (`ACRFW\API::NAMESPACE_NAME` /
  `VERSION`) so the admin app talks to one API.

## Performance rule

Never load every order or every message into PHP for a number: sum or count in
SQL (see `FollowupStats::revenue()`, which reads HPOS tables or posts directly).
`wc_get_orders( 'limit' => -1 )` on an unbounded set is a bug.

## Conventions

WordPress Coding Standards (`phpcs.xml.dist`, prefixes `acrfwp` / `ACRFWP`,
text domain `abandoned-cart-recovery-for-woocommerce-pro`), `array()` syntax,
Yoda conditions, `%i` placeholders for table names, PHP 7.4 compatible.
