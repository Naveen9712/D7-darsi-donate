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
 *   SECTION 2 — Legacy token re-issue (one-time migration)
 *   SECTION 3 — REST API
 *   SECTION 4 — Admin dashboard
 *   SECTION 5 — CORS
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

			// Token prefix. Result looks like D7-RAMESH-X7K4 (random, not sequential).
			'token_prefix' => 'D7-RAMESH-',

			// First N registrations receive the special Ganesh idol + Puja Samagri + gift.
			// This is stored on the row and shown only in admin — never encoded in the token.
			'special_limit' => 200,

			// Max submissions allowed per IP address per hour.
			// Indian mobile carriers use CGNAT, so many devotees can share one public
			// IP. Keep this generous or a whole neighbourhood gets locked out.
			'rate_limit' => 40,

			/**
			 * Front-end origins allowed to call the API from a browser.
			 * No trailing slash. Never use '*'. Never list localhost in production.
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
			 * Key for the standalone /admin/ page.
			 * Send ONLY as the X-D7-Admin-Key header — never as a query string.
			 * Rotate immediately if this value is ever pasted into chat, email,
			 * a screenshot, a support ticket or a git commit.
			 */
			'admin_api_key' => 'Upsc@365',
		);
	}
}

if ( ! defined( 'D7_GANESH_DB_VERSION' ) ) {
	define( 'D7_GANESH_DB_VERSION', '1.4.0' );
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
 *
 * `old_token` holds the original sequential token (D7-RAMESH-0009) for rows
 * that were re-issued a random token. It is deliberately NOT unique, because
 * every new registration stores an empty string there.
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
			old_token varchar(32) NOT NULL DEFAULT '',
			name varchar(120) NOT NULL DEFAULT '',
			phone varchar(15) NOT NULL DEFAULT '',
			address text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			is_special tinyint(1) unsigned NOT NULL DEFAULT 0,
			ip_address varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY phone (phone),
			UNIQUE KEY token (token),
			KEY old_token (old_token),
			KEY status (status),
			KEY is_special (is_special),
			KEY created_at (created_at)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Normalise the old brand prefix without changing any numeric suffix.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET token = REPLACE(token, %s, %s) WHERE token LIKE %s",
				'D7-GANESH-',
				'D7-RAMESH-',
				'D7-GANESH-%'
			)
		);

		$config = d7_ganesh_config();
		$limit  = max( 0, (int) $config['special_limit'] );

		// One-time backfill only. Later version bumps must never rewrite is_special,
		// or people already promised a special idol would silently lose it.
		if ( ! get_option( 'd7_ganesh_special_backfill_done' ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET is_special = CASE WHEN id <= %d THEN 1 ELSE 0 END",
					$limit
				)
			);
			update_option( 'd7_ganesh_special_backfill_done', '1' );
		}

		update_option( 'd7_ganesh_db_version', D7_GANESH_DB_VERSION );

		// Re-issue random tokens to any rows still carrying a sequential one.
		d7_ganesh_reissue_legacy_tokens();
	}
}

/**
 * Create/upgrade the table on first request (front end, REST, or admin).
 */
add_action( 'init', function () {
	if ( get_option( 'd7_ganesh_db_version' ) !== D7_GANESH_DB_VERSION ) {
		d7_ganesh_install_table();
	}
} );

/**
 * Random token suffix. Letters and digits, omitting 0/O/1/I/L so a token
 * like D7-RAMESH-X7K4 is easy to read aloud at the collection counter.
 *
 * Because 0 and 1 are excluded, a random suffix can never look like a
 * zero-padded sequence number — the migration in SECTION 2 relies on this.
 *
 * @param int $length
 * @return string
 */
if ( ! function_exists( 'd7_ganesh_random_suffix' ) ) {
	function d7_ganesh_random_suffix( $length = 4 ) {
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$max      = strlen( $alphabet ) - 1;
		$out      = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, $max ) ];
		}

		return $out;
	}
}

/**
 * Build a token. Uniqueness is enforced by the UNIQUE index on `token`;
 * callers that insert should retry on a clash.
 *
 * @param int $id Unused; kept so older call sites stay valid.
 * @return string e.g. D7-RAMESH-X7K4
 */
if ( ! function_exists( 'd7_ganesh_build_token' ) ) {
	function d7_ganesh_build_token( $id = 0 ) {
		$config = d7_ganesh_config();
		return $config['token_prefix'] . d7_ganesh_random_suffix( 4 );
	}
}

/**
 * Build a token that is not already used as a current OR historical token.
 *
 * Used by the migration, which writes tokens with UPDATE rather than INSERT
 * and so cannot lean on insert-retry. Checking `old_token` too means a
 * volunteer searching an old slip number can never land on two rows.
 *
 * @return string Empty string if no free token was found (practically never).
 */
if ( ! function_exists( 'd7_ganesh_unique_token' ) ) {
	function d7_ganesh_unique_token() {
		global $wpdb;

		$config = d7_ganesh_config();
		$table  = d7_ganesh_table();

		$attempts = array_fill( 0, 40, 4 );          // 4-character suffix
		$attempts = array_merge( $attempts, array_fill( 0, 20, 6 ) ); // then widen

		foreach ( $attempts as $length ) {
			$token = $config['token_prefix'] . d7_ganesh_random_suffix( $length );

			$taken = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE token = %s OR old_token = %s LIMIT 1",
					$token,
					$token
				)
			);

			if ( ! $taken ) {
				return $token;
			}
		}

		return '';
	}
}

/**
 * Whether this row is one of the first N special-idol registrations.
 * Uses the stored flag when present; otherwise falls back to the row id.
 *
 * @param array $row
 * @return bool
 */
if ( ! function_exists( 'd7_ganesh_row_is_special' ) ) {
	function d7_ganesh_row_is_special( $row ) {
		$config = d7_ganesh_config();
		$limit  = max( 0, (int) $config['special_limit'] );

		if ( isset( $row['is_special'] ) && '' !== $row['is_special'] && null !== $row['is_special'] ) {
			return 1 === (int) $row['is_special'];
		}

		return (int) $row['id'] <= $limit;
	}
}

/**
 * Return the current public token format while preserving its suffix.
 * Keeps any row created under the old brand prefix consistent in output.
 *
 * @param string $token Stored token value.
 * @return string Current public token value.
 */
if ( ! function_exists( 'd7_ganesh_public_token' ) ) {
	function d7_ganesh_public_token( $token ) {
		$config = d7_ganesh_config();
		$token  = (string) $token;

		if ( 0 === strpos( $token, 'D7-GANESH-' ) ) {
			return $config['token_prefix'] . substr( $token, strlen( 'D7-GANESH-' ) );
		}

		return $token;
	}
}

/**
 * The token as it should be shown to staff: the current random token, with the
 * original sequential one in brackets when the row was re-issued.
 *
 *   D7-RAMESH-X7K4 (Old: D7-RAMESH-0009)
 *   D7-RAMESH-M4QP                         <- registered after the change
 *
 * @param array $row
 * @return string
 */
if ( ! function_exists( 'd7_ganesh_display_token' ) ) {
	function d7_ganesh_display_token( $row ) {
		$new = d7_ganesh_public_token( isset( $row['token'] ) ? $row['token'] : '' );
		$old = isset( $row['old_token'] ) ? trim( (string) $row['old_token'] ) : '';

		if ( '' === $old ) {
			return $new;
		}

		$old = d7_ganesh_public_token( $old );
		if ( $old === $new ) {
			return $new;
		}

		return $new . ' (Old: ' . $old . ')';
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
 * Search covers name, phone, the current token and the original token, so a
 * devotee holding an old printed slip can still be found by their old number.
 *
 * All placeholders are collected and bound in a single prepare() call. Building
 * a pre-prepared WHERE string and re-preparing it would let the % characters in
 * a LIKE pattern be re-read as placeholders, which silently empties the query.
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

		$where_sql  = 'WHERE 1=1';
		$where_args = array();

		if ( '' !== trim( $args['search'] ) ) {
			$like       = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where_sql .= ' AND ( name LIKE %s OR phone LIKE %s OR token LIKE %s OR old_token LIKE %s )';
			array_push( $where_args, $like, $like, $like, $like );
		}

		if ( in_array( $args['status'], array( 'pending', 'collected' ), true ) ) {
			$where_sql   .= ' AND status = %s';
			$where_args[] = $args['status'];
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$total     = (int) ( $where_args
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $where_args ) )
			: $wpdb->get_var( $count_sql )
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $where_args, array( $per_page, $offset ) )
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
			'special'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_special = 1" ),
			'reissued'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE old_token <> ''" ),
		);
	}
}


/* =============================================================================
 * SECTION 2 — LEGACY TOKEN RE-ISSUE (one-time migration)
 *
 * Early registrations were issued sequential tokens (D7-RAMESH-0009) which leak
 * the registration count and are trivially guessable. This migration gives every
 * such row a fresh random token and keeps the original in `old_token`, so the
 * admin screen can show:
 *
 *     D7-RAMESH-X7K4 (Old: D7-RAMESH-0009)
 *
 * Nothing else on the row is touched — name, phone, address, status, created_at
 * and is_special are all left exactly as they are.
 * ========================================================================== */

/**
 * Is this token a legacy sequential one?
 *
 * Two independent signals, either of which is conclusive:
 *
 *   1. The suffix contains 0 or 1. The random alphabet excludes both, so a
 *      random token can never contain them.
 *   2. The suffix, read as a number, equals the row id — which is exactly how
 *      the old sequential tokens were built.
 *
 * A random suffix of pure digits (e.g. X7K4 -> 2345) is possible but would also
 * have to coincide with its own row id to be misread, which is why both tests
 * are required rather than "suffix is numeric".
 *
 * @param string $token
 * @param int    $id
 * @return bool
 */
if ( ! function_exists( 'd7_ganesh_is_legacy_token' ) ) {
	function d7_ganesh_is_legacy_token( $token, $id ) {
		$token = (string) $token;
		$pos   = strrpos( $token, '-' );

		if ( false === $pos ) {
			return false;
		}

		$suffix = substr( $token, $pos + 1 );

		if ( '' === $suffix || ! ctype_digit( $suffix ) ) {
			return false;
		}

		if ( false !== strpos( $suffix, '0' ) || false !== strpos( $suffix, '1' ) ) {
			return true;
		}

		return (int) $suffix === (int) $id;
	}
}

/**
 * Re-issue random tokens to every row that still has a sequential one.
 *
 * Safe to call more than once:
 *   - a completion flag short-circuits it after the first successful pass;
 *   - a transient lock stops two concurrent requests running it together;
 *   - the UPDATE carries `AND old_token = ''`, so a row can never have its
 *     original token overwritten by an already-re-issued value.
 *
 * @return int Number of rows re-issued in this pass.
 */
if ( ! function_exists( 'd7_ganesh_reissue_legacy_tokens' ) ) {
	function d7_ganesh_reissue_legacy_tokens() {
		global $wpdb;

		if ( get_option( 'd7_ganesh_token_reissue_done' ) ) {
			return 0;
		}

		// Cheap advisory lock. Another request is already migrating; let it finish.
		if ( get_transient( 'd7_ganesh_reissue_lock' ) ) {
			return 0;
		}
		set_transient( 'd7_ganesh_reissue_lock', 1, 5 * MINUTE_IN_SECONDS );

		$table = d7_ganesh_table();
		$rows  = $wpdb->get_results(
			"SELECT id, token, old_token FROM {$table} ORDER BY id ASC",
			ARRAY_A
		);

		$done   = 0;
		$failed = 0;

		if ( $rows ) {
			foreach ( $rows as $row ) {

				// Already re-issued in an earlier pass.
				if ( '' !== trim( (string) $row['old_token'] ) ) {
					continue;
				}

				if ( ! d7_ganesh_is_legacy_token( $row['token'], $row['id'] ) ) {
					continue;
				}

				$new_token = d7_ganesh_unique_token();
				if ( '' === $new_token ) {
					$failed++;
					continue;
				}

				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET token = %s, old_token = %s WHERE id = %d AND old_token = ''",
						$new_token,
						$row['token'],
						(int) $row['id']
					)
				);

				if ( $updated ) {
					$done++;
				} else {
					$failed++;
				}
			}
		}

		delete_transient( 'd7_ganesh_reissue_lock' );

		// Only mark complete when nothing was left behind, so a partial run
		// (token clash, dropped connection) is retried on the next request.
		if ( 0 === $failed ) {
			update_option( 'd7_ganesh_token_reissue_done', '1' );
			update_option( 'd7_ganesh_token_reissue_count', (int) get_option( 'd7_ganesh_token_reissue_count', 0 ) + $done );
		}

		return $done;
	}
}


/* =============================================================================
 * SECTION 3 — REST API
 *
 * Routes (namespace d7-ganesh/v1):
 *   POST /register                     public  — create a registration
 *   GET  /stats                        public  — total count only
 *   GET  /admin-stats                  admin   — dashboard counts
 *   GET  /registrations                admin   — paginated list + search
 *   GET  /registrations/<id>           admin   — one record
 *   POST /registrations/<id>/status    admin   — pending / collected
 *   POST /registrations/<id>/delete    admin   — delete
 *   GET  /export                       admin   — CSV download
 * ========================================================================== */

/* ------------------------------------------------------------- validation */

/**
 * Validate a name: at least 3 characters, at most 60, no digits.
 * Any script is accepted so Telugu names pass.
 */
if ( ! function_exists( 'd7_ganesh_valid_name' ) ) {
	function d7_ganesh_valid_name( $value ) {
		$value = trim( (string) $value );
		if ( mb_strlen( $value ) < 3 || mb_strlen( $value ) > 60 ) {
			return false;
		}
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
 * Per-IP rate limit backed by transients. Only successful registrations are
 * counted, so a devotee fixing typos cannot exhaust the quota for their whole
 * CGNAT block.
 *
 * @return bool true when the request is allowed.
 */
if ( ! function_exists( 'd7_ganesh_rate_limit_ok' ) ) {
	function d7_ganesh_rate_limit_ok( $ip ) {
		$config = d7_ganesh_config();
		$max    = (int) $config['rate_limit'];

		return ( (int) get_transient( 'd7g_rl_' . md5( $ip ) ) ) < $max;
	}
}

/** Record one successful registration against this IP. */
if ( ! function_exists( 'd7_ganesh_rate_limit_hit' ) ) {
	function d7_ganesh_rate_limit_hit( $ip ) {
		$key   = 'd7g_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}
}

/**
 * Shape a DB row for output. Never returns the IP address publicly.
 */
if ( ! function_exists( 'd7_ganesh_format_row' ) ) {
	function d7_ganesh_format_row( $row, $include_private = false ) {
		$old = isset( $row['old_token'] ) ? trim( (string) $row['old_token'] ) : '';

		$out = array(
			'token'           => d7_ganesh_public_token( $row['token'] ),
			'old_token'       => '' === $old ? '' : d7_ganesh_public_token( $old ),
			'token_display'   => d7_ganesh_display_token( $row ),
			'name'            => $row['name'],
			'phone'           => $row['phone'],
			'address'         => $row['address'],
			'status'          => $row['status'],
			'created_at'      => $row['created_at'],
			'created_display' => mysql2date( 'd-m-Y, h:i A', $row['created_at'] ),
		);

		if ( $include_private ) {
			$is_special         = d7_ganesh_row_is_special( $row );
			$out['id']          = (int) $row['id'];
			$out['ip_address']  = $row['ip_address'];
			$out['is_special']  = $is_special;
			$out['gift']        = $is_special ? 'special' : 'standard';
		}

		return $out;
	}
}

/* ------------------------------------------------------------- permission */

/**
 * Admin-only endpoints.
 *
 * The shared key is accepted from (in order):
 *   1) X-D7-Admin-Key request header (wp-admin, curl, hosts that forward it),
 *   2) ?admin_key= query string,
 *   3) admin_key POST field (form-encoded writes avoid CORS preflight).
 *
 * The public /admin/ page MUST use (2) or (3), not the custom header.
 * This host answers OPTIONS before WordPress, with Allow-Headers that omit
 * X-D7-Admin-Key, so a browser preflight would fail even with a valid key.
 *
 * Values are trimmed before comparison so a copy/pasted key with a trailing
 * space still works.
 */
if ( ! function_exists( 'd7_ganesh_admin_permission' ) ) {
	function d7_ganesh_admin_permission( $request = null ) {
		$config = d7_ganesh_config();

		if ( ! empty( $config['admin_api_key'] ) ) {
			$provided_key = '';

			// 1) X-D7-Admin-Key header (some hosts expose it under another key).
			foreach ( array( 'HTTP_X_D7_ADMIN_KEY', 'HTTP_X_D7_ADMINKEY', 'HTTP_X_D7ADMINKEY', 'HTTP_X_ADMIN_KEY' ) as $server_key ) {
				if ( ! empty( $_SERVER[ $server_key ] ) ) {
					$provided_key = (string) wp_unslash( $_SERVER[ $server_key ] );
					break;
				}
			}

			// 2) getallheaders() fallback for servers that don't populate $_SERVER.
			if ( '' === $provided_key && function_exists( 'getallheaders' ) ) {
				$headers = getallheaders();
				if ( is_array( $headers ) ) {
					foreach ( $headers as $name => $value ) {
						if ( 'x-d7-admin-key' === strtolower( (string) $name ) ) {
							$provided_key = (string) $value;
							break;
						}
					}
				}
			}

			// 3) Query string / form-body fallback (?admin_key=... or POST
			//    admin_key=...) for hosts/proxies that strip custom headers.
			//    Note: $request->get_param() merges query + body, so one read
			//    covers both transports.
			if ( '' === $provided_key && null !== $request && $request instanceof WP_REST_Request ) {
				$from_param = $request->get_param( 'admin_key' );
				if ( null !== $from_param && '' !== $from_param ) {
					$provided_key = (string) $from_param;
				}
			}
			if ( '' === $provided_key && isset( $_GET['admin_key'] ) && '' !== $_GET['admin_key'] ) {
				$provided_key = (string) wp_unslash( $_GET['admin_key'] );
			}
			if ( '' === $provided_key && isset( $_POST['admin_key'] ) && '' !== $_POST['admin_key'] ) {
				$provided_key = (string) wp_unslash( $_POST['admin_key'] );
			}

			if ( '' !== trim( $provided_key ) && hash_equals( (string) $config['admin_api_key'], trim( $provided_key ) ) ) {
				return true;
			}
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

	// ---- GET /stats ------------------------------------------------------
	// Public callers get { total } only. Authorised callers (admin key or WP
	// admin session) get the full breakdown — this keeps older frontends that
	// call GET /stats for the dashboard working after login.
	register_rest_route( 'd7-ganesh/v1', '/stats', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( true === d7_ganesh_admin_permission( $request ) ) {
				return rest_ensure_response( d7_ganesh_get_counts() );
			}
			global $wpdb;
			$table = d7_ganesh_table();
			return rest_ensure_response( array(
				'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			) );
		},
		'permission_callback' => '__return_true',
	) );

	// ---- GET /admin-stats (admin) ----------------------------------------
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

	// ---- Write routes (admin). POST + form-encoded to avoid preflight. ----
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

/* --------------------------------------------------------------- handlers */

/**
 * POST /register
 *
 * Validation order: honeypot, rate limit, field rules, duplicate phone,
 * then insert. The token is a unique random code, never the row id.
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
			return d7_ganesh_duplicate_error( $existing, $config );
		}

		// 5. Insert with a random unique token (not derived from the row id).
		$table = d7_ganesh_table();
		$id    = 0;

		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$token = d7_ganesh_build_token();

			$inserted = $wpdb->insert(
				$table,
				array(
					'token'      => $token,
					'old_token'  => '',
					'name'       => $name,
					'phone'      => $phone,
					'address'    => $address,
					'status'     => 'pending',
					'is_special' => 0,
					'ip_address' => $ip,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				$id = (int) $wpdb->insert_id;
				break;
			}

			/*
			 * Capture the error BEFORE running any other query. wpdb::query()
			 * flushes last_error at the start of every call, so reading it after
			 * a lookup would always return an empty string and a token clash
			 * would be misreported as a fatal database error.
			 */
			$insert_error = (string) $wpdb->last_error;

			$token_clash = ( false !== stripos( $insert_error, 'Duplicate' ) )
				&& ( false !== stripos( $insert_error, 'token' ) );

			if ( $token_clash ) {
				continue; // vanishingly rare; just draw another token
			}

			// Anything else: most likely the phone unique index tripped in a race.
			$existing = d7_ganesh_find_by_phone( $phone );
			if ( $existing ) {
				return d7_ganesh_duplicate_error( $existing, $config );
			}

			return new WP_Error(
				'd7_db_error',
				'నమోదు సేవ్ కాలేదు. దయచేసి మళ్లీ ప్రయత్నించండి.',
				array( 'status' => 500 )
			);
		}

		if ( ! $id ) {
			return new WP_Error(
				'd7_db_error',
				'నమోదు సేవ్ కాలేదు. దయచేసి మళ్లీ ప్రయత్నించండి.',
				array( 'status' => 500 )
			);
		}

		// 6. First N row ids get the special idol. Never encoded in the token.
		$limit      = max( 0, (int) $config['special_limit'] );
		$is_special = ( $id <= $limit ) ? 1 : 0;
		$wpdb->update(
			$table,
			array( 'is_special' => $is_special ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		d7_ganesh_rate_limit_hit( $ip );

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
 * Shared 409 for an already-registered phone number.
 * Includes the old token too, so someone holding a pre-migration slip sees
 * both numbers and knows the new one replaces it.
 */
if ( ! function_exists( 'd7_ganesh_duplicate_error' ) ) {
	function d7_ganesh_duplicate_error( $existing, $config ) {
		$error_data = array( 'status' => 409, 'field' => 'phone' );

		if ( ! empty( $config['reveal_token_on_duplicate'] ) ) {
			$old = isset( $existing['old_token'] ) ? trim( (string) $existing['old_token'] ) : '';

			$error_data['token']         = d7_ganesh_public_token( $existing['token'] );
			$error_data['old_token']     = '' === $old ? '' : d7_ganesh_public_token( $old );
			$error_data['token_display'] = d7_ganesh_display_token( $existing );
		}

		return new WP_Error(
			'd7_duplicate_phone',
			'ఈ ఫోన్ నంబర్ ఇప్పటికే నమోదైంది.',
			$error_data
		);
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

/**
 * POST /registrations/<id>/status
 */
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

/**
 * POST /registrations/<id>/delete
 */
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
			'token'   => d7_ganesh_display_token( $row ),
		) );
	}
}

/**
 * GET /export
 *
 * CORS headers are sent by hand because this handler ends with exit(), which
 * runs before rest_pre_serve_request would have attached them.
 */
if ( ! function_exists( 'd7_ganesh_rest_export' ) ) {
	function d7_ganesh_rest_export( WP_REST_Request $request ) {
		$origin = d7_ganesh_request_origin();
		if ( $origin ) {
			d7_ganesh_send_cors_headers( $origin );
		}

		d7_ganesh_stream_csv();
		exit;
	}
}


/* =============================================================================
 * SECTION 4 — ADMIN DASHBOARD
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

/* -------------------------------------------------------------- CSV export */

/**
 * Neutralise spreadsheet formula injection. A cell starting with = + - @ or a
 * control character is executed by Excel, and these are free-text fields.
 */
if ( ! function_exists( 'd7_ganesh_csv_cell' ) ) {
	function d7_ganesh_csv_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}
}

/**
 * Write the CSV to the output buffer. Shared by the wp-admin export button and
 * the REST export route so the two can never drift apart.
 */
if ( ! function_exists( 'd7_ganesh_stream_csv' ) ) {
	function d7_ganesh_stream_csv() {
		global $wpdb;

		$rows     = $wpdb->get_results( 'SELECT * FROM ' . d7_ganesh_table() . ' ORDER BY id ASC', ARRAY_A );
		$filename = 'd7-ganesh-registrations-' . current_time( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		// BOM so Excel opens the Telugu names correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array(
			'Token',        // combined display, e.g. D7-RAMESH-X7K4 (Old: D7-RAMESH-0009)
			'New token',    // plain, for VLOOKUP
			'Old token',    // plain, blank for post-migration registrations
			'Name',
			'Phone',
			'Address',
			'Status',
			'Gift',
			'Registered at',
		) );

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$old = isset( $row['old_token'] ) ? trim( (string) $row['old_token'] ) : '';

				fputcsv( $out, array(
					d7_ganesh_csv_cell( d7_ganesh_display_token( $row ) ),
					d7_ganesh_csv_cell( d7_ganesh_public_token( $row['token'] ) ),
					d7_ganesh_csv_cell( '' === $old ? '' : d7_ganesh_public_token( $old ) ),
					d7_ganesh_csv_cell( $row['name'] ),
					d7_ganesh_csv_cell( $row['phone'] ),
					d7_ganesh_csv_cell( $row['address'] ),
					d7_ganesh_csv_cell( $row['status'] ),
					d7_ganesh_csv_cell( d7_ganesh_row_is_special( $row ) ? 'special' : 'standard' ),
					d7_ganesh_csv_cell( $row['created_at'] ),
				) );
			}
		}

		fclose( $out );
	}
}

add_action( 'admin_post_d7_ganesh_export', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to export registrations.' );
	}
	check_admin_referer( 'd7_ganesh_export' );
	d7_ganesh_stream_csv();
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

			<?php if ( ! empty( $counts['reissued'] ) ) : ?>
				<div class="notice notice-info">
					<p>
						<strong><?php echo esc_html( number_format_i18n( $counts['reissued'] ) ); ?></strong>
						early registration(s) were given a new random token. Their original
						number is shown in brackets and is fully searchable, so a devotee
						arriving with an old printed slip can still be found by that number.
					</p>
				</div>
			<?php endif; ?>

			<!-- summary cards -->
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0 22px;">
				<?php
				$cards = array(
					'Total registrations'      => $counts['total'],
					'Registered today'         => $counts['today'],
					'Special idol (first 200)' => isset( $counts['special'] ) ? $counts['special'] : 0,
					'Idol collected'           => $counts['collected'],
					'Still pending'            => $counts['pending'],
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
					       placeholder="Name, phone, token or old token"
					       style="min-width:260px;">

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
						<th style="width:230px;">Token</th>
						<th style="width:170px;">Name</th>
						<th style="width:125px;">Phone</th>
						<th>Address</th>
						<th style="width:155px;">Registered at</th>
						<th style="width:100px;">Status</th>
						<th style="width:120px;">Gift</th>
						<th style="width:185px;">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $result['rows'] ) ) : ?>
					<tr>
						<td colspan="8">
							<?php echo $search || $status
								? 'No registration matches this search.'
								: 'No registrations yet. They will appear here as soon as the form is used.'; ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $result['rows'] as $row ) :
						$id       = (int) $row['id'];
						$old      = isset( $row['old_token'] ) ? trim( (string) $row['old_token'] ) : '';
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
							<td>
								<strong><?php echo esc_html( d7_ganesh_public_token( $row['token'] ) ); ?></strong>
								<?php if ( '' !== $old ) : ?>
									<br>
									<span style="color:#646970;font-size:12px;">
										(Old: <?php echo esc_html( d7_ganesh_public_token( $old ) ); ?>)
									</span>
								<?php endif; ?>
							</td>
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
								<?php if ( d7_ganesh_row_is_special( $row ) ) : ?>
									<span style="color:#7a4a12;font-weight:600;">Special idol</span>
								<?php else : ?>
									<span>Standard idol</span>
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

		$old = isset( $row['old_token'] ) ? trim( (string) $row['old_token'] ) : '';
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( d7_ganesh_display_token( $row ) ); ?></h1>
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
							<td><strong><?php echo esc_html( d7_ganesh_public_token( $row['token'] ) ); ?></strong></td>
						</tr>
						<?php if ( '' !== $old ) : ?>
						<tr>
							<th scope="row">Original token</th>
							<td>
								<code><?php echo esc_html( d7_ganesh_public_token( $old ) ); ?></code>
								<p class="description">
									This is the number printed on the devotee's original slip.
									Accept either number at the counter.
								</p>
							</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">Gift</th>
							<td>
								<?php echo d7_ganesh_row_is_special( $row )
									? 'Special idol + Puja Samagri + Special Gift (first 200)'
									: 'Standard Ganesh idol'; ?>
							</td>
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
 * SECTION 5 — CORS
 * ========================================================================== */

if ( ! function_exists( 'd7_ganesh_allowed_origins' ) ) {
	function d7_ganesh_allowed_origins() {
		$config  = d7_ganesh_config();
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
		header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, Content-Disposition' );
		header( 'Access-Control-Max-Age: 600' );
		header( 'Vary: Origin', false );
	}
}

// Let WordPress core include the admin header in its preflight list.
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

	// Matches pretty permalinks and the ?rest_route= fallback used when the
	// site is on Plain permalinks.
	$is_d7 = ( false !== strpos( $uri, '/wp-json/d7-ganesh/' ) )
		|| ( false !== strpos( $uri, 'rest_route=/d7-ganesh/' ) )
		|| ( false !== strpos( $uri, 'rest_route=%2Fd7-ganesh%2F' ) );

	if ( ! $is_d7 ) {
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