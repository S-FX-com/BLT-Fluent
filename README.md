# BLT Fluent

A series of extensions for FluentCart & FluentCRM.

BLT Fluent lets an administrator pick which **FluentCRM custom contact fields** are
collected during **FluentCart checkout**, order them by drag-and-drop, and enable
that field set per product. Submitted values are written straight to the
customer's FluentCRM contact record.

FluentCRM owns the schema; this plugin stores configuration only. No ACF bridge,
no user-meta duplication, no Fluent Forms dependency.

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.5 (for the `Requires Plugins` header) |
| PHP | 7.4 |
| FluentCart | 1.4.0 |
| FluentCRM | 2.8.0 |

Dependencies are enforced in three layers: the `Requires Plugins` header, an
activation guard that blocks direct/WP-CLI activation, and a runtime guard that
shows a notice and registers nothing — but never self-deactivates, so
configuration is never silently destroyed.

## Install for development

```bash
git clone git@github.com:s-fx-com/blt-fluent.git wp-content/plugins/blt-fluent
cd wp-content/plugins/blt-fluent
bin/install-puc.sh          # fetches Plugin Update Checker into vendor/
```

Plugin Update Checker is not committed. The plugin works without it — only the
self-update mechanism is inert, and the Diagnostics panel says so.

For the private-repo updater, add a fine-grained read-only PAT to `wp-config.php`:

```php
define( 'BLT_FLUENT_GH_TOKEN', 'github_pat_...' );
```

## Release

1. Bump `Version:` in `blt-fluent.php` and `Stable tag:` in `readme.txt`.
2. `bin/build-zip.sh`
3. Tag `vX.Y.Z` and attach `build/blt-fluent-X.Y.Z.zip` as a release asset.

The site checks for updates once a day at local midnight: PUC's own scheduler is
disabled (`$checkPeriod = 0`) and a WP-Cron event anchored to `wp_timezone()`
drives `checkForUpdates()` instead. Note that WP-Cron is lazy — on a low-traffic
site the event fires on the first page load after midnight. The Plugins screen
"Check for updates" link and the Diagnostics tab button both still work for
manual checks.

## Data lifecycle

| Event | Config in `wp_options` | Cron event | FluentCRM data |
|---|---|---|---|
| Activate | Preserved if present; defaults seeded only if absent | Scheduled if not already | Untouched |
| Deactivate | **Preserved** | Unscheduled | Untouched |
| Reactivate | **Preserved** | Rescheduled | Untouched |
| Plugin update | Preserved; migrated by `version` key | Preserved | Untouched |
| Uninstall (delete) | Deleted **only if** `delete_on_uninstall` is true | Unscheduled | **Always untouched** |

The uninstall opt-in covers this plugin's configuration only. FluentCRM contacts,
field definitions and values, FluentCart orders and products, and anything written
by the profile-edit form are never deleted.

## FluentCart hooks used

| Stage | Hook | Type |
|---|---|---|
| Render | `fluent_cart/before_payment_methods` (filterable) | action |
| Validate | `fluent_cart/checkout/validate_data` | filter |
| Persist | `fluent_cart/checkout/prepare_other_data` | action |

`fluent_cart/checkout/customer_data_saved` was **removed in FluentCart 1.4.0** and
is deliberately not used — a callback on it never runs, silently.

The render hook defaults to `fluent_cart/before_payment_methods` because it fires
in the standard, modal *and* block renderers. `fluent_cart/checkout/b2b_extra_fields`
fires in the full-page renderer only.

## Filters

Every assumption about FluentCart's payload shapes is overridable without a code
change:

| Filter | Purpose |
|---|---|
| `blt_fluent/render_hook` | Swap the action the fields render on |
| `blt_fluent/cart_pairs` | Override the detected product/variation pairs |
| `blt_fluent/cart_sources` | Add a FluentCart cart accessor to try |
| `blt_fluent/field_set_key` | Choose the field set for the current cart outright |
| `blt_fluent/is_renewal` | Override the renewal decision |
| `blt_fluent/prepared_fields` | Add, remove or reshape fields before render |
| `blt_fluent/multi_value` | Change how multi-value fields are serialised |
| `blt_fluent/error_key` | Change the key validation errors are reported under |
| `blt_fluent/contact_email` | Supply the contact email when detection fails |
| `blt_fluent/crm_contact_payload` | Adjust the FluentCRM `createOrUpdate` payload |
| `blt_fluent/order_meta_payload` | Adjust or skip the order-meta audit trail |
| `blt_fluent/crm_field_definitions` | Adjust the FluentCRM field definitions |
| `blt_fluent/capability` | Capability required to manage the plugin |
| `blt_fluent/github_url`, `blt_fluent/github_branch`, `blt_fluent/github_token` | Updater source |

Actions: `blt_fluent/booted`, `blt_fluent/after_render`, `blt_fluent/values_written`,
`blt_fluent/captured`.

## Tests

```bash
php tests/smoke-test.php
```

`tests/bootstrap.php` is a small WordPress shim — enough of the API for the
sanitisation, normalisation, validation and render code to run from the CLI. The
FluentCRM reader is swapped for a stub, so the tests never need FluentCart or
FluentCRM installed. CI runs the same script plus `php -l` on 7.4, 8.2 and 8.4.

## Build status

| Phase | Deliverable | State |
|---|---|---|
| 0 | Skeleton, dependency guards, PUC + cron | Built |
| 1 | Checkout capture end-to-end | Built — needs live verification |
| 2 | Validation | Built |
| 3 | Config layer (picker, ordering, product map) | Built |
| 4 | Pre-fill + renewal skip | Built |
| 5 | Hardening (multi-value, orphans, modal, i18n) | Built — items 1, 4, 5 in `docs/VERIFY.md` need a live install |

`docs/VERIFY.md` lists the assumptions that can only be settled against
equinephotographers.org, each with the filter that fixes it if the assumption
turns out wrong. Turn on **Advanced → Record a diagnostic log** before starting:
the log names the cart-detection strategy, the FluentCRM read path, and every
skipped or aborted write.
