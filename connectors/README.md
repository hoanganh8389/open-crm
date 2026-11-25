# connectors/ — danh sách kết nối mở rộng (OC-011.1)

<!-- [2026-11-24 Johnny Chu - Chu Hoàng Anh] PHASE-011 OC-011.1 — mỗi thư mục một nền tảng; thêm nền tảng = thêm một connector.json, không sửa mã. -->

Mỗi `<key>/connector.json` (schema `ocrm-connector@1`):

| Khoá | Nghĩa |
|---|---|
| `key` | trùng tên thư mục, `[a-z0-9-]` |
| `group` | `pos` · `pay` · `ship` · `msg` (tab trên màn Kết nối mở rộng) |
| `order`, `name`, `desc`, `bizcity` | hiển thị |
| `fields[]` | 1–4 ô: `id`, `label`, `type` (`text` · `url` · `secret`), `required`, `placeholder`, `help` |
| `check` | `null` = chưa có cách kiểm ⇒ lưu xong hiện "Đã lưu · chưa kiểm được". Có thì `{method, url, headers, ok_when:{path, equals}}`; `{field}` được thay bằng giá trị ô (trong URL đã `rawurlencode`) |
| `webhook` | nền tảng gửi webhook vào (OC-011.3) |
| `capabilities` | năng lực kịch bản / chat dùng được khi đã kết nối |

Khoá lưu ở option theo blog `ocrm_connectors` (autoload = no). Ô `secret` mã hoá AES-256-GCM (khoá dẫn từ `wp_salt('auth')`); REST không bao giờ trả lại giá trị, chỉ 4 ký tự cuối. Không có openssl ⇒ từ chối lưu, không lưu thô.

Đường kiểm (`check.url`) lấy theo tài liệu công khai của từng nền tảng, **chưa chạy thử với khoá thật**. Nền tảng chưa chắc đường kiểm thì để `check: null` — giao diện nói thật "chưa kiểm được", không hiện "Đã kết nối".

Sau khi sửa json: `node frontend/dev/gen-oc-connectors.cjs` để sinh lại `frontend/dev/oc-connectors.js` (bản cho trang xem trước).

## Webhook vào (OC-011.3)

`POST /wp-json/bizcity-crm/v1/webhook/{key}` — chỉ nền tảng có bộ đọc trong `BizCity_OCRM_Connectors::WEBHOOK_ADAPTERS` (hiện: `casso` header `Secure-Token` = ô `secure_token`; `sepay` header `Authorization: Apikey …` = ô `webhook_key`). Chưa đặt khoá ⇒ từ chối mọi webhook. Mỗi giao dịch ⇒ `BizCity_OCRM_Events::submit()` vào bảng `bizcity_ocrm_events`, khoá tự nhiên `(source, doc_key = mã giao dịch)`. Thêm nền tảng: thêm khoá vào `WEBHOOK_ADAPTERS` + hàm `parse_{key}()`.
