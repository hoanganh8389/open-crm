<?php
/**
 * BizCity_CRM_Announce_Store — "Lịch thông báo" rows (PHASE-0.146, doc 21 §3/§6): one row of `bizcity_crm_broadcasts` per Zalo number
 * (`channel_kind = zalo_group_broadcast`, D146-15), recipients = conversations picked in the Inbox (`bizcity_crm_broadcast_recipients`).
 *
 * The site DEFINES (content, recipients, schedule, rev); the cell keeps the clock and the run ledger (ADR 15, D146-01). Pure helpers
 * (split_by_account · validate_* · next_rev · build_pack · shape_item) take arrays and touch no DB so the unit test pins them.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 *
 * [2026-10-28 06:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L3 — new file.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Announce_Store {

	const KIND           = 'zalo_group_broadcast';
	const DRAFT_TTL_DAYS = 7;
	const STATES         = array( 'draft', 'scheduling', 'active', 'paused', 'ended', 'error' );
	const MODES          = array( 'now', 'once', 'daily', 'monthly', 'dates' );
	/** Contract limits (cell zod, doc 21 §3) — over these the request is refused. */
	const HARD_MAX_VARIANTS = 3;
	const HARD_MAX_TEXT     = 2000;
	const HARD_MAX_FILES    = 5;
	const HARD_MAX_BYTES    = 26214400; // 25 MB
	const HARD_MAX_RECIPIENTS = 1000;
	const MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'video/mp4' );

	/** Test seams: now():int · tz():string · attachment(id):?array{url,mime,size,name,path} · accounts():list · user_is_admin(uid) · user_rank(uid) */
	public static $readers = array();

	/** Defaults the owner can change (D146-00: warnings, never a refusal) — doc 21 §6 `announce/boot`. */
	public static function defaults(): array {
		return array(
			'max_recipients' => (int) apply_filters( 'bizcity_crm_announce_max_recipients', 100 ),
			'max_per_day'    => 200,
			'gap_min_s'      => 20,
			'gap_max_s'      => 45,
			'max_files'      => self::HARD_MAX_FILES,
			'max_image_mb'   => 5,
			'max_file_mb'    => 20,
			'max_variants'   => self::HARD_MAX_VARIANTS,
			'max_text'       => self::HARD_MAX_TEXT,
			'quiet'          => array( 'from' => '21:00', 'to' => '07:00' ),
		);
	}

	public static function new_ref( string $prefix ): string {
		return $prefix . '-' . bin2hex( random_bytes( 3 ) );
	}

	/* ── pure ──────────────────────────────────────────────────────────── */

	/**
	 * Conversation rows (see conversations_by_ids) ⇒ one recipient list per Zalo number + what cannot be announced.
	 * A zalo_personal inbox whose channel_ref_id is the bridge account id; group = `source_id` 'group:<gid>'.
	 *
	 * @param array      $rows     list<{conversation_id, channel_type, account_ref, source_id, contact_id, name, attributes}>
	 * @param array      $ids      the ids asked for (missing ones ⇒ unsupported not_found)
	 * @param array|null $accounts bridge ids the actor may send from; null = every number
	 * @return array{groups:array<string,array>,unsupported:array}
	 */
	public static function split_by_account( array $rows, array $ids, $accounts = null ): array {
		$groups      = array();
		$unsupported = array();
		$seen        = array();
		foreach ( $rows as $r ) {
			$cid = (int) ( $r['conversation_id'] ?? 0 );
			if ( $cid <= 0 || isset( $seen[ $cid ] ) ) {
				continue;
			}
			$seen[ $cid ] = true;
			$display = self::display_of( $r );
			$account = preg_replace( '/[^0-9]/', '', (string) ( $r['account_ref'] ?? '' ) );
			if ( 'zalo_personal' !== (string) ( $r['channel_type'] ?? '' ) || '' === $account ) {
				$unsupported[] = array( 'conversation_id' => $cid, 'display' => $display, 'reason' => 'not_zalo_personal' );
				continue;
			}
			if ( is_array( $accounts ) && ! in_array( $account, array_map( 'strval', $accounts ), true ) ) {
				$unsupported[] = array( 'conversation_id' => $cid, 'display' => $display, 'reason' => 'account_not_allowed' );
				continue;
			}
			$source   = (string) ( $r['source_id'] ?? '' );
			$is_group = 0 === strpos( $source, 'group:' );
			$thread   = preg_replace( '/[^0-9]/', '', $is_group ? substr( $source, 6 ) : $source );
			if ( '' === $thread ) {
				$unsupported[] = array( 'conversation_id' => $cid, 'display' => $display, 'reason' => 'no_thread' );
				continue;
			}
			$groups[ $account ][] = array(
				'conversation_id' => $cid,
				'contact_id'      => (int) ( $r['contact_id'] ?? 0 ),
				'kind'            => $is_group ? 'group' : 'user',
				'thread_ref'      => $thread,
				'display'         => $display,
			);
		}
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
				$unsupported[] = array( 'conversation_id' => $id, 'display' => '', 'reason' => 'not_found' );
			}
		}
		return array( 'groups' => $groups, 'unsupported' => $unsupported );
	}

	private static function display_of( array $r ): string {
		$attrs = $r['attributes'] ?? null;
		$attrs = is_array( $attrs ) ? $attrs : ( is_string( $attrs ) && '' !== $attrs ? json_decode( $attrs, true ) : array() );
		$name  = is_array( $attrs ) && '' !== (string) ( $attrs['group_name'] ?? '' ) ? (string) $attrs['group_name'] : (string) ( $r['name'] ?? '' );
		return self::cut( trim( wp_strip_all_tags( $name ) ), 120 );
	}

	/** ≤ 3 non-empty variants of ≤ 2 000 characters (contract). @return array|WP_Error */
	public static function validate_variants( $variants ) {
		$out = array();
		foreach ( (array) $variants as $v ) {
			$v = trim( (string) $v );
			if ( '' === $v ) {
				continue;
			}
			if ( self::len( $v ) > self::HARD_MAX_TEXT ) {
				return new WP_Error( 'broadcast_text_too_long', 'Một biến thể dài quá ' . self::HARD_MAX_TEXT . ' ký tự.', array( 'status' => 400, 'hint' => 'Rút gọn nội dung hoặc tách thành tệp đính kèm.' ) );
			}
			$out[] = $v;
		}
		if ( count( $out ) > self::HARD_MAX_VARIANTS ) {
			return new WP_Error( 'broadcast_too_many_variants', 'Tối đa ' . self::HARD_MAX_VARIANTS . ' biến thể.', array( 'status' => 400, 'hint' => 'Bỏ bớt biến thể.' ) );
		}
		return $out;
	}

	/**
	 * schedule_json (doc 20 §3) ⇒ normalized, or WP_Error 400 broadcast_schedule_invalid. Quiet hours are only a warning (D146-10).
	 *
	 * @return array|WP_Error
	 */
	public static function validate_schedule( $s, string $tz ) {
		$bad = static function ( string $why ) {
			return new WP_Error( 'broadcast_schedule_invalid', 'Lịch gửi chưa hợp lệ: ' . $why . '.', array( 'status' => 400, 'hint' => 'Chọn lại ở bước ③ Lịch.' ) );
		};
		if ( ! is_array( $s ) ) {
			return $bad( 'thiếu lịch' );
		}
		$mode = (string) ( $s['mode'] ?? '' );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return $bad( 'chế độ không có' );
		}
		$tz_in = (string) ( $s['tz'] ?? '' );
		$tz    = '' !== $tz_in && in_array( $tz_in, timezone_identifiers_list(), true ) ? $tz_in : $tz;
		$time_ok = static function ( $t ) {
			return is_string( $t ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t );
		};
		$date_ok = static function ( $d ) {
			return is_string( $d ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) );
		};
		$out = array( 'mode' => $mode, 'tz' => $tz );
		if ( 'now' === $mode ) {
			return array( 'mode' => 'now' );
		}
		if ( 'once' === $mode ) {
			$at = (string) ( $s['at'] ?? '' );
			try {
				$dt = '' !== $at ? new DateTimeImmutable( $at, new DateTimeZone( $tz ) ) : null;
			} catch ( \Throwable $e ) {
				$dt = null;
			}
			if ( ! $dt ) {
				return $bad( 'thiếu giờ gửi' );
			}
			$out['at'] = $dt->format( 'c' );
			return $out;
		}
		if ( ! $time_ok( $s['time'] ?? null ) ) {
			return $bad( 'giờ phải dạng HH:MM' );
		}
		$out['time'] = (string) $s['time'];
		if ( 'daily' === $mode ) {
			$days = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $s['weekdays'] ?? array( 0, 1, 2, 3, 4, 5, 6 ) ) ), static function ( $d ) {
				return $d >= 0 && $d <= 6;
			} ) ) );
			if ( ! $days ) {
				return $bad( 'chọn ít nhất một thứ' );
			}
			sort( $days );
			$out['weekdays'] = $days;
		} elseif ( 'monthly' === $mode ) {
			$day = $s['day'] ?? 1;
			if ( 'last' !== $day && ( (int) $day < 1 || (int) $day > 28 ) ) {
				return $bad( 'ngày trong tháng 1–28 hoặc cuối tháng' );
			}
			$out['day'] = 'last' === $day ? 'last' : (int) $day;
		} else {
			$dates = array_values( array_unique( array_filter( (array) ( $s['dates'] ?? array() ), $date_ok ) ) );
			sort( $dates );
			if ( ! $dates || count( $dates ) > 31 ) {
				return $bad( 'chọn 1–31 ngày' );
			}
			$out['dates'] = $dates;
			return $out;
		}
		foreach ( array( 'start', 'end' ) as $k ) {
			if ( isset( $s[ $k ] ) && '' !== (string) $s[ $k ] ) {
				if ( ! $date_ok( $s[ $k ] ) ) {
					return $bad( 'ngày bắt đầu / kết thúc sai' );
				}
				$out[ $k ] = (string) $s[ $k ];
			}
		}
		if ( isset( $out['start'], $out['end'] ) && $out['end'] < $out['start'] ) {
			return $bad( 'ngày kết thúc trước ngày bắt đầu' );
		}
		$out['max_runs'] = isset( $s['max_runs'] ) && (int) $s['max_runs'] > 0 ? (int) $s['max_runs'] : null;
		return $out;
	}

	/** Any change of content / recipients / schedule of a row the cell already holds is a new rev (Q146-4: applies from the next run). */
	public static function next_rev( int $rev, string $state, bool $changed ): int {
		if ( ! $changed || 'draft' === $state ) {
			return max( 1, $rev );
		}
		return max( 1, $rev ) + 1;
	}

	/** Over-default notes (D146-00): never a refusal. */
	public static function warnings( array $row, int $recipients, array $attachments ): array {
		$d   = self::defaults();
		$out = array();
		if ( $recipients > $d['max_recipients'] ) {
			$out[] = array( 'code' => 'broadcast_over_default_recipients', 'message' => 'Hơn ' . $d['max_recipients'] . ' người nhận một lần chạy — lâu hơn và dễ bị Zalo hạn chế; vẫn gửi được.' );
		}
		foreach ( $attachments as $a ) {
			$limit = 0 === strpos( (string) ( $a['mime'] ?? '' ), 'image/' ) ? $d['max_image_mb'] : $d['max_file_mb'];
			if ( (int) ( $a['size'] ?? 0 ) > $limit * 1048576 ) {
				$out[] = array( 'code' => 'broadcast_over_default_file', 'message' => 'Tệp "' . self::cut( (string) ( $a['name'] ?? '' ), 60 ) . '" lớn hơn ' . $limit . ' MB mặc định.' );
			}
		}
		$s = self::json( $row['schedule_json'] ?? '' );
		if ( isset( $s['time'] ) && ( $s['time'] >= $d['quiet']['from'] || $s['time'] < $d['quiet']['to'] ) ) {
			$out[] = array( 'code' => 'broadcast_quiet_hours', 'message' => 'Giờ gửi ' . $s['time'] . ' nằm trong giờ yên lặng ' . $d['quiet']['from'] . '–' . $d['quiet']['to'] . ' (chỉ nhắc, không chặn).' );
		}
		return $out;
	}

	/** Wire pack `group-broadcast@1` (doc 21 §3). `thread_id` is the real uid — server ⇄ server only. */
	public static function build_pack( array $row, array $recipients, string $tz ): array {
		$atts = array();
		foreach ( self::json( $row['attachments_json'] ?? '' ) as $a ) {
			$one = array(
				'url'  => (string) ( $a['url'] ?? '' ),
				'mime' => (string) ( $a['mime'] ?? '' ),
				'size' => (int) ( $a['size'] ?? 0 ),
				'name' => (string) ( $a['name'] ?? '' ),
			);
			if ( preg_match( '/^[a-f0-9]{64}$/', (string) ( $a['sha256'] ?? '' ) ) ) {
				$one['sha256'] = (string) $a['sha256'];
			}
			$atts[] = $one;
		}
		$rcp = array();
		foreach ( $recipients as $r ) {
			$thread = (string) ( $r['thread_ref'] ?? '' );
			if ( '' === $thread ) {
				continue;
			}
			$rcp[] = array(
				'thread_id' => $thread,
				'kind'      => 'group' === (string) ( $r['target_kind'] ?? $r['kind'] ?? '' ) ? 'group' : 'user',
				'display'   => self::cut( (string) ( $r['display_name'] ?? $r['display'] ?? '' ), 120 ),
				'conv_ref'  => (string) (int) ( $r['conversation_id'] ?? 0 ),
			);
		}
		$d = self::defaults();
		return array(
			'contract'      => 'group-broadcast@1',
			'broadcast_ref' => (string) ( $row['broadcast_ref'] ?? '' ),
			'rev'           => max( 1, (int) ( $row['rev'] ?? 1 ) ),
			'account_ref'   => (string) ( $row['account_ref'] ?? '' ),
			'title'         => self::cut( (string) ( $row['title'] ?? '' ), 180 ),
			'variants'      => array_values( array_map( 'strval', self::json( $row['variants_json'] ?? '' ) ) ),
			'attachments'   => $atts,
			'recipients'    => $rcp,
			'quiet_hours'   => array( 'from' => $d['quiet']['from'], 'to' => $d['quiet']['to'], 'tz' => $tz ),
		);
	}

	/** Item (doc 21 §6). $recipients null ⇒ list view (counts only, no recipients key). */
	public static function shape_item( array $row, $recipients = null, array $extra = array() ): array {
		$push  = self::json( $row['cell_push_json'] ?? '' );
		$atts  = array();
		foreach ( self::json( $row['attachments_json'] ?? '' ) as $a ) {
			$atts[] = array(
				'attachment_id' => (int) ( $a['attachment_id'] ?? 0 ),
				'url'           => (string) ( $a['url'] ?? '' ),
				'mime'          => (string) ( $a['mime'] ?? '' ),
				'size'          => (int) ( $a['size'] ?? 0 ),
				'name'          => (string) ( $a['name'] ?? '' ),
			);
		}
		$last  = self::json( $row['last_run_json'] ?? '' );
		$sched = self::json( $row['schedule_json'] ?? '' );
		$item  = array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'broadcast_ref'  => (string) ( $row['broadcast_ref'] ?? '' ),
			'batch_label'    => (string) ( $row['batch_label'] ?? '' ),
			'title'          => (string) ( $row['title'] ?? '' ),
			'account_ref'    => (string) ( $row['account_ref'] ?? '' ),
			'account_label'  => (string) ( $extra['account_label'] ?? '' ),
			'account_online' => (bool) ( $extra['account_online'] ?? false ),
			'rev'            => max( 1, (int) ( $row['rev'] ?? 1 ) ),
			'state'          => in_array( (string) ( $row['cell_state'] ?? '' ), self::STATES, true ) ? (string) $row['cell_state'] : 'draft',
			'state_reason'   => (string) ( $row['state_reason'] ?? '' ),
			'variants'       => array_values( array_map( 'strval', self::json( $row['variants_json'] ?? '' ) ) ),
			'attachments'    => $atts,
			'schedule'       => $sched ? $sched : null,
			'count_group'    => (int) ( $extra['count_group'] ?? 0 ),
			'count_user'     => (int) ( $extra['count_user'] ?? 0 ),
			'next_run_at'    => isset( $push['next_run_at'] ) && '' !== (string) $push['next_run_at'] ? (string) $push['next_run_at'] : null,
			'last_run'       => $last && ! empty( $last['run_ref'] ) ? $last : null,
			'created_at'     => (string) ( $row['created_at'] ?? '' ),
			'updated_at'     => (string) ( $row['updated_at'] ?? '' ),
		);
		if ( is_array( $recipients ) ) {
			$item['recipients'] = array();
			$g = 0;
			$u = 0;
			foreach ( $recipients as $r ) {
				$kind = 'group' === (string) ( $r['target_kind'] ?? $r['kind'] ?? '' ) ? 'group' : 'user';
				'group' === $kind ? $g++ : $u++;
				$item['recipients'][] = array( 'conversation_id' => (int) ( $r['conversation_id'] ?? 0 ), 'kind' => $kind, 'display' => (string) ( $r['display_name'] ?? $r['display'] ?? '' ) );
			}
			$item['count_group'] = $g;
			$item['count_user']  = $u;
		}
		if ( isset( $extra['warnings'] ) ) {
			$item['warnings'] = (array) $extra['warnings'];
		}
		return $item;
	}

	/* ── access (D146-11 / D146-17) ───────────────────────────────────── */

	/** zalo-hub numbers of this site: list<{bridge_id, label, owner_user_id}>. */
	public static function accounts(): array {
		if ( isset( self::$readers['accounts'] ) ) {
			return (array) call_user_func( self::$readers['accounts'] );
		}
		return class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? (array) BizCity_Zalo_Hub_Config_Sync::accounts() : array();
	}

	public static function account( string $ref ): array {
		foreach ( self::accounts() as $a ) {
			if ( is_array( $a ) && (string) ( $a['bridge_id'] ?? '' ) === $ref ) {
				return $a;
			}
		}
		return array();
	}

	/** Admin or supervisor (any number they can see) — the number owner only for their own numbers. */
	private static function is_manager( int $user_id ): bool {
		if ( isset( self::$readers['is_manager'] ) ) {
			return (bool) call_user_func( self::$readers['is_manager'], $user_id );
		}
		if ( class_exists( 'BizCity_CRM_Inbox_Access' ) && BizCity_CRM_Inbox_Access::is_admin( $user_id ) ) {
			return true;
		}
		if ( class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
			return BizCity_CRM_Staff_Policy::rank( BizCity_CRM_Staff_Policy::role( $user_id ) ) >= BizCity_CRM_Staff_Policy::rank( BizCity_CRM_Staff_Policy::ROLE_SUPERVISOR );
		}
		return function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' );
	}

	/** @return string[]|null bridge ids the user may announce from; null = every number of the site. */
	public static function allowed_accounts( int $user_id ) {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( self::is_manager( $user_id ) ) {
			return null;
		}
		$out = array();
		foreach ( self::accounts() as $a ) {
			if ( is_array( $a ) && (int) ( $a['owner_user_id'] ?? 0 ) === $user_id ) {
				$out[] = (string) $a['bridge_id'];
			}
		}
		return $out;
	}

	public static function can_announce( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		$allowed = self::allowed_accounts( $user_id );
		return null === $allowed || array() !== $allowed;
	}

	public static function can_use_account( int $user_id, string $ref ): bool {
		$allowed = self::allowed_accounts( $user_id );
		return null === $allowed || in_array( $ref, $allowed, true );
	}

	/* ── DB ────────────────────────────────────────────────────────────── */

	private static function tbl(): string {
		return BizCity_CRM_DB_Installer_V2::tbl_broadcasts();
	}

	private static function tbl_r(): string {
		return BizCity_CRM_DB_Installer_V2::tbl_broadcast_recipients();
	}

	/** The rows behind Inbox ids, scoped to the inboxes the user may read (null = all). */
	public static function conversations_by_ids( array $ids, $allowed_inbox_ids ): array {
		global $wpdb;
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, self::HARD_MAX_RECIPIENTS );
		if ( ! $ids || ( is_array( $allowed_inbox_ids ) && ! $allowed_inbox_ids ) ) {
			return array();
		}
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ct   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$ibx  = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$sql  = "SELECT c.id AS conversation_id, i.channel_type, i.channel_ref_id AS account_ref, ci.source_id, ci.contact_id, ct.name, ct.additional_attributes AS attributes
			FROM {$conv} c LEFT JOIN {$ci} ci ON ci.id = c.contact_inbox_id LEFT JOIN {$ibx} i ON i.id = c.inbox_id LEFT JOIN {$ct} ct ON ct.id = ci.contact_id
			WHERE c.id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		$params = $ids;
		if ( is_array( $allowed_inbox_ids ) ) {
			$sql   .= ' AND c.inbox_id IN (' . implode( ',', array_fill( 0, count( $allowed_inbox_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, array_map( 'intval', $allowed_inbox_ids ) );
		}
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_by_ref( string $ref ) {
		global $wpdb;
		if ( ! preg_match( '/^BC-[a-f0-9]{6,32}$/', $ref ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tbl() . ' WHERE broadcast_ref = %s AND channel_kind = %s', $ref, self::KIND ), ARRAY_A ); // phpcs:ignore
		return is_array( $row ) ? $row : null;
	}

	public static function get_by_id( int $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tbl() . ' WHERE id = %d AND channel_kind = %s', $id, self::KIND ), ARRAY_A ); // phpcs:ignore
		return is_array( $row ) ? $row : null;
	}

	public static function rows_by_batch( string $label ): array {
		global $wpdb;
		if ( ! preg_match( '/^BL-[a-f0-9]{6,32}$/', $label ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tbl() . " WHERE batch_label = %s AND channel_kind = %s AND ( state_reason IS NULL OR state_reason <> 'deleted' ) ORDER BY id", $label, self::KIND ), ARRAY_A ); // phpcs:ignore
	}

	/** @return array{rows:array,total:int} */
	public static function list_rows( $accounts, string $state, string $q, int $page, int $per = 50 ): array {
		global $wpdb;
		$where  = array( 'channel_kind = %s', "( state_reason IS NULL OR state_reason <> 'deleted' )" );
		$params = array( self::KIND );
		if ( is_array( $accounts ) ) {
			if ( ! $accounts ) {
				return array( 'rows' => array(), 'total' => 0 );
			}
			$where[] = 'account_ref IN (' . implode( ',', array_fill( 0, count( $accounts ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_map( 'strval', $accounts ) );
		}
		if ( in_array( $state, self::STATES, true ) ) {
			$where[]  = 'cell_state = %s';
			$params[] = $state;
		}
		if ( '' !== $q ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $q ) . '%';
		}
		$w     = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::tbl() . " WHERE {$w}", $params ) ); // phpcs:ignore
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tbl() . " WHERE {$w} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, max( 0, $page - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore
		return array( 'rows' => $rows, 'total' => $total );
	}

	public static function recipients( int $broadcast_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT conversation_id, contact_id, target_kind, thread_ref, display_name FROM ' . self::tbl_r() . ' WHERE broadcast_id = %d ORDER BY id', $broadcast_id ), ARRAY_A ); // phpcs:ignore
	}

	/** Counts per kind for a page of rows (one query). @return array<int,array{group:int,user:int}> */
	public static function recipient_counts( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT broadcast_id, target_kind, COUNT(*) AS n FROM ' . self::tbl_r() . ' WHERE broadcast_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY broadcast_id, target_kind', $ids ), ARRAY_A ); // phpcs:ignore
		$out = array();
		foreach ( $rows as $r ) {
			$k = 'group' === (string) $r['target_kind'] ? 'group' : 'user';
			$out[ (int) $r['broadcast_id'] ][ $k ] = (int) $r['n'] + ( $out[ (int) $r['broadcast_id'] ][ $k ] ?? 0 );
		}
		return $out;
	}

	public static function insert_draft( string $account_ref, string $batch, array $recipients, int $user_id, string $title = '' ): array {
		global $wpdb;
		$now = self::now_mysql();
		$ref = self::new_ref( 'BC' );
		$wpdb->insert( self::tbl(), array(
			'title'            => '' !== $title ? self::cut( $title, 250 ) : 'Thông báo ' . substr( $now, 0, 10 ),
			'message_template' => '',
			'status'           => 'draft',
			'created_by'       => $user_id,
			'created_at'       => $now,
			'updated_at'       => $now,
			'channel_kind'     => self::KIND,
			'broadcast_ref'    => $ref,
			'batch_label'      => $batch,
			'account_ref'      => $account_ref,
			'rev'              => 1,
			'variants_json'    => '[]',
			'attachments_json' => '[]',
			'cell_state'       => 'draft',
			'total_count'      => count( $recipients ),
		) );
		$id = (int) $wpdb->insert_id;
		self::write_recipients( $id, $recipients );
		return self::get_by_id( $id ) ?: array();
	}

	public static function write_recipients( int $broadcast_id, array $recipients ): void {
		global $wpdb;
		$wpdb->delete( self::tbl_r(), array( 'broadcast_id' => $broadcast_id ) );
		$written = 0;
		foreach ( $recipients as $r ) {
			$ok = $wpdb->insert( self::tbl_r(), array(
				'broadcast_id'    => $broadcast_id,
				'contact_id'      => (int) ( $r['contact_id'] ?? 0 ),
				'conversation_id' => (int) $r['conversation_id'],
				'status'          => 'listed',
				'target_kind'     => 'group' === (string) ( $r['kind'] ?? '' ) ? 'group' : 'user',
				'thread_ref'      => (string) $r['thread_ref'],
				'display_name'    => self::cut( (string) ( $r['display'] ?? '' ), 190 ),
			) );
			$written += false === $ok ? 0 : 1; // // [2026-10-28 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 review — UNIQUE(broadcast_id, contact_id) can refuse a second conversation of one contact: count what is really stored
		}
		$wpdb->update( self::tbl(), array( 'total_count' => $written ), array( 'id' => $broadcast_id ) );
	}

	public static function remove_recipients( int $broadcast_id, array $conversation_ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $conversation_ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::tbl_r() . ' WHERE broadcast_id = %d AND conversation_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $broadcast_id ), $ids ) ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tbl() . ' SET total_count = ( SELECT COUNT(*) FROM ' . self::tbl_r() . ' WHERE broadcast_id = %d ) WHERE id = %d', $broadcast_id, $broadcast_id ) ); // phpcs:ignore
		return $n;
	}

	public static function update_row( int $id, array $cols ): void {
		global $wpdb;
		$cols['updated_at'] = self::now_mysql();
		foreach ( array( 'variants_json', 'attachments_json', 'schedule_json', 'last_run_json', 'cell_push_json' ) as $k ) {
			if ( isset( $cols[ $k ] ) && is_array( $cols[ $k ] ) ) {
				$cols[ $k ] = wp_json_encode( $cols[ $k ] );
			}
		}
		$wpdb->update( self::tbl(), $cols, array( 'id' => $id ) );
	}

	public static function delete_row( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::tbl_r(), array( 'broadcast_id' => $id ) );
		$wpdb->delete( self::tbl(), array( 'id' => $id ) );
	}

	/** Drafts nobody enabled within 7 days (30 §2) — run when a new draft is made, bounded. */
	public static function purge_old_drafts(): void {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', self::now() - self::DRAFT_TTL_DAYS * DAY_IN_SECONDS );
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::tbl() . " WHERE channel_kind = %s AND cell_state = 'draft' AND created_at < %s LIMIT 50", self::KIND, $cut ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			self::delete_row( (int) $id );
		}
	}

	/**
	 * attachment ids ⇒ attachments_json entries (mime / size / url / name / sha256 read on the site, never from the browser).
	 * sha256 is reused from $previous for an unchanged id (no re-hash on every save).
	 *
	 * @return array{items:array}|WP_Error
	 */
	public static function attachments_from_ids( array $ids, array $previous = array() ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( count( $ids ) > self::HARD_MAX_FILES ) {
			return new WP_Error( 'broadcast_too_many_files', 'Tối đa ' . self::HARD_MAX_FILES . ' tệp một thông báo.', array( 'status' => 400, 'hint' => 'Bỏ bớt tệp.' ) );
		}
		$prev = array();
		foreach ( $previous as $p ) {
			$prev[ (int) ( $p['attachment_id'] ?? 0 ) ] = $p;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$a = self::attachment( $id );
			if ( ! $a ) {
				return new WP_Error( 'broadcast_attachment_missing', 'Không tìm thấy tệp #' . $id . ' trong Thư viện.', array( 'status' => 400, 'hint' => 'Tải tệp lên lại.' ) );
			}
			if ( ! in_array( $a['mime'], self::MIMES, true ) ) {
				return new WP_Error( 'broadcast_attachment_type', 'Loại tệp "' . $a['mime'] . '" chưa hỗ trợ.', array( 'status' => 400, 'hint' => 'Dùng ảnh, PDF, Word, Excel hoặc MP4.' ) );
			}
			if ( $a['size'] > self::HARD_MAX_BYTES ) {
				return new WP_Error( 'broadcast_attachment_too_big', 'Tệp "' . self::cut( $a['name'], 60 ) . '" lớn hơn 25 MB.', array( 'status' => 400, 'hint' => 'Nén tệp hoặc gửi đường dẫn trong nội dung.' ) );
			}
			$sha = isset( $prev[ $id ]['sha256'] ) && (int) ( $prev[ $id ]['size'] ?? -1 ) === $a['size'] ? (string) $prev[ $id ]['sha256'] : ( '' !== $a['path'] && is_readable( $a['path'] ) ? (string) hash_file( 'sha256', $a['path'] ) : '' );
			$out[] = array( 'attachment_id' => $id, 'url' => $a['url'], 'mime' => $a['mime'], 'size' => $a['size'], 'name' => $a['name'], 'sha256' => $sha );
		}
		return array( 'items' => $out );
	}

	/** @return array{url:string,mime:string,size:int,name:string,path:string}|null */
	private static function attachment( int $id ) {
		if ( isset( self::$readers['attachment'] ) ) {
			$a = call_user_func( self::$readers['attachment'], $id );
			return is_array( $a ) ? $a : null;
		}
		if ( ! function_exists( 'get_post' ) || ! ( $post = get_post( $id ) ) || 'attachment' !== $post->post_type ) {
			return null;
		}
		// // [2026-10-28 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 review — only a Media Library file of THIS site (multisite: per-site uploads dir) that the actor may read.
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'read_post', $id ) ) {
			return null;
		}
		$path = (string) get_attached_file( $id );
		$up   = function_exists( 'wp_get_upload_dir' ) ? (array) wp_get_upload_dir() : array();
		if ( '' !== $path && ! self::path_in_dir( $path, (string) ( $up['basedir'] ?? '' ) ) ) {
			return null;
		}
		return array(
			'url'  => (string) wp_get_attachment_url( $id ),
			'mime' => (string) get_post_mime_type( $id ),
			'size' => '' !== $path && is_file( $path ) ? (int) filesize( $path ) : 0,
			'name' => '' !== $path ? basename( $path ) : (string) $post->post_title,
			'path' => $path,
		);
	}

	/** // [2026-10-28 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 review — is $path (after symlinks / ..) a file under $base? Pure apart from realpath. */
	public static function path_in_dir( string $path, string $base ): bool {
		$real_base = '' !== $base ? realpath( $base ) : false;
		$real      = realpath( $path );
		if ( false === $real_base || false === $real ) {
			return false;
		}
		$real_base = rtrim( str_replace( '\\', '/', $real_base ), '/' ) . '/';
		return 0 === strpos( str_replace( '\\', '/', $real ), $real_base );
	}

	/* ── helpers ──────────────────────────────────────────────────────── */

	public static function json( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$d = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $d ) ? $d : array();
	}

	public static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	public static function now_mysql(): string {
		return gmdate( 'Y-m-d H:i:s', self::now() );
	}

	public static function tz(): string {
		if ( isset( self::$readers['tz'] ) ) {
			return (string) call_user_func( self::$readers['tz'] );
		}
		$tz = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		// the cell's cron needs an IANA name; a bare "+07:00" offset site falls back to Việt Nam time
		return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'Asia/Ho_Chi_Minh';
	}

	private static function len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
	}

	public static function cut( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}
}
