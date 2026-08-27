# Live verification checklist

Seven assumptions in the spec cannot be settled without a real FluentCart +
FluentCRM install. Each one below has a test, the expected result, and the exact
lever to pull if the result differs — no code change should be needed.

Before starting: **BLT Fluent → Advanced → Record a diagnostic log**. The
Diagnostics panel then shows the cart-detection strategy in use, the FluentCRM
read path, and every skip or abort with its reason.

## 1. `Requires Plugins` slugs

**Test.** On WP 6.5+ with both plugins deleted, look at the BLT Fluent row on the
Plugins screen. It should say the plugin requires FluentCart and FluentCRM and grey
out Activate.

**If the row shows nothing:** a slug is wrong — the header fails silently. Confirm
the wp.org directory slugs and correct the `Requires Plugins:` line in
`blt-fluent.php`. The activation guard still blocks activation either way.

## 2. Does the render hook fire in the modal renderer?

**Test.** Map a product on the Products tab, tick one field, then open checkout
both full-page and through the modal/inline flow used for signup.

**Expected.** The field block appears in both.

**If the modal shows nothing:** try the alternative hook.

```php
add_filter( 'blt_fluent/render_hook', fn() => 'fluent_cart/checkout/b2b_extra_fields' );
```

Registering both is fine — render is idempotent per request, but check for a
duplicated block if you do.

## 3. Is the cart detected?

**Test.** Load checkout with a mapped product. The diagnostic log should not
contain `No cart products detected`.

**If it does:** the log entry names the strategy that was tried. Pin the cart
contents directly:

```php
add_filter( 'blt_fluent/cart_pairs', function ( $pairs ) {
	return $pairs ?: array( array( 'product_id' => 123, 'variation_id' => 0 ) );
} );
```

Or bypass detection and force a set:

```php
add_filter( 'blt_fluent/field_set_key', fn( $key ) => $key ?: 'default' );
```

A better fix is a new accessor in `blt_fluent/cart_sources` once the real
FluentCart API is known — put it in `Cart_Context::cart_sources()`.

## 4. Do arbitrary POST fields reach `prepare_other_data`?

**Test.** Submit a checkout with one text field filled in. Check the contact's
Custom Profile Data in FluentCRM, and the order's `_blt_fluent_fields` meta.

**Expected.** Both carry the value. The log shows `CRM write ok`.

**If nothing is written:** the log says which step bailed.
`Persist aborted: could not determine an email address` means the order object
did not expose one — supply it:

```php
add_filter( 'blt_fluent/contact_email', function ( $email, $order, $request ) {
	return $email ?: ( $request['billing']['email'] ?? '' );
}, 10, 3 );
```

If the values themselves are missing, FluentCart is not passing request data
through: the code already falls back to `$_POST`, so check whether the fields are
inside the submitted form at all (see item 5).

## 5. AJAX fragment survival

**Test.** Half-fill a field, then change the billing country (or apply a coupon)
to trigger FluentCart's fragment re-render.

**Expected.** The value is still there — `assets/checkout.js` mirrors values into
`sessionStorage` and restores them whenever the block reappears.

**If the whole block disappears** and never returns, our fields live inside a
replaced fragment. Re-inject on
`fluent_cart/checkout/after_patch_checkout_data_fragments`, calling
`Plugin::instance()->checkout()->render()` for the fragment payload.

## 6. Multi-value round-trip

**Test.** Configure a FluentCRM checkbox (or multi-select) field, submit two
options at checkout, then open the profile-edit form and confirm both are ticked.
Edit them there and reload checkout to confirm the pre-fill agrees.

**Expected.** Both directions agree. Values are written as an array, matching how
FluentCRM's own UI stores them.

**If the profile form disagrees:** reshape the value.

```php
add_filter( 'blt_fluent/multi_value', fn( $values ) => implode( ', ', $values ) );
```

## 7. Renewal `order_type`

**Test.** Process a renewal on a subscription product whose product is mapped.

**Expected.** No fields render; the log shows `Render skipped: renewal order`
with the observed `order_type`.

**If fields render on a renewal:** the log's `order_type` value shows what
FluentCart actually sends. Widen the check:

```php
add_filter( 'blt_fluent/is_renewal', function ( $is_renewal, $order_type ) {
	return $is_renewal || in_array( $order_type, array( 'renewal', 'subscription_renewal' ), true );
}, 10, 2 );
```

## 8. Guest checkout

**Test.** Complete a guest checkout (one-time product) with fields mapped to it.

**Expected.** The contact is created or updated by email; `createOrUpdate` does not
need a pre-existing contact.

**If the write fails:** the log shows `createOrUpdate failed` with the message.
EPNET membership is a subscription and needs an account, so guest capture is a
nice-to-have — but a failure here should not block checkout, and does not: every
CRM call is wrapped and logged.

## 9. Companies module: is it reachable?

**Test.** Put `[blt_fluent_company]` on a members-only page and load it as a
signed-in member with a FluentCRM contact.

**Expected.** The picker renders with the member's current company.

**If it says "Company selection is not available":** the model class was not
found. `Companies::model()` looks for `\FluentCrm\App\Models\Company` and
`...\Companies`; confirm the real class name on this FluentCRM version and add
it there. The log records the same thing.

## 10. Company search, creation and the contact relationship

**Test.** Type three letters of a company you know exists — results should
appear. Then pick one, Save, and confirm in FluentCRM that the contact now shows
that company. Finally type a name that does not exist, use **+ Add a new
company**, and confirm both that the company appears in FluentCRM's Companies
list and that it is attached to the contact.

**Expected.** All three work, and the Diagnostics log records which strategy
each step used (`companies relation`, `company_id column`, `syncCompanies`,
`attachCompanies`, …).

**If search returns nothing** but companies exist, the name column differs.
Override the search:

```php
add_filter( 'blt_fluent/pre_company_search', function ( $results, $term ) {
	// Return an array of [ 'id' => 1, 'name' => 'Acme', 'meta' => '' ].
}, 10, 2 );
```

**If creation fails**, the log carries FluentCRM's own message — usually a
column that is required but absent from the payload. `Companies::create()` only
sends columns the table actually has; add whatever else is mandatory:

```php
add_filter( 'blt_fluent/new_company_data', function ( $data, $name ) {
	$data['owner_id'] = get_current_user_id();
	return $data;
}, 10, 2 );
```

**If the company saves but does not stick** — Save reports success and the
contact still shows the old company — the write path and the read path disagree.
The log's strategy line names the write that was used. Take over the assignment:

```php
add_filter( 'blt_fluent/pre_assign_company', function ( $handled, $contact, $company_id ) {
	// Do the write, return true on success.
	return true;
}, 10, 3 );
```

## 11. One company per contact, or several?

The module presents a single company per member and replaces on save. If this
FluentCRM version models companies as many-to-many and the site wants members in
several, short-circuit `blt_fluent/pre_assign_company` to attach without
detaching, and `blt_fluent/company_search_results` is unaffected.

**Worth confirming either way:** attach a member to two companies in FluentCRM's
admin, then load the shortcode. It shows the first one. Saving replaces both
with the chosen company — check that is what the site wants before rolling this
out to members.

## Phase 0 exit criteria

- [ ] Activation is blocked with either dependency inactive (try WP-CLI too).
- [ ] Deactivating a dependency shows the notice and renders nothing at checkout,
      with configuration intact afterwards.
- [ ] `blt_fluent_daily_update_check` is visible in WP Crontrol, next run at local
      midnight.
- [ ] Configure fields, deactivate, reactivate — the configuration is unchanged.
- [ ] Diagnostics shows both dependencies active and a non-zero field count.
