<?php
/**
 * Class ER_Customer_Data_Store file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ER Customer Data Store.
 */
class ER_Customer_Data_Store extends ER_Data_Store_WP implements ER_Customer_Data_Store_Interface,
	ER_Object_Data_Store_Interface {

	/**
	 * Data stored in meta keys, but not considered "meta".
	 *
	 * @var array
	 */
	protected $internal_meta_keys = array(
		'locale',
		'address_postcode',
		'address_city',
		'address_address_1',
		'address_address_2',
		'address_state',
		'address_country',
		'paying_customer',
		'er_last_update',
		'er_last_active',
		'first_name',
		'last_name',
		'display_name',
		'show_admin_bar_front',
		'use_ssl',
		'admin_color',
		'rich_editing',
		'comment_shortcuts',
		'dismissed_wp_pointers',
		'show_welcome_panel',
		'session_tokens',
		'nickname',
		'description',
		'address_first_name',
		'address_last_name',
		'address_company',
		'address_phone',
		'address_email',
		'wptests_capabilities',
		'wptests_user_level',
		'syntax_highlighting',
		'_order_count',
		'_money_spent',
		'_last_order',
	);

	/**
	 * Internal meta type used to store user data.
	 *
	 * @var string
	 */
	protected $meta_type = 'user';

	/**
	 * Callback to remove unwanted meta data.
	 *
	 * @param object $meta Meta object.
	 *
	 * @return bool
	 */
	protected function exclude_internal_meta_keys( $meta ) {
		global $wpdb;

		$table_prefix = $wpdb->prefix ? $wpdb->prefix : 'wp_';

		return ! in_array( $meta->meta_key, $this->internal_meta_keys, true )
		       && 0 !== strpos( $meta->meta_key, '_easyreservations_persistent_cart' )
		       && 0 !== strpos( $meta->meta_key, 'closedpostboxes_' )
		       && 0 !== strpos( $meta->meta_key, 'metaboxhidden_' )
		       && 0 !== strpos( $meta->meta_key, 'manageedit-' )
		       && ! strstr( $meta->meta_key, $table_prefix )
		       && 0 !== stripos( $meta->meta_key, 'wp_' );
	}

	/**
	 * Method to create a new customer in the database.
	 *
	 * @param ER_Customer $customer Customer object.
	 *
	 * @throws ER_Data_Exception If unable to create new customer.
	 */
	public function create( &$customer ) {
		$id = er_create_new_customer( $customer->get_email(), $customer->get_username(), $customer->get_password() );

		if ( is_wp_error( $id ) ) {
			throw new ER_Data_Exception( $id->get_error_code(), $id->get_error_message() );
		}

		$customer->set_id( $id );
		$this->update_user_meta( $customer );

		// Prevent wp_update_user calls in the same request and customer trigger the 'Notice of Password Changed' email.
		$customer->set_password( '' );

		wp_update_user(
			apply_filters(
				'easyreservations_update_customer_args',
				array(
					'ID'           => $customer->get_id(),
					'role'         => $customer->get_role(),
					'display_name' => $customer->get_display_name(),
				),
				$customer
			)
		);
		$wp_user = new WP_User( $customer->get_id() );
		$customer->set_date_created( $wp_user->user_registered );
		$customer->set_date_modified( get_user_meta( $customer->get_id(), 'er_last_update', true ) );
		$customer->save_meta_data();
		$customer->apply_changes();
		do_action( 'easyreservations_new_customer', $customer->get_id(), $customer );
	}

	/**
	 * Method to read a customer object.
	 *
	 * @param ER_Customer $customer Customer object.
	 *
	 * @throws Exception If invalid customer.
	 */
	public function read( &$customer ) {
		$user_object = $customer->get_id() ? get_user_by( 'id', $customer->get_id() ) : false;

		// User object is required.
		if ( ! $user_object || empty( $user_object->ID ) ) {
			throw new Exception( __( 'Invalid customer.', 'easyReservations' ) );
		}

		$customer_id = $customer->get_id();

		// Load meta but exclude deprecated props and parent keys.
		$user_meta = array_diff_key(
			array_change_key_case( array_map( 'er_flatten_meta_callback', get_user_meta( $customer_id ) ) ),
			array_flip( array( 'country', 'state', 'postcode', 'city', 'address', 'address_2', 'default', 'location' ) ),
			array_change_key_case( (array) $user_object->data )
		);

		$customer->set_props( $user_meta );
		$customer->set_props(
			array(
				'is_paying_customer' => get_user_meta( $customer_id, 'paying_customer', true ),
				'email'              => $user_object->user_email,
				'username'           => $user_object->user_login,
				'display_name'       => $user_object->display_name,
				'date_created'       => $user_object->user_registered, // Mysql string in local format.
				'date_modified'      => get_user_meta( $customer_id, 'er_last_update', true ),
				'role'               => ! empty( $user_object->roles[0] ) ? $user_object->roles[0] : 'easy_customer',
			)
		);
		$customer->read_meta_data();
		$customer->set_object_read( true );
		do_action( 'easyreservations_customer_loaded', $customer );
	}

	/**
	 * Updates a customer in the database.
	 *
	 * @param ER_Customer $customer Customer object.
	 */
	public function update( &$customer ) {
		wp_update_user(
			apply_filters(
				'easyreservations_update_customer_args',
				array(
					'ID'           => $customer->get_id(),
					'user_email'   => $customer->get_email(),
					'display_name' => $customer->get_display_name(),
				),
				$customer
			)
		);

		// Only update password if a new one was set with set_password.
		if ( $customer->get_password() ) {
			wp_update_user(
				array(
					'ID'        => $customer->get_id(),
					'user_pass' => $customer->get_password(),
				)
			);
			$customer->set_password( '' );
		}

		$this->update_user_meta( $customer );
		$customer->set_date_modified( get_user_meta( $customer->get_id(), 'er_last_update', true ) );
		$customer->save_meta_data();
		$customer->apply_changes();
		do_action( 'easyreservations_update_customer', $customer->get_id(), $customer );
	}

	/**
	 * Deletes a customer from the database.
	 *
	 * @param ER_Customer $customer Customer object.
	 * @param array       $args Array of args to pass to the delete method.
	 */
	public function delete( &$customer, $args = array() ) {
		if ( ! $customer->get_id() ) {
			return;
		}

		$args = wp_parse_args(
			$args,
			array(
				'reassign' => 0,
			)
		);

		$id = $customer->get_id();
		wp_delete_user( $id, $args['reassign'] );

		do_action( 'easyreservations_delete_customer', $id );
	}

	/**
	 * Helper method that updates all the meta for a customer. Used for update & create.
	 *
	 * @param ER_Customer $customer Customer object.
	 */
	private function update_user_meta( $customer ) {
		$updated_props = array();
		$changed_props = $customer->get_changes();

		$meta_key_to_props = array(
			'paying_customer' => 'is_paying_customer',
			'first_name'      => 'first_name',
			'last_name'       => 'last_name',
		);

		foreach ( $meta_key_to_props as $meta_key => $prop ) {
			if ( ! array_key_exists( $prop, $changed_props ) ) {
				continue;
			}

			if ( update_user_meta( $customer->get_id(), $meta_key, $customer->{"get_$prop"}( 'edit' ) ) ) {
				$updated_props[] = $prop;
			}
		}

		$address_props = array(
			'address_first_name' => 'address_first_name',
			'address_last_name'  => 'address_last_name',
			'address_company'    => 'address_company',
			'address_address_1'  => 'address_address_1',
			'address_address_2'  => 'address_address_2',
			'address_city'       => 'address_city',
			'address_state'      => 'address_state',
			'address_postcode'   => 'address_postcode',
			'address_country'    => 'address_country',
			'address_email'      => 'address_email',
			'address_phone'      => 'address_phone',
		);

		foreach ( $address_props as $meta_key => $prop ) {
			$prop_key = substr( $prop, 8 );

			if ( ! isset( $changed_props['address'] ) || ! array_key_exists( $prop_key, $changed_props['address'] ) ) {
				continue;
			}

			if ( update_user_meta( $customer->get_id(), $meta_key, $customer->{"get_$prop"}( 'edit' ) ) ) {
				$updated_props[] = $prop;
			}
		}

		do_action( 'easyreservations_customer_object_updated_props', $customer, $updated_props );
	}

	/**
	 * Gets the customers last order.
	 *
	 * @param ER_Customer $customer Customer object.
	 *
	 * @return ER_Order|false
	 */
	public function get_last_order( &$customer ) {
		$last_order = apply_filters(
			'easyreservations_customer_get_last_order',
			get_user_meta( $customer->get_id(), '_last_order', true ),
			$customer
		);

		if ( '' === $last_order ) {
			global $wpdb;

			$last_order = $wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
				"SELECT posts.ID
			FROM $wpdb->posts AS posts
			LEFT JOIN {$wpdb->postmeta} AS meta on posts.ID = meta.post_id
			WHERE meta.meta_key = '_customer_user'
			AND   meta.meta_value = '" . esc_sql( $customer->get_id() ) . "'
			AND   posts.post_type = 'easy_order'
			AND   posts.post_status IN ( '" . implode( "','", array_map( 'esc_sql', array_keys( er_get_order_statuses() ) ) ) . "' )
			ORDER BY posts.ID DESC"
			// phpcs:enable
			);
		}

		if ( ! $last_order ) {
			return false;
		}

		return er_get_order( absint( $last_order ) );
	}

	/**
	 * Return the number of orders this customer has.
	 *
	 * @param ER_Customer $customer Customer object.
	 *
	 * @return integer
	 */
	public function get_order_count( &$customer ) {
		$count = apply_filters(
			'easyreservations_customer_get_order_count',
			get_user_meta( $customer->get_id(), '_order_count', true ),
			$customer
		);

		if ( '' === $count ) {
			global $wpdb;

			$count = $wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
				"SELECT COUNT(*)
				FROM $wpdb->posts as posts
				LEFT JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
				WHERE   meta.meta_key = '_customer_user'
				AND     posts.post_type = 'easy_order'
				AND     posts.post_status IN ( '" . implode( "','", array_map( 'esc_sql', array_keys( ER_Order_Status::get_statuses() ) ) ) . "' )
				AND     meta_value = '" . esc_sql( $customer->get_id() ) . "'"
			// phpcs:enable
			);
			update_user_meta( $customer->get_id(), '_order_count', $count );
		}

		return absint( $count );
	}

	/**
	 * Return how much money this customer has spent.
	 *
	 * @param ER_Customer $customer Customer object.
	 *
	 * @return float
	 */
	public function get_total_spent( &$customer ) {
		$spent = apply_filters(
			'easyreservations_customer_get_total_spent',
			get_user_meta( $customer->get_id(), '_money_spent', true ),
			$customer
		);

		if ( '' === $spent ) {
			global $wpdb;

			$statuses = array_map( 'esc_sql', er_get_is_paid_statuses() );
			$spent    = $wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
				apply_filters(
					'easyreservations_customer_get_total_spent_query',
					"SELECT SUM(meta2.meta_value)
					FROM $wpdb->posts as posts
					LEFT JOIN {$wpdb->postmeta} AS meta ON posts.ID = meta.post_id
					LEFT JOIN {$wpdb->postmeta} AS meta2 ON posts.ID = meta2.post_id
					WHERE   meta.meta_key       = '_customer_user'
					AND     meta.meta_value     = '" . esc_sql( $customer->get_id() ) . "'
					AND     posts.post_type     = 'easy_order'
					AND     posts.post_status   IN ( '" . implode( "','", $statuses ) . "' )
					AND     meta2.meta_key      = '_order_total'",
					$customer
				)
			// phpcs:enable
			);

			if ( ! $spent ) {
				$spent = 0;
			}
			update_user_meta( $customer->get_id(), '_money_spent', $spent );
		}

		return er_format_decimal( $spent, 2 );
	}

	/**
	 * Search customers and return customer IDs.
	 *
	 * @param string     $term Search term.
	 * @param int|string $limit Limit search results.
	 *
	 * @return array
	 */
	public function search_customers( $term, $limit = '' ) {
		$results = apply_filters( 'easyreservations_customer_pre_search_customers', false, $term, $limit );
		if ( is_array( $results ) ) {
			return $results;
		}

		$query = new WP_User_Query(
			apply_filters(
				'easyreservations_customer_search_customers',
				array(
					'search'         => '*' . esc_attr( $term ) . '*',
					'search_columns' => array(
						'user_login',
						'user_url',
						'user_email',
						'user_nicename',
						'display_name'
					),
					'fields'         => 'ID',
					'number'         => $limit,
				),
				$term,
				$limit,
				'main_query'
			)
		);

		$query2 = new WP_User_Query(
			apply_filters(
				'easyreservations_customer_search_customers',
				array(
					'fields'     => 'ID',
					'number'     => $limit,
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'     => 'first_name',
							'value'   => $term,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'last_name',
							'value'   => $term,
							'compare' => 'LIKE',
						),
					),
				),
				$term,
				$limit,
				'meta_query'
			)
		);

		$results = wp_parse_id_list( array_merge( (array) $query->get_results(), (array) $query2->get_results() ) );

		if ( $limit && count( $results ) > $limit ) {
			$results = array_slice( $results, 0, $limit );
		}

		return $results;
	}

	/**
	 * Get all user ids who have `billing_email` set to any of the email passed in array.
	 *
	 * @param array $emails List of emails to check against.
	 *
	 * @return array
	 */
	public function get_user_ids_for_email( $emails ) {
		$emails      = array_unique( array_map( 'strtolower', array_map( 'sanitize_email', $emails ) ) );
		$users_query = new WP_User_Query(
			array(
				'fields'     => 'ID',
				'meta_query' => array(
					array(
						'key'     => 'address_email',
						'value'   => $emails,
						'compare' => 'IN',
					),
				),
			)
		);

		return array_unique( $users_query->get_results() );
	}
}
