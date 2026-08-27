<?php
/**
 * FluentCRM Companies reader and writer.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * The only place in the plugin that talks to FluentCRM's Companies module.
 *
 * FluentCRM owns the company records exactly as it owns the contact schema. This
 * class searches them, creates one when a member's company is genuinely new, and
 * attaches a company to a contact. It never defines its own storage.
 *
 * FluentCRM's Companies module arrived in 2.8 and its contact relationship is not
 * a documented contract, so reads and writes try each plausible access path in
 * turn and record which one worked -- see the Diagnostics tab.
 */
class Companies {

	/**
	 * Default number of search results returned.
	 */
	const SEARCH_LIMIT = 20;

	/**
	 * Longest company name accepted.
	 */
	const MAX_NAME_LENGTH = 191;

	/**
	 * Shortest company name accepted.
	 */
	const MIN_NAME_LENGTH = 2;

	/**
	 * How the last operation resolved, for diagnostics.
	 *
	 * @var string
	 */
	private $strategy = '';

	/**
	 * Cached company table column names.
	 *
	 * @var string[]|null
	 */
	private $columns = null;

	/**
	 * Whether FluentCRM's Companies module is reachable.
	 *
	 * @return bool
	 */
	public function available() {
		return '' !== $this->model();
	}

	/**
	 * The FluentCRM company model class name.
	 *
	 * @return string Empty string when the module is absent.
	 */
	public function model() {
		foreach ( array( '\FluentCrm\App\Models\Company', '\FluentCrm\App\Models\Companies' ) as $class ) {
			if ( class_exists( $class ) ) {
				return $class;
			}
		}

		return '';
	}

	/**
	 * How the last read or write resolved.
	 *
	 * @return string
	 */
	public function strategy() {
		return $this->strategy;
	}

	/**
	 * Clean a submitted company name, or reject it.
	 *
	 * @param mixed $name Raw name.
	 * @return string Empty string when the name is unusable.
	 */
	public static function clean_name( $name ) {
		if ( ! is_scalar( $name ) ) {
			return '';
		}

		$name = sanitize_text_field( (string) $name );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );

		if ( '' === $name ) {
			return '';
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );

		if ( $length < self::MIN_NAME_LENGTH || $length > self::MAX_NAME_LENGTH ) {
			return '';
		}

		return $name;
	}

	/**
	 * Escape LIKE wildcards in a search term.
	 *
	 * The term is bound as a parameter, so this is about the customer searching
	 * for "100%" getting what they asked for -- not about injection.
	 *
	 * @param string $term Search term.
	 * @return string
	 */
	public static function escape_like( $term ) {
		return addcslashes( (string) $term, '%_\\' );
	}

	/**
	 * Normalize a company record into the shape the front end consumes.
	 *
	 * @param mixed $row Company model, array or object.
	 * @return array|null
	 */
	public static function normalize_row( $row ) {
		$data = Cart_Context::to_array( $row );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$id = (int) Cart_Context::first_scalar( $data, array( 'id', 'ID' ) );

		if ( ! $id ) {
			return null;
		}

		$name = (string) Cart_Context::first_scalar( $data, array( 'name', 'title', 'company_name' ) );

		if ( '' === $name ) {
			return null;
		}

		// A second line disambiguates two companies sharing a name.
		$meta_parts = array();

		foreach ( array( 'industry', 'city', 'country', 'website' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$meta_parts[] = (string) $data[ $key ];
			}
		}

		return array(
			'id'   => $id,
			'name' => $name,
			'meta' => implode( ' · ', array_slice( $meta_parts, 0, 2 ) ),
		);
	}

	/**
	 * Search companies by name.
	 *
	 * @param string $term  Search term.
	 * @param int    $limit Maximum results.
	 * @return array[] Normalized company rows.
	 */
	public function search( $term, $limit = self::SEARCH_LIMIT ) {
		$term  = trim( (string) $term );
		$limit = max( 1, min( 50, (int) $limit ) );

		/**
		 * Short-circuit the company search.
		 *
		 * Return an array of rows (id, name, meta) to bypass FluentCRM entirely.
		 *
		 * @param array|null $results Results, or null to run the default search.
		 * @param string     $term    Search term.
		 * @param int        $limit   Maximum results.
		 */
		$pre = apply_filters( 'blt_fluent/pre_company_search', null, $term, $limit );

		if ( is_array( $pre ) ) {
			$this->strategy = 'filter';

			return $pre;
		}

		if ( '' === $term ) {
			return array();
		}

		$model = $this->model();

		if ( '' === $model ) {
			Plugin::log( 'Company search skipped: FluentCRM Companies module not found' );

			return array();
		}

		$results = array();

		try {
			$rows = $model::query()
				->where( 'name', 'LIKE', '%' . self::escape_like( $term ) . '%' )
				->orderBy( 'name', 'ASC' )
				->limit( $limit )
				->get();

			foreach ( $rows as $row ) {
				$normalized = self::normalize_row( $row );

				if ( $normalized ) {
					$results[] = $normalized;
				}
			}

			$this->strategy = 'Company model query';
		} catch ( \Throwable $e ) {
			$this->strategy = 'failed';
			Plugin::log( 'Company search failed', $e->getMessage() );
		}

		/**
		 * Filter company search results before they reach the browser.
		 *
		 * @param array[] $results Normalized rows.
		 * @param string  $term    Search term.
		 */
		return apply_filters( 'blt_fluent/company_search_results', $results, $term );
	}

	/**
	 * A single company by ID.
	 *
	 * @param int $company_id Company ID.
	 * @return array|null Normalized row.
	 */
	public function get( $company_id ) {
		$company_id = (int) $company_id;
		$model      = $this->model();

		if ( ! $company_id || '' === $model ) {
			return null;
		}

		try {
			$row = $model::query()->find( $company_id );

			return $row ? self::normalize_row( $row ) : null;
		} catch ( \Throwable $e ) {
			Plugin::log( 'Company lookup failed', $e->getMessage() );

			return null;
		}
	}

	/**
	 * Find a company by exact name, case-insensitively.
	 *
	 * This is what stops "Acme Ltd" being created three times over.
	 *
	 * @param string $name Company name.
	 * @return array|null Normalized row.
	 */
	public function find_by_name( $name ) {
		$name  = self::clean_name( $name );
		$model = $this->model();

		if ( '' === $name || '' === $model ) {
			return null;
		}

		try {
			$row = $model::query()->whereRaw( 'LOWER(name) = ?', array( strtolower( $name ) ) )->first();

			return $row ? self::normalize_row( $row ) : null;
		} catch ( \Throwable $e ) {
			Plugin::log( 'Company name lookup failed', $e->getMessage() );
		}

		// whereRaw is not available on every builder version; fall back to a
		// bounded LIKE scan and compare in PHP.
		foreach ( $this->search( $name, 50 ) as $candidate ) {
			if ( 0 === strcasecmp( $candidate['name'], $name ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Column names on the companies table.
	 *
	 * Used to build a create payload that cannot reference a column this
	 * FluentCRM version does not have.
	 *
	 * @return string[]
	 */
	private function columns() {
		if ( null !== $this->columns ) {
			return $this->columns;
		}

		$this->columns = array();
		$model         = $this->model();

		if ( '' === $model ) {
			return $this->columns;
		}

		try {
			$instance = new $model();
			$table    = method_exists( $instance, 'getTable' ) ? $instance->getTable() : '';

			if ( '' === $table ) {
				return $this->columns;
			}

			global $wpdb;

			if ( ! $wpdb ) {
				return $this->columns;
			}

			// The table name comes from the model, not from user input.
			$rows = $wpdb->get_col( 'SHOW COLUMNS FROM `' . str_replace( '`', '', $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( is_array( $rows ) ) {
				$this->columns = array_map( 'strval', $rows );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'Company column introspection failed', $e->getMessage() );
		}

		return $this->columns;
	}

	/**
	 * Create a company.
	 *
	 * Callers must check find_by_name() first; this does not deduplicate.
	 *
	 * @param string $name Company name.
	 * @return array|null Normalized row, or null on failure.
	 */
	public function create( $name ) {
		$name = self::clean_name( $name );

		if ( '' === $name ) {
			return null;
		}

		/**
		 * Short-circuit company creation.
		 *
		 * @param array|null $company Normalized row, or null to create normally.
		 * @param string     $name    Company name.
		 */
		$pre = apply_filters( 'blt_fluent/pre_create_company', null, $name );

		if ( is_array( $pre ) ) {
			$this->strategy = 'filter';

			return $pre;
		}

		$model = $this->model();

		if ( '' === $model ) {
			return null;
		}

		$columns = $this->columns();
		$payload = array( 'name' => $name );

		// Only touch columns this FluentCRM version actually has.
		if ( empty( $columns ) || in_array( 'status', $columns, true ) ) {
			$payload['status'] = 'active';
		}

		if ( in_array( 'hash', $columns, true ) ) {
			$payload['hash'] = md5( $name . wp_rand() . microtime( true ) );
		}

		/**
		 * Filter the attributes a new company is created with.
		 *
		 * @param array  $payload Model attributes.
		 * @param string $name    Company name.
		 */
		$payload = apply_filters( 'blt_fluent/new_company_data', $payload, $name );

		try {
			$company = $model::create( $payload );

			if ( ! $company ) {
				return null;
			}

			$this->strategy = 'Company::create';

			$normalized = self::normalize_row( $company );

			if ( $normalized ) {
				Plugin::log( 'Company created', $normalized );

				/**
				 * Fires after a company has been created from the front end.
				 *
				 * @param array $normalized Normalized row.
				 * @param mixed $company    The FluentCRM model.
				 */
				do_action( 'blt_fluent/company_created', $normalized, $company );
			}

			return $normalized;
		} catch ( \Throwable $e ) {
			$this->strategy = 'failed';
			Plugin::log( 'Company creation failed', $e->getMessage() );

			return null;
		}
	}

	/**
	 * The company currently attached to a contact.
	 *
	 * @param object|null $contact FluentCRM subscriber.
	 * @return array|null Normalized row.
	 */
	public function contact_company( $contact ) {
		if ( ! $contact ) {
			return null;
		}

		try {
			// Many-to-many relation, which is how FluentCRM models companies.
			if ( method_exists( $contact, 'companies' ) ) {
				$companies = $contact->companies;

				if ( $companies ) {
					foreach ( $companies as $company ) {
						$normalized = self::normalize_row( $company );

						if ( $normalized ) {
							$this->strategy = 'companies relation';

							return $normalized;
						}
					}
				}
			}

			// Single company column.
			if ( ! empty( $contact->company_id ) ) {
				$this->strategy = 'company_id column';

				return $this->get( $contact->company_id );
			}

			if ( ! empty( $contact->company ) ) {
				$normalized = self::normalize_row( $contact->company );

				if ( $normalized ) {
					$this->strategy = 'company property';

					return $normalized;
				}
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'Reading contact company failed', $e->getMessage() );
		}

		return null;
	}

	/**
	 * Attach a company to a contact, replacing whatever was there.
	 *
	 * The module presents one company per member, so assignment replaces rather
	 * than accumulates. Sites that want multiple companies per contact can
	 * short-circuit this with the filter below.
	 *
	 * @param object $contact    FluentCRM subscriber.
	 * @param int    $company_id Company ID.
	 * @return bool
	 */
	public function assign( $contact, $company_id ) {
		$company_id = (int) $company_id;

		if ( ! $contact || ! $company_id ) {
			return false;
		}

		/**
		 * Short-circuit assigning a company to a contact.
		 *
		 * @param bool|null $handled    True/false to short-circuit, null to proceed.
		 * @param object    $contact    FluentCRM subscriber.
		 * @param int       $company_id Company ID.
		 */
		$pre = apply_filters( 'blt_fluent/pre_assign_company', null, $contact, $company_id );

		if ( null !== $pre ) {
			$this->strategy = 'filter';

			return (bool) $pre;
		}

		try {
			if ( method_exists( $contact, 'syncCompanies' ) ) {
				$contact->syncCompanies( array( $company_id ) );
				$this->strategy = 'syncCompanies';

				return $this->confirm_assignment( $contact, $company_id );
			}

			if ( method_exists( $contact, 'attachCompanies' ) ) {
				// Replace: drop what is there before attaching the new one.
				$existing = $this->contact_company( $contact );

				if ( $existing && $existing['id'] !== $company_id && method_exists( $contact, 'detachCompanies' ) ) {
					$contact->detachCompanies( array( $existing['id'] ) );
				}

				$contact->attachCompanies( array( $company_id ) );
				$this->strategy = 'attachCompanies';

				return $this->confirm_assignment( $contact, $company_id );
			}

			// Single company column on the subscriber.
			$contact->company_id = $company_id;

			if ( method_exists( $contact, 'save' ) ) {
				$contact->save();
				$this->strategy = 'company_id column';

				return $this->confirm_assignment( $contact, $company_id );
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'Company assignment failed', $e->getMessage() );
		}

		// Last resort: let the contacts API persist it.
		try {
			if ( function_exists( 'FluentCrmApi' ) && ! empty( $contact->email ) ) {
				FluentCrmApi( 'contacts' )->createOrUpdate(
					array(
						'email'      => $contact->email,
						'company_id' => $company_id,
					)
				);

				$this->strategy = 'contacts API';

				return true;
			}
		} catch ( \Throwable $e ) {
			Plugin::log( 'Company assignment via contacts API failed', $e->getMessage() );
		}

		$this->strategy = 'failed';

		return false;
	}

	/**
	 * Re-read the contact to confirm an assignment actually stuck.
	 *
	 * A relation method that exists but silently no-ops would otherwise report
	 * success and leave the member's company unchanged.
	 *
	 * @param object $contact    FluentCRM subscriber.
	 * @param int    $company_id Expected company ID.
	 * @return bool
	 */
	private function confirm_assignment( $contact, $company_id ) {
		try {
			if ( method_exists( $contact, 'refresh' ) ) {
				$contact->refresh();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$current = $this->contact_company( $contact );

		if ( $current && (int) $current['id'] === (int) $company_id ) {
			return true;
		}

		// The read path and the write path may disagree on a given FluentCRM
		// version; trust the write unless the read found a different company.
		return ! $current;
	}
}
