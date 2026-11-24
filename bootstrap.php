<?php
/**
 * Open CRM — Bootstrap (extension plugin on the core/crm spine).
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-3.2 — everything the spine owns (ledger, scope, ingest,
 * outbound, magic links, spine REST, spine installer) now loads from bizcity-twin-ai/core/crm BEFORE this file runs
 * (plugins_loaded@6). This bootstrap only adds what Open CRM sells on top: pipeline, tasks/handoff, SLA, reports,
 * submissions, calendar adapter, CSKH tự động (Woo recap/CSAT/shipping/loyalty), campaigns/broadcast, B2B, invoices,
 * email automation, print-ads, service dispatch, AI usage, staff admin, the React SPA and the admin menu.
 * Dropped here on the owner's decision 2026-10-09: the PHP AI replier (cell answers now), the CRM rule engine (all
 * automation goes through bizcity-automation) and the identity-conflict queue (identity has one source of truth: cell).
 *
 * Never `require` a core file by path from here (D96-4): core classes are either already loaded or absent, and absent
 * means this plugin does not boot (see bizcity-twin-crm.php).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Plugin', false ) ) {
	return;
}

final class BizCity_CRM_Plugin {

	/** Features this plugin registers on the spine (doc 50 §4.1). */
	const FEATURES = array(
		'workspace', 'pipeline', 'tasks', 'sla', 'reports', 'campaigns', 'broadcast', 'invoices', 'b2b',
		'submissions', 'calendar', 'care', 'admin_ui', 'staff_admin', 'email_automation', 'print_ads', 'ai_usage', 'service',
	);

	/** @var self */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Tell the spine what this plugin brings, first — core gates on BizCity_CRM_Spine::has() from here on.
		if ( class_exists( 'BizCity_CRM_Spine' ) ) {
			$manifest = self::manifest();
			BizCity_CRM_Spine::register_extension( $manifest );
		}

		$this->includes();

		// Extension schema: append DDL to the spine's single dbDelta pass + own version stamp (doc 20 §5).
		BizCity_CRM_DB_Installer_Ext::register();
		add_action( 'admin_init', array( 'BizCity_CRM_DB_Installer_Ext', 'maybe_upgrade_ext' ), 2 );
		add_action( 'rest_api_init', array( 'BizCity_CRM_DB_Installer_Ext', 'maybe_upgrade_ext' ), 2 );

		// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — channel manifests (policy only).
		$this->register_built_in_manifests();

		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G6 — the one canonical Inbox renderer (the SPA).
		if ( ! defined( 'BIZCITY_CRM_SETTING_PANEL_REGISTERED' )
			&& class_exists( 'BizCity_Twin_Plugin_SDK' )
			&& class_exists( 'BizCity_Setting_Panel_Registry' ) ) {
			BizCity_Twin_Plugin_SDK::register_ui( array(
				'setting_panel' => array(
					array(
						'contract'        => 'setting-panel-registration',
						'version'         => '1.0.0',
						'id'              => 'bundle.twin-crm.inbox',
						'owner'           => 'plugins/bizcity-twin-crm',
						'origin'          => 'plugin',
						'destination'     => 'crm-inbox',
						'group'           => 'crm.inbox',
						'label_key'       => 'settings.crm_inbox.label',
						'description_key' => 'settings.crm_inbox.description',
						'icon'            => 'cil-inbox',
						'capability'      => class_exists( 'BizCity_CRM_Authority' ) ? BizCity_CRM_Authority::menu_cap( 'crm.inbox.open' ) : 'manage_options',
						'scope'           => 'site_user',
						'surface'         => 'admin_shell',
						'renderer'        => array(
							'type'           => 'deep_link',
							'id'             => 'bundle.twin-crm.inbox',
							'canonical_slug' => 'bizcity-crm',
						),
						'availability'    => array(
							'policy'         => 'registered-owner',
							'dependency_ids' => array( 'plugins.bizcity-twin-crm' ),
						),
						'position'        => 500,
						'aliases'         => array( 'bizcity-crm-inbox' ),
					),
				),
			) );
			define( 'BIZCITY_CRM_SETTING_PANEL_REGISTERED', true );
		}

		// v1.16.0 — built-in Customer Sources (Sales Pipeline auto-fill), priority 5 so third parties override at 10.
		add_filter( 'bizcity_crm_register_customer_sources', static function ( array $sources ): array {
			if ( ! isset( $sources['messenger'] ) && class_exists( 'BizCity_CRM_Source_Inbox_Messenger' ) ) { $sources['messenger'] = new BizCity_CRM_Source_Inbox_Messenger(); }
			if ( ! isset( $sources['dino_tichdiem'] ) && class_exists( 'BizCity_CRM_Source_Dino_Tichdiem' ) ) { $sources['dino_tichdiem'] = new BizCity_CRM_Source_Dino_Tichdiem(); }
			if ( ! isset( $sources['user_points'] ) && class_exists( 'BizCity_CRM_Source_User_Points' ) ) { $sources['user_points'] = new BizCity_CRM_Source_User_Points(); }
			return $sources;
		}, 5 );

		// Pipeline follows the ledger: every persisted message / saved contact re-syncs that contact's pipeline row.
		add_action( 'bizcity_crm_message_persisted', static function ( $ctx ) {
			if ( ! is_array( $ctx ) || ! class_exists( 'BizCity_CRM_Pipeline_Sync' ) ) { return; }
			$cid = isset( $ctx['contact_id'] ) ? (int) $ctx['contact_id'] : 0;
			if ( $cid > 0 ) {
				BizCity_CRM_Pipeline_Sync::sync_for_contact( $cid );
			}
		}, 20, 1 );
		add_action( 'bizcity_crm_contact_saved', static function ( $contact_id ) {
			if ( class_exists( 'BizCity_CRM_Pipeline_Sync' ) ) {
				BizCity_CRM_Pipeline_Sync::sync_for_contact( (int) $contact_id );
			}
		}, 20, 1 );
		// [2026-09-23] PHASE-0.71 F71-13 / 0.63C GC-9 — auto-link attachments to the one open pipeline run (D71-3).
		add_action( 'bizcity_crm_message_persisted', static function ( $ctx ) {
			if ( class_exists( 'BizCity_CRM_Pipeline_Document_Link' ) ) {
				BizCity_CRM_Pipeline_Document_Link::on_message_persisted( $ctx );
			}
		}, 20, 1 );

		// REST — extension routes only; the spine routes are registered by core at priority 9.
		// [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.2 — the moved spine routes, same priority as core's (no-op on an old core that still has them).
		add_action( 'rest_api_init', array( 'BizCity_CRM_Spine_REST_Ext', 'register_ext_routes' ), 9 );
		add_action( 'rest_api_init', array( 'BizCity_CRM_REST_Controller', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'BizCity_CRM_Staff_REST', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'BizCity_CRM_Leader_Member_REST', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'BizCity_CRM_Pipeline_REST', 'register_routes' ) );
		if ( class_exists( 'BizCity_CRM_Service_REST' ) ) {
			add_action( 'rest_api_init', array( 'BizCity_CRM_Service_REST', 'register_routes' ) );
		}
		if ( class_exists( 'BizCity_CRM_AI_Usage_REST' ) ) {
			add_action( 'rest_api_init', array( 'BizCity_CRM_AI_Usage_REST', 'register_routes' ) );
		}
		BizCity_CRM_Task_Handoff_Notify::register();
		BizCity_CRM_Task_Overdue_Notify::register();
		BizCity_CRM_Attribution_Backfill::register();

		// Admin menu "Open CRM" + SPA enqueue.
		if ( is_admin() ) {
			BizCity_CRM_Admin_Menu::instance();
			// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-13 B-6 — dev-only page (D-35), absent in production.
			if ( class_exists( 'BizCity_CRM_Sprint_Diagnostic', false ) ) {
				BizCity_CRM_Sprint_Diagnostic::instance();
			}
		}
	}

	/** zalo-brain.json (doc 50 §4.1) decoded, with a safe fallback when the file is missing. */
	public static function manifest(): array {
		$file = BIZCITY_CRM_DIR . '/zalo-brain.json';
		$data = null;
		if ( is_readable( $file ) ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
		}
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return array_merge(
			array(
				'contract' => 'zalo-brain-extension@1',
				'id'       => 'bizcity-twin-crm',
				// [2026-11-21 04:08 PM Johnny Chu - Chu Hoàng Anh] BRAND-OPEN-CRM — brand name Zalo Brain CRM -> Open CRM, repo open-crm (slug bizcity-twin-crm, prefix and owner unchanged).
				'name'     => 'Open CRM',
				'version'  => BIZCITY_CRM_VERSION,
				'features' => self::FEATURES,
			),
			$data
		);
	}

	private function includes(): void {
		$inc = BIZCITY_CRM_DIR . '/includes/';

		// Schema + data (extension halves of the spine classes).
		require_once $inc . 'class-db-installer-ext.php';
		require_once $inc . 'class-repository-ext.php';
		// [2026-11-23 09:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-005 OC-UI — REST chỉ đọc cho giao diện mới (bảng Danh bạ). Bọc is_readable (N7: file mới chưa upload không được làm sập site).
		if ( is_readable( $inc . 'rest/class-oc-rest.php' ) ) {
			require_once $inc . 'rest/class-oc-rest.php';
		}
		// [2026-11-24 Johnny Chu - Chu Hoàng Anh] PHASE-011 OC-011.1 — danh sách kết nối (connectors/*/connector.json) + kho khoá mã hoá. Bọc is_readable (N7).
		// [2026-11-24 Johnny Chu - Chu Hoàng Anh] OC-013.1 — danh sách ứng dụng (plugin có module.json schema ocrm-addon@1), chỉ đọc.
		if ( is_readable( $inc . 'rest/class-oc-apps-rest.php' ) ) {
			require_once $inc . 'rest/class-oc-apps-rest.php';
		}
		// [2026-11-24 Johnny Chu - Chu Hoàng Anh] OC-011.3 — Event Gateway tối thiểu (bảng bizcity_ocrm_events), nạp trước connectors vì webhook ghi qua nó.
		if ( is_readable( $inc . 'kernel/class-ocrm-events.php' ) ) {
			require_once $inc . 'kernel/class-ocrm-events.php';
		}
		if ( is_readable( $inc . 'connectors/class-oc-connectors.php' ) ) {
			require_once $inc . 'connectors/class-oc-connectors.php';
		}

		// [2026-10-17 10:59 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.116 S116-X4 (C1, doc 97) — CRM files moved out of bizcity-twin-ai/core/crm (no core caller). Same load + registration as core did;
		// the Channel Gateway keeps working without this plugin (doc 97 §4). Guarded: a partial deploy must not white-screen.
		// Mixed deploy safety: an OLD core still requires its own copies — never declare a class / function twice (that is a fatal).
		$moved_files = array(
			'class-conversation-identity-resolver.php' => 'BizCity_CRM_Conversation_Identity_Resolver',
			'class-contact-transfer.php'               => 'BizCity_CRM_Contact_Transfer',
			'class-personal-quota-rest.php'            => 'BizCity_CRM_Personal_Quota_REST',
			'bridges/class-google-tool-bridge.php'     => 'BizCity_CRM_Bridge_Google',
			'class-inbox-to-crm-bridge.php'            => 'BizCity_CRM_Inbox_To_CRM_Bridge',
			'class-crm-ingest-notif.php'               => 'BizCity_CRM_Ingest_Notif',
			'class-guru-resolver.php'                  => 'BizCity_CRM_Guru_Resolver',
			'class-guru-roles-admin.php'               => 'BizCity_CRM_Guru_Roles_Admin',
			'class-crm-order-identity-metabox.php'     => 'BizCity_CRM_Order_Identity_Metabox',
			// [2026-10-20 10:24 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 G-C — admin-chat grants moved from core/crm (class_exists guard = old-core safe).
			'admin-chat/class-admin-chat-grants.php'   => 'BizCity_CRM_Admin_Chat_Grants',
			'admin-chat/class-admin-chat-policy.php'   => 'BizCity_CRM_Admin_Chat_Policy',
			'admin-chat/class-admin-chat-confirm.php'  => 'BizCity_CRM_Admin_Chat_Confirm',
			'admin-chat/class-admin-chat-grants-rest.php' => 'BizCity_CRM_Admin_Chat_Grants_REST',
			// [2026-10-24 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-C2 — moved from core/crm; an old core that still ships them wins (class_exists, false).
			'class-email-send-log.php'                 => 'BizCity_CRM_Email_Send_Log',
			'class-crm-lead-score.php'                 => 'BizCity_CRM_Lead_Score',
			// [2026-11-19 10:25 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.1 — contact signals moved from core/crm (every core reader is class_exists-guarded).
			'class-contact-signals.php'                => 'BizCity_CRM_Contact_Signals',
			// [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 D156-5 — Team_Manager moved with its tables and REST routes; core Staff_Policy reads it behind class_exists.
			'class-team-manager.php'                   => 'BizCity_CRM_Team_Manager',
		);
		if ( is_admin() ) {
			$moved_files['admin-chat/class-admin-chat-grants-admin.php'] = 'BizCity_CRM_Admin_Chat_Grants_Admin';
		}
		foreach ( $moved_files as $moved => $moved_class ) {
			if ( ! class_exists( $moved_class, false ) && is_readable( $inc . $moved ) ) {
				require_once $inc . $moved;
			}
		}
		if ( ! function_exists( 'bizcity_crm_issue_magic_link' ) && is_readable( $inc . 'admin-chat/functions.php' ) ) {
			require_once $inc . 'admin-chat/functions.php';
		}
		// [2026-11-19 10:25 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.1 — the CRM admin surface descriptors moved from core/crm (only this plugin reads them; an old core that still ships them wins).
		if ( ! function_exists( 'bizcity_crm_surface_descriptors' ) && is_readable( $inc . 'contracts/crm-surfaces.php' ) ) {
			require_once $inc . 'contracts/crm-surfaces.php';
		}
		unset( $moved_files, $moved, $moved_class );
		// [2026-11-21 12:43 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 W5 (B) — Mabel Wheel lead intake moved from core (adapter from core/crm, listener from core/channel-gateway).
		// The adapter extends core's BizCity_CRM_Adapter_Base, so it loads only when the spine is there; an old core that still ships both wins (class_exists, false).
		if ( class_exists( 'BizCity_CRM_Adapter_Base' ) && ! class_exists( 'BizCity_CRM_Adapter_Mabel_Wheel', false ) && is_readable( $inc . 'inbox/channels/class-adapter-mabel-wheel.php' ) ) {
			require_once $inc . 'inbox/channels/class-adapter-mabel-wheel.php';
		}
		if ( class_exists( 'BizCity_CRM_Adapter_Mabel_Wheel', false ) ) {
			add_filter( 'bizcity_crm_register_adapters', static function ( $adapters ) {
				if ( is_array( $adapters ) && ! isset( $adapters['mabel_wheel'] ) ) {
					$adapters['mabel_wheel'] = new BizCity_CRM_Adapter_Mabel_Wheel();
				}
				return $adapters;
			}, 10 );
		}
		if ( ! class_exists( 'BizCity_Mabel_Wheel_Channel_Listener', false ) && is_readable( $inc . 'inbox/channels/class-mabel-wheel-channel-listener.php' ) ) {
			require_once $inc . 'inbox/channels/class-mabel-wheel-channel-listener.php';
			BizCity_Mabel_Wheel_Channel_Listener::init(); // an old core that still ships the listener already ran init()
		}
		if ( class_exists( 'BizCity_CRM_Inbox_To_CRM_Bridge' ) ) {
			BizCity_CRM_Inbox_To_CRM_Bridge::register();
		}
		if ( class_exists( 'BizCity_CRM_Personal_Quota_REST' ) ) {
			add_action( 'rest_api_init', array( 'BizCity_CRM_Personal_Quota_REST', 'register_routes' ) );
		}
		if ( class_exists( 'BizCity_CRM_Guru_Roles_Admin' ) && method_exists( 'BizCity_CRM_Guru_Roles_Admin', 'register' ) ) {
			BizCity_CRM_Guru_Roles_Admin::register();
		}
		if ( class_exists( 'BizCity_CRM_Admin_Chat_Grants' ) ) {
			BizCity_CRM_Admin_Chat_Grants::register();
		}
		if ( class_exists( 'BizCity_CRM_Admin_Chat_Grants_REST' ) ) {
			BizCity_CRM_Admin_Chat_Grants_REST::register();
		}
		if ( is_admin() && class_exists( 'BizCity_CRM_Admin_Chat_Grants_Admin' ) ) {
			BizCity_CRM_Admin_Chat_Grants_Admin::register();
		}

		// [2026-10-20 09:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.126 G-C — CF7 lead capture + ZNS automation moved here from the Channel Gateway (self-guarded for a mixed deploy).
		if ( is_readable( $inc . 'marketing/bootstrap.php' ) ) {
			require_once $inc . 'marketing/bootstrap.php';
		}

		// [2026-09-21 PHASE-0.63A S-4] The pipeline platform has exactly one entry point. Lanes add files
		// under includes/pipeline/ or includes/context/ and they load themselves — this line never changes again.
		require_once $inc . 'pipeline/bootstrap-pipeline.php';
		// [2026-09-23 PHASE-0.69] Service dispatch — loaded through BizCity_Safe_Loader (a partial deploy must not white-screen).
		if ( class_exists( 'BizCity_Safe_Loader' ) ) {
			BizCity_Safe_Loader::require_file( $inc . 'service/class-crm-location-service.php', 'crm.service.location_service' );
			BizCity_Safe_Loader::require_file( $inc . 'service/class-crm-staff-profile.php', 'crm.service.staff_profile' );
			BizCity_Safe_Loader::require_file( $inc . 'service/class-crm-service-matcher.php', 'crm.service.service_matcher' );
			BizCity_Safe_Loader::require_file( $inc . 'service/class-service-rest.php', 'crm.service.service_rest' );
			BizCity_Safe_Loader::require_file( $inc . 'service/class-location-link-handler.php', 'crm.service.location_link_handler' );
			BizCity_Safe_Loader::require_file( $inc . 'service/class-service-sla-listener.php', 'crm.service.service_sla_listener' );
			// [2026-09-30] PHASE-0.85 §K1–K3 — "Chi phí AI" report.
			BizCity_Safe_Loader::require_file( $inc . 'ai-usage/class-ai-usage-scope.php', 'crm.ai_usage.scope' );
			BizCity_Safe_Loader::require_file( $inc . 'ai-usage/class-ai-usage-thread-map.php', 'crm.ai_usage.thread_map' );
			BizCity_Safe_Loader::require_file( $inc . 'ai-usage/class-ai-usage-service.php', 'crm.ai_usage.service' );
			BizCity_Safe_Loader::require_file( $inc . 'ai-usage/class-ai-usage-alerts.php', 'crm.ai_usage.alerts' );
			BizCity_Safe_Loader::require_file( $inc . 'ai-usage/class-ai-usage-rest.php', 'crm.ai_usage.rest' );
		}
		if ( class_exists( 'BizCity_CRM_Location_Service' ) ) {
			BizCity_CRM_Location_Service::register();
		}
		if ( class_exists( 'BizCity_CRM_Location_Link_Handler' ) ) {
			BizCity_CRM_Location_Link_Handler::register();
		}
		if ( class_exists( 'BizCity_CRM_Service_SLA_Listener' ) ) {
			BizCity_CRM_Service_SLA_Listener::register();
		}

		// Teams ops: fair assignment, kanban, insights, export.
		require_once $inc . 'class-assignment-manager.php';
		if ( class_exists( 'BizCity_CRM_Assignment_Manager' ) && method_exists( 'BizCity_CRM_Assignment_Manager', 'register' ) ) {
			BizCity_CRM_Assignment_Manager::register();
		}
		require_once $inc . 'class-kanban-manager.php';
		require_once $inc . 'class-team-insights.php';
		require_once $inc . 'class-crm-export.php';
		$reconciliation_preview = $inc . 'admin/class-conversation-reconciliation-preview.php';
		if ( is_file( $reconciliation_preview ) && is_readable( $reconciliation_preview ) && class_exists( 'BizCity_Safe_Loader' ) ) {
			BizCity_Safe_Loader::require_file( $reconciliation_preview, 'crm.conversation_reconciliation_preview' );
		}
		unset( $reconciliation_preview );
		// [2026-09-23 04:25 PM Claude Fable 5.1] PHASE-0.60B — birthday reminder adapter (needs the Scheduler base class).
		if ( class_exists( 'BizCity_Scheduler_Adapter_Base' ) ) {
			require_once $inc . 'class-scheduler-adapter-contact-birthday.php';
		}

		// Audit log (v1.17.0).
		require_once $inc . 'audit/class-audit-log.php';
		require_once $inc . 'audit/class-audit-repository.php';
		require_once $inc . 'audit/class-admin-chat-audit.php';

		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-2 / CL-12 — sales/orders/stock (Woo) + customers (CRM) projection packs.
		// [2026-10-09 10:49 PM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 W20-L4 — the kinds are registered by core now
		// (core/mcp/verticals/woo + core/crm customers pack); this class only decorates customers rows with the pipeline stage.
		if ( class_exists( 'BizCity_Safe_Loader' ) ) {
			BizCity_Safe_Loader::require_file( $inc . 'woo/class-business-pack-exporter.php', 'crm.business_pack_exporter' );
		}
		if ( class_exists( 'BizCity_CRM_Business_Pack_Exporter' ) ) {
			BizCity_CRM_Business_Pack_Exporter::register();
		}

		// Customer sources + pipeline sync (consumers of the spine ingest hooks).
		require_once $inc . 'inbox/sources/interface-customer-source.php';
		require_once $inc . 'inbox/sources/class-customer-source-registry.php';
		require_once $inc . 'inbox/sources/class-source-inbox-messenger.php';
		require_once $inc . 'inbox/sources/class-source-dino-tichdiem.php';
		require_once $inc . 'inbox/sources/class-source-user-points.php';
		require_once $inc . 'inbox/class-pipeline-sync.php';
		$_crm_pipeline_doc_link_file = $inc . 'inbox/class-pipeline-document-link.php';
		if ( class_exists( 'BizCity_Safe_Loader' ) ) {
			BizCity_Safe_Loader::require_file( $_crm_pipeline_doc_link_file, 'crm.pipeline_document_link' );
		} elseif ( is_file( $_crm_pipeline_doc_link_file ) && is_readable( $_crm_pipeline_doc_link_file ) ) {
			require_once $_crm_pipeline_doc_link_file;
		}
		unset( $_crm_pipeline_doc_link_file );

		// REST (extension routes; the controller extends the spine REST class loaded by core).
		// [2026-11-20 08:06 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.156 S156-3.2 — the 29 spine routes only this plugin's screens call now live in
		// rest/class-crm-spine-rest-ext.php (BizCity_CRM_Spine_REST_Ext extends the core class; the controller extends it). Loaded first.
		require_once $inc . 'rest/class-crm-spine-rest-ext.php';
		require_once $inc . 'class-rest-controller.php';
		require_once $inc . 'class-staff-rest.php';
		require_once $inc . 'class-task-handoff.php';
		require_once $inc . 'class-customer-360-team-view.php';
		require_once $inc . 'class-leader-member-rest.php';
		require_once $inc . 'class-customer-pipeline.php';
		require_once $inc . 'class-pipeline-stage-service.php';
		require_once $inc . 'class-pipeline-rest.php';
		require_once $inc . 'class-task-handoff-notify.php';
		require_once $inc . 'class-task-overdue-notify.php';
		require_once $inc . 'class-attribution-backfill.php';
		require_once $inc . 'class-admin-menu.php';
		// [2026-09-27 Claude Opus 5.5] CORE-REDUCTION WP-13 B-6 — dev-only diagnostic pages (D-35), absent in production.
		foreach ( array( 'class-sprint-diagnostic.php', 'class-sprint-diagnostic-phase-cd.php' ) as $crm_diag_file ) {
			if ( is_file( $inc . $crm_diag_file ) && is_readable( $inc . $crm_diag_file ) && class_exists( 'BizCity_Safe_Loader', false ) ) {
				BizCity_Safe_Loader::require_file( $inc . $crm_diag_file, 'crm.diagnostics.' . basename( $crm_diag_file, '.php' ) );
			}
		}
		unset( $crm_diag_file );
		require_once $inc . 'class-scheduler-adapter.php';
		require_once $inc . 'class-service-templates.php';
		require_once $inc . 'class-order-adapter.php';
		require_once $inc . 'class-fulfillment-adapter.php';

		// CSKH tự động — WooCommerce bridge orchestrator (only when Woo is active).
		if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
			require_once $inc . 'woo/class-woo-bridge.php';
			add_action( 'init', array( 'BizCity_CRM_Woo_Bridge', 'boot' ), 5 );
			require_once $inc . 'woo/class-woo-order-recap-notifier.php';
			add_action( 'init', array( 'BizCity_CRM_Woo_Order_Recap_Notifier', 'boot' ), 10 );
			require_once $inc . 'woo/class-woo-order-recap-rest.php';
			add_action( 'rest_api_init', array( 'BizCity_CRM_Order_Recap_REST', 'register_routes' ) );
			require_once $inc . 'woo/class-order-public-token.php';
			require_once $inc . 'woo/class-order-public-controller.php';
			add_action( 'init', array( 'BizCity_CRM_Order_Public_Controller', 'boot' ), 11 );
			require_once $inc . 'woo/class-shipping-tracker.php';
			if ( class_exists( 'BizCity_CRM_Shipping_Tracker' ) ) {
				add_action( 'init', array( 'BizCity_CRM_Shipping_Tracker', 'boot' ), 5 );
			}
			require_once $inc . 'woo/class-woo-loyalty-bridge.php';
			add_action( 'init', array( 'BizCity_CRM_Woo_Loyalty_Bridge', 'boot' ), 15 );
		}
		// Legacy biz_contacts → contacts backfill helper (admin-run, not Woo-gated).
		require_once $inc . 'woo/migrations/migrate-biz-contacts-to-contacts.php';

		// Custom attributes + macro renderer (the renderer resolves {{kg:…}} through the notebook query helper).
		require_once $inc . 'kg/class-nb-query-kg.php';
		require_once $inc . 'attributes/class-custom-attr-validator.php';
		require_once $inc . 'macros/class-template-renderer.php';

		// SLA.
		require_once $inc . 'sla/class-working-hours.php';
		require_once $inc . 'sla/class-sla-evaluator.php';

		// Reports.
		require_once $inc . 'reports/class-report-builder.php';
		require_once $inc . 'reports/class-daily-rollup.php';
		require_once $inc . 'reports/class-csat-survey.php';
		require_once $inc . 'reports/class-reporting-rollup.php';
		BizCity_CRM_Reporting_Rollup::register();
		require_once $inc . 'class-staff-metrics.php';
		BizCity_CRM_Staff_Metrics::register();

		// Invoicing.
		require_once $inc . 'invoicing/class-invoice-repository.php';
		require_once $inc . 'invoicing/class-invoice-pdf.php';
		require_once $inc . 'invoicing/class-invoice-cron.php';

		// Email client (accounts repo) + email automation rules/dispatcher (SMTP repo + send log live in core).
		require_once $inc . 'email/class-email-repository.php';
		require_once $inc . 'email/class-email-poller.php';
		require_once $inc . 'email-automation/class-email-event-registry.php';
		require_once $inc . 'email-automation/class-email-rules-repo.php';
		require_once $inc . 'email-automation/class-email-dispatcher.php';
		BizCity_CRM_Email_Dispatcher::register();

		// Lead capture (CF7 / comment / generic → crm_leads).
		require_once $inc . 'lead-capture/class-lead-classifier.php';
		require_once $inc . 'lead-capture/class-lead-capture-engine.php';
		require_once $inc . 'lead-capture/class-lead-source-cf7.php';
		require_once $inc . 'lead-capture/class-lead-source-comment.php';
		require_once $inc . 'lead-capture/class-lead-source-generic.php';
		BizCity_CRM_Lead_Source_CF7::register();
		BizCity_CRM_Lead_Source_Comment::register();
		BizCity_CRM_Lead_Source_Generic::register();

		// Campaigns, QR, loyalty, broadcast.
		require_once $inc . 'campaigns/class-campaign-repository.php';
		require_once $inc . 'campaigns/class-qr-link-repository.php';
		require_once $inc . 'campaigns/class-campaign-ref-codec.php';
		require_once $inc . 'campaigns/class-qr-generator.php';
		require_once $inc . 'campaigns/class-campaign-tracker.php';
		require_once $inc . 'campaigns/class-conversion-linker.php';
		require_once $inc . 'campaigns/class-loyalty-shortcodes.php';
		require_once $inc . 'campaigns/class-loyalty-bridge.php';
		require_once $inc . 'campaigns/class-user-points-contact-bridge.php';
		require_once $inc . 'campaigns/class-flow-importer.php';
		require_once $inc . 'campaigns/class-conversion-bridge.php';
		require_once $inc . 'campaigns/class-campaign-scenario-dispatcher.php';
		require_once $inc . 'campaigns/class-broadcast-dispatcher.php';
		BizCity_CRM_Broadcast_Dispatcher::init();
		// [2026-10-11 10:19 AM Johnny Chu - Chu Hoàng Anh] CORE-REDUCTION WP-20 Z-7d — the ZNS/email mass-send engine (formerly core/channel-gateway/includes/broadcast/) lives here now
		// (D-W20-1, Q-W16-4). Same classes, REST bizcity-channel/v1/broadcasts*, cron bizcity_cg_broadcast_tick, tables
		// bizcity_cg_broadcasts + _recipients (DDL through BizCity_CRM_DB_Installer_Ext). CRM Broadcast proxies to it.
		require_once $inc . 'broadcast/class-broadcast-installer.php';
		require_once $inc . 'broadcast/class-broadcast-manager.php';
		require_once $inc . 'broadcast/class-broadcast-dispatcher.php';
		require_once $inc . 'broadcast/class-broadcast-rest.php';
		add_action( 'admin_init', array( 'BizCity_Broadcast_Installer', 'maybe_upgrade' ), 3 );
		BizCity_Broadcast_Dispatcher::init_cron();
		BizCity_Broadcast_REST::init();
		// [2026-10-28 07:12 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.146 S146-L3/L4/L6 — "Lịch thông báo": Inbox selection ⇒ one row per Zalo number,
		// the cell keeps the clock and runs the sends (doc 21). REST bizcity-crm/v1/announce/*, push retry cron bizcity_crm_announce_push_retry.
		require_once $inc . 'announce/class-announce-store.php';
		require_once $inc . 'announce/class-announce-cell.php';
		require_once $inc . 'announce/class-announce-rest.php';
		BizCity_CRM_Announce_Cell::init();
		add_action( 'rest_api_init', array( 'BizCity_CRM_Announce_REST', 'register_routes' ) );

		// Print-ads template library.
		require_once $inc . 'print-ads/class-print-templates-installer.php';
		require_once $inc . 'print-ads/seed-print-templates.php';
		if ( is_admin() ) {
			require_once $inc . 'print-ads/class-print-templates-admin.php';
			BizCity_CRM_Print_Templates_Admin::register();
			add_action( 'admin_init', array( 'BizCity_CRM_Print_Templates_Installer', 'maybe_upgrade' ), 20 );
		}
		require_once $inc . 'print-ads/class-print-ads-composer.php';
		require_once $inc . 'print-ads/class-print-ads-rest.php';
		BizCity_CRM_Print_Ads_REST::register();

		// Marketing asset studio.
		require_once $inc . 'marketing/class-brand-kit.php';
		require_once $inc . 'marketing/class-asset-renderer.php';
		require_once $inc . 'marketing/class-asset-cache.php';
		require_once $inc . 'marketing/class-asset-cache-invalidator.php';

		// Scheduler adapter (calendar: CRM events from conversations).
		BizCity_CRM_Scheduler_Adapter::register();

		// Guru roles admin profile hooks also run on REST (the admin screen itself is registered by core).
		if ( class_exists( 'BizCity_CRM_Guru_Roles_Admin' ) && method_exists( 'BizCity_CRM_Guru_Roles_Admin', 'register_profile_hooks' ) ) {
			BizCity_CRM_Guru_Roles_Admin::register_profile_hooks();
		}

		// Cron-driven services.
		BizCity_CRM_SLA_Evaluator::register();
		BizCity_CRM_Daily_Rollup::register();
		BizCity_CRM_CSAT_Survey::register();
		add_filter( 'bizcity_intent_monitor_tabs', array( 'BizCity_CRM_CSAT_Survey', 'register_intent_monitor_tab' ), 10, 1 );
		BizCity_CRM_Invoice_Cron::register();

		// [2026-09-27 Claude Opus 5.5] PHASE-0.80 doc 26 OB-3 — team leaders also see the Zalo connection report (additive to the core gate).
		add_filter( 'bizcity_zalo_connection_can_view', static function ( $can ) {
			return (bool) $can || ( class_exists( 'BizCity_CRM_Capabilities' ) && current_user_can( BizCity_CRM_Capabilities::CAP_MANAGE_TEAMS ) );
		} );

		// IMAP poller retired 2026-05-31 — one-time cleanup of leftover schedules.
		add_action( 'init', static function () {
			if ( class_exists( 'BizCity_CRM_Email_Poller' ) && wp_next_scheduled( BizCity_CRM_Email_Poller::HOOK ) ) {
				wp_clear_scheduled_hook( BizCity_CRM_Email_Poller::HOOK );
			}
		}, 1 );

		// Campaign runtime.
		BizCity_CRM_Campaign_Tracker::register();
		BizCity_CRM_Campaign_Conversion_Linker::register();
		BizCity_CRM_Loyalty_Shortcodes::register();
		BizCity_CRM_Loyalty_Bridge::register();
		BizCity_CRM_UserPoints_Contact_Bridge::register();
		BizCity_CRM_Campaign_Conversion_Bridge::register();
		BizCity_CRM_Campaign_Scenario_Dispatcher::register();
		BizCity_CRM_Asset_Cache_Invalidator::bootstrap();

		// [2026-09-19] PHASE-0.57 — built-in training doc CPT + auto-seed into the KG notebook.
		require_once $inc . 'training/class-training-cpt.php';
		require_once $inc . 'training/class-training-seeder.php';
		BizCity_CRM_Training_CPT::register();
		BizCity_CRM_Training_Seeder::register();
	}

	private function register_built_in_manifests(): void {
		if ( ! class_exists( 'BizCity_Framework_SDK' ) ) {
			return;
		}
		$manifest_file = __DIR__ . '/manifests/builtin-channel-manifests.json';
		if ( ! is_file( $manifest_file ) || ! is_readable( $manifest_file ) ) {
			return;
		}
		try {
			$manifests = json_decode( (string) file_get_contents( $manifest_file ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( ! is_array( $manifests ) ) {
			return;
		}
		foreach ( $manifests as $manifest ) {
			if ( is_array( $manifest ) ) {
				BizCity_Framework_SDK::register( $manifest, $this );
			}
		}
	}
}
