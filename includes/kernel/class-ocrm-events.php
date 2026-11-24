<?php
/**
 * [2026-11-24 Johnny Chu - Chu Hoàng Anh] PHASE-011 OC-011.3 — Event Gateway tối thiểu: một cửa submit() ghi bảng `bizcity_ocrm_events`
 * (docs/open-crm/20-DATA-LEDGER §2, chủ chọn "chỉ tạo bảng ocrm_events" 2026-11-24).
 *
 * Luật: chỉ lớp này ghi bảng; không UPDATE, không DELETE. Khoá tự nhiên UNIQUE (source, doc_key) ⇒ nhà cung cấp gửi lại cùng giao dịch
 * bao nhiêu lần cũng chỉ một dòng. Gửi lại cùng mã mà nội dung khác ⇒ KHÔNG ghi đè, trả `changed = true` (dòng đảo cần sổ ocrm_ledger —
 * chưa có; ghi ở checklist). Dòng mới ⇒ do_action( 'bizcity_ocrm_event', $event ) để plugin khác (Automation) nghe.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_OCRM_Events', false ) ) {

	class BizCity_OCRM_Events {

		public static function table(): string {
			global $wpdb;
			return $wpdb->prefix . 'bizcity_ocrm_events';
		}

		/** DDL — bộ cài gọi qua dbDelta (BizCity_CRM_DB_Installer_Ext::migrate_oc_011). */
		public static function ddl(): string {
			global $wpdb;
			$t = self::table();
			return "CREATE TABLE `{$t}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(40) NOT NULL,
			doc_key VARCHAR(120) NOT NULL,
			type VARCHAR(64) NOT NULL,
			version INT UNSIGNED NOT NULL DEFAULT 1,
			payload LONGTEXT NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'received',
			received_at DATETIME NOT NULL,
			actor VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_source_doc (source, doc_key),
			KEY idx_type_at (type, received_at),
			KEY idx_source_at (source, received_at)
		) {$wpdb->get_charset_collate()};";
		}

		private static $ready = null;

		public static function ready(): bool {
			if ( null === self::$ready ) {
				global $wpdb;
				$t           = self::table();
				self::$ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
			}
			return self::$ready;
		}

		/**
		 * @param array{source:string,doc_key:string,type:string,payload:array,actor?:string} $e
		 * @return array{ok:bool,id?:int,duplicate?:bool,changed?:bool,code?:string}
		 */
		public static function submit( array $e ): array {
			global $wpdb;
			$source = (string) ( $e['source'] ?? '' );
			$doc    = trim( (string) ( $e['doc_key'] ?? '' ) );
			$type   = (string) ( $e['type'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9-]{2,40}$/', $source ) || '' === $doc || strlen( $doc ) > 120 || ! preg_match( '/^[a-z]+(\.[a-z_]+)+$/', $type ) ) {
				return array( 'ok' => false, 'code' => 'OC-EVT-01' );
			}
			if ( ! self::ready() ) {
				return array( 'ok' => false, 'code' => 'OC-EVT-02' );
			}
			$payload = wp_json_encode( $e['payload'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$t       = self::table();
			$now     = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$t}` (source, doc_key, type, version, payload, status, received_at, actor) VALUES (%s, %s, %s, 1, %s, 'received', %s, %s)",
				$source, $doc, $type, $payload, $now, substr( (string) ( $e['actor'] ?? '' ), 0, 64 ) ) );
			if ( false === $n ) {
				return array( 'ok' => false, 'code' => 'OC-EVT-03' );
			}
			if ( 1 === (int) $n ) {
				$id    = (int) $wpdb->insert_id;
				$event = array( 'id' => $id, 'source' => $source, 'doc_key' => $doc, 'type' => $type, 'payload' => $e['payload'] ?? array(), 'received_at' => $now );
				do_action( 'bizcity_ocrm_event', $event );
				return array( 'ok' => true, 'id' => $id, 'duplicate' => false );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, payload FROM `{$t}` WHERE source = %s AND doc_key = %s", $source, $doc ), ARRAY_A );
			return array( 'ok' => true, 'id' => (int) ( $row['id'] ?? 0 ), 'duplicate' => true, 'changed' => $row && $row['payload'] !== $payload );
		}

		/** @return array{count:int,last_at:?string,recent:array} sự việc gần nhất của một nguồn (cho màn Kết nối). */
		public static function stats( string $source, int $limit = 5 ): array {
			global $wpdb;
			if ( ! self::ready() ) {
				return array( 'count' => 0, 'last_at' => null, 'recent' => array() );
			}
			$t = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, doc_key, type, payload, received_at FROM `{$t}` WHERE source = %s ORDER BY id DESC LIMIT %d", $source, $limit ), ARRAY_A );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE source = %s", $source ) );
			$recent = array();
			foreach ( $rows as $r ) {
				$p        = json_decode( (string) $r['payload'], true );
				$recent[] = array(
					'id'          => (int) $r['id'],
					'doc_key'     => $r['doc_key'],
					'type'        => $r['type'],
					'amount'      => is_array( $p ) ? ( $p['amount'] ?? null ) : null,
					'content'     => is_array( $p ) ? (string) ( $p['content'] ?? '' ) : '',
					'received_at' => str_replace( ' ', 'T', $r['received_at'] ) . 'Z',
				);
			}
			return array( 'count' => $count, 'last_at' => $recent ? $recent[0]['received_at'] : null, 'recent' => $recent );
		}
	}
}
