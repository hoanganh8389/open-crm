# Open CRM

<!-- [2026-10-09 Johnny Chu - Chu Hoàng Anh] PHASE-0.96 — README của plugin riêng bizcity-twin-crm (Zalo Brain CRM). -->

**Open CRM is the team arm of Zalo Brain.** It turns the conversation ledger that Zalo Brain keeps for every Zalo number into a sales and customer-care operation: pipeline, tasks and handoff, SLA, reports, campaigns and broadcast, B2B accounts, invoices, automated after-sales (Woo recap, CSAT, shipping), email automation, print-ads, service dispatch and the React inbox.

**Author:** Biz Central Brain — Johnny Chu (Chu Hoàng Anh). Bizcity Central Brain, copyright registration certificate No. 8877/2026/QTG.

## Where it lives

| | |
|---|---|
| Folder | `wp-content/plugins/bizcity-twin-crm/` — a **separate plugin**, outside `bizcity-twin-ai` (PHASE-0.96, D96-1) |
| Layout | Flat: `bizcity-twin-crm.php`, `bootstrap.php`, `zalo-brain.json`, `includes/`, `apps/`, `templates/`, `assets/dist/`, `data/`, `manifests/` |
| Git | `github.com/hoanganh8389/open-crm` (its own repository; only PHP + built dist are published — R-PROD-REPO) |
| Requires | **Zalo Brain** = `bizcity-twin-ai` (`Requires Plugins`), PHP 7.4, WordPress 6.0. Activated **per site** — single-site or any subsite; never network-wide (`Network: false`) |
| Licence | **Premium** add-on (1API master tier) |
| Name | Brand **Open CRM** (was “Zalo Brain CRM” until 2026-11-21, owner decision). Owner, the BizCity trademark, the folder slug `bizcity-twin-crm`, the text domain and the `BizCity_`/`bizcity_` prefix are unchanged. <!-- [2026-11-21 04:08 PM Johnny Chu - Chu Hoàng Anh] BRAND-OPEN-CRM --> |
| Earlier home (superseded) | `bizcity-twin-ai/plugins/bizcity-twin-crm/` as "BizCity Twin CRM (Inbox Hub)" 0.32.x, a mandatory bundle — until 2026-10-09 |

## How it is loaded

`bizcity-twin-ai` loads the **CRM spine** (`core/crm`, contract `crm-spine@1`) on every request: contacts, inboxes, conversations, messages, attachments, labels, teams and inbox members, archive receipts, magic links, the channel ingestor and outbound dispatcher, the spine REST routes and the spine DB installer. This plugin boots on top at `plugins_loaded@6`:

1. `BizCity_CRM_Spine::register_extension( zalo-brain.json )` — tells the spine which features it brings (`pipeline`, `tasks`, `sla`, `reports`, …). Core code asks `BizCity_CRM_Spine::has( 'pipeline' )` before touching anything that exists only here, and answers `crm_feature_unavailable` when this plugin is absent.
2. `BizCity_CRM_DB_Installer_Ext` appends its CREATE statements to the spine's single `dbDelta` pass (`bizcity_crm_install_tables`) and runs its migrations on `bizcity_crm_install_migrate`. Own version option `bizcity_crm_ext_db_ver`. Nothing is ever dropped on deactivation.
3. `BizCity_CRM_REST_Controller extends BizCity_CRM_Spine_REST` registers the extension routes under the shared namespace `bizcity-crm/v1`; the spine registered its own routes at priority 9.

Without Zalo Brain the plugin shows one admin notice and loads nothing. Without this plugin, Zalo Brain still receives, answers, archives and scopes every conversation — only the team features are missing.

## What moved where (PHASE-0.96)

| Stays in Zalo Brain (`core/crm`) | This plugin | Dropped 2026-10-09 (archived in `_archived/PHASE-0.96`, not published) |
|---|---|---|
| ledger tables (15), Repository, ingestor, outbound dispatcher, inbox access + staff policy, contracts (Actor/Authority), channel adapters, magic links + admin-chat grants, Guru per inbox, spine REST, spine installer | pipeline, tasks/handoff/kanban, SLA, reports + AI usage, submissions/lead capture, calendar adapter, CSKH tự động (Woo), campaigns/broadcast/QR/loyalty, B2B + invoices, email automation, print-ads, service dispatch, staff admin REST, React SPA, menu "Open CRM", `/crm/` | PHP AI replier (the cell answers now), CRM rule engine (all automation = bizcity-automation), identity-conflict queue (identity has one source of truth: the cell) |

Design and checklist: `bizcity-twin-ai/core/channel-gateway/docs/PHASE-0.96-ZALO-BRAIN-CRM-SPLIT/` (kept local, not published).

## Rules that apply here

- Never `require` a core file by path (D96-4). Core classes are loaded before this plugin or they are not there at all.
- Class, table, REST and capability names are unchanged from the bundled era (`BizCity_CRM_*`, `bizcity_crm_*`, `bizcity-crm/v1`, `bizcity_crm_*` caps).
- All writes go through the Repository + `BizCity_CRM_Event_Emitter` (R-UNIFY); every UI write is a verb button → `ActionSheet` (R-ACTION-SHEET); every flow ≤ 4 steps (R-LEAN-4).
