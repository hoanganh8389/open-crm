<?php
/**
 * Zalo Brain CRM — extension DB installer: the tables only this plugin uses.
 *
 * [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 S96-3.2 — split from the former class-db-installer.php. Core/crm
 * (BizCity_CRM_DB_Installer_V2) owns the spine tables and the single dbDelta pass; this class appends its CREATE
 * statements through `bizcity_crm_install_tables` and runs its migrations on `bizcity_crm_install_migrate`.
 * Table-name accessors stay on the core class (extends it) so every `BizCity_CRM_DB_Installer_V2::tbl_*()` call keeps
 * working. Own version option so the extension can upgrade without re-stamping the spine. Never drops a table (D96-6).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_DB_Installer_Ext' ) && class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) :

class BizCity_CRM_DB_Installer_Ext extends BizCity_CRM_DB_Installer_V2 {

	// [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 owner decisions: automation_rules (rule engine → bizcity-automation),
	// contact_id_map + identity_conflicts + identity_conflict_audit (identity = cell) are no longer created; existing rows stay
	// (quarantined in core/helper/class-bizcity-legacy-table-policy.php, never dropped).

	const EXT_DB_VERSION_OPTION = 'bizcity_crm_ext_db_ver';

	/** Hook the two spine extension points. Call once from the plugin bootstrap. */
	public static function register(): void {
		add_filter( 'bizcity_crm_install_tables', array( __CLASS__, 'append_tables' ), 10, 2 );
		add_action( 'bizcity_crm_install_migrate', array( __CLASS__, 'migrate' ) );
	}

	/** Extension tables (name => table) — used by self-heal and diagnostics. */
	public static function ext_tables(): array {
		return array(
			'custom_attribute_definitions' => self::tbl_custom_attribute_definitions(),
			'macros' => self::tbl_macros(),
			'working_hours' => self::tbl_working_hours(),
			'sla_policies' => self::tbl_sla_policies(),
			'applied_slas' => self::tbl_applied_slas(),
			'accounts' => self::tbl_accounts(),
			'crm_tasks' => self::tbl_crm_tasks(),
			'crm_documents' => self::tbl_crm_documents(),
			'crm_leads' => self::tbl_crm_leads(),
			'crm_opportunities' => self::tbl_crm_opportunities(),
			'crm_opportunity_lines' => self::tbl_crm_opportunity_lines(),
			'crm_contracts' => self::tbl_crm_contracts(),
			'crm_contract_lines' => self::tbl_crm_contract_lines(),
			'crm_product_categories' => self::tbl_crm_product_categories(),
			'crm_products' => self::tbl_crm_products(),
			'crm_invoices' => self::tbl_crm_invoices(),
			'crm_invoice_lines' => self::tbl_crm_invoice_lines(),
			'crm_invoice_payments' => self::tbl_crm_invoice_payments(),
			'crm_email_accounts' => self::tbl_crm_email_accounts(),
			'crm_email_threads' => self::tbl_crm_email_threads(),
			'crm_email_messages' => self::tbl_crm_email_messages(),
			'campaigns' => self::tbl_campaigns(),
			'campaign_visits' => self::tbl_campaign_visits(),
			'email_event_rules' => self::tbl_email_event_rules(),
			'crm_audit_log' => self::tbl_crm_audit_log(),
			'broadcasts'                   => self::tbl_broadcasts(),
			'broadcast_recipients'         => self::tbl_broadcast_recipients(),
			'order_recap_log'              => self::tbl_order_recap_log(),
			'order_csat'                   => self::tbl_order_csat(),
			'shipment_status_log'          => self::tbl_shipment_status_log(),
			'crm_notes_doc'                => self::tbl_notes_doc(),
			'crm_activities'               => self::tbl_crm_activities(),
			'crm_submissions'              => self::tbl_crm_submissions(),
			'reporting_events'             => self::tbl_reporting_events(),
			'reporting_rollups'            => self::tbl_reporting_rollups(),
			'crm_assignment_policies'      => self::tbl_assignment_policies(),
			'crm_inbox_assignment_policies'=> self::tbl_inbox_assignment_policies(),
			'crm_pipeline_deadlines'       => self::tbl_pipeline_deadlines(),
		);
	}

	/** Missing extension tables (self-heal). */
	public static function missing_ext_tables(): array {
		$missing = array();
		foreach ( self::ext_tables() as $key => $tbl ) {
			if ( ! self::table_exists( $tbl ) ) {
				$missing[ $key ] = $tbl;
			}
		}
		return $missing;
	}

	/** admin_init / rest_api_init: run the shared install() when this extension's stamp or tables are behind. */
	public static function maybe_upgrade_ext(): void {
		if ( get_option( self::EXT_DB_VERSION_OPTION ) === BIZCITY_CRM_EXT_DB_VERSION && empty( self::missing_ext_tables() ) ) {
			return;
		}
		self::register();
		parent::install();
		update_option( self::EXT_DB_VERSION_OPTION, BIZCITY_CRM_EXT_DB_VERSION );
	}

	/** Activation: same path. */
	public static function install(): void {
		self::register();
		parent::install();
		update_option( self::EXT_DB_VERSION_OPTION, BIZCITY_CRM_EXT_DB_VERSION );
	}

	/** Filter callback: append this plugin's CREATE TABLE statements to the spine pass. */
	public static function append_tables( array $sql, string $charset ): array {
		$cad = self::tbl_custom_attribute_definitions();
		$macros = self::tbl_macros();
		$wh = self::tbl_working_hours();
		$slap = self::tbl_sla_policies();
		$appl = self::tbl_applied_slas();
		$accounts = self::tbl_accounts();
		$crm_tasks = self::tbl_crm_tasks();
		$crm_docs = self::tbl_crm_documents();
		$crm_leads = self::tbl_crm_leads();
		$crm_opps = self::tbl_crm_opportunities();
		$crm_opp_lines = self::tbl_crm_opportunity_lines();
		$crm_contracts = self::tbl_crm_contracts();
		$crm_contract_lines = self::tbl_crm_contract_lines();
		$crm_product_categories = self::tbl_crm_product_categories();
		$crm_products = self::tbl_crm_products();
		$crm_invoices = self::tbl_crm_invoices();
		$crm_invoice_lines = self::tbl_crm_invoice_lines();
		$crm_invoice_payments = self::tbl_crm_invoice_payments();
		$crm_email_accounts = self::tbl_crm_email_accounts();
		$crm_email_threads = self::tbl_crm_email_threads();
		$crm_email_messages = self::tbl_crm_email_messages();
		$campaigns = self::tbl_campaigns();
		$campaign_visits = self::tbl_campaign_visits();
		$email_rules = self::tbl_email_event_rules();
		$audit_log = self::tbl_crm_audit_log();

		$sql[] = "CREATE TABLE `{$cad}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attribute_key VARCHAR(64) NOT NULL,
			display_name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			display_type VARCHAR(32) NOT NULL DEFAULT 'text',
			target VARCHAR(16) NOT NULL DEFAULT 'contact',
			regex_pattern VARCHAR(255) NULL,
			options_json LONGTEXT NULL,
			default_value TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_target_key (target, attribute_key)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$macros}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			visibility VARCHAR(16) NOT NULL DEFAULT 'global',
			owner_user_id BIGINT UNSIGNED NULL,
			template LONGTEXT NULL,
			actions_json LONGTEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			run_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_used_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_owner (owner_user_id),
			KEY idx_visibility_active (visibility, active)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$wh}` (
			inbox_id BIGINT UNSIGNED NOT NULL,
			day_of_week TINYINT NOT NULL,
			is_open TINYINT(1) NOT NULL DEFAULT 1,
			open_time TIME NOT NULL DEFAULT '09:00:00',
			close_time TIME NOT NULL DEFAULT '18:00:00',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (inbox_id, day_of_week)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$slap}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			frt_threshold_minutes INT UNSIGNED NULL,
			nrt_threshold_minutes INT UNSIGNED NULL,
			rt_threshold_minutes  INT UNSIGNED NULL,
			only_during_business_hours TINYINT(1) NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_active (active)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$appl}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL,
			sla_policy_id BIGINT UNSIGNED NOT NULL,
			applied_at BIGINT NOT NULL,
			frt_due_at BIGINT NULL,
			nrt_due_at BIGINT NULL,
			rt_due_at  BIGINT NULL,
			frt_breached_at BIGINT NULL,
			nrt_breached_at BIGINT NULL,
			rt_breached_at  BIGINT NULL,
			met_at BIGINT NULL,
			last_evaluated_at BIGINT NULL,
			state VARCHAR(16) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_conv (conversation_id),
			KEY idx_state_due (state, frt_due_at),
			KEY idx_policy (sla_policy_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$accounts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			industry VARCHAR(64) NULL,
			size VARCHAR(32) NULL,
			website VARCHAR(255) NULL,
			country VARCHAR(64) NULL,
			annual_revenue DECIMAL(18,2) NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT 'active',
			owner_id BIGINT UNSIGNED NULL,
			opportunities_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_owner (owner_id),
			KEY idx_status (status),
			KEY idx_industry (industry)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_tasks}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT 'open',
			priority VARCHAR(16) NOT NULL DEFAULT 'medium',
			due_date DATE NULL,
			assignee_id BIGINT UNSIGNED NULL,
			related_entity_type VARCHAR(32) NULL,
			related_entity_id BIGINT UNSIGNED NULL,
			notes LONGTEXT NULL,
			completed TINYINT(1) NOT NULL DEFAULT 0,
			completed_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_assignee_status (assignee_id, status),
			KEY idx_due_date (due_date),
			KEY idx_related (related_entity_type, related_entity_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_docs}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			type VARCHAR(64) NOT NULL DEFAULT 'file',
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			path VARCHAR(512) NOT NULL DEFAULT '',
			uploaded_by BIGINT UNSIGNED NULL,
			related_entity_type VARCHAR(32) NULL,
			related_entity_id BIGINT UNSIGNED NULL,
			message_id BIGINT UNSIGNED NULL,
			uploaded_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_related (related_entity_type, related_entity_id),
			KEY idx_uploader (uploaded_by),
			KEY idx_message (message_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_leads}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(120) NOT NULL DEFAULT '',
			last_name  VARCHAR(120) NOT NULL DEFAULT '',
			email VARCHAR(190) NULL,
			phone VARCHAR(64) NULL,
			company VARCHAR(190) NULL,
			title VARCHAR(120) NULL,
			source VARCHAR(64) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'new',
			rating VARCHAR(16) NULL,
			owner_id BIGINT UNSIGNED NULL,
			account_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			converted_at DATETIME NULL,
			converted_opportunity_id BIGINT UNSIGNED NULL,
			notes LONGTEXT NULL,
			custom_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_owner (owner_id),
			KEY idx_email (email),
			KEY idx_phone (phone),
			KEY idx_account (account_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_opps}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			account_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			lead_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NULL,
			stage VARCHAR(32) NOT NULL DEFAULT 'qualification',
			status VARCHAR(32) NOT NULL DEFAULT 'open',
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			probability TINYINT UNSIGNED NOT NULL DEFAULT 10,
			expected_revenue DECIMAL(18,2) NOT NULL DEFAULT 0,
			close_date DATE NULL,
			actual_close_date DATE NULL,
			lost_reason VARCHAR(190) NULL,
			next_step VARCHAR(255) NULL,
			description LONGTEXT NULL,
			custom_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_stage (stage),
			KEY idx_status (status),
			KEY idx_owner (owner_id),
			KEY idx_account (account_id),
			KEY idx_close (close_date)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_opp_lines}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			opportunity_id BIGINT UNSIGNED NOT NULL,
			product_code VARCHAR(64) NULL,
			description VARCHAR(255) NOT NULL DEFAULT '',
			quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_opp (opportunity_id, position)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_contracts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(64) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			account_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			opportunity_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'draft',
			start_date DATE NULL,
			end_date DATE NULL,
			signed_date DATE NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			terms LONGTEXT NULL,
			custom_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_code (code),
			KEY idx_status (status),
			KEY idx_account (account_id),
			KEY idx_opp (opportunity_id),
			KEY idx_dates (start_date, end_date)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_contract_lines}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NOT NULL,
			product_code VARCHAR(64) NULL,
			description VARCHAR(255) NOT NULL DEFAULT '',
			quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_contract (contract_id, position)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_product_categories}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			slug VARCHAR(190) NOT NULL DEFAULT '',
			parent_id BIGINT UNSIGNED NULL,
			description TEXT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_slug (slug),
			KEY idx_parent (parent_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_products}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			category_id BIGINT UNSIGNED NULL,
			sku VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			type VARCHAR(16) NOT NULL DEFAULT 'product',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			description TEXT NULL,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
			is_recurring TINYINT(1) NOT NULL DEFAULT 0,
			billing_period VARCHAR(16) NULL,
			stock_qty DECIMAL(14,3) NULL,
			custom_json LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_sku (sku),
			KEY idx_category (category_id),
			KEY idx_status (status),
			KEY idx_type (type),
			KEY idx_name (name)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_invoices}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			number VARCHAR(64) NOT NULL DEFAULT '',
			account_id BIGINT UNSIGNED NULL,
			contact_id BIGINT UNSIGNED NULL,
			opportunity_id BIGINT UNSIGNED NULL,
			contract_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			fx_rate DECIMAL(14,6) NOT NULL DEFAULT 1,
			subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			tax_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			total DECIMAL(18,2) NOT NULL DEFAULT 0,
			amount_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
			amount_due DECIMAL(18,2) NOT NULL DEFAULT 0,
			issue_date DATE NULL,
			due_date DATE NULL,
			sent_at DATETIME NULL,
			paid_at DATETIME NULL,
			voided_at DATETIME NULL,
			notes LONGTEXT NULL,
			billing_address LONGTEXT NULL,
			custom_json LONGTEXT NULL,
			wc_order_id BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_number (number),
			KEY idx_status_due (status, due_date),
			KEY idx_account (account_id),
			KEY idx_contact (contact_id),
			KEY idx_contract (contract_id),
			KEY idx_owner (owner_id),
			KEY idx_wc_order (wc_order_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_invoice_lines}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NULL,
			product_code VARCHAR(64) NULL,
			description VARCHAR(255) NOT NULL DEFAULT '',
			quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			discount_type VARCHAR(16) NOT NULL DEFAULT 'percentage',
			tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
			line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_invoice (invoice_id, position),
			KEY idx_product (product_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_invoice_payments}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'VND',
			method VARCHAR(32) NOT NULL DEFAULT 'manual',
			reference VARCHAR(190) NULL,
			paid_at DATETIME NOT NULL,
			notes TEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_invoice (invoice_id),
			KEY idx_paid_at (paid_at)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_email_accounts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			label VARCHAR(190) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			imap_host VARCHAR(190) NOT NULL DEFAULT '',
			imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
			imap_secure VARCHAR(8) NOT NULL DEFAULT 'ssl',
			imap_user VARCHAR(190) NOT NULL DEFAULT '',
			imap_pass_enc TEXT NULL,
			imap_folder VARCHAR(64) NOT NULL DEFAULT 'INBOX',
			smtp_use_global TINYINT(1) NOT NULL DEFAULT 1,
			smtp_host VARCHAR(190) NULL,
			smtp_port SMALLINT UNSIGNED NULL,
			smtp_secure VARCHAR(8) NULL,
			smtp_user VARCHAR(190) NULL,
			smtp_pass_enc TEXT NULL,
			last_uid_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_sync_at DATETIME NULL,
			last_sync_status VARCHAR(16) NULL,
			last_sync_error TEXT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_email (user_id, email),
			KEY idx_active (is_active)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_email_threads}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			normalized_subject VARCHAR(255) NOT NULL DEFAULT '',
			participants_json LONGTEXT NULL,
			message_count INT UNSIGNED NOT NULL DEFAULT 0,
			unread_count INT UNSIGNED NOT NULL DEFAULT 0,
			has_attachment TINYINT(1) NOT NULL DEFAULT 0,
			labels_json LONGTEXT NULL,
			last_message_at DATETIME NULL,
			related_entity_type VARCHAR(32) NULL,
			related_entity_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_account_last (account_id, last_message_at),
			KEY idx_subject (normalized_subject),
			KEY idx_related (related_entity_type, related_entity_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$crm_email_messages}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			thread_id BIGINT UNSIGNED NULL,
			direction VARCHAR(8) NOT NULL DEFAULT 'in',
			imap_uid BIGINT UNSIGNED NULL,
			message_id_header VARCHAR(190) NULL,
			in_reply_to VARCHAR(190) NULL,
			from_email VARCHAR(190) NULL,
			from_name VARCHAR(190) NULL,
			to_json LONGTEXT NULL,
			cc_json LONGTEXT NULL,
			bcc_json LONGTEXT NULL,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			body_html LONGTEXT NULL,
			body_text LONGTEXT NULL,
			attachments_json LONGTEXT NULL,
			is_seen TINYINT(1) NOT NULL DEFAULT 0,
			is_starred TINYINT(1) NOT NULL DEFAULT 0,
			received_at DATETIME NULL,
			sent_at DATETIME NULL,
			send_status VARCHAR(16) NULL,
			send_error TEXT NULL,
			raw_size INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_account_uid (account_id, imap_uid),
			KEY idx_thread (thread_id),
			KEY idx_message_id (message_id_header),
			KEY idx_received (received_at),
			KEY idx_direction (direction)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$campaigns}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(64) NOT NULL,
			name VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			landing_url TEXT NULL,
			utm_source VARCHAR(120) NULL,
			utm_medium VARCHAR(120) NULL,
			utm_campaign VARCHAR(120) NULL,
			utm_content VARCHAR(120) NULL,
			utm_term VARCHAR(120) NULL,
			loyalty_points_award INT NOT NULL DEFAULT 0,
			notebook_id BIGINT UNSIGNED NULL,
			welcome_template_id BIGINT UNSIGNED NULL,
			bound_character_id BIGINT UNSIGNED NULL,
			bound_notebook_id BIGINT UNSIGNED NULL,
			scenario_action_type VARCHAR(20) NOT NULL DEFAULT 'send_message',
			scenario_shortcode TEXT NULL,
			scenario_template TEXT NULL,
			scenario_attrs_json LONGTEXT NULL,
			scenario_prompt TEXT NULL,
			reminder_delay INT NOT NULL DEFAULT 0,
			reminder_unit VARCHAR(10) NOT NULL DEFAULT 'minutes',
			reminder_text TEXT NULL,
			reminder_only TINYINT(1) NOT NULL DEFAULT 0,
			imported_from_bizgpt_flow_id BIGINT UNSIGNED NULL,
			notes_json LONGTEXT NULL,
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			deleted_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_code (code),
			KEY idx_status (status),
			KEY idx_window (starts_at, ends_at),
			KEY idx_deleted (deleted_at),
			KEY idx_imported_flow (imported_from_bizgpt_flow_id),
			KEY idx_action_type (scenario_action_type)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$campaign_visits}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			client_id VARCHAR(190) NOT NULL,
			contact_id BIGINT UNSIGNED NULL,
			converted_contact_id BIGINT UNSIGNED NULL,
			referer TEXT NULL,
			user_agent VARCHAR(255) NULL,
			ip_hash CHAR(64) NULL,
			utm_source VARCHAR(120) NULL,
			utm_medium VARCHAR(120) NULL,
			utm_campaign VARCHAR(120) NULL,
			utm_content VARCHAR(120) NULL,
			utm_term VARCHAR(120) NULL,
			meta_json LONGTEXT NULL,
			converted_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_campaign_created (campaign_id, created_at),
			KEY idx_client (client_id),
			KEY idx_converted (converted_contact_id),
			KEY idx_created (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$email_rules}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			event_key VARCHAR(120) NOT NULL,
			account_id BIGINT UNSIGNED NULL,
			is_enabled TINYINT(1) NOT NULL DEFAULT 1,
			to_template TEXT NOT NULL,
			cc_template TEXT NULL,
			bcc_template TEXT NULL,
			subject_template VARCHAR(500) NOT NULL DEFAULT '',
			body_template LONGTEXT NULL,
			conditions_json LONGTEXT NULL,
			last_fired_at DATETIME NULL,
			last_fire_status VARCHAR(16) NULL,
			last_fire_error TEXT NULL,
			fire_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_event_enabled (event_key, is_enabled),
			KEY idx_account (account_id)
		) {$charset};";

		$sql[] = "CREATE TABLE `{$audit_log}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(64) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(32) NOT NULL DEFAULT 'updated',
			before_json LONGTEXT NULL,
			after_json LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			user_label VARCHAR(190) NULL,
			event_uuid VARCHAR(36) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_user (user_id),
			KEY idx_created_at (created_at),
			KEY idx_event_uuid (event_uuid)
		) {$charset};";

		return $sql;
	}

	/** Action callback: extension migrations, in the historical order. */
	public static function migrate(): void {
		self::migrate_phase_038();
		self::migrate_phase_039();
		self::migrate_phase_040();
		self::migrate_phase_041();
		self::migrate_phase_042();
		self::migrate_phase_043();
		self::migrate_phase_044();
		self::migrate_phase_045();
		self::migrate_phase_046();
		self::migrate_phase_047();
		self::migrate_phase_048();
		self::migrate_phase_049();
		self::migrate_phase_053();
		self::migrate_phase_063();
		self::migrate_phase_063c();
		self::migrate_phase_071();
		self::invalidate_tables_cache();
	}

	/**
	 * Idempotent migration for PHASE 0.35 M-CRM.M1.W2 (Product Catalog).
	 *
	 * - Adds `product_id` + `discount_type` columns to opportunity_lines and contract_lines.
	 * - New tables (crm_products, crm_product_categories) handled by dbDelta() in install().
	 *
	 * @return array<string,string>
	 */
	public static function migrate_phase_038(): array {
		global $wpdb;
		$results = array();

		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb, &$results ): void {
			$key = "col:{$table}.{$column}";
			if ( self::column_exists( $table, $column ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb, &$results ): void {
			$key = "idx:{$table}.{$index}";
			if ( self::index_exists( $table, $index ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$opp_lines = self::tbl_crm_opportunity_lines();
		$add_column( $opp_lines, 'product_id',    'BIGINT UNSIGNED NULL AFTER opportunity_id' );
		$add_column( $opp_lines, 'discount_type', "VARCHAR(16) NOT NULL DEFAULT 'percentage' AFTER discount_pct" );
		$add_index(  $opp_lines, 'idx_product',   '(product_id)' );

		$ct_lines = self::tbl_crm_contract_lines();
		$add_column( $ct_lines, 'product_id',    'BIGINT UNSIGNED NULL AFTER contract_id' );
		$add_column( $ct_lines, 'discount_type', "VARCHAR(16) NOT NULL DEFAULT 'percentage' AFTER discount_pct" );
		$add_index(  $ct_lines, 'idx_product',   '(product_id)' );

		return $results;
	}

	/**
	 * Idempotent migration for PHASE 0.35 M6.W10 — Campaign Scenario Builder.
	 *
	 * Adds 10 columns + 2 indexes to `bizcity_crm_campaigns` for the
	 * scenario-builder UX ported from `wp_bizgpt_custom_flows`. All ALTERs are
	 * guarded by SHOW COLUMNS / SHOW INDEX so re-runs are no-ops. Defaults are
	 * picked so existing rows behave as plain "send_message + no reminder" —
	 * no eager backfill needed.
	 *
	 * Spec: PHASE-0.35-CRM-CAMPAIGN.md §2 + §13.
	 *
	 * @return array<string,string> Map of operation => result.
	 */
	public static function migrate_phase_041(): array {
		global $wpdb;
		$results = array();
		$tbl     = self::tbl_campaigns();

		// Sanity — base table must exist (M6.W1 ran). Otherwise dbDelta in install()
		// will pick up the new cols on first run; this method is upgrade-only.
		if ( ! self::table_exists( $tbl ) ) {
			$results['precheck'] = 'skip:base_table_missing';
			return $results;
		}

		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb, &$results ): void {
			$key = "col:{$table}.{$column}";
			if ( self::column_exists( $table, $column ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb, &$results ): void {
			$key = "idx:{$table}.{$index}";
			if ( self::index_exists( $table, $index ) ) {
				$results[ $key ] = 'skip';
				return;
			}
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		// 10 scenario / reminder / import columns. Place AFTER bound_notebook_id so
		// SHOW CREATE TABLE reads naturally; MariaDB ignores AFTER if col exists.
		$add_column( $tbl, 'scenario_action_type',         "VARCHAR(20) NOT NULL DEFAULT 'send_message' AFTER bound_notebook_id" );
		$add_column( $tbl, 'scenario_shortcode',           'TEXT NULL AFTER scenario_action_type' );
		$add_column( $tbl, 'scenario_template',            'TEXT NULL AFTER scenario_shortcode' );
		$add_column( $tbl, 'scenario_attrs_json',          'LONGTEXT NULL AFTER scenario_template' );
		$add_column( $tbl, 'scenario_prompt',              'TEXT NULL AFTER scenario_attrs_json' );
		$add_column( $tbl, 'reminder_delay',               'INT NOT NULL DEFAULT 0 AFTER scenario_prompt' );
		$add_column( $tbl, 'reminder_unit',                "VARCHAR(10) NOT NULL DEFAULT 'minutes' AFTER reminder_delay" );
		$add_column( $tbl, 'reminder_text',                'TEXT NULL AFTER reminder_unit' );
		$add_column( $tbl, 'reminder_only',                'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_text' );
		$add_column( $tbl, 'imported_from_bizgpt_flow_id', 'BIGINT UNSIGNED NULL AFTER reminder_only' );

		$add_index( $tbl, 'idx_imported_flow', '(imported_from_bizgpt_flow_id)' );
		$add_index( $tbl, 'idx_action_type',   '(scenario_action_type)' );

		// M6.W13.5 — seed default automation rule that wires
		// crm_campaign_visit_recorded → dispatch_campaign_scenario. Idempotent.
		if ( class_exists( 'BizCity_CRM_Campaign_Scenario_Dispatcher' ) ) {
			$seeded_id = BizCity_CRM_Campaign_Scenario_Dispatcher::seed_default_rule();
			$results['seed:campaign_dispatch_rule'] = $seeded_id > 0 ? ( 'ok:#' . $seeded_id ) : 'fail';
		} else {
			$results['seed:campaign_dispatch_rule'] = 'skip:dispatcher_class_missing';
		}

		return $results;
	}

	/**
	 * Idempotent migration for v1.16.0 — Customer Source adapter columns.
	 *
	 * Adds idempotent upsert keys so BizCity_CRM_Pipeline_Sync can write
	 * once-per-(source, source_ref) without race conditions:
	 *   - opportunities.{source, source_ref, source_synced_at} + UNIQUE(source, source_ref)
	 *   - leads.{source_ref} + UNIQUE(source, source_ref)
	 *
	 * ADD-only; safe to run on every install() call. UNIQUE index creation
	 * uses ALTER TABLE ADD UNIQUE so existing duplicate NULLs survive
	 * (MySQL/MariaDB treat multiple NULLs as distinct under UNIQUE).
	 */
	public static function migrate_phase_042(): array {
		global $wpdb;
		$results = array();

		$add_column = static function ( string $table, string $column, string $ddl ) use ( $wpdb, &$results ): void {
			$key = "col:{$table}.{$column}";
			if ( self::column_exists( $table, $column ) ) { $results[ $key ] = 'skip'; return; }
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$ddl}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};
		$add_unique = static function ( string $table, string $index, string $cols ) use ( $wpdb, &$results ): void {
			$key = "idx:{$table}.{$index}";
			if ( self::index_exists( $table, $index ) ) { $results[ $key ] = 'skip'; return; }
			$ok = $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY {$index} {$cols}" );
			$results[ $key ] = ( false === $ok ) ? ( 'fail:' . $wpdb->last_error ) : 'ok';
		};

		$opps = self::tbl_crm_opportunities();
		if ( self::table_exists( $opps ) ) {
			$add_column( $opps, 'source',           'VARCHAR(64) NULL AFTER custom_json' );
			$add_column( $opps, 'source_ref',       'VARCHAR(190) NULL AFTER source' );
			$add_column( $opps, 'source_synced_at', 'DATETIME NULL AFTER source_ref' );
			$add_unique( $opps, 'uniq_source_ref', '(source, source_ref)' );
		}

		$leads = self::tbl_crm_leads();
		if ( self::table_exists( $leads ) ) {
			$add_column( $leads, 'source_ref', 'VARCHAR(190) NULL AFTER source' );
			$add_unique( $leads, 'uniq_source_ref', '(source, source_ref)' );
		}

		return $results;
	}

	/**
	 * 2026-05-28 v1.17.0 — Audit log table for M-CRM.M1.W3.
	 * Uses dbDelta — safe to run on existing installs (table already exists = no-op).
	 */
	public static function migrate_phase_043(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset   = $wpdb->get_charset_collate();
		$audit_log = self::tbl_crm_audit_log();
		dbDelta( "CREATE TABLE `{$audit_log}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type VARCHAR(64) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(32) NOT NULL DEFAULT 'updated',
			before_json LONGTEXT NULL,
			after_json LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			user_label VARCHAR(190) NULL,
			event_uuid VARCHAR(36) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_user (user_id),
			KEY idx_created_at (created_at),
			KEY idx_event_uuid (event_uuid)
		) {$charset};" );
	}

	/**
	 * 2026-06-07 v1.20.0 — Phase 0.38 Order Fulfillment Hub: 3 new tables.
	 * ADD-only via dbDelta. No DROP/MODIFY. R-DCL compliant (modules.twin-crm.json v1.20.0).
	 * [2026-06-07 Johnny Chu] PHASE-0.38.W1.3 — create recap_log + order_csat + shipment_status_log
	 */
	public static function migrate_phase_045(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// 1) Order recap send audit log.
		$recap_log = self::tbl_order_recap_log();
		dbDelta( "CREATE TABLE `{$recap_log}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			recap_type VARCHAR(32) NOT NULL DEFAULT 'new_order',
			platform VARCHAR(32) NOT NULL DEFAULT '',
			chat_id VARCHAR(190) NOT NULL DEFAULT '',
			gateway_msg_id VARCHAR(190) NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'sent',
			error VARCHAR(512) NULL,
			sent_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_order_type (order_id, recap_type),
			KEY idx_sent (sent_at),
			KEY idx_status (status)
		) {$charset};" );

		// 2) Customer satisfaction feedback from public tracking page.
		$order_csat = self::tbl_order_csat();
		dbDelta( "CREATE TABLE `{$order_csat}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			contact_id BIGINT UNSIGNED NULL,
			rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
			comment VARCHAR(512) NULL,
			source VARCHAR(32) NOT NULL DEFAULT 'public_tracking',
			ip VARCHAR(45) NULL,
			submitted_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_order (order_id),
			KEY idx_rating (rating),
			KEY idx_submitted (submitted_at)
		) {$charset};" );

		// 3) Shipping status change log (detected by cron tracker, feed timeline).
		$shipment_log = self::tbl_shipment_status_log();
		dbDelta( "CREATE TABLE `{$shipment_log}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			tracking_number VARCHAR(100) NULL,
			provider VARCHAR(32) NULL,
			old_status VARCHAR(64) NULL,
			new_status VARCHAR(64) NOT NULL DEFAULT '',
			raw_payload LONGTEXT NULL,
			changed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_order (order_id, changed_at),
			KEY idx_tracking (tracking_number)
		) {$charset};" );
	}

	// [2026-06-07 Johnny Chu] PHASE-0.40.G1 — Deplao Parity: variants_json + checklist_json + crm_notes_doc (v1.21.0).
	public static function migrate_phase_046(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// 1) campaigns.variants_json — ADD-only guard.
		$tbl_campaigns = self::tbl_campaigns();
		if ( self::table_exists( $tbl_campaigns ) && ! self::column_exists( $tbl_campaigns, 'variants_json' ) ) {
			$wpdb->query( "ALTER TABLE `{$tbl_campaigns}` ADD COLUMN variants_json LONGTEXT NULL" );
		}

		// 2) crm_tasks.checklist_json — ADD-only guard.
		$tbl_tasks = self::tbl_crm_tasks();
		if ( self::table_exists( $tbl_tasks ) && ! self::column_exists( $tbl_tasks, 'checklist_json' ) ) {
			$wpdb->query( "ALTER TABLE `{$tbl_tasks}` ADD COLUMN checklist_json LONGTEXT NULL" );
		}

		// 3) crm_notes_doc — new table (Deplao ERP Note parity).
		$tbl_notes = self::tbl_notes_doc();
		dbDelta( "CREATE TABLE `{$tbl_notes}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			folder VARCHAR(120) NOT NULL DEFAULT 'General',
			title VARCHAR(255) NOT NULL DEFAULT '',
			content LONGTEXT NULL,
			tags_json LONGTEXT NULL,
			share_scope VARCHAR(16) NOT NULL DEFAULT 'private',
			owner_id BIGINT UNSIGNED NULL,
			pinned TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_owner_folder (owner_id, folder),
			KEY idx_share (share_scope)
		) {$charset};" );
	}

	// [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-16 B-5 (R-LEAN-4) — bizcity_crm_admin_chat_audit is
	// quarantined; BizCity_CRM_AdminChat_Audit now writes/reads the shared JSONL logger
	// (contract core.twin_crm.admin_chat_audit).
	public static function migrate_phase_047(): void {
		if ( false ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'bizcity_crm_admin_chat_audit';
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			chat_id VARCHAR(255) NOT NULL DEFAULT '',
			guru_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			grant_id BIGINT UNSIGNED NULL,
			action VARCHAR(80) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'attempted',
			input_json LONGTEXT NULL,
			result_json LONGTEXT NULL,
			ip VARCHAR(45) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_created (user_id, created_at),
			KEY idx_chat_created (chat_id(191), created_at),
			KEY idx_action_status (action, status),
			KEY idx_guru (guru_id)
		) {$charset};" );
		}
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-0.43 M0 — ADD action_flags_json + delay_sec to broadcasts;
	 * ADD scheduled_send_at + idx_scheduled_send to broadcast_recipients. ADD-only, idempotent.
	 */
	public static function migrate_phase_048(): void {
		global $wpdb;
		// bizcity_crm_broadcasts — add action_flags_json
		$bc = self::tbl_broadcasts();
		$existing = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM `%1s`', $bc ), 0 ); // phpcs:ignore
		$existing = $wpdb->get_results( "SHOW COLUMNS FROM `{$bc}`", ARRAY_A );
		$cols     = array_column( $existing, 'Field' );
		if ( ! in_array( 'action_flags_json', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$bc}` ADD COLUMN `action_flags_json` LONGTEXT NULL" );
		}
		if ( ! in_array( 'delay_sec', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$bc}` ADD COLUMN `delay_sec` SMALLINT UNSIGNED NOT NULL DEFAULT 5" );
		}
		// bizcity_crm_broadcast_recipients — add scheduled_send_at
		$rcp = self::tbl_broadcast_recipients();
		$rcols = $wpdb->get_results( "SHOW COLUMNS FROM `{$rcp}`", ARRAY_A );
		$rcols = array_column( $rcols, 'Field' );
		if ( ! in_array( 'scheduled_send_at', $rcols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$rcp}` ADD COLUMN `scheduled_send_at` DATETIME NULL" );
		}
		// Add index idx_scheduled_send if absent
		$indexes = $wpdb->get_col( "SHOW INDEX FROM `{$rcp}` WHERE Key_name = 'idx_scheduled_send'", 0 );
		if ( empty( $indexes ) ) {
			$wpdb->query( "ALTER TABLE `{$rcp}` ADD INDEX `idx_scheduled_send` (`status`, `scheduled_send_at`)" );
		}
	}

	/**
	 * [2026-07-03 Johnny Chu] PHASE-0.46 M1 — v1.24.0
	 * Create bizcity_crm_activities + bizcity_crm_submissions tables if missing.
	 * Idempotent: uses dbDelta (CREATE TABLE IF NOT EXISTS equivalent).
	 */
	public static function migrate_phase_049(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// bizcity_crm_activities — entity-typed activity log for any CRM entity
		$act = self::tbl_crm_activities();
		dbDelta( "CREATE TABLE `{$act}` (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type      VARCHAR(32)     NOT NULL,
			entity_id        BIGINT UNSIGNED NOT NULL,
			type             VARCHAR(32)     NOT NULL DEFAULT 'note',
			title            VARCHAR(255)    NOT NULL DEFAULT '',
			body             LONGTEXT        NULL,
			user_id          BIGINT UNSIGNED NULL,
			user_label       VARCHAR(190)    NULL,
			call_date             DATETIME        NULL,
			call_agent_wp_user_id BIGINT UNSIGNED NULL,
			call_agent_label      VARCHAR(190)    NULL,
			created_at       DATETIME        NOT NULL,
			deleted_at       DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_created (created_at),
			KEY idx_deleted (deleted_at)
		) {$charset};" );

		// bizcity_crm_submissions — unified submission pipeline
		$sub = self::tbl_crm_submissions();
		dbDelta( "CREATE TABLE `{$sub}` (
			id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type             VARCHAR(32)     NOT NULL DEFAULT 'cf7',
			source_ref_id           BIGINT UNSIGNED NOT NULL,
			contact_name            VARCHAR(255)    NULL,
			contact_phone           VARCHAR(32)     NULL,
			contact_email           VARCHAR(190)    NULL,
			follow_status           VARCHAR(32)     NOT NULL DEFAULT 'new',
			assigned_to_wp_user_id  BIGINT UNSIGNED NULL,
			assigned_by_wp_user_id  BIGINT UNSIGNED NULL,
			assigned_at             DATETIME        NULL,
			source_meta_json        LONGTEXT        NULL,
			pipeline_opp_id         BIGINT UNSIGNED NULL,
			created_at              DATETIME        NOT NULL,
			updated_at              DATETIME        NOT NULL,
			deleted_at              DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY idx_source (source_type, source_ref_id),
			KEY idx_follow (follow_status),
			KEY idx_assignee (assigned_to_wp_user_id),
			KEY idx_deleted (deleted_at)
		) {$charset};" );

		// Invalidate bizcity_tbl_exists static + wp_cache for both tables
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $act );
			bizcity_tbl_invalidate( $sub );
		}
	}




	/**
	 * PHASE-0.63A WP-0 — storage for the pipeline platform (v1.35.0).
	 *
	 * Three additive columns pin a run to the definition version it started on, one column gives a
	 * task somewhere to keep the evidence a step demands, and one new table — the only new table of
	 * the whole 0.6x programme — carries the SLA deadline queue the 60s runner claims from.
	 *
	 * ADD-only and idempotent: every statement is guarded by SHOW COLUMNS / SHOW INDEX, so running it
	 * again on a migrated site is a no-op. R-DCL: declared in core/diagnostics/changelog/modules.twin-crm.json.
	 *
	 * [2026-09-21 PHASE-0.63A WP-0.1..0.5]
	 */
	public static function migrate_phase_063(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$opps  = self::tbl_crm_opportunities();
		$tasks = self::tbl_crm_tasks();

		// 0.1/0.2 — a run knows which pipeline it belongs to and which definition version it was pinned to.
		if ( ! self::column_exists( $opps, 'pipeline_kind' ) ) {
			$wpdb->query( "ALTER TABLE `{$opps}` ADD COLUMN pipeline_kind VARCHAR(32) NOT NULL DEFAULT 'sales' AFTER stage" );
		}
		if ( ! self::column_exists( $opps, 'pipeline_def_id' ) ) {
			$wpdb->query( "ALTER TABLE `{$opps}` ADD COLUMN pipeline_def_id BIGINT UNSIGNED NULL AFTER pipeline_kind" );
		}
		if ( ! self::column_exists( $opps, 'pipeline_def_version' ) ) {
			$wpdb->query( "ALTER TABLE `{$opps}` ADD COLUMN pipeline_def_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER pipeline_def_id" );
		}
		// 0.1 — structured evidence for a step lives on its work ticket, not in a second table.
		if ( ! self::column_exists( $tasks, 'data_json' ) ) {
			$wpdb->query( "ALTER TABLE `{$tasks}` ADD COLUMN data_json LONGTEXT NULL AFTER notes" );
		}

		// 0.3 — the deadline queue (PHASE-0.63 §4.1, verbatim columns).
		$deadlines = self::tbl_pipeline_deadlines();
		dbDelta( "CREATE TABLE `{$deadlines}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NOT NULL,
			pipeline_kind VARCHAR(32) NOT NULL DEFAULT '',
			stage_key VARCHAR(64) NULL,
			rule_id VARCHAR(64) NOT NULL DEFAULT '',
			layer VARCHAR(16) NOT NULL DEFAULT 'step',
			anchor_at DATETIME NULL,
			due_at DATETIME NOT NULL,
			level TINYINT UNSIGNED NOT NULL DEFAULT 0,
			next_fire_at DATETIME NULL,
			state VARCHAR(16) NOT NULL DEFAULT 'pending',
			met_at DATETIME NULL,
			breached_at DATETIME NULL,
			paused_ms INT UNSIGNED NOT NULL DEFAULT 0,
			paused_at DATETIME NULL,
			claimed_by VARCHAR(64) NULL,
			claimed_at DATETIME NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(190) NULL,
			dedupe_key VARCHAR(190) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_dedupe (dedupe_key),
			KEY idx_due (state, next_fire_at),
			KEY idx_run (run_id, state),
			KEY idx_kind_state (pipeline_kind, state)
		) {$charset};" );

		// 0.4 — the two reads the stage rail and the target selector do on every conversation open.
		if ( ! self::index_exists( $opps, 'idx_kind_stage' ) ) {
			$wpdb->query( "ALTER TABLE `{$opps}` ADD KEY idx_kind_stage (pipeline_kind, stage, owner_id)" );
		}
		if ( ! self::index_exists( $opps, 'idx_contact_kind' ) ) {
			$wpdb->query( "ALTER TABLE `{$opps}` ADD KEY idx_contact_kind (contact_id, pipeline_kind)" );
		}

		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $opps );
			bizcity_tbl_invalidate( $tasks );
			bizcity_tbl_invalidate( $deadlines );
		}
	}

	/**
	 * PHASE-0.63C GC-25.1 — `opportunities.stage_entered_at` (v1.38.0).
	 *
	 * "Stuck in this stage" and "hours in stage" need the moment a run ENTERED its current stage. Until now that
	 * lived only in `custom_json.stage_at` (not groupable, not comparable in SQL), and `updated_at` cannot stand in
	 * for it: it moves on every write, including ticking a sub-step. `Pipeline_Run_Service::write_row()` stamps this
	 * column only when `stage` really changes.
	 *
	 * ADD-only and idempotent. The backfill only copies a moment the run itself recorded (`custom_json.stage_at`,
	 * written by every transition since PHASE-0.63A) — a row without one stays NULL rather than being given an invented
	 * history. It is bounded (one batch of 500 provable rows per upgrade request), so a web request never walks the
	 * whole table. Readers use COALESCE(stage_entered_at, updated_at), which is a read-side fallback, not a written
	 * fact. R-DCL: modules.twin-crm.json v1.38.0.
	 */
	public static function migrate_phase_063c(): void {
		global $wpdb;
		$opps = self::tbl_crm_opportunities();
		if ( ! self::column_exists( $opps, 'stage_entered_at' ) ) {
			$wpdb->query( "ALTER TABLE `{$opps}` ADD COLUMN stage_entered_at DATETIME NULL AFTER pipeline_def_version" );
		}
		if ( ! self::index_exists( $opps, 'idx_kind_stage_at' ) ) {
			$wpdb->query( "ALTER TABLE `{$opps}` ADD KEY idx_kind_stage_at (pipeline_kind, stage, stage_entered_at)" );
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $opps );
		}
		if ( function_exists( 'bizcity_column_invalidate' ) ) {
			bizcity_column_invalidate( $opps, 'stage_entered_at' );
		}
		if ( ! self::column_exists( $opps, 'stage_entered_at' ) ) {
			return;
		}
		// Only rows that PROVE their stage entry: registry runs (not the legacy 'sales' default) that carry custom_json.stage_at.
		// stage_at is written from current_time('timestamp') — already site-local — so gmdate() of it IS the local wall time
		// (adding gmt_offset again would shift it twice).
		$rows = $wpdb->get_results( "SELECT id, custom_json FROM `{$opps}` WHERE pipeline_kind <> 'sales' AND status = 'open' AND stage_entered_at IS NULL AND deleted_at IS NULL AND custom_json LIKE '%\"stage_at\"%' ORDER BY id ASC LIMIT 500", ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$custom = json_decode( (string) ( $row['custom_json'] ?? '' ), true );
			if ( ! is_array( $custom ) || empty( $custom['stage_at'] ) || ! is_numeric( $custom['stage_at'] ) ) { continue; }
			$wpdb->update( $opps, array( 'stage_entered_at' => gmdate( 'Y-m-d H:i:s', (int) $custom['stage_at'] ) ), array( 'id' => (int) $row['id'] ) );
		}
	}

	/**
	 * PHASE-0.71 F71-13 — `bizcity_crm_documents.message_id` (v1.37.0).
	 *
	 * `crm_documents` had `related_entity_type`/`related_entity_id` but no way to point back
	 * at the inbound message an attachment came from — 0.63C's GC-9 text assumed this column
	 * already existed; it didn't (confirmed by reading the CREATE TABLE directly, PHASE-0.71
	 * §2.3 F71-7). `BizCity_CRM_Pipeline_Document_Link` (new, `includes/inbox/class-pipeline-
	 * document-link.php`) uses this column both to store the back-pointer and, more importantly,
	 * as its idempotency key — a message whose attachments are already linked is never
	 * re-processed. ADD-only, idempotent.
	 */
	public static function migrate_phase_071(): void {
		global $wpdb;
		$documents = self::tbl_crm_documents();
		if ( ! self::column_exists( $documents, 'message_id' ) ) {
			$wpdb->query( "ALTER TABLE `{$documents}` ADD COLUMN message_id BIGINT UNSIGNED NULL AFTER related_entity_id" );
		}
		if ( ! self::index_exists( $documents, 'idx_message' ) ) {
			$wpdb->query( "ALTER TABLE `{$documents}` ADD KEY idx_message (message_id)" );
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $documents );
		}
		if ( function_exists( 'bizcity_column_invalidate' ) ) {
			bizcity_column_invalidate( $documents, 'message_id' );
		}
	}

	/** PHASE 0.35 M6.W9 (plugin part) — campaign ↔ scenario binding columns. */
	public static function migrate_phase_039(): array {
		global $wpdb;
		$results = array();
		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb ): void {
			if ( ! self::column_exists( $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			}
		};
		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb ): void {
			if ( ! self::index_exists( $table, $index ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			}
		};
		$tbl = self::tbl_campaigns();
		$add_column( $tbl, 'welcome_template_id', 'BIGINT UNSIGNED NULL AFTER notebook_id' );
		$add_column( $tbl, 'bound_character_id',  'BIGINT UNSIGNED NULL AFTER welcome_template_id' );
		$add_column( $tbl, 'bound_notebook_id',   'BIGINT UNSIGNED NULL AFTER bound_character_id' );
		return $results;
	}

	/** PHASE 0.35 M-CRM.M8.W2 (plugin part) — crm_invoices.wc_order_id. */
	public static function migrate_phase_040(): array {
		global $wpdb;
		$results = array();
		$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb ): void {
			if ( ! self::column_exists( $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}" );
			}
		};
		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb ): void {
			if ( ! self::index_exists( $table, $index ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$index} {$definition}" );
			}
		};
		$invoices = self::tbl_crm_invoices();
		$add_column( $invoices, 'wc_order_id', 'BIGINT UNSIGNED NULL AFTER custom_json' );
		$add_index(  $invoices, 'idx_wc_order', '(wc_order_id)' );
		return $results;
	}

	/** v1.18.0 (plugin part) — broadcasts + recipients. */
	public static function migrate_phase_044(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$broadcasts = self::tbl_broadcasts();
		dbDelta( "CREATE TABLE `{$broadcasts}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			inbox_ids_json LONGTEXT NULL,
			segment_filter_json LONGTEXT NULL,
			message_template LONGTEXT NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'draft',
			scheduled_at DATETIME NULL,
			sent_at DATETIME NULL,
			total_count INT UNSIGNED NOT NULL DEFAULT 0,
			sent_count INT UNSIGNED NOT NULL DEFAULT 0,
			failed_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_scheduled (scheduled_at),
			KEY idx_created_by (created_by)
		) {$charset};" );

		$recipients = self::tbl_broadcast_recipients();
		dbDelta( "CREATE TABLE `{$recipients}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			broadcast_id BIGINT UNSIGNED NOT NULL,
			contact_id BIGINT UNSIGNED NOT NULL,
			conversation_id BIGINT UNSIGNED NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'queued',
			sent_at DATETIME NULL,
			error VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_bc (broadcast_id, contact_id),
			KEY idx_broadcast (broadcast_id, status),
			KEY idx_contact (contact_id)
		) {$charset};" );
	}

	/** PHASE-0.39F-F2-F5 (plugin part) — reporting events/rollups, assignment policies. */
	public static function migrate_phase_053(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$reporting_events = self::tbl_reporting_events();
		dbDelta( "CREATE TABLE `{$reporting_events}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_uuid CHAR(36) NOT NULL,
			metric VARCHAR(64) NOT NULL,
			occurred_at DATETIME NOT NULL,
			event_date DATE NOT NULL,
			inbox_id BIGINT UNSIGNED NULL,
			team_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			conversation_id BIGINT UNSIGNED NULL,
			channel_type VARCHAR(32) NULL,
			value DECIMAL(18,4) NOT NULL DEFAULT 1,
			business_hours_value DECIMAL(18,4) NULL,
			dedupe_key VARCHAR(190) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_dedupe (dedupe_key),
			KEY idx_date_metric (event_date, metric),
			KEY idx_dimension_date (inbox_id, team_id, user_id, event_date)
		) {$charset};" );

		$rollups = self::tbl_reporting_rollups();
		dbDelta( "CREATE TABLE `{$rollups}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bucket_date DATE NOT NULL,
			dimension_type VARCHAR(16) NOT NULL,
			dimension_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel_type VARCHAR(32) NOT NULL DEFAULT '',
			metric VARCHAR(64) NOT NULL,
			count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sum_value DECIMAL(18,4) NOT NULL DEFAULT 0,
			sum_business_hours DECIMAL(18,4) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_bucket_dimension (bucket_date, dimension_type, dimension_id, channel_type, metric),
			KEY idx_metric_date (metric, bucket_date),
			KEY idx_dimension_date (dimension_type, dimension_id, bucket_date)
		) {$charset};" );

		$policies = self::tbl_assignment_policies();
		dbDelta( "CREATE TABLE `{$policies}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			assignment_order VARCHAR(32) NOT NULL DEFAULT 'round_robin',
			conversation_priority VARCHAR(32) NOT NULL DEFAULT 'earliest_created',
			fair_distribution_limit INT UNSIGNED NOT NULL DEFAULT 100,
			fair_distribution_window_seconds INT UNSIGNED NOT NULL DEFAULT 3600,
			max_open_conversations_per_user INT UNSIGNED NULL,
			overflow_action VARCHAR(32) NOT NULL DEFAULT 'leave_unassigned',
			created_by BIGINT UNSIGNED NULL,
			updated_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_name (name),
			KEY idx_enabled_order (enabled, assignment_order)
		) {$charset};" );

		$inbox_policies = self::tbl_inbox_assignment_policies();
		dbDelta( "CREATE TABLE `{$inbox_policies}` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			inbox_id BIGINT UNSIGNED NOT NULL,
			assignment_policy_id BIGINT UNSIGNED NOT NULL,
			team_id BIGINT UNSIGNED NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_inbox (inbox_id),
			KEY idx_policy_team (assignment_policy_id, team_id)
		) {$charset};" );
		self::invalidate_tables_cache();
	}
}

endif;
