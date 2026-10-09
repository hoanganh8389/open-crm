<?php
/**
 * Plugin Name:       Zalo Brain CRM
 * Plugin URI:        https://github.com/hoanganh8389/zalo-brain-crm
 * Description:       Quản lý đội ngũ nhiều Zalo bot trên Zalo Brain — pipeline, giao việc, SLA, báo cáo, chiến dịch, CSKH tự động, B2B, hoá đơn. Cần plugin Zalo Brain (bizcity-twin-ai).
 * Version:           1.0.0
 * Requires Plugins:  bizcity-twin-ai
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Network:           false
 * Author:            Biz Central Brain — Johnny Chu (Chu Hoàng Anh)
 * License:           GPL-2.0-or-later
 * Text Domain:       bizcity-twin-crm
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
if ( ! defined( 'BIZCITY_CRM_EXT_DB_VERSION' ) ) { define( 'BIZCITY_CRM_EXT_DB_VERSION', '1.0.0' ); } // extension tables = plugin 1.38.0 DDL, unchanged
if ( ! defined( 'BIZCITY_CRM_FILE' ) )           { define( 'BIZCITY_CRM_FILE', __FILE__ ); }
if ( ! defined( 'BIZCITY_CRM_DIR' ) )            { define( 'BIZCITY_CRM_DIR', __DIR__ ); }
if ( ! defined( 'BIZCITY_CRM_URL' ) )            { define( 'BIZCITY_CRM_URL', plugins_url( '', __FILE__ ) ); }
if ( ! defined( 'BIZCITY_CRM_REST_NS' ) )        { define( 'BIZCITY_CRM_REST_NS', 'bizcity-crm/v1' ); }

/** The only condition: Zalo Brain (bizcity-twin-ai) has loaded the CRM spine on this request. No multisite needed. */
function bizcity_crm_spine_ready(): bool {
	return defined( 'BIZCITY_CRM_SPINE_LOADED' ) && class_exists( 'BizCity_CRM_Spine', false );
}

add_action( 'admin_notices', static function () {
	if ( ! current_user_can( 'activate_plugins' ) || defined( 'BIZCITY_TWIN_AI_VERSION' ) || defined( 'BIZCITY_CRM_SPINE_LOADED' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>Zalo Brain CRM</strong> cần plugin <strong>Zalo Brain</strong> (bizcity-twin-ai) đang hoạt động. Cách sửa: cài và kích hoạt Zalo Brain trước, rồi tải lại trang này.</p></div>';
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
	if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) { // WP < 6.5 does not enforce "Requires Plugins"
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			'Zalo Brain CRM cần plugin Zalo Brain (bizcity-twin-ai) được kích hoạt trước. Cách sửa: kích hoạt Zalo Brain rồi kích hoạt lại plugin này.',
			'Zalo Brain CRM',
			array( 'back_link' => true )
		);
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
	flush_rewrite_rules( false );
} );

register_deactivation_hook( __FILE__, static function () {
	flush_rewrite_rules( false );
} );

/* ------------------------------------------------------------------
 * Public slug `/crm/` — direct CRM SPA mount (one TwinShell iframe, no nested wp-admin).
 * ------------------------------------------------------------------ */
add_action( 'init', static function () {
	add_rewrite_rule( '^crm/?$', 'index.php?bizcity_agent_page=crm', 'top' );
	add_rewrite_tag( '%bizcity_agent_page%', '([^&]+)' );
}, 11 );

add_action( 'template_redirect', static function () {
	if ( get_query_var( 'bizcity_agent_page' ) !== 'crm' ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( home_url( '/crm/' ) ) );
		exit;
	}
	add_filter( 'show_admin_bar', '__return_false', 100 );
	// [2026-09-19 Johnny Chu] PHASE-0.60 C2/C5 — public /crm/ uses the same action gate as wp-admin and TwinShell.
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
<title><?php esc_html_e( 'Zalo Brain CRM', 'bizcity-twin-crm' ); ?></title>
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
}
?>
</body>
</html><?php
	exit;
} );
