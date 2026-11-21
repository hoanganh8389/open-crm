<?php
/**
 * Open CRM — marketing channels: Contact Form 7 lead capture + ZNS automation.
 *
 * [2026-10-20 09:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 G-C (ZBC-12, owner: "marketing, CRM… đưa sang bizcity-twin-crm; zalo-brain plugin ko có UI UX của CRM") — moved from
 * bizcity-twin-ai/core/channel-gateway/includes/{cf7,zns} (+ class-lead-source-tracker.php) with the same class names, REST namespace
 * `bizcity-channel/v1`, option names and table names (`bizcity_cf7_submissions`, `bizcity_zns_event_rules`), so every caller keeps working:
 * the twin-crm SPA, the Automation `bizcity_cf7_submitted` trigger, `class_exists` consumers.
 *
 * Mixed-deploy safety: most of these classes have no redeclare guard. If an OLD core already loaded the CF7 / ZNS block (class exists),
 * the matching block here is skipped entirely — never declare a class twice (that is a fatal).
 *
 * Without this plugin: CF7 lead capture, the CF7 ZNS reply, ZNS rules and the CF7 Automation trigger are off (the brain axis does not need them).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

( static function () {
	$base = __DIR__ . '/';

	// ── Contact Form 7 lead-capture channel ────────────────────────────────────
	if ( ! class_exists( 'BizCity_CF7_Installer', false ) ) {
		$cf7 = $base . 'cf7/';
		require_once $cf7 . 'class-cf7-installer.php';
		require_once $cf7 . 'class-cf7-submissions-log.php';
		require_once $cf7 . 'class-cf7-crm-sync.php';
		require_once $cf7 . 'class-cf7-biglead.php';
		require_once $cf7 . 'class-cf7-channel-listener.php';
		require_once $cf7 . 'class-cf7-rest.php';
		require_once $cf7 . 'class-cf7-response-config.php';
		require_once $cf7 . 'class-cf7-zns-config.php';
		require_once $cf7 . 'class-cf7-zns-sender.php';
		require_once $cf7 . 'class-cf7-zns-templates.php';
		require_once $cf7 . 'class-cf7-zns-templates-rest.php';
		BizCity_CF7_ZNS_Templates_REST::init();
		require_once $cf7 . 'class-cf7-tracking-frontend.php';
		BizCity_CF7_Tracking_Frontend::init();
		// Lead source tracker (UTM / referrer / device) — only user is the CF7 listener.
		if ( ! class_exists( 'BizCity_Lead_Source_Tracker', false ) ) {
			require_once $base . 'class-lead-source-tracker.php';
		}
		BizCity_Lead_Source_Tracker::init();
		BizCity_CF7_Installer::maybe_install(); // idempotent: skips when the version option matches
		if ( class_exists( 'BizCity_Schema_Registry' ) ) {
			BizCity_Schema_Registry::register(
				'bizcity_cf7_submissions',
				'modules.cf7-channel',
				BizCity_CF7_Installer::SCHEMA_VERSION,
				BizCity_CF7_Installer::VERSION_OPTION,
				array( 'BizCity_CF7_Installer', 'install' )
			);
		}
		// CF7 registers at plugins_loaded:10 — hook the listeners at :20 (this file runs at :6).
		add_action( 'plugins_loaded', static function () {
			if ( class_exists( 'BizCity_CF7_BigLead' ) ) {
				BizCity_CF7_BigLead::init();
			}
			if ( class_exists( 'BizCity_CF7_Channel_Listener' ) ) {
				BizCity_CF7_Channel_Listener::init(); // wpcf7_mail_sent can fire lazily: register unconditionally once the class is loaded
			}
			if ( class_exists( 'BizCity_CF7_Response_Config' ) ) {
				BizCity_CF7_Response_Config::init();
			}
		}, 20 );
		BizCity_CF7_REST::init();
	}

	// ── ZNS automation hub (event-driven rules) ────────────────────────────────
	if ( ! class_exists( 'BizCity_ZNS_Dispatcher', false ) ) {
		$zns = $base . 'zns/';
		require_once $zns . 'class-zns-event-registry.php';
		require_once $zns . 'class-zns-rules-repo.php';
		require_once $zns . 'class-zns-general-sender.php';
		require_once $zns . 'class-zns-send-tracker.php';
		require_once $zns . 'class-zns-dispatcher.php';
		// REST class only in REST / admin context (R-PERF). `$_bizcity_admin_ctx` is set by Zalo Brain; absent ⇒ load (fail open for a missing flag).
		$admin_ctx = isset( $GLOBALS['_bizcity_admin_ctx'] ) ? (bool) $GLOBALS['_bizcity_admin_ctx'] : true;
		if ( $admin_ctx ) {
			require_once $zns . 'class-zns-automation-rest.php';
			add_action( 'rest_api_init', array( 'BizCity_ZNS_Automation_REST', 'register_routes' ) );
		}
		// Dispatcher hooks register at init:20 (after WooCommerce init:10). This plugin boots on every request, so Woo front-end hooks are covered
		// (the old core-side lazy listener is gone).
		add_action( 'init', static function () {
			BizCity_ZNS_Dispatcher::init();
		}, 20 );
	}
} )();
