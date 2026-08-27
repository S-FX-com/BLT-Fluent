<?php
/**
 * CLI smoke tests: php tests/smoke-test.php
 *
 * @package BLT_Fluent
 */

require_once __DIR__ . '/bootstrap.php';

use BLT_Fluent\CRM_Fields;
use BLT_Fluent\Cart_Context;
use BLT_Fluent\Checkout;
use BLT_Fluent\Settings;

/**
 * A CRM_Fields whose definitions come from the test, not from FluentCRM.
 */
class Stub_CRM_Fields extends CRM_Fields {

	/**
	 * Definitions to return.
	 *
	 * @var array
	 */
	private $stub;

	/**
	 * Constructor.
	 *
	 * @param array $stub Raw definitions keyed by slug.
	 */
	public function __construct( array $stub ) {
		$this->stub = $stub;
	}

	/**
	 * Stubbed definitions.
	 *
	 * @return array
	 */
	public function definitions() {
		return $this->stub;
	}
}

// --- Field type mapping ---------------------------------------------------

check( 'canonical_type: single line text', CRM_Fields::TYPE_TEXT, CRM_Fields::canonical_type( 'text' ) );
check( 'canonical_type: textarea', CRM_Fields::TYPE_TEXTAREA, CRM_Fields::canonical_type( 'textarea' ) );
check( 'canonical_type: numeric', CRM_Fields::TYPE_NUMBER, CRM_Fields::canonical_type( 'number' ) );
check( 'canonical_type: select-one', CRM_Fields::TYPE_SELECT, CRM_Fields::canonical_type( 'select-one' ) );
check( 'canonical_type: select-multi', CRM_Fields::TYPE_MULTISELECT, CRM_Fields::canonical_type( 'select-multi' ) );
check( 'canonical_type: radio', CRM_Fields::TYPE_RADIO, CRM_Fields::canonical_type( 'radio' ) );
check( 'canonical_type: checkbox', CRM_Fields::TYPE_CHECKBOX, CRM_Fields::canonical_type( 'checkbox' ) );
check( 'canonical_type: date', CRM_Fields::TYPE_DATE, CRM_Fields::canonical_type( 'date' ) );
check( 'canonical_type: date_time', CRM_Fields::TYPE_DATETIME, CRM_Fields::canonical_type( 'date_time' ) );
check( 'canonical_type: unknown falls back to text', CRM_Fields::TYPE_TEXT, CRM_Fields::canonical_type( 'something-new' ) );

check( 'is_multi_value: checkbox', true, CRM_Fields::is_multi_value( CRM_Fields::TYPE_CHECKBOX ) );
check( 'is_multi_value: select', false, CRM_Fields::is_multi_value( CRM_Fields::TYPE_SELECT ) );

check(
	'normalize_options: list of strings',
	array( 'Dressage' => 'Dressage', 'Eventing' => 'Eventing' ),
	CRM_Fields::normalize_options( array( 'Dressage', 'Eventing' ) )
);

check(
	'normalize_options: label/value pairs',
	array( 'dr' => 'Dressage' ),
	CRM_Fields::normalize_options( array( array( 'value' => 'dr', 'label' => 'Dressage' ) ) )
);

// --- Settings -------------------------------------------------------------

check( 'normalize_product_key: plain id', '123', Settings::normalize_product_key( '123' ) );
check( 'normalize_product_key: product:variation', '123:45', Settings::normalize_product_key( '123:45' ) );
check( 'normalize_product_key: rejects junk', '', Settings::normalize_product_key( 'abc' ) );
check( 'normalize_product_key: rejects injection', '', Settings::normalize_product_key( "1; DROP TABLE" ) );

$settings = new Settings();

$settings->save(
	array(
		'field_sets'  => array(
			'default' => array(
				'label'  => 'Default',
				'fields' => array(
					array( 'slug' => 'specialty', 'required' => true ),
					array( 'slug' => 'biography', 'required' => false, 'label' => 'Short bio' ),
					array( 'slug' => 'BAD SLUG!', 'required' => false ),
				),
			),
			'premium' => array( 'label' => 'Premium', 'fields' => array() ),
		),
		'product_map' => array(
			'123'    => 'default',
			'123:45' => 'premium',
			'999'    => 'does_not_exist',
			'junk'   => 'default',
		),
	)
);

$config = $settings->get();

check( 'settings: schema version stamped', Settings::SCHEMA_VERSION, $config['version'] );
check( 'settings: delete_on_uninstall defaults to false', false, $config['delete_on_uninstall'] );
check( 'settings: renewals skipped by default', true, $config['skip_renewals'] );
check( 'settings: field order preserved', array( 'specialty', 'biography', 'badslug' ), array_column( $config['field_sets']['default']['fields'], 'slug' ) );
check( 'settings: label override kept', 'Short bio', $config['field_sets']['default']['fields'][1]['label'] );
check( 'settings: mapping to unknown set dropped', false, isset( $config['product_map']['999'] ) );
check( 'settings: unusable product key dropped', false, isset( $config['product_map']['junk'] ) );
check( 'settings: variation mapping kept', 'premium', $config['product_map']['123:45'] );
check( 'settings: variation mapping wins', 'premium', $settings->field_set_key_for_product( 123, 45 ) );
check( 'settings: product mapping used when no variation match', 'default', $settings->field_set_key_for_product( 123, 999 ) );
check( 'settings: unmapped product yields nothing', '', $settings->field_set_key_for_product( 456 ) );

// add_option() must not overwrite an existing configuration.
$before = get_option( BLT_FLUENT_OPTION );
Settings::maybe_seed_defaults();
check( 'settings: seeding never overwrites existing config', $before, get_option( BLT_FLUENT_OPTION ) );

// --- Cart context ---------------------------------------------------------

$payload = array(
	'checkout_data' => array(
		'order_type' => 'initial',
		'items'      => array(
			array( 'product_id' => 123, 'variation_id' => 45, 'quantity' => 1 ),
			array( 'product_id' => 200 ),
		),
	),
	'billing'       => array( 'billing_email' => 'rider@example.com' ),
);

$context = new Cart_Context( $payload );

check( 'cart: nested product ids found', array( 123, 200 ), $context->product_ids() );
check( 'cart: order type read', 'initial', $context->order_type() );
check( 'cart: initial order is not a renewal', false, $context->is_renewal() );
check( 'cart: email found in nested payload', 'rider@example.com', $context->email() );

$renewal = new Cart_Context( array( 'order_type' => 'renewal' ) );
check( 'cart: renewal detected', true, $renewal->is_renewal() );

check(
	'cart: deep_find_array locates nested values',
	array( 'specialty' => 'Dressage' ),
	Cart_Context::deep_find_array( array( 'data' => array( 'blt_fluent_fields' => array( 'specialty' => 'Dressage' ) ) ), 'blt_fluent_fields' )
);

// --- Checkout -------------------------------------------------------------

$definitions = array(
	'specialty'     => array(
		'slug'     => 'specialty',
		'label'    => 'Specialty',
		'type'     => CRM_Fields::TYPE_SELECT,
		'raw_type' => 'select-one',
		'options'  => array( 'dressage' => 'Dressage', 'eventing' => 'Eventing' ),
		'raw'      => array(),
	),
	'biography'     => array(
		'slug'     => 'biography',
		'label'    => 'Biography',
		'type'     => CRM_Fields::TYPE_TEXTAREA,
		'raw_type' => 'textarea',
		'options'  => array(),
		'raw'      => array(),
	),
	'disciplines'   => array(
		'slug'     => 'disciplines',
		'label'    => 'Disciplines',
		'type'     => CRM_Fields::TYPE_CHECKBOX,
		'raw_type' => 'checkbox',
		'options'  => array( 'jumping' => 'Jumping', 'western' => 'Western' ),
		'raw'      => array(),
	),
	'years_active'  => array(
		'slug'     => 'years_active',
		'label'    => 'Years active',
		'type'     => CRM_Fields::TYPE_NUMBER,
		'raw_type' => 'number',
		'options'  => array(),
		'raw'      => array(),
	),
	'joined_on'     => array(
		'slug'     => 'joined_on',
		'label'    => 'Joined on',
		'type'     => CRM_Fields::TYPE_DATE,
		'raw_type' => 'date',
		'options'  => array(),
		'raw'      => array(),
	),
);

$settings->save(
	array(
		'field_sets'  => array(
			'default' => array(
				'label'  => 'Default',
				'fields' => array(
					array( 'slug' => 'specialty', 'required' => true ),
					array( 'slug' => 'biography', 'required' => false, 'label' => 'Short bio' ),
					array( 'slug' => 'disciplines', 'required' => false ),
					array( 'slug' => 'years_active', 'required' => false ),
					array( 'slug' => 'joined_on', 'required' => false ),
					array( 'slug' => 'deleted_in_crm', 'required' => true ),
				),
			),
		),
		'product_map' => array( '123' => 'default' ),
	)
);

$crm      = new Stub_CRM_Fields( $definitions );
$checkout = new Checkout( $settings, $crm );

$prepared = $checkout->prepared_fields( 'default' );

check( 'checkout: orphan slug skipped', array( 'specialty', 'biography', 'disciplines', 'years_active', 'joined_on' ), array_column( $prepared, 'slug' ) );
check( 'checkout: label override applied', 'Short bio', $prepared[1]['label'] );
check( 'checkout: definition label used when no override', 'Specialty', $prepared[0]['label'] );
check( 'checkout: required flag carried through', true, $prepared[0]['required'] );

$field_by_slug = array();

foreach ( $prepared as $field ) {
	$field_by_slug[ $field['slug'] ] = $field;
}

check(
	'sanitize: select value in options is kept',
	'dressage',
	call_private( $checkout, 'sanitize_value', array( $field_by_slug['specialty'], 'dressage' ) )
);

check(
	'sanitize: select value outside options is dropped',
	'',
	call_private( $checkout, 'sanitize_value', array( $field_by_slug['specialty'], 'not-an-option' ) )
);

check(
	'sanitize: textarea strips markup',
	'Rider and photographer alert(1)',
	call_private( $checkout, 'sanitize_value', array( $field_by_slug['biography'], "Rider and photographer <script>alert(1)</script>" ) )
);

check(
	'sanitize: checkbox keeps allowed values only, as an array',
	array( 'jumping' ),
	call_private( $checkout, 'sanitize_value', array( $field_by_slug['disciplines'], array( 'jumping', 'nope' ) ) )
);

check(
	'sanitize: number rejects text',
	'',
	call_private( $checkout, 'sanitize_value', array( $field_by_slug['years_active'], 'twelve' ) )
);

check(
	'sanitize: number keeps decimals',
	'12.5',
	call_private( $checkout, 'sanitize_value', array( $field_by_slug['years_active'], '12.5' ) )
);

check(
	'sanitize: malformed date rejected',
	'',
	call_private( $checkout, 'sanitize_value', array( $field_by_slug['joined_on'], '31/12/2025' ) )
);

check(
	'sanitize: valid date kept',
	'2025-12-31',
	call_private( $checkout, 'sanitize_value', array( $field_by_slug['joined_on'], '2025-12-31' ) )
);

check( 'is_valid_date: rejects impossible date', false, Checkout::is_valid_date( '2025-02-30' ) );

// Only configured slugs survive collection, whatever else is submitted.
$collected = call_private(
	$checkout,
	'collect_values',
	array(
		$prepared,
		array(
			'blt_fluent_fields' => array(
				'specialty'  => 'eventing',
				'biography'  => 'Bio text',
				'user_role'  => 'administrator',
				'deleted_in_crm' => 'should not appear',
			),
		),
	)
);

check( 'collect: only configured slugs kept', array( 'specialty', 'biography' ), array_keys( $collected ) );
check( 'collect: values sanitized', 'eventing', $collected['specialty'] );

// Validation: a missing required field must produce an error keyed by input name.
$errors = $checkout->validate(
	array(),
	array(
		'blt_fluent_field_set' => 'default',
		'blt_fluent_fields'    => array( 'biography' => 'Bio only' ),
	)
);

check( 'validate: required field missing is reported', array( 'blt_fluent_fields[specialty]' ), array_keys( $errors ) );

$errors = $checkout->validate(
	array(),
	array(
		'blt_fluent_field_set' => 'default',
		'blt_fluent_fields'    => array( 'specialty' => 'dressage' ),
	)
);

check( 'validate: complete submission passes', array(), $errors );

$errors = $checkout->validate(
	array(),
	array(
		'blt_fluent_field_set' => 'default',
		'blt_fluent_fields'    => array( 'specialty' => 'dressage', 'years_active' => 'twelve' ),
	)
);

check( 'validate: non-numeric number reported', array( 'blt_fluent_fields[years_active]' ), array_keys( $errors ) );

// A renewal must never be validated for fields it was never shown.
$errors = $checkout->validate(
	array(),
	array(
		'order_type'           => 'renewal',
		'blt_fluent_field_set' => 'default',
		'blt_fluent_fields'    => array(),
	)
);

check( 'validate: renewal is not validated', array(), $errors );

// An unknown field set key cannot introduce fields.
$errors = $checkout->validate(
	array(),
	array(
		'blt_fluent_field_set' => 'not_a_set',
		'blt_fluent_fields'    => array(),
	)
);

check( 'validate: unknown field set ignored', array(), $errors );

// A pre-existing error from another integration must survive.
$errors = $checkout->validate(
	array( 'billing_email' => 'Email is required.' ),
	array(
		'blt_fluent_field_set' => 'default',
		'blt_fluent_fields'    => array(),
	)
);

check(
	'validate: existing errors preserved',
	array( 'billing_email', 'blt_fluent_fields[specialty]' ),
	array_keys( $errors )
);

// Checkout data handed in place of an error bag must not be mangled.
$data   = array( 'payment_method' => 'stripe', 'cart' => array() );
$result = $checkout->validate( $data );

check( 'validate: checkout data passed through untouched', $data, $result );

// Invalid choices are reported rather than silently dropped.
$errors = $checkout->validate(
	array(),
	array(
		'blt_fluent_field_set' => 'default',
		'blt_fluent_fields'    => array( 'specialty' => 'not-an-option' ),
	)
);

check( 'validate: invalid select option reported', array( 'blt_fluent_fields[specialty]' ), array_keys( $errors ) );

$errors = $checkout->validate(
	array(),
	array(
		'blt_fluent_field_set' => 'default',
		'blt_fluent_fields'    => array( 'specialty' => 'dressage', 'disciplines' => array( 'jumping', 'forged' ) ),
	)
);

check( 'validate: invalid checkbox option reported', array( 'blt_fluent_fields[disciplines]' ), array_keys( $errors ) );

// Values nested inside a JSON-style payload are still found.
$collected = call_private(
	$checkout,
	'collect_values',
	array(
		$prepared,
		array( 'data' => array( 'checkout' => array( 'blt_fluent_fields' => array( 'specialty' => 'dressage' ) ) ) ),
	)
);

check( 'collect: nested payload located', array( 'specialty' => 'dressage' ), $collected );

// --- Render ---------------------------------------------------------------

ob_start();
$checkout->render( array( 'checkout_data' => array( 'order_type' => 'initial', 'items' => array( array( 'product_id' => 123 ) ) ) ) );
$html = ob_get_clean();

check( 'render: field set marker present', true, false !== strpos( $html, 'name="blt_fluent_field_set" value="default"' ) );
check( 'render: text/select input named for the slug', true, false !== strpos( $html, 'name="blt_fluent_fields[specialty]"' ) );
check( 'render: textarea rendered for textarea field', true, false !== strpos( $html, '<textarea' ) );
check( 'render: checkbox group uses array notation', true, false !== strpos( $html, 'name="blt_fluent_fields[disciplines][]"' ) );
check( 'render: required attribute on required field', true, false !== strpos( $html, 'required="required"' ) );
check( 'render: orphan field not rendered', false, strpos( $html, 'deleted_in_crm' ) !== false );
check( 'render: label override used', true, false !== strpos( $html, 'Short bio' ) );

ob_start();
$checkout->render( array( 'checkout_data' => array( 'items' => array( array( 'product_id' => 123 ) ) ) ) );
$second = ob_get_clean();

check( 'render: block is not rendered twice per request', '', $second );

// A renewal renders nothing.
$renewal_checkout = new Checkout( $settings, $crm );

ob_start();
$renewal_checkout->render( array( 'order_type' => 'renewal', 'items' => array( array( 'product_id' => 123 ) ) ) );
$renewal_html = ob_get_clean();

check( 'render: renewal renders nothing', '', $renewal_html );

// An unmapped product renders nothing.
$unmapped_checkout = new Checkout( $settings, $crm );

ob_start();
$unmapped_checkout->render( array( 'items' => array( array( 'product_id' => 777 ) ) ) );
$unmapped_html = ob_get_clean();

check( 'render: unmapped product renders nothing', '', $unmapped_html );

// Option values are escaped on output.
$quoted_crm = new Stub_CRM_Fields(
	array(
		'specialty' => array(
			'slug'     => 'specialty',
			'label'    => 'Specialty',
			'type'     => CRM_Fields::TYPE_SELECT,
			'raw_type' => 'select-one',
			'options'  => array( '"><script>' => 'Hostile "option"' ),
			'raw'      => array(),
		),
	)
);

$escaping_checkout = new Checkout( $settings, $quoted_crm );

ob_start();
$escaping_checkout->render( array( 'items' => array( array( 'product_id' => 123 ) ) ) );
$escaped_html = ob_get_clean();

check( 'render: hostile option value escaped', false, strpos( $escaped_html, '"><script>' ) !== false );

// --- Report ---------------------------------------------------------------

blt_test_report();
