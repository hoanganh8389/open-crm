# AGENTS.md

Hướng dẫn cho người và agent làm việc trong repo này.

<!-- bizcity:loi-truoc@1 — khối chung do bizcity-platform phát (bizcity_core_first@1). Sửa ở bizcity-platform/templates/loi-truoc/AGENTS-khoi.md rồi chép lại nguyên khối; SC-STD-4 kiểm dấu này. -->
## Lõi trước — bánh xe đóng góp (R-CORE-10)

Làm app thì lõi lớn lên: tối ưu open-ketoan, open-cake, open-kiot… là đồng thời hoàn thiện **open-core**; app làm sau hưởng toàn bộ thành quả.

```text
① App phát triển (nghiệp vụ mới) ─► ② Phát hiện năng lực chung ─► ③ Rút lên lõi (test · release) ─► ④ App dùng lại, xoá bản trùng ─┐
        ▲                                                                                                                      │
        └──────────────────────────────── app sau viết ít hơn ◄────────────────────────────────────────────────────────────────┘
```

1. **Trước khi viết thứ phổ thông** (danh sách, form, nhập / xuất, cài đặt, meta, quyền, chứng từ, sự kiện, đồng bộ, thông báo, báo cáo, CLI…): tra lõi — `bizcity doctor --tim <từ khoá>` (khi chưa có lệnh: đọc `open-core/packages/*/src` + `open-core/CHANGELOG.md`). Lõi có ⇒ **dùng**, không viết lại.
2. **Lõi chưa có ⇒ chọn đúng một đường**, ghi vào ô **"Lõi hay app?"** của mẫu PR:
   - **A — vào lõi:** ≥ 2 app cần, hoặc là hạ tầng chung ⇒ mở PR ở open-core trước (nhãn `rut-len-loi`); app dùng chế độ `core-dev` (`bizcity dev link-core`) trong lúc chờ bản phát hành.
   - **B — ứng viên lõi:** mới một app cần nhưng rõ là phổ thông ⇒ viết trong `src/core-candidate/`, đầu tệp `@bizcity-core-candidate <mô tả>`, không chuỗi nghiệp vụ cứng — để rút lên được nguyên tệp.
   - **C — nghiệp vụ:** chỉ app này có nghĩa ⇒ viết ở app như thường.
3. **Phát hiện tự động:** `open-core/tools/ung-vien-loi.mjs` dò ứng viên đã đánh dấu + mã trùng giữa các app ⇒ bảng "Ứng viên lõi" trong scoreboard của open-core.
4. **Rút lên lõi** có test chạy thật, CHANGELOG lõi ghi app nguồn ⇒ **app xoá bản riêng** khi lên bản lõi kế tiếp (codemod `open-core/tools/doi-nguon/` nếu đổi đường import).

App PHP / WordPress (open-kiot, plugin bizcity-*): "lõi" = hợp đồng + vector chung ở bizcity-platform và PHP SDK chung; cùng bốn bước.
Giữ chỗ việc ở lõi: `open-core/docs/phases/CLAIMS.md` · chia lane: `open-core/docs/phases/LANES.md` · chuẩn: [`bizcity_core_first@1`](https://github.com/hoanganh8389/bizcity-platform/blob/main/docs/standard/BIZCITY-CORE-FIRST-v1.md).
<!-- /bizcity:loi-truoc -->
