<?php
/**
 * Handle data for the current customers session.
 * Implements the ER_Session abstract class.
 *
 * @class    ER_Session_Handler
 * @package  easyReservations/Classes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Session handler class.
 */
class ER_Session_Handler extends ER_Session {

	/**
	 * Cookie name used for the session.
	 *
	 * @var string cookie name
	 */
	protected $_cookie;

	/**
	 * Stores session expiry.
	 *
	 * @var string session due to expire timestamp
	 */
	protected $_session_expiring;

	/**
	 * Stores session due to expire timestamp.
	 *
	 * @var string session expiration timestamp
	 */
	protected $_session_expiration;

	/**
	 * True when the cookie exists.
	 *
	 * @var bool Based on whether a cookie exists.
	 */
	protected $_has_cookie = false;

	/**
	 * Table name for session data.
	 *
	 * @var string Custom session table name
	 */
	protected $_table;

	/**
	 * Constructor for the session class.
	 */
	public function __construct() {
		$this->_cookie = apply_filters( 'easyreservations_cookie', 'wp_easyreservations_session_' . COOKIEHASH );
		$this->_table  = $GLOBALS['wpdb']->prefix . 'reservations_sessions';
	}

	/**
	 * Init hooks and session data.
	 */
	public function init() {
		$this->init_session_cookie();

		add_action( 'easyreservations_set_cart_cookies', array( $this, 'set_customer_session_cookie' ), 10 );
		add_action( 'shutdown', array( $this, 'save_data' ), 20 );
		add_action( 'wp_logout', array( $this, 'destroy_session' ) );

		if ( ! is_user_logged_in() ) {
			add_filter( 'nonce_user_logged_out', array( $this, 'maybe_update_nonce_user_logged_out' ), 10, 2 );
		}
	}

	/**
	 * Setup cookie and customer ID.
	 */
	public function init_session_cookie() {
		$cookie = $this->get_session_cookie();

		if ( $cookie ) {
			$this->_customer_id        = $cookie[0];
			$this->_session_expiration = $cookie[1];
			$this->_session_expiring   = $cookie[2];
			$this->_has_cookie         = true;
			$this->_data               = $this->get_session_data();

			// If the user logs in, update session.
			if ( is_user_logged_in() && strval( get_current_user_id() ) !== $this->_customer_id ) {
				$guest_session_id   = $this->_customer_id;
				$this->_customer_id = strval( get_current_user_id() );
				$this->_dirty       = true;
				$this->save_data( $guest_session_id );
				$this->set_customer_session_cookie( true );
			}

			// Update session if its close to expiring.
			if ( time() > $this->_session_expiring ) {
				$this->set_session_expiration();
				$this->update_session_timestamp( $this->_customer_id, $this->_session_expiration );
			}
		} else {
			$this->set_session_expiration();
			$this->_customer_id = $this->generate_customer_id();
			$this->_data        = $this->get_session_data();
		}
	}

	/**
	 * Sets the session cookie on-demand (usually after adding an item to the cart).
	 * Since the cookie name is prepended with wp, cache systems like batcache will not cache pages when set.
	 * Warning: Cookies will only be set if this is called before the headers are sent.
	 *
	 * @param bool $set Should the session cookie be set.
	 */
	public function set_customer_session_cookie( $set ) {
		if ( $set ) {
			$to_hash           = $this->_customer_id . '|' . $this->_session_expiration;
			$cookie_hash       = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
			$cookie_value      = $this->_customer_id . '||' . $this->_session_expiration . '||' . $this->_session_expiring . '||' . $cookie_hash;
			$this->_has_cookie = true;

			if ( ! isset( $_COOKIE[ $this->_cookie ] ) || $_COOKIE[ $this->_cookie ] !== $cookie_value ) {
				er_set_cookie( $this->_cookie, $cookie_value, $this->_session_expiration, $this->use_secure_cookie(), true );
			}
		}
	}

	/**
	 * Should the session cookie be secure?
	 *
	 * @return bool
	 */
	protected function use_secure_cookie() {
		return apply_filters( 'easyreservations_session_use_secure_cookie', er_site_is_https() && is_ssl() );
	}

	/**
	 * Return true if the current user has an active session, i.e. a cookie to retrieve values.
	 *
	 * @return bool
	 */
	public function has_session() {
		return isset( $_COOKIE[ $this->_cookie ] ) || $this->_has_cookie || is_user_logged_in(); // @codingStandardsIgnoreLine.
	}

	/**
	 * Set session expiration.
	 */
	public function set_session_expiration() {
		$this->_session_expiring   = time() + intval( apply_filters( 'easyreservations_session_expiring', 60 * 60 * 47 ) ); // 47 Hours.
		$this->_session_expiration = time() + intval( apply_filters( 'easyreservations_session_expiration', 60 * 60 * 48 ) ); // 48 Hours.
	}

	/**
	 * Generate a unique customer ID for guests, or return user ID if logged in.
	 *
	 * Uses Portable PHP password hashing framework to generate a unique cryptographically strong ID.
	 *
	 * @return string
	 */
	public function generate_customer_id() {
		$customer_id = '';

		if ( is_user_logged_in() ) {
			$customer_id = strval( get_current_user_id() );
		}

		if ( empty( $customer_id ) ) {
			require_once ABSPATH . 'wp-includes/class-phpass.php';
			$hasher      = new PasswordHash( 8, false );
			$customer_id = md5( $hasher->get_random_bytes( 32 ) );
		}

		return $customer_id;
	}

	/**
	 * Get the session cookie, if set. Otherwise return false.
	 *
	 * Session cookies without a customer ID are invalid.
	 *
	 * @return bool|array
	 */
	public function get_session_cookie() {
		$cookie_value = isset( $_COOKIE[ $this->_cookie ] ) ? wp_unslash( $_COOKIE[ $this->_cookie ] ) : false; // @codingStandardsIgnoreLine.

		if ( empty( $cookie_value ) || ! is_string( $cookie_value ) ) {
			return false;
		}

		list( $customer_id, $session_expiration, $session_expiring, $cookie_hash ) = explode( '||', $cookie_value );

		if ( empty( $customer_id ) ) {
			return false;
		}

		// Validate hash.
		$to_hash = $customer_id . '|' . $session_expiration;
		$hash    = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );

		if ( empty( $cookie_hash ) || ! hash_equals( $hash, $cookie_hash ) ) {
			return false;
		}

		return array( $customer_id, $session_expiration, $session_expiring, $cookie_hash );
	}

	/**
	 * Get session data.
	 *
	 * @return array
	 */
	public function get_session_data() {
		return $this->has_session() ? (array) $this->get_session( $this->_customer_id, array() ) : array();
	}

	/**
	 * Gets a cache prefix. This is used in session names so the entire cache can be invalidated with 1 function call.
	 *
	 * @return string
	 */
	private function get_cache_prefix() {
		// Get cache key - uses cache key er_orders_cache_prefix to invalidate when needed.
		$prefix = wp_cache_get( 'er_' . RESERVATIONS_SESSION_CACHE_GROUP . '_cache_prefix', RESERVATIONS_SESSION_CACHE_GROUP );

		if ( false === $prefix ) {
			$prefix = 1;
			wp_cache_set( 'er_' . RESERVATIONS_SESSION_CACHE_GROUP . '_cache_prefix', $prefix, RESERVATIONS_SESSION_CACHE_GROUP );
		}

		return 'er_cache_' . $prefix . '_';
	}

	/**
	 * Save data.
	 */
	public function save_data() {
		// Dirty if something changed - prevents saving nothing new.
		if ( $this->_dirty && $this->has_session() ) {
			global $wpdb;

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $this->_table (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d)
 					ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)",
					$this->_customer_id,
					maybe_serialize( $this->_data ),
					$this->_session_expiration
				)
			);

			$test         = wp_cache_set( $this->get_cache_prefix() . $this->_customer_id, $this->_data, RESERVATIONS_SESSION_CACHE_GROUP, $this->_session_expiration - time() );
			$this->_dirty = false;
		}
	}

	/**
	 * Destroy all session data.
	 */
	public function destroy_session() {
		$this->delete_session( $this->_customer_id );
		$this->forget_session();
	}

	/**
	 * Forget all session data without destroying it.
	 */
	public function forget_session() {
		er_set_cookie( $this->_cookie, '', time() - YEAR_IN_SECONDS, $this->use_secure_cookie(), true );

		er_empty_cart();

		$this->_data        = array();
		$this->_dirty       = false;
		$this->_customer_id = $this->generate_customer_id();
	}

	/**
	 * When a user is logged out, ensure they have a unique nonce to manage cart and more using the customer/session ID.
	 * This filter runs everything `wp_verify_nonce()` and `wp_create_nonce()` gets called.
	 *
	 * @param int    $uid User ID.
	 * @param string $action The nonce action.
	 *
	 * @return int|string
	 */
	public function maybe_update_nonce_user_logged_out( $uid, $action ) {
		if ( er_string_starts_with( $action, 'easyreservations' ) ) {
			return $this->has_session() && $this->_customer_id ? $this->_customer_id : $uid;
		}

		return $uid;
	}

	/**
	 * Cleanup session data from the database and clear caches.
	 */
	public function cleanup_sessions() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $this->_table WHERE session_expiry < %d", time() ) ); // @codingStandardsIgnoreLine.

		er_invalidate_cache_group( RESERVATIONS_SESSION_CACHE_GROUP );
	}

	/**
	 * Returns the session.
	 *
	 * @param string $customer_id Custo ID.
	 * @param mixed  $default Default session value.
	 *
	 * @return string|array
	 */
	public function get_session( $customer_id, $default = false ) {
		global $wpdb;

		if ( defined( 'WP_SETUP_CONFIG' ) ) {
			return false;
		}

		// Try to get it from the cache, it will return false if not present or if object cache not in use.
		$value = wp_cache_get( $this->get_cache_prefix() . $customer_id, RESERVATIONS_SESSION_CACHE_GROUP );

		if ( false === $value ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM $this->_table WHERE session_key = %s", $customer_id ) ); // @codingStandardsIgnoreLine.

			if ( is_null( $value ) ) {
				$value = $default;
			}

			wp_cache_add( $this->get_cache_prefix() . $customer_id, $value, RESERVATIONS_SESSION_CACHE_GROUP, $this->_session_expiration - time() );
		}

		return maybe_unserialize( $value );
	}

	/**
	 * Delete the session from the cache and database.
	 *
	 * @param int $customer_id Customer ID.
	 */
	public function delete_session( $customer_id ) {
		global $wpdb;

		wp_cache_delete( $this->get_cache_prefix() . $customer_id, RESERVATIONS_SESSION_CACHE_GROUP );

		$wpdb->delete(
			$this->_table,
			array(
				'session_key' => $customer_id,
			)
		);
	}

	/**
	 * Update the session expiry timestamp.
	 *
	 * @param string $customer_id Customer ID.
	 * @param int    $timestamp Timestamp to expire the cookie.
	 */
	public function update_session_timestamp( $customer_id, $timestamp ) {
		global $wpdb;

		$wpdb->update(
			$this->_table,
			array(
				'session_expiry' => $timestamp,
			),
			array(
				'session_key' => $customer_id,
			),
			array(
				'%d',
			)
		);
	}
}
