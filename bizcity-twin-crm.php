<?php
/**
 * Plugin Name:       Open CRM
 * [2026-11-21 04:08 PM Johnny Chu - Chu Hoàng Anh] BRAND-OPEN-CRM — brand name Zalo Brain CRM -> Open CRM, repo open-crm (slug bizcity-twin-crm, prefix and owner unchanged).
 * Plugin URI:        https://github.com/hoanganh8389/open-crm
 * Description:       Quản lý đội ngũ nhiều Zalo bot trên Zalo Brain — pipeline, giao việc, SLA, báo cáo, chiến dịch, CSKH tự động, B2B, hoá đơn. Kích hoạt và cài sổ dữ liệu được khi chưa có Zalo Brain; hộp thư và nhận tin cần Zalo Brain (bizcity-twin-ai).
 * Version:           1.0.0
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Network:           false
 * Author:            Biz Central Brain — Johnny Chu (Chu Hoàng Anh)
 * License:           GPL-2.0-or-later
 * Text Domain:       bizcity-twin-crm
 * Zalo Brain Addon:  crm
 *
 * [2026-10-12 11:25 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.102 ST102-L30 — R-ADDON-HOST v1 — bỏ `Requires Plugins`; chặn kích hoạt + thông báo đã có; thêm header `Zalo Brain Addon:`.
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-3.2 — Zalo Brain CRM is a real WordPress plugin now (D96-3):
 * activated per site (single-site or any subsite, never network-wide), it boots on top of the CRM spine that
 * bizcity-twin-ai/core/crm loads before plugins_loaded@6. Without Zalo Brain it shows one notice and loads nothing.
 * Design: bizcity-twin-ai core/channel-gateway/docs/PHASE-0.96-ZALO-BRAIN-CRM-SPLIT/30-ZALO-BRAIN-CRM-PLUGIN.md.
 * Continues "BizCity Twin CRM (Inbox Hub)" 0.32.2; version restarts at 1.0.0.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------
 * Constants (names kept from the bundled era so nothing else changes)
 * ------------------------------------------------------------------ */
if ( ! defined( 'BIZCITY_CRM_VERSION' ) )        { define( 'BIZCITY_CRM_VERSION', '1.0.0' ); }
// [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 D156-5 — 1.2.0: this plugin creates bizcity_crm_teams + bizcity_crm_team_members (moved from core).
// [2026-11-24 01:20 AM Johnny Chu - Chu Hoàng Anh] OC-010.3 — 1.3.0: migrate_oc_010 (crm_tasks.project + crm_tasks.source, ADD-only).
if ( ! defined( 'BIZCITY_CRM_EXT_DB_VERSION' ) ) { define( 'BIZCITY_CRM_EXT_DB_VERSION', '1.4.0' ); } // [2026-11-24 Johnny Chu - Chu Hoàng Anh] OC-011.3 — 1.4.0: migrate_oc_011 (bảng bizcity_ocrm_events). // [2026-10-28 05:58 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L1 — 1.1.0: migrate_phase_146 (announce columns on broadcasts + recipients)
if ( ! defined( 'BIZCITY_CRM_FILE' ) )           { define( 'BIZCITY_CRM_FILE', __FILE__ ); }
if ( ! defined( 'BIZCITY_CRM_DIR' ) )            { define( 'BIZCITY_CRM_DIR', __DIR__ ); }
if ( ! defined( 'BIZCITY_CRM_URL' ) )            { define( 'BIZCITY_CRM_URL', plugins_url( '', __FILE__ ) ); }
if ( ! defined( 'BIZCITY_CRM_REST_NS' ) )        { define( 'BIZCITY_CRM_REST_NS', 'bizcity-crm/v1' ); }

/** The only condition: Zalo Brain (bizcity-twin-ai) has loaded the CRM spine on this request. No multisite needed. */
function bizcity_crm_spine_ready(): bool {
	return defined( 'BIZCITY_CRM_SPINE_LOADED' ) && class_exists( 'BizCity_CRM_Spine', false );
}

/*
 * [2026-11-20 08:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.3 (doc 00 §4–§5) — the plugin activates and installs its DB without Zalo Brain.
 * spine/ holds byte copies of core/crm's installer + the crm-ledger@1 contract (node bin/validate-crm-ledger-contract.mjs --sync), so one install()
 * creates exactly the tables core would (ledger + labels + archive receipts + magic links) plus this plugin's own. Same version stamp as core
 * (BIZCITY_CRM_STANDALONE_DB_VERSION = core BIZCITY_CRM_DB_VERSION, checked by the validator) ⇒ Zalo Brain activated later adopts the tables.
 * With Zalo Brain present the copy is never loaded: core's installer is the owner (R-DCL, one owner per table).
 */
if ( ! defined( 'BIZCITY_CRM_STANDALONE_DB_VERSION' ) ) { define( 'BIZCITY_CRM_STANDALONE_DB_VERSION', '2.1.0' ); }

/** Loads the installer pair for a site WITHOUT Zalo Brain. @return bool true when BizCity_CRM_DB_Installer_Ext is usable. */
function bizcity_crm_standalone_installer(): bool {
	if ( class_exists( 'BizCity_CRM_DB_Installer_Ext', false ) ) {
		return true;
	}
	if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2', false ) ) {
		if ( defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
			return false; // Zalo Brain is active: its own core/crm installer owns the ledger tables
		}
		if ( ! defined( 'BIZCITY_CRM_DB_VERSION' ) ) {
			define( 'BIZCITY_CRM_DB_VERSION', BIZCITY_CRM_STANDALONE_DB_VERSION );
		}
		$spine = __DIR__ . '/spine/includes/class-db-installer.php';
		if ( ! is_readable( $spine ) ) {
			return false;
		}
		require_once $spine;
	}
	$ext = __DIR__ . '/includes/class-db-installer-ext.php';
	if ( is_readable( $ext ) ) {
		require_once $ext;
	}
	return class_exists( 'BizCity_CRM_DB_Installer_Ext', false );
}

// Standalone: keep the DB current after a plugin update (same 15-minute guard as with Zalo Brain, see maybe_upgrade_ext()).
add_action( 'admin_init', static function () {
	if ( defined( 'BIZCITY_TWIN_AI_VERSION' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( bizcity_crm_standalone_installer() ) {
		BizCity_CRM_DB_Installer_Ext::maybe_upgrade_ext();
	}
} );

add_action( 'admin_notices', static function () {
	if ( ! current_user_can( 'activate_plugins' ) || defined( 'BIZCITY_TWIN_AI_VERSION' ) || defined( 'BIZCITY_CRM_SPINE_LOADED' ) ) {
		return;
	}
	// [2026-11-20 08:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.3 — standalone: say what works (the DB) and what does not (the screens), never an error box.
	$installed = get_option( 'bizcity_crm_ext_db_ver' ) === BIZCITY_CRM_EXT_DB_VERSION;
	// [2026-11-21 04:08 PM Johnny Chu - Chu Hoàng Anh] BRAND-OPEN-CRM — brand name Zalo Brain CRM -> Open CRM, repo open-crm (slug bizcity-twin-crm, prefix and owner unchanged).
	echo '<div class="notice notice-info"><p><strong>Open CRM</strong> — '
		. ( $installed ? 'đã cài sổ dữ liệu (chạy một mình, không cần Zalo Brain). ' : 'chưa cài xong sổ dữ liệu; tải lại trang này để thử lại. ' )
		. 'Hộp thư, đội và nhận tin Zalo cần plugin <strong>Zalo Brain</strong> (bizcity-twin-ai). Cách bật: cài và kích hoạt Zalo Brain — dữ liệu đã cài được giữ nguyên.</p></div>';
} );

// [2026-08-09 Johnny Chu] R-PERF-LOADER-BUNDLE — the default TwinChat admin shell renders its own iframe and does not
// need the CRM runtime graph. Keep CRM surface requests (plugin=crm), REST, webhooks, cron and public /crm/ alive.
$_bizcity_crm_shell_plugin = isset( $_GET['plugin'] ) ? sanitize_key( (string) $_GET['plugin'] ) : '';
$_bizcity_crm_skip_boot    = is_admin()
	&& isset( $_GET['page'] )
	&& 'bizcity-twinchat' === sanitize_key( (string) $_GET['page'] )
	&& ( '' === $_bizcity_crm_shell_plugin || 'twinchat' === $_bizcity_crm_shell_plugin );
unset( $_bizcity_crm_shell_plugin );

// Boot after the spine (core/crm loads inside bizcity-twin-ai, which WordPress loads first — alphabetical order).
if ( ! $_bizcity_crm_skip_boot ) {
	add_action( 'plugins_loaded', static function () {
		if ( ! bizcity_crm_spine_ready() ) {
			return;
		}
		require_once __DIR__ . '/bootstrap.php';
		BizCity_CRM_Plugin::instance();
	}, 6 );
}
unset( $_bizcity_crm_skip_boot );

/* ------------------------------------------------------------------
 * Activation — per site. Installs the extension tables through the spine's single dbDelta pass, grants caps,
 * seeds print templates, flushes rewrites (/crm/, /o/<token>). Deactivation never drops anything (D96-6).
 * ------------------------------------------------------------------ */
register_activation_hook( __FILE__, static function () {
	// [2026-11-20 08:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.3 — no Zalo Brain: activate anyway and install the whole DB from spine/
	// (was: deactivate + wp_die). Current blog only (R-MSDB); each subsite installs when its admin opens it (admin_init above).
	if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
		if ( bizcity_crm_standalone_installer() ) {
			BizCity_CRM_DB_Installer_Ext::install();
		}
		if ( is_readable( __DIR__ . '/includes/print-ads/class-print-templates-installer.php' ) ) {
			require_once __DIR__ . '/includes/print-ads/class-print-templates-installer.php';
			if ( class_exists( 'BizCity_CRM_Print_Templates_Installer' ) ) {
				BizCity_CRM_Print_Templates_Installer::install();
			}
		}
		bizcity_crm_flush_rewrites_now();
		return;
	}
	if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) && defined( 'BIZCITY_TWIN_AI_DIR' ) && is_readable( BIZCITY_TWIN_AI_DIR . '/core/crm/bootstrap.php' ) ) {
		require_once BIZCITY_TWIN_AI_DIR . '/core/crm/bootstrap.php'; // activation requests may not have loaded the spine yet
	}
	if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
		return; // spine not loadable on this request; maybe_upgrade_ext() finishes on the next admin page
	}
	require_once __DIR__ . '/includes/class-db-installer-ext.php';
	BizCity_CRM_DB_Installer_Ext::install();
	if ( class_exists( 'BizCity_CRM_Capabilities' ) ) {
		BizCity_CRM_Capabilities::grant_all();
	}
	require_once __DIR__ . '/includes/print-ads/class-print-templates-installer.php';
	BizCity_CRM_Print_Templates_Installer::install();
	bizcity_crm_flush_rewrites_now();
} );

register_deactivation_hook( __FILE__, static function () {
	flush_rewrite_rules( false );
} );

/* ------------------------------------------------------------------
 * Public slug `/crm/` — direct CRM SPA mount (full page, no nested wp-admin).
 * [2026-11-23 11:59 PM Johnny Chu - Chu Hoàng Anh] OC-003.3 — Open CRM is its own page; no TwinShell iframe.
 * ------------------------------------------------------------------ */
function bizcity_crm_add_rewrites(): void {
	add_rewrite_rule( '^crm/?$', 'index.php?bizcity_agent_page=crm', 'top' );
	add_rewrite_tag( '%bizcity_agent_page%', '([^&]+)' );
}
add_action( 'init', 'bizcity_crm_add_rewrites', 11 );

/**
 * [2026-10-09 11:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.96 hotfix — activation runs AFTER `init`, so the `init`
 * hook above never fired on that request and a plain flush saved rules WITHOUT /crm/ (owner: "kích hoạt xong nhưng
 * không tự flush"). Add the rule first, flush now, and also queue the central registry flush so the next admin page
 * rebuilds with every plugin's rules in place.
 */
function bizcity_crm_flush_rewrites_now(): void {
	bizcity_crm_add_rewrites();
	flush_rewrite_rules( false );
	if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
		BizCity_Rewrite_Flush_Registry::queue_flush( 'bizcity-twin-crm-public' );
	}
}

// Version bump ⇒ one central flush on the next admin page; this also heals sites activated before the hotfix.
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'bizcity-twin-crm-public', '1.0.1' );
}

add_action( 'template_redirect', static function () {
	if ( get_query_var( 'bizcity_agent_page' ) !== 'crm' ) {
		return;
	}
	// [2026-10-09 11:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.96 hotfix — plugin did not boot (Zalo Brain's CRM spine not
	// ready on this request, e.g. an older Zalo Brain on the server) ⇒ never print an empty page. Let the core /crm/
	// page (template_redirect@20) answer; without it, say what is wrong and how to fix it.
	if ( ! class_exists( 'BizCity_CRM_Admin_Menu' ) ) {
		if ( class_exists( 'BizCity_CRM_Core_Page', false ) ) {
			return;
		}
		// [2026-11-20 08:23 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.3 — standalone (no Zalo Brain at all): the DB is installed, the inbox needs Zalo Brain. Not a server error ⇒ 200.
		if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
			wp_die(
				// [2026-11-21 04:08 PM Johnny Chu - Chu Hoàng Anh] BRAND-OPEN-CRM — brand name Zalo Brain CRM -> Open CRM, repo open-crm (slug bizcity-twin-crm, prefix and owner unchanged).
				'Open CRM đã cài sổ dữ liệu nhưng hộp thư cần plugin Zalo Brain (bizcity-twin-ai) để nhận và gửi tin. Cách bật: cài và kích hoạt Zalo Brain rồi mở lại trang này — dữ liệu đã cài được giữ nguyên.',
				'Open CRM',
				array( 'response' => 200 )
			);
		}
		wp_die(
			// [2026-11-21 04:08 PM Johnny Chu - Chu Hoàng Anh] BRAND-OPEN-CRM — brand name Zalo Brain CRM -> Open CRM, repo open-crm (slug bizcity-twin-crm, prefix and owner unchanged).
			'Open CRM đã bật nhưng chưa chạy được vì plugin Zalo Brain (bizcity-twin-ai) trên máy chủ chưa có phần lõi CRM. Cách sửa: cập nhật Zalo Brain lên bản mới nhất rồi tải lại trang.',
			'Open CRM',
			array( 'response' => 503 )
		);
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( home_url( '/crm/' ) ) );
		exit;
	}
	add_filter( 'show_admin_bar', '__return_false', 100 );
	// [2026-09-19 Johnny Chu] PHASE-0.60 C2/C5 — public /crm/ uses the same action gate as wp-admin.
	if ( class_exists( 'BizCity_CRM_Authority' ) && ! BizCity_CRM_Authority::can( 'crm.inbox.open', array(), BizCity_CRM_Actor::current( 'be' ) )['ok'] ) {
		wp_die( 'Tài khoản này chưa được cấp quyền sử dụng CRM Inbox. Hãy liên hệ quản lý để được cấp quyền.', 'CRM', array( 'response' => 403 ) );
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php esc_html_e( 'Open CRM', 'bizcity-twin-crm' ); ?></title>
<?php
// R-PERF-LOADER — print only the canonical CRM handles; no wp_head()/wp_footer().
$crm_public_assets = array();
if ( class_exists( 'BizCity_CRM_Admin_Menu' ) ) {
	$crm_public_assets = BizCity_CRM_Admin_Menu::instance()->enqueue_public_assets();
}
$crm_script_handle = ! empty( $crm_public_assets['script'] ) ? $crm_public_assets['script'] : '';
if ( ! empty( $crm_public_assets['style'] ) ) {
	wp_print_styles( array( $crm_public_assets['style'] ) );
}
?>
<style>
html,body{margin:0;padding:0;height:100%;background:#FAFBFC;}
html { margin-top: 0 !important; }
body { padding-top: 0 !important; }
#wpadminbar, #bizchat-float-btn, #bizchat-window, .bizchat-window, [id*="bizchat"], [class*="bizchat"] { display:none !important; }
#bizcity-crm-inbox-root{display:block;width:100vw;height:100vh;min-height:600px;}
</style>
</head>
<body>
<div id="bizcity-crm-inbox-root"></div>
<?php
if ( '' !== $crm_script_handle ) {
	// PHASE-CRM-MUSTLOAD — the bundle must execute after the mount element exists.
	wp_print_scripts( array( $crm_script_handle ) );
} else {
	// [2026-10-09 11:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.96 hotfix — no app script ⇒ say so instead of a blank page.
	echo '<p style="font:15px/1.5 system-ui,sans-serif;padding:24px;color:#334155">Chưa tải được giao diện CRM (thiếu tệp <code>assets/dist/inbox-app.js</code> của plugin Open CRM). Cách sửa: tải lại đủ thư mục <code>bizcity-twin-crm/assets/dist/</code> lên máy chủ rồi tải lại trang.</p>';
}
?>
</body>
</html><?php
	exit;
} );
