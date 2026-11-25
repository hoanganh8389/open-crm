<?php
/**
 * BizCity_CRM_Announce_REST — `bizcity-crm/v1/announce/*` (PHASE-0.146, doc 21 §6): the Inbox selection becomes one draft per Zalo number,
 * the Dialog fills content + schedule, "Bật lịch" hands the row to the cell, the "Lịch thông báo" tab reads the cell's truth through the Hub.
 *
 * Who (D146-11 / D146-17): admin + supervisor (Staff_Policy) on every number, the number owner on their own numbers — the same rule
 * decides `canAnnounce` in the SPA boot. Errors: BizCity_Error_Payload (R-ERROR-UX) with a real 4xx status. Over-default values come
 * back as `warnings`, never a refusal (D146-00).
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, Giấy chứng nhận đăng ký quyền tác giả số 8877/2026/QTG.
 *
 * [2026-10-28 07:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L3 / S146-L6 — new file.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Announce_REST {

	const REF = 'BC-[a-f0-9]{6,32}';
	const RUN = 'BR-[a-f0-9]{6,32}';

	public static function register_routes(): void {
		$ns   = defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
		$perm = array( __CLASS__, 'can_announce' );
		$ref  = '/announce/(?P<ref>' . self::REF . ')';
		register_rest_route( $ns, '/announce/boot', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'boot' ), 'permission_callback' => 'is_user_logged_in' ) );
		register_rest_route( $ns, '/announce/drafts', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'drafts' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/announce/batch/(?P<label>BL-[a-f0-9]{6,32})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'batch' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/announce', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/announce/pause-all', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'pause_all' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, $ref, array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'show' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update' ), 'permission_callback' => $perm ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'destroy' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( $ns, $ref . '/(?P<action>enable|pause|resume|run-now|duplicate)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'action' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, $ref . '/runs', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'runs' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, $ref . '/runs/(?P<run>' . self::RUN . ')', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'run' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, $ref . '/runs/(?P<run>' . self::RUN . ')/control', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'control' ), 'permission_callback' => $perm ) );
		// [2026-11-25 Johnny Chu - Chu Hoàng Anh] OC-007.4 — "Sẵn sàng gửi" of one number + the owner's mass-send switch (owner 2026-11-25: "Chủ số tự bật trong Open CRM").
		register_rest_route( $ns, '/announce/readiness', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'readiness' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/announce/readiness/switch', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'set_switch' ), 'permission_callback' => $perm ) );
	}

	/* ── readiness (OC-007.4) ─────────────────────────────────────────── */

	const SWITCH_KEY = 'GROUP_BROADCAST';

	/**
	 * What still blocks a campaign on ONE number, each item from its real source:
	 *   online       — the number is on this site's zalo-hub list (Store::account);
	 *   owner        — push() would find the owner agent (else owner_agent_missing), + the owner-uid-claim@1 state to fix it from here;
	 *   switch       — the cell's GROUP_BROADCAST of the number (agent-config-view switches; absent on an older cell ⇒ supported:false);
	 *   pace         — the cell's real BROADCAST_MAX_PER_DAY / GAP for this number (null when the cell did not answer).
	 */
	public static function readiness( WP_REST_Request $req ): WP_REST_Response {
		$uid = (int) get_current_user_id();
		$acc = preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'account_ref' ) );
		if ( '' === $acc || ! BizCity_CRM_Announce_Store::can_use_account( $uid, $acc ) ) {
			return self::err( 'permission_denied', 'Bạn không phụ trách số Zalo này.', 'Chọn một số bạn là chủ, hoặc nhờ quản trị.', 403 );
		}
		$a     = BizCity_CRM_Announce_Store::account( $acc );
		$claim = class_exists( 'BizCity_Zalo_Owner_Uid_Claim' ) ? BizCity_Zalo_Owner_Uid_Claim::state( $acc, $uid ) : null;
		$view  = self::agent_config( 'GET', $acc );
		$sw    = self::switch_of( $view );
		$pace  = is_array( $view['broadcast'] ?? null ) ? $view['broadcast'] : null;
		$out   = array(
			'account_ref'   => $acc,
			'account_label' => self::account_extra( $acc )['account_label'],
			'online'        => array() !== $a,
			'owner'         => array(
				'ready'      => BizCity_CRM_Announce_Cell::owner_ready( $acc, $uid ),
				'can_claim'  => is_array( $claim ) && ! empty( $claim['can_claim'] ),
				'reason'     => is_array( $claim ) ? (string) ( $claim['reason'] ?? '' ) : 'claim_unavailable',
				'uid_masked' => is_array( $claim ) ? (string) ( $claim['uid_masked'] ?? '' ) : '',
				'pending'    => is_array( $claim ) && is_array( $claim['pending'] ?? null ) ? $claim['pending'] : null,
				'proxy'      => is_array( $claim ) && ! empty( $claim['proxy'] ),
			),
			'switch'        => null === $sw ? array( 'supported' => false, 'on' => null, 'origin' => '' ) : array( 'supported' => true, 'on' => (bool) ( $sw['effective']['value'] ?? false ), 'origin' => (string) ( $sw['effective']['origin'] ?? '' ) ),
			'pace'          => null === $pace ? null : array(
				'max_per_day' => (int) ( $pace['max_per_day'] ?? 0 ),
				'gap_min_s'   => (int) round( (int) ( $pace['gap_min_ms'] ?? 0 ) / 1000 ),
				'gap_max_s'   => (int) round( (int) ( $pace['gap_max_ms'] ?? 0 ) / 1000 ),
			),
		);
		if ( empty( $view['ok'] ) ) {
			$out['_degraded'] = true;
			$out['cell_code'] = (string) ( $view['code'] ?? 'cell_unavailable' );
		}
		return new WP_REST_Response( $out, 200 );
	}

	/** Owner / admin / supervisor turn mass-send on or off for ONE number (the cell writes the number layer; D146-00: the owner bears the risk). */
	public static function set_switch( WP_REST_Request $req ): WP_REST_Response {
		$uid = (int) get_current_user_id();
		$p   = $req->get_json_params();
		$p   = is_array( $p ) ? $p : $req->get_params();
		$acc = preg_replace( '/[^0-9]/', '', (string) ( $p['account_ref'] ?? '' ) );
		if ( '' === $acc || ! BizCity_CRM_Announce_Store::can_use_account( $uid, $acc ) ) {
			return self::err( 'permission_denied', 'Bạn không phụ trách số Zalo này.', 'Chỉ chủ số hoặc quản trị bật / tắt được.', 403 );
		}
		if ( ! array_key_exists( 'on', $p ) ) {
			return self::err( 'invalid_param', 'Thiếu lựa chọn bật / tắt.', 'Gửi on: true hoặc false.', 400 );
		}
		$on   = filter_var( $p['on'], FILTER_VALIDATE_BOOLEAN );
		$view = self::agent_config( 'PATCH', $acc, array( 'key' => self::SWITCH_KEY, 'value' => $on ? 1 : 0 ) );
		$sw   = self::switch_of( $view );
		if ( empty( $view['ok'] ) || null === $sw ) {
			$code = (string) ( $view['code'] ?? '' );
			if ( 'unknown_key' === $code || ( ! empty( $view['ok'] ) && null === $sw ) ) {
				return self::err( 'broadcast_switch_unsupported', 'Trợ lý (Cell) của số này chưa hỗ trợ bật gửi hàng loạt từ Open CRM.', 'Nhờ BizCity cập nhật Cell, hoặc bật "GROUP_BROADCAST" ở trang Cấu hình của Cell.', 409 );
			}
			if ( in_array( $code, array( 'permission_denied', 'account_not_found', 'not_found' ), true ) ) {
				return self::err( 'broadcast_number_not_on_hub', 'Số này chưa gắn với website trên BizCity Hub.', 'Thêm lại số bằng khoá API + quét QR ở Tổng quan, rồi thử lại.', 409, array( 'cell_code' => $code ) );
			}
			return self::err( 'broadcast_cell_unreachable', 'Chưa đổi được: trợ lý (Cell) không trả lời.', 'Thử lại sau ít phút; trạng thái cũ giữ nguyên.', 502, array( 'cell_code' => $code ) );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_crm_announce_switch', $acc, $on, $uid );
		}
		return new WP_REST_Response( array( 'account_ref' => $acc, 'on' => (bool) ( $sw['effective']['value'] ?? false ), 'origin' => (string) ( $sw['effective']['origin'] ?? '' ) ), 200 );
	}

	/** agent-config-view@1 of one number through the Hub (GET, or PATCH one key). Always an array; failure ⇒ ok:false. */
	private static function agent_config( string $method, string $acc, array $body = array() ): array {
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! method_exists( 'BizCity_Zalo_Personal_Hub_Client', 'agent_config_call' ) ) {
			return array( 'ok' => false, 'code' => 'hub_client_missing' );
		}
		$r = BizCity_Zalo_Personal_Hub_Client::instance()->agent_config_call( $method, $acc, $body );
		return is_array( $r ) ? $r : array( 'ok' => false, 'code' => 'cell_unavailable' );
	}

	private static function switch_of( array $view ) {
		foreach ( (array) ( $view['switches'] ?? array() ) as $s ) {
			if ( is_array( $s ) && self::SWITCH_KEY === (string) ( $s['key'] ?? '' ) ) {
				return $s;
			}
		}
		return null;
	}

	public static function can_announce(): bool {
		return function_exists( 'is_user_logged_in' ) && is_user_logged_in() && BizCity_CRM_Announce_Store::can_announce( (int) get_current_user_id() );
	}

	/* ── routes ───────────────────────────────────────────────────────── */

	public static function boot(): WP_REST_Response {
		return new WP_REST_Response( array( 'can_announce' => BizCity_CRM_Announce_Store::can_announce( (int) get_current_user_id() ), 'defaults' => BizCity_CRM_Announce_Store::defaults() ), 200 );
	}

	public static function drafts( WP_REST_Request $req ): WP_REST_Response {
		$uid = (int) get_current_user_id();
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $req->get_param( 'conversation_ids' ) ) ) ) ), 0, BizCity_CRM_Announce_Store::HARD_MAX_RECIPIENTS );
		if ( ! $ids ) {
			return self::err( 'invalid_param', 'Chưa chọn hội thoại nào.', 'Tick các hội thoại ở Inbox rồi bấm Gửi thông báo.', 400 );
		}
		$inboxes = class_exists( 'BizCity_CRM_Inbox_Access' ) ? BizCity_CRM_Inbox_Access::allowed_inbox_ids( $uid ) : null;
		$split   = BizCity_CRM_Announce_Store::split_by_account( BizCity_CRM_Announce_Store::conversations_by_ids( $ids, $inboxes ), $ids, BizCity_CRM_Announce_Store::allowed_accounts( $uid ) );
		$edit    = (string) $req->get_param( 'edit_ref' );
		if ( '' !== $edit ) {
			$row = self::row_or_error( $edit );
			if ( $row instanceof WP_REST_Response ) {
				return $row;
			}
			$account     = (string) $row['account_ref'];
			$unsupported = $split['unsupported'];
			foreach ( $split['groups'] as $acc => $list ) {
				if ( (string) $acc !== $account ) {
					foreach ( $list as $r ) {
						$unsupported[] = array( 'conversation_id' => $r['conversation_id'], 'display' => $r['display'], 'reason' => 'other_account' );
					}
				}
			}
			$keep = $split['groups'][ $account ] ?? array();
			if ( ! $keep ) {
				return self::err( 'invalid_param', 'Không còn hội thoại nào của số này trong lựa chọn.', 'Chọn ít nhất một hội thoại của đúng số Zalo của lịch.', 400, array( 'unsupported' => $unsupported ) );
			}
			BizCity_CRM_Announce_Store::write_recipients( (int) $row['id'], $keep );
			$row = self::after_change( $row, true );
			return new WP_REST_Response( array( 'batch_label' => (string) $row['batch_label'], 'drafts' => array( self::item( $row ) ), 'unsupported' => $unsupported ), 200 );
		}
		BizCity_CRM_Announce_Store::purge_old_drafts();
		$batch  = BizCity_CRM_Announce_Store::new_ref( 'BL' );
		$drafts = array();
		foreach ( $split['groups'] as $acc => $list ) {
			$row = BizCity_CRM_Announce_Store::insert_draft( (string) $acc, $batch, $list, $uid );
			if ( $row ) {
				$drafts[] = self::item( $row );
			}
		}
		return new WP_REST_Response( array( 'batch_label' => $drafts ? $batch : '', 'drafts' => $drafts, 'unsupported' => $split['unsupported'] ), 200 );
	}

	public static function batch( WP_REST_Request $req ): WP_REST_Response {
		$uid    = (int) get_current_user_id();
		$drafts = array();
		foreach ( BizCity_CRM_Announce_Store::rows_by_batch( (string) $req['label'] ) as $row ) {
			if ( BizCity_CRM_Announce_Store::can_use_account( $uid, (string) $row['account_ref'] ) ) {
				$drafts[] = self::item( $row );
			}
		}
		if ( ! $drafts ) {
			return self::err( 'not_found', 'Không tìm thấy nháp thông báo này (nháp giữ 7 ngày).', 'Quay lại Inbox, chọn hội thoại và bấm Gửi thông báo.', 404 );
		}
		return new WP_REST_Response( array( 'batch_label' => (string) $req['label'], 'drafts' => $drafts, 'unsupported' => array() ), 200 );
	}

	public static function index( WP_REST_Request $req ): WP_REST_Response {
		$uid  = (int) get_current_user_id();
		$acc  = preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'account_ref' ) );
		$scope = BizCity_CRM_Announce_Store::allowed_accounts( $uid );
		if ( '' !== $acc ) {
			$scope = null === $scope || in_array( $acc, $scope, true ) ? array( $acc ) : array();
		}
		$page = max( 1, (int) $req->get_param( 'page' ) );
		// [2026-10-28 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 hoàn thiện — per_page 10–200 (UI xin 200; trước đây cắt im lặng ở 50 dù total lớn hơn).
		$per  = min( 200, max( 10, (int) ( $req->get_param( 'per_page' ) ?: 50 ) ) );
		$list = BizCity_CRM_Announce_Store::list_rows( $scope, sanitize_key( (string) $req->get_param( 'state' ) ), sanitize_text_field( (string) $req->get_param( 'q' ) ), $page, $per );
		$ref  = BizCity_CRM_Announce_Cell::refresh( $list['rows'] );
		$cnt  = BizCity_CRM_Announce_Store::recipient_counts( array_column( $ref['rows'], 'id' ) );
		$items = array();
		foreach ( $ref['rows'] as $row ) {
			$c = $cnt[ (int) $row['id'] ] ?? array();
			$items[] = BizCity_CRM_Announce_Store::shape_item( $row, null, self::account_extra( (string) $row['account_ref'] ) + array( 'count_group' => (int) ( $c['group'] ?? 0 ), 'count_user' => (int) ( $c['user'] ?? 0 ) ) );
		}
		$out = array( 'items' => $items, 'total' => $list['total'] );
		if ( $ref['degraded'] ) {
			$out['_degraded'] = true;
		}
		return new WP_REST_Response( $out, 200 );
	}

	public static function show( WP_REST_Request $req ): WP_REST_Response {
		$row = self::row_or_error( (string) $req['ref'] );
		if ( $row instanceof WP_REST_Response ) {
			return $row;
		}
		$row = BizCity_CRM_Announce_Cell::refresh( array( $row ) )['rows'][0];
		return new WP_REST_Response( self::item( $row ), 200 );
	}

	/** Content / attachments / schedule / removed recipients. On a row the cell already holds ⇒ rev+1 and pushed again (Q146-4). */
	public static function update( WP_REST_Request $req ): WP_REST_Response {
		$row = self::row_or_error( (string) $req['ref'] );
		if ( $row instanceof WP_REST_Response ) {
			return $row;
		}
		$cols    = array();
		$changed = false;
		$p       = $req->get_json_params();
		$p       = is_array( $p ) ? $p : $req->get_params();
		if ( isset( $p['title'] ) ) {
			$cols['title'] = BizCity_CRM_Announce_Store::cut( trim( sanitize_text_field( (string) $p['title'] ) ), 250 );
			$changed       = $changed || $cols['title'] !== (string) $row['title'];
		}
		if ( isset( $p['variants'] ) ) {
			$v = BizCity_CRM_Announce_Store::validate_variants( $p['variants'] );
			if ( is_wp_error( $v ) ) {
				return self::wp_err( $v );
			}
			$changed               = $changed || BizCity_CRM_Announce_Store::json( $row['variants_json'] ) !== $v;
			$cols['variants_json'] = $v;
		}
		if ( isset( $p['attachment_ids'] ) ) {
			$prev = BizCity_CRM_Announce_Store::json( $row['attachments_json'] );
			$a    = BizCity_CRM_Announce_Store::attachments_from_ids( (array) $p['attachment_ids'], $prev );
			if ( is_wp_error( $a ) ) {
				return self::wp_err( $a );
			}
			$changed                  = $changed || $prev !== $a['items'];
			$cols['attachments_json'] = $a['items'];
		}
		if ( isset( $p['schedule'] ) ) {
			$s = BizCity_CRM_Announce_Store::validate_schedule( $p['schedule'], BizCity_CRM_Announce_Store::tz() );
			if ( is_wp_error( $s ) ) {
				return self::wp_err( $s );
			}
			$changed               = $changed || BizCity_CRM_Announce_Store::json( $row['schedule_json'] ) !== $s;
			$cols['schedule_json'] = $s;
		}
		if ( ! empty( $p['remove_conversation_ids'] ) && BizCity_CRM_Announce_Store::remove_recipients( (int) $row['id'], (array) $p['remove_conversation_ids'] ) > 0 ) {
			$changed = true;
		}
		if ( $cols ) {
			BizCity_CRM_Announce_Store::update_row( (int) $row['id'], $cols );
		}
		$row = self::after_change( BizCity_CRM_Announce_Store::get_by_id( (int) $row['id'] ), $changed );
		return new WP_REST_Response( self::item( $row ), 200 );
	}

	public static function action( WP_REST_Request $req ): WP_REST_Response {
		$row = self::row_or_error( (string) $req['ref'] );
		if ( $row instanceof WP_REST_Response ) {
			return $row;
		}
		$id    = (int) $row['id'];
		$state = (string) $row['cell_state'];
		switch ( (string) $req['action'] ) {
			case 'enable':
				$missing = self::missing_for_enable( $row );
				if ( '' !== $missing ) {
					return self::err( 'invalid_param', $missing, 'Hoàn tất bước còn thiếu trong hộp thoại rồi bấm Bật lịch.', 400 );
				}
				if ( in_array( $state, array( 'active', 'paused' ), true ) ) {
					return new WP_REST_Response( self::item( $row ) + array( 'push' => array( 'status' => 'already' ) ), 200 );
				}
				$cols = array( 'cell_state' => 'scheduling', 'status' => 'scheduled' );
				if ( in_array( $state, array( 'ended', 'error' ), true ) ) {
					$cols['rev'] = BizCity_CRM_Announce_Store::next_rev( (int) $row['rev'], $state, true );
				}
				BizCity_CRM_Announce_Store::update_row( $id, $cols );
				$push = BizCity_CRM_Announce_Cell::push( $id, 'upsert' );
				return self::item_response( $id, $push );
			case 'pause':
			case 'resume':
				$want = 'pause' === $req['action'] ? 'active' : 'paused';
				if ( $state !== $want ) {
					return self::err( 'invalid_param', 'pause' === $req['action'] ? 'Lịch này không đang chạy.' : 'Lịch này không đang tạm dừng.', 'Tải lại trang để xem trạng thái mới.', 409 );
				}
				return self::item_response( $id, BizCity_CRM_Announce_Cell::push( $id, (string) $req['action'] ) );
			case 'run-now':
				if ( in_array( $state, array( 'draft', 'scheduling' ), true ) ) {
					return self::err( 'invalid_param', 'Lịch chưa được bật nên chưa chạy ngay được.', 'Bấm Bật lịch trước.', 409 );
				}
				return self::item_response( $id, BizCity_CRM_Announce_Cell::push( $id, 'run_now' ) );
			default: // duplicate
				$copy = BizCity_CRM_Announce_Store::insert_draft( (string) $row['account_ref'], BizCity_CRM_Announce_Store::new_ref( 'BL' ), self::recipients_as_input( $id ), (int) get_current_user_id(), BizCity_CRM_Announce_Store::cut( (string) $row['title'], 230 ) . ' (bản sao)' );
				if ( ! $copy ) {
					return self::err( 'crm_exception', 'Không nhân bản được lịch.', 'Thử lại sau.', 500 );
				}
				BizCity_CRM_Announce_Store::update_row( (int) $copy['id'], array( 'variants_json' => (string) $row['variants_json'], 'attachments_json' => (string) $row['attachments_json'], 'schedule_json' => (string) $row['schedule_json'] ) );
				return new WP_REST_Response( self::item( BizCity_CRM_Announce_Store::get_by_id( (int) $copy['id'] ) ), 200 );
		}
	}

	public static function destroy( WP_REST_Request $req ): WP_REST_Response {
		$row = self::row_or_error( (string) $req['ref'] );
		if ( $row instanceof WP_REST_Response ) {
			return $row;
		}
		if ( 'draft' === (string) $row['cell_state'] ) {
			BizCity_CRM_Announce_Store::delete_row( (int) $row['id'] );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		// // [2026-10-28 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 review — mark deleted first: a retry of this cancel (hub down) and any older queued retry both see 'deleted'.
		BizCity_CRM_Announce_Store::update_row( (int) $row['id'], array( 'cell_state' => 'ended', 'state_reason' => 'deleted', 'status' => 'cancelled' ) );
		$push = BizCity_CRM_Announce_Cell::push( (int) $row['id'], 'cancel' );
		return new WP_REST_Response( array( 'ok' => true, 'push' => $push ), 200 );
	}

	public static function pause_all(): WP_REST_Response {
		$uid = (int) get_current_user_id();
		if ( null !== BizCity_CRM_Announce_Store::allowed_accounts( $uid ) ) {
			return self::err( 'permission_denied', 'Chỉ quản trị / trưởng nhóm được tạm dừng mọi lịch.', 'Tạm dừng từng lịch của số bạn ở danh sách.', 403 );
		}
		// // [2026-10-28 08:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 review — read every active id first: pausing moves a row out of the 'active' list, so paging while pushing skipped every other page.
		$ids = array();
		for ( $page = 1; $page <= 20; $page++ ) {
			$list = BizCity_CRM_Announce_Store::list_rows( null, 'active', '', $page, 50 );
			if ( ! $list['rows'] ) {
				break;
			}
			foreach ( $list['rows'] as $row ) {
				$ids[] = (int) $row['id'];
			}
		}
		foreach ( array_unique( $ids ) as $id ) {
			BizCity_CRM_Announce_Cell::push( $id, 'pause' );
		}
		$n = count( array_unique( $ids ) );
		return new WP_REST_Response( array( 'ok' => true, 'count' => $n ), 200 );
	}

	public static function runs( WP_REST_Request $req ): WP_REST_Response {
		$row = self::row_or_error( (string) $req['ref'] );
		if ( $row instanceof WP_REST_Response ) {
			return $row;
		}
		$r = BizCity_CRM_Announce_Cell::relay( 'GET', 'broadcast/runs', (string) $row['account_ref'], array( 'broadcast_ref' => $row['broadcast_ref'], 'limit' => 20, '_t' => self::tick() ) );
		if ( empty( $r['ok'] ) ) {
			return new WP_REST_Response( array( 'items' => array(), '_degraded' => true, 'code' => (string) ( $r['code'] ?? '' ) ), 200 );
		}
		return new WP_REST_Response( array( 'items' => array_values( (array) ( $r['items'] ?? array() ) ) ), 200 );
	}

	public static function run( WP_REST_Request $req ): WP_REST_Response {
		$row = self::row_or_error( (string) $req['ref'] );
		if ( $row instanceof WP_REST_Response ) {
			return $row;
		}
		$r = BizCity_CRM_Announce_Cell::relay( 'GET', 'broadcast/runs/' . $req['run'], (string) $row['account_ref'], array( 'cursor' => max( 0, (int) $req->get_param( 'cursor' ) ), '_t' => self::tick() ) );
		if ( empty( $r['ok'] ) ) {
			return new WP_REST_Response( array( 'run' => null, 'items' => array(), 'next_cursor' => null, '_degraded' => true, 'code' => (string) ( $r['code'] ?? '' ) ), 200 );
		}
		$run = is_array( $r['run'] ?? null ) ? $r['run'] : array();
		if ( (string) ( $run['broadcast_ref'] ?? '' ) !== (string) $row['broadcast_ref'] ) {
			return self::err( 'not_found', 'Lần chạy này không thuộc lịch đang xem.', 'Mở lại từ danh sách lần chạy.', 404 );
		}
		$items = array();
		foreach ( (array) ( $r['items'] ?? array() ) as $it ) {
			unset( $it['thread_id'] ); // the cell already masks it (doc 21 §5); belt and braces
			$items[] = $it;
		}
		return new WP_REST_Response( array( 'run' => $run, 'items' => $items, 'next_cursor' => $r['next_cursor'] ?? null ), 200 );
	}

	public static function control( WP_REST_Request $req ): WP_REST_Response {
		$row = self::row_or_error( (string) $req['ref'] );
		if ( $row instanceof WP_REST_Response ) {
			return $row;
		}
		$op = (string) $req->get_param( 'op' );
		if ( ! in_array( $op, array( 'pause', 'resume', 'cancel', 'retry_failed' ), true ) ) {
			return self::err( 'invalid_param', 'Thao tác không có.', 'Dùng Tạm dừng, Tiếp, Huỷ phần còn lại hoặc Gửi lại lỗi.', 400 );
		}
		$r = BizCity_CRM_Announce_Cell::relay( 'POST', 'broadcast/runs/' . $req['run'] . '/control', (string) $row['account_ref'], array(), array( 'op' => $op ) );
		if ( empty( $r['ok'] ) ) {
			return new WP_REST_Response( $r + array( 'run' => null ), 200 );
		}
		if ( 'retry_failed' === $op && preg_match( '/^' . self::RUN . '$/', (string) ( $r['run_ref'] ?? '' ) ) ) {
			BizCity_CRM_Announce_Store::update_row( (int) $row['id'], array( 'last_run_json' => array( 'run_ref' => (string) $r['run_ref'], 'state' => 'queued', 'counts' => null, 'started_at' => null, 'ended_at' => null ) ) );
		}
		return new WP_REST_Response( $r, 200 );
	}

	/* ── helpers ──────────────────────────────────────────────────────── */

	/** After content / recipients changed: a row the cell holds gets rev+1 and is pushed again (a paused one stays paused). */
	private static function after_change( array $row, bool $changed ): array {
		$state = (string) $row['cell_state'];
		if ( ! $changed || ! in_array( $state, array( 'active', 'paused', 'scheduling', 'error' ), true ) ) {
			return $row;
		}
		$id = (int) $row['id'];
		BizCity_CRM_Announce_Store::update_row( $id, array( 'rev' => BizCity_CRM_Announce_Store::next_rev( (int) $row['rev'], $state, true ) ) );
		if ( ! BizCity_CRM_Announce_Store::recipients( $id ) ) {
			BizCity_CRM_Announce_Cell::push( $id, 'cancel' );
		} else {
			BizCity_CRM_Announce_Cell::push( $id, 'upsert' );
			if ( 'paused' === $state ) {
				BizCity_CRM_Announce_Cell::push( $id, 'pause' );
			}
		}
		return BizCity_CRM_Announce_Store::get_by_id( $id ) ?: $row;
	}

	private static function missing_for_enable( array $row ): string {
		if ( ! BizCity_CRM_Announce_Store::json( $row['variants_json'] ) ) {
			return 'Chưa có nội dung thông báo.';
		}
		if ( ! BizCity_CRM_Announce_Store::json( $row['schedule_json'] ) ) {
			return 'Chưa chọn lịch gửi.';
		}
		if ( ! BizCity_CRM_Announce_Store::recipients( (int) $row['id'] ) ) {
			return 'Lịch chưa có người nhận nào.';
		}
		return '';
	}

	private static function recipients_as_input( int $id ): array {
		$out = array();
		foreach ( BizCity_CRM_Announce_Store::recipients( $id ) as $r ) {
			$out[] = array( 'conversation_id' => (int) $r['conversation_id'], 'contact_id' => (int) $r['contact_id'], 'kind' => (string) $r['target_kind'], 'thread_ref' => (string) $r['thread_ref'], 'display' => (string) $r['display_name'] );
		}
		return $out;
	}

	/** @return array|WP_REST_Response the row, or a 404 / 403 response */
	private static function row_or_error( string $ref ) {
		$row = BizCity_CRM_Announce_Store::get_by_ref( $ref );
		if ( ! $row || 'deleted' === (string) $row['state_reason'] ) {
			return self::err( 'not_found', 'Không tìm thấy lịch thông báo này.', 'Mở lại từ tab Lịch thông báo.', 404 );
		}
		if ( ! BizCity_CRM_Announce_Store::can_use_account( (int) get_current_user_id(), (string) $row['account_ref'] ) ) {
			return self::err( 'permission_denied', 'Bạn không quản lý số Zalo của lịch này.', 'Nhờ quản trị hoặc chủ số thao tác.', 403 );
		}
		return $row;
	}

	private static function item( array $row ): array {
		$recipients = BizCity_CRM_Announce_Store::recipients( (int) $row['id'] );
		$atts       = BizCity_CRM_Announce_Store::json( $row['attachments_json'] );
		$extra      = self::account_extra( (string) $row['account_ref'] ) + array( 'warnings' => BizCity_CRM_Announce_Store::warnings( $row, count( $recipients ), $atts ) );
		return BizCity_CRM_Announce_Store::shape_item( $row, $recipients, $extra );
	}

	private static function item_response( int $id, array $push ): WP_REST_Response {
		$row = BizCity_CRM_Announce_Store::get_by_id( $id );
		return new WP_REST_Response( ( $row ? self::item( $row ) : array() ) + array( 'push' => $push ), 200 );
	}

	private static function account_extra( string $ref ): array {
		$a     = BizCity_CRM_Announce_Store::account( $ref );
		$label = (string) ( $a['label'] ?? '' );
		$label = (string) preg_replace_callback( '/(?<!\d)(?:\+?84|0)\d{8,10}(?!\d)/', static function ( $m ) {
			return substr( $m[0], 0, 2 ) . '***' . substr( $m[0], -2 );
		}, $label );
		return array( 'account_label' => '' !== $label ? $label : 'Số …' . substr( $ref, -4 ), 'account_online' => (bool) $a );
	}

	/** Busts the Hub's 15 s read cache every 5 s (run progress polls every 5 s, 30 §5). */
	private static function tick(): int {
		return (int) floor( BizCity_CRM_Announce_Store::now() / 5 );
	}

	private static function err( string $code, string $message, string $hint, int $status, array $context = array() ): WP_REST_Response {
		$payload = class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $code, $message, $hint, $code, $context )
			: array( 'success' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $code, 'context' => $context );
		$payload['ok'] = false;
		return new WP_REST_Response( $payload, $status );
	}

	private static function wp_err( WP_Error $e ): WP_REST_Response {
		$data = $e->get_error_data();
		$data = is_array( $data ) ? $data : array();
		return self::err( (string) $e->get_error_code(), $e->get_error_message(), (string) ( $data['hint'] ?? '' ), (int) ( $data['status'] ?? 400 ) );
	}
}
