<?php
/**
 * [2026-11-24 Johnny Chu - Chu Hoàng Anh] PHASE-011 OC-011.1 / OC-011.2 — danh sách kết nối mở rộng + kho khoá mã hoá + "Kiểm tra".
 *
 * Danh sách = `connectors/<key>/connector.json` (schema ocrm-connector@1) — thêm nền tảng là thêm một file, không sửa mã.
 * Khoá = option theo blog `ocrm_connectors` (autoload = no):
 *   { <key>: { config: { <field>: "v1:…" (secret, mã hoá) | "giá trị" (text) }, status: saved|ok|error,
 *              last_ok_at, last_check_at, last_error, updated_at, updated_by } }
 *
 * Trạng thái nói thật: chưa lưu ⇒ "Chưa kết nối"; lưu mà connector không có đường kiểm ⇒ "saved" (giao diện: "Đã lưu · chưa kiểm được");
 * chỉ khi gọi kiểm thành công mới là "ok". Giá trị ô secret không bao giờ ra khỏi máy chủ — REST chỉ trả 4 ký tự cuối.
 *
 * REST (bizcity-crm/v1, quyền manage_options):
 *   GET    /oc/connectors                 ⇒ { items: [ connector + state ], webhook: { ready: false } }
 *   POST   /oc/connectors/{key}           { config: {…} } — ô secret để trống = giữ giá trị cũ; lưu xong tự kiểm nếu có đường kiểm
 *   POST   /oc/connectors/{key}/check     ⇒ kiểm lại bằng khoá đã lưu
 *   DELETE /oc/connectors/{key}           ⇒ gỡ khoá
 *   POST   /webhook/{key}                 (công khai, xác thực bằng khoá bảo mật) ⇒ BizCity_OCRM_Events::submit — OC-011.3, Casso + SePay.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_OCRM_Connectors', false ) ) {

	class BizCity_OCRM_Connectors {

		const NS     = 'bizcity-crm/v1';
		const OPTION = 'ocrm_connectors';
		const GROUPS = array( 'pos', 'pay', 'ship', 'msg' );

		/**
		 * [2026-11-24 Johnny Chu - Chu Hoàng Anh] OC-011.3 — nền tảng đã có bộ đọc webhook vào. connector.json có `webhook: true` mà không có ở đây
		 * ⇒ giao diện nói "chưa nhận webhook từ {tên} trên bản này". Mỗi bộ đọc: khoá bảo mật (ô secret) bắt buộc + tách giao dịch.
		 */
		const WEBHOOK_ADAPTERS = array(
			'casso' => array( 'secret_field' => 'secure_token', 'header' => 'secure-token', 'scheme' => '' ),
			'sepay' => array( 'secret_field' => 'webhook_key', 'header' => 'authorization', 'scheme' => 'Apikey ' ),
		);

		/** @var array<string,array>|null */
		private static $registry = null;

		/* ---------------- registry ---------------- */

		/** @return array<string,array> key ⇒ connector.json đã kiểm hình dạng. */
		public static function registry(): array {
			if ( null !== self::$registry ) {
				return self::$registry;
			}
			self::$registry = array();
			$dir = ( defined( 'BIZCITY_CRM_DIR' ) ? BIZCITY_CRM_DIR : dirname( __DIR__, 2 ) ) . '/connectors/';
			foreach ( (array) glob( $dir . '*/connector.json' ) as $file ) {
				$key = basename( dirname( $file ) );
				$raw = is_readable( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
				$c   = json_decode( (string) $raw, true );
				if ( ! is_array( $c ) || ( $c['key'] ?? '' ) !== $key || ! preg_match( '/^[a-z0-9-]{2,40}$/', $key )
					|| ! in_array( $c['group'] ?? '', self::GROUPS, true ) || empty( $c['fields'] ) || ! is_array( $c['fields'] ) ) {
					continue; // file hỏng không làm sập màn; self-check (011.4) sẽ báo.
				}
				$fields = array();
				foreach ( array_slice( $c['fields'], 0, 4 ) as $f ) {
					if ( empty( $f['id'] ) || ! preg_match( '/^[a-z0-9_]{1,40}$/', (string) $f['id'] ) ) {
						continue;
					}
					$fields[] = array(
						'id'          => (string) $f['id'],
						'label'       => (string) ( $f['label'] ?? $f['id'] ),
						'type'        => in_array( $f['type'] ?? '', array( 'text', 'url', 'secret' ), true ) ? $f['type'] : 'text',
						'required'    => ! empty( $f['required'] ),
						'placeholder' => (string) ( $f['placeholder'] ?? '' ),
						'help'        => (string) ( $f['help'] ?? '' ),
					);
				}
				$check = null;
				if ( ! empty( $c['check']['url'] ) && 0 === strpos( (string) $c['check']['url'], 'https://' ) ) {
					$check = array(
						'method'  => 'POST' === strtoupper( (string) ( $c['check']['method'] ?? 'GET' ) ) ? 'POST' : 'GET',
						'url'     => (string) $c['check']['url'],
						'headers' => is_array( $c['check']['headers'] ?? null ) ? $c['check']['headers'] : array(),
						'ok_when' => is_array( $c['check']['ok_when'] ?? null ) ? $c['check']['ok_when'] : null,
					);
				}
				self::$registry[ $key ] = array(
					'key'          => $key,
					'group'        => $c['group'],
					'order'        => (int) ( $c['order'] ?? 99 ),
					'name'         => (string) ( $c['name'] ?? $key ),
					'desc'         => (string) ( $c['desc'] ?? '' ),
					'bizcity'      => ! empty( $c['bizcity'] ),
					'fields'       => $fields,
					'check'        => $check,
					'webhook'      => ! empty( $c['webhook'] ),
					'capabilities' => array_values( array_map( 'strval', (array) ( $c['capabilities'] ?? array() ) ) ),
				);
			}
			uasort( self::$registry, static function ( $a, $b ) {
				return array( array_search( $a['group'], self::GROUPS, true ), $a['order'] ) <=> array( array_search( $b['group'], self::GROUPS, true ), $b['order'] );
			} );
			return self::$registry;
		}

		/* ---------------- kho khoá ---------------- */

		private static function store(): array {
			$s = get_option( self::OPTION, array() );
			return is_array( $s ) ? $s : array();
		}

		private static function put( array $s ): void {
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, $s, '', 'no' );
			} else {
				update_option( self::OPTION, $s, false );
			}
		}

		private static function cipher_key(): string {
			return hash( 'sha256', wp_salt( 'auth' ) . '|ocrm-connectors', true );
		}

		/** @return string|null null = máy chủ không mã hoá được (không lưu thô). */
		private static function encrypt( string $plain ) {
			if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', (array) openssl_get_cipher_methods(), true ) ) {
				return null;
			}
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::cipher_key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $ct ? null : 'v1:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ciphertext envelope.
		}

		private static function decrypt( string $env ): string {
			if ( 0 !== strpos( $env, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$bin = base64_decode( substr( $env, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- ciphertext envelope.
			if ( false === $bin || strlen( $bin ) < 29 ) {
				return '';
			}
			$pt = openssl_decrypt( substr( $bin, 28 ), 'aes-256-gcm', self::cipher_key(), OPENSSL_RAW_DATA, substr( $bin, 0, 12 ), substr( $bin, 12, 16 ) );
			return false === $pt ? '' : $pt; // đổi salt ⇒ giải không được ⇒ coi như chưa có, giao diện xin dán lại.
		}

		/** Giá trị thật của các ô (chỉ dùng trong máy chủ: kiểm, gọi nền tảng). */
		public static function config( string $key ): array {
			$c   = self::registry()[ $key ] ?? null;
			$row = self::store()[ $key ] ?? null;
			if ( ! $c || ! is_array( $row ) ) {
				return array();
			}
			$out = array();
			foreach ( $c['fields'] as $f ) {
				$v = (string) ( $row['config'][ $f['id'] ] ?? '' );
				$out[ $f['id'] ] = 'secret' === $f['type'] ? self::decrypt( $v ) : $v;
			}
			return $out;
		}

		private static function mask( string $v ): string {
			$n = strlen( $v );
			return $n <= 4 ? str_repeat( '•', max( 4, $n ) ) : '••••' . substr( $v, -4 );
		}

		/** Giờ lưu là UTC (current_time gmt) ⇒ ISO có Z để trình duyệt đổi đúng giờ địa phương. */
		private static function iso( $v ) {
			return $v ? str_replace( ' ', 'T', (string) $v ) . 'Z' : null;
		}

		/** Hình chiếu cho giao diện: không có giá trị secret. */
		private static function project( array $c, $row ): array {
			$row    = is_array( $row ) ? $row : null;
			$values = array();
			$real   = $row ? self::config( $c['key'] ) : array();
			foreach ( $c['fields'] as $f ) {
				$v = (string) ( $real[ $f['id'] ] ?? '' );
				$values[ $f['id'] ] = 'secret' === $f['type']
					? array( 'set' => '' !== $v, 'preview' => '' !== $v ? self::mask( $v ) : '' )
					: array( 'set' => '' !== $v, 'value' => $v );
			}
			$c['has_check'] = null !== $c['check'];
			unset( $c['check'] );
			$ad = self::WEBHOOK_ADAPTERS[ $c['key'] ] ?? null;
			if ( $c['webhook'] && $ad && class_exists( 'BizCity_OCRM_Events', false ) && BizCity_OCRM_Events::ready() ) {
				$c['webhook_in'] = array(
					'url'          => rest_url( self::NS . '/webhook/' . $c['key'] ),
					'secret_field' => $ad['secret_field'],
					'secret_set'   => ! empty( $values[ $ad['secret_field'] ]['set'] ),
				) + BizCity_OCRM_Events::stats( $c['key'] );
			} else {
				$c['webhook_in'] = null;
			}
			$c['state'] = $row ? array(
				'status'        => in_array( $row['status'] ?? '', array( 'saved', 'ok', 'error' ), true ) ? $row['status'] : 'saved',
				'values'        => $values,
				'last_ok_at'    => self::iso( $row['last_ok_at'] ?? null ),
				'last_check_at' => self::iso( $row['last_check_at'] ?? null ),
				'last_error'    => $row['last_error'] ?? null,
				'updated_at'    => self::iso( $row['updated_at'] ?? null ),
				'updated_by'    => isset( $row['updated_by'] ) && ( $u = get_userdata( (int) $row['updated_by'] ) ) ? $u->display_name : null,
			) : null;
			return $c;
		}

		/* ---------------- kiểm tra ---------------- */

		/** @return array{ok:bool,message:string,http?:int} */
		private static function run_check( array $c, array $cfg ): array {
			$chk = $c['check'];
			$sub = static function ( string $tpl, bool $in_url ) use ( $cfg ) {
				return preg_replace_callback( '/\{([a-z0-9_]+)\}/', static function ( $m ) use ( $cfg, $in_url ) {
					$v = (string) ( $cfg[ $m[1] ] ?? '' );
					return $in_url ? rawurlencode( $v ) : str_replace( array( "\r", "\n" ), '', $v );
				}, $tpl );
			};
			$headers = array( 'Accept' => 'application/json' );
			foreach ( $chk['headers'] as $h => $tpl ) {
				$headers[ (string) $h ] = $sub( (string) $tpl, false );
			}
			$res = wp_safe_remote_request( $sub( $chk['url'], true ), array(
				'method'      => $chk['method'],
				'headers'     => $headers,
				'timeout'     => 10,
				'redirection' => 0,
			) );
			if ( is_wp_error( $res ) ) {
				return array( 'ok' => false, 'message' => 'Không gọi được ' . $c['name'] . ': ' . $res->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 401 === $code || 403 === $code ) {
				return array( 'ok' => false, 'http' => $code, 'message' => $c['name'] . ' từ chối khoá này. Kiểm lại khoá vừa dán (có thể đã hết hạn hoặc bị thu hồi).' );
			}
			if ( $code < 200 || $code >= 300 ) {
				return array( 'ok' => false, 'http' => $code, 'message' => $c['name'] . ' trả lỗi HTTP ' . $code . '. Thử lại sau ít phút.' );
			}
			if ( $chk['ok_when'] ) {
				$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
				$got  = is_array( $body ) ? ( $body[ (string) ( $chk['ok_when']['path'] ?? '' ) ] ?? null ) : null;
				if ( $got !== ( $chk['ok_when']['equals'] ?? null ) ) {
					return array( 'ok' => false, 'http' => $code, 'message' => $c['name'] . ' không nhận khoá này (trả lời không hợp lệ).' );
				}
			}
			return array( 'ok' => true, 'http' => $code, 'message' => 'Đã kiểm với ' . $c['name'] . '.' );
		}

		private static function check_and_store( array $c ): array {
			$s   = self::store();
			$row = $s[ $c['key'] ];
			$now = current_time( 'mysql', true );
			if ( null === $c['check'] ) {
				$row['status'] = 'saved';
				$r = array( 'ok' => null, 'message' => 'Đã lưu. ' . $c['name'] . ' chưa có cách kiểm tự động trên bản này, nên chưa biết khoá đúng hay sai.' );
			} else {
				$r = self::run_check( $c, self::config( $c['key'] ) );
				$row['status']        = $r['ok'] ? 'ok' : 'error';
				$row['last_check_at'] = $now;
				$row['last_error']    = $r['ok'] ? null : $r['message'];
				if ( $r['ok'] ) {
					$row['last_ok_at'] = $now;
				}
			}
			$s[ $c['key'] ] = $row;
			self::put( $s );
			return $r;
		}

		/* ---------------- REST ---------------- */

		public static function register(): void {
			register_rest_route( self::NS, '/oc/connectors', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			) );
			register_rest_route( self::NS, '/oc/connectors/(?P<key>[a-z0-9-]+)', array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_save' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array( 'config' => array( 'type' => 'object', 'required' => true ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'rest_delete' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			) );
			// [2026-11-24 Johnny Chu - Chu Hoàng Anh] OC-011.3 — webhook vào. Mở công khai (nền tảng gọi không có cookie); xác thực bằng khoá bảo mật trong handler.
			register_rest_route( self::NS, '/webhook/(?P<key>[a-z0-9-]+)', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_webhook' ),
				'permission_callback' => '__return_true',
			) );
			register_rest_route( self::NS, '/oc/connectors/(?P<key>[a-z0-9-]+)/check', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_check' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			) );
		}

		public static function can_manage(): bool {
			return current_user_can( 'manage_options' );
		}

		private static function err( string $code, string $message, string $hint, int $status, array $extra = array() ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $code ) + $extra, $status );
		}

		private static function one( WP_REST_Request $req ) {
			$c = self::registry()[ (string) $req['key'] ] ?? null;
			return $c ?: self::err( 'OC-CONN-01', 'Không có nền tảng này trong danh sách kết nối.', 'Tải lại trang Kết nối mở rộng.', 404 );
		}

		public static function rest_list() {
			$s     = self::store();
			$items = array();
			$last  = null;
			foreach ( self::registry() as $key => $c ) {
				$p       = self::project( $c, $s[ $key ] ?? null );
				$items[] = $p;
				if ( ! empty( $p['webhook_in']['last_at'] ) && ( null === $last || $p['webhook_in']['last_at'] > $last ) ) {
					$last = $p['webhook_in']['last_at'];
				}
			}
			$ready = class_exists( 'BizCity_OCRM_Events', false ) && BizCity_OCRM_Events::ready();
			return rest_ensure_response( array( 'ok' => true, 'data' => array(
				'items'   => $items,
				'webhook' => array(
					'ready'   => $ready,
					'last_at' => $last,
					'reason'  => $ready ? '' : 'Bảng sự việc chưa được tạo — cập nhật đủ file Open CRM (bizcity-twin-crm.php cùng bộ cài bảng).',
				),
			) ) );
		}

		public static function rest_save( WP_REST_Request $req ) {
			$c = self::one( $req );
			if ( $c instanceof WP_REST_Response ) {
				return $c;
			}
			$in   = (array) $req->get_param( 'config' );
			$s    = self::store();
			$old  = is_array( $s[ $c['key'] ] ?? null ) ? $s[ $c['key'] ] : array( 'config' => array() );
			$cfg  = array();
			$bad  = array();
			foreach ( $c['fields'] as $f ) {
				$id  = $f['id'];
				$raw = isset( $in[ $id ] ) && is_scalar( $in[ $id ] ) ? trim( (string) $in[ $id ] ) : '';
				if ( 'secret' === $f['type'] ) {
					if ( '' === $raw ) {
						$cfg[ $id ] = (string) ( $old['config'][ $id ] ?? '' ); // để trống = giữ khoá cũ.
					} else {
						$enc = self::encrypt( $raw );
						if ( null === $enc ) {
							return self::err( 'OC-CONN-03', 'Máy chủ không mã hoá được khoá nên chưa lưu.', 'Nhờ nhà cung cấp hosting bật PHP openssl rồi thử lại.', 500 );
						}
						$cfg[ $id ] = $enc;
					}
				} elseif ( 'url' === $f['type'] ) {
					$cfg[ $id ] = '' === $raw ? '' : (string) esc_url_raw( $raw, array( 'https' ) );
					if ( '' !== $raw && '' === $cfg[ $id ] ) {
						$bad[ $id ] = 'Địa chỉ cần bắt đầu bằng https://';
					}
				} else {
					$cfg[ $id ] = sanitize_text_field( $raw );
				}
				if ( $f['required'] && '' === $cfg[ $id ] && empty( $bad[ $id ] ) ) {
					$bad[ $id ] = 'Cần điền ' . $f['label'] . '.';
				}
			}
			if ( $bad ) {
				return self::err( 'OC-CONN-02', 'Còn ô chưa điền đúng.', 'Xem dòng đỏ dưới từng ô.', 400, array( 'fields' => $bad ) );
			}
			$s[ $c['key'] ] = array(
				'config'     => $cfg,
				'status'     => 'saved',
				'last_ok_at' => $old['last_ok_at'] ?? null,
				'updated_at' => current_time( 'mysql', true ),
				'updated_by' => get_current_user_id(),
			);
			self::put( $s );
			$r = self::check_and_store( $c );
			return rest_ensure_response( array( 'ok' => true, 'data' => array( 'check' => $r, 'item' => self::project( $c, self::store()[ $c['key'] ] ) ) ) );
		}

		public static function rest_check( WP_REST_Request $req ) {
			$c = self::one( $req );
			if ( $c instanceof WP_REST_Response ) {
				return $c;
			}
			if ( ! is_array( self::store()[ $c['key'] ] ?? null ) ) {
				return self::err( 'OC-CONN-04', 'Chưa có khoá ' . $c['name'] . ' để kiểm.', 'Điền khoá rồi bấm Lưu và kiểm tra.', 400 );
			}
			$r = self::check_and_store( $c );
			return rest_ensure_response( array( 'ok' => true, 'data' => array( 'check' => $r, 'item' => self::project( $c, self::store()[ $c['key'] ] ) ) ) );
		}

		/* ---------------- webhook vào (OC-011.3) ---------------- */

		/**
		 * POST /webhook/{key}. Trả lỗi bằng mã HTTP để nền tảng tự gửi lại khi lỗi phía mình (5xx), không gửi lại khi khoá sai (401).
		 * Mỗi giao dịch ⇒ BizCity_OCRM_Events::submit( payment.received | payment.sent ), khoá tự nhiên = mã giao dịch của nền tảng.
		 */
		public static function rest_webhook( WP_REST_Request $req ) {
			$key = (string) $req['key'];
			$ad  = self::WEBHOOK_ADAPTERS[ $key ] ?? null;
			$c   = self::registry()[ $key ] ?? null;
			if ( ! $ad || ! $c || empty( $c['webhook'] ) ) {
				return self::err( 'OC-HOOK-01', 'Open CRM chưa nhận webhook từ nền tảng này.', 'Xem danh sách ở Kết nối mở rộng.', 404 );
			}
			if ( ! class_exists( 'BizCity_OCRM_Events', false ) || ! BizCity_OCRM_Events::ready() ) {
				return self::err( 'OC-HOOK-02', 'Bảng sự việc chưa sẵn sàng.', 'Cập nhật đủ file Open CRM rồi để nền tảng gửi lại.', 503 );
			}
			$secret = (string) ( self::config( $key )[ $ad['secret_field'] ] ?? '' );
			if ( '' === $secret ) {
				return self::err( 'OC-HOOK-03', 'Chưa đặt khoá bảo mật webhook cho ' . $c['name'] . ' nên Open CRM không nhận.', 'Mở Kết nối mở rộng → ' . $c['name'] . ', điền khoá bảo mật webhook.', 401 );
			}
			$got = trim( (string) $req->get_header( $ad['header'] ) );
			if ( '' === $got ) {
				return self::err( 'OC-HOOK-04', 'Webhook không kèm khoá bảo mật.', 'authorization' === $ad['header'] ? 'Kiểm cấu hình chứng thực API Key trên ' . $c['name'] . '; nếu đã đặt mà vẫn báo lỗi này, máy chủ web có thể đang chặn header Authorization.' : 'Kiểm khoá bảo mật trên ' . $c['name'] . '.', 401 );
			}
			if ( '' !== $ad['scheme'] && 0 === stripos( $got, $ad['scheme'] ) ) {
				$got = trim( substr( $got, strlen( $ad['scheme'] ) ) );
			}
			if ( ! hash_equals( $secret, $got ) ) {
				return self::err( 'OC-HOOK-05', 'Khoá bảo mật webhook không khớp.', 'Dán lại cùng một khoá ở ' . $c['name'] . ' và ở Open CRM.', 401 );
			}
			if ( strlen( (string) $req->get_body() ) > 262144 ) {
				return self::err( 'OC-HOOK-06', 'Webhook quá lớn.', 'Mỗi lần gửi tối đa 256 KB.', 413 );
			}
			$body = $req->get_json_params();
			$txs  = is_array( $body ) ? call_user_func( array( __CLASS__, 'parse_' . $key ), $body ) : array();
			if ( ! $txs ) {
				return self::err( 'OC-HOOK-07', 'Không đọc được giao dịch trong webhook.', 'Nội dung không đúng khuôn ' . $c['name'] . '.', 400 );
			}
			$new = 0;
			$dup = 0;
			foreach ( $txs as $tx ) {
				$r = BizCity_OCRM_Events::submit( array(
					'source'  => $key,
					'doc_key' => $tx['ref'],
					'type'    => $tx['amount'] < 0 ? 'payment.sent' : 'payment.received',
					'payload' => $tx,
					'actor'   => 'webhook:' . $key,
				) );
				if ( empty( $r['ok'] ) ) {
					return self::err( $r['code'] ?? 'OC-EVT-03', 'Chưa ghi được giao dịch.', 'Nền tảng sẽ gửi lại; nếu lặp lại, xem nhật ký máy chủ.', 500 );
				}
				$r['duplicate'] ? $dup++ : $new++;
			}
			// Casso đọc { error: 0 }, SePay đọc { success: true }; trả cả hai cho chắc.
			return new WP_REST_Response( array( 'error' => 0, 'success' => true, 'recorded' => $new, 'duplicate' => $dup ), 200 );
		}

		/** Một giao dịch chuẩn hoá: ref (mã giao dịch), amount (âm = tiền ra), content, account, bank, at, raw. */
		private static function tx( $ref, $amount, $content, $account, $bank, $at, array $raw ) {
			$ref = trim( (string) $ref );
			if ( '' === $ref || ! is_numeric( $amount ) ) {
				return null;
			}
			return array( 'ref' => $ref, 'amount' => 0 + $amount, 'content' => (string) $content, 'account' => (string) $account, 'bank' => (string) $bank, 'at' => (string) $at, 'raw' => $raw );
		}

		/** Casso: { error, data: [ {id, tid, description, amount, when, bank_sub_acc_id…} ] } hoặc data là một đối tượng (bản v2: reference, transactionDateTime, accountNumber). */
		public static function parse_casso( array $b ): array {
			$data = $b['data'] ?? null;
			if ( ! is_array( $data ) ) {
				return array();
			}
			$list = isset( $data['amount'] ) ? array( $data ) : $data;
			$out  = array();
			foreach ( $list as $d ) {
				if ( ! is_array( $d ) ) {
					continue;
				}
				$ref = $d['tid'] ?? $d['reference'] ?? $d['id'] ?? '';
				$tx  = self::tx( $ref, $d['amount'] ?? null, $d['description'] ?? '', $d['bank_sub_acc_id'] ?? $d['subAccId'] ?? $d['accountNumber'] ?? '', $d['bankName'] ?? $d['bankAbbreviation'] ?? '', $d['when'] ?? $d['transactionDateTime'] ?? '', $d );
				if ( $tx ) {
					$out[] = $tx;
				}
			}
			return $out;
		}

		/** SePay: { id, gateway, transactionDate, accountNumber, content, transferType: in|out, transferAmount, referenceCode… } — một giao dịch mỗi lần. */
		public static function parse_sepay( array $b ): array {
			if ( ! isset( $b['id'], $b['transferAmount'] ) || ! is_numeric( $b['transferAmount'] ) ) {
				return array();
			}
			$amt = 'out' === ( $b['transferType'] ?? 'in' ) ? -abs( 0 + $b['transferAmount'] ) : abs( 0 + $b['transferAmount'] );
			$tx  = self::tx( $b['id'], $amt, $b['content'] ?? $b['description'] ?? '', $b['accountNumber'] ?? '', $b['gateway'] ?? '', $b['transactionDate'] ?? '', $b );
			return $tx ? array( $tx ) : array();
		}

		public static function rest_delete( WP_REST_Request $req ) {
			$c = self::one( $req );
			if ( $c instanceof WP_REST_Response ) {
				return $c;
			}
			$s = self::store();
			unset( $s[ $c['key'] ] );
			self::put( $s );
			return rest_ensure_response( array( 'ok' => true, 'data' => array( 'item' => self::project( $c, null ) ) ) );
		}
	}

	add_action( 'rest_api_init', array( 'BizCity_OCRM_Connectors', 'register' ) );
}
