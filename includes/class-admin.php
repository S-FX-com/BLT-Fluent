<?php
/**
 * Settings screen.
 *
 * @package BLT_Fluent
 */

namespace BLT_Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * Field selection, ordering, per-product enablement and diagnostics.
 *
 * Per-product enablement lives here rather than in FluentCart's product editor:
 * FluentCart keeps product data in its own tables, and injected meta panels have
 * a history of not rendering there at all. A product picker on our own screen
 * sidesteps that entirely.
 */
class Admin {

	/**
	 * Menu/page slug.
	 */
	const PAGE_SLUG = 'blt-fluent';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * FluentCRM field service.
	 *
	 * @var CRM_Fields
	 */
	private $crm;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Settings service.
	 * @param CRM_Fields $crm      FluentCRM field service.
	 */
	public function __construct( Settings $settings, CRM_Fields $crm ) {
		$this->settings = $settings;
		$this->crm      = $crm;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 100 );
		add_action( 'admin_post_blt_fluent_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_blt_fluent_action', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . BLT_FLUENT_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Capability required to manage the plugin.
	 *
	 * @return string
	 */
	public function capability() {
		/**
		 * Filter the capability required to manage BLT Fluent.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'blt_fluent/capability', 'manage_options' );
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->page_url() ),
			esc_html__( 'Settings', 'blt-fluent' )
		);

		return $links;
	}

	/**
	 * URL of the settings screen.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public function page_url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Register the menu entry, under FluentCart when its menu exists.
	 *
	 * @return void
	 */
	public function register_menu() {
		$parent = $this->fluent_cart_menu_slug();

		if ( '' !== $parent ) {
			add_submenu_page(
				$parent,
				__( 'BLT Fluent', 'blt-fluent' ),
				__( 'BLT Fluent', 'blt-fluent' ),
				$this->capability(),
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);

			return;
		}

		add_menu_page(
			__( 'BLT Fluent', 'blt-fluent' ),
			__( 'BLT Fluent', 'blt-fluent' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-forms',
			58
		);
	}

	/**
	 * Detect FluentCart's top-level menu slug.
	 *
	 * @return string Empty string when no FluentCart menu is registered.
	 */
	private function fluent_cart_menu_slug() {
		global $admin_page_hooks;

		if ( ! is_array( $admin_page_hooks ) ) {
			return '';
		}

		foreach ( array_keys( $admin_page_hooks ) as $slug ) {
			if ( is_string( $slug ) && preg_match( '/^fluent[-_]?cart/i', $slug ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Enqueue admin assets on our screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'blt-fluent-admin',
			BLT_FLUENT_URL . 'assets/admin.css',
			array(),
			BLT_FLUENT_VERSION
		);

		// jQuery UI Sortable ships with WP admin; no extra dependency needed.
		wp_enqueue_script(
			'blt-fluent-admin',
			BLT_FLUENT_URL . 'assets/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			BLT_FLUENT_VERSION,
			true
		);
	}

	/**
	 * The active tab.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'fields'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $tab, array( 'fields', 'products', 'advanced' ), true ) ? $tab : 'fields';
	}

	/**
	 * The field set being edited.
	 *
	 * @return string
	 */
	private function current_set() {
		$requested = isset( $_GET['set'] ) ? sanitize_key( wp_unslash( $_GET['set'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sets      = $this->settings->field_sets();

		if ( '' !== $requested && isset( $sets[ $requested ] ) ) {
			return $requested;
		}

		$keys = array_keys( $sets );

		return $keys ? $keys[0] : 'default';
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage BLT Fluent.', 'blt-fluent' ) );
		}

		$tab = $this->current_tab();

		echo '<div class="wrap blt-fluent-wrap">';
		echo '<h1>' . esc_html__( 'BLT Fluent', 'blt-fluent' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Choose which FluentCRM custom contact fields are collected at FluentCart checkout. Values are written straight to the contact record — FluentCRM stays the single source of truth.', 'blt-fluent' ) . '</p>';

		$this->render_notices();

		$tabs = array(
			'fields'   => __( 'Fields', 'blt-fluent' ),
			'products' => __( 'Products', 'blt-fluent' ),
			'advanced' => __( 'Advanced', 'blt-fluent' ),
		);

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $this->page_url( array( 'tab' => $slug ) ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';

		if ( 'products' === $tab ) {
			$this->render_products_tab();
		} elseif ( 'advanced' === $tab ) {
			$this->render_advanced_tab();
		} else {
			$this->render_fields_tab();
		}

		echo '</div>';
	}

	/**
	 * Render admin notices driven by redirect args.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect flag.
		if ( ! empty( $_GET['blt-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'blt-fluent' ) . '</p></div>';
		}

		if ( ! empty( $_GET['blt-message'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['blt-message'] ) ) ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $this->crm->available() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'FluentCRM\'s contact API is not reachable, so no fields can be read or written.', 'blt-fluent' ) . '</p></div>';
		}
	}

	/**
	 * Fields tab: choose, order and label the fields in a set.
	 *
	 * @return void
	 */
	private function render_fields_tab() {
		$set_key     = $this->current_set();
		$sets        = $this->settings->field_sets();
		$set         = isset( $sets[ $set_key ] ) ? $sets[ $set_key ] : array( 'label' => $set_key, 'title' => '', 'description' => '', 'fields' => array() );
		$definitions = $this->crm->definitions();

		$configured = array();

		foreach ( $set['fields'] as $field ) {
			$configured[ $field['slug'] ] = $field;
		}

		echo '<h2 class="screen-reader-text">' . esc_html__( 'Field sets', 'blt-fluent' ) . '</h2>';

		echo '<p class="blt-fluent-setbar">';
		echo '<strong>' . esc_html__( 'Field set:', 'blt-fluent' ) . '</strong> ';

		foreach ( $sets as $key => $candidate ) {
			printf(
				'<a href="%1$s" class="button button-small%2$s">%3$s</a> ',
				esc_url( $this->page_url( array( 'tab' => 'fields', 'set' => $key ) ) ),
				$key === $set_key ? ' button-primary' : '',
				esc_html( $candidate['label'] . ' (' . $key . ')' )
			);
		}

		echo '</p>';

		if ( empty( $definitions ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No FluentCRM custom contact fields were found. Create them in FluentCRM first (Settings → Custom Contact Fields); this plugin never defines its own.', 'blt-fluent' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'blt_fluent_save' );
		echo '<input type="hidden" name="action" value="blt_fluent_save" />';
		echo '<input type="hidden" name="tab" value="fields" />';
		printf( '<input type="hidden" name="set_key" value="%s" />', esc_attr( $set_key ) );

		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="blt-set-label">%1$s</label></th><td><input type="text" class="regular-text" id="blt-set-label" name="set_label" value="%2$s" /> <p class="description">%3$s</p></td></tr>',
			esc_html__( 'Set name (admin only)', 'blt-fluent' ),
			esc_attr( $set['label'] ),
			esc_html__( 'Shown on this screen only.', 'blt-fluent' )
		);
		printf(
			'<tr><th scope="row"><label for="blt-set-title">%1$s</label></th><td><input type="text" class="regular-text" id="blt-set-title" name="set_title" value="%2$s" /> <p class="description">%3$s</p></td></tr>',
			esc_html__( 'Heading at checkout', 'blt-fluent' ),
			esc_attr( $set['title'] ),
			esc_html__( 'Optional heading shown above the fields. Leave empty for none.', 'blt-fluent' )
		);
		printf(
			'<tr><th scope="row"><label for="blt-set-description">%1$s</label></th><td><input type="text" class="large-text" id="blt-set-description" name="set_description" value="%2$s" /></td></tr>',
			esc_html__( 'Intro text at checkout', 'blt-fluent' ),
			esc_attr( $set['description'] )
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Fields', 'blt-fluent' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Tick the fields to collect and drag the handles to set the order they appear in at checkout.', 'blt-fluent' ) . '</p>';

		$order = array_merge( array_keys( $configured ), array_diff( array_keys( $definitions ), array_keys( $configured ) ) );

		printf( '<input type="hidden" name="field_order" id="blt-field-order" value="%s" />', esc_attr( implode( ',', $order ) ) );

		echo '<table class="widefat striped blt-fluent-fields-table"><thead><tr>';
		echo '<th class="blt-fluent-col-handle"><span class="screen-reader-text">' . esc_html__( 'Reorder', 'blt-fluent' ) . '</span></th>';
		echo '<th class="blt-fluent-col-include">' . esc_html__( 'Collect', 'blt-fluent' ) . '</th>';
		echo '<th>' . esc_html__( 'FluentCRM field', 'blt-fluent' ) . '</th>';
		echo '<th>' . esc_html__( 'Label override', 'blt-fluent' ) . '</th>';
		echo '<th>' . esc_html__( 'Placeholder', 'blt-fluent' ) . '</th>';
		echo '<th>' . esc_html__( 'Help text', 'blt-fluent' ) . '</th>';
		echo '<th>' . esc_html__( 'Required', 'blt-fluent' ) . '</th>';
		echo '</tr></thead><tbody id="blt-fluent-sortable">';

		foreach ( $order as $slug ) {
			$definition = isset( $definitions[ $slug ] ) ? $definitions[ $slug ] : null;
			$field      = isset( $configured[ $slug ] ) ? $configured[ $slug ] : array(
				'required'    => false,
				'label'       => '',
				'placeholder' => '',
				'help'        => '',
			);

			$this->render_field_row( $slug, $definition, $field, isset( $configured[ $slug ] ) );
		}

		echo '</tbody></table>';

		submit_button( __( 'Save fields', 'blt-fluent' ) );
		echo '</form>';

		$this->render_set_management( $set_key, count( $sets ) );
	}

	/**
	 * One row of the field table.
	 *
	 * @param string     $slug       Field slug.
	 * @param array|null $definition FluentCRM definition, null when orphaned.
	 * @param array      $field      Stored per-field config.
	 * @param bool       $included   Whether the field is currently collected.
	 * @return void
	 */
	private function render_field_row( $slug, $definition, array $field, $included ) {
		printf( '<tr data-slug="%s">', esc_attr( $slug ) );

		echo '<td class="blt-fluent-col-handle"><span class="blt-fluent-handle dashicons dashicons-menu" aria-hidden="true"></span></td>';

		printf(
			'<td class="blt-fluent-col-include"><input type="checkbox" name="include[%1$s]" value="1"%2$s /></td>',
			esc_attr( $slug ),
			checked( $included, true, false )
		);

		echo '<td>';

		if ( $definition ) {
			printf(
				'<strong>%1$s</strong><br /><code>%2$s</code> <span class="blt-fluent-type">%3$s</span>',
				esc_html( $definition['label'] ),
				esc_html( $slug ),
				esc_html( $definition['raw_type'] )
			);
		} else {
			printf(
				'<strong>%1$s</strong><br /><code>%2$s</code> <span class="blt-fluent-orphan">%3$s</span><p class="description">%4$s</p>',
				esc_html( $slug ),
				esc_html( $slug ),
				esc_html__( 'Missing in FluentCRM', 'blt-fluent' ),
				esc_html__( 'This field is configured here but no longer exists in FluentCRM. It is skipped at checkout. Untick "Collect" and save to remove it.', 'blt-fluent' )
			);
		}

		echo '</td>';

		printf(
			'<td><input type="text" class="regular-text" name="label[%1$s]" value="%2$s" placeholder="%3$s" /></td>',
			esc_attr( $slug ),
			esc_attr( $field['label'] ),
			esc_attr( $definition ? $definition['label'] : '' )
		);

		printf(
			'<td><input type="text" class="regular-text" name="placeholder[%1$s]" value="%2$s" /></td>',
			esc_attr( $slug ),
			esc_attr( $field['placeholder'] )
		);

		printf(
			'<td><input type="text" class="regular-text" name="help[%1$s]" value="%2$s" /></td>',
			esc_attr( $slug ),
			esc_attr( $field['help'] )
		);

		printf(
			'<td><input type="checkbox" name="required[%1$s]" value="1"%2$s /></td>',
			esc_attr( $slug ),
			checked( ! empty( $field['required'] ), true, false )
		);

		echo '</tr>';
	}

	/**
	 * Add/delete field sets.
	 *
	 * @param string $set_key   Current set.
	 * @param int    $set_count Total number of sets.
	 * @return void
	 */
	private function render_set_management( $set_key, $set_count ) {
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Field sets', 'blt-fluent' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Separate sets let different membership tiers ask different questions. Products are mapped to a set on the Products tab.', 'blt-fluent' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="blt-fluent-inline-form">';
		wp_nonce_field( 'blt_fluent_save' );
		echo '<input type="hidden" name="action" value="blt_fluent_save" />';
		echo '<input type="hidden" name="tab" value="sets" />';
		printf(
			'<input type="text" name="new_set_key" placeholder="%1$s" pattern="[a-z0-9_\-]+" /> <input type="text" name="new_set_label" placeholder="%2$s" /> ',
			esc_attr( 'new_set_key' ),
			esc_attr__( 'Set name', 'blt-fluent' )
		);
		submit_button( __( 'Add field set', 'blt-fluent' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( $set_count > 1 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="blt-fluent-inline-form">';
			wp_nonce_field( 'blt_fluent_save' );
			echo '<input type="hidden" name="action" value="blt_fluent_save" />';
			echo '<input type="hidden" name="tab" value="sets" />';
			printf( '<input type="hidden" name="delete_set" value="%s" />', esc_attr( $set_key ) );
			printf(
				'<button type="submit" class="button button-link-delete" onclick="return confirm( \'%1$s\' );">%2$s</button>',
				esc_js( __( 'Delete this field set? Products mapped to it will stop collecting fields. No member data is affected.', 'blt-fluent' ) ),
				esc_html(
					sprintf(
						/* translators: %s: field set key */
						__( 'Delete the "%s" set', 'blt-fluent' ),
						$set_key
					)
				)
			);
			echo '</form>';
		}
	}

	/**
	 * Products tab: map products to field sets.
	 *
	 * @return void
	 */
	private function render_products_tab() {
		$map      = $this->settings->product_map();
		$sets     = $this->settings->field_sets();
		$products = $this->products();

		echo '<h2>' . esc_html__( 'Products that collect fields', 'blt-fluent' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A checkout renders fields only when the cart holds a mapped product. Use product:variation (for example 123:45) to ask different questions per tier.', 'blt-fluent' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'blt_fluent_save' );
		echo '<input type="hidden" name="action" value="blt_fluent_save" />';
		echo '<input type="hidden" name="tab" value="products" />';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'blt-fluent' ) . '</th>';
		echo '<th>' . esc_html__( 'Field set', 'blt-fluent' ) . '</th>';
		echo '<th>' . esc_html__( 'Remove', 'blt-fluent' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $map ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No products mapped yet. Nothing is rendered at checkout until you add one.', 'blt-fluent' ) . '</td></tr>';
		}

		foreach ( $map as $product_key => $mapped_set ) {
			$label = isset( $products[ (int) $product_key ] ) ? $products[ (int) $product_key ] : '';

			echo '<tr>';
			printf(
				'<td><code>%1$s</code>%2$s<input type="hidden" name="product_key[]" value="%1$s" /></td>',
				esc_attr( $product_key ),
				'' !== $label ? ' ' . esc_html( $label ) : ''
			);

			echo '<td><select name="product_set[]">';

			foreach ( $sets as $key => $set ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $key ),
					selected( $key, $mapped_set, false ),
					esc_html( $set['label'] . ' (' . $key . ')' )
				);
			}

			echo '</select></td>';
			printf(
				'<td><label><input type="checkbox" name="remove[%1$s]" value="1" /> %2$s</label></td>',
				esc_attr( $product_key ),
				esc_html__( 'Remove', 'blt-fluent' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Add a product', 'blt-fluent' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">';
		echo esc_html__( 'Product', 'blt-fluent' );
		echo '</th><td>';

		if ( ! empty( $products ) ) {
			echo '<select name="new_product_select">';
			printf( '<option value="">%s</option>', esc_html__( '— Select a FluentCart product —', 'blt-fluent' ) );

			foreach ( $products as $product_id => $label ) {
				printf(
					'<option value="%1$s">%2$s</option>',
					esc_attr( $product_id ),
					esc_html( $label . ' (#' . $product_id . ')' )
				);
			}

			echo '</select> ';
			echo '<span class="description">' . esc_html__( 'or', 'blt-fluent' ) . '</span> ';
		} else {
			echo '<p class="description">' . esc_html__( 'No FluentCart products were detected automatically — enter the product ID by hand.', 'blt-fluent' ) . '</p>';
		}

		printf(
			'<input type="text" name="new_product_key" placeholder="%s" /> ',
			esc_attr__( 'product ID or 123:45', 'blt-fluent' )
		);

		echo '<select name="new_product_set">';

		foreach ( $sets as $key => $set ) {
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $key ),
				esc_html( $set['label'] . ' (' . $key . ')' )
			);
		}

		echo '</select>';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Save products', 'blt-fluent' ) );
		echo '</form>';
	}

	/**
	 * Advanced tab: behaviour toggles, data lifecycle and diagnostics.
	 *
	 * @return void
	 */
	private function render_advanced_tab() {
		$config = $this->settings->get();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'blt_fluent_save' );
		echo '<input type="hidden" name="action" value="blt_fluent_save" />';
		echo '<input type="hidden" name="tab" value="advanced" />';

		echo '<h2>' . esc_html__( 'Behaviour', 'blt-fluent' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="skip_renewals" value="1"%2$s /> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Renewals', 'blt-fluent' ),
			checked( ! empty( $config['skip_renewals'] ), true, false ),
			esc_html__( 'Skip the fields on renewal orders', 'blt-fluent' ),
			esc_html__( 'Members are not re-asked for their profile details every time a subscription renews.', 'blt-fluent' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="prefill" value="1"%2$s /> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Pre-fill', 'blt-fluent' ),
			checked( ! empty( $config['prefill'] ), true, false ),
			esc_html__( 'Pre-fill from the existing FluentCRM contact', 'blt-fluent' ),
			esc_html__( 'Applies to logged-in customers, or once an email address is known.', 'blt-fluent' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="debug" value="1"%2$s /> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Diagnostics', 'blt-fluent' ),
			checked( ! empty( $config['debug'] ), true, false ),
			esc_html__( 'Record a diagnostic log', 'blt-fluent' ),
			esc_html__( 'Keeps the last 40 events below. Useful while verifying checkout on a live site; leave off otherwise.', 'blt-fluent' )
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'When this plugin is deleted', 'blt-fluent' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1"%2$s /> <strong>%3$s</strong></label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Clean-up', 'blt-fluent' ),
			checked( ! empty( $config['delete_on_uninstall'] ), true, false ),
			esc_html__( 'Delete BLT Fluent settings when the plugin is deleted', 'blt-fluent' ),
			esc_html__( 'Leave unchecked to keep your field configuration if you remove and later reinstall the plugin. Member data in FluentCRM is never deleted either way. Deactivating the plugin never deletes anything.', 'blt-fluent' )
		);
		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'blt-fluent' ) );
		echo '</form>';

		$this->render_diagnostics();
	}

	/**
	 * Diagnostics panel.
	 *
	 * @return void
	 */
	private function render_diagnostics() {
		$next_run    = Updater::next_run();
		$definitions = $this->crm->definitions();
		$render_hook = apply_filters( 'blt_fluent/render_hook', 'fluent_cart/before_payment_methods' );

		$rows = array(
			__( 'Plugin version', 'blt-fluent' )        => BLT_FLUENT_VERSION,
			__( 'FluentCart', 'blt-fluent' )            => Dependencies::fluentcart_active()
				? sprintf( /* translators: %s: version */ __( 'active %s', 'blt-fluent' ), Dependencies::fluentcart_version() )
				: __( 'not detected', 'blt-fluent' ),
			__( 'FluentCRM', 'blt-fluent' )             => Dependencies::fluentcrm_active()
				? sprintf( /* translators: %s: version */ __( 'active %s', 'blt-fluent' ), Dependencies::fluentcrm_version() )
				: __( 'not detected', 'blt-fluent' ),
			__( 'Custom fields found', 'blt-fluent' )   => sprintf(
				/* translators: 1: number of fields, 2: source of the definitions */
				__( '%1$d via %2$s', 'blt-fluent' ),
				count( $definitions ),
				$this->crm->source()
			),
			__( 'Render hook', 'blt-fluent' )           => (string) $render_hook,
			__( 'Update checker', 'blt-fluent' )        => '' !== Updater::library_path()
				? ( Updater::instance()->checker() ? __( 'ready', 'blt-fluent' ) : __( 'library present, not initialised', 'blt-fluent' ) )
				: __( 'plugin-update-checker not installed', 'blt-fluent' ),
			__( 'GitHub token', 'blt-fluent' )          => '' !== Updater::token() ? __( 'configured', 'blt-fluent' ) : __( 'not set (BLT_FLUENT_GH_TOKEN)', 'blt-fluent' ),
			__( 'Next update check', 'blt-fluent' )     => $next_run
				? wp_date( 'Y-m-d H:i', $next_run )
				: __( 'not scheduled', 'blt-fluent' ),
		);

		echo '<hr /><h2>' . esc_html__( 'Diagnostics', 'blt-fluent' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row" style="width:220px">%1$s</th><td>%2$s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</tbody></table>';

		echo '<p>';
		$this->render_action_button( 'check_updates', __( 'Check for updates now', 'blt-fluent' ) );
		echo ' ';
		$this->render_action_button( 'clear_log', __( 'Clear log', 'blt-fluent' ) );
		echo '</p>';

		$entries = Plugin::log_entries();

		if ( empty( $entries ) ) {
			echo '<p class="description">' . esc_html__( 'No log entries recorded.', 'blt-fluent' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:160px">' . esc_html__( 'When', 'blt-fluent' ) . '</th>';
		echo '<th>' . esc_html__( 'Event', 'blt-fluent' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_reverse( $entries ) as $entry ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s%3$s</td></tr>',
				esc_html( wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) ),
				esc_html( $entry['message'] ),
				'' !== $entry['context'] ? ' <code>' . esc_html( $entry['context'] ) . '</code>' : ''
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A small POST form rendering one maintenance button.
	 *
	 * @param string $do    Action key.
	 * @param string $label Button label.
	 * @return void
	 */
	private function render_action_button( $do, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="blt-fluent-inline-form">';
		wp_nonce_field( 'blt_fluent_action' );
		echo '<input type="hidden" name="action" value="blt_fluent_action" />';
		printf( '<input type="hidden" name="do" value="%s" />', esc_attr( $do ) );
		printf( '<button type="submit" class="button">%s</button>', esc_html( $label ) );
		echo '</form>';
	}

	/**
	 * FluentCart products, best effort.
	 *
	 * @return array<int,string> Product ID => label.
	 */
	private function products() {
		$products = array();

		if ( class_exists( '\FluentCart\App\Models\Product' ) ) {
			try {
				$rows = \FluentCart\App\Models\Product::query()->limit( 200 )->get();

				foreach ( $rows as $row ) {
					$data = Cart_Context::to_array( $row );

					if ( ! is_array( $data ) ) {
						continue;
					}

					$id = (int) ( Cart_Context::first_scalar( $data, array( 'ID', 'id', 'post_id' ) ) );

					if ( ! $id ) {
						continue;
					}

					$title = (string) Cart_Context::first_scalar( $data, array( 'post_title', 'title', 'name' ) );

					$products[ $id ] = '' !== $title ? $title : sprintf( '#%d', $id );
				}
			} catch ( \Throwable $e ) {
				Plugin::log( 'Product query failed', $e->getMessage() );
			}
		}

		if ( empty( $products ) ) {
			foreach ( array( 'fc_product', 'fluent-cart-product', 'fluentcart_product', 'fct_product' ) as $post_type ) {
				if ( ! post_type_exists( $post_type ) ) {
					continue;
				}

				$posts = get_posts(
					array(
						'post_type'        => $post_type,
						'posts_per_page'   => 200,
						'post_status'      => array( 'publish', 'draft', 'private' ),
						'orderby'          => 'title',
						'order'            => 'ASC',
						'suppress_filters' => false,
					)
				);

				foreach ( $posts as $post ) {
					$products[ (int) $post->ID ] = $post->post_title;
				}

				if ( ! empty( $products ) ) {
					break;
				}
			}
		}

		/**
		 * Filter the product list shown in the picker.
		 *
		 * @param array<int,string> $products Product ID => label.
		 */
		return apply_filters( 'blt_fluent/admin_products', $products );
	}

	/**
	 * Handle a settings save.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage BLT Fluent.', 'blt-fluent' ) );
		}

		check_admin_referer( 'blt_fluent_save' );

		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$config = $this->settings->get();
		$args   = array( 'blt-updated' => 1 );

		if ( 'fields' === $tab ) {
			$set_key = isset( $_POST['set_key'] ) ? sanitize_key( wp_unslash( $_POST['set_key'] ) ) : '';

			if ( isset( $config['field_sets'][ $set_key ] ) ) {
				$config['field_sets'][ $set_key ] = $this->collect_field_set( $config['field_sets'][ $set_key ] );
			}

			$args['tab'] = 'fields';
			$args['set'] = $set_key;
		} elseif ( 'sets' === $tab ) {
			$config      = $this->apply_set_changes( $config );
			$args['tab'] = 'fields';
		} elseif ( 'products' === $tab ) {
			$config['product_map'] = $this->collect_product_map( $config );
			$args['tab']           = 'products';
		} elseif ( 'advanced' === $tab ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above by check_admin_referer().
			$config['skip_renewals']       = ! empty( $_POST['skip_renewals'] );
			$config['prefill']             = ! empty( $_POST['prefill'] );
			$config['debug']               = ! empty( $_POST['debug'] );
			$config['delete_on_uninstall'] = ! empty( $_POST['delete_on_uninstall'] );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$args['tab'] = 'advanced';
		}

		$this->settings->save( $config );

		wp_safe_redirect( $this->page_url( $args ) );
		exit;
	}

	/**
	 * Build one field set from the submitted form.
	 *
	 * @param array $set Existing set.
	 * @return array
	 */
	private function collect_field_set( array $set ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$order    = isset( $_POST['field_order'] ) ? sanitize_text_field( wp_unslash( $_POST['field_order'] ) ) : '';
		$included = isset( $_POST['include'] ) && is_array( $_POST['include'] ) ? array_keys( wp_unslash( $_POST['include'] ) ) : array();

		$labels       = isset( $_POST['label'] ) && is_array( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : array();
		$placeholders = isset( $_POST['placeholder'] ) && is_array( $_POST['placeholder'] ) ? wp_unslash( $_POST['placeholder'] ) : array();
		$helps        = isset( $_POST['help'] ) && is_array( $_POST['help'] ) ? wp_unslash( $_POST['help'] ) : array();
		$required     = isset( $_POST['required'] ) && is_array( $_POST['required'] ) ? array_keys( wp_unslash( $_POST['required'] ) ) : array();

		$set['label']       = isset( $_POST['set_label'] ) ? sanitize_text_field( wp_unslash( $_POST['set_label'] ) ) : $set['label'];
		$set['title']       = isset( $_POST['set_title'] ) ? sanitize_text_field( wp_unslash( $_POST['set_title'] ) ) : $set['title'];
		$set['description'] = isset( $_POST['set_description'] ) ? sanitize_text_field( wp_unslash( $_POST['set_description'] ) ) : $set['description'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$included = array_map( 'sanitize_key', $included );
		$required = array_map( 'sanitize_key', $required );

		$ordered = array_filter( array_map( 'sanitize_key', explode( ',', $order ) ) );

		// Anything ticked but missing from the order list still gets included.
		$ordered = array_merge( $ordered, array_diff( $included, $ordered ) );

		$fields = array();

		foreach ( $ordered as $slug ) {
			if ( ! in_array( $slug, $included, true ) ) {
				continue;
			}

			$fields[] = array(
				'slug'        => $slug,
				'required'    => in_array( $slug, $required, true ),
				'label'       => isset( $labels[ $slug ] ) ? sanitize_text_field( $labels[ $slug ] ) : '',
				'placeholder' => isset( $placeholders[ $slug ] ) ? sanitize_text_field( $placeholders[ $slug ] ) : '',
				'help'        => isset( $helps[ $slug ] ) ? sanitize_text_field( $helps[ $slug ] ) : '',
			);
		}

		$set['fields'] = $fields;

		return $set;
	}

	/**
	 * Add or delete a field set.
	 *
	 * @param array $config Current config.
	 * @return array
	 */
	private function apply_set_changes( array $config ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$new_key   = isset( $_POST['new_set_key'] ) ? sanitize_key( wp_unslash( $_POST['new_set_key'] ) ) : '';
		$new_label = isset( $_POST['new_set_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_set_label'] ) ) : '';
		$delete    = isset( $_POST['delete_set'] ) ? sanitize_key( wp_unslash( $_POST['delete_set'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $new_key && ! isset( $config['field_sets'][ $new_key ] ) ) {
			$config['field_sets'][ $new_key ] = array(
				'label'       => '' !== $new_label ? $new_label : $new_key,
				'title'       => '',
				'description' => '',
				'fields'      => array(),
			);
		}

		if ( '' !== $delete && isset( $config['field_sets'][ $delete ] ) && count( $config['field_sets'] ) > 1 ) {
			unset( $config['field_sets'][ $delete ] );

			// Product mappings pointing at a deleted set are dropped by
			// normalize(), which only keeps mappings to sets that still exist.
		}

		return $config;
	}

	/**
	 * Build the product map from the submitted form.
	 *
	 * @param array $config Current config.
	 * @return array
	 */
	private function collect_product_map( array $config ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$keys   = isset( $_POST['product_key'] ) && is_array( $_POST['product_key'] ) ? wp_unslash( $_POST['product_key'] ) : array();
		$values = isset( $_POST['product_set'] ) && is_array( $_POST['product_set'] ) ? wp_unslash( $_POST['product_set'] ) : array();
		$remove = isset( $_POST['remove'] ) && is_array( $_POST['remove'] ) ? array_keys( wp_unslash( $_POST['remove'] ) ) : array();

		$new_key    = isset( $_POST['new_product_key'] ) ? sanitize_text_field( wp_unslash( $_POST['new_product_key'] ) ) : '';
		$new_select = isset( $_POST['new_product_select'] ) ? sanitize_text_field( wp_unslash( $_POST['new_product_select'] ) ) : '';
		$new_set    = isset( $_POST['new_product_set'] ) ? sanitize_key( wp_unslash( $_POST['new_product_set'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$remove = array_map( array( Settings::class, 'normalize_product_key' ), $remove );
		$map    = array();

		foreach ( array_values( $keys ) as $index => $key ) {
			$key = Settings::normalize_product_key( $key );

			if ( '' === $key || in_array( $key, $remove, true ) ) {
				continue;
			}

			$set_key = isset( $values[ $index ] ) ? sanitize_key( $values[ $index ] ) : '';

			if ( isset( $config['field_sets'][ $set_key ] ) ) {
				$map[ $key ] = $set_key;
			}
		}

		$addition = '' !== $new_key ? $new_key : $new_select;
		$addition = Settings::normalize_product_key( $addition );

		if ( '' !== $addition && isset( $config['field_sets'][ $new_set ] ) ) {
			$map[ $addition ] = $new_set;
		}

		return $map;
	}

	/**
	 * Handle maintenance buttons.
	 *
	 * @return void
	 */
	public function handle_action() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage BLT Fluent.', 'blt-fluent' ) );
		}

		check_admin_referer( 'blt_fluent_action' );

		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$message = '';

		if ( 'clear_log' === $do ) {
			Plugin::clear_log();
			$message = __( 'Diagnostic log cleared.', 'blt-fluent' );
		} elseif ( 'check_updates' === $do ) {
			$message = Updater::instance()->check_now();
		}

		wp_safe_redirect(
			$this->page_url(
				array(
					'tab'         => 'advanced',
					'blt-message' => $message,
				)
			)
		);
		exit;
	}
}
