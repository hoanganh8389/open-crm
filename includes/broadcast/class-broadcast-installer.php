<?php
/**
 * Broadcast Installer — tạo bảng bizcity_cg_broadcasts + bizcity_cg_broadcast_recipients.
 *
 * Cache Contract (group: bzcast)
 *   Keys:
 *     broadcast_list_{status}_{page}  — TTL_MEDIUM — danh sách broadcast
 *     broadcast_{id}                  — TTL_MEDIUM — single row
 *     progress_{id}                   — TTL_SHORT  — tiến độ gửi
 *   Invalidations: insert/update/delete → BizCity_Cache::flush_group('bzcast')
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — new installer.
 * [2026-10-11 10:19 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7d — moved from bizcity-twin-ai
 * core/channel-gateway/includes/broadcast/ into Zalo Brain CRM (D-W20-1, Q-W16-4: the mass-send engine is a CRM tool, CRM
 * Broadcast already drove it through bizcity-channel/v1/broadcasts*). Table names, columns, SCHEMA_VERSION and the version
 * option bizcity_cg_broadcast_schema_version are unchanged, so an existing site neither re-creates nor loses rows. The DDL
 * now rides the extension installer: BizCity_CRM_DB_Installer_Ext::append_tables() appends table_sql() to the spine's
 * single dbDelta pass (filter bizcity_crm_install_tables) and ext_tables() self-heals a missing table.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-CG-BROADCAST (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Broadcast_Installer' ) ) {
	return;
}

class BizCity_Broadcast_Installer {

	const SCHEMA_VERSION  = '1.0.0';
	const VERSION_OPTION  = 'bizcity_cg_broadcast_schema_version';

	/** @return string Physical name of the jobs table. */
	public static function tbl_broadcasts() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_cg_broadcasts';
	}

	/** @return string Physical name of the recipients table. */
	public static function tbl_recipients() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_cg_broadcast_recipients';
	}

	/**
	 * CREATE TABLE statements (dbDelta format). Same DDL as PHASE-CG-BROADCAST 1.0.0.
	 *
	 * @param string $charset Charset/collation clause.
	 * @return string[]
	 */
	public static function table_sql( $charset ) {
		$t_bc   = self::tbl_broadcasts();
		$t_rcpt = self::tbl_recipients();

		$sql_broadcasts = "CREATE TABLE `{$t_bc}` (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name          VARCHAR(255)    NOT NULL DEFAULT '',
			type          VARCHAR(20)     NOT NULL DEFAULT 'zns',
			status        VARCHAR(20)     NOT NULL DEFAULT 'draft',
			meta_json     LONGTEXT        NULL,
			total_count   INT UNSIGNED    NOT NULL DEFAULT 0,
			sent_count    INT UNSIGNED    NOT NULL DEFAULT 0,
			failed_count  INT UNSIGNED    NOT NULL DEFAULT 0,
			batch_size    SMALLINT UNSIGNED NOT NULL DEFAULT 10,
			delay_sec     SMALLINT UNSIGNED NOT NULL DEFAULT 5,
			created_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at    DATETIME        NOT NULL,
			started_at    DATETIME        NULL,
			done_at       DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_created (created_at)
		) {$charset};";

		$sql_recipients = "CREATE TABLE `{$t_rcpt}` (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			broadcast_id  BIGINT UNSIGNED NOT NULL,
			name          VARCHAR(255)    NOT NULL DEFAULT '',
			phone         VARCHAR(50)     NULL,
			email         VARCHAR(190)    NULL,
			custom_data   LONGTEXT        NULL,
			status        VARCHAR(20)     NOT NULL DEFAULT 'queued',
			error         TEXT            NULL,
			sent_at       DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY idx_bc_status (broadcast_id, status),
			KEY idx_bc_id     (broadcast_id)
		) {$charset};";

		return array( $sql_broadcasts, $sql_recipients );
	}

	/**
	 * Run installer (idempotent, ADD-only via dbDelta) through the extension installer's single pass.
	 * Called by the dispatcher's self-heal and by maybe_upgrade().
	 */
	public static function install() {
		if ( class_exists( 'BizCity_CRM_DB_Installer_Ext' ) ) {
			BizCity_CRM_DB_Installer_Ext::install();
		} else {
			// Spine absent (should not happen: the plugin does not boot without core) — create just these two tables.
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( self::table_sql( $wpdb->get_charset_collate() ) as $stmt ) {
				dbDelta( $stmt );
			}
		}
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Stamp check. When the stamp is behind but the tables already exist (the extension pass created them), only re-stamp.
	 */
	public static function maybe_upgrade() {
		$current = get_option( self::VERSION_OPTION, '' );
		if ( version_compare( (string) $current, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}
		global $wpdb;
		$have = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::tbl_recipients() ) ) === self::tbl_recipients()
			&& (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::tbl_broadcasts() ) ) === self::tbl_broadcasts();
		if ( $have ) {
			update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
			return;
		}
		self::install();
	}
}

// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Schema Registry (R-CR.2).
// [2026-10-11 10:19 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7d — module id follows the changelog (modules.twin-crm).
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_cg_broadcasts',
		'modules.twin-crm',
		BizCity_Broadcast_Installer::SCHEMA_VERSION,
		BizCity_Broadcast_Installer::VERSION_OPTION,
		array( 'BizCity_Broadcast_Installer', 'install' )
	);
}
