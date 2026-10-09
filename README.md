# BizCity Twin CRM (Inbox Hub)

> **R-LEAN-4 (Tối thượng):** mọi việc ≤ 4 bước (Census → Cut/Build → Prove → Record), mọi luồng UI ≤ 4 bước; plugin client chỉ được **nhẹ đi** — PHP lên server < 10 MB, bảng client sống < 30; trước khi xong chạy `node bin/lean-scoreboard.mjs --check` (CI chạy cùng lệnh). Luật: [R-LEAN-4](../../docs/rules/PHASE-0-RULE-LEAN-FOUR-STEP.md) · hàng đợi: [CORE-REDUCTION CHECKLIST §3d](../../core/knowledge/docs/CORE-REDUCTION-CHECKLIST.md). CRM giữ 11 bảng lõi; campaign/invoice/contract/SLA/reporting/print-ads/loyalty sang plugin add-on `bizcity-twin-crm-addon` (Q-W16-2, wave B-7).

> **Product priority:** CRM is currently built around **Zalo Cá nhân** as the
> primary customer-care channel. The Inbox must therefore preserve Personal
> session truth, owner/phone scope, captions/media, contact-card rendering and
> outbound delivery. Zalo OA and Facebook/Fanpage are the next business-owned
> expansion channels; Zalo Bot, Telegram and TwinChat are supporting/admin
> surfaces. This is a sequencing priority, not a second channel architecture:
> all channels still use the canonical Channel Gateway → CRM → Brain path.

Unified multi-channel customer-care inbox (Facebook, Messenger, Zalo OA, Zalo
Personal, WebChat, Email) with a Twin Brain trace — the admin/agent UI built
on top of the `core/channel-gateway` omni-channel foundation.

- **Framework contract:** [manifest.json](manifest.json) declares this
  plugin's capabilities. See
  [PLUGIN-TWIN-STANDARD.md](../../docs/extending/PLUGIN-TWIN-STANDARD.md) for
  the canonical extension shape and validation command
  (`php bin/twin diagnostics plugin plugins/bizcity-twin-crm --json`).
- **Architecture position:** `core/channel-gateway` normalizes every Zone 1
  customer channel into one envelope; this plugin is the CRM/Inbox surface
  that reads/writes that normalized data — it does not own channel adapters
  itself. See [PHASE-0-RULE-BRAIN-UNIFICATION.md](../../docs/rules/PHASE-0-RULE-BRAIN-UNIFICATION.md)
  Contract 13 for the omni-channel-to-brain-to-admin-UI chain.
- **Docs:** see [docs/](docs/) for the CRM inbox operating suite, contact
  unification, and channel adapter contract (`includes/inbox/class-channel-contract.php`).
- **Registry status:** `bizcity.twin-crm` in
  [PLUGIN-CONTRACT-REGISTRY-v1.json](../../docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json)
  is `partial` — required surfaces include `channel_normalized`,
  `outbound_logging`, `identity_scoping`, `error_envelope`, `runtime_probe`.

Do not `$wpdb->insert/update` CRM data directly from a controller/adapter —
all writes go through the Repository + `BizCity_CRM_Event_Emitter` per
R-UNIFY.
