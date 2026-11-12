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
    (`App\Stats\FollowupStats` answers `acrfw_followup_stats`;
    `App\Conditions\Conditions` answers `acrfw_validate_conditions` and
    `acrfw_conditions_match`; the field list stays in the free plugin's
    `App\Followups\ConditionFields`; `App\Blacklist\Blacklist` answers
    `acrfw_is_blacklisted` from the free plugin's `blacklist.*` settings;
    `App\Gdpr\PhoneConsent` adds the phone notice to `acrfw_gdpr_notices` when
    `gdpr.enable_phone_gdpr` is on — the free plugin renders it and handles the opt-out).
  - `API/`: REST controllers only (extend `WP_REST_Controller`, register routes). `API\ProductsReport` serves `GET /reports/products` and `/reports/products/export` (CSV, sent raw through `rest_pre_serve_request`) from `App\Reports\ProductsReport`, which groups the free plugin's `acrfw_cart_items` in SQL.
  - `Frontend/`: storefront hooks. `Admin/`: wp-admin only. Each is a thin dispatcher with a static
  `init()`. To add a capability, create a class under `includes/<Module>/` with
  its own static `init()` and register it in that module's dispatcher.
- Autoload: `ACRFWP\` → `includes/` (Composer PSR-4). `includes/functions.php`
  holds global helpers (prefix `acrfwp_`), `includes/hooks.php` truly global hooks.
- `Installer::check_update()` runs per load; upgrade steps go in
  `maybe_run_migrations()` (or hook `acrfwp_migrate`).

## Licensing (Freemius)

- **SDK:** the Freemius WordPress SDK (2.10.1) lives in **`license/`** (copied as-is, the same layout as Better Badge Pro). Lint excludes it; the build ships it.
- **Init:** `acrfwp_fs()` is defined and called at the top of the bootstrap file, before anything else:
  - product id 44750, slug `abandoned-cart-recovery-for-woocommerce-pro`;
  - menu slug `acrfw-license` under the free plugin's `acrfw` menu.
- **License submenu:** `ACRFWP\Admin\License` adds Better Recovery → **License** (`admin.php?page=acrfw-license`).
  - Not activated: Freemius shows its activation screen there (license key).
  - Activated: `load-{hook}` redirects to the Freemius account page `acrfw-license-account`.
- **Free plugin menu script:** `acrfw-admin-menu.js` only intercepts SPA links (`page=acrfw` exactly), so the License link opens normally.
- **License check:** `acrfwp_fs()->is_registered() && acrfwp_fs()->has_active_valid_license()`. Pro features are **not** gated by it yet.

## Uninstall

There is **no `uninstall.php`**: Freemius owns the uninstall hook, and its rules forbid the file. The cleanup lives in the bootstrap file:
- `acrfwp_fs_uninstall_cleanup()` → `acrfwp_uninstall_site()` per site;
- it's registered with `acrfwp_fs()->add_action( 'after_uninstall', … )`.

It deletes Pro's own `acrfwp_*` options when the free plugin's Danger Zone switch (`advanced.delete_plugin_data`) is on, or when the free settings are already gone. All other data (settings, tables) belongs to the free plugin and is removed by its own uninstaller. If Pro ever stores its own data, add it there.

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
