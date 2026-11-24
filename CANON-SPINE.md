# Canon chung và xương sống xuyên app — áp cho open-crm (bản WordPress)

<!-- [2026-11-24 06:40 PM Johnny Chu - Chu Hoàng Anh] SPINE-3 — gieo canon theo Q-SPINE-4 (chủ duyệt 2026-11-24). Đặt ở gốc vì repo bỏ theo dõi docs/ (quy tắc chủ 2026-10-09: chỉ đưa PHP + dist). Tài liệu thuần, không đổi mã. -->

| Tài liệu | Ở đâu |
|---|---|
| **BIZCITY OPEN CANON** | [open-hub/docs/canon](https://github.com/hoanganh8389/open-hub/blob/main/docs/canon/README.md) |
| **`bizcity_spine@1`** — xương sống xuyên app | [bizcity-platform/docs/standard/BIZCITY-SPINE-v1.md](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-SPINE-v1.md) |
| Bản web song song | [open-cake](https://github.com/hoanganh8389/open-cake) — không chung DB, chung tên / luật / hợp đồng (`docs/00-CANON.md` của cake: "Hai bản song song") |

## Open CRM trong xương sống (`bizcity.app.json`)

| | |
|---|---|
| Làm chủ | `pipeline` (giai đoạn, việc, SLA — sổ riêng của app) |
| Dùng | `tenant`, `user` (`sub = wp:<id>`), `contact` (chủ là open-cake: mang danh tính có bằng chứng, không bịa `contact_id`), `partner` (gửi `code` một chiều khi khách phát sinh công nợ), `document` (`doc_ref` của chứng từ app khác, ghi `document_links`) |
| Nền | chạy trên sổ hội thoại của Zalo Brain (`core/crm`, `crm-spine@1`); không có Zalo Brain thì không nạp |

## Ba điều không làm

- Không ghép người theo tên; không khoá bảng theo id kênh thay cho danh tính có `proof` (R-CANON-3).
- Không ghi sổ kế toán, không giữ số tài khoản / thuế suất.
- Không thêm trục mới ở repo này.

## Giấy phép

Mọi ứng dụng Bizcity Open **0đ** (chủ chốt 2026-11-24). Dòng "Premium add-on" trong README cũ không còn đúng và sẽ bị `spine-check` nhắc.

## Kiểm

```bash
node ../bizcity-platform/tools/spine-check.mjs --manifest bizcity.app.json   # SC-SPINE-1, phải ĐẠT
```
