<?php
/**
 * BizCity CRM — Admin Chat grants REST (/bizcity-crm/v1/admin-chat-grants*).
 *
 * [2026-10-20 10:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 G-C (ZBC-12) — moved here from bizcity-twin-ai/core/crm (BizCity_CRM_Spine_REST).
 * Same namespace, same paths, same handlers. The only consumer is the Grants screen of this plugin. Self-guarded: an OLD core
 * that still registers the routes wins and this class stays silent (mixed deploy).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants_REST', false ) ) :

class BizCity_CRM_Admin_Chat_Grants_REST {

	const GRANTS_VERSION_OPTION = 'bzc_crm_grants_version';
	const GRANTS_CACHE_GROUP    = 'bzc_crm_grants';
	const GRANTS_CACHE_TTL      = 300; // 5 min — version-keyed, so basically until the next mutation.

	public static function register(): void {
		// Old core still owns the routes → do nothing (never register a path twice).
		if ( class_exists( 'BizCity_CRM_Spine_REST', false ) && method_exists( 'BizCity_CRM_Spine_REST', 'admin_chat_grants_list' ) ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 9 );
		$bump = array( __CLASS__, 'bump_grants_version' );
		add_action( 'bizcity_crm_admin_chat_grant_issued', $bump );
		add_action( 'bizcity_crm_admin_chat_grant_approved', $bump );
		add_action( 'bizcity_crm_admin_chat_grant_revoked', $bump );
	}

	public static function register_routes(): void {
		$ns = defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';

		// PHASE 3.5 Wave B — Admin Chat grants (3-axis delegation).
		register_rest_route( $ns, '/admin-chat-grants', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_list' ),
			// [2026-09-21 11:10 PM OpenAI GPT-5.6 Luna] HOTFIX — grant administration must accept Super Admin/network CRM admins, not only local manage_options.
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			'args'                => array(
				'status' => array( 'type' => 'string', 'required' => false ),
				'limit'  => array( 'type' => 'integer', 'required' => false ),
			),
		) );

		// Lightweight poll endpoint: returns version + pending count only.
		register_rest_route( $ns, '/admin-chat-grants/version', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_version' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
		) );

		register_rest_route( $ns, '/admin-chat-grants/(?P<id>\d+)/approve', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_approve' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
		) );

		register_rest_route( $ns, '/admin-chat-grants/(?P<id>\d+)/revoke', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_revoke' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
		) );

		register_rest_route( $ns, '/admin-chat-grants/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'admin_chat_grants_update' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
		) );

	}

	public static function can_manage_rules(): bool {
		$cap = class_exists( 'BizCity_CRM_Capabilities' )
			? BizCity_CRM_Capabilities::CAP_MANAGE_RULES
			: 'manage_options';
		// [2026-09-21 06:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.60-C02 — network Super Admins must receive tenant-wide CRM roster scope even when the mapped blog does not grant local manage_options.
		return ( function_exists( 'is_super_admin' ) && is_super_admin() )
			|| current_user_can( $cap )
			|| current_user_can( 'manage_options' )
			|| current_user_can( 'manage_network' );
	}

	protected static function wrap( callable $fn ) {
		try {
			$data = $fn();
			if ( $data instanceof WP_REST_Response ) {
				return $data;
			}
			return new WP_REST_Response( array(
				'ok'   => true,
				'data' => $data,
				'ts'   => (int) round( microtime( true ) * 1000 ),
			), 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array(
				'ok'    => false,
				'error' => array(
					'code'    => 'crm_exception',
					'message' => $e->getMessage(),
				),
				'ts'    => (int) round( microtime( true ) * 1000 ),
			), 500 );
		}
	}

	protected static function grants_version(): int {
		$v = (int) get_option( self::GRANTS_VERSION_OPTION, 0 );
		if ( $v <= 0 ) {
			$v = time();
			update_option( self::GRANTS_VERSION_OPTION, $v, false );
		}
		return $v;
	}

	public static function bump_grants_version(): int {
		$v = time();
		update_option( self::GRANTS_VERSION_OPTION, $v, false );
		return $v;
	}

	public static function admin_chat_grants_version( WP_REST_Request $req ) {
		return self::wrap( static function () {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) || ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_Admin_Chat_Grants::table() ) ) {
				return array( 'version' => 0, 'pending' => 0 );
			}
			$ver = self::grants_version();
			$ck  = 'pending_count_v' . $ver;
			$pending = wp_cache_get( $ck, self::GRANTS_CACHE_GROUP );
			if ( false === $pending ) {
				global $wpdb;
				$tbl = BizCity_CRM_Admin_Chat_Grants::table();
				$pending = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tbl . ' WHERE status="pending"' );
				wp_cache_set( $ck, $pending, self::GRANTS_CACHE_GROUP, self::GRANTS_CACHE_TTL );
			}
			return array( 'version' => (int) $ver, 'pending' => (int) $pending );
		} );
	}

	public static function admin_chat_grants_list( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) || ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_Admin_Chat_Grants::table() ) ) {
				return array( 'rows' => array(), 'counts' => array( 'pending' => 0, 'active' => 0, 'revoked' => 0 ), 'version' => 0 );
			}
			$status = sanitize_key( (string) $req->get_param( 'status' ) );
			$limit  = (int) $req->get_param( 'limit' );
			if ( $limit <= 0 || $limit > 200 ) { $limit = 50; }
			if ( ! in_array( $status, array( 'active', 'pending', 'revoked' ), true ) ) { $status = ''; }

			$ver = self::grants_version();
			$ck  = 'list_v' . $ver . '_s' . $status . '_l' . $limit;
			$cached = wp_cache_get( $ck, self::GRANTS_CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			global $wpdb;
			$tbl   = BizCity_CRM_Admin_Chat_Grants::table();
			$where = '1=1';
			if ( '' !== $status ) {
				$where = $wpdb->prepare( 'status = %s', $status );
			}
			$rows = $wpdb->get_results(
				'SELECT * FROM ' . $tbl . ' WHERE ' . $where
				. ' ORDER BY (status="pending") DESC, created_at DESC LIMIT ' . (int) $limit,
				ARRAY_A
			) ?: array();

			$count_rows = $wpdb->get_results(
				'SELECT status, COUNT(*) AS n FROM ' . $tbl . ' GROUP BY status',
				ARRAY_A
			) ?: array();
			$counts = array( 'pending' => 0, 'active' => 0, 'revoked' => 0 );
			foreach ( $count_rows as $r ) {
				$counts[ $r['status'] ] = (int) $r['n'];
			}

			$shaped = array_map( array( __CLASS__, 'shape_admin_chat_grant' ), $rows );
			$out = array( 'rows' => $shaped, 'counts' => $counts, 'version' => (int) $ver );
			wp_cache_set( $ck, $out, self::GRANTS_CACHE_GROUP, self::GRANTS_CACHE_TTL );
			return $out;
		} );
	}

	public static function admin_chat_grants_approve( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) || ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_Admin_Chat_Grants::table() ) ) { throw new \RuntimeException( 'grants_unavailable' ); }
			$id = (int) $req->get_param( 'id' );
			if ( $id <= 0 ) { throw new \RuntimeException( 'invalid_id' ); }
			global $wpdb;
			$ok = $wpdb->update(
				BizCity_CRM_Admin_Chat_Grants::table(),
				array(
					'status'             => BizCity_CRM_Admin_Chat_Grants::STATUS_ACTIVE,
					'granted_by_user_id' => get_current_user_id(),
					'granted_at'         => current_time( 'mysql' ),
					'updated_at'         => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			if ( $ok === false ) { throw new \RuntimeException( $wpdb->last_error ?: 'update_failed' ); }
			self::bump_grants_version();
			do_action( 'bizcity_crm_admin_chat_grant_approved', $id, get_current_user_id() );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BizCity_CRM_Admin_Chat_Grants::table() . ' WHERE id=%d', $id ), ARRAY_A );
			return self::shape_admin_chat_grant( (array) $row );
		} );
	}

	public static function admin_chat_grants_revoke( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) || ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_Admin_Chat_Grants::table() ) ) { throw new \RuntimeException( 'grants_unavailable' ); }
			$id = (int) $req->get_param( 'id' );
			if ( $id <= 0 ) { throw new \RuntimeException( 'invalid_id' ); }
			BizCity_CRM_Admin_Chat_Grants::revoke( $id, get_current_user_id() );
			self::bump_grants_version();
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BizCity_CRM_Admin_Chat_Grants::table() . ' WHERE id=%d', $id ), ARRAY_A );
			return self::shape_admin_chat_grant( (array) $row );
		} );
	}

	public static function admin_chat_grants_update( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) || ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_Admin_Chat_Grants::table() ) ) { throw new \RuntimeException( 'grants_unavailable' ); }
			$id = (int) $req->get_param( 'id' );
			if ( $id <= 0 ) { throw new \RuntimeException( 'invalid_id' ); }
			$body = (array) $req->get_json_params();

			$update = array( 'updated_at' => current_time( 'mysql' ) );
			$fmt    = array( '%s' );
			foreach ( array( 'allow_producer', 'allow_retriever', 'allow_distributor' ) as $f ) {
				if ( array_key_exists( $f, $body ) ) {
					$update[ $f ] = ! empty( $body[ $f ] ) ? 1 : 0;
					$fmt[]        = '%d';
				}
			}
			if ( array_key_exists( 'quota_per_day', $body ) ) {
				$update['quota_per_day'] = max( 0, (int) $body['quota_per_day'] );
				$fmt[]                    = '%d';
			}
			if ( array_key_exists( 'tool_overrides_json', $body ) ) {
				$ov = $body['tool_overrides_json'];
				if ( is_array( $ov ) ) {
					$clean = array();
					foreach ( $ov as $tool => $verb ) {
						$verb = strtolower( (string) $verb );
						if ( in_array( $verb, array( 'allow', 'confirm', 'deny' ), true ) ) {
							$clean[ sanitize_key( (string) $tool ) ] = $verb;
						}
					}
					$update['tool_overrides_json'] = $clean ? wp_json_encode( $clean ) : null;
				} else {
					$update['tool_overrides_json'] = null;
				}
				$fmt[] = '%s';
			}

			global $wpdb;
			$ok = $wpdb->update(
				BizCity_CRM_Admin_Chat_Grants::table(),
				$update,
				array( 'id' => $id ),
				$fmt,
				array( '%d' )
			);
			if ( $ok === false ) { throw new \RuntimeException( $wpdb->last_error ?: 'update_failed' ); }
			self::bump_grants_version();
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BizCity_CRM_Admin_Chat_Grants::table() . ' WHERE id=%d', $id ), ARRAY_A );
			return self::shape_admin_chat_grant( (array) $row );
		} );
	}

	protected static function shape_admin_chat_grant( array $row ): array {
		$user_id = (int) ( $row['user_id'] ?? 0 );
		$user    = $user_id ? get_user_by( 'id', $user_id ) : null;
		$char_id = (int) ( $row['character_id'] ?? 0 );
		$guru_name = '';
		if ( $char_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
			try {
				$kdb = BizCity_Knowledge_Database::instance();
				if ( method_exists( $kdb, 'get_character' ) ) {
					$c = $kdb->get_character( $char_id );
					if ( is_object( $c ) ) { $guru_name = (string) ( $c->name ?? '' ); }
				}
			} catch ( \Throwable $e ) { /* ignore */ }
		}
		$overrides = null;
		if ( ! empty( $row['tool_overrides_json'] ) ) {
			$decoded = json_decode( (string) $row['tool_overrides_json'], true );
			if ( is_array( $decoded ) ) { $overrides = $decoded; }
		}
		return array(
			'id'                  => (int) $row['id'],
			'user_id'             => $user_id,
			'user_login'          => $user ? $user->user_login : '',
			'user_name'           => $user ? ( $user->display_name ?: $user->user_login ) : '',
			'user_email'          => $user ? $user->user_email : '',
			'character_id'        => $char_id,
			'character_name'      => $guru_name,
			'platform'            => (string) ( $row['platform'] ?? '' ),
			'chat_id'             => (string) ( $row['chat_id'] ?? '' ),
			'channel_binding_id'  => isset( $row['channel_binding_id'] ) ? (int) $row['channel_binding_id'] : null,
			'status'              => (string) ( $row['status'] ?? '' ),
			'allow_producer'      => (int) ( $row['allow_producer'] ?? 0 ),
			'allow_retriever'     => (int) ( $row['allow_retriever'] ?? 0 ),
			'allow_distributor'   => (int) ( $row['allow_distributor'] ?? 0 ),
			'quota_per_day'       => (int) ( $row['quota_per_day'] ?? 0 ),
			'quota_used_today'    => (int) ( $row['quota_used_today'] ?? 0 ),
			'quota_reset_at'      => $row['quota_reset_at'] ?? null,
			'tool_overrides'      => $overrides,
			'granted_at'          => $row['granted_at'] ?? null,
			'granted_by_user_id'  => (int) ( $row['granted_by_user_id'] ?? 0 ),
			'created_at'          => $row['created_at'] ?? null,
			'updated_at'          => $row['updated_at'] ?? null,
		);
	}

}

endif;
