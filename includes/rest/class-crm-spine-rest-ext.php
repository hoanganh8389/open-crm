<?php
/**
 * BizCity CRM — the CRM-plugin half of the bizcity-crm/v1 spine REST.
 *
 * [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.2 (census evidence/crm-spine-routes.md) — the 29 routes only the CRM
 * plugin's own screens call (teams, labels, channels, inbox admin, conversation actions, contact care…) moved here from
 * core/crm/includes/rest/class-crm-spine-rest.php by bin/php-static-class-split.mjs (--mode move-rest, KEEP list = the 11 routes
 * Channel Gateway / Bot Studio / MCP call). Shared helpers (wrap, scope checks, shape_*) stay in the core parent class.
 * BizCity_CRM_REST_Controller extends this class, so every method it inherited before is still inherited.
 *
 * Historical header of the original controller:
 *
 * Namespace: bizcity-crm/v1
 *
 * Endpoints:
 *   GET /channels                            — adapters registered
 *   GET /inboxes                             — list inboxes
 *   GET /conversations?inbox_id&status&...   — list conversations
 *   GET /conversations/(?P<id>\d+)           — one conversation
 *   GET /conversations/(?P<id>\d+)/messages  — list messages
 *
 * Response shape (R-CRM-5):
 *   { ok:true, data:..., next_cursor:?string, ts: epoch_ms }
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Spine_REST_Ext extends BizCity_CRM_Spine_REST {

	// [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.2 — CONTACT_ACTIVITY_TYPES is inherited from the core parent (not redeclared).

	/**
	 * [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.2 — not named register_routes(): BizCity_CRM_REST_Controller (a subclass) has its own register_routes(), which would shadow it.
	 * Mixed deploy: an OLD core still declares and registers these routes itself ⇒ register nothing (no double registration).
	 */
	public static function register_ext_routes(): void {
		if ( method_exists( 'BizCity_CRM_Spine_REST', 'get_teams' ) ) {
			return;
		}
		$ns = BIZCITY_CRM_REST_NS;

		// [2026-08-04 Johnny Chu] PHASE-0.48-INBOX-CLEANUP — allow only marked test/diagnostic inboxes to be removed.
		register_rest_route( $ns, '/inboxes/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_inbox' ),
			// [2026-08-04 Johnny Chu] PHASE-0.48-INBOX-CLEANUP — destructive inbox cleanup is admin-only.
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		) );

		// [2026-08-22 Johnny Chu] PHASE-0.39C — explicit legacy Zalo Personal cleanup; never broaden the generic inbox delete route.
		register_rest_route( $ns, '/inboxes/(?P<id>\d+)/zalo-legacy', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_legacy_zalo_inbox' ),
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		) );

		// M7.W4 — runtime health for nav sidebar dot.
		register_rest_route( $ns, '/inboxes/(?P<id>\d+)/health', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_inbox_health' ),
			'permission_callback' => array( __CLASS__, 'can_read_inbox_id_scope' ),
		) );

		// [2026-08-22 Johnny Chu] PHASE-0.39C — read-only Zalo Personal flow diagnostic for CRM operators.
		register_rest_route( $ns, '/inboxes/(?P<id>\d+)/zalo-diagnostic', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_zalo_diagnostic' ),
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		) );

		// [2026-10-28 06:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L2 (D146-16) — ids of every conversation matching the list filters (≤ 1 000).
		register_rest_route( $ns, '/conversations/ids', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_conversation_ids' ),
			'permission_callback' => array( __CLASS__, 'can_read_inbox_scope' ),
		) );

		// [2026-09-26 PHASE-0.63C GC-21.4] Per-role thread counts for the Inbox "Vai:" chips.
		register_rest_route( $ns, '/conversations/role-counts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_conversation_role_counts' ),
			'permission_callback' => array( __CLASS__, 'can_read_inbox_scope' ),
			'args'                => array(
				'inbox_id'    => array( 'type' => 'integer' ),
				'status'      => array( 'type' => 'string', 'enum' => array( 'open', 'pending', 'resolved', 'snoozed' ) ),
				'thread_kind' => array( 'type' => 'string', 'enum' => array( 'group', 'personal' ) ),
			),
		) );

		// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.48-§2.11.5 — upload a clipboard-pasted image into WP Media so Composer can attach its public URL exactly like the existing wp.media picker (post_message() already accepts a public Media Library URL, unchanged).
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/composer-image', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_composer_image' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
		) );

		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48-H2.1 — re-dispatch one failed outbound row in place; never inserts a second message.
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/messages/(?P<message_id>\d+)/retry', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_message_retry' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/group-members', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_group_members' ),
			'permission_callback' => array( __CLASS__, 'can_read_inbox_scope' ),
		) );

		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48B-8.11 — contact-scoped care tools; contact_id is resolved from the scoped conversation, never posted.
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/contact-care', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_contact_care' ),
				'permission_callback' => array( __CLASS__, 'can_read_inbox_scope' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_contact_care' ),
				'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
				'args'                => array(
					'kind'              => array( 'type' => 'string', 'required' => true, 'enum' => array( 'note', 'task', 'appointment' ) ),
					'content'           => array( 'type' => 'string', 'required' => true ),
					'due_at'            => array( 'type' => 'integer' ),
					'reminder_min'      => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10080 ),
					'client_request_id' => array( 'type' => 'string' ),
				),
			),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/contact-facts', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_contact_fact' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
			'args'                => array(
				'field' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'phone', 'email', 'name' ) ),
				'value' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — expose canonical conversation triage mutations.
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/assignee', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'post_assign' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
			'args'                => array(
				'assignee_id' => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );

		// [2026-08-24 Johnny Chu] PHASE-0.39F-F4-F5 — BE Teams and inbox membership commands; all IDs are revalidated server-side.
		register_rest_route( $ns, '/teams', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_teams' ),
				'permission_callback' => array( __CLASS__, 'can_manage_teams' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_team' ),
				'permission_callback' => array( __CLASS__, 'can_manage_teams' ),
			)
		) );

		// [2026-09-23] PHASE-0.71 F71-10 / 0.63C GC-6 — the update path `settings_json.purpose`
		// never had: `upsert_inbox()` only writes settings once, at creation.
		register_rest_route( $ns, '/inboxes/(?P<id>\d+)/purpose', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'patch_inbox_purpose' ),
			'permission_callback' => array( __CLASS__, 'can_manage_inbox_settings' ),
			'args'                => array(
				'purpose' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/inboxes/(?P<id>\d+)/members', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_inbox_member' ),
			'permission_callback' => array( __CLASS__, 'can_manage_teams' ),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/priority', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'post_priority' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
			'args'                => array(
				'priority' => array( 'type' => 'integer', 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/reopen', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'post_reopen' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
		) );

		// PHASE 0.35 M1.W4 — snooze a conversation until N seconds OR ISO ts.
		register_rest_route( $ns, '/conversations/(?P<id>\d+)/snooze', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_snooze' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
			'args'                => array(
				'duration_seconds' => array( 'type' => 'integer' ),
				'until'            => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/unsnooze', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_unsnooze' ),
			'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
		) );

		register_rest_route( $ns, '/contacts/(?P<id>\d+)/enrich', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'post_contact_enrich' ),
			'permission_callback' => array( __CLASS__, 'can_write_contact_scope' ),
			'args'                => array( 'conversation_id' => array( 'type' => 'integer', 'default' => 0 ) ),
		) );

		register_rest_route( $ns, '/contacts/(?P<id>\d+)/birthday', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'put_contact_birthday' ),
			'permission_callback' => array( __CLASS__, 'can_write_contact_scope' ),
		) );

		register_rest_route( $ns, '/contacts/(?P<id>\d+)/enrichment', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_contact_enrichment' ),
			'permission_callback' => array( __CLASS__, 'can_write_contact_scope' ),
		) );

		register_rest_route( $ns, '/labels', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_labels' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_label' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );

		register_rest_route( $ns, '/labels/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_label' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'put_label' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_label' ),
				'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			),
		) );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)/labels', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_conversation_labels' ),
				'permission_callback' => array( __CLASS__, 'can_read_inbox_scope' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_conversation_labels' ),
				'permission_callback' => array( __CLASS__, 'can_write_inbox_scope' ),
				'args'                => array(
					'labels' => array( 'type' => 'array', 'required' => true ),
				),
			),
		) );

		// [2026-08-23 Johnny Chu] PHASE-0.39D — contact-to-inbox navigation projection.
		register_rest_route( $ns, '/crm-contacts/(?P<id>\d+)/channels', array(
			'methods'              => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_contact_channels' ),
			'permission_callback' => array( __CLASS__, 'can_read_contact_scope' ),
		) );

		// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — assignable users for call agent picker + bulk-assign
		register_rest_route( $ns, '/crm-settings/assignable-users', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_assignable_users' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );

		// [2026-09-08 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — resolve B2 selected-user scope and Contacts through the canonical CRM owner.
		register_rest_route( $ns, '/crm-settings/user-inbox-scope', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_user_inbox_scope' ),
			'permission_callback' => array( __CLASS__, 'can_manage_rules' ),
			'args'                => array(
				'user_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
				'limit' => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 200 ),
			),
		) );

		// [2026-09-16 01:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48D-USER-RAIL — B2 management tier groups the Inbox rail by WordPress user instead of by channel; C /gpt/ stays current-user-only.
		register_rest_route( $ns, '/crm-settings/inbox-user-groups', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_crm_inbox_user_groups' ),
			'permission_callback' => array( __CLASS__, 'can_view_inbox_user_groups' ),
		) );
	}

	/** PHASE-0.48F T1 — rail "Theo người dùng": rule managers (tenant-wide, as before) or a team lead/supervisor (scoped in the callback). */
	public static function can_view_inbox_user_groups(): bool {
		if ( function_exists( 'is_super_admin' ) && is_super_admin() ) { return true; }
		if ( self::can_manage_rules() ) { return true; }
		return class_exists( 'BizCity_CRM_Staff_Policy' )
			&& BizCity_CRM_Staff_Policy::rank( BizCity_CRM_Staff_Policy::role( get_current_user_id() ) ) >= 2;
	}

	public static function can_manage_teams(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'bizcity_crm_manage_teams' );
	}

	/** Same admin tier as inbox creation (`can_read()`'s two admin checks, minus the broader staff-scope leg). */
	public static function can_manage_inbox_settings(): bool {
		return current_user_can( 'manage_options' )
			|| ( class_exists( 'BizCity_CRM_Inbox_Access' ) && BizCity_CRM_Inbox_Access::is_admin() );
	}

	/**
	 * Read scope for routes whose `id` path param is an Inbox ID, not a conversation ID.
	 */
	public static function can_read_inbox_id_scope( $request = null ): bool {
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.49-E2 — /inboxes/{id}/* must not resolve `id` as a conversation.
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) { return false; }
		$inbox_id = $request instanceof WP_REST_Request ? (int) $request->get_param( 'id' ) : 0;
		return $inbox_id > 0 && BizCity_CRM_Inbox_Access::can_view_inbox( $inbox_id, $user_id );
	}

	public static function get_channels( WP_REST_Request $req ) {
		return self::wrap( static function () {
			$out = array();
			foreach ( BizCity_CRM_Channel_Registry::all() as $a ) {
				$has_wizard = method_exists( $a, 'setup_form_schema' );
				$out[] = array(
					'code'          => $a->code(),
					'label'         => $a->label(),
					'capabilities'  => $a->capabilities(),
					'wizard_ready'  => $has_wizard,
					'contract'      => class_exists( 'BizCity_CRM_Channel_Contract' ) ? BizCity_CRM_Channel_Contract::describe( $a->code() ) : array(),
				);
			}
			return $out;
		} );
	}

	public static function get_teams( WP_REST_Request $req ) {
		return self::wrap( static function () {
			if ( ! class_exists( 'BizCity_CRM_Team_Manager' ) ) { throw new \RuntimeException( 'team_manager_not_loaded' ); }
			return array( 'teams' => BizCity_CRM_Team_Manager::list_teams() );
		} );
	}

	/** PHASE-0.71 F71-10 / 0.63C GC-6 — `PATCH /inboxes/{id}/purpose`. */
	public static function patch_inbox_purpose( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$inbox_id = (int) $req['id'];
			$purpose  = sanitize_key( (string) $req->get_param( 'purpose' ) );
			if ( ! in_array( $purpose, BizCity_CRM_Repository::INBOX_PURPOSES, true ) ) {
				throw new \InvalidArgumentException( 'purpose_invalid: expected one of ' . implode( ', ', BizCity_CRM_Repository::INBOX_PURPOSES ) );
			}
			if ( ! BizCity_CRM_Repository::get_inbox( $inbox_id ) ) {
				throw new \RuntimeException( 'inbox_not_found' );
			}
			if ( ! BizCity_CRM_Repository::set_inbox_purpose( $inbox_id, $purpose ) ) {
				throw new \RuntimeException( 'inbox_purpose_save_failed' );
			}
			return array( 'inbox_id' => $inbox_id, 'purpose' => $purpose );
		} );
	}

	public static function post_team( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$name = sanitize_text_field( (string) ( $req->get_param( 'name' ) ?? '' ) );
			$id = BizCity_CRM_Team_Manager::create_team( $name, sanitize_textarea_field( (string) ( $req->get_param( 'description' ) ?? '' ) ), (int) get_current_user_id() );
			if ( $id <= 0 ) { throw new \RuntimeException( 'team_create_failed' ); }
			return array( 'team_id' => $id );
		} );
	}

	public static function get_team_members( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			return array( 'members' => BizCity_CRM_Team_Manager::list_team_members( (int) $req['id'] ) );
		} );
	}

	public static function post_team_member( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$user_id = (int) $req->get_param( 'user_id' );
			$ok = BizCity_CRM_Team_Manager::add_team_member( (int) $req['id'], $user_id, sanitize_key( (string) ( $req->get_param( 'member_role' ) ?? 'agent' ) ) );
			if ( ! $ok ) { throw new \RuntimeException( 'team_member_save_failed' ); }
			return array( 'saved' => true, 'team_id' => (int) $req['id'], 'user_id' => $user_id );
		} );
	}

	public static function post_inbox_member( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$user_id = (int) $req->get_param( 'user_id' );
			$ok = BizCity_CRM_Team_Manager::add_inbox_member( (int) $req['id'], $user_id, sanitize_key( (string) ( $req->get_param( 'member_role' ) ?? 'agent' ) ), null === $req->get_param( 'can_assign' ) ? null : ! empty( $req->get_param( 'can_assign' ) ) );
			if ( ! $ok ) { throw new \RuntimeException( 'inbox_member_save_failed' ); }
			return array( 'saved' => true, 'inbox_id' => (int) $req['id'], 'user_id' => $user_id );
		} );
	}

	public static function post_team_assign( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$team_id = max( 0, (int) $req->get_param( 'team_id' ) );
			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			if ( $team_id > 0 && ! current_user_can( 'manage_options' ) ) {
				$members = BizCity_CRM_Team_Manager::list_team_members( $team_id );
				$inbox_id = (int) ( $conv['inbox_id'] ?? 0 );
				$has_member = false;
				foreach ( $members as $member ) {
					if ( (int) ( $member['user_id'] ?? 0 ) > 0 && BizCity_CRM_Team_Manager::is_inbox_member( $inbox_id, (int) $member['user_id'] ) ) { $has_member = true; break; }
				}
				if ( ! $has_member ) { throw new \RuntimeException( 'team_has_no_inbox_member' ); }
			}
			if ( ! BizCity_CRM_Repository::set_conversation_team( $conv_id, $team_id > 0 ? $team_id : null, (int) get_current_user_id() ) ) { throw new \RuntimeException( 'team_assignment_failed' ); }
			return array( 'updated' => true, 'team_id' => $team_id ?: null );
		} );
	}

	/** M7.W1 — return full setup_form_schema for one channel code. */
	public static function get_channel_detail( WP_REST_Request $req ) {
		$blocked = self::channel_setup_error( (string) $req['code'] );
		if ( $blocked ) { return $blocked; }
		return self::wrap( static function () use ( $req ) {
			$code = (string) $req['code'];
			$a = BizCity_CRM_Channel_Registry::get( $code );
			if ( ! $a ) {
				throw new \RuntimeException( 'channel_not_found' );
			}
			$schema = method_exists( $a, 'setup_form_schema' ) ? $a->setup_form_schema() : array( 'fields' => array() );
			return array(
				'code'         => $a->code(),
				'label'        => $a->label(),
				'capabilities' => $a->capabilities(),
				'contract'     => class_exists( 'BizCity_CRM_Channel_Contract' ) ? BizCity_CRM_Channel_Contract::describe( $a->code() ) : array(),
				'schema'       => $schema,
			);
		} );
	}

	/** M7.W1 — verify wizard form submission against the channel API. */
	public static function post_channel_verify( WP_REST_Request $req ) {
		$blocked = self::channel_setup_error( (string) $req['code'] );
		if ( $blocked ) { return $blocked; }
		return self::wrap( static function () use ( $req ) {
			$code   = (string) $req['code'];
			$config = $req->get_param( 'config' );
			if ( ! is_array( $config ) ) { $config = array(); }

			$a = BizCity_CRM_Channel_Registry::get( $code );
			if ( ! $a ) {
				throw new \RuntimeException( 'channel_not_found' );
			}
			if ( ! method_exists( $a, 'verify' ) ) {
				return array( 'ok' => true, 'name' => $a->label(), 'hints' => array( 'No verify implemented.' ) );
			}
			return $a->verify( $config );
		} );
	}

	/** [2026-08-04 Johnny Chu] PHASE-0.48-INBOX-CLEANUP — delete a marked test inbox and its owned conversation data. */
	public static function delete_inbox( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			$inbox = BizCity_CRM_Repository::get_inbox( $id );
			if ( ! $inbox ) {
				throw new \RuntimeException( 'inbox_not_found' );
			}
			if ( ! BizCity_CRM_Repository::is_test_inbox( $inbox ) ) {
				throw new \RuntimeException( 'inbox_delete_requires_test_marker' );
			}
			if ( ! BizCity_CRM_Repository::delete_inbox( $id ) ) {
				throw new \RuntimeException( 'inbox_delete_failed' );
			}
			return array( 'deleted' => true, 'inbox_id' => $id );
		} );
	}

	/** Delete a stale direct Zalo Personal CRM channel without touching a managed mapping. */
	public static function delete_legacy_zalo_inbox( WP_REST_Request $req ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39C — expose a bounded cleanup action for old direct zca channels.
		$id     = (int) $req['id'];
		$result = BizCity_CRM_Repository::delete_legacy_zalo_personal_inbox( $id );
		if ( 'deleted' === $result ) {
			return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'deleted' => true, 'inbox_id' => $id ) ), 200 );
		}
		$messages = array(
			'inbox_not_found'       => array( 'code' => 'not_found', 'message' => 'Không tìm thấy kênh Zalo Personal.', 'hint' => 'Làm mới danh sách kênh rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' ),
			'zalo_personal_only'    => array( 'code' => 'invalid_param', 'message' => 'Chỉ được xóa kênh Zalo Personal cũ.', 'hint' => 'Chọn đúng kênh Zalo Personal trong CRM.', 'help_code' => 'invalid_param_generic' ),
			'managed_mapping_exists' => array( 'code' => 'permission_denied', 'message' => 'Kênh đang được account managed sử dụng.', 'hint' => 'Xóa hoặc ngắt account tại Channel Gateway trước.', 'help_code' => 'permission_denied' ),
			'managed_scope_unavailable' => array( 'code' => 'gateway_degraded', 'message' => 'Chưa xác minh được phạm vi account managed.', 'hint' => 'Kiểm tra Hub/1API rồi thử lại để tránh xóa nhầm kênh.', 'help_code' => 'zalo_bridge_unreachable' ),
		);
		$error = $messages[ $result ] ?? array( 'code' => 'crm_exception', 'message' => 'Không xóa được kênh Zalo Personal.', 'hint' => 'Kiểm tra log CRM rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' );
		return new WP_REST_Response( array( 'ok' => false, 'error' => $error ), 'managed_mapping_exists' === $result ? 409 : 400 );
	}

	/** M7.W4 — health snapshot for one inbox. */
	public static function get_inbox_health( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			$inbox = BizCity_CRM_Repository::get_inbox( $id );
			if ( ! $inbox ) {
				throw new \RuntimeException( 'inbox_not_found' );
			}
			$a = BizCity_CRM_Channel_Registry::get( (string) $inbox['channel_type'] );
			if ( ! $a || ! method_exists( $a, 'health' ) ) {
				return array(
					'status'          => 'unknown',
					'last_inbound_at' => null,
					'last_error'      => null,
					'details'         => array( 'reason' => 'adapter_no_health' ),
				);
			}
			return $a->health( $inbox );
		} );
	}

	/** Read-only diagnostic for the sidecar -> Hub -> client callback -> CRM path. */
	public static function get_zalo_diagnostic( WP_REST_Request $req ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39C — expose bounded flow evidence without sending a Zalo message or returning credentials/PII.
		$id    = (int) $req['id'];
		$inbox = BizCity_CRM_Repository::get_inbox( $id );
		if ( ! $inbox ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy CRM Inbox.', 'hint' => 'Làm mới danh sách inbox rồi thử lại.', 'help_code' => 'zalo_bridge_bad_response' ), 404 );
		}
		if ( 'zalo_personal' !== strtolower( (string) ( $inbox['channel_type'] ?? '' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'invalid_param', 'message' => 'Inbox này không phải Zalo Personal.', 'hint' => 'Chọn đúng inbox Zalo Personal để kiểm tra flow.', 'help_code' => 'invalid_param_generic' ), 400 );
		}

		$checks = array();
		$checks['crm_inbox'] = array(
			'status' => ! empty( $inbox['is_active'] ) ? 'PASS' : 'FAIL',
			'reason' => ! empty( $inbox['is_active'] ) ? 'active_inbox' : 'inactive_inbox',
		);
		$adapter = class_exists( 'BizCity_CRM_Channel_Registry' ) ? BizCity_CRM_Channel_Registry::get( 'zalo_personal' ) : null;
		$checks['adapter'] = array(
			'status' => $adapter && method_exists( $adapter, 'normalize_inbound' ) && method_exists( $adapter, 'send' ) ? 'PASS' : 'FAIL',
			'reason' => $adapter ? 'adapter_loaded' : 'adapter_missing',
		);

		$mapping = null;
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'find_account_by_crm_inbox_id' ) ) {
			$mapping = BizCity_Zalo_Mapping_Repo::find_account_by_crm_inbox_id( $id );
		}
		$checks['mapping'] = array(
			'status' => is_array( $mapping ) && (string) ( $mapping['bridge_account_id'] ?? '' ) === (string) ( $inbox['channel_ref_id'] ?? '' ) ? 'PASS' : 'FAIL',
			'reason' => is_array( $mapping ) ? 'account_inbox_match' : 'account_inbox_mapping_missing',
		);

		$bridge = null;
		if ( class_exists( 'BizCity_Zalo_Bridge_Client' ) && method_exists( 'BizCity_Zalo_Bridge_Client', 'instance' ) ) {
			$bridge = BizCity_Zalo_Bridge_Client::instance();
		}
		$bridge_result = $bridge && method_exists( $bridge, 'health' ) ? $bridge->health() : array( 'success' => false, 'code' => 'bridge_client_missing' );
		$bridge_ok = ! empty( $bridge_result['success'] ) || ! empty( $bridge_result['ok'] );
		$checks['bridge'] = array(
			'status'     => $bridge_ok && empty( $bridge_result['_degraded'] ) ? 'PASS' : 'FAIL',
			'reason'     => (string) ( $bridge_result['code'] ?? ( $bridge_ok ? 'ok' : 'bridge_unavailable' ) ),
			'mode'       => $bridge && method_exists( $bridge, 'get_mode' ) ? $bridge->get_mode() : '',
			'http_code'  => isset( $bridge_result['http_code'] ) ? (int) $bridge_result['http_code'] : 0,
			'key_id'     => isset( $bridge_result['key_id'] ) ? (int) $bridge_result['key_id'] : 0,
			'domain_set' => array_key_exists( 'domain_set', $bridge_result ) ? (bool) $bridge_result['domain_set'] : null,
		);
		$checks['managed_account'] = array( 'status' => 'SKIP', 'reason' => 'custom_bridge_or_unchecked' );
		if ( $bridge && method_exists( $bridge, 'get_mode' ) && 'managed_1api' === $bridge->get_mode() && method_exists( $bridge, 'list_accounts' ) ) {
			$remote_accounts = $bridge->list_accounts();
			$remote_ok = ! empty( $remote_accounts['success'] ) && empty( $remote_accounts['_degraded'] );
			$managed_match = false;
			if ( $remote_ok ) {
				foreach ( (array) ( $remote_accounts['accounts'] ?? array() ) as $remote_account ) {
					if ( (string) ( $remote_account['id'] ?? '' ) === (string) ( $inbox['channel_ref_id'] ?? '' ) ) { $managed_match = true; break; }
				}
			}
			$checks['managed_account'] = array(
				'status' => ! $remote_ok ? 'FAIL' : ( $managed_match ? 'PASS' : 'FAIL' ),
				'reason' => ! $remote_ok ? 'managed_account_scope_unavailable' : ( $managed_match ? 'exact_key_account_found' : 'account_not_in_exact_key_scope' ),
			);
		}
		$signal = array( 'inbound' => 0, 'outbound' => 0, 'last_inbound_at' => null, 'last_outbound_at' => null, 'last_status' => null );
		if ( class_exists( 'BizCity_Zalo_Hook_Log' ) ) {
			foreach ( BizCity_Zalo_Hook_Log::read( 100 ) as $row ) {
				if ( (string) ( $row['account_id'] ?? '' ) !== (string) ( $inbox['channel_ref_id'] ?? '' ) ) { continue; }
				$dir = (string) ( $row['dir'] ?? '' );
				$at  = isset( $row['ts'] ) ? (int) $row['ts'] : 0;
				if ( 'inbound' === $dir ) {
					$signal['inbound']++;
					if ( null === $signal['last_inbound_at'] ) { $signal['last_inbound_at'] = $at > 0 ? gmdate( 'c', $at ) : null; }
				}
				if ( 'outbound' === $dir ) {
					$signal['outbound']++;
					if ( null === $signal['last_outbound_at'] ) { $signal['last_outbound_at'] = $at > 0 ? gmdate( 'c', $at ) : null; }
				}
				if ( null === $signal['last_status'] ) { $signal['last_status'] = (string) ( $row['status'] ?? '' ); }
			}
		}
		$checks['inbound_signal'] = array(
			'status' => $signal['inbound'] > 0 ? 'PASS' : 'SKIP',
			'reason' => $signal['inbound'] > 0 ? 'inbound_observed' : 'no_inbound_observed',
		);
		$checks['outbound_signal'] = array(
			'status' => $signal['outbound'] > 0 ? 'PASS' : 'SKIP',
			'reason' => $signal['outbound'] > 0 ? 'outbound_observed' : 'no_outbound_observed',
		);

		$has_fail = false;
		foreach ( $checks as $check ) {
			if ( 'FAIL' === $check['status'] ) { $has_fail = true; break; }
		}
		return new WP_REST_Response( array(
			'ok'       => ! $has_fail,
			'overall'  => $has_fail ? 'FAIL' : 'PASS',
			'inbox_id' => $id,
			'checks'   => $checks,
			'signal'   => $signal,
			'next'     => $has_fail ? 'Sửa check FAIL trước rồi chạy lại.' : 'Gửi một tin test và chạy lại để chuyển signal SKIP thành PASS.',
		), 200 );
	}

	/** [2026-09-26 PHASE-0.63C GC-21.4] `GET /conversations/role-counts` — counts per contact role inside the caller's inbox scope. */
	public static function get_conversation_role_counts( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_conversations() ) ) {
				return array( 'total' => 0, 'none' => 0 );
			}
			$status = (string) $req->get_param( 'status' );
			$args   = array(
				'inbox_id'    => (int) $req->get_param( 'inbox_id' ),
				'status'      => in_array( $status, array( 'open', 'pending', 'resolved', 'snoozed' ), true ) ? $status : '',
				'thread_kind' => in_array( (string) $req->get_param( 'thread_kind' ), array( 'group', 'personal' ), true ) ? (string) $req->get_param( 'thread_kind' ) : '',
			);
			if ( class_exists( 'BizCity_CRM_Inbox_Access' ) ) {
				$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids();
				if ( is_array( $allowed ) ) { $args['inbox_ids'] = $allowed; }
			}
			return BizCity_CRM_Repository::count_conversations_by_role( $args );
		} );
	}

	/**
	 * [2026-10-28 06:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L2 (D146-16) — GET conversations/ids: every id matching the current
	 * list filters (≤ 1 000) + the true total. `stage` is a computed filter (R-PIPE-2) and is not applied here: `ignored` says so.
	 */
	public static function get_conversation_ids( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_conversations() ) ) {
				return array( 'ids' => array(), 'total' => 0, 'capped' => false );
			}
			$args = self::conversation_list_args( $req );
			if ( is_wp_error( $args ) ) {
				return rest_convert_error_to_response( $args );
			}
			$total = BizCity_CRM_Repository::count_conversations( $args );
			$ids   = BizCity_CRM_Repository::list_conversation_ids( $args, 1000 );
			$out   = array( 'ids' => $ids, 'total' => $total, 'capped' => $total > count( $ids ) );
			if ( '' !== sanitize_key( (string) $req->get_param( 'stage' ) ) ) {
				$out['ignored'] = array( 'stage' );
			}
			return $out;
		} );
	}

	public static function get_group_members( WP_REST_Request $req ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-0.39H — resolve the CRM-owned group and fetch its public provider roster server-side.
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req['id'];
			$conversation = BizCity_CRM_Repository::get_conversation( $id );
			if ( ! is_array( $conversation ) ) {
				throw new \RuntimeException( 'conversation_not_found' );
			}
			$rows = BizCity_CRM_Repository::list_conversations( array( 'id' => $id, 'limit' => 1 ) );
			$shaped = ! empty( $rows ) ? self::shape_conversation( $rows[0] ) : array();
			$contact = is_array( $shaped['contact'] ?? null ) ? $shaped['contact'] : array();
			if ( empty( $contact['is_group'] ) || (string) ( $contact['group_id'] ?? '' ) === '' ) {
				throw new \RuntimeException( 'group_conversation_required' );
			}
			$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
			if ( ! is_array( $inbox ) || (string) ( $inbox['channel_type'] ?? '' ) !== 'zalo_personal' ) {
				throw new \RuntimeException( 'zalo_personal_group_required' );
			}
			$account_id = (string) ( $inbox['channel_ref_id'] ?? '' );
			if ( $account_id === '' || ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
				throw new \RuntimeException( 'zalo_personal_bridge_missing' );
			}
			$result = BizCity_Zalo_Bridge_Client::instance()->get_group_members( $account_id, (string) $contact['group_id'] );
			if ( ! is_array( $result ) ) {
				throw new \RuntimeException( 'group_members_unavailable' );
			}
			return array(
				'success'      => empty( $result['_degraded'] ) && ! empty( $result['ok'] ),
				'_degraded'    => ! empty( $result['_degraded'] ),
				'members'      => is_array( $result['members'] ?? null ) ? $result['members'] : array(),
				'provider'     => 'zca-js@getGroupInfo|getGroupMembersInfo',
				'conversation_id' => $id,
			);
		} );
	}

	/**
	 * POST /conversations/{id}/composer-image — upload one clipboard-pasted image into WordPress Media.
	 *
	 * PHASE-0.48 §2.11.5. Returns the same shape (`url`/`thumb_url`/`mime`/`size`/`filename`) the
	 * Composer's `wp.media` picker already produces client-side, so `post_message()` and every channel
	 * adapter downstream stay unchanged — both already accept an attachment as a public Media Library URL.
	 */
	public static function post_composer_image( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { return self::care_error( 'conversation_not_found', 'Không tìm thấy hội thoại.', 404 ); }

			$user_id = (int) get_current_user_id();
			if ( ! $user_id ) { return self::care_error( 'auth_required', 'Vui lòng đăng nhập lại.', 401 ); }

			if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
				return self::care_error( 'invalid_param', 'Thiếu ảnh cần tải lên.', 400 );
			}
			$file = $_FILES['file'];
			$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
			$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
			if ( '' === $name || '' === $tmp || $size <= 0 || ! is_uploaded_file( $tmp ) ) {
				return self::care_error( 'invalid_param', 'Ảnh tải lên không hợp lệ.', 400 );
			}

			// Same ceiling post_message() enforces at send time (PHASE-0.48-ATTACHMENT-POLICY) — checked
			// again here so an oversized paste never reaches media_handle_upload().
			$max_bytes = (int) apply_filters( 'bizcity_crm_composer_image_max_bytes', 10 * 1024 * 1024 );
			if ( $size > $max_bytes ) {
				return self::care_error( 'attachment_image_too_large', 'Ảnh vượt quá giới hạn 10 MB.', 413 );
			}

			$allowed_mimes = array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			);
			$checked = wp_check_filetype_and_ext( $tmp, $name, $allowed_mimes );
			$mime    = isset( $checked['type'] ) ? (string) $checked['type'] : '';
			if ( '' === $mime || empty( $checked['ext'] ) ) {
				return self::care_error( 'attachment_mime_not_allowed', 'Định dạng ảnh chưa được hỗ trợ — dùng JPG, PNG, GIF hoặc WebP.', 415 );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$attachment_id = media_handle_upload( 'file', 0, array(
				'post_author' => $user_id,
				'post_title'  => preg_replace( '/\.[^.]+$/', '', $name ),
			), array(
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			) );
			if ( is_wp_error( $attachment_id ) ) {
				return self::care_error( 'artifact_write_failed', 'Không thể lưu ảnh. Thử lại sau.', 500 );
			}

			$attachment_id = (int) $attachment_id;
			update_post_meta( $attachment_id, '_bizcity_crm_composer_attachment', '1' );
			update_post_meta( $attachment_id, '_bizcity_crm_composer_conversation_id', $conv_id );

			$thumb = wp_get_attachment_image_src( $attachment_id, 'medium' );
			return array(
				'id'        => $attachment_id,
				'url'       => (string) wp_get_attachment_url( $attachment_id ),
				'thumb_url' => is_array( $thumb ) ? (string) $thumb[0] : '',
				'mime'      => $mime,
				'size'      => $size,
				'filename'  => $name,
			);
		} );
	}

	/**
	 * POST /conversations/{id}/messages/{message_id}/retry — re-send a failed outbound message.
	 *
	 * Atomic claim failed → pending guards against concurrent retries (double click, two agents).
	 */
	public static function post_message_retry( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$conv_id    = (int) $req['id'];
			$message_id = (int) $req['message_id'];
			$row = BizCity_CRM_Repository::get_message( $message_id );
			if ( ! is_array( $row ) || (int) $row['conversation_id'] !== $conv_id ) {
				return self::care_error( 'message_not_found', 'Không tìm thấy tin nhắn.', 404 );
			}
			if ( 'outgoing' !== (string) $row['message_type'] || 'failed' !== (string) $row['status'] ) {
				return self::care_error( 'retry_not_allowed', 'Chỉ gửi lại được tin gửi đi đang ở trạng thái lỗi.', 409 );
			}
			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { return self::care_error( 'conversation_not_found', 'Không tìm thấy hội thoại.', 404 ); }
			$inbox_row = BizCity_CRM_Repository::get_inbox( (int) $conv['inbox_id'] );
			if ( class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
				$descriptor = BizCity_CRM_Channel_Contract::require_crm_enabled( (string) ( $inbox_row['channel_type'] ?? '' ) );
				if ( is_wp_error( $descriptor ) ) {
					$disabled_response = self::channel_setup_error( (string) ( $inbox_row['channel_type'] ?? '' ) );
					return $disabled_response ?: self::care_error( $descriptor->get_error_code(), 'Kênh chưa sẵn sàng gửi tin.', 409 );
				}
			}
			$resolved = BizCity_CRM_Repository::resolve_chat_id( $conv_id );
			if ( ! $resolved ) { return self::care_error( 'chat_id_unresolved', 'Không xác định được người nhận.', 409 ); }

			$msg_tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE `{$msg_tbl}` SET status = 'pending' WHERE id = %d AND status = 'failed'", $message_id ) );
			if ( 1 !== (int) $claimed ) {
				return self::care_error( 'retry_in_progress', 'Tin nhắn đang được gửi lại.', 409 );
			}

			$attachments = array();
			$att_tbl = BizCity_CRM_DB_Installer_V2::tbl_attachments();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT file_type, data_url, thumb_url, meta_json FROM `{$att_tbl}` WHERE message_id = %d ORDER BY id ASC LIMIT 1", $message_id ), ARRAY_A ) as $att ) {
				$meta = json_decode( (string) ( $att['meta_json'] ?? '' ), true );
				$attachments[] = array(
					'file_type' => (string) $att['file_type'],
					'data_url'  => (string) $att['data_url'],
					'thumb_url' => (string) ( $att['thumb_url'] ?? '' ),
					'meta'      => is_array( $meta ) ? $meta : array(),
				);
			}
			$ai_meta  = json_decode( (string) ( $row['ai_metadata_json'] ?? '' ), true );
			$reply_to = is_array( $ai_meta ) ? (int) ( $ai_meta['reply_to']['id'] ?? 0 ) : 0;
			$kind     = (string) ( $row['responder_kind'] ?? '' );

			if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
				BizCity_Responder_Stamper::push( array(
					'kind'         => $kind !== '' ? $kind : 'manual',
					'character_id' => ! empty( $row['character_id'] ) ? (int) $row['character_id'] : null,
					'user_id'      => (int) get_current_user_id(),
					'source'       => 'crm-rest-retry',
				) );
			}
			try {
				// Group mentions are not persisted on the row, so a retry sends the stored text without native mentions.
				$result = self::dispatch_crm_outbound( $conv, $inbox_row, $resolved, array(
					'content'      => (string) $row['content'],
					'content_type' => (string) ( $row['content_type'] ?: 'text' ),
					'attachments'  => $attachments,
					'mentions'     => array(),
					'reply_to'     => $reply_to,
				) );
			} catch ( \Throwable $e ) {
				// Never leave the row stuck in pending when the adapter throws.
				$result = array( 'sent' => false, 'outcome' => 'failed', 'error' => $e->getMessage() );
			} finally {
				if ( class_exists( 'BizCity_Responder_Stamper' ) ) {
					BizCity_Responder_Stamper::pop();
				}
			}
			BizCity_CRM_Repository::update_message_delivery( $message_id, array_merge( $result, array( 'platform' => $resolved['platform'] ) ) );
			$fresh = BizCity_CRM_Repository::get_message( $message_id );
			return array(
				'message'  => $fresh ? self::shape_message( $fresh ) : null,
				'dispatch' => array(
					'sent'    => (bool) ( $result['sent'] ?? false ),
					'outcome' => (string) ( $result['outcome'] ?? '' ),
					'error'   => (string) ( $result['error'] ?? '' ),
				),
			);
		} );
	}

	protected static function care_error( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response( array(
			'ok'    => false,
			'error' => array( 'code' => $code, 'message' => $message ),
			'ts'    => (int) round( microtime( true ) * 1000 ),
		), $status );
	}

	/**
	 * Canonical contact ID behind a conversation (0 when unresolved).
	 */
	protected static function care_contact_id_for_conversation( array $conv ): int {
		global $wpdb;
		$contact_inbox_id = (int) ( $conv['contact_inbox_id'] ?? 0 );
		if ( $contact_inbox_id <= 0 ) { return 0; }
		$ci_tbl = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM `{$ci_tbl}` WHERE id = %d", $contact_inbox_id ) );
		return self::resolve_canonical_contact_id( $contact_id );
	}

	/**
	 * Conversation IDs of a contact limited to the caller's Inbox scope (no cross-table JOIN).
	 *
	 * @return int[]
	 */
	protected static function care_scoped_conversation_ids( int $contact_id ): array {
		global $wpdb;
		$ci_tbl = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ci_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$ci_tbl}` WHERE contact_id = %d LIMIT 200", $contact_id ) ) );
		if ( empty( $ci_ids ) ) { return array(); }
		$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$params = $ci_ids;
		$sql = "SELECT id FROM `{$conv_tbl}` WHERE contact_inbox_id IN (" . implode( ',', array_fill( 0, count( $ci_ids ), '%d' ) ) . ')';
		$allowed = class_exists( 'BizCity_CRM_Inbox_Access' ) ? BizCity_CRM_Inbox_Access::allowed_inbox_ids( (int) get_current_user_id() ) : array();
		if ( is_array( $allowed ) ) {
			if ( empty( $allowed ) ) { return array(); }
			$sql .= ' AND inbox_id IN (' . implode( ',', array_fill( 0, count( $allowed ), '%d' ) ) . ')';
			$params = array_merge( $params, array_map( 'intval', $allowed ) );
		}
		$sql .= ' ORDER BY id DESC LIMIT 200';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
	}

	protected static function care_user_label( int $user_id ): string {
		if ( $user_id <= 0 ) { return ''; }
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * GET /conversations/{id}/contact-care — notes + reminders + appointments of the conversation's contact.
	 */
	public static function get_contact_care( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$conv = BizCity_CRM_Repository::get_conversation( (int) $req['id'] );
			if ( ! $conv ) { return self::care_error( 'conversation_not_found', 'Không tìm thấy hội thoại.', 404 ); }
			$contact_id = self::care_contact_id_for_conversation( $conv );
			if ( $contact_id <= 0 ) { return self::care_error( 'contact_not_resolved', 'Hội thoại chưa gắn contact.', 404 ); }

			$degraded = array();
			$notes = array();
			$conv_ids = self::care_scoped_conversation_ids( $contact_id );
			if ( ! empty( $conv_ids ) ) {
				$msg_tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
				$sql = "SELECT id, conversation_id, content, sender_id, created_at FROM `{$msg_tbl}` WHERE message_type = 'private_note' AND conversation_id IN (" . implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) ) . ') ORDER BY id DESC LIMIT 20';
				$note_rows = $wpdb->get_results( $wpdb->prepare( $sql, $conv_ids ), ARRAY_A );
				$note_rows = BizCity_CRM_Repository::hydrate_messages( is_array( $note_rows ) ? $note_rows : array() );
				foreach ( $note_rows as $row ) {
					$author_id = (int) ( $row['sender_id'] ?? 0 );
					$notes[] = array(
						'id'              => (int) $row['id'],
						'conversation_id' => (int) $row['conversation_id'],
						'content'         => wp_strip_all_tags( (string) $row['content'] ),
						'author'          => self::care_user_label( $author_id ),
						'created_at'      => $row['created_at'],
					);
				}
			}

			$task_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$tasks = array();
			$task_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$task_tbl}` WHERE deleted_at IS NULL AND related_entity_type = 'contact' AND related_entity_id = %d ORDER BY completed ASC, (due_date IS NULL) ASC, due_date ASC, id DESC LIMIT 20",
				$contact_id
			), ARRAY_A );
			foreach ( (array) $task_rows as $row ) {
				$task = self::shape_crm_task( $row );
				if ( $task ) {
					$task['assignee'] = self::care_user_label( (int) ( $task['assignee_id'] ?? 0 ) );
					$tasks[] = $task;
				}
			}

			$appointments = array();
			if ( class_exists( 'BizCity_Scheduler_Manager' ) && BizCity_Scheduler_Manager::instance()->is_ready() ) {
				$event_tbl = BizCity_Scheduler_Manager::instance()->get_table();
				$event_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, title, start_at, end_at, event_type, status, reminder_min, conversation_id, user_id FROM `{$event_tbl}` WHERE contact_id = %d AND status IN ('active','done') ORDER BY start_at DESC LIMIT 20",
					$contact_id
				), ARRAY_A );
				foreach ( (array) $event_rows as $row ) {
					$appointments[] = array(
						'id'              => (int) $row['id'],
						'title'           => (string) $row['title'],
						'start_at'        => ! empty( $row['start_at'] ) ? (int) strtotime( $row['start_at'] . ' UTC' ) : 0,
						'end_at'          => ! empty( $row['end_at'] ) ? (int) strtotime( $row['end_at'] . ' UTC' ) : 0,
						'status'          => (string) $row['status'],
						'reminder_min'    => (int) $row['reminder_min'],
						'conversation_id' => (int) ( $row['conversation_id'] ?? 0 ),
						'owner'           => self::care_user_label( (int) ( $row['user_id'] ?? 0 ) ),
					);
				}
			} else {
				$degraded[] = 'scheduler_unavailable';
			}

			return array(
				'contact_id'   => $contact_id,
				'notes'        => $notes,
				'tasks'        => $tasks,
				'appointments' => $appointments,
				'degraded'     => $degraded,
			);
		} );
	}

	/**
	 * POST /conversations/{id}/contact-care — create a note, reminder task or appointment for the conversation's contact.
	 * Body: { kind: note|task|appointment, content, due_at?: unix, reminder_min?, client_request_id? }
	 */
	public static function post_contact_care( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$conv_id = (int) $req['id'];
			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { return self::care_error( 'conversation_not_found', 'Không tìm thấy hội thoại.', 404 ); }
			$contact_id = self::care_contact_id_for_conversation( $conv );
			if ( $contact_id <= 0 ) { return self::care_error( 'contact_not_resolved', 'Hội thoại chưa gắn contact.', 404 ); }

			$body    = self::extract_json_body( $req );
			$kind    = sanitize_key( (string) ( $body['kind'] ?? '' ) );
			$content = trim( sanitize_textarea_field( (string) ( $body['content'] ?? '' ) ) );
			if ( ! in_array( $kind, array( 'note', 'task', 'appointment' ), true ) ) { return self::care_error( 'invalid_kind', 'Loại thao tác không hợp lệ.', 422 ); }
			if ( $content === '' ) { return self::care_error( 'content_required', 'Nội dung không được để trống.', 422 ); }
			if ( mb_strlen( $content ) > 2000 ) { return self::care_error( 'content_too_long', 'Nội dung tối đa 2000 ký tự.', 422 ); }
			$user_id = (int) get_current_user_id();

			// Idempotency: the same client_request_id from the same user returns the first result (double Enter / retry).
			$request_key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $body['client_request_id'] ?? '' ) );
			$idem_key = $request_key !== '' ? 'bzc_care_' . md5( get_current_blog_id() . '|' . $user_id . '|' . $conv_id . '|' . $request_key ) : '';
			if ( $idem_key !== '' ) {
				$previous = get_transient( $idem_key );
				if ( is_array( $previous ) ) { return array_merge( $previous, array( 'duplicate' => true ) ); }
			}

			$title = mb_substr( preg_replace( '/\s+/', ' ', $content ), 0, 180 );
			$due_at = (int) ( $body['due_at'] ?? 0 );
			$result = array( 'kind' => $kind, 'contact_id' => $contact_id, 'conversation_id' => $conv_id );

			if ( 'note' === $kind ) {
				$msg_id = BizCity_CRM_Repository::insert_message( array(
					'conversation_id'   => $conv_id,
					'inbox_id'          => (int) $conv['inbox_id'],
					'content'           => $content,
					'content_type'      => 'text',
					'message_type'      => 'private_note',
					'sender_type'       => 'agent',
					'sender_id'         => $user_id ?: null,
					'status'            => 'note',
					'responder_kind'    => 'manual',
					'responder_user_id' => $user_id ?: null,
				) );
				if ( ! $msg_id ) { return self::care_error( 'note_insert_failed', 'Không lưu được ghi chú.', 500 ); }
				$result['id'] = (int) $msg_id;
			} elseif ( 'task' === $kind ) {
				// crm_tasks.due_date is a DATE column: reminders are day-granular in the site timezone.
				$due_date = $due_at > 0 ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $due_at ), 'Y-m-d' ) : null;
				if ( $due_date !== null && $due_date < current_time( 'Y-m-d' ) ) { return self::care_error( 'due_in_past', 'Ngày nhắc đã qua.', 422 ); }
				$now = current_time( 'mysql' );
				$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_tasks(), array(
					'title'               => $title,
					'status'              => 'open',
					'priority'            => 'medium',
					'due_date'            => $due_date,
					'assignee_id'         => $user_id ?: null,
					'related_entity_type' => 'contact',
					'related_entity_id'   => $contact_id,
					'notes'               => $content . "\n\n— conversation #" . $conv_id,
					'completed'           => 0,
					'created_by'          => $user_id ?: null,
					'created_at'          => $now,
					'updated_at'          => $now,
				) );
				$task_id = (int) $wpdb->insert_id;
				if ( ! $task_id ) { return self::care_error( 'task_insert_failed', 'Không tạo được nhắc việc.', 500 ); }
				$result['id'] = $task_id;
			} else {
				if ( $due_at <= 0 ) { return self::care_error( 'due_at_required', 'Chọn thời gian lịch hẹn.', 422 ); }
				if ( $due_at < time() - 300 ) { return self::care_error( 'due_in_past', 'Thời gian lịch hẹn đã qua.', 422 ); }
				if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) { return self::care_error( 'scheduler_unavailable', 'Scheduler chưa sẵn sàng.', 503 ); }
				$event_id = BizCity_Scheduler_Manager::instance()->create_event( array(
					'title'           => $title,
					'description'     => $content,
					'start_at'        => gmdate( 'Y-m-d H:i:s', $due_at ),
					'end_at'          => gmdate( 'Y-m-d H:i:s', $due_at + 30 * MINUTE_IN_SECONDS ),
					'event_type'      => 'meeting',
					'source'          => 'crm_inbox',
					'reminder_min'    => isset( $body['reminder_min'] ) ? max( 0, min( 10080, (int) $body['reminder_min'] ) ) : 15,
					'user_id'         => $user_id ?: null,
					'contact_id'      => $contact_id,
					'conversation_id' => $conv_id,
					'metadata'        => array( 'related_entity_type' => 'contact', 'related_entity_id' => $contact_id, 'origin' => 'crm_contact_care' ),
				) );
				if ( is_wp_error( $event_id ) ) { return self::care_error( 'appointment_create_failed', $event_id->get_error_message(), 422 ); }
				$result['id'] = (int) $event_id;
			}

			if ( $idem_key !== '' ) { set_transient( $idem_key, $result, 10 * MINUTE_IN_SECONDS ); }
			do_action( 'bizcity_crm_contact_care_created', $result, $user_id );
			return $result;
		} );
	}

	/**
	 * POST /conversations/{id}/contact-facts — save a normalized phone/email on the conversation's contact.
	 * Body: { field: phone|email, value }
	 */
	public static function post_contact_fact( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$conv = BizCity_CRM_Repository::get_conversation( (int) $req['id'] );
			if ( ! $conv ) { return self::care_error( 'conversation_not_found', 'Không tìm thấy hội thoại.', 404 ); }
			$contact_id = self::care_contact_id_for_conversation( $conv );
			if ( $contact_id <= 0 ) { return self::care_error( 'contact_not_resolved', 'Hội thoại chưa gắn contact.', 404 ); }

			$body  = self::extract_json_body( $req );
			$field = sanitize_key( (string) ( $body['field'] ?? '' ) );
			$raw   = trim( (string) ( $body['value'] ?? '' ) );
			// `name` added for the R-ACTION-SHEET "Sửa thông tin" sheet (parity with /gpt/crm/ ContactFactsSheet).
			if ( ! in_array( $field, array( 'phone', 'email', 'name' ), true ) ) { return self::care_error( 'invalid_field', 'Trường không hợp lệ.', 422 ); }
			if ( $raw === '' ) { return self::care_error( 'value_required', 'Giá trị không được để trống.', 422 ); }

			if ( 'name' === $field ) {
				$value = mb_substr( sanitize_text_field( $raw ), 0, 190 );
				if ( $value === '' ) { return self::care_error( 'value_required', 'Tên không được để trống.', 422 ); }
			} elseif ( 'email' === $field ) {
				$value = strtolower( sanitize_email( $raw ) );
				if ( $value === '' || ! is_email( $value ) ) { return self::care_error( 'invalid_email', 'Email không hợp lệ.', 422 ); }
			} else {
				$value = class_exists( 'BizCity_Phone_Normalizer' )
					? (string) BizCity_Phone_Normalizer::normalize_vn( $raw )
					: (string) preg_replace( '/\D+/', '', $raw );
				$digits = (string) preg_replace( '/\D+/', '', $value );
				if ( strlen( $digits ) < 9 || strlen( $digits ) > 12 ) { return self::care_error( 'invalid_phone', 'Số điện thoại không hợp lệ.', 422 ); }
			}

			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT `{$field}` FROM `{$tbl}` WHERE id = %d", $contact_id ) );
			if ( $current !== '' && strtolower( $current ) === strtolower( $value ) ) {
				return array( 'status' => 'unchanged', 'field' => $field, 'value' => $value, 'contact_id' => $contact_id );
			}

			$other_id = 'name' === $field ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE deleted_at IS NULL AND id <> %d AND `{$field}` = %s LIMIT 1", $contact_id, $value ) );
			if ( $other_id > 0 ) {
				// Never expose another contact's ID outside tenant-admin scope; conflicts go to the identity owner, not an auto-merge.
				$response = self::care_error( 'contact_fact_conflict', 'phone' === $field ? 'Số điện thoại đã thuộc một contact khác.' : 'Email đã thuộc một contact khác.', 409 );
				if ( class_exists( 'BizCity_CRM_Inbox_Access' ) && BizCity_CRM_Inbox_Access::is_admin() ) {
					$data = $response->get_data();
					$data['error']['conflict_contact_id'] = $other_id;
					$response->set_data( $data );
				}
				return $response;
			}

			// P-U-3: never report success when the row was not written.
			if ( false === $wpdb->update( $tbl, array( $field => $value, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $contact_id ) ) ) {
				return self::care_error( 'contact_fact_write_failed', 'Không lưu được thông tin khách, thử lại sau.', 500 );
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id = %d", $contact_id ), ARRAY_A );
			if ( method_exists( 'BizCity_CRM_Repository', 'invalidate_read_models' ) ) { BizCity_CRM_Repository::invalidate_read_models(); }
			do_action( 'bizcity_crm_contact_saved', $contact_id, $row );
			return array( 'status' => 'saved', 'field' => $field, 'value' => $value, 'contact_id' => $contact_id, 'previous_present' => $current !== '' );
		} );
	}

	/**
	 * EDITABLE /conversations/{id}/assignee — assign or unassign a conversation.
	 */
	public static function post_assign( WP_REST_Request $req ) {
		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — assign or unassign conversation.
		return self::wrap( static function () use ( $req ) {
			$conv_id     = (int) $req['id'];
			$assignee_id = max( 0, (int) $req->get_param( 'assignee_id' ) );
			$conv        = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }

			if ( $assignee_id > 0 ) {
				$user = get_userdata( $assignee_id );
				if ( ! $user || ! current_user_can( 'bizcity_crm_assign_conversations' ) && ! current_user_can( 'manage_options' ) ) {
					throw new \RuntimeException( 'assignee_not_allowed' );
				}
				if ( ! current_user_can( 'manage_options' ) && class_exists( 'BizCity_CRM_Team_Manager' ) && ! BizCity_CRM_Team_Manager::can_assign( $conv_id, (int) get_current_user_id(), $assignee_id ) ) {
					throw new \RuntimeException( 'assignee_membership_denied' );
				}
			}

			$ok = BizCity_CRM_Repository::set_conversation_assignee(
				$conv_id,
				$assignee_id > 0 ? $assignee_id : null,
				(int) get_current_user_id()
			);
			return array( 'assigned' => (bool) $ok, 'assignee_id' => $assignee_id ?: null );
		} );
	}

	/**
	 * EDITABLE /conversations/{id}/priority — set a priority from 0 (low) to 3 (urgent).
	 */
	public static function post_priority( WP_REST_Request $req ) {
		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — update conversation priority.
		return self::wrap( static function () use ( $req ) {
			$conv_id  = (int) $req['id'];
			$priority = (int) $req->get_param( 'priority' );
			if ( $priority < 0 || $priority > 3 ) {
				throw new \RuntimeException( 'invalid_priority' );
			}
			if ( ! BizCity_CRM_Repository::get_conversation( $conv_id ) ) {
				throw new \RuntimeException( 'conversation_not_found' );
			}
			$ok = BizCity_CRM_Repository::set_conversation_priority( $conv_id, $priority, (int) get_current_user_id() );
			return array( 'updated' => (bool) $ok, 'priority' => $priority );
		} );
	}

	/**
	 * EDITABLE /conversations/{id}/reopen — move a resolved conversation back to open.
	 */
	public static function post_reopen( WP_REST_Request $req ) {
		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — reopen a resolved conversation.
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			if ( (string) $conv['status'] !== 'resolved' ) {
				throw new \RuntimeException( 'conversation_not_resolved' );
			}
			$ok = BizCity_CRM_Repository::set_conversation_status( $conv_id, 'open', (int) get_current_user_id() );
			return array( 'reopened' => (bool) $ok, 'status' => 'open' );
		} );
	}

	/**
	 * POST /conversations/{id}/snooze — set snoozed_until.
	 * Body: { duration_seconds?: int, until?: ISO-8601 string }. duration_seconds wins.
	 */
	public static function post_snooze( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }

			$dur   = (int) $req->get_param( 'duration_seconds' );
			$until = (string) $req->get_param( 'until' );
			$ts    = 0;
			if ( $dur > 0 ) {
				$ts = time() + $dur;
			} elseif ( $until !== '' ) {
				$ts = (int) strtotime( $until );
			}
			if ( $ts <= time() ) {
				throw new \RuntimeException( 'snooze_until_must_be_in_future' );
			}
			$ok = BizCity_CRM_Repository::set_snooze( $conv_id, $ts, (int) get_current_user_id() );
			return array(
				'snoozed'        => (bool) $ok,
				'snoozed_until'  => $ts,
				'snoozed_until_iso' => gmdate( 'c', $ts ),
			);
		} );
	}

	/**
	 * POST /conversations/{id}/unsnooze — clear snoozed_until.
	 */
	public static function post_unsnooze( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$conv_id = (int) $req['id'];
			$conv    = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( ! $conv ) { throw new \RuntimeException( 'conversation_not_found' ); }
			$ok = BizCity_CRM_Repository::set_snooze( $conv_id, 0, (int) get_current_user_id() );
			return array( 'unsnoozed' => (bool) $ok );
		} );
	}

	public static function post_contact_enrich( WP_REST_Request $req ) {
		// [2026-09-23 04:30 PM Claude Fable 5.1] PHASE-0.60B A4.4 — "Làm mới từ Zalo" with a 10-minute manual throttle.
		$id = (int) $req['id'];
		if ( ! class_exists( 'BizCity_CRM_Contact_Enrichment' ) ) {
			return self::enrichment_error( 'module_not_loaded', 'Làm giàu liên hệ chưa sẵn sàng.', 'Bật module CRM enrichment rồi thử lại.', 'module_not_loaded', 503 );
		}
		$conversation_id = (int) $req->get_param( 'conversation_id' );
		if ( $conversation_id <= 0 ) {
			$convs = BizCity_CRM_Repository::list_conversations_for_contact( $id, 1 );
			$conversation_id = ! empty( $convs ) ? (int) $convs[0]['id'] : 0;
		}
		if ( BizCity_CRM_Contact_Enrichment::is_throttled( $id ) ) {
			return self::enrichment_error( 'rate_limited', 'Vừa làm giàu liên hệ này rồi.', 'Đợi vài phút rồi bấm lại; bot không gọi bridge mỗi tin.', 'enrichment_throttled', 429 );
		}
		$res = BizCity_CRM_Contact_Enrichment::enrich_from_zalo( $id, $conversation_id, 'manual' );
		if ( 'ok' !== $res['status'] ) {
			$map = array(
				'group_thread'     => array( 'Hội thoại nhóm không làm giàu hồ sơ cá nhân.', 'Mở một chat riêng với khách để làm giàu.', 'enrichment_group_thread' ),
				'opted_out'        => array( 'Khách đã yêu cầu không thu thập thêm.', 'Chờ hết thời gian từ chối hoặc khách đồng ý lại.', 'enrichment_opted_out' ),
				'not_zalo_personal'=> array( 'Chỉ làm giàu được từ kênh Zalo Cá nhân.', 'Chọn hội thoại Zalo Cá nhân của khách.', 'enrichment_channel' ),
				'source_missing'   => array( 'Chưa xác định được UID Zalo của khách.', 'Chọn đúng hội thoại có tin nhắn của khách.', 'enrichment_source_missing' ),
			);
			$reason = (string) $res['reason'];
			$row    = $map[ $reason ] ?? array( 'Không đọc được hồ sơ Zalo lúc này.', 'Kiểm tra bridge Zalo Cá nhân đã kết nối rồi thử lại.', 'enrichment_bridge_' . $reason );
			return self::enrichment_error( 'degraded' === $res['status'] ? 'gateway_degraded' : 'invalid_param', $row[0], $row[1], $row[2], 'degraded' === $res['status'] ? 502 : 422 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => $res ), 200 );
	}

	public static function put_contact_birthday( WP_REST_Request $req ) {
		// [2026-09-23 04:30 PM Claude Fable 5.1] PHASE-0.60B §4.1 rule 1 — staff edit is the only forced write; '' clears.
		$id   = (int) $req['id'];
		$body = self::extract_json_body( $req );
		$date = trim( (string) ( $body['date'] ?? '' ) );
		$time = trim( (string) ( $body['time'] ?? '' ) );
		if ( $date !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && ! preg_match( '/^\d{2}-\d{2}$/', $date ) ) {
			return self::enrichment_error( 'invalid_param', 'Ngày sinh phải ở dạng YYYY-MM-DD hoặc MM-DD.', 'Nhập ví dụ 1990-03-12, hoặc 03-12 nếu chưa biết năm.', 'birthday_format', 422 );
		}
		if ( $date !== '' && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return self::enrichment_error( 'invalid_param', 'Ngày sinh không tồn tại.', 'Kiểm tra lại ngày/tháng.', 'birthday_invalid', 422 );
		}
		if ( $time !== '' && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return self::enrichment_error( 'invalid_param', 'Giờ sinh phải ở dạng HH:MM.', 'Ví dụ 07:30.', 'birthday_time_format', 422 );
		}
		if ( ! class_exists( 'BizCity_CRM_Contact_Enrichment' ) ) {
			return self::enrichment_error( 'module_not_loaded', 'Làm giàu liên hệ chưa sẵn sàng.', 'Bật module CRM enrichment rồi thử lại.', 'module_not_loaded', 503 );
		}
		$ok = BizCity_CRM_Contact_Enrichment::set_birthday( $id, $date, $time, array( 'source' => 'staff', 'force' => true, 'at' => gmdate( 'c' ) ) );
		if ( ! $ok ) {
			return self::enrichment_error( 'write_failed', 'Không lưu được ngày sinh.', 'Cột birthday có thể chưa được cài; chạy lại cập nhật CSDL CRM.', 'birthday_write_failed', 500 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'contact_id' => $id, 'date' => $date, 'time' => $time ) ), 200 );
	}

	public static function delete_contact_enrichment( WP_REST_Request $req ) {
		// [2026-09-23 04:30 PM Claude Fable 5.1] PHASE-0.60B rule 6 — withdrawal; no re-ask for N days.
		$id   = (int) $req['id'];
		$body = self::extract_json_body( $req );
		$days = max( 1, min( 3650, (int) ( $body['opt_out_days'] ?? 90 ) ) );
		if ( ! class_exists( 'BizCity_CRM_Contact_Enrichment' ) ) {
			return self::enrichment_error( 'module_not_loaded', 'Làm giàu liên hệ chưa sẵn sàng.', 'Bật module CRM enrichment rồi thử lại.', 'module_not_loaded', 503 );
		}
		$ok = BizCity_CRM_Contact_Enrichment::clear_enrichment( $id, $days );
		if ( ! $ok ) {
			return self::enrichment_error( 'write_failed', 'Không xoá được dữ liệu làm giàu.', 'Thử lại; nếu vẫn lỗi hãy liên hệ quản trị viên.', 'enrichment_clear_failed', 500 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'contact_id' => $id, 'opt_out_days' => $days ) ), 200 );
	}

	public static function get_labels( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$args = array(
				'q'               => (string) ( $req->get_param( 'q' ) ?? '' ),
				'show_on_sidebar' => $req->get_param( 'show_on_sidebar' ),
			);
			if ( $args['q'] === '' )                  { unset( $args['q'] ); }
			if ( $args['show_on_sidebar'] === null )  { unset( $args['show_on_sidebar'] ); }
			$rows = BizCity_CRM_Repository::list_labels( $args );
			return array( 'labels' => array_map( array( __CLASS__, 'shape_label' ), $rows ), 'count' => count( $rows ) );
		} );
	}

	public static function get_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$row = BizCity_CRM_Repository::get_label( (int) $req->get_param( 'id' ) );
			if ( ! $row ) { return new WP_Error( 'not_found', 'Label not found', array( 'status' => 404 ) ); }
			return self::shape_label( $row );
		} );
	}

	public static function post_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$body  = self::extract_json_body( $req );
			$title = trim( (string) ( $body['title'] ?? '' ) );
			if ( $title === '' ) {
				return new WP_Error( 'invalid_title', 'Field "title" is required', array( 'status' => 422 ) );
			}
			if ( BizCity_CRM_Repository::get_label_by_title( $title ) ) {
				return new WP_Error( 'duplicate_title', 'A label with this title already exists', array( 'status' => 409 ) );
			}
			$id = BizCity_CRM_Repository::upsert_label( $body );
			if ( ! $id ) { return new WP_Error( 'insert_failed', 'Could not create label', array( 'status' => 500 ) ); }
			return self::shape_label( BizCity_CRM_Repository::get_label( $id ) );
		} );
	}

	public static function put_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			if ( ! BizCity_CRM_Repository::get_label( $id ) ) {
				return new WP_Error( 'not_found', 'Label not found', array( 'status' => 404 ) );
			}
			$body       = self::extract_json_body( $req );
			$body['id'] = $id;
			BizCity_CRM_Repository::upsert_label( $body );
			return self::shape_label( BizCity_CRM_Repository::get_label( $id ) );
		} );
	}

	public static function delete_label( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$id = (int) $req->get_param( 'id' );
			$ok = BizCity_CRM_Repository::delete_label( $id );
			return array( 'deleted' => $ok, 'id' => $id );
		} );
	}

	public static function get_conversation_labels( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$cid  = (int) $req->get_param( 'id' );
			$rows = BizCity_CRM_Repository::get_conversation_labels( $cid );
			return array( 'labels' => array_map( array( __CLASS__, 'shape_label' ), $rows ) );
		} );
	}

	public static function post_conversation_labels( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			$cid  = (int) $req->get_param( 'id' );
			if ( ! BizCity_CRM_Repository::get_conversation( $cid ) ) {
				return new WP_Error( 'not_found', 'Conversation not found', array( 'status' => 404 ) );
			}
			$body  = self::extract_json_body( $req );
			$input = $body['labels'] ?? array();
			if ( ! is_array( $input ) ) {
				return new WP_Error( 'invalid_labels', 'Field "labels" must be an array of ids or titles', array( 'status' => 422 ) );
			}
			$ids = array();
			foreach ( $input as $entry ) {
				if ( is_numeric( $entry ) ) { $ids[] = (int) $entry; continue; }
				if ( is_string( $entry ) && $entry !== '' ) {
					$lbl = BizCity_CRM_Repository::get_label_by_title( $entry );
					if ( $lbl ) { $ids[] = (int) $lbl['id']; }
				}
			}
			$diff = BizCity_CRM_Repository::set_conversation_labels( $cid, $ids, get_current_user_id() );
			if ( ! empty( $diff['failed'] ) ) {
				return new WP_Error( 'label_write_failed', 'Không lưu được nhãn cho hội thoại.', array( 'status' => 500, 'hint' => 'Bảng nhãn của site chưa sẵn sàng hoặc không ghi được — báo quản trị kiểm tra Schema/Site Provisioner.', 'help_code' => 'label_write_failed' ) );
			}
			$rows = BizCity_CRM_Repository::get_conversation_labels( $cid );
			$saved_ids = array_map( static function ( $r ) { return (int) $r['id']; }, $rows );
			$wanted = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ), static function ( $id ) { return $id > 0 && BizCity_CRM_Repository::get_label( $id ); } ) );
			sort( $saved_ids ); sort( $wanted );
			if ( $saved_ids !== $wanted ) {
				return new WP_Error( 'label_write_mismatch', 'Nhãn chưa được lưu đúng như đã chọn.', array( 'status' => 500, 'hint' => 'Tải lại hội thoại và thử lại; nếu lặp lại, báo quản trị kiểm tra bảng nhãn.', 'help_code' => 'label_write_failed' ) );
			}
			return array(
				'labels'  => array_map( array( __CLASS__, 'shape_label' ), $rows ),
				'added'   => $diff['added'],
				'removed' => $diff['removed'],
			);
		} );
	}

	protected static function shape_label( ?array $row ): ?array {
		if ( ! $row ) { return null; }
		return array(
			'id'              => (int) $row['id'],
			'title'           => (string) $row['title'],
			'description'     => $row['description'] !== null ? (string) $row['description'] : '',
			'color'           => (string) $row['color'],
			'show_on_sidebar' => (bool) (int) $row['show_on_sidebar'],
			'created_at'      => $row['created_at'] ?? null,
			'updated_at'      => $row['updated_at'] ?? null,
		);
	}

	/**
	 * GET /crm-contacts/{id}/channels — scoped contact inbox links.
	 */
	public static function get_crm_contact_channels( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$contact_id = self::resolve_canonical_contact_id( (int) $req['id'] );
			if ( ! $contact_id ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}
			if ( ! self::contact_is_in_scope( $contact_id, (int) get_current_user_id() ) ) {
				return new WP_Error( 'not_found', 'Contact not found', array( 'status' => 404 ) );
			}
			$ci_tbl   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$inbox_tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
			$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ci.id AS contact_inbox_id, ci.inbox_id, ci.source_id,
					i.channel_type, i.name AS inbox_name,
					cv.id AS conversation_id, cv.status AS conversation_status,
					cv.last_activity_at
				 FROM `{$ci_tbl}` ci
				 JOIN `{$inbox_tbl}` i ON i.id = ci.inbox_id
				 LEFT JOIN `{$conv_tbl}` cv ON cv.contact_inbox_id = ci.id
				 WHERE ci.contact_id = %d
				 ORDER BY ci.id ASC, cv.last_activity_at DESC, cv.id DESC",
				$contact_id
			), ARRAY_A );
			$allowed = class_exists( 'BizCity_CRM_Inbox_Access' )
				? BizCity_CRM_Inbox_Access::allowed_inbox_ids()
				: null;
			$links = array();
			$seen  = array();
			foreach ( (array) $rows as $row ) {
				$inbox_id = (int) ( $row['inbox_id'] ?? 0 );
				if ( is_array( $allowed ) && ! in_array( $inbox_id, $allowed, true ) ) {
					continue;
				}
				$contact_inbox_id = (int) ( $row['contact_inbox_id'] ?? 0 );
				if ( isset( $seen[ $contact_inbox_id ] ) ) {
					continue;
				}
				$seen[ $contact_inbox_id ] = true;
				$links[] = array(
					'contact_inbox_id'   => $contact_inbox_id,
					'inbox_id'           => $inbox_id,
					'source_id'          => (string) ( $row['source_id'] ?? '' ),
					'channel_type'       => (string) ( $row['channel_type'] ?? '' ),
					'inbox_name'         => (string) ( $row['inbox_name'] ?? '' ),
					'conversation_id'    => (int) ( $row['conversation_id'] ?? 0 ),
					'conversation_status' => (string) ( $row['conversation_status'] ?? '' ),
					'last_activity_at'   => $row['last_activity_at'] ?? null,
				);
			}
			return array( 'channels' => $links );
		} );
	}

	protected static function shape_crm_task( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		return array(
			'id'                  => (int) $r['id'],
			'title'               => (string) $r['title'],
			'status'              => (string) $r['status'],
			'priority'            => (string) $r['priority'],
			'due_date'            => $r['due_date'],
			'assignee_id'         => $r['assignee_id'] ? (int) $r['assignee_id'] : null,
			'related_entity_type' => $r['related_entity_type'],
			'related_entity_id'   => $r['related_entity_id'] ? (int) $r['related_entity_id'] : null,
			'notes'               => $r['notes'],
			'completed'           => (bool) (int) $r['completed'],
			'completed_at'        => $r['completed_at'],
			'created_at'          => $r['created_at'],
			'updated_at'          => $r['updated_at'],
		);
	}

	public static function get_crm_documents( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			global $wpdb;
			$tbl   = BizCity_CRM_DB_Installer_V2::tbl_crm_documents();
			$where = array( '1=1' );
			$et    = (string) ( $req->get_param( 'related_entity_type' ) ?: '' );
			$eid   = $req->get_param( 'related_entity_id' );
			if ( $et !== '' ) { $where[] = $wpdb->prepare( 'related_entity_type = %s', $et ); }
			if ( $eid !== null ) { $where[] = $wpdb->prepare( 'related_entity_id = %d', (int) $eid ); }
			$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?: 0 ) );
			$sql    = "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY uploaded_at DESC LIMIT {$limit} OFFSET {$offset}";
			$rows   = $wpdb->get_results( $sql, ARRAY_A );
			return array(
				'documents' => array_map( array( __CLASS__, 'shape_crm_document' ), (array) $rows ),
				'count'     => count( (array) $rows ),
			);
		} );
	}

	protected static function shape_crm_document( ?array $r ): ?array {
		if ( ! $r ) { return null; }
		return array(
			'id'                  => (int) $r['id'],
			'name'                => (string) $r['name'],
			'type'                => (string) $r['type'],
			'size_bytes'          => (int) $r['size_bytes'],
			'path'                => (string) $r['path'],
			'uploaded_by'         => $r['uploaded_by'] ? (int) $r['uploaded_by'] : null,
			'related_entity_type' => $r['related_entity_type'],
			'related_entity_id'   => $r['related_entity_id'] ? (int) $r['related_entity_id'] : null,
			'uploaded_at'         => $r['uploaded_at'],
		);
	}

	/**
	 * Create one contact activity; returns the created item or a WP_Error (R-ERROR-UX codes).
	 *
	 * @return array|WP_Error
	 */
	public static function create_contact_activity( int $contact_id, array $body, int $user_id ) {
		global $wpdb;
		$act_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_activities();
		if ( ! bizcity_tbl_exists( $act_tbl ) ) {
			return new WP_Error( 'activity_store_missing', 'Kho activity của site chưa sẵn sàng.', array( 'status' => 503, 'hint' => 'Báo quản trị chạy cập nhật cơ sở dữ liệu CRM.' ) );
		}
		$type  = sanitize_key( (string) ( $body['type'] ?? 'note' ) );
		$title = mb_substr( sanitize_text_field( (string) ( $body['title'] ?? '' ) ), 0, 255 );
		$text  = sanitize_textarea_field( (string) ( $body['body'] ?? '' ) );
		if ( ! in_array( $type, self::CONTACT_ACTIVITY_TYPES, true ) ) {
			return new WP_Error( 'invalid_activity_type', 'Loại activity không hợp lệ.', array( 'status' => 422, 'hint' => 'Chọn ghi chú, cuộc gọi, cuộc hẹn, email hoặc việc.' ) );
		}
		if ( '' === $title ) {
			return new WP_Error( 'activity_title_required', 'Cần nhập tiêu đề activity.', array( 'status' => 422, 'hint' => 'Ví dụ: "Gọi tư vấn combo".' ) );
		}
		$user  = get_userdata( $user_id );
		$now   = current_time( 'mysql' );
		$ok = $wpdb->insert( $act_tbl, array(
			'entity_type' => 'contact',
			'entity_id'   => $contact_id,
			'type'        => $type,
			'title'       => $title,
			'body'        => $text,
			'user_id'     => $user_id ?: null,
			'user_label'  => $user ? (string) $user->display_name : '',
			'created_at'  => $now,
		) );
		if ( false === $ok ) {
			error_log( '[bizcity-crm] create_contact_activity failed contact=' . $contact_id . ' ' . $wpdb->last_error );
			return new WP_Error( 'activity_write_failed', 'Không lưu được activity.', array( 'status' => 500, 'hint' => 'Thử lại; nếu lặp lại, báo quản trị kiểm tra bảng activity.' ) );
		}
		return array( 'id' => (int) $wpdb->insert_id, 'type' => $type, 'title' => $title, 'body' => $text, 'user_id' => $user_id, 'user_display_name' => $user ? (string) $user->display_name : '', 'user' => $user ? (string) $user->display_name : '', 'created_at' => $now );
	}

	/**
	 * [2026-07-03 Johnny Chu] PHASE-0.46 FIX
	 * GET /crm-settings/assignable-users — list WP users that can be assigned to submissions.
	 * Returns users with manage_options or a lighter CRM role.
	 * No JOIN on global wp_users table is needed — uses get_users() which handles multisite.
	 */
	public static function get_crm_assignable_users( WP_REST_Request $req ) {
		return self::wrap( static function () {
			// [2026-09-25 10:31 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.60H — restrict assignable users to the current multisite blog.
			$wp_users = get_users( array(
				'blog_id'    => get_current_blog_id(),
				// [2026-09-07 09:00 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.48D-CRM-ADMIN-INBOX-MENU-V2 — expose every non-subscriber WP operator through the server-owned staff scope catalog
				'role__not_in' => array( 'subscriber' ),
				// [2026-09-24 Claude Sonnet 5] PHASE-0.60H — a role-less user slips through `role__not_in`; the powerless
				// Bot Studio system owner must never be offered as someone a conversation can be assigned to.
				'exclude'  => class_exists( 'BizCity_CRM_System_Owner' ) ? BizCity_CRM_System_Owner::exclude_ids() : array(),
				'number'   => 200,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => array( 'ID', 'display_name', 'user_email' ),
			) );
			$out = array();
			foreach ( $wp_users as $u ) {
				if ( class_exists( 'BizCity_CRM_Staff_Policy' ) && ! BizCity_CRM_Staff_Policy::is_assignable_user( (int) $u->ID ) ) { continue; }
				$out[] = array(
					'id'           => (int) $u->ID,
					'display_name' => (string) $u->display_name,
					'email'        => (string) $u->user_email,
					'roles'        => array_values( array_map( 'sanitize_key', (array) $u->roles ) ),
				);
			}
			return $out;
		} );
	}

	public static function get_crm_user_inbox_scope( WP_REST_Request $req ) {
		// [2026-09-08 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — accept only principals from the server-owned B2 staff catalog.
		return self::wrap( static function () use ( $req ) {
			$user_id = (int) $req->get_param( 'user_id' );
			if ( ! self::is_crm_assignable_user( $user_id ) ) {
				return new WP_Error( 'invalid_param', 'Nhân viên không thuộc phạm vi CRM hiện tại.', array( 'status' => 400, 'hint' => 'Chọn một nhân viên trong danh sách do máy chủ cung cấp.', 'help_code' => 'invalid_param_generic' ) );
			}
			if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) || ! method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_user_contact_projection' ) ) {
				return new WP_Error( 'module_not_loaded', 'Phạm vi Inbox chưa sẵn sàng.', array( 'status' => 503, 'hint' => 'Tải lại CRM sau khi module Inbox được nạp.', 'help_code' => 'module_not_loaded' ) );
			}
			$limit = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
			return BizCity_CRM_Inbox_Access::resolve_user_contact_projection( $user_id, 'b2', $limit );
		} );
	}

	/**
	 * GET /crm-settings/inbox-user-groups — B2 management rail grouped by WordPress user.
	 *
	 * [2026-09-16 01:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48D-USER-RAIL
	 *
	 * The management tier (`/twin/?plugin=crm`) manages Inbox per WordPress
	 * `user_id` instead of per channel. Each member logs in and uses the public
	 * frontend at `/gpt/`; this endpoint only supplies the B2 grouping catalog.
	 *
	 * Ownership sources, in order:
	 *   1. `bizcity_zalo_accounts.owner_user_id` → `crm_inbox_id` (exact owner).
	 *   2. `bizcity_crm_inbox_members` (business inbox membership).
	 *
	 * Only safe labels are returned; raw provider identifiers never leave the
	 * server. A posted user ID is a selector input, never an ACL.
	 */
	public static function get_crm_inbox_user_groups( WP_REST_Request $req ) {
		return self::wrap( static function () use ( $req ) {
			if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
				return array( 'groups' => array(), 'unassigned' => array(), '_degraded' => true );
			}
			global $wpdb;
			$inboxes_table = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
			$rows = $wpdb->get_results( "SELECT id, name, channel_type, channel_ref_id FROM `{$inboxes_table}` WHERE is_active = 1 ORDER BY id ASC", ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();

			$inbox_ids = array();
			foreach ( $rows as $row ) {
				$inbox_ids[] = (int) ( $row['id'] ?? 0 );
			}
			$inbox_ids = array_values( array_filter( array_unique( $inbox_ids ) ) );

			// 1) Exact Personal-account ownership.
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48F F1-02 (S2 slice) —
			// piggy-back on this same `list_personal_accounts()` read to also expose
			// `session_state`/`can_relogin` per inbox, straight from the already-synced
			// local `bizcity_zalo_accounts.status` column — no per-account bridge/
			// readiness call, so a 30-employee rail stays one query. The richer
			// `session_disconnected`/`superseded`/`bridge_unavailable` states from the
			// full readiness envelope (0.48E §E3.2) need live sidecar state and remain
			// F1-01 (not done here).
			$owner_map = array();
			$session_state_map = array();
			$dead_states = array( 'expired', 'logged_out' );
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60J BG-3 — every Zalo Cá nhân phone with its owner, straight from the account table.
			// The rail below DROPS a phone whose owner is not a CRM-assignable user (it lands in no group and not in `unassigned`) and
			// any inbox with is_active = 0, so the add-number picker cannot be built from `groups`/`unassigned` alone.
			$all_phones = array();
			$inbox_name_by_id = array();
			foreach ( $rows as $inbox_row ) { $inbox_name_by_id[ (int) ( $inbox_row['id'] ?? 0 ) ] = (string) ( $inbox_row['name'] ?? '' ); }
			if ( ! empty( $inbox_ids ) && class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'list_personal_accounts' ) ) {
				foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) ) as $account ) {
					$owner_user_id = (int) ( $account['owner_user_id'] ?? 0 );
					$inbox_id      = (int) ( $account['crm_inbox_id'] ?? 0 );
					if ( $owner_user_id > 0 && $inbox_id > 0 ) {
						$owner_map[ $inbox_id ] = $owner_user_id;
					}
					if ( $inbox_id > 0 ) {
						$status = sanitize_key( (string) ( $account['status'] ?? 'unknown' ) );
						// [2026-09-18] R-ZP-ERR — 'revoked' (deleted) is a known state: shown as "Đã xoá", never offered QR, not counted dead.
						if ( ! in_array( $status, array( 'connected', 'pending_qr', 'expired', 'logged_out', 'revoked' ), true ) ) { $status = 'unknown'; }
						$session_state_map[ $inbox_id ] = array(
							'session_state' => $status,
							'can_relogin'   => in_array( $status, $dead_states, true ),
						);
						if ( 'revoked' !== $status ) {
							$owner_user = $owner_user_id > 0 ? get_userdata( $owner_user_id ) : false;
							$phone_name = trim( (string) ( $inbox_name_by_id[ $inbox_id ] ?? '' ) );
							if ( '' === $phone_name ) { $phone_name = trim( (string) ( $account['label'] ?? '' ) ); }
							$all_phones[ $inbox_id ] = array(
								'inbox_id'      => $inbox_id,
								'name'          => sanitize_text_field( '' !== $phone_name ? $phone_name : ( 'SĐT #' . $inbox_id ) ),
								'owner_user_id' => $owner_user_id,
								'owner_name'    => $owner_user_id > 0 ? ( $owner_user ? sanitize_text_field( (string) $owner_user->display_name ) : ( '#' . $owner_user_id ) ) : '',
								'session_state' => $status,
							);
						}
					}
				}
			}
			// [2026-09-18] PHASE-0.48F U10 DUP-10 — a logged-out phone whose Zalo login is now CONNECTED on
			// another account of this site is a duplicate, not a session to re-login: re-login would kick the
			// live one (R-ZP-DUP-2). Mark it so the rail says "Trùng SĐT" and offers cleanup instead of QR.
			if ( class_exists( 'BizCity_Zalo_Duplicate_Guard' ) && method_exists( 'BizCity_Zalo_Duplicate_Guard', 'find_twins' ) ) {
				foreach ( BizCity_Zalo_Duplicate_Guard::find_twins( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) ) ) as $dead_inbox_id => $twin ) {
					$session_state_map[ $dead_inbox_id ] = array(
						'session_state'         => 'duplicate',
						'can_relogin'           => false,
						'duplicate_of_inbox_id' => (int) $twin['crm_inbox_id'],
						'duplicate_of_label'    => (string) $twin['label'],
					);
				}
			}

			// 2) Business inbox membership.
			$member_map = array();
			// [2026-09-25] `member_role` per (inbox, user) — the "Người trực" sheet needs it to tell lead from agent.
			$member_role_map = array();
			$members_table = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
			if ( ! empty( $inbox_ids ) && BizCity_CRM_DB_Installer_V2::table_exists( $members_table ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
				$member_rows = $wpdb->get_results( $wpdb->prepare( "SELECT inbox_id, user_id, member_role FROM `{$members_table}` WHERE is_active = 1 AND inbox_id IN ({$placeholders})", $inbox_ids ), ARRAY_A );
				foreach ( is_array( $member_rows ) ? $member_rows : array() as $member_row ) {
					$inbox_id  = (int) ( $member_row['inbox_id'] ?? 0 );
					$user_id   = (int) ( $member_row['user_id'] ?? 0 );
					if ( $inbox_id > 0 && self::is_crm_assignable_user( $user_id ) ) {
						$member_map[ $inbox_id ][] = $user_id;
						$member_role_map[ $inbox_id ][ $user_id ] = sanitize_key( (string) ( $member_row['member_role'] ?? 'agent' ) );
					}
				}
			}

			// PHASE-0.48F T1-07 — open / waiting-past-SLA counts per inbox, one grouped query
			// (same last-message rule as `BizCity_CRM_Staff_REST::fetch_conversation_aggregates()`).
			$count_map = array();
			if ( ! empty( $inbox_ids ) ) {
				$conv_table = BizCity_CRM_DB_Installer_V2::tbl_conversations();
				$msg_table  = BizCity_CRM_DB_Installer_V2::tbl_messages();
				$wait_minutes = class_exists( 'BizCity_CRM_Staff_REST' ) ? (int) BizCity_CRM_Staff_REST::WAIT_MINUTES_BREACH : 15;
				$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $wait_minutes . ' minutes', current_time( 'timestamp' ) ) );
				$placeholders = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
				$count_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT c.inbox_id, COUNT(*) AS open_count,
						SUM(CASE WHEN m.message_type = 'incoming' AND m.created_at < %s THEN 1 ELSE 0 END) AS breach_count
					 FROM `{$conv_table}` c
					 LEFT JOIN `{$msg_table}` m ON m.id = c.last_message_id
					 WHERE c.status = 'open' AND c.inbox_id IN ({$placeholders})
					 GROUP BY c.inbox_id",
					array_merge( array( $cutoff ), $inbox_ids )
				), ARRAY_A );
				foreach ( is_array( $count_rows ) ? $count_rows : array() as $count_row ) {
					$count_map[ (int) $count_row['inbox_id'] ] = array(
						'open_count'   => (int) $count_row['open_count'],
						'breach_count' => (int) $count_row['breach_count'],
					);
				}
			}

			// PHASE-0.48F T1 — a team lead/supervisor without the rules cap sees only the staff
			// Staff_Policy lets them see, and no "unassigned" bucket (that is tenant administration).
			$visible_user_ids = null;
			if ( ! self::can_manage_rules() && class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
				$visible_user_ids = (array) ( BizCity_CRM_Staff_Policy::visible_user_ids( get_current_user_id() ) ?? array() );
			}

			$groups     = array();
			$unassigned = array();
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60H — the per-phone "Bot trả lời" sheet needs the bridge account
			// id (= inbox.channel_ref_id = Channel_Binding.account_id). Exposed ONLY to site admins, the same set
			// that may open that sheet (its REST routes are manage_options); staff/leads seeing the rail never get it.
			$expose_channel_ref = ( function_exists( 'is_super_admin' ) && is_super_admin() ) || current_user_can( 'manage_options' );
			foreach ( $rows as $row ) {
				$inbox_id = (int) ( $row['id'] ?? 0 );
				if ( $inbox_id <= 0 ) { continue; }
				$item = array(
					'id'           => $inbox_id,
					'name'         => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
					'channel_type' => sanitize_key( (string) ( $row['channel_type'] ?? '' ) ),
					'open_count'   => (int) ( $count_map[ $inbox_id ]['open_count'] ?? 0 ),
					'breach_count' => (int) ( $count_map[ $inbox_id ]['breach_count'] ?? 0 ),
					// [2026-09-24 Claude Sonnet 5] PHASE-0.60J BG-3 — who OWNS this phone (0 = nobody). A phone also appears under each
					// member's group, so the group alone cannot say who the owner is; the add-number picker needs it to ask before a change.
					'owner_user_id' => (int) ( $owner_map[ $inbox_id ] ?? 0 ),
				);
				if ( $expose_channel_ref && 'zalo_personal' === $item['channel_type'] ) {
					$item['channel_ref_id'] = sanitize_text_field( (string) ( $row['channel_ref_id'] ?? '' ) );
				}
				if ( isset( $session_state_map[ $inbox_id ] ) ) {
					$item = array_merge( $item, $session_state_map[ $inbox_id ] );
				}
				$user_ids = array();
				if ( isset( $owner_map[ $inbox_id ] ) ) {
					$user_ids[] = (int) $owner_map[ $inbox_id ];
				}
				foreach ( (array) ( $member_map[ $inbox_id ] ?? array() ) as $member_user_id ) {
					$user_ids[] = (int) $member_user_id;
				}
				$user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
				if ( empty( $user_ids ) ) {
					if ( null === $visible_user_ids ) { $unassigned[] = $item; }
					continue;
				}
				foreach ( $user_ids as $user_id ) {
					if ( ! self::is_crm_assignable_user( $user_id ) ) { continue; }
					if ( null !== $visible_user_ids && ! in_array( $user_id, $visible_user_ids, true ) ) { continue; }
					if ( ! isset( $groups[ $user_id ] ) ) {
						$user = get_userdata( $user_id );
						$groups[ $user_id ] = array(
							'user_id'      => $user_id,
							'display_name' => $user ? sanitize_text_field( (string) $user->display_name ) : ( '#' . $user_id ),
							'inboxes'      => array(),
							'open_count'   => 0,
							'breach_count' => 0,
							'dead_count'   => 0,
						);
					}
					$groups[ $user_id ]['inboxes'][] = $item;
					$groups[ $user_id ]['open_count']   += $item['open_count'];
					$groups[ $user_id ]['breach_count'] += $item['breach_count'];
					if ( ! empty( $item['can_relogin'] ) || 'duplicate' === ( $item['session_state'] ?? '' ) ) { $groups[ $user_id ]['dead_count']++; }
				}
			}

			// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N6 (§5.1 `business[]`) — OA/Facebook/WebChat
			// channels, one row per inbox (not nested per-member like `groups` above, even though the same
			// inbox also shows up there today via `$member_map`). `can.manage` is deliberately
			// `current_user_can('manage_options')` directly (not a `Staff_Policy` action — rule 6 in the doc
			// says kênh doanh nghiệp is admin-only to connect/reconfigure); `can.members` mirrors
			// `self::can_manage_teams()`, the ACTUAL gate on the existing `POST/DELETE /inboxes/{id}/members`
			// routes — not the doc's own §4 rule 6 text ("gán người trực theo inbox.member", i.e. a
			// `Staff_Policy` lead+ check), which does not match what those routes enforce today. Showing
			// `Staff_Policy`'s looser rank-2 answer here would offer a "Người trực" control to leads who the
			// real endpoint would then 403 — surfacing the stricter, TRUE gate instead.
			$business = array();
			$business_channel_types = array( 'zalo_oa', 'facebook', 'facebook_page', 'webchat' );
			// Deliberately two different checks, not one reused value: `manage` (connect/reconfigure the
			// channel, rule 6 "chỉ admin") is strictly `manage_options`; `members` mirrors the real
			// `POST/DELETE /inboxes/{id}/members` gate (`can_manage_teams()`), which also allows the
			// narrower `bizcity_crm_manage_teams` capability without full `manage_options`.
			$can_manage_channel = current_user_can( 'manage_options' );
			$can_manage_members = self::can_manage_teams();
			foreach ( $rows as $row ) {
				$inbox_id = (int) ( $row['id'] ?? 0 );
				$channel_type = sanitize_key( (string) ( $row['channel_type'] ?? '' ) );
				if ( $inbox_id <= 0 || ! in_array( $channel_type, $business_channel_types, true ) ) { continue; }
				$state = 'connected';
				if ( 'zalo_oa' === $channel_type && class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
					// OA accounts live in the same `bizcity_zalo_accounts` table as Zalo Personal (`kind = 'oa'`),
					// same `status` column — no separate OA session model to build.
					$oa_account = BizCity_Zalo_Mapping_Repo::find_account_by_crm_inbox_id( $inbox_id );
					if ( is_array( $oa_account ) ) { $state = sanitize_key( (string) ( $oa_account['status'] ?? 'connected' ) ); }
				}
				$members = array();
				foreach ( (array) ( $member_map[ $inbox_id ] ?? array() ) as $member_user_id ) {
					if ( ! self::is_crm_assignable_user( (int) $member_user_id ) ) { continue; }
					$member_user = get_userdata( (int) $member_user_id );
					$members[] = array(
						'user_id'      => (int) $member_user_id,
						'display_name' => $member_user ? sanitize_text_field( (string) $member_user->display_name ) : ( '#' . $member_user_id ),
						'member_role'  => (string) ( $member_role_map[ $inbox_id ][ (int) $member_user_id ] ?? 'agent' ),
					);
				}
				$business[] = array(
					'inbox_id' => $inbox_id,
					'channel'  => $channel_type,
					'name'     => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
					'state'    => $state,
					'members'  => $members,
					'can'      => array(
						'manage'  => $can_manage_channel,
						'members' => $can_manage_members,
					),
				);
			}

			// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N1 — `?include=staff` adds every D1-eligible
			// user (administrator + editor + `bizcity_crm_staff`, same roster as `GET /crm-staff`) who has
			// no inbox at all yet, so the rail can show a "Chưa có SĐT Zalo · [+ Thêm SĐT]" invite row
			// instead of the person disappearing (doc gap G1). Scoped the same way the loop above already
			// scopes existing groups: null `$visible_user_ids` = administrator (tenant-wide), otherwise the
			// actor's own team.
			$include = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', (string) ( $req->get_param( 'include' ) ?? '' ) ) ) ) );
			if ( in_array( 'staff', $include, true ) ) {
				$roster_ids = null !== $visible_user_ids
					? $visible_user_ids
					: ( class_exists( 'BizCity_CRM_Staff_REST' ) ? BizCity_CRM_Staff_REST::admin_staff_user_ids() : array() );
				foreach ( (array) $roster_ids as $roster_user_id ) {
					$roster_user_id = (int) $roster_user_id;
					if ( $roster_user_id <= 0 || isset( $groups[ $roster_user_id ] ) || ! self::is_crm_assignable_user( $roster_user_id ) ) { continue; }
					$roster_user = get_userdata( $roster_user_id );
					$groups[ $roster_user_id ] = array(
						'user_id'      => $roster_user_id,
						'display_name' => $roster_user ? sanitize_text_field( (string) $roster_user->display_name ) : ( '#' . $roster_user_id ),
						'inboxes'      => array(),
						'open_count'   => 0,
						'breach_count' => 0,
						'dead_count'   => 0,
					);
				}
			}

			// PHASE-0.53 N1 (§5.1) — `role_label` + `can{assign_phone,qr,transfer,remove}` + `quota{used,limit}`
			// per group, so the FE can hide/disable the `+`/QR/Chuyển/Gỡ actions per P7 without guessing the
			// actor's rank client-side. `assign_phone` gates the S1 "+" (add a NEW phone for this person):
			// true for your own row (self-add never runs an ACL check — see `Staff_Policy::MIN_RANK` note on
			// `phone.add_for_other`), otherwise the fail-closed `phone.add_for_other` (D2, administrator-only).
			// `transfer`/`remove` both gate on `phone.assign`, matching S4/S5's access column in the doc exactly.
			$actor_id       = get_current_user_id();
			$has_policy     = class_exists( 'BizCity_CRM_Staff_Policy' );
			$has_grant_layer = class_exists( 'BizCity_Channel_User_Grant' );
			foreach ( $groups as $group_user_id => &$group ) {
				$group['role_label'] = $has_policy ? BizCity_CRM_Staff_Policy::label( BizCity_CRM_Staff_Policy::role( $group_user_id ) ) : '—';
				$can_assign = $has_policy && ! empty( BizCity_CRM_Staff_Policy::can( $actor_id, 'phone.assign', $group_user_id )['ok'] );
				$group['can'] = array(
					'assign_phone' => $group_user_id === $actor_id || ( $has_policy && ! empty( BizCity_CRM_Staff_Policy::can( $actor_id, 'phone.add_for_other', $group_user_id )['ok'] ) ),
					'qr'           => $has_policy && ! empty( BizCity_CRM_Staff_Policy::can( $actor_id, 'phone.qr', $group_user_id )['ok'] ),
					'transfer'     => $can_assign,
					'remove'       => $can_assign,
				);
				$quota_status = $has_grant_layer ? BizCity_Channel_User_Grant::personal_quota_status( $group_user_id ) : array();
				$group['quota'] = array(
					'used'  => (int) ( $quota_status['owned'] ?? 0 ),
					'limit' => (int) ( $quota_status['quota'] ?? 0 ),
				);
			}
			unset( $group );

			$out = array_values( $groups );
			// Mockup §2.1: groups with a dead session float to the top, then most customers waiting past SLA.
			usort( $out, static function ( $a, $b ) {
				return ( ( $b['dead_count'] > 0 ) <=> ( $a['dead_count'] > 0 ) )
					?: ( $b['breach_count'] <=> $a['breach_count'] )
					?: strcasecmp( (string) $a['display_name'], (string) $b['display_name'] );
			} );

			return array(
				'contract'   => 'crm-inbox-user-groups',
				// 1.1.0 added optional `session_state`/`can_relogin`; 1.2.0 adds optional
				// `open_count`/`breach_count` (inbox + group) and `dead_count` (group).
				// 1.3.0 adds session_state 'duplicate' + optional duplicate_of_inbox_id/duplicate_of_label (R-ZP-DUP).
				// 1.4.0 (PHASE-0.53 N1) adds `?include=staff` (0-phone D1 members) and, on every group,
				// `role_label` + `can{assign_phone,qr,transfer,remove}` + `quota{used,limit}`.
				// 1.5.0 (PHASE-0.53 N6) adds `business[]` (OA/Facebook/WebChat channels, one row per inbox).
				'version'    => '1.6.0',
				'scoped'     => null !== $visible_user_ids,
				'wait_minutes' => class_exists( 'BizCity_CRM_Staff_REST' ) ? (int) BizCity_CRM_Staff_REST::WAIT_MINUTES_BREACH : 15,
				'groups'     => $out,
				'unassigned' => $unassigned,
				'business'   => $business,
				// 1.6.0 (PHASE-0.60J) — `phones[]`: every Zalo Cá nhân phone + current owner, unscoped viewers (administrators) only.
				'phones'     => null === $visible_user_ids ? array_values( $all_phones ) : array(),
			);
		} );
	}
}
