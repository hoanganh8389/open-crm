<?php
/**
 * [2026-11-24 Johnny Chu - Chu Hoàng Anh] PHASE-013 OC-013.1 — danh sách ứng dụng (add-on) cho màn "Ứng dụng, API & Webhook".
 *
 * Add-on = plugin WordPress đang bật có file `module.json` ở gốc thư mục plugin, khai `"schema": "ocrm-addon@1"`
 * (10-ARCHITECTURE §2: đọc khai báo, không đọc mã). File module.json của mục đích khác (không có schema này) bị bỏ qua.
 *
 * GET bizcity-crm/v1/oc/addons (manage_options) ⇒ { items: [ {key, name, version, author, description, kind, slots, permissions, plugin} ],
 *   invalid: [ {plugin, reason} ] }
 * Bật / tắt và hiện chỗ gắn (trang riêng, panel chat, nút soạn tin) là bước sau — bản này chỉ đọc.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_OCRM_Apps_REST', false ) ) {

	class BizCity_OCRM_Apps_REST {

		const NS    = 'bizcity-crm/v1';
		const SLOTS = array( 'page', 'chat_panel', 'composer_button', 'automation_node' );
		const KINDS = array( 'ui', 'server', 'external' );

		public static function register(): void {
			register_rest_route( self::NS, '/oc/addons', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_addons' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			) );
		}

		/** Plugin đang bật (blog + toàn mạng). */
		private static function active_plugins(): array {
			$list = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$list = array_merge( $list, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			return array_values( array_unique( array_map( 'strval', $list ) ) );
		}

		public static function scan(): array {
			$items   = array();
			$invalid = array();
			foreach ( self::active_plugins() as $file ) {
				$dir = dirname( $file );
				if ( '.' === $dir || '' === $dir ) {
					continue; // plugin một file không có thư mục ⇒ không có module.json.
				}
				$path = WP_PLUGIN_DIR . '/' . $dir . '/module.json';
				if ( ! is_readable( $path ) ) {
					continue;
				}
				$m = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
				if ( ! is_array( $m ) || 'ocrm-addon@1' !== ( $m['schema'] ?? '' ) ) {
					continue; // module.json của việc khác.
				}
				$key = (string) ( $m['key'] ?? '' );
				if ( ! preg_match( '/^[a-z0-9-]{2,40}$/', $key ) || empty( $m['name'] ) ) {
					$invalid[] = array( 'plugin' => $dir, 'reason' => 'module.json thiếu key (a-z, 0-9, -) hoặc name.' );
					continue;
				}
				if ( isset( $items[ $key ] ) ) {
					$invalid[] = array( 'plugin' => $dir, 'reason' => 'Trùng key "' . $key . '" với plugin ' . $items[ $key ]['plugin'] . '.' );
					continue;
				}
				$items[ $key ] = array(
					'key'         => $key,
					'name'        => (string) $m['name'],
					'version'     => (string) ( $m['version'] ?? '' ),
					'author'      => (string) ( $m['author'] ?? '' ),
					'description' => (string) ( $m['description'] ?? '' ),
					'kind'        => in_array( $m['kind'] ?? '', self::KINDS, true ) ? $m['kind'] : 'server',
					'slots'       => array_values( array_intersect( self::SLOTS, array_map( 'strval', (array) ( $m['slots'] ?? array() ) ) ) ),
					'permissions' => array_values( array_map( 'strval', (array) ( $m['permissions'] ?? array() ) ) ),
					'plugin'      => $dir,
				);
			}
			return array( 'items' => array_values( $items ), 'invalid' => $invalid );
		}

		public static function get_addons() {
			return rest_ensure_response( array( 'ok' => true, 'data' => self::scan() ) );
		}
	}

	add_action( 'rest_api_init', array( 'BizCity_OCRM_Apps_REST', 'register' ) );
}
