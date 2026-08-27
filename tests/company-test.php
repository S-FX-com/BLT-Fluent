<?php
/**
 * CLI tests for the company selection module: php tests/company-test.php
 *
 * @package BLT_Fluent
 */

require_once __DIR__ . '/bootstrap.php';

use BLT_Fluent\Companies;
use BLT_Fluent\Company_Shortcode;
use BLT_Fluent\CRM_Fields;

/**
 * A Companies service backed by an in-memory list instead of FluentCRM.
 */
class Stub_Companies extends Companies {

	/**
	 * Known companies, id => name.
	 *
	 * @var array
	 */
	public $rows;

	/**
	 * The company currently on the contact.
	 *
	 * @var array|null
	 */
	public $current = null;

	/**
	 * Company IDs passed to assign().
	 *
	 * @var int[]
	 */
	public $assigned = array();

	/**
	 * Names passed to create().
	 *
	 * @var string[]
	 */
	public $created = array();

	/**
	 * Terms passed to search().
	 *
	 * @var string[]
	 */
	public $searched = array();

	/**
	 * Whether the module reports itself available.
	 *
	 * @var bool
	 */
	public $is_available = true;

	/**
	 * Whether create() succeeds.
	 *
	 * @var bool
	 */
	public $can_create = true;

	/**
	 * Whether assign() succeeds.
	 *
	 * @var bool
	 */
	public $can_assign = true;

	/**
	 * Next id handed out by create().
	 *
	 * @var int
	 */
	private $next_id = 100;

	/**
	 * Constructor.
	 *
	 * @param array $rows id => name.
	 */
	public function __construct( array $rows = array() ) {
		$this->rows = $rows;
	}

	/**
	 * Availability.
	 *
	 * @return bool
	 */
	public function available() {
		return $this->is_available;
	}

	/**
	 * Search by substring.
	 *
	 * @param string $term  Term.
	 * @param int    $limit Limit.
	 * @return array[]
	 */
	public function search( $term, $limit = 20 ) {
		$this->searched[] = $term;
		$results          = array();

		foreach ( $this->rows as $id => $name ) {
			if ( false !== stripos( $name, $term ) ) {
				$results[] = array( 'id' => (int) $id, 'name' => $name, 'meta' => '' );
			}
		}

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Fetch by id.
	 *
	 * @param int $company_id Company id.
	 * @return array|null
	 */
	public function get( $company_id ) {
		$company_id = (int) $company_id;

		return isset( $this->rows[ $company_id ] )
			? array( 'id' => $company_id, 'name' => $this->rows[ $company_id ], 'meta' => '' )
			: null;
	}

	/**
	 * Exact, case-insensitive name lookup.
	 *
	 * @param string $name Name.
	 * @return array|null
	 */
	public function find_by_name( $name ) {
		$name = self::clean_name( $name );

		foreach ( $this->rows as $id => $existing ) {
			if ( 0 === strcasecmp( $existing, $name ) ) {
				return array( 'id' => (int) $id, 'name' => $existing, 'meta' => '' );
			}
		}

		return null;
	}

	/**
	 * Create a company.
	 *
	 * @param string $name Name.
	 * @return array|null
	 */
	public function create( $name ) {
		$name            = self::clean_name( $name );
		$this->created[] = $name;

		if ( ! $this->can_create || '' === $name ) {
			return null;
		}

		$id                = $this->next_id++;
		$this->rows[ $id ] = $name;

		return array( 'id' => $id, 'name' => $name, 'meta' => '' );
	}

	/**
	 * The contact's company.
	 *
	 * @param object|null $contact Contact.
	 * @return array|null
	 */
	public function contact_company( $contact ) {
		return $this->current;
	}

	/**
	 * Attach a company to a contact.
	 *
	 * @param object $contact    Contact.
	 * @param int    $company_id Company id.
	 * @return bool
	 */
	public function assign( $contact, $company_id ) {
		$this->assigned[] = (int) $company_id;

		if ( ! $this->can_assign ) {
			return false;
		}

		$this->current = $this->get( $company_id );

		return true;
	}
}

/**
 * A CRM_Fields that returns a fixed contact.
 */
class Stub_Contact_CRM extends CRM_Fields {

	/**
	 * The contact to return.
	 *
	 * @var object|null
	 */
	public $contact;

	/**
	 * Constructor.
	 *
	 * @param object|null $contact Contact.
	 */
	public function __construct( $contact = null ) {
		$this->contact = $contact;
	}

	/**
	 * The stubbed contact.
	 *
	 * @param string $email Email.
	 * @return object|null
	 */
	public function get_contact( $email ) {
		return $this->contact;
	}
}

/**
 * Build a shortcode instance around the given stubs.
 *
 * @param Stub_Companies $companies Companies stub.
 * @param object|null    $contact   Contact.
 * @return Company_Shortcode
 */
function make_shortcode( $companies, $contact ) {
	return new Company_Shortcode( new Stub_Contact_CRM( $contact ), $companies );
}

$contact = (object) array( 'id' => 7, 'email' => 'rider@example.com' );

// --- Name cleaning --------------------------------------------------------

check( 'clean_name: plain name kept', 'Acme Ltd', Companies::clean_name( 'Acme Ltd' ) );
check( 'clean_name: whitespace collapsed', 'Acme Ltd', Companies::clean_name( "  Acme \n  Ltd  " ) );
check( 'clean_name: markup stripped', 'Acme', Companies::clean_name( '<b>Acme</b>' ) );
check( 'clean_name: script stripped', 'alert(1)', Companies::clean_name( '<script>alert(1)</script>' ) );
check( 'clean_name: single character rejected', '', Companies::clean_name( 'A' ) );
check( 'clean_name: empty rejected', '', Companies::clean_name( '   ' ) );
check( 'clean_name: over-long rejected', '', Companies::clean_name( str_repeat( 'a', 200 ) ) );
check( 'clean_name: max length accepted', 191, strlen( Companies::clean_name( str_repeat( 'a', 191 ) ) ) );
check( 'clean_name: array rejected', '', Companies::clean_name( array( 'Acme' ) ) );
check( 'clean_name: null rejected', '', Companies::clean_name( null ) );

check( 'escape_like: wildcards escaped', '100\\%\\_off', Companies::escape_like( '100%_off' ) );

// --- Row normalization ----------------------------------------------------

check(
	'normalize_row: id and name extracted',
	array( 'id' => 4, 'name' => 'Acme Ltd', 'meta' => '' ),
	Companies::normalize_row( array( 'id' => 4, 'name' => 'Acme Ltd' ) )
);

check(
	'normalize_row: meta built from the first two details',
	'Photography · Lexington',
	Companies::normalize_row(
		array( 'id' => 4, 'name' => 'Acme', 'industry' => 'Photography', 'city' => 'Lexington', 'country' => 'US' )
	)['meta']
);

check( 'normalize_row: row without an id rejected', null, Companies::normalize_row( array( 'name' => 'Acme' ) ) );
check( 'normalize_row: row without a name rejected', null, Companies::normalize_row( array( 'id' => 4 ) ) );
check( 'normalize_row: non-array rejected', null, Companies::normalize_row( 'Acme' ) );

// --- Render ---------------------------------------------------------------

blt_test_login( null );

$companies = new Stub_Companies( array( 4 => 'Acme Ltd' ) );
$shortcode = make_shortcode( $companies, $contact );

$html = $shortcode->render( array() );
check( 'render: signed-out visitors are asked to sign in', true, false !== strpos( $html, 'Please sign in' ) );
check( 'render: signed-out visitors get no combobox', false, strpos( $html, 'role="combobox"' ) !== false );

blt_test_login( 'rider@example.com', 1 );

$companies->is_available = false;
check( 'render: unavailable module renders a notice', true, false !== strpos( $shortcode->render( array() ), 'not available' ) );
$companies->is_available = true;

$no_contact = make_shortcode( $companies, null );
check( 'render: missing contact renders a notice', true, false !== strpos( $no_contact->render( array() ), 'could not find your contact record' ) );

$companies->current = array( 'id' => 4, 'name' => 'Acme Ltd', 'meta' => '' );
$html               = $shortcode->render( array() );

check( 'render: combobox present', true, false !== strpos( $html, 'role="combobox"' ) );
check( 'render: listbox present', true, false !== strpos( $html, 'role="listbox"' ) );
check( 'render: current company shown', true, false !== strpos( $html, 'Acme Ltd' ) );
check( 'render: current id in config', true, false !== strpos( $html, '&quot;currentId&quot;:4' ) );
check( 'render: create offered by default', true, false !== strpos( $html, '&quot;allowCreate&quot;:true' ) );
check( 'render: save starts disabled', true, false !== strpos( $html, 'class="blt-company__save" disabled' ) );

$html = $shortcode->render( array( 'allow_create' => 'no' ) );
check( 'render: allow_create="no" respected', true, false !== strpos( $html, '&quot;allowCreate&quot;:false' ) );

$html = $shortcode->render( array( 'label' => 'Your studio', 'button' => 'Update' ) );
check( 'render: label attribute used', true, false !== strpos( $html, 'Your studio' ) );
check( 'render: button attribute used', true, false !== strpos( $html, 'Update' ) );

// A hostile company name must not break out of the value attribute.
$companies->current = array( 'id' => 9, 'name' => 'Acme" onmouseover="alert(1)', 'meta' => '' );
$html               = $shortcode->render( array() );

check( 'render: hostile company name escaped', false, strpos( $html, 'onmouseover="alert' ) !== false );
$companies->current = null;

// --- Search endpoint ------------------------------------------------------

$companies = new Stub_Companies( array( 4 => 'Acme Ltd', 5 => 'Acme Studios', 6 => 'Bridle Path Media' ) );
$shortcode = make_shortcode( $companies, $contact );

$response = $shortcode->rest_search( new WP_REST_Request( array( 'q' => 'acme' ) ) );
check( 'search: matches returned', 2, count( $response['results'] ) );

$response = $shortcode->rest_search( new WP_REST_Request( array( 'q' => '   ' ) ) );
check( 'search: blank term returns nothing', array(), $response['results'] );

$shortcode->rest_search( new WP_REST_Request( array( 'q' => str_repeat( 'a', 300 ) ) ) );
check( 'search: over-long term truncated before querying', 100, strlen( end( $companies->searched ) ) );

// --- Save endpoint --------------------------------------------------------

$companies = new Stub_Companies( array( 4 => 'Acme Ltd' ) );
$shortcode = make_shortcode( $companies, $contact );

$result = $shortcode->rest_save( new WP_REST_Request( array( 'company_id' => 4 ) ) );
check( 'save: existing company assigned', array( 4 ), $companies->assigned );
check( 'save: response carries the company', 'Acme Ltd', $result['company']['name'] );
check( 'save: existing company is not flagged as created', false, $result['created'] );

$result = $shortcode->rest_save( new WP_REST_Request( array( 'company_id' => 999 ) ) );
check( 'save: unknown company id rejected', 'blt_fluent_unknown_company', $result->get_error_code() );
check( 'save: unknown company id is a 404', 404, $result->get_error_status() );

$result = $shortcode->rest_save( new WP_REST_Request( array() ) );
check( 'save: empty request rejected', 'blt_fluent_no_company', $result->get_error_code() );
check( 'save: empty request is a 400', 400, $result->get_error_status() );

$result = $shortcode->rest_save( new WP_REST_Request( array( 'company_name' => 'A' ) ) );
check( 'save: unusable name rejected', 'blt_fluent_no_company', $result->get_error_code() );

// A name that already exists must be reused, whatever its casing.
$companies->created = array();
$result             = $shortcode->rest_save( new WP_REST_Request( array( 'company_name' => 'acme LTD' ) ) );

check( 'save: existing name reused rather than duplicated', array(), $companies->created );
check( 'save: reused company keeps its id', 4, $result['company']['id'] );
check( 'save: reuse is not flagged as created', false, $result['created'] );

// A genuinely new name creates one.
$result = $shortcode->rest_save( new WP_REST_Request( array( 'company_name' => 'Bridle Path Media' ) ) );

check( 'save: new company created', array( 'Bridle Path Media' ), $companies->created );
check( 'save: creation flagged in the response', true, $result['created'] );
check( 'save: new company assigned', 100, end( $companies->assigned ) );

// A contact-less user cannot save.
$orphan = make_shortcode( $companies, null );
$result = $orphan->rest_save( new WP_REST_Request( array( 'company_id' => 4 ) ) );
check( 'save: missing contact rejected', 'blt_fluent_no_contact', $result->get_error_code() );
check( 'save: missing contact is a 404', 404, $result->get_error_status() );

// Creation can be switched off server-side, whatever the markup said.
add_filter( 'blt_fluent/company_allow_create', '__return_false_stub' );

/**
 * Filter callback returning false.
 *
 * @return bool
 */
function __return_false_stub() {
	return false;
}

$companies->created = array();
$result             = $shortcode->rest_save( new WP_REST_Request( array( 'company_name' => 'Totally New Co' ) ) );

check( 'save: creation refused when disabled', 'blt_fluent_create_disabled', $result->get_error_code() );
check( 'save: creation refused is a 403', 403, $result->get_error_status() );
check( 'save: nothing created while disabled', array(), $companies->created );

remove_all_filters( 'blt_fluent/company_allow_create' );

// The hourly creation limit holds.
set_transient( 'blt_fluent_company_creates_1', 5, HOUR_IN_SECONDS );

$companies->created = array();
$result             = $shortcode->rest_save( new WP_REST_Request( array( 'company_name' => 'Another New Co' ) ) );

check( 'save: creation throttled', 'blt_fluent_too_many_companies', $result->get_error_code() );
check( 'save: throttling is a 429', 429, $result->get_error_status() );
check( 'save: nothing created while throttled', array(), $companies->created );

delete_transient( 'blt_fluent_company_creates_1' );

// A failed create surfaces as an error rather than a silent success.
$companies->can_create = false;
$result                = $shortcode->rest_save( new WP_REST_Request( array( 'company_name' => 'Cannot Create Co' ) ) );

check( 'save: failed creation reported', 'blt_fluent_create_failed', $result->get_error_code() );
$companies->can_create = true;

// So does a failed assignment.
$companies->can_assign = false;
$result                = $shortcode->rest_save( new WP_REST_Request( array( 'company_id' => 4 ) ) );

check( 'save: failed assignment reported', 'blt_fluent_assign_failed', $result->get_error_code() );
check( 'save: failed assignment is a 500', 500, $result->get_error_status() );
$companies->can_assign = true;

// --- Permission -----------------------------------------------------------

check( 'permission: signed-in user allowed', true, $shortcode->permission() );

blt_test_login( null );
check( 'permission: signed-out visitor refused', false, $shortcode->permission() );

// --- Report ---------------------------------------------------------------

blt_test_report();
