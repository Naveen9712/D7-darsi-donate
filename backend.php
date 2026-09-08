<?php
/**
 * =============================================================================
 * D7 News Telugu — Ganesh idol registrations (ALL-IN-ONE)
 * =============================================================================
 *
 * This single snippet replaces snippets 1-4. Do not run both sets at once.
 *
 * Code Snippets settings:
 *   Title   : D7 Ganesh Registrations — backend
 *   Scope   : Run snippet everywhere      <-- required, the REST API needs it
 *   Priority: 10
 *
 * Contents:
 *   SECTION 0 — Configuration (edit this part)
 *   SECTION 1 — Database table + helpers
 *   SECTION 2 — REST API
 *   SECTION 3 — Admin dashboard
 *   SECTION 4 — CORS (only active if you list front-end origins in SECTION 0)
 *
 * NOTE: do NOT paste the opening <?php tag into Code Snippets.
 * =============================================================================
 */


/* =============================================================================
 * SECTION 0 — CONFIGURATION
 * ========================================================================== */

if ( ! function_exists( 'd7_ganesh_config' ) ) {
	function d7_ganesh_config() {
		return array(

			// Token prefix. Result looks like D7-GANESH-0001.
			'token_prefix' => 'D7-GANESH-',

			// Max submissions allowed per IP address per hour.
			'rate_limit' => 5,

			/**
			 * Front-end origins allowed to call the API from a browser.
			 * No trailing slash. Never use '*'.
			 *
			 * After you deploy the form (Render etc.), add that exact origin too.
			 */
			'allowed_origins' => array(
				'https://pncreators.com',
				'https://www.pncreators.com',
				'https://d7darsidonate.onrender.com',
				'http://localhost:5500',
				'http://127.0.0.1:5500',
				'http://localhost:8080',
				'http://127.0.0.1:8080',
			),

			/**
			 * Return the existing token when someone re-submits a phone number
			 * that is already registered.
			 *
			 * true  = helpful at the counter (lost slip? re-enter your number)
			 * false = a guessed phone number cannot reveal someone's token
			 */
			'reveal_token_on_duplicate' => true,

			/**
			 * Optional key for the standalone /admin/ page on the static site.
			 * Generate a long random value and keep it out of public frontend code.
			 * Leave empty to use the normal WordPress administrator session only.
			 */
			'admin_api_key' => 'Upsc@365',
		);
	}
}

if ( ! defined( 'D7_GANESH_DB_VERSION' ) ) {
	define( 'D7_GANESH_DB_VERSION', '1.0.0' );
}


/* =============================================================================
 * SECTION 1 — DATABASE TABLE + HELPERS
 *
 * A dedicated table is used instead of a custom post type: this data is queried
 * by phone and token, needs a real UNIQUE constraint on the phone column, and
 * never needs the post editor.
 * ========================================================================== */

/**
 * Full table name including the site's table prefix.
 */
if ( ! function_exists( 'd7_ganesh_table' ) ) {
	function d7_ganesh_table() {
		global $wpdb;
		return $wpdb->prefix . 'd7_ganesh_registrations';
	}
}

/**
 * Create or upgrade the registrations table.
 *
 * The UNIQUE KEY on `phone` is the real duplicate guard — the PHP check in the
 * REST handler only exists to return a friendly message. Two simultaneous
 * requests with the same number cannot both be written.
 */
if ( ! function_exists( 'd7_ganesh_install_table' ) ) {
	function d7_ganesh_install_table() {
		global $wpdb;

		$table   = d7_ganesh_table();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY,
		// one field per line, lowercase KEY names.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(32) NOT NULL DEFAULT '',
			name varchar(120) NOT NULL DEFAULT '',
			phone varchar(15) NOT NULL DEFAULT '',
			address text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			ip_address varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY phone (phone),
			KEY token (token),
			KEY status (status),
			KEY created_at (created_at)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'd7_ganesh_db_version', D7_GANESH_DB_VERSION );
	}
}

/**
 * Create the table on first request (front end, REST, or admin), then skip.
 */
add_action( 'init', function () {
	if ( get_option( 'd7_ganesh_db_version' ) !== D7_GANESH_DB_VERSION ) {
		d7_ganesh_install_table();
	}
} );

/**
 * Build a token from the row's auto-increment id.
 *
 * Deriving the token from the primary key makes it sequential and unique by
 * construction — no counter option, no race condition, no reuse after deletion.
 *
 * @param int $id Row id.
 * @return string e.g. D7-GANESH-0001
 */
if ( ! function_exists( 'd7_ganesh_build_token' ) ) {
	function d7_ganesh_build_token( $id ) {
		$config = d7_ganesh_config();
		return $config['token_prefix'] . str_pad( (int) $id, 4, '0', STR_PAD_LEFT );
	}
}

/**
 * Fetch a single registration row.
 *
 * @param int $id
 * @return array|null
 */
if ( ! function_exists( 'd7_ganesh_get_registration' ) ) {
	function d7_ganesh_get_registration( $id ) {
		global $wpdb;
		$table = d7_ganesh_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);
	}
}

/**
 * Find a registration by phone number.
 *
 * @param string $phone 10-digit number.
 * @return array|null
 */
if ( ! function_exists( 'd7_ganesh_find_by_phone' ) ) {
	function d7_ganesh_find_by_phone( $phone ) {
		global $wpdb;
		$table = d7_ganesh_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE phone = %s", $phone ),
			ARRAY_A
		);
	}
}

/**
 * Shared query used by both the admin screen and the REST list endpoint.
 *
 * @param array $args search, status, per_page, page.
 * @return array { rows: array, total: int }
 */
if ( ! function_exists( 'd7_ganesh_query_registrations' ) ) {
	function d7_ganesh_query_registrations( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array(
			'search'   => '',
			'status'   => '',
			'per_page' => 20,
			'page'     => 1,
		) );

		$table    = d7_ganesh_table();
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where = 'WHERE 1=1';

		// One search box covers name, phone and token.
		if ( '' !== trim( $args['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where .= $wpdb->prepare(
				' AND ( name LIKE %s OR phone LIKE %s OR token LIKE %s )',
				$like, $like, $like
			);
		}

		if ( in_array( $args['status'], array( 'pending', 'collected' ), true ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}
}

/**
 * Counts for the dashboard cards.
 *
 * @return array
 */
if ( ! function_exists( 'd7_ganesh_get_counts' ) ) {
	function d7_ganesh_get_counts() {
		global $wpdb;
		$table = d7_ganesh_table();
		$today = current_time( 'Y-m-d' );

		return array(
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ),
			'collected' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'collected'" ),
			'today'     => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = %s", $today )
			),
		);
	}
}


/* =============================================================================
 * SECTION 2 — REST API
 *
 * Routes (namespace d7-ganesh/v1):
 *   POST /register              public  — create a registration, returns token
 *   GET  /stats                 public  — total count only
 *   GET  /registrations         admin   — paginated list + search
 *   GET  /registrations/<id>    admin   — one record
 * ========================================================================== */

/* ------------------------------------------------------------- validation */

/**
 * Validate a name: letters in any script (so Telugu works), marks, spaces,
 * apostrophes, hyphens and dots. Digits are rejected.
 */
if ( ! function_exists( 'd7_ganesh_valid_name' ) ) {
	function d7_ganesh_valid_name( $value ) {
		$value = trim( (string) $value );
		if ( mb_strlen( $value ) < 3 || mb_strlen( $value ) > 60 ) {
			return false;
		}
		// Same rule as the form: any script (Telugu included), no digits.
		return ! preg_match( '/\d/u', $value );
	}
}

/** Indian mobile: 10 digits starting 6-9. */
if ( ! function_exists( 'd7_ganesh_valid_phone' ) ) {
	function d7_ganesh_valid_phone( $value ) {
		return (bool) preg_match( '/^[6-9]\d{9}$/', (string) $value );
	}
}

if ( ! function_exists( 'd7_ganesh_valid_address' ) ) {
	function d7_ganesh_valid_address( $value ) {
		$len = mb_strlen( trim( (string) $value ) );
		return $len >= 10 && $len <= 250;
	}
}

/**
 * Client IP, used only for rate limiting and abuse review.
 * Trust proxy headers only if you are actually behind Cloudflare or an ALB.
 */
if ( ! function_exists( 'd7_ganesh_client_ip' ) ) {
	function d7_ganesh_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		// Uncomment if Cloudflare sits in front of the site:
		// if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) { $ip = wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ); }

		$ip = filter_var( $ip, FILTER_VALIDATE_IP );
		return $ip ? $ip : '0.0.0.0';
	}
}

/**
 * Per-IP rate limit backed by transients.
 *
 * @return bool true when the request is allowed.
 */
if ( ! function_exists( 'd7_ganesh_rate_limit_ok' ) ) {
	function d7_ganesh_rate_limit_ok( $ip ) {
		$config = d7_ganesh_config();
		$max    = (int) $config['rate_limit'];

		$key   = 'd7g_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}

/**
 * Shape a DB row for output. Never returns the IP address publicly.
 */
if ( ! function_exists( 'd7_ganesh_format_row' ) ) {
	function d7_ganesh_format_row( $row, $include_private = false ) {
		$out = array(
			'id'              => (int) $row['id'],
			'token'           => $row['token'],
			'name'            => $row['name'],
			'phone'           => $row['phone'],
			'address'         => $row['address'],
			'status'          => $row['status'],
			'created_at'      => $row['created_at'],
			'created_display' => mysql2date( 'd-m-Y, h:i A', $row['created_at'] ),
		);

		if ( $include_private ) {
			$out['ip_address'] = $row['ip_address'];
		}

		return $out;
	}
}

/* ------------------------------------------------------------- permission */

/**
 * Admin-only endpoints. Logged out gets 401, logged-in non-admin gets 403.
 */
	if ( ! function_exists( 'd7_ganesh_admin_permission' ) ) {
		function d7_ganesh_admin_permission( $request = null ) {
			$config = d7_ganesh_config();
			$provided_key = '';

			if ( ! empty( $_SERVER['HTTP_X_D7_ADMIN_KEY'] ) ) {
				$provided_key = (string) wp_unslash( $_SERVER['HTTP_X_D7_ADMIN_KEY'] );
			} elseif ( $request instanceof WP_REST_Request && $request->get_param( 'admin_key' ) ) {
				$provided_key = (string) $request->get_param( 'admin_key' );
			} elseif ( ! empty( $_GET['admin_key'] ) ) {
				$provided_key = (string) wp_unslash( $_GET['admin_key'] );
			}

			if ( ! empty( $config['admin_api_key'] ) && '' !== $provided_key && hash_equals( (string) $config['admin_api_key'], $provided_key ) ) {
				return true;
			}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				'You must be logged in.',
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				'You are not allowed to view registrations.',
				array( 'status' => 403 )
			);
		}
		return true;
	}
}

/* ----------------------------------------------------------------- routes */

add_action( 'rest_api_init', function () {

	// ---- POST /register (public) -----------------------------------------
	register_rest_route( 'd7-ganesh/v1', '/register', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'd7_ganesh_rest_register',
		'permission_callback' => '__return_true', // public form; abuse controls below
		'args'                => array(
			'name' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'phone' => array(
				'required'          => true,
				'sanitize_callback' => function ( $v ) {
					return preg_replace( '/\D/', '', (string) $v );
				},
			),
			'address' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			// Honeypot — real users never fill this in.
			'website' => array(
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );

	// ---- GET /stats (public, count only) ---------------------------------
	register_rest_route( 'd7-ganesh/v1', '/stats', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function () {
			global $wpdb;
			$table = d7_ganesh_table();
			return rest_ensure_response( array(
				'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			) );
		},
		'permission_callback' => '__return_true',
	) );

	// ---- GET /admin-stats (admin, dashboard counts) ----------------------
	register_rest_route( 'd7-ganesh/v1', '/admin-stats', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function () {
			return rest_ensure_response( d7_ganesh_get_counts() );
		},
		'permission_callback' => 'd7_ganesh_admin_permission',
	) );

	// ---- GET /registrations (admin) --------------------------------------
	register_rest_route( 'd7-ganesh/v1', '/registrations', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'd7_ganesh_rest_list',
		'permission_callback' => 'd7_ganesh_admin_permission',
		'args'                => array(
			'search'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'status'   => array( 'sanitize_callback' => 'sanitize_key' ),
			'page'     => array( 'sanitize_callback' => 'absint', 'default' => 1 ),
			'per_page' => array( 'sanitize_callback' => 'absint', 'default' => 20 ),
		),
	) );

	// ---- GET /registrations/<id> (admin) ---------------------------------
	register_rest_route( 'd7-ganesh/v1', '/registrations/(?P<id>\d+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'd7_ganesh_rest_single',
		'permission_callback' => 'd7_ganesh_admin_permission',
		'args'                => array(
			'id' => array( 'sanitize_callback' => 'absint' ),
		),
	) );
} );

/* --------------------------------------------------------------- handlers */

/**
 * POST /register
 *
 * Validation order: honeypot, rate limit, field rules, duplicate phone,
 * then insert. The token is written after insert, from the row id.
 */
if ( ! function_exists( 'd7_ganesh_rest_register' ) ) {
	function d7_ganesh_rest_register( WP_REST_Request $request ) {
		global $wpdb;

		$config = d7_ganesh_config();

		// 1. Honeypot. Return a generic error, not "you are a bot".
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new WP_Error(
				'd7_rejected',
				'నమోదు పూర్తి కాలేదు. దయచేసి మళ్లీ ప్రయత్నించండి.',
				array( 'status' => 400 )
			);
		}

		// 2. Rate limit per IP.
		$ip = d7_ganesh_client_ip();
		if ( ! d7_ganesh_rate_limit_ok( $ip ) ) {
			return new WP_Error(
				'd7_rate_limited',
				'చాలా ఎక్కువ ప్రయత్నాలు జరిగాయి. కొంత సేపటి తర్వాత ప్రయత్నించండి.',
				array( 'status' => 429 )
			);
		}

		$name    = trim( (string) $request->get_param( 'name' ) );
		$phone   = (string) $request->get_param( 'phone' );
		$address = trim( (string) $request->get_param( 'address' ) );

		// 3. Field rules — the same rules the browser enforces, re-checked here.
		if ( ! d7_ganesh_valid_name( $name ) ) {
			return new WP_Error(
				'd7_invalid_name',
				'సరైన పేరు రాయండి (కనీసం 3 అక్షరాలు, అంకెలు వద్దు).',
				array( 'status' => 400, 'field' => 'name' )
			);
		}
		if ( ! d7_ganesh_valid_phone( $phone ) ) {
			return new WP_Error(
				'd7_invalid_phone',
				'సరైన 10 అంకెల మొబైల్ నంబర్ రాయండి.',
				array( 'status' => 400, 'field' => 'phone' )
			);
		}
		if ( ! d7_ganesh_valid_address( $address ) ) {
			return new WP_Error(
				'd7_invalid_address',
				'పూర్తి చిరునామా రాయండి (10 నుంచి 250 అక్షరాలు).',
				array( 'status' => 400, 'field' => 'address' )
			);
		}

		// 4. Duplicate phone.
		$existing = d7_ganesh_find_by_phone( $phone );
		if ( $existing ) {
			$error_data = array( 'status' => 409, 'field' => 'phone' );

			if ( ! empty( $config['reveal_token_on_duplicate'] ) ) {
				$error_data['token'] = $existing['token'];
			}

			return new WP_Error(
				'd7_duplicate_phone',
				'ఈ ఫోన్ నంబర్ ఇప్పటికే నమోదైంది.',
				$error_data
			);
		}

		// 5. Insert.
		$table    = d7_ganesh_table();
		$inserted = $wpdb->insert(
			$table,
			array(
				'token'      => '',
				'name'       => $name,
				'phone'      => $phone,
				'address'    => $address,
				'status'     => 'pending',
				'ip_address' => $ip,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// The UNIQUE index catches a race that slipped past step 4.
			$existing = d7_ganesh_find_by_phone( $phone );
			if ( $existing ) {
				$error_data = array( 'status' => 409, 'field' => 'phone' );
				if ( ! empty( $config['reveal_token_on_duplicate'] ) ) {
					$error_data['token'] = $existing['token'];
				}
				return new WP_Error( 'd7_duplicate_phone', 'ఈ ఫోన్ నంబర్ ఇప్పటికే నమోదైంది.', $error_data );
			}
			return new WP_Error(
				'd7_db_error',
				'నమోదు సేవ్ కాలేదు. దయచేసి మళ్లీ ప్రయత్నించండి.',
				array( 'status' => 500 )
			);
		}

		// 6. Token from the row id — sequential, unique, never reused.
		$id    = (int) $wpdb->insert_id;
		$token = d7_ganesh_build_token( $id );
		$wpdb->update( $table, array( 'token' => $token ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		$row = d7_ganesh_get_registration( $id );

		/**
		 * Fires after a devotee is registered.
		 * Hook here to send an SMS or WhatsApp message with the token.
		 *
		 * @param array $row
		 */
		do_action( 'd7_ganesh_registered', $row );

		$response = rest_ensure_response( array(
			'success' => true,
			'data'    => d7_ganesh_format_row( $row ),
		) );
		$response->set_status( 201 );

		return $response;
	}
}

/**
 * GET /registrations
 */
if ( ! function_exists( 'd7_ganesh_rest_list' ) ) {
	function d7_ganesh_rest_list( WP_REST_Request $request ) {
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$result = d7_ganesh_query_registrations( array(
			'search'   => (string) $request->get_param( 'search' ),
			'status'   => (string) $request->get_param( 'status' ),
			'per_page' => $per_page,
			'page'     => $page,
		) );

		$items = array_map( function ( $row ) {
			return d7_ganesh_format_row( $row, true );
		}, $result['rows'] );

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $per_page ) );

		return $response;
	}
}

/**
 * GET /registrations/<id>
 */
if ( ! function_exists( 'd7_ganesh_rest_single' ) ) {
	function d7_ganesh_rest_single( WP_REST_Request $request ) {
		$row = d7_ganesh_get_registration( (int) $request->get_param( 'id' ) );

		if ( ! $row ) {
			return new WP_Error( 'd7_not_found', 'Registration not found.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( d7_ganesh_format_row( $row, true ) );
	}
}


/* =============================================================================
 * SECTION 3 — ADMIN DASHBOARD
 *
 * Adds a "Ganesh Registrations" menu with counts, search, status filter,
 * pagination, a single-record view, mark-as-collected, delete and CSV export.
 * These hooks only fire inside wp-admin, so they cost nothing on the front end.
 * ========================================================================== */

/* ------------------------------------------------------------------- menu */

add_action( 'admin_menu', function () {
	add_menu_page(
		'Ganesh Registrations',        // page title
		'Ganesh Registrations',        // menu label
		'manage_options',              // capability
		'd7-ganesh-registrations',     // slug
		'd7_ganesh_render_admin_page', // callback
		'dashicons-tickets-alt',
		26
	);
} );

/* ------------------------------------------------- row actions (redirects) */

/**
 * Handled on admin_init so wp_safe_redirect() runs before output starts.
 * Every action checks the capability AND a nonce.
 */
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['page'], $_GET['d7_action'] ) || 'd7-ganesh-registrations' !== $_GET['page'] ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to do this.' );
	}

	global $wpdb;

	$action = sanitize_key( wp_unslash( $_GET['d7_action'] ) );
	$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	$table  = d7_ganesh_table();

	check_admin_referer( 'd7_ganesh_' . $action . '_' . $id );

	switch ( $action ) {
		case 'collect':
			$wpdb->update( $table, array( 'status' => 'collected' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			$notice = 'collected';
			break;

		case 'uncollect':
			$wpdb->update( $table, array( 'status' => 'pending' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
			$notice = 'pending';
			break;

		case 'delete':
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			$notice = 'deleted';
			break;

		default:
			$notice = '';
	}

	// Redirect to a clean URL so a refresh cannot repeat the action.
	$back = add_query_arg(
		array_filter( array(
			'page'      => 'd7-ganesh-registrations',
			's'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : null,
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : null,
			'paged'     => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : null,
			'd7_notice' => $notice ? $notice : null,
		) ),
		admin_url( 'admin.php' )
	);

	wp_safe_redirect( $back );
	exit;
} );

/* --------------------------------------------------------------- CSV export */

add_action( 'admin_post_d7_ganesh_export', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to export registrations.' );
	}
	check_admin_referer( 'd7_ganesh_export' );

	global $wpdb;
	$table = d7_ganesh_table();
	$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

	$filename = 'd7-ganesh-registrations-' . current_time( 'Y-m-d-His' ) . '.csv';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$out = fopen( 'php://output', 'w' );

	// BOM so Excel opens the Telugu names correctly.
	fwrite( $out, "\xEF\xBB\xBF" );

	fputcsv( $out, array( 'Token', 'Name', 'Phone', 'Address', 'Status', 'Registered at' ) );

	foreach ( $rows as $row ) {
		fputcsv( $out, array(
			$row['token'],
			$row['name'],
			$row['phone'],
			$row['address'],
			$row['status'],
			$row['created_at'],
		) );
	}

	fclose( $out );
	exit;
} );

/* --------------------------------------------------------------- page body */

if ( ! function_exists( 'd7_ganesh_render_admin_page' ) ) {
	function d7_ganesh_render_admin_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You are not allowed to view this page.' );
		}

		// A single record was requested.
		$view_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		if ( $view_id ) {
			d7_ganesh_render_single_view( $view_id );
			return;
		}

		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;

		$result = d7_ganesh_query_registrations( array(
			'search'   => $search,
			'status'   => $status,
			'per_page' => $per_page,
			'page'     => $paged,
		) );

		$counts = d7_ganesh_get_counts();
		$notice = isset( $_GET['d7_notice'] ) ? sanitize_key( wp_unslash( $_GET['d7_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Ganesh Registrations</h1>

			<a class="page-title-action"
			   href="<?php echo esc_url( wp_nonce_url(
					admin_url( 'admin-post.php?action=d7_ganesh_export' ),
					'd7_ganesh_export'
				) ); ?>">Export CSV</a>

			<hr class="wp-header-end">

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php
						$messages = array(
							'collected' => 'Marked as collected.',
							'pending'   => 'Moved back to pending.',
							'deleted'   => 'Registration deleted.',
						);
						echo esc_html( isset( $messages[ $notice ] ) ? $messages[ $notice ] : 'Done.' );
					?></p>
				</div>
			<?php endif; ?>

			<!-- summary cards -->
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0 22px;">
				<?php
				$cards = array(
					'Total registrations' => $counts['total'],
					'Registered today'    => $counts['today'],
					'Idol collected'      => $counts['collected'],
					'Still pending'       => $counts['pending'],
				);
				foreach ( $cards as $label => $value ) : ?>
					<div style="flex:1 1 170px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #d63638;border-radius:6px;padding:14px 16px;">
						<div style="font-size:12px;color:#646970;"><?php echo esc_html( $label ); ?></div>
						<div style="font-size:26px;font-weight:600;line-height:1.3;"><?php echo esc_html( number_format_i18n( $value ) ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- search + filter -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="d7-ganesh-registrations">

				<p class="search-box" style="float:none;margin-bottom:12px;">
					<label class="screen-reader-text" for="d7-search">Search registrations</label>
					<input type="search" id="d7-search" name="s"
					       value="<?php echo esc_attr( $search ); ?>"
					       placeholder="Name, phone or token">

					<select name="status">
						<option value="">All statuses</option>
						<option value="pending" <?php selected( $status, 'pending' ); ?>>Pending</option>
						<option value="collected" <?php selected( $status, 'collected' ); ?>>Collected</option>
					</select>

					<?php submit_button( 'Search', 'secondary', '', false ); ?>

					<?php if ( $search || $status ) : ?>
						<a class="button"
						   href="<?php echo esc_url( admin_url( 'admin.php?page=d7-ganesh-registrations' ) ); ?>">Clear</a>
					<?php endif; ?>
				</p>
			</form>

			<p class="displaying-num" style="margin:0 0 6px;">
				<?php echo esc_html( number_format_i18n( $result['total'] ) ); ?> item(s) found
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:140px;">Token</th>
						<th style="width:180px;">Name</th>
						<th style="width:130px;">Phone</th>
						<th>Address</th>
						<th style="width:160px;">Registered at</th>
						<th style="width:110px;">Status</th>
						<th style="width:190px;">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $result['rows'] ) ) : ?>
					<tr>
						<td colspan="7">
							<?php echo $search || $status
								? 'No registration matches this search.'
								: 'No registrations yet. They will appear here as soon as the form is used.'; ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $result['rows'] as $row ) :
						$id       = (int) $row['id'];
						$base_url = add_query_arg(
							array_filter( array(
								'page'   => 'd7-ganesh-registrations',
								's'      => $search ? $search : null,
								'status' => $status ? $status : null,
								'paged'  => $paged > 1 ? $paged : null,
							) ),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td><strong><?php echo esc_html( $row['token'] ); ?></strong></td>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td>
								<a href="tel:<?php echo esc_attr( $row['phone'] ); ?>">
									<?php echo esc_html( $row['phone'] ); ?>
								</a>
							</td>
							<td><?php echo esc_html( wp_trim_words( $row['address'], 12, '…' ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'd-m-Y, h:i A', $row['created_at'] ) ); ?></td>
							<td>
								<?php if ( 'collected' === $row['status'] ) : ?>
									<span style="color:#00794a;font-weight:600;">Collected</span>
								<?php else : ?>
									<span style="color:#996800;font-weight:600;">Pending</span>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( 'view', $id, $base_url ) ); ?>">View</a>

								<?php if ( 'collected' === $row['status'] ) : ?>
									| <a href="<?php echo esc_url( wp_nonce_url(
										add_query_arg( array( 'd7_action' => 'uncollect', 'id' => $id ), $base_url ),
										'd7_ganesh_uncollect_' . $id
									) ); ?>">Undo</a>
								<?php else : ?>
									| <a href="<?php echo esc_url( wp_nonce_url(
										add_query_arg( array( 'd7_action' => 'collect', 'id' => $id ), $base_url ),
										'd7_ganesh_collect_' . $id
									) ); ?>">Mark collected</a>
								<?php endif; ?>

								| <a style="color:#b32d2e;"
								     href="<?php echo esc_url( wp_nonce_url(
										add_query_arg( array( 'd7_action' => 'delete', 'id' => $id ), $base_url ),
										'd7_ganesh_delete_' . $id
									) ); ?>"
								     onclick="return confirm('Delete this registration? This cannot be undone.');">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $result['total'] / $per_page );
			if ( $total_pages > 1 ) :
				$links = paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $paged,
					'type'      => 'plain',
				) );
				?>
				<div class="tablenav bottom">
					<div class="tablenav-pages" style="float:none;text-align:right;">
						<?php echo wp_kses_post( $links ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

/* ------------------------------------------------------------ single record */

if ( ! function_exists( 'd7_ganesh_render_single_view' ) ) {
	function d7_ganesh_render_single_view( $id ) {
		$row = d7_ganesh_get_registration( $id );

		if ( ! $row ) {
			echo '<div class="wrap"><h1>Registration not found</h1><p>'
				. '<a href="' . esc_url( admin_url( 'admin.php?page=d7-ganesh-registrations' ) ) . '">Back to all registrations</a>'
				. '</p></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $row['token'] ); ?></h1>
			<a class="page-title-action"
			   href="<?php echo esc_url( admin_url( 'admin.php?page=d7-ganesh-registrations' ) ); ?>">Back to all</a>
			<hr class="wp-header-end">

			<div style="max-width:640px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:6px 20px 16px;">
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">Name</th>
							<td><?php echo esc_html( $row['name'] ); ?></td>
						</tr>
						<tr>
							<th scope="row">Phone number</th>
							<td>
								<a href="tel:<?php echo esc_attr( $row['phone'] ); ?>">
									+91 <?php echo esc_html( $row['phone'] ); ?>
								</a>
							</td>
						</tr>
						<tr>
							<th scope="row">Address</th>
							<td><?php echo nl2br( esc_html( $row['address'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row">Token number</th>
							<td><strong><?php echo esc_html( $row['token'] ); ?></strong></td>
						</tr>
						<tr>
							<th scope="row">Registered at</th>
							<td><?php echo esc_html( mysql2date( 'd-m-Y, h:i A', $row['created_at'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row">Status</th>
							<td><?php echo esc_html( ucfirst( $row['status'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row">Submitted from IP</th>
							<td><code><?php echo esc_html( $row['ip_address'] ); ?></code></td>
						</tr>
					</tbody>
				</table>

				<?php
				$base_url = admin_url( 'admin.php?page=d7-ganesh-registrations' );
				if ( 'collected' !== $row['status'] ) : ?>
					<a class="button button-primary"
					   href="<?php echo esc_url( wp_nonce_url(
							add_query_arg( array( 'd7_action' => 'collect', 'id' => (int) $row['id'] ), $base_url ),
							'd7_ganesh_collect_' . (int) $row['id']
						) ); ?>">Mark idol collected</a>
				<?php else : ?>
					<a class="button"
					   href="<?php echo esc_url( wp_nonce_url(
							add_query_arg( array( 'd7_action' => 'uncollect', 'id' => (int) $row['id'] ), $base_url ),
							'd7_ganesh_uncollect_' . (int) $row['id']
						) ); ?>">Move back to pending</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}


/* =============================================================================
 * SECTION 4 — CORS
 * ========================================================================== */

if ( ! function_exists( 'd7_ganesh_allowed_origins' ) ) {
	function d7_ganesh_allowed_origins() {
		$config = d7_ganesh_config();
		$allowed = isset( $config['allowed_origins'] ) ? (array) $config['allowed_origins'] : array();
		return array_values( array_filter( array_map( 'untrailingslashit', $allowed ) ) );
	}
}

if ( ! function_exists( 'd7_ganesh_request_origin' ) ) {
	function d7_ganesh_request_origin() {
		$origin = get_http_origin();
		$origin = $origin ? untrailingslashit( $origin ) : '';
		return ( $origin && in_array( $origin, d7_ganesh_allowed_origins(), true ) ) ? $origin : '';
	}
}

if ( ! function_exists( 'd7_ganesh_send_cors_headers' ) ) {
	function d7_ganesh_send_cors_headers( $origin ) {
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce, X-D7-Admin-Key' );
		header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages' );
		header( 'Access-Control-Max-Age: 600' );
		header( 'Vary: Origin', false );
	}
}

// Let WordPress core include the optional admin header in its preflight list.
add_filter( 'rest_allowed_cors_headers', function ( $headers ) {
	$headers[] = 'X-D7-Admin-Key';
	return array_values( array_unique( $headers ) );
} );

// Answer our preflight before WordPress REST routing takes over.
add_action( 'init', function () {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'OPTIONS' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( false === strpos( $uri, 'd7-ganesh' ) ) {
		return;
	}

	$origin = d7_ganesh_request_origin();
	if ( ! $origin ) {
		status_header( 403 );
		exit;
	}

	d7_ganesh_send_cors_headers( $origin );
	status_header( 204 );
	exit;
}, 1 );

// Add CORS headers to actual responses and expose pagination metadata.
add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
	$route = $request instanceof WP_REST_Request ? $request->get_route() : '';
	if ( 0 !== strpos( $route, '/d7-ganesh/v1' ) ) {
		return $served;
	}

	$origin = d7_ganesh_request_origin();
	if ( $origin ) {
		d7_ganesh_send_cors_headers( $origin );
	}

	return $served;
}, 20, 3 );


/* =============================================================================
 * ADMIN ACTIONS
 * ========================================================================== */

// These write routes use POST with form-encoded bodies to avoid CORS preflight.
add_action( 'rest_api_init', function () {
	register_rest_route( 'd7-ganesh/v1', '/registrations/(?P<id>\d+)/status', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'd7_ganesh_rest_update_status',
		'permission_callback' => 'd7_ganesh_admin_permission',
		'args'                => array(
			'id'     => array( 'sanitize_callback' => 'absint' ),
			'status' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		),
	) );

	register_rest_route( 'd7-ganesh/v1', '/registrations/(?P<id>\d+)/delete', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'd7_ganesh_rest_delete',
		'permission_callback' => 'd7_ganesh_admin_permission',
		'args'                => array(
			'id' => array( 'sanitize_callback' => 'absint' ),
		),
	) );

	register_rest_route( 'd7-ganesh/v1', '/export', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'd7_ganesh_rest_export',
		'permission_callback' => 'd7_ganesh_admin_permission',
	) );
} );

if ( ! function_exists( 'd7_ganesh_rest_update_status' ) ) {
	function d7_ganesh_rest_update_status( WP_REST_Request $request ) {
		global $wpdb;

		$id     = (int) $request->get_param( 'id' );
		$status = (string) $request->get_param( 'status' );
		$row    = d7_ganesh_get_registration( $id );

		if ( ! in_array( $status, array( 'pending', 'collected' ), true ) ) {
			return new WP_Error( 'd7_invalid_status', 'Status must be pending or collected.', array( 'status' => 400 ) );
		}
		if ( ! $row ) {
			return new WP_Error( 'd7_not_found', 'Registration not found.', array( 'status' => 404 ) );
		}

		$updated = $wpdb->update(
			d7_ganesh_table(),
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'd7_db_error', 'Registration status could not be updated.', array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => d7_ganesh_format_row( d7_ganesh_get_registration( $id ), true ),
		) );
	}
}

if ( ! function_exists( 'd7_ganesh_rest_delete' ) ) {
	function d7_ganesh_rest_delete( WP_REST_Request $request ) {
		global $wpdb;

		$id  = (int) $request->get_param( 'id' );
		$row = d7_ganesh_get_registration( $id );
		if ( ! $row ) {
			return new WP_Error( 'd7_not_found', 'Registration not found.', array( 'status' => 404 ) );
		}

		$deleted = $wpdb->delete( d7_ganesh_table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'd7_db_error', 'Registration could not be deleted.', array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'deleted' => $id,
			'token'   => $row['token'],
		) );
	}
}

if ( ! function_exists( 'd7_ganesh_rest_export' ) ) {
	function d7_ganesh_rest_export( WP_REST_Request $request ) {
		global $wpdb;

		$rows     = $wpdb->get_results( 'SELECT * FROM ' . d7_ganesh_table() . ' ORDER BY id ASC', ARRAY_A );
		$filename = 'd7-ganesh-registrations-' . current_time( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Token', 'Name', 'Phone', 'Address', 'Status', 'Registered at' ) );

		foreach ( $rows as $row ) {
			fputcsv( $out, array(
				$row['token'],
				$row['name'],
				$row['phone'],
				$row['address'],
				$row['status'],
				$row['created_at'],
			) );
		}

		fclose( $out );
		exit;
	}
}