<?php

namespace PIWA;

class Payments {
	use Singleton;

	const POST_TYPE        = 'amazon-payment';
	const POST_TYPE_PLURAL = 'amazon-payments';

	public $current_screen = [];

	public function __construct() {
		add_action( 'init', [ $this, 'init' ], 30 );
		add_action( 'admin_init', [ $this, 'admin_init' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ], 30 );
		add_action( 'template_redirect', [ $this, 'maybe_record_checkout_session' ] );
	}

	public function init() {
		register_post_type(
			self::POST_TYPE,
			[
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => 'admin.php?page=' . $this->option_key,
				'query_var'           => true,
				'rewrite'             => [ 'slug' => 'payment' ],
				'taxonomies'          => [],
				'capability_type'     => [ self::POST_TYPE, self::POST_TYPE_PLURAL ],
				'map_meta_cap'        => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_position'       => 5,
				'menu_icon'           => 'dashicons-media-document',
				'supports'            => [ 'author' ],
				'exclude_from_search' => true,
				'can_export'          => true,
				'labels'              => $this->i18n( self::POST_TYPE . '_labels' ),
			]
		);
	}

	public function admin_init() {
		// Make sure administrators have permission to edit this post type.
		if ( current_user_can( 'manage_options' ) && ! current_user_can( 'edit_' . self::POST_TYPE_PLURAL ) ) {
			$role = get_role( 'administrator' );
			foreach ( (array) get_post_type_object( self::POST_TYPE )->cap as $capability ) {
				$role->add_cap( $capability );
			}
		}

		switch ( $this->get_current_screen() ) {
			case 'edit':
				add_filter( 'gettext', [ $this, 'gettext' ], 20, 3 );
				add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
				add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
				break;
			case 'list':
				add_filter( 'gettext', [ $this, 'gettext' ], 20, 3 );
				add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

				// Invoice list columns.
				add_filter( sprintf( 'manage_%s_posts_columns', self::POST_TYPE ), [ $this, 'add_post_listing_columns' ] );
				add_action( 'manage_posts_custom_column', [ $this, 'manage_posts_custom_column' ], 10, 2 );

				// Column sorting.
				add_filter( sprintf( 'manage_edit-%s_sortable_columns', self::POST_TYPE ), [ $this, 'sortable_columns' ] );
				add_action( 'pre_get_posts', [ $this, 'sort_invoices' ] );
				break;
		}
	}

	public function get_current_screen() {

        $php_self = filter_input(INPUT_SERVER, 'PHP_SELF', FILTER_SANITIZE_URL);

		if ( empty( $this->current_screen ) ) {
			$this->current_screen = [
				'basename'          => basename( $php_self ),
				'is_this_post_type' => array_key_exists( 'post_type', $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					? ( self::POST_TYPE === filter_input( INPUT_GET, 'post_type', FILTER_SANITIZE_SPECIAL_CHARS ) )
					: ( array_key_exists( 'post', $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						? ( self::POST_TYPE === get_post_type( filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT ) ) )
						: false ),
			];
		}

		if ( ! $this->current_screen['is_this_post_type'] ) {
			return false;
		}

		switch ( $this->current_screen['basename'] ) {
			case 'post.php':
				return 'edit'; // Edit screen.
			case 'edit.php':
				return 'list'; // Listing screen.
			case 'post-new.php':
				// Manually creating a payment does not exist.
				wp_safe_redirect( add_query_arg( [ 'post_type' => self::POST_TYPE ], admin_url( 'edit.php' ) ) );
				exit;
		}
		return false;
	}

	public function admin_enqueue_scripts() {
		switch ( $this->get_current_screen() ) {
			case 'list':
				wp_enqueue_style(
					'admin-payments-listing',
					plugins_url( 'src/css/admin-payments-listing.css', $this->plugin_file ),
					[],
					md5_file( dirname( $this->plugin_file ) . '/src/css/admin-payments-listing.css' )
				);
				wp_enqueue_script(
					'admin-payment-listing',
					plugins_url( 'src/js/admin-payment-listing.js', $this->plugin_file ),
					[],
					md5_file( dirname( $this->plugin_file ) . '/src/js/admin-payment-listing.js' ),
					true
				);
				wp_localize_script(
					'admin-payment-listing',
					'PIWAPaymentListingI18n',
					[
						'payment_failed' => $this->i18n( 'payment_failed' ),
						'capture_notice' => $this->i18n( 'capture_notice' ),
					]
				);
				break;
			case 'edit':
				wp_enqueue_style(
					'admin-payments-edit',
					plugins_url( 'src/css/admin-payments-edit.css', $this->plugin_file ),
					[],
					md5_file( dirname( $this->plugin_file ) . '/src/css/admin-payments-edit.css' )
				);
				wp_enqueue_script(
					'admin-payment-detail',
					plugins_url( 'src/js/admin-payment-detail.js', $this->plugin_file ),
					[],
					md5_file( dirname( $this->plugin_file ) . '/src/js/admin-payment-detail.js' ),
					true
				);
				break;
		}
	}

	/**
	 * If a user has capabilities to see the submenu page, but not the Settings page which is the default parent,
	 * WP will make the URL for the first accessible submenu page the URL for the parent page automatically.
	 */
	public function admin_menu() {
		add_submenu_page(
			$this->option_key,
			$this->i18n( self::POST_TYPE . '_labels' )['menu_name'],
			$this->i18n( self::POST_TYPE . '_labels' )['menu_name'],
			'edit_' . self::POST_TYPE_PLURAL,
			'edit.php?post_type=' . self::POST_TYPE
		);
	}

	/**
	 * Modify strings to be appropriate to the Payment post type.
	 */
	public function gettext( $translation, $text, $domain ) {
		switch ( $text ) {
			case 'Add title':
				return $this->i18n( 'add_description' );
			case 'Title':
				return $this->i18n( 'description' );
			case 'Author':
				return $this->i18n( 'customer' );
		}
		return $translation;
	}

	/**
	 * @see https://developer.amazon.com/docs/amazon-pay-api-v2/checkout-session.html
	 */
	public function maybe_record_checkout_session() {
		if ( ! isset( $_GET['amazonCheckoutSessionId'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$checkout_session_id = sanitize_key( $_GET['amazonCheckoutSessionId'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Has this checkout session already been recorded?
		if ( ! empty(
			get_posts(
				[
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'meta_key'       => 'checkout_session_id',
					'meta_value'     => $checkout_session_id,
				]
			)
		) ) {
			return;
		}

		$response = $this->client->get_checkout_session( $checkout_session_id );

		if (
			array_key_exists( 'statusDetails', (array) $response )
			&& array_key_exists( 'state', (array) $response['statusDetails'] )
			&& 'Canceled' === $response['statusDetails']['state']
		) {
			$this->process_canceled_checkout_session( $response, $checkout_session_id );
			return;
		}
		$this->process_checkout_session( $response, $checkout_session_id );
	}

	private function process_checkout_session( $response, $checkout_session_id ) {
		$user = $this->maybe_create_user( $response );

		if ( ! empty( $user ) ) {
			$post_content = '';
			if ( ! empty( $response['billingAddress'] ) ) {
				$post_content .= sprintf(
					'-- Billing --
Name: %s
Address 1: %s
Address 2: %s
Address 3: %s
City: %s
County: %s
District: %s
State or Region: %s
Postal Code: %s
Country Code: %s
Phone: %s',
					$response['billingAddress']['name'],
					$response['billingAddress']['addressLine1'],
					$response['billingAddress']['addressLine2'],
					$response['billingAddress']['addressLine3'],
					$response['billingAddress']['city'],
					$response['billingAddress']['county'],
					$response['billingAddress']['district'],
					$response['billingAddress']['stateOrRegion'],
					$response['billingAddress']['postalCode'],
					$response['billingAddress']['countryCode'],
					$response['billingAddress']['phoneNumber']
				);
			}
			if ( ! empty( $response['shippingAddress'] ) ) {
				$post_content .= sprintf(
					'%s-- Shipping --
Name: %s
Address 1: %s
Address 2: %s
Address 3: %s
City: %s
County: %s
District: %s
State or Region: %s
Postal Code: %s
Country Code: %s
Phone: %s',
					PHP_EOL . PHP_EOL,
					$response['shippingAddress']['name'],
					$response['shippingAddress']['addressLine1'],
					$response['shippingAddress']['addressLine2'],
					$response['shippingAddress']['addressLine3'],
					$response['shippingAddress']['city'],
					$response['shippingAddress']['county'],
					$response['shippingAddress']['district'],
					$response['shippingAddress']['stateOrRegion'],
					$response['shippingAddress']['postalCode'],
					$response['shippingAddress']['countryCode'],
					$response['shippingAddress']['phoneNumber']
				);
			}

			$payment_args = [
				'post_type'    => self::POST_TYPE,
				'post_author'  => $user->ID,
				'post_title'   => sprintf(
					'%s%s %s %s %s',
					$this->i18n( 'currency_symbols_plain' )[ $response['paymentDetails']['chargeAmount']['currencyCode'] ],
					str_replace( '.00', '', $response['paymentDetails']['chargeAmount']['amount'] ),
					$this->i18n( 'from' ),
					$response['buyer']['name'],
					( ! empty( $response['merchantMetadata']['merchantReferenceId'] ) )
						? sprintf(
							'%s %s',
							$this->i18n( 'for' ),
							$response['merchantMetadata']['merchantReferenceId']
						)
						: ''
				),
				'post_content' => $post_content,
				'post_status'  => 'publish',
				'post_parent'  => array_key_exists( 'ap-payment-id', $_GET ) ? intval( explode( '-', sanitize_text_field( $_GET['ap-payment-id']) )[0] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			];
			$payment_id   = wp_insert_post( $payment_args, true );

			if ( is_wp_error( $payment_id ) ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'Amazon Pay: Problem recording payment %s: %s %s %s',
						sanitize_key( $_GET['amazonCheckoutSessionId'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$payment_id->get_error_message(),
						PHP_EOL,
						esc_html( print_r( $payment_args, true ) ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
					)
				);
				return false;
			}

			// Meta available for a payment that may not yet be authorized.
			update_post_meta( $payment_id, 'invoice_number', $response['merchantMetadata']['merchantReferenceId'] );
			update_post_meta( $payment_id, 'amount', trim( $response['paymentDetails']['chargeAmount']['amount'] ) );
			update_post_meta( $payment_id, 'currency_code', trim( $response['paymentDetails']['chargeAmount']['currencyCode'] ) );
			update_post_meta( $payment_id, 'checkout_session_id', $checkout_session_id );
			update_post_meta( $payment_id, 'checkout_session_object', $response );

			$payment_status = $this->client->maybe_complete_checkout_session( $response );

			foreach ( [ 'status_code', 'payment_status', 'payment_status_message', 'checkout_session_response' ] as $meta_key ) {
				if ( array_key_exists( $meta_key, (array) $payment_status ) && ! empty( $payment_status[ $meta_key ] ) ) {
					update_post_meta( $payment_id, $meta_key, $payment_status[ $meta_key ] );
				}
			}
			if ( array_key_exists( 'checkout_session_response', (array) $payment_status ) ) {
				if ( array_key_exists( 'chargeId', (array) $payment_status['checkout_session_response'] ) ) {
					update_post_meta( $payment_id, 'charge_id', $payment_status['checkout_session_response']['chargeId'] );
				}
				if ( array_key_exists( 'chargePermissionId', (array) $payment_status['checkout_session_response'] ) ) {
					update_post_meta( $payment_id, 'charge_permission_id', $payment_status['checkout_session_response']['chargePermissionId'] );
				}
			}
		}
	}

	private function process_canceled_checkout_session( $response, $checkout_session_id ) {
		$payment_args = [
			'post_type'    => self::POST_TYPE,
			'post_author'  => ( is_user_logged_in() ) ? get_current_user_id() : 0,
			'post_title'   => 'Declined',
			'post_content' => '',
			'post_status'  => 'pending',
			'post_parent'  => array_key_exists( 'ap-payment-id', $_GET ) ? intval( explode( '-', sanitize_text_field($_GET['ap-payment-id']) )[0] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		];
		$payment_id   = wp_insert_post( $payment_args, true );

		if ( is_wp_error( $payment_id ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Amazon Pay: Problem recording declined payment %s: %s %s %s',
					sanitize_key( $_GET['amazonCheckoutSessionId'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$payment_id->get_error_message(),
					PHP_EOL,
					esc_html( print_r( $payment_args, true ) ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				)
			);
			return false;
		}

		update_post_meta( $payment_id, 'payment_status', 'declined' );
		update_post_meta( $payment_id, 'declined_reason', wp_strip_all_tags( $response['statusDetails']['reasonDescription'] ) );
		update_post_meta( $payment_id, 'checkout_session_id', $checkout_session_id );
		update_post_meta( $payment_id, 'checkout_session_object', $response );

		return true;
	}

	public function maybe_create_user( $response ) {
		$email = sanitize_email( $response['buyer']['email'] );

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if (
				$user->user_email !== $email
				&& ! in_array( $email, get_user_meta( $user->ID, 'amazon_emails', false ), true )
			) {
				add_user_meta( $user->ID, 'amazon_emails', $email );
			}
			return $user;
		}
		$user = get_user_by( 'email', $email );
		if ( ! empty( $user ) ) {
			return $user;
		}
		$users = get_users(
			[
				'fields'     => 'all',
				'meta_key'   => 'amazon_emails',
				'meta_value' => $email,
				'number'     => 1,
			]
		);
		if ( ! empty( $users ) ) {
			return $users[0];
		}

		$display_name  = trim( $response['buyer']['name'] );
		$exploded_name = explode( ' ', $display_name );
		if ( count( $exploded_name ) > 1 ) {
			$last_name  = array_pop( $exploded_name );
			$first_name = implode( ' ', $exploded_name );
		} else {
			$first_name = $display_name;
			$last_name  = '';
		}

		if ( ! wp_roles()->is_role( 'customer' ) ) {
			add_role( 'customer', 'Customer', [ 'read' => true ] );
		}

		$user_id = wp_insert_user(
			[
				'role'         => 'customer',
				'user_login'   => str_replace(
					[ '@', '.', '+' ],
					[ '-at-', '-dot-', '-plus-' ],
					$email
				),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 18 ),
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $display_name,
				'description'  => $this->i18n( 'created_by_piwa' ),
			]
		);

		if ( is_wp_error( $user_id ) ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Amazon Pay: Problem creating user for %s: %s',
					$email,
					$user_id->get_error_message()
				)
			);
			return false;
		}

		return $this->log_user_in( get_user_by( 'ID', $user_id ) );
	}

	/**
	 * Initiates a session for a newly created account.
	 */
	private function log_user_in( $user ) {
		if ( ! is_user_logged_in() ) {
			clean_user_cache( $user->ID );
			wp_clear_auth_cookie();
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true, false );
			update_user_caches( $user );
			return $user;
		}
		return false;
	}

	public function edit_form_after_title( $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}
		printf(
			'<pre>%s</pre>',
			wp_kses_post( $post->post_content )
		);
	}
	public function add_meta_boxes() {
		add_meta_box( 'details', $this->i18n( 'details' ), [ $this, 'meta_box_details' ], self::POST_TYPE, 'side' );
		add_meta_box( 'customer', $this->i18n( 'customer' ), [ $this, 'meta_box_customer' ], self::POST_TYPE, 'normal' );
		remove_meta_box( 'authordiv', self::POST_TYPE, 'normal' );
		remove_meta_box( 'slugdiv', self::POST_TYPE, 'normal' );
	}

	public function meta_box_details( $post ) {
		$amount               = get_post_meta( $post->ID, 'amount', true );
		$currency_code        = get_post_meta( $post->ID, 'currency_code', true );
		$charge_permission_id = get_post_meta( $post->ID, 'charge_permission_id', true );
		$declined_reason      = get_post_meta( $post->ID, 'declined_reason', true );

		echo '<dl>';

		// Date.
		printf(
			'<dt>%s</dt><dd>%s</dd>',
			esc_html( $this->i18n( 'received' ) ),
			esc_html(
				sprintf(
					$this->i18n( 'date_at_time' ),
					get_the_date(),
					get_the_time()
				)
			)
		);

		// Amount.
		if ( ! empty( $amount ) && ! empty( $currency_code ) ) {
			printf(
				'<dt>%s</dt><dd>%s%s</dd>',
				esc_html( $this->i18n( 'amount' ) ),
				esc_html( $this->i18n( 'currency_symbols_plain' )[ $currency_code ] ),
				esc_html( str_replace( '.00', '', number_format( floatval( $amount ), 2 ) ) )
			);
		}

		// Reference ID / Seller Central Link.
		if ( ! empty( $charge_permission_id ) ) {
			printf(
				'<dt>%s</dt>
				<dl>
					<a href="https://sellercentral.amazon.com/external-payments/pmd/payment-details?orderReferenceId=%s" target="_blank">
						%s
					</a>
					<br/>
					<small class="help">%s</small>
				</dl>',
				esc_html( $this->i18n( 'reference_id' ) ),
				esc_attr( $charge_permission_id ),
				esc_html( $charge_permission_id ),
				wp_kses_post(
					sprintf(
						$this->i18n( 'if_link_not_found' ),
						( 'P' === substr( $charge_permission_id, 0, 1 ) )
							? $this->i18n( 'production_view' )
							: $this->i18n( 'sandbox_view' )
					)
				)
			);
		}
		if ( ! empty( $declined_reason ) ) {
			printf(
				'<dt>%s</dt>
				<dd>%s</dd>',
				esc_html( $this->i18n( 'payment_failed' ) ),
				esc_html( $declined_reason )
			);
		}

		echo '</dl>';
	}

	public function meta_box_customer( $post ) {
		$user = get_user_by( 'ID', $post->post_author );

		printf(
			'<h3><a href="%s">%s</a></h3><p><a href="mailto:%s" class="button">%s</a></p>',
			esc_url( get_edit_user_link( $user->ID ) ),
			( ! empty( $user->first_name ) && ! empty( $user->last_name ) )
				? esc_html( sprintf( '%s %s', $user->first_name, $user->last_name ) )
				: esc_html( $user->nickname ),
			esc_attr( $user->user_email ),
			esc_html( $user->user_email )
		);

		$amazon_emails = get_user_meta( $user->ID, 'amazon_emails', false );
		if ( ! empty( $amazon_emails ) ) {
			echo '<p>';
			foreach ( (array) $amazon_emails as $email ) {
				printf(
					'<a href="mailto:%s" class="button">%s</a><br/>',
					esc_attr( $email ),
					esc_html( $email )
				);
			}
			echo '</p>';
		}

		echo '<dl>';
		foreach ( explode( PHP_EOL, $post->post_content ) as $line ) {
			if ( 0 === strpos( $line, '--' ) ) {
				printf(
					'<h3>%s</h3>',
					esc_html(
						str_replace(
							[
								'Billing',
								'Shipping',
							],
							[
								$this->i18n( 'billing_address' ),
								$this->i18n( 'shipping_address' ),
							],
							trim( $line, '- ' )
						)
					)
				);
			} elseif ( false !== strpos( $line, ':' ) ) {
				list( $label, $value ) = explode( ': ', $line );
				$value                 = trim( $value );
				if ( ! empty( $value ) ) {
					if ( in_array( $label, [ 'Address 1', 'Address 2', 'Address 3' ], true ) ) {
						if ( 'Address 1' === $label ) {
							printf(
								'<dt>%s</dt>',
								esc_html( $this->i18n( sanitize_key( $label ) ) )
							);
						}
						printf(
							'<dd>%s</dd>',
							esc_html( $value )
						);
					} else {
						printf(
							'<dt>%s</dt><dd>%s</dd>',
							esc_html( $this->i18n( sanitize_key( $label ) ) ),
							( is_numeric( $value ) && 10 === strlen( $value ) )
								? esc_html(
									sprintf(
										'(%s) %s-%s',
										substr( $value, 0, 3 ),
										substr( $value, 3, 3 ),
										substr( $value, 6, 4 )
									)
								)
								: esc_html( $value )
						);
					}
				}
			}
		}
		echo '</dl>';
	}

	/**
	 * Add sortable columns to payment listing.
	 */
	public function sortable_columns( $columns ) {
		return array_merge(
			$columns,
			[
				self::POST_TYPE . '_date'           => 'date',
				self::POST_TYPE . '_customer'       => 'customer',
				self::POST_TYPE . '_amount'         => 'amount',
				self::POST_TYPE . '_invoice_number' => 'invoice_number',
				self::POST_TYPE . '_reference_id'   => 'reference_id',
				self::POST_TYPE . '_source'         => 'source',
			]
		);
	}

	/**
	 * Modify query when sorting by amount
	 */
	public function sort_invoices( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		switch ( $query->get( 'orderby' ) ) {
			case 'date':
				$query->set( 'orderby', 'date' );
				break;
			case 'invoice_number':
				$query->set( 'orderby', 'meta_value' );
				$query->set( 'meta_key', 'invoice_number' );
				break;
			case 'reference_id':
				$query->set( 'orderby', 'meta_value' );
				$query->set( 'meta_key', 'charge_permission_id' );
				break;
			case 'amount':
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'meta_key', 'amount' );
				break;
			case 'source':
				$query->set( 'orderby', 'parent' );
				break;
			case 'customer':
				$query->set( 'orderby', 'author' );
				break;
		}
	}

	/**
	 * Display source, amount, and customer as a columns on /wp-admin/edit.php.
	 */
	public function add_post_listing_columns( $columns ) {
		unset( $columns['author'], $columns['title'], $columns['date'] );

		/**
		 * This column will show if &details is added to the Payment listing URL.
		 * 
		 * @see /wp-admin/edit.php?post_type=amazon-payment&details
		 */
		$technical_details = ( array_key_exists( 'details', $_GET ) ) ? [ self::POST_TYPE . '_details' => $this->i18n( 'technical_details' ) ] : [];

		return array_merge(
			$columns,
			[
				self::POST_TYPE . '_amount'         => $this->i18n( 'amount' ),
				self::POST_TYPE . '_date'           => $this->i18n( 'date' ),
				self::POST_TYPE . '_customer'       => $this->i18n( 'customer' ),
				self::POST_TYPE . '_invoice_number' => $this->i18n( 'invoice_number' ),
				self::POST_TYPE . '_source'         => $this->i18n( 'source' ),
				self::POST_TYPE . '_reference_id'   => $this->i18n( 'reference_id' ),
			],
			$technical_details
		);
	}

	public function manage_posts_custom_column( $column_name, $post_id ) {
		$prefix = self::POST_TYPE . '_';
		if ( false === strpos( $column_name, $prefix ) ) {
			return;
		}
		$column_name = str_replace( $prefix, '', $column_name );

		switch ( $column_name ) {
			case 'date':
				$post = get_post( $post_id );
				printf(
					'<a href="%s">%s<br/>%s</a>',
					esc_url( get_edit_post_link( $post ) ),
					esc_html( get_the_date( get_option( 'date_format' ), $post ) ),
					esc_html( get_the_time( get_option( 'time_format' ), $post ) )
				);
				break;
			case 'source':
				$post_parent_id = get_post( $post_id )->post_parent;
				if ( ! empty( $post_parent_id ) ) {
					printf(
						'<a href="%s">%s</a>',
						esc_url( (string) get_edit_post_link( $post_parent_id ) ),
						esc_html( (string) get_the_title( $post_parent_id ) )
					);
				}
				break;
			case 'invoice_number':
				$invoice_number = get_post_meta( $post_id, 'invoice_number', true );
				if ( ! empty( $invoice_number ) && intval( $invoice_number ) !== intval( get_post( $post_id )->post_parent ) ) {
					printf(
						'%s',
						esc_html( $invoice_number )
					);
				}
				break;
			case 'reference_id':
				$charge_permission_id = get_post_meta( $post_id, 'charge_permission_id', true );
				if ( empty( $charge_permission_id ) ) {
					break;
				}
				printf(
					'<a href="https://sellercentral.amazon.com/external-payments/pmd/payment-details?orderReferenceId=%s" target="_blank" data-tooltip="%s">
						%s
					</a>',
					esc_attr( $charge_permission_id ),
					esc_attr(
						wp_strip_all_tags(
							sprintf(
								$this->i18n( 'if_link_not_found' ),
								( 'P' === substr( $charge_permission_id, 0, 1 ) )
									? $this->i18n( 'production_view' )
									: $this->i18n( 'sandbox_view' )
							)
						)
					),
					esc_html( $charge_permission_id )
				);
				break;
			case 'amount':
				$amount        = get_post_meta( $post_id, 'amount', true );
				$currency_code = get_post_meta( $post_id, 'currency_code', true );
				if ( ! empty( $amount )
					&& ! empty( $currency_code )
				) {
					printf(
						'<a href="%s">%s%s</a>',
						esc_url( get_edit_post_link( $post_id ) ),
						esc_html( $this->i18n( 'currency_symbols_plain' )[ $currency_code ] ),
						esc_html( str_replace( '.00', '', number_format( floatval( $amount ), 2 ) ) )
					);
				}else {
					echo 'Declined';
				}
				break;
			case 'customer':
				$user = get_user_by( 'ID', get_post( $post_id )->post_author );
				if ( empty( $user ) ) {
					return $this->i18n( 'none' );
				}
				printf(
					'<a href="%s">%s</a><br/><a href="mailto:%s">%s</a>',
					esc_url( get_edit_user_link( $user->ID ) ),
					( ! empty( $user->first_name ) && ! empty( $user->last_name ) )
						? esc_html( sprintf( '%s %s', $user->first_name, $user->last_name ) )
						: esc_html( $user->nickname ),
					esc_attr( $user->user_email ),
					esc_html( $user->user_email )
				);
				$amazon_emails = get_user_meta( $user->ID, 'amazon_emails', false );
				if ( ! empty( $amazon_emails ) ) {
					echo '<p>';
					foreach ( (array) $amazon_emails as $email ) {
						$email = trim( $email );
						if ( empty( $email ) ) { continue; }
						printf(
							'<a href="mailto:%s">%s</a><br/>',
							esc_attr( $email ),
							esc_html( $email )
						);
					}
					echo '</p>';
				}
				break;
			case 'details':
				$payment = (array) get_post( $post_id );
				$meta    = array_map(
					function( $value ) {
						if ( is_array( $value ) ) {
							return array_map( 'maybe_unserialize', $value );
						}
						return maybe_unserialize( $value );
					},
					get_post_meta( $post_id )
				);

				echo '<dl>';
				foreach(
					[ 'ID', 'post_author', 'post_date', 'post_status', 'post_parent' ]
					as $payment_key
				) {
					if ( array_key_exists( $payment_key, $payment ) && ! empty( $payment[ $payment_key ] ) ) {
						printf(
							'<dt>%s</dt><dd>%s</dd>',
							esc_html( $payment_key ),
							esc_html( $payment[ $payment_key ] )
						);
					}
				}
				foreach(
					[ 'payment_status', 'declined_reason', 'status_code', 'payment_status', 'payment_status_message', 'charge_id', 'charge_permission_id', 'checkout_session_id', 'checkout_session_object', 'checkout_session_response' ]
					as $meta_key
				) {
					if ( array_key_exists( $meta_key, $meta ) && ! empty( $meta[ $meta_key ] ) ) {
						if ( is_array( $meta[ $meta_key ] ) && 1 === count( $meta[ $meta_key ] ) && array_key_exists( 0, $meta[ $meta_key ] ) ) {
							$meta[ $meta_key ] = $meta[ $meta_key ][0];
						}
						printf(
							'<dt>%s</dt><dd><pre>%s</pre></dd>',
							esc_html( $meta_key ),
							esc_html( str_replace( 'Array' . PHP_EOL, '', print_r( $meta[ $meta_key ], true ) ) )
						);
					}
				}
				echo '</dl>';
				break;
		}
	}
}
