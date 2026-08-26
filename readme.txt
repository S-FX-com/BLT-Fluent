=== BLT Fluent ===
Contributors: sfx
Tags: fluentcart, fluentcrm, checkout, custom fields, membership
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect FluentCRM custom contact fields during FluentCart checkout and write them straight to the contact record.

== Description ==

BLT Fluent lets an administrator select which FluentCRM custom contact fields are collected during FluentCart checkout, order them by drag-and-drop, and enable that field set per product.

On submission the values are written directly to the customer's FluentCRM contact record. FluentCRM remains the single source of truth for member profile data: no ACF bridge, no WordPress user meta duplication, no Fluent Forms dependency.

Design principles:

1. FluentCRM owns the schema. The plugin never defines its own fields; it reads FluentCRM's existing custom contact field definitions and lets the administrator choose from them.
2. One field system, two entry points. The same fields are captured at signup and edited later through the existing profile-edit form: identical slugs, identical API calls.
3. No new source of truth. The plugin stores configuration only. All member data lives in FluentCRM.

== Installation ==

1. Install and activate FluentCart 1.4.0+ and FluentCRM 2.8.0+.
2. Upload and activate BLT Fluent.
3. Define your update token in wp-config.php if you use the private-repo updater: `define( 'BLT_FLUENT_GH_TOKEN', '...' );`
4. Go to BLT Fluent, tick the FluentCRM fields to collect, drag them into order, then map the products that should ask for them on the Products tab.

== Frequently Asked Questions ==

= Does deactivating the plugin lose my field configuration? =

No. Deactivation unschedules the update check and nothing else. Reactivating brings the prior setup back intact.

= Does deleting the plugin delete member data? =

Never. Deleting removes this plugin's own configuration, and only if "Delete BLT Fluent settings when the plugin is deleted" was ticked in advance. FluentCRM contacts, field definitions and stored values are always left alone, as are FluentCart orders and products.

= Are members asked for their profile details again at renewal? =

No. Renewal orders skip the fields by default; the behaviour is a toggle on the Advanced tab.

== Changelog ==

= 0.1.1 =
* Added a GitHub Actions release workflow: pushes to `main` build the distributable zip and publish a GitHub release tagged from the `Version` header (skipped if that tag already exists).
* Excluded `tests/` and `CLAUDE.md` from the built zip.
* Added `CLAUDE.md` documenting the versioning and release conventions.

= 0.1.0 =
* First build: dependency guards, daily update check, checkout capture with validation, field picker with drag-and-drop ordering, per-product field sets, pre-fill, renewal skip and diagnostics.
