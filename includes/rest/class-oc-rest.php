<?php
/**
 * [2026-11-23 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-005 OC-UI — REST chỉ đọc cho giao diện Open CRM mới (frontend/src/oc/).
 *
 * GET bizcity-crm/v1/oc/contacts — bảng Danh bạ (ảnh 2 Deplao): mỗi dòng = một hội thoại (khách × số Zalo) với
 * tên, tên thật, ảnh, SĐT, giới tính, sinh nhật, nhãn, lần nhắn cuối; phân trang 200/trang; đếm cho dải tab.
 *
 * Chỉ đọc 4 bảng sổ hội thoại (crm-ledger@1) theo tên `$wpdb->prefix . bizcity_crm_*` ⇒ chạy được cả khi có Zalo Brain
 * (lõi là chủ bảng) lẫn khi chạy một mình (plugin tạo bảng từ spine/). Không ghi gì. Quyền đọc = quyền đọc hộp thư;
 * nhân viên chỉ thấy số Zalo được giao (BizCity_CRM_Inbox_Access khi có).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_OCRM_REST', false ) ) {

	class BizCity_OCRM_REST {

		const NS = 'bizcity-crm/v1';

		public static function register(): void {
			register_rest_route( self::NS, '/oc/contacts', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_contacts' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'inbox_id' => array( 'type' => 'integer' ),
					'kind'     => array( 'type' => 'string', 'enum' => array( 'personal', 'group' ), 'default' => 'personal' ),
					'q'        => array( 'type' => 'string' ),
					'label'    => array( 'type' => 'string' ),
					'sex'      => array( 'type' => 'string', 'enum' => array( '', 'male', 'female' ) ),
					'birthday' => array( 'type' => 'string', 'enum' => array( '', 'has', 'today', 'month' ) ),
					'sort'     => array( 'type' => 'string', 'enum' => array( 'name_asc', 'name_desc', 'recent' ), 'default' => 'recent' ),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 200 ),
				),
			) );
			// [2026-11-23 10:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-009 OC-UI — số Zalo được giao cho từng nhân viên (inbox_members) + đang đăng nhập.
			register_rest_route( self::NS, '/oc/staff-inboxes', array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_staff_inboxes' ),
					'permission_callback' => array( __CLASS__, 'can_manage_staff' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_staff_inboxes' ),
					'permission_callback' => array( __CLASS__, 'can_manage_staff' ),
					'args'                => array(
						'user_id'   => array( 'type' => 'integer', 'required' => true ),
						'inbox_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'default' => array() ),
					),
				),
			) );
			// [2026-11-24 12:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-009 OC-009.3 — "Mục được dùng" theo người (user meta ocrm_user_modules).
			register_rest_route( self::NS, '/oc/staff-modules', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_staff_modules' ),
				'permission_callback' => array( __CLASS__, 'can_manage_staff' ),
				'args'                => array(
					'user_id' => array( 'type' => 'integer', 'required' => true ),
					'modules' => array( 'type' => array( 'array', 'null' ), 'items' => array( 'type' => 'string' ) ),
				),
			) );
		}

		/**
		 * [2026-11-24 12:10 AM Johnny Chu - Chu Hoàng Anh] PHASE-009 OC-009.3 — các mục trên rail giao được cho nhân viên.
		 * Cài đặt không nằm đây: ai cũng mở được, trang Nhân viên tự chặn theo can_manage_staff.
		 * Mặc định cho phép (chưa đặt = mọi mục). Đây là ẩn mục trên giao diện; quyền dữ liệu vẫn do REST từng mục + số Zalo được giao.
		 */
		const MODULES = array( 'dashboard', 'inbox', 'contacts', 'campaigns', 'reports', 'workflow', 'integrations', 'tasks', 'apps' );
		const META    = 'ocrm_user_modules';

		/** @return string[]|null null = mọi mục (chưa đặt, hoặc người quản lý). */
		public static function user_modules( int $uid ) {
			if ( $uid <= 0 || user_can( $uid, 'manage_options' ) || user_can( $uid, 'bizcity_crm_manage_teams' ) ) {
				return null;
			}
			$raw = get_user_meta( $uid, self::META, true );
			if ( ! is_array( $raw ) ) {
				return null;
			}
			return array_values( array_intersect( self::MODULES, array_map( 'strval', $raw ) ) );
		}

		/** POST /oc/staff-modules { user_id, modules: string[]|null } — null ⇒ xoá, trở lại mọi mục. */
		public static function set_staff_modules( WP_REST_Request $req ) {
			$uid = (int) $req->get_param( 'user_id' );
			if ( $uid <= 0 || ! get_userdata( $uid ) ) {
				return self::err( 'OC-STAFF-01', 'Không tìm thấy nhân viên này.', 'Tải lại trang Nhân viên rồi thử lại.', 404 );
			}
			$mods = $req->get_param( 'modules' );
			if ( null === $mods ) {
				delete_user_meta( $uid, self::META );
				return rest_ensure_response( array( 'ok' => true, 'data' => array( 'user_id' => $uid, 'modules' => null ) ) );
			}
			$mods = array_values( array_intersect( self::MODULES, array_map( 'strval', (array) $mods ) ) );
			if ( ! $mods ) {
				return self::err( 'OC-STAFF-03', 'Nhân viên cần ít nhất một mục để làm việc.', 'Chọn ít nhất một mục, ví dụ Hộp thư. Muốn chặn hẳn thì bấm Tạm dừng.', 400 );
			}
			update_user_meta( $uid, self::META, $mods );
			return rest_ensure_response( array( 'ok' => true, 'data' => array( 'user_id' => $uid, 'modules' => $mods ) ) );
		}

		public static function can_manage_staff(): bool {
			return current_user_can( 'manage_options' ) || current_user_can( 'bizcity_crm_manage_teams' );
		}

		/** GET /oc/staff-inboxes ⇒ { members: { user_id: [inbox_id…] }, logged_in: { user_id: bool } } */
		public static function get_staff_inboxes( WP_REST_Request $req ) {
			global $wpdb;
			$tbl = $wpdb->prefix . 'bizcity_crm_inbox_members';
			$members = array();
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				foreach ( (array) $wpdb->get_results( "SELECT inbox_id, user_id FROM `{$tbl}` WHERE is_active = 1", ARRAY_A ) as $r ) {
					$members[ (int) $r['user_id'] ][] = (int) $r['inbox_id'];
				}
			}
			$logged  = array();
			$modules = array();
			$ids     = array_keys( $members );
			foreach ( (array) $req->get_param( 'user_ids' ) as $u ) { $ids[] = (int) $u; }
			foreach ( array_unique( array_filter( $ids ) ) as $uid ) {
				$logged[ $uid ]  = class_exists( 'WP_Session_Tokens' ) && count( WP_Session_Tokens::get_instance( $uid )->get_all() ) > 0;
				$modules[ $uid ] = self::user_modules( (int) $uid );
			}
			return rest_ensure_response( array( 'ok' => true, 'data' => array( 'members' => (object) $members, 'logged_in' => (object) $logged, 'modules' => (object) $modules, 'all_modules' => self::MODULES ) ) );
		}

		/** POST /oc/staff-inboxes { user_id, inbox_ids[] } — đặt đúng danh sách số Zalo của một người (thêm thiếu, gỡ thừa). */
		public static function set_staff_inboxes( WP_REST_Request $req ) {
			global $wpdb;
			$uid  = (int) $req->get_param( 'user_id' );
			$want = array_values( array_unique( array_filter( array_map( 'intval', (array) $req->get_param( 'inbox_ids' ) ) ) ) );
			if ( $uid <= 0 || ! get_userdata( $uid ) ) {
				return self::err( 'OC-STAFF-01', 'Không tìm thấy nhân viên này.', 'Tải lại trang Nhân viên rồi thử lại.', 404 );
			}
			if ( ! class_exists( 'BizCity_CRM_Team_Manager' ) ) {
				return self::err( 'OC-STAFF-02', 'Chưa giao được số Zalo trên site này.', 'Cập nhật Open CRM lên bản mới nhất.', 501 );
			}
			$tbl = $wpdb->prefix . 'bizcity_crm_inbox_members';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$have = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT inbox_id FROM `{$tbl}` WHERE user_id = %d AND is_active = 1", $uid ) ) );
			foreach ( array_diff( $want, $have ) as $iid ) { BizCity_CRM_Team_Manager::add_inbox_member( (int) $iid, $uid, 'agent' ); }
			foreach ( array_diff( $have, $want ) as $iid ) { BizCity_CRM_Team_Manager::remove_inbox_member( (int) $iid, $uid ); }
			return rest_ensure_response( array( 'ok' => true, 'data' => array( 'user_id' => $uid, 'inbox_ids' => $want ) ) );
		}

		public static function can_read( $request = null ): bool {
			if ( class_exists( 'BizCity_CRM_Spine_REST' ) && method_exists( 'BizCity_CRM_Spine_REST', 'can_read_inbox_scope' ) ) {
				return (bool) BizCity_CRM_Spine_REST::can_read_inbox_scope( $request );
			}
			return current_user_can( 'manage_options' ) || current_user_can( 'bizcity_crm_handle_inbox' );
		}

		/** @return int[]|null null = mọi số Zalo. */
		protected static function allowed_inboxes() {
			if ( class_exists( 'BizCity_CRM_Inbox_Access' ) && method_exists( 'BizCity_CRM_Inbox_Access', 'allowed_inbox_ids' ) ) {
				$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids();
				return is_array( $allowed ) ? array_map( 'intval', $allowed ) : null;
			}
			return null;
		}

		protected static function err( string $code, string $message, string $hint, int $status ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $code ), $status );
		}

		public static function get_contacts( WP_REST_Request $req ) {
			global $wpdb;
			$conv = $wpdb->prefix . 'bizcity_crm_conversations';
			$ci   = $wpdb->prefix . 'bizcity_crm_contact_inboxes';
			$ct   = $wpdb->prefix . 'bizcity_crm_contacts';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv ) ) !== $conv ) {
				return rest_ensure_response( array( 'ok' => true, 'data' => array( 'rows' => array(), 'total' => 0, 'counts' => array( 'personal' => 0, 'group' => 0 ) ) ) );
			}

			$where  = array( '1=1' );
			$params = array();

			$allowed = self::allowed_inboxes();
			$inbox   = (int) $req->get_param( 'inbox_id' );
			if ( $inbox > 0 ) {
				if ( is_array( $allowed ) && ! in_array( $inbox, $allowed, true ) ) {
					return self::err( 'OC-AUTH-01', 'Bạn chưa được giao số Zalo này.', 'Nhờ quản lý giao số ở Cài đặt → Nhân viên.', 403 );
				}
				$where[]  = 'cv.inbox_id = %d';
				$params[] = $inbox;
			} elseif ( is_array( $allowed ) ) {
				if ( ! $allowed ) {
					return rest_ensure_response( array( 'ok' => true, 'data' => array( 'rows' => array(), 'total' => 0, 'counts' => array( 'personal' => 0, 'group' => 0 ) ) ) );
				}
				$where[] = 'cv.inbox_id IN (' . implode( ',', array_map( 'intval', $allowed ) ) . ')';
			}
			$scope_where  = $where;
			$scope_params = $params;

			$kind    = 'group' === $req->get_param( 'kind' ) ? 'group' : 'personal';
			$where[] = 'group' === $kind ? "ci.source_id LIKE 'group:%'" : "ci.source_id NOT LIKE 'group:%'";

			$q = trim( (string) $req->get_param( 'q' ) );
			if ( '' !== $q ) {
				$like     = '%' . $wpdb->esc_like( $q ) . '%';
				$where[]  = '( c.name LIKE %s OR c.phone LIKE %s OR ci.source_id LIKE %s OR c.platform_uid LIKE %s )';
				array_push( $params, $like, $like, $like, $like );
			}
			$label = trim( (string) $req->get_param( 'label' ) );
			if ( '' !== $label ) {
				$where[]  = "CONCAT(',', REPLACE(IFNULL(cv.cached_label_list,''), ', ', ','), ',') LIKE %s";
				$params[] = '%,' . $wpdb->esc_like( $label ) . ',%';
			}
			$sex = (string) $req->get_param( 'sex' );
			if ( 'male' === $sex || 'female' === $sex ) {
				$where[]  = 'c.additional_attributes LIKE %s';
				$params[] = '%"gender":"' . $sex . '"%';
			}
			$bd = (string) $req->get_param( 'birthday' );
			if ( 'has' === $bd ) {
				$where[] = "c.birthday_md IS NOT NULL AND c.birthday_md <> ''";
			} elseif ( 'today' === $bd ) {
				$where[]  = 'c.birthday_md = %s';
				$params[] = gmdate( 'm-d', current_time( 'timestamp' ) );
			} elseif ( 'month' === $bd ) {
				$where[]  = 'c.birthday_md LIKE %s';
				$params[] = gmdate( 'm', current_time( 'timestamp' ) ) . '-%';
			}

			$order = array(
				'name_asc'  => 'c.name ASC, cv.id DESC',
				'name_desc' => 'c.name DESC, cv.id DESC',
				'recent'    => 'cv.last_activity_at DESC, cv.id DESC',
			)[ (string) $req->get_param( 'sort' ) ] ?? 'cv.last_activity_at DESC, cv.id DESC';

			$per  = max( 1, min( 500, (int) $req->get_param( 'per_page' ) ) );
			$page = max( 1, (int) $req->get_param( 'page' ) );
			$from = "FROM `{$conv}` cv INNER JOIN `{$ci}` ci ON ci.id = cv.contact_inbox_id INNER JOIN `{$ct}` c ON c.id = ci.contact_id";
			$sqlw = implode( ' AND ', $where );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; values go through prepare().
			$count_sql = "SELECT COUNT(*) {$from} WHERE {$sqlw}";
			$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

			$list_sql = "SELECT cv.id AS conversation_id, cv.inbox_id, cv.cached_label_list, cv.last_activity_at, cv.unread_count,
				c.id AS contact_id, c.name, c.first_name, c.last_name, c.phone, c.birthday, c.avatar_url, c.additional_attributes, ci.source_id
				{$from} WHERE {$sqlw} ORDER BY {$order} LIMIT %d OFFSET %d";
			$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );

			$scope_sql = "SELECT SUM(ci.source_id LIKE 'group:%') AS g, SUM(ci.source_id NOT LIKE 'group:%') AS p {$from} WHERE " . implode( ' AND ', $scope_where );
			$counts    = $wpdb->get_row( $scope_params ? $wpdb->prepare( $scope_sql, $scope_params ) : $scope_sql, ARRAY_A );
			// phpcs:enable

			$out = array();
			foreach ( (array) $rows as $r ) {
				$attrs  = json_decode( (string) ( $r['additional_attributes'] ?? '' ), true );
				$attrs  = is_array( $attrs ) ? $attrs : array();
				$gender = (string) ( $attrs['zalo_profile']['gender'] ?? ( $attrs['gender'] ?? '' ) );
				$real   = trim( (string) ( $r['first_name'] ?? '' ) . ' ' . (string) ( $r['last_name'] ?? '' ) );
				$zname  = (string) ( $attrs['zalo_profile']['display_name'] ?? '' );
				$labels = '' !== (string) $r['cached_label_list'] ? array_values( array_filter( array_map( 'trim', explode( ',', (string) $r['cached_label_list'] ) ) ) ) : array();
				$out[]  = array(
					'conversation_id'  => (int) $r['conversation_id'],
					'contact_id'       => (int) $r['contact_id'],
					'inbox_id'         => (int) $r['inbox_id'],
					'name'             => (string) $r['name'],
					'real_name'        => $real !== '' && $real !== $r['name'] ? $real : ( $zname !== '' && $zname !== $r['name'] ? $zname : '' ),
					'phone'            => (string) ( $r['phone'] ?? '' ),
					'gender'           => in_array( $gender, array( 'male', 'female' ), true ) ? $gender : '',
					'birthday'         => ( $r['birthday'] && '0000-00-00' !== $r['birthday'] ) ? (string) $r['birthday'] : '',
					'avatar_url'       => $r['avatar_url'] ?: null,
					'labels'           => $labels,
					'has_note'         => ! empty( $attrs['note'] ) || ! empty( $attrs['staff_note'] ),
					'last_activity_at' => $r['last_activity_at'],
					'unread_count'     => (int) $r['unread_count'],
					'is_group'         => 0 === strpos( (string) $r['source_id'], 'group:' ),
				);
			}
			return rest_ensure_response( array(
				'ok'   => true,
				'data' => array(
					'rows'     => $out,
					'total'    => $total,
					'page'     => $page,
					'per_page' => $per,
					'counts'   => array( 'personal' => (int) ( $counts['p'] ?? 0 ), 'group' => (int) ( $counts['g'] ?? 0 ) ),
				),
			) );
		}
	}

	add_action( 'rest_api_init', array( 'BizCity_OCRM_REST', 'register' ) );
}
