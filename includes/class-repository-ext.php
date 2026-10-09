<?php
/**
 * BizCity CRM — Repository (write gate).
 *
 * SOLE entry point for INSERT/UPDATE on CRM tables (R-CRM-1).
 * Every state-change emits a Twin Event Stream event.
 *
 * Cache Contract (R-CACHE): group `crm_repository`; keys include the current
 * blog, routed database and hashed `(channel_type, channel_ref_id)` tuple;
 * inbox writes flush the group; TTL is short for account selection reads.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Repository_Ext extends BizCity_CRM_Repository {

	/** List member-visible care tasks without exposing internal notes or tenant-wide rows. */
	public static function list_tasks_for_member( int $wp_user_id, int $limit = 50 ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F8 — expose only assigned or own-contact tasks to the C surface.
		if ( $wp_user_id <= 0 ) { return array(); }
		global $wpdb;
		// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — resolve table names through the canonical installer owner.
		$tasks = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$contacts = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$limit = max( 1, min( 100, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.title, t.status, t.priority, t.due_date, t.assignee_id, t.related_entity_type, t.related_entity_id, t.updated_at FROM `{$tasks}` t WHERE t.deleted_at IS NULL AND (t.assignee_id = %d OR (t.related_entity_type = 'contact' AND EXISTS (SELECT 1 FROM `{$contacts}` c WHERE c.id = t.related_entity_id AND c.wp_user_id = %d AND c.deleted_at IS NULL))) ORDER BY t.due_date IS NULL ASC, t.due_date ASC, t.id DESC LIMIT %d", $wp_user_id, $wp_user_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function can_user_move_order_care( string $object_type, int $object_id, int $user_id = 0 ): bool {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-S3 — enforce task/opportunity ownership at the repository boundary.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		if ( $object_id <= 0 || $user_id <= 0 ) { return false; }
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		global $wpdb;
		if ( 'task' === $object_type ) {
			// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — keep order-care authorization on the canonical table-name owner.
			$tasks = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$contacts = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT assignee_id, related_entity_type, related_entity_id FROM `{$tasks}` WHERE id = %d AND deleted_at IS NULL LIMIT 1", $object_id ), ARRAY_A );
			if ( ! is_array( $row ) ) { return false; }
			if ( (int) ( $row['assignee_id'] ?? 0 ) === $user_id ) { return true; }
			return 'contact' === (string) ( $row['related_entity_type'] ?? '' ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$contacts}` WHERE id = %d AND wp_user_id = %d AND deleted_at IS NULL LIMIT 1", (int) $row['related_entity_id'], $user_id ) );
		}
		if ( 'opportunity' === $object_type ) {
			// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — resolve opportunity table through the canonical installer owner.
			$opportunities = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$opportunities}` WHERE id = %d AND owner_id = %d AND deleted_at IS NULL LIMIT 1", $object_id, $user_id ) );
		}
		return false;
	}

	public static function move_order_care_object( string $object_type, int $object_id, string $target_state, array $changes = array(), int $actor_user_id = 0 ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-S3 — own task/opportunity board state mutation and return repeat-safe current-state outcomes.
		$object_type = sanitize_key( $object_type );
		$target_state = sanitize_key( $target_state );
		$actor_user_id = $actor_user_id > 0 ? $actor_user_id : (int) get_current_user_id();
		if ( ! in_array( $object_type, array( 'task', 'opportunity' ), true ) || $object_id <= 0 ) {
			return array( 'success' => false, 'outcome' => 'permanent_failed', 'code' => 'invalid_order_care_object', 'retryable' => false, 'contract_version' => '1.0.0' );
		}
		if ( ! self::can_user_move_order_care( $object_type, $object_id, $actor_user_id ) ) {
			return array( 'success' => false, 'outcome' => 'permanent_failed', 'code' => 'order_care_scope_denied', 'retryable' => false, 'contract_version' => '1.0.0' );
		}
		global $wpdb;
		// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — use canonical table helpers for order-care mutation after authorization.
		$table = 'task' === $object_type ? BizCity_CRM_DB_Installer_V2::tbl_crm_tasks() : BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND deleted_at IS NULL LIMIT 1", $object_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array( 'success' => false, 'outcome' => 'permanent_failed', 'code' => 'order_care_object_not_found', 'retryable' => false, 'contract_version' => '1.0.0' );
		}
		$allowed = 'task' === $object_type
			? array( 'open', 'pending', 'in_progress', 'done', 'cancelled' )
			: array( 'prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost' );
		if ( ! in_array( $target_state, $allowed, true ) ) {
			return array( 'success' => false, 'outcome' => 'permanent_failed', 'code' => 'invalid_order_care_state', 'retryable' => false, 'contract_version' => '1.0.0' );
		}
		$current_state = 'task' === $object_type ? sanitize_key( (string) ( $row['status'] ?? '' ) ) : sanitize_key( (string) ( $row['stage'] ?? '' ) );
		if ( $current_state === $target_state ) {
			return array( 'success' => true, 'outcome' => 'already_applied', 'code' => 'already_applied', 'retryable' => false, 'contract_version' => '1.0.0', 'object_type' => $object_type, 'object_id' => $object_id, 'target_state' => $target_state );
		}
		$fields = array( 'updated_at' => current_time( 'mysql' ) );
		if ( 'task' === $object_type ) {
			$fields['status'] = $target_state;
			$fields['completed'] = 'done' === $target_state ? 1 : 0;
			$fields['completed_at'] = 'done' === $target_state ? current_time( 'mysql' ) : null;
			if ( isset( $changes['priority'] ) && in_array( (string) $changes['priority'], array( 'low', 'medium', 'high', 'urgent' ), true ) ) { $fields['priority'] = sanitize_key( (string) $changes['priority'] ); }
			if ( array_key_exists( 'due_date', $changes ) ) { $fields['due_date'] = $changes['due_date'] ? sanitize_text_field( (string) $changes['due_date'] ) : null; }
		} else {
			$fields['stage'] = $target_state;
		}
		if ( false === $wpdb->update( $table, $fields, array( 'id' => $object_id ) ) ) {
			return array( 'success' => false, 'outcome' => 'retryable', 'code' => 'order_care_move_failed', 'retryable' => true, 'contract_version' => '1.0.0' );
		}
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_order_care_moved', array( 'object_type' => $object_type, 'object_id' => $object_id, 'from_state' => $current_state, 'to_state' => $target_state, 'by_user_id' => $actor_user_id ) );
		}
		if ( class_exists( 'BizCity_Cache_Registry' ) ) { BizCity_Cache_Registry::flush_module( 'modules.twin-crm' ); }
		return array( 'success' => true, 'outcome' => 'updated', 'code' => 'moved', 'retryable' => false, 'contract_version' => '1.0.0', 'object_type' => $object_type, 'object_id' => $object_id, 'from_state' => $current_state, 'target_state' => $target_state );
	}

	/**
	 * @param array $args { event_name?, active?, inbox_id?, q?, limit?, offset? }
	 */
	public static function list_automation_rules( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['event_name'] ) ) {
			$where[]  = 'event_name = %s';
			$params[] = (string) $args['event_name'];
		}
		if ( isset( $args['active'] ) ) {
			$where[]  = 'active = %d';
			$params[] = (int) (bool) $args['active'];
		}
		if ( isset( $args['inbox_id'] ) ) {
			$where[]  = '(inbox_id IS NULL OR inbox_id = %d)';
			$params[] = (int) $args['inbox_id'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 100 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql    = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where )
				. " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_automation_rule( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Insert (when id missing/0) or update (when id > 0).
	 *
	 * @return int Rule id (0 on failure).
	 */
	public static function upsert_automation_rule( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );

		$cond_json = isset( $data['conditions'] ) && ! is_string( $data['conditions'] )
			? wp_json_encode( $data['conditions'] )
			: ( $data['conditions_json'] ?? null );
		$act_json  = isset( $data['actions'] ) && ! is_string( $data['actions'] )
			? wp_json_encode( $data['actions'] )
			: ( $data['actions_json'] ?? null );

		$row = array(
			'name'            => (string) ( $data['name'] ?? '' ),
			'description'     => isset( $data['description'] ) ? (string) $data['description'] : null,
			'event_name'      => (string) ( $data['event_name'] ?? '' ),
			'inbox_id'        => isset( $data['inbox_id'] ) && (int) $data['inbox_id'] > 0 ? (int) $data['inbox_id'] : null,
			'conditions_json' => $cond_json,
			'actions_json'    => $act_json,
			'active'          => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'      => $now,
		);

		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['run_count']     = 0;
		$row['last_run_at']   = null;
		$row['created_by_id'] = isset( $data['created_by_id'] ) ? (int) $data['created_by_id'] : ( get_current_user_id() ?: null );
		$row['created_at']    = $now;
		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) { return 0; }
		return (int) $wpdb->insert_id;
	}

	public static function delete_automation_rule( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	public static function bump_rule_run_count( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$now = current_time( 'mysql' );
		$ok  = $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET run_count = run_count + 1, last_run_at = %s WHERE id = %d",
			$now, $id
		) );
		return (bool) $ok;
	}

	public static function list_custom_attribute_defs( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['target'] ) ) {
			$where[]  = 'target = %s';
			$params[] = (string) $args['target'];
		}
		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY display_name ASC';
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_custom_attribute_def( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function upsert_custom_attribute_def( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );
		$row = array(
			'attribute_key' => sanitize_key( (string) ( $data['attribute_key'] ?? '' ) ),
			'display_name'  => (string) ( $data['display_name'] ?? '' ),
			'description'   => isset( $data['description'] ) ? (string) $data['description'] : null,
			'display_type'  => (string) ( $data['display_type'] ?? 'text' ),
			'target'        => (string) ( $data['target'] ?? 'contact' ),
			'regex_pattern' => isset( $data['regex_pattern'] ) ? (string) $data['regex_pattern'] : null,
			'options_json'  => isset( $data['options'] ) && ! is_string( $data['options'] )
				? wp_json_encode( $data['options'] )
				: ( $data['options_json'] ?? null ),
			'default_value' => isset( $data['default_value'] ) ? (string) $data['default_value'] : null,
			'updated_at'    => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_custom_attribute_def( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	public static function list_macros( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$where  = array( '1=1' );
		$params = array();
		if ( isset( $args['active'] ) ) {
			$where[]  = 'active = %d';
			$params[] = (int) (bool) $args['active'];
		}
		if ( ! empty( $args['visibility'] ) ) {
			$where[]  = 'visibility = %s';
			$params[] = (string) $args['visibility'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC';
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_macro( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function upsert_macro( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );
		$row = array(
			'name'         => (string) ( $data['name'] ?? '' ),
			'description'  => isset( $data['description'] ) ? (string) $data['description'] : null,
			'visibility'   => (string) ( $data['visibility'] ?? 'global' ),
			'owner_user_id'=> isset( $data['owner_user_id'] ) ? (int) $data['owner_user_id'] : ( get_current_user_id() ?: null ),
			'template'     => isset( $data['template'] ) ? (string) $data['template'] : null,
			'actions_json' => isset( $data['actions'] ) && ! is_string( $data['actions'] )
				? wp_json_encode( $data['actions'] )
				: ( $data['actions_json'] ?? null ),
			'active'       => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'   => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = $now;
		$row['run_count']  = 0;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_macro( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	public static function bump_macro_run_count( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$now = current_time( 'mysql' );
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET run_count = run_count + 1, last_used_at = %s WHERE id = %d",
			$now, $id
		) );
	}

	/**
	 * Default schedule (used by seeder and fallback when no row exists):
	 *   Mon-Fri 09:00–18:00 open · Sat-Sun closed.
	 *   day_of_week: 0=Sun, 1=Mon, ..., 6=Sat (matches PHP `date('w')`).
	 */
	public static function default_working_hours_grid(): array {
		$grid = array();
		for ( $d = 0; $d <= 6; $d++ ) {
			$grid[] = array(
				'day_of_week' => $d,
				'is_open'     => ( $d >= 1 && $d <= 5 ) ? 1 : 0,
				'open_time'   => '09:00:00',
				'close_time'  => '18:00:00',
			);
		}
		return $grid;
	}

	public static function list_working_hours( int $inbox_id ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_working_hours();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE inbox_id = %d ORDER BY day_of_week ASC",
			$inbox_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function ensure_working_hours_seeded( int $inbox_id ): int {
		$existing = self::list_working_hours( $inbox_id );
		if ( ! empty( $existing ) ) { return 0; }
		$inserted = 0;
		foreach ( self::default_working_hours_grid() as $row ) {
			$row['inbox_id'] = $inbox_id;
			if ( self::upsert_working_hour_row( $row ) ) { $inserted++; }
		}
		return $inserted;
	}

	public static function upsert_working_hour_row( array $row ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_working_hours();
		$now = current_time( 'mysql' );
		$inbox_id = (int) ( $row['inbox_id']    ?? 0 );
		$dow      = (int) ( $row['day_of_week'] ?? -1 );
		if ( $inbox_id <= 0 || $dow < 0 || $dow > 6 ) { return false; }
		$data = array(
			'inbox_id'    => $inbox_id,
			'day_of_week' => $dow,
			'is_open'     => isset( $row['is_open'] ) ? (int) (bool) $row['is_open'] : 1,
			'open_time'   => self::sanitize_time( $row['open_time']  ?? '09:00:00' ),
			'close_time'  => self::sanitize_time( $row['close_time'] ?? '18:00:00' ),
			'updated_at'  => $now,
		);
		// Try insert; if PK collision, update.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$tbl} WHERE inbox_id = %d AND day_of_week = %d",
			$inbox_id, $dow
		) );
		if ( $existing ) {
			return false !== $wpdb->update(
				$tbl,
				$data,
				array( 'inbox_id' => $inbox_id, 'day_of_week' => $dow ),
				array( '%d', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);
		}
		$data['created_at'] = $now;
		return false !== $wpdb->insert( $tbl, $data );
	}

	private static function sanitize_time( string $t ): string {
		$ts = strtotime( '1970-01-01 ' . $t );
		return $ts ? gmdate( 'H:i:s', $ts ) : '09:00:00';
	}

	public static function list_sla_policies( array $args = array() ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		$where = array( '1=1' );
		$prep  = array();
		if ( isset( $args['active'] ) ) {
			$where[] = 'active = %d';
			$prep[]  = (int) (bool) $args['active'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[] = 'name LIKE %s';
			$prep[]  = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
		}
		$sql = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC LIMIT 200';
		$sql = $prep ? $wpdb->prepare( $sql, ...$prep ) : $sql;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_sla_policy( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		// [2026-09-21 10:50 PM OpenAI GPT-5.6 Luna] HOTFIX — policy lookup must also degrade when the optional legacy SLA schema is absent.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function upsert_sla_policy( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		$now = current_time( 'mysql' );
		$row = array(
			'name'                       => trim( (string) ( $data['name'] ?? '' ) ),
			'description'                => isset( $data['description'] ) ? (string) $data['description'] : null,
			'frt_threshold_minutes'      => isset( $data['frt_threshold_minutes'] ) ? (int) $data['frt_threshold_minutes'] : null,
			'nrt_threshold_minutes'      => isset( $data['nrt_threshold_minutes'] ) ? (int) $data['nrt_threshold_minutes'] : null,
			'rt_threshold_minutes'       => isset( $data['rt_threshold_minutes'] )  ? (int) $data['rt_threshold_minutes']  : null,
			'only_during_business_hours' => isset( $data['only_during_business_hours'] ) ? (int) (bool) $data['only_during_business_hours'] : 0,
			'active'                     => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'                 => $now,
		);
		if ( ! empty( $data['id'] ) ) {
			$id = (int) $data['id'];
			$wpdb->update( $tbl, $row, array( 'id' => $id ), null, array( '%d' ) );
			return $id;
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_sla_policy( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	public static function get_applied_sla_for_conversation( int $conv_id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		// [2026-09-21 10:45 PM OpenAI GPT-5.6 Luna] HOTFIX — legacy SLA inspection must fail open when a tenant has not installed the optional SLA schema.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE conversation_id = %d",
			$conv_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function upsert_applied_sla( array $data ): int {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		$now    = current_time( 'mysql' );
		$convid = (int) ( $data['conversation_id'] ?? 0 );
		if ( $convid <= 0 ) { return 0; }
		$existing = self::get_applied_sla_for_conversation( $convid );
		$row = array(
			'conversation_id'   => $convid,
			'sla_policy_id'     => (int) ( $data['sla_policy_id'] ?? 0 ),
			'applied_at'        => isset( $data['applied_at'] ) ? (int) $data['applied_at'] : time(),
			'frt_due_at'        => isset( $data['frt_due_at'] ) ? (int) $data['frt_due_at'] : null,
			'nrt_due_at'        => isset( $data['nrt_due_at'] ) ? (int) $data['nrt_due_at'] : null,
			'rt_due_at'         => isset( $data['rt_due_at'] )  ? (int) $data['rt_due_at']  : null,
			'frt_breached_at'   => isset( $data['frt_breached_at'] ) ? (int) $data['frt_breached_at'] : null,
			'nrt_breached_at'   => isset( $data['nrt_breached_at'] ) ? (int) $data['nrt_breached_at'] : null,
			'rt_breached_at'    => isset( $data['rt_breached_at'] )  ? (int) $data['rt_breached_at']  : null,
			'met_at'            => isset( $data['met_at'] )            ? (int) $data['met_at']            : null,
			'last_evaluated_at' => isset( $data['last_evaluated_at'] ) ? (int) $data['last_evaluated_at'] : null,
			'state'             => (string) ( $data['state'] ?? 'active' ),
			'updated_at'        => $now,
		);
		if ( $existing ) {
			$wpdb->update( $tbl, $row, array( 'id' => (int) $existing['id'] ), null, array( '%d' ) );
			return (int) $existing['id'];
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_applied_sla_fields( int $id, array $fields ): bool {
		global $wpdb;
		if ( empty( $fields ) ) { return false; }
		$fields['updated_at'] = current_time( 'mysql' );
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		return false !== $wpdb->update( $tbl, $fields, array( 'id' => $id ), null, array( '%d' ) );
	}

	public static function list_active_applied_slas( int $limit = 500 ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		// PHASE 0.35 fix — silently skip on subsites where CRM schema isn't installed.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE state IN ('active','breached') AND (met_at IS NULL OR state = 'breached') ORDER BY frt_due_at ASC LIMIT %d",
			$limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
