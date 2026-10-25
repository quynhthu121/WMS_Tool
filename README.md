# WMS Tool – demo seminar

Công cụ WMS demo phục vụ seminar *Hệ thống quản lý nhà kho* của nhóm tại UIT, kế thừa source FlowLink-SCM.

> **Phạm vi:** Case study Seedcom Logistics chỉ là **bối cảnh** để xây dựng demo. Mọi kịch bản, SKU, location, số lượng là **mô phỏng của nhóm**. Công cụ này **không phản ánh hệ thống hay quy trình thật của Seedcom và không khẳng định Seedcom sử dụng nó.** Phân loại FACT / SIMULATION / PROPOSED SOLUTION: [docs/DATA_LABELS.md](docs/DATA_LABELS.md).

## 1. Nguồn kế thừa

- Repo nhóm: https://github.com/quynhthu121/WMS_Tool
- Source nền: https://github.com/nchphuong06/FlowLink-SCM (commit `79ad75e2`). Giữ nguyên thông tin tác giả và giấy phép trong mã kế thừa. README gốc: https://github.com/nchphuong06/FlowLink-SCM/blob/79ad75e2/README.md
- Database Core kế thừa từ nhánh `feature/db-core` của WMS_Tool.

## 2. Demo chứng minh điều gì

Luồng `Receiving → Put-away → Inventory → Outbound Order → Reservation → Picking → Packing → Shipping`; Stock Movement chạy xuyên suốt, Inventory Audit/Adjustment là nhánh đối soát.

| Năng lực | Màn hình |
|---|---|
| Inventory Visibility – hàng gì, bao nhiêu, ở đâu? | **Tồn kho theo vị trí**: On-hand / Reserved / Available theo SKU + Location, ledger của từng SKU |
| Accuracy & Traceability – vì sao số tồn đổi? | **Stock Movement**, **Đối soát**, **Kiểm kê** |
| Order Fulfillment Control – hàng nào còn phân bổ được? | **Xuất kho**: Reservation FIFO, Pick List, Packing, Shipping, trạng thái đơn |

Trang **Bằng chứng** tính trực tiếp từ dữ liệu 6 kết quả prototype phải chứng minh; **North Star**: với mọi cặp (SKU, Location), `on_hand` = tổng đại số Stock Movement (kể cả `OPENING`).

Số liệu chuẩn (khớp tài liệu nguồn trong `docs/source/`):

| Kịch bản | Diễn biến tổng tồn |
|---|---|
| 1 – Inbound ASN-DEMO-001 (A2) | 305 → 350 (khả dụng 305 → 325 → 335 → 350) |
| 2 – Outbound + Audit ORD-DEMO-001 (A4 §4) | 350 → 320 → 318; AO-001-M-DEN 50 → 70 → 40 → 38 |
| 3 – Ngoại lệ pick thiếu ORD-DEMO-002 (A4 §3.3, độc lập) | 315 → 309 → 261 |

## 3. Công nghệ

PHP 8.1 + Apache, MySQL 8.0 (InnoDB, CHECK constraint, trigger), Composer (autoload `App\` → `src/`, PHPUnit 10), Docker Compose, Adminer. Không dùng framework.

## 4. Chạy trên máy thành viên

Yêu cầu: Git, Docker Desktop (Linux engine).

```powershell
git clone https://github.com/quynhthu121/WMS_Tool.git
cd WMS_Tool
Copy-Item .env.example .env
```

Mở `.env`, đặt:

| Biến | Ý nghĩa |
|---|---|
| `DB_ROOT_PASSWORD`, `DB_PASSWORD` | Mật khẩu root và `app_user` |
| `DEMO_USER_PASSWORD` | Mật khẩu cho tài khoản demo `giamsat_demo`, `nhanvien_demo` (≥ 6 ký tự) |
| `DEMO_RESET_ENABLED` | `1` để bật nút Reset / stepper trong Bảng điều khiển Demo |
| Google, Gemini | Có thể để trống; Google Login và Trợ lý AI sẽ ẩn/không dùng được |

Không commit `.env`.

```bash
docker compose config --quiet
docker compose up -d --build
docker compose exec web php bin/seed_demo.php --preset=main
```

`up` chạy service `migrate` một lần trước `web`: đồng bộ `vendor` theo `composer.lock` rồi áp dụng migration cho `login` và DB test `login_test`. Website: http://127.0.0.1:8890 · Adminer: http://127.0.0.1:8891 (máy chủ `db`, người dùng `app_user`).

Đăng nhập `giamsat_demo` (Giám sát kho) hoặc `nhanvien_demo` (Nhân viên kho) với mật khẩu `DEMO_USER_PASSWORD`, rồi vào **Bảng điều khiển Demo**. Kịch bản trình bày: [docs/DEMO_SCRIPT.md](docs/DEMO_SCRIPT.md).

### Database đã có từ trước (volume cũ)

Không cần `down -v`. Chạy lại `docker compose up -d --build` (hoặc `docker compose run --rm migrate`): `000_baseline` nhận ra 12 bảng cũ và chỉ ghi nhận, các migration sau được thêm vào. Nên sao lưu trước:

```bash
docker compose exec db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction login' > backup_login.sql
```

`docker compose down -v` xóa toàn bộ dữ liệu – chỉ dùng khi chủ động muốn làm sạch.

### Lệnh thường dùng

```bash
docker compose run --rm migrate php bin/migrate.php --status   # trạng thái migration
docker compose exec web vendor/bin/phpunit                      # toàn bộ test (chạy trên login_test)
docker compose exec web php bin/demo.php main --db=login_test   # chạy kịch bản 1 + 2, in bảng, thoát ≠ 0 nếu lệch
docker compose exec web php bin/demo.php exception --db=login_test
docker compose exec web sh bin/check_writes.sh                  # chỉ InventoryService được ghi tồn
```

Test và `bin/demo.php --db=login_test` không đụng tới DB `login`.

## 5. Cấu trúc

| Đường dẫn | Vai trò |
|---|---|
| `index.php` | Xác thực, CSRF, điều hướng module, layout + banner DEMO |
| `modules/*.php` | Màn hình: nhận request → gọi service → hiển thị |
| `src/Service/InventoryService.php` | **Nơi duy nhất** sửa `inventory_balances` và ghi `stock_movements` |
| `src/Service/{Receiving,Outbound,Audit}Service.php` | Nghiệp vụ Inbound (A2), Outbound (A3), Audit (A4) |
| `src/Service/{InventoryQuery,LedgerService,ReconciliationService,EvidenceService}.php` | Đọc tồn, ledger, đối soát, bằng chứng |
| `src/Service/{DemoService,DemoScenarios,DemoRunner,ScenarioService}.php` | Preset/Reset, 3 kịch bản stepper |
| `database/migrations/` | Migration đánh số, có checksum (`schema_migrations`) |
| `database/seeds/` | Seed SIMULATION theo A1 (preset `main`, `exception`) |
| `bin/` | `migrate.php`, `seed_demo.php`, `demo.php`, `check_writes.sh` |
| `tests/` | PHPUnit trên MySQL thật, DB riêng `login_test` |
| `docs/` | Tài liệu nguồn, kiến trúc, nhãn dữ liệu, kịch bản trình bày |
| `_installation/` | Script SQL của source nền – Compose chỉ dùng `02` và `04` |

Chi tiết: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## 6. Đã hoàn thành

- Tồn theo SKU + Location + ledger chỉ-INSERT (trigger chặn UPDATE/DELETE), CHECK `on_hand ≥ 0`, `0 ≤ reserved ≤ on_hand`.
- Inbound A2 đủ 10 bước + E1–E4, Giám sát duyệt chênh lệch / nhận thừa.
- Outbound A3: Reservation FIFO (tách nhiều location), Pick List có quét và số thực lấy, Packing quét đối soát + Tracking ID, nhãn in được, Shipping `LOAD` + `SHIP` một giao dịch, hủy đơn trả hàng về location gốc, `oms_outbox`.
- Audit A4: cycle count C1–C6, đếm mù, đếm lại, duyệt Adjustment; pick thiếu ⇒ khóa phân bổ, tự tạo phiếu kiểm kê, hủy Reserved dư, phân bổ lại hoặc backorder.
- Đối soát, Bằng chứng, Bảng điều khiển Demo (preset, Reset, stepper 3 kịch bản, E1–E4), trang Về dữ liệu, banner phạm vi.
- `products.qty` đã bỏ (migration 007); bảng kế thừa `stock_picking`, `stock_move`, `inventory_audit` đóng băng, chỉ đọc.
- Bảo mật: CSRF mọi POST, prepared statement, escape output, vai trò Giám sát / Nhân viên, không còn fallback root trong `config/db.php`, xác minh chữ ký Google ID token khi đăng ký bằng Google.

## 7. Hạn chế

- FIFO ở **mức cặp** (SKU, Location) qua `first_in_at`, không có lot; `first_in_at` chỉ gán khi cặp từ 0 lên dương.
- Một kho demo, đúng 7 location của A1; không có khu hàng lỗi (hàng lỗi theo dõi trên phiếu QC, không vào tồn).
- Mỗi SKU chỉ một dòng trên ASN và trên đơn xuất.
- `oms_outbox` chỉ ghi sự kiện, không gọi hệ thống OMS thật; nhãn vận đơn là trang HTML in được, không có PDF.
- Reset trong ứng dụng xóa toàn bộ dữ liệu giao dịch WMS của DB đang dùng (không đụng tài khoản, đối tác, bảng kế thừa) – chỉ bật trên DB demo.
- Google Login và Trợ lý AI (Gemini) chưa được cấu hình/kiểm thử; Trợ lý AI ẩn khi thiếu `GEMINI_API_KEY`.
- Secret Google và một Gemini key từng lộ trong lịch sử repo FlowLink-SCM (upstream) – chủ tài khoản cần thu hồi. Lịch sử `main` của WMS_Tool không chứa các commit đó.
- `composer audit` báo advisory ở thư viện phụ thuộc của `google/apiclient` (kế thừa); chưa nâng cấp trong PR này.
