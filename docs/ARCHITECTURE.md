# Kiến trúc WMS Tool (demo)

PROPOSED SOLUTION của nhóm cho prototype. Tài liệu nguồn: `docs/source/` (foundation, A1–A4).

## 1. Lớp

```
Trình duyệt ─► index.php (đăng nhập, CSRF, vai trò, layout + banner)
                 └─► modules/<trang>.php  (đọc request → gọi service → HTML; POST theo PRG)
                        └─► src/Service/*Service.php  (nghiệp vụ, transaction)
                               └─► InventoryService  ── nơi DUY NHẤT ghi inventory_balances + stock_movements
CLI: bin/migrate.php · bin/seed_demo.php · bin/demo.php · bin/check_writes.sh
```

`bin/check_writes.sh` (chạy trong test `tests/Phase7/ArchitectureTest.php`) bảo đảm không file nào ngoài `InventoryService` ghi hai bảng tồn, và không còn mã dùng `products.qty`.

## 2. Mô hình dữ liệu

| Bảng | Vai trò |
|---|---|
| `warehouses`, `warehouse_locations` | 1 kho demo, 7 location (`zone_type` storage/staging/dock, `is_allocatable` chỉ A-01, B-01) |
| `products` | Dữ liệu chủ (size, màu, đơn vị, location put-away gợi ý). Không còn cột tồn |
| `inventory_balances` | On-hand / Reserved theo (SKU, Location); `alloc_locked`, `first_in_at`; CHECK `on_hand ≥ 0 AND reserved ≥ 0 AND reserved ≤ on_hand` |
| `stock_movements` | Ledger chỉ-INSERT (trigger chặn UPDATE/DELETE). `qty > 0`; hướng do `from`/`to` (NULL = ngoài kho); `reserved_moved`; `changes_on_hand`; chứng từ; người thực hiện |
| `goods_receipts`, `goods_receipt_items`, `qc_records`, `putaway_tasks` | Inbound A2 |
| `outbound_orders`, `outbound_order_lines`, `reservations`, `pick_lists`, `pick_list_lines`, `shipments`, `oms_outbox` | Outbound A3 |
| `cycle_counts` | Audit A4 |
| `demo_state`, `schema_migrations` | Trạng thái Bảng điều khiển Demo; migration |
| `stock_picking`, `stock_move`, `inventory_audit` | Kế thừa FlowLink-SCM – đóng băng, chỉ đọc |

### Loại movement

| `movement_type` | Từ → Đến | Đổi tổng tồn |
|---|---|---|
| `OPENING` | (ngoài kho) → location đầu kỳ | khởi tạo |
| `RECEIVE` | (ngoài kho) → RECEIVING | + |
| `PUTAWAY` | RECEIVING → Storage | – |
| `RESERVE` / `UNRESERVE` | giữ / hủy giữ tại location (không di chuyển hàng) | – |
| `PICK` | Storage → PICK-STG | – |
| `PACK_IN` / `PACK_OUT` | PICK-STG → PACK-01 → OUT-STG | – |
| `LOAD` + `SHIP` | OUT-STG → DOCK → (ra khỏi kho), một transaction, hiển thị gộp "Shipping" | − |
| `ADJUST` | tại location Storage được kiểm | ± |
| `RESTOCK` | staging → location gốc (hủy đơn sau khi pick) | – |

Đối soát cặp: `on_hand = Σ qty vào − Σ qty ra` (bỏ RESERVE/UNRESERVE); `reserved = Σ reserved_moved vào − ra`. Toàn kho: `Tồn cuối = OPENING + RECEIVE − SHIP ± ADJUST`.

## 3. Business rules → nơi hiện thực

| Rule | Hiện thực |
|---|---|
| BR-1 Tổng tồn chỉ tăng ở Xác nhận Receiving (A2-R1, R5) | `ReceivingService::confirmReceiving` → `InventoryService::receive`; ASN không ghi tồn |
| BR-2 Ghi nhận số thực nhận đạt (A2-R6) | `received_qty` = QC đạt (phần thừa chỉ khi Giám sát duyệt) |
| BR-3 RECEIVING không khả dụng (A2-R3, R4) | `is_allocatable = 0`; `InventoryQuery` Available = 0 ngoài Storage |
| BR-4 Put-away RECEIVING → Storage, chặn sai đích (A2-R2, E4) | `ReceivingService::confirmPutaway` (quét) + `InventoryService::MOVE_RULES` |
| BR-5 Idempotent | khóa phiếu/task `FOR UPDATE`, kiểm trạng thái trước khi ghi |
| BR-6 Reservation FIFO, không vượt Available | `OutboundService::planFifo/reserve`, kiểm lại dưới khóa trong `InventoryService::reserve` |
| BR-7 Pick/Pack chỉ đổi location, Reserved đi theo hàng | `InventoryService::move` với `reservedQty` |
| BR-8 Ship giảm On-hand + Reserved đúng một lần | `OutboundService::ship` kiểm `SHIPPED`; `InventoryService::ship` |
| BR-9 Quét sai bị từ chối | `confirmPutaway`, `confirmPick`, `packOut`, `ship` |
| BR-10 Ledger chỉ-INSERT | trigger `trg_stock_movements_no_update/no_delete`; Reset dùng `TRUNCATE` |
| BR-11 Không âm, reserved ≤ on_hand | CHECK `chk_balance_qty` + kiểm trong service |
| BR-12 Một transaction, khóa theo (sku, location_id) | `InventoryService::transactional/lockPairs` |
| BR-13 Adjustment (−) hủy Reserved dư trước | `AuditService::releaseUnbackedReservations` → `approve` |
| BR-14 Cycle count chỉ Storage | `AuditService::createCount`, `InventoryService::adjust` |

## 4. Trạng thái

- Phiếu nhập: `EXPECTED → CHECKED_IN → COUNTED → QC_DONE → RECEIVED → PUTAWAY_DONE → CLOSED` (+ `CANCELLED`).
- Đơn xuất: `CREATED → RESERVED → PICKING → PICKED → PACKING → PACKED → SHIPPED` (+ `CANCELLED`). A3 ghi "CREATED/FENDING", "RESERVED/ALLOCATED", "SHIPPED/COMPLETED" – chọn `CREATED`, `RESERVED`, `SHIPPED`. Pick thiếu: giữ `PICKING` + `hold_reason` (không thêm trạng thái).
- Phiếu kiểm kê: `OPEN → CLOSED_MATCH | RECOUNT → CLOSED_MATCH | PENDING_APPROVAL → ADJUSTED`.

## 5. Vai trò

`users.role`: `supervisor` (Giám sát kho) / `operator` (Nhân viên kho). Cần Giám sát: xác nhận Receiving khi có chênh lệch, duyệt nhận thừa, đóng phiếu nhập, tạo phiếu và duyệt Adjustment, hủy Reserved khi kiểm kê, Reset và chọn kịch bản demo.

## 6. Migration và hạ tầng

- Service Compose `migrate` (image web, chỉ service này có `DB_ROOT_PASS`) chạy `composer install` + `bin/migrate.php` mỗi lần `up`. Root cần cho `CREATE TRIGGER` khi binlog bật (app_user gặp lỗi 1419 – đã kiểm), `CREATE DATABASE login_test` và `GRANT`.
- `000_baseline` nhận ra DB do `docker-entrypoint-initdb.d` tạo; DB mới thì chạy đúng 3 script Compose dùng.
- Checksum (bỏ `\r`) phát hiện file migration đã áp dụng bị sửa. Forward-only; mỗi file ghi lệnh `down` trong comment.
- Test chạy trên `login_test` (bootstrap từ chối DB không có hậu tố `_test`).

## 7. Kiểm thử

`tests/` – 47 test PHPUnit trên MySQL thật: golden Inbound A2, ledger A4 §4, ngoại lệ A4 §3.3 (11 bước, 315 → 261), E1–E4, ship/receive/put-away hai lần, reserve vượt Available, hai tiến trình reserve song song, rollback giữa chừng, trigger, CHECK, Available = 0 ở staging/dock, 3 kịch bản stepper + 6 bằng chứng, kiến trúc ghi tồn.
