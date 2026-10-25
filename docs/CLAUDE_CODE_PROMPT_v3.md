# PROMPT CHO CLAUDE CODE — Hoàn thiện WMS Tool DEMO cho seminar (v3)

> **Cách dùng:** mở terminal trong thư mục repo `WMS_Tool` đã clone, bật **Plan Mode** (Shift+Tab), chạy `claude`. Khuyến nghị lưu file này vào `docs/CLAUDE_CODE_PROMPT_v3.md` rồi chỉ gửi một câu: *"Đọc docs/CLAUDE_CODE_PROMPT_v3.md và thực hiện mục 13."* (hoặc dán toàn bộ phần bên dưới vạch `-----`).
>
> **Trước khi gửi**, copy 5 tài liệu vào repo (Claude Code đọc trực tiếp, đây là nguồn sự thật):
>
> | File gốc | Đặt vào repo |
> |---|---|
> | Giới thiệu DN – Bài toán – Hướng đi.docx | `docs/source/foundation.docx` |
> | A1_Boi_canh_va_du_lieu_demo.txt | `docs/source/A1_boi_canh_du_lieu.txt` |
> | A2_Kich_ban_Inbound.txt | `docs/source/A2_inbound.txt` |
> | Kịch bản Outbound-WMS-EC214.docx (= A3) | `docs/source/A3_outbound.docx` |
> | A4_Kich_ban_Inventory_Audit.txt | `docs/source/A4_inventory_audit.txt` |
>
> **Thay đổi so với v2:** đổi tên rule thành BR-x (tránh trùng R1–R6 của A2); Giám sát duyệt mọi chênh lệch Receiving; chặn UPDATE/DELETE bằng trigger (không dùng REVOKE) kèm lưu ý binlog; test chạy trên DB riêng; migration baseline; golden bổ sung cho preset `exception` (315 → 261); điền sẵn đáp án mặc định cho các câu hỏi mở; bổ sung hiện trạng repo (mục 0.1).

-----

## 0. Vai trò, mục tiêu và cách làm việc

Bạn là kỹ sư phần mềm chính của dự án **WMS Tool** (seminar *Hệ thống quản lý nhà kho – UIT*). Hãy biến source nền trong repo thành một **WMS demo chạy được, bám sát từng con số trong 5 tài liệu nguồn**, dùng để trình diễn trong buổi seminar, rồi push lên GitHub qua Pull Request.

**Tuyên bố phạm vi (phải xuất hiện trong ứng dụng và tài liệu):** Case study Seedcom Logistics chỉ là **bối cảnh** để xây dựng demo. Mọi kịch bản, SKU, location, số lượng là **mô phỏng của nhóm**. Công cụ này **không phản ánh hệ thống hay quy trình thật của Seedcom và không khẳng định Seedcom sử dụng nó.** Tuyệt đối không viết, đặt tên hay trình bày bất cứ thứ gì ám chỉ ngược lại (ví dụ logo/tên "Seedcom WMS", "hệ thống của Seedcom").

Quy trình bắt buộc:

1. **Đọc trước, code sau.** Đọc 5 tài liệu nguồn, rồi `README.md`, `docker-compose.yml`, `Dockerfile`, `config/db.php`, `database/schema.sql`, `database/init/*.sql`, `_installation/*.sql`, `index.php`, toàn bộ `modules/*.php` và `views/*.php`. Chạy `grep -rn "qty" modules index.php` để biết chỗ nào đọc/ghi `products.qty`.
2. **Vào Plan Mode**: trình bày kế hoạch theo các phase ở mục 9 (file sẽ tạo/sửa/xóa, quyết định thiết kế, câu hỏi còn lại gộp thành một lượt), rồi **chờ tôi duyệt** trước khi code.
3. Làm **từng phase**. Cuối mỗi phase: chạy test, commit, báo cáo ngắn (làm gì, test gì, còn gì). Không sang phase sau khi test phase trước chưa xanh.
4. Giao diện, thông báo lỗi, comment, commit viết **tiếng Việt có dấu (UTF-8)**; tên biến/hàm/bảng dùng tiếng Anh.
5. **Khi tài liệu mâu thuẫn với prompt này, tài liệu thắng** — nêu xung đột trong plan. Khi tài liệu tự mâu thuẫn hoặc thiếu, **không tự bịa**: ghi vào danh sách câu hỏi mở.
6. Quy ước đánh số: rule trong prompt này là **BR-1…BR-14**. Rule **R1–R6 trong A2** là một bộ khác; khi dẫn tài liệu nguồn hãy ghi rõ "A2-R3", "A2-R6"… để không nhầm.

### 0.1 Hiện trạng repo đã biết (README + docker-compose; hãy xác minh lại bằng code thật)

* PHP 8.1 + Apache, **MySQL 8.0** (`image: mysql:8.0` ⇒ CHECK constraint có hiệu lực), InnoDB, Composer, Docker Compose, Adminer. Website `127.0.0.1:8890`, Adminer `127.0.0.1:8891`. Tên project Compose `wms-tool-integration`.
* Database dùng chung `login`; ứng dụng kết nối bằng `app_user` (do `MYSQL_USER` tạo, **toàn quyền trên `login.*`, không có SUPER**); root chỉ dùng cho khởi tạo.
* Compose chỉ mount 3 script vào `docker-entrypoint-initdb.d` theo thứ tự: `_installation/02-create-and-fill-users-table.sql`, `_installation/04-create-partners-and-inventory-audit.sql`, `database/schema.sql`. **Chúng chỉ chạy khi volume database mới được tạo** ⇒ cần cơ chế migration chạy được trên DB đã tồn tại của các thành viên.
* 12 bảng: kế thừa `users, products, partners, stock_picking, stock_move, inventory_audit`; WMS mới `warehouses, warehouse_locations, inventory_balances, stock_movements, goods_receipts, goods_receipt_items`.
* `web` mount `.:/var/www/html` và **`vendor` là named volume** (`wms_vendor_data`): thêm thư viện (PHPUnit…) phải `composer install` **bên trong container** `web`.
* `_installation/` có các định nghĩa bảng trùng nhau — không chạy toàn bộ thư mục. `database/init/01-base-tables.sql` **không được Compose dùng** (đưa vào mục (d) của plan: xóa hay giữ).
* README mục "Công việc còn lại" liệt kê: giao diện vẫn dùng `products.qty`; chưa tích hợp `inventory_balances`/`stock_movements`; chưa thống nhất `stock_picking`/`stock_move`; Google Login và Gemini chưa cấu hình; secret Google từng lộ trong source gốc.

## 1. Bài toán và thứ demo phải chứng minh

**Câu bài toán (PROPOSED SOLUTION của nhóm):** Làm thế nào để quản lý chính xác tồn kho và dòng di chuyển hàng hóa trong trung tâm phân phối, đồng thời có đủ khả năng quan sát và kiểm soát gần thời gian thực để xử lý khối lượng đơn hàng tăng trong môi trường omni-channel?

Flow cần hiện thực (Stock Movement chạy xuyên suốt; Inventory Audit/Adjustment là nhánh đối soát):

```
Receiving → Put-away → Inventory → Outbound Order → Reservation → Picking → Packing → Shipping
```

Ba năng lực → thứ phải xem được trên màn hình:

| Năng lực | Câu hỏi | Demo cần có |
|---|---|---|
| Inventory Visibility | Hàng gì, bao nhiêu, ở đâu? | Bảng On-hand / Reserved / Available theo **SKU + Location** |
| Accuracy & Traceability | Vì sao số tồn đổi? | Ledger Stock Movement + Audit/Adjustment; trang **Đối soát** |
| Order Fulfillment Control | Hàng nào còn phân bổ được? | Available Stock, Reservation, đơn có trạng thái |

**Sáu kết quả prototype phải chứng minh** (làm thành trang **"Bằng chứng"** với dấu ✓/✗ tính trực tiếp từ dữ liệu, không hard-code):

1. Truy vấn một SKU thấy đang có bao nhiêu và nằm ở location nào.
2. Receiving tăng tồn đúng một lần; Put-away không tăng tổng tồn.
3. Mọi thay đổi số lượng/vị trí đều có Stock Movement.
4. Reservation không vượt Available; Picking/Packing không giảm tổng On-hand.
5. Shipping giảm On-hand đúng một lần và trạng thái đơn nhất quán.
6. Audit/Adjustment giải thích và ghi nhận được chênh lệch.

**North Star:** tồn kho hiện tại phải được giải thích bằng lịch sử giao dịch và vị trí hàng — với mọi cặp (SKU, Location), `on_hand` = tổng đại số các dòng movement (kể cả dòng `OPENING`) liên quan đến cặp đó.

## 2. Dữ liệu SIMULATION chuẩn (từ A1 — dùng đúng, không tự đổi)

* Đơn vị tính: **Cái (PCS)**. Quy tắc lấy hàng: **FIFO** (hàng Áo/Quần không có hạn dùng ⇒ **không làm FEFO, không thêm lot/hạn dùng**). A3 nêu "FEFO/FIFO hoặc tối ưu quãng đường"; chọn **FIFO** theo A1 và nêu trong plan.
* **1 warehouse demo** (ví dụ `WH-DEMO`, tên ghi rõ "Kho demo (mô phỏng)").

**Master Location (7):**

| Mã | Vai trò | `zone_type` | Tính vào tồn khả dụng? |
|---|---|---|---|
| RECEIVING | Khu nhận hàng | staging | Không |
| A-01 | Kệ lưu trữ A-01 | storage | **Có** |
| B-01 | Kệ lưu trữ B-01 | storage | **Có** |
| PICK-STG | Khu chờ sau khi pick | staging | Không |
| PACK-01 | Bàn đóng gói | staging | Không |
| OUT-STG | Khu chờ xuất | staging | Không |
| DOCK | Cửa xuất | dock | Không |

**8 SKU và tồn đầu kỳ** (chỉ A-01, B-01 có tồn; 5 location còn lại = 0):

| SKU | Tên | Size/Màu | Location | Tồn đầu |
|---|---|---|---|---|
| AO-001-M-DEN | Áo thun basic | M / Đen | A-01 | 50 |
| AO-001-L-DEN | Áo thun basic | L / Đen | A-01 | 40 |
| AO-002-M-TRG | Áo sơ mi trơn | M / Trắng | A-01 | 30 |
| AO-002-L-TRG | Áo sơ mi trơn | L / Trắng | A-01 | 25 |
| QUAN-001-M-XAM | Quần jogger | M / Xám | B-01 | 45 |
| QUAN-001-L-XAM | Quần jogger | L / Xám | B-01 | 60 |
| QUAN-002-M-DEN | Quần tây | M / Đen | B-01 | 35 |
| QUAN-002-L-DEN | Quần tây | L / Đen | B-01 | 20 |

Tổng: A-01 = 145, B-01 = 160, tổng toàn kho = **305**. Áo → gợi ý put-away A-01; Quần → B-01.

Tồn đầu kỳ phải được nạp bằng movement loại **`OPENING`** (không phải UPDATE thẳng) để đối soát vẫn đúng. `OPENING` **không** tính là "Nhập" trong công thức tồn cuối.

## 3. Định nghĩa dùng thống nhất (từ A2, A3, A4)

* **On-hand (cặp SKU+Location):** số hệ thống ghi nhận tại cặp đó. **Tổng tồn** = tổng On-hand ở **mọi** location.
* **Reserved:** số đã giữ cho đơn tại cặp đó. Reserved **đi theo hàng** khi pick/pack (khi pick A-01 → PICK-STG thì Reserved nằm ở cặp SKU+PICK-STG).
* **Available (cặp)** = nếu location **allocatable** (storage) thì `On-hand − Reserved`, ngược lại **= 0** (hiển thị 0 ở staging/dock dù On-hand > 0).
* **Available (SKU / toàn kho)** = **tổng Available của các cặp** (không phải `tổng On-hand − tổng Reserved`). Đây là điểm dễ sai: sau Receiving, SKU AO-001-M-DEN có On-hand 70 nhưng Available **50** vì 20 cái còn ở RECEIVING; toàn kho là Tổng tồn 350, khả dụng **305**.
* Công thức tồn: `Tồn cuối = Tồn đầu + Nhập (Receiving) − Xuất (Shipping) ± Adjustment`.

### Bảng movement (A4 §1.1) — nguồn sự thật của rule

| Movement (`movement_type`) | Từ → Đến | Đổi TỔNG TỒN? | Đổi location? |
|---|---|---|---|
| `RECEIVE` | (ngoài kho) → RECEIVING | **Có (+)** | Có |
| `PUTAWAY` | RECEIVING → A-01 / B-01 | Không | Có |
| `RESERVE` / `UNRESERVE` | (không di chuyển hàng) | Không | Không (chỉ đổi Reserved) |
| `PICK` | Storage → PICK-STG | Không | Có |
| `PACK_IN` | PICK-STG → PACK-01 | Không | Có |
| `PACK_OUT` | PACK-01 → OUT-STG | Không | Có |
| `LOAD` | OUT-STG → DOCK | Không | Có |
| `SHIP` | DOCK → (ra khỏi kho) | **Có (−)** | Có |
| `ADJUST` | Tại location được kiểm | **Có (±)** | Không |
| `OPENING` | (ngoài kho) → location đầu kỳ | Khởi tạo | — |

Tổng tồn **chỉ** đổi ở 3 loại: `RECEIVE (+)`, `SHIP (−)`, `ADJUST (±)`. `LOAD` và `SHIP` thực hiện trong **một** transaction khi xác nhận ship, để OUT-STG trống sau khi ship như bảng A4 §2.4. A4 coi Shipping là **một** bước (OUT-STG → DOCK → ra khỏi kho): ledger UI phải gộp hiển thị `LOAD`+`SHIP` thành một dòng "Shipping" (có thể bung chi tiết) để khớp bảng A4 khi trình bày. `RESERVE/UNRESERVE` ghi vào ledger với cờ "không đổi On-hand" để vẫn truy vết được.

**Quy ước dữ liệu movement:** `from_location_id` và `to_location_id` cho phép NULL (= "ngoài kho"); `qty > 0` luôn dương; dấu của `ADJUST` được xác định bằng hướng (ADJUST − : from = location, to = NULL; ADJUST + : from = NULL, to = location). Nhờ vậy `on_hand` của cặp = Σ(vào) − Σ(ra) trên các dòng có `changes_on_hand` hoặc dịch chuyển.

## 4. Business rules (ép ở tầng service **và** tầng DB)

**Inbound (A2):**
* BR-1. Tổng tồn **chỉ tăng** ở "Xác nhận Receiving"; ASN/PO chỉ là số dự kiến, **không** đổi tồn (A2-R1, A2-R5).
* BR-2. Số ghi nhận là **thực nhận ĐẠT** (sau kiểm đếm + QC), không phải số ASN (A2-R6).
* BR-3. Hàng ở RECEIVING tính vào tổng tồn nhưng **không** khả dụng; chỉ được phân bổ cho đơn **sau put-away** vào Storage (A2-R3, A2-R4).
* BR-4. Put-away chỉ chuyển RECEIVING → A-01/B-01 (RECEIVING giảm bao nhiêu, Storage tăng bấy nhiêu) (A2-R2); **chặn** nếu location đích không phải Storage hợp lệ hoặc không khớp task; không đổi tồn khi bị chặn.
* BR-5. Xác nhận Receiving/Put-away **idempotent**: làm lần hai không cộng/chuyển thêm.

**Outbound (A3 + A4):**
* BR-6. Reservation chỉ từ cặp **allocatable** và **không** vượt Available; chọn location theo **FIFO** (cặp có `first_in_at` sớm nhất trước; hòa thì theo mã location tăng dần) và có thể tách một dòng đơn qua nhiều location. `first_in_at` chỉ gán khi `on_hand` của cặp đi từ 0 lên dương, **không** cập nhật khi cộng thêm vào cặp đã có hàng (xấp xỉ ở mức cặp, không có lot — ghi vào "Hạn chế").
* BR-7. Picking/Packing không giảm tổng On-hand, chỉ đổi location; Reserved đi theo hàng.
* BR-8. Chỉ Shipping giảm On-hand **và** Reserved tương ứng, **đúng một lần**; ship lần hai bị chặn.
* BR-9. Quét sai SKU/location bị từ chối, không đổi dữ liệu.

**Toàn vẹn & audit:**
* BR-10. Mọi thay đổi số lượng hoặc vị trí sinh movement; `stock_movements` **chỉ INSERT**. Chặn bằng **trigger** `BEFORE UPDATE` / `BEFORE DELETE` với `SIGNAL SQLSTATE '45000'`. **Không** dùng REVOKE (vì `app_user` có toàn quyền trên `login.*`, MySQL không trừ quyền ở mức bảng). Nút Reset dùng `TRUNCATE` (không kích hoạt trigger; tắt `FOREIGN_KEY_CHECKS` tạm thời trong phiên). Sửa sai bằng bút toán đảo, không sửa dòng cũ.
* BR-11. DB không bao giờ có `on_hand < 0`, `reserved < 0`, `reserved > on_hand` (CHECK constraint — hiệu lực vì MySQL 8.0 — kèm kiểm tra trong service). Có test cố INSERT/UPDATE vi phạm và kỳ vọng lỗi.
* BR-12. Mọi thao tác nhiều bước chạy **một transaction**, khóa dòng `SELECT … FOR UPDATE` theo thứ tự cố định (sku, location_id) để tránh deadlock; lỗi ⇒ rollback toàn bộ.
* BR-13. Adjustment chỉ đổi On-hand tại cặp được kiểm, **không** đổi location. Nếu Adjustment (−) làm `On-hand < Reserved` thì phần Reserved dư phải bị hủy và đơn tương ứng được phân bổ lại (hủy Reserved **trước** khi hạ On-hand để không vi phạm BR-11).
* BR-14. Cycle count chỉ áp dụng cặp **Storage**; hàng ở staging/dock không nằm trong cycle count của demo.

## 5. Kịch bản nghiệp vụ chi tiết

### 5.1 Inbound (A2) — ASN-DEMO-001
10 bước (người thực hiện → vai trò): 1 Inbound Coordinator tạo ASN dự kiến; 2 Receiver check-in xe, dỡ hàng (hệ thống chưa ghi nhận location); 3 Receiver kiểm đếm, ghi chênh lệch; 4 QC phân loại đạt/lỗi; 5 **Xác nhận Receiving** (tăng On-hand vào RECEIVING; **Giám sát kho duyệt nếu có chênh lệch**); 6 hệ thống tạo task put-away, gợi ý A-01 (Áo) / B-01 (Quần); 7–8 nhân viên put-away nhận task, **quét** location nguồn, SKU, location đích (hệ thống kiểm tra); 9 **Xác nhận Put-away**; 10 Giám sát đóng phiếu, đối soát ASN – thực nhận – put-away.

**Quy tắc duyệt:** đường chuẩn (thực nhận = ASN, QC đạt 100%) **không cần** duyệt. Khi có **bất kỳ chênh lệch nào** (thiếu, thừa, hàng lỗi) thì xác nhận Receiving cần Giám sát duyệt; riêng **nhận thừa** phải có duyệt rõ ràng mới được ghi nhận phần thừa (E2).

Trạng thái phiếu đề xuất: `EXPECTED → CHECKED_IN → COUNTED → QC_DONE → RECEIVED → PUTAWAY_DONE → CLOSED` (+ `CANCELLED`).

Dữ liệu ASN-DEMO-001: dòng 1 AO-001-M-DEN ×20 → A-01; dòng 2 AO-002-L-TRG ×10 → A-01; dòng 3 QUAN-002-L-DEN ×15 → B-01; tổng 45. Đường đi chuẩn: nhận đủ 45, QC đạt 100%.

**Ngoại lệ bắt buộc có (A2 §3):**
* **E1 nhận thiếu** (ví dụ ASN 20, nhận 18): ghi chênh lệch −2 và lý do ở bước đếm; xác nhận Receiving chỉ 18; put-away đúng 18; phần thiếu **mở theo dõi** (chờ bổ sung hoặc đóng có lý do); không tạo tồn ảo.
* **E2 nhận thừa** (ASN 10, nhận 12): mặc định chỉ nhận 10, 2 thừa chờ quyết định; nếu **Giám sát duyệt** nhận thừa thì xác nhận 12 và ghi chênh lệch +2. **Không tự nhận thừa khi chưa duyệt.**
* **E3 hàng lỗi** (dòng 3: 15, đạt 13, lỗi 2): lập phiếu QC cho 2 cái lỗi; xác nhận Receiving 13; chỉ put-away 13. Hàng lỗi **không vào tồn** (master location không có khu hàng lỗi): theo dõi trên **phiếu QC**, chờ trả nhà cung cấp. Không thêm location mới.
* **E4 quét sai location put-away**: chặn, không đổi tồn.

### 5.2 Outbound (A3) — ORD-DEMO-001
Luồng: `CREATED → RESERVED → PICKING → PICKED → PACKING → PACKED → SHIPPED` (+ `CANCELLED`). A3 ghi "CREATED/FENDING" (hiểu là `PENDING`), "RESERVED/ALLOCATED", "SHIPPED/COMPLETED": chọn **một** tên cho mỗi trạng thái là `CREATED`, `RESERVED`, `SHIPPED` và nêu trong plan.

| Bước | Hành động | On-hand | Available (toàn kho) |
|---|---|---|---|
| Order Created | Ghi Order ID, SKU, Requested Qty, địa chỉ/kênh; kiểm tra sơ bộ (SKU tồn tại, qty > 0, không trùng SKU) | Không đổi | Không đổi |
| Reservation | Kiểm `Requested ≤ Available`, tạo reservation, chỉ định location theo FIFO | Không đổi | **Giảm** |
| Picking | Pick List (SKU, Location, qty), quét SKU/location; movement Storage → PICK-STG | Không đổi | Không đổi |
| Packing | Quét SKU đối soát đơn, đóng thùng, sinh **Tracking ID/Shipping Label**; `PACK_IN` rồi `PACK_OUT` → OUT-STG | Không đổi | Không đổi |
| Shipping | Quét tracking/mã xuất tại DOCK; `LOAD` + `SHIP`; ghi sự kiện vào `oms_outbox` (mô phỏng báo OMS) | **Giảm** | Không đổi |

Dữ liệu: **ORD-DEMO-001 = 30 cái AO-001-M-DEN**, kênh "Kênh online (mô phỏng)", địa chỉ "Địa chỉ mẫu – không có thật".

### 5.3 Audit (A4)
**Cycle count C1–C6:** C1 Giám sát tạo phiếu cho cặp (SKU+Location), hệ thống **chốt tồn hệ thống tại thời điểm tạo phiếu**; C2 nhân viên nhập tồn thực tế (**chế độ đếm mù**: ẩn số hệ thống khi nhập); C3 tính chênh lệch = thực tế − hệ thống, bằng 0 thì đóng phiếu; C4 chênh lệch ≠ 0 ⇒ **recount** + rà soát giao dịch gần nhất của cặp; C5 Giám sát **duyệt** → tạo `ADJUST` đúng bằng chênh lệch kèm lý do; C6 đóng phiếu, lưu ledger.
Ví dụ: AO-001-M-DEN + A-01 sau ship: hệ thống 40, thực tế 38, recount 38 ⇒ `ADJUST −2` ⇒ On-hand 38.

**Ngoại lệ bắt buộc — pick thiếu hàng (A4 §3.3), kịch bản ĐỘC LẬP ORD-DEMO-002:**
Giả định ghi rõ trong tài liệu: AO-001-M-DEN có **A-01 = 50, B-01 = 10**; đơn yêu cầu **48**. Luồng: Reserve 48 tại A-01 → Pick thực tế chỉ lấy được **44** → hệ thống **khóa phân bổ** cặp (AO-001-M-DEN, A-01) và **tự tạo phiếu kiểm kê** (trigger type `PICK_SHORTAGE`) → recount: hệ thống 6, thực tế 0, chênh lệch −6 → **hủy** 4 Reserved không còn hàng đỡ → `ADJUST −6` → **mở khóa** và **Reservation lại 4 từ B-01** → Pick 4 → Pack → Ship 48. Nếu **không còn location nào đủ hàng**: phần thiếu chuyển `backorder`, không tạo tồn ảo.
Thiết kế đi kèm: Pick UI cho nhập **số lượng thực lấy** (≤ số trên Pick List); khi thiếu, đơn **giữ trạng thái `PICKING` + cờ ngoại lệ/`hold_reason`** (A4 không quy định trạng thái riêng — không tạo thêm trạng thái).

## 6. Số liệu chuẩn để làm **test vàng** (golden tests — phải khớp từng số)

Viết thêm đối chiếu với **chính file nguồn** (đọc lại khi viết test) để chắc không chép sai.

**Inbound ASN-DEMO-001 (toàn kho)** — cột: RECEIVING / A-01 / B-01 / Tổng tồn / Tồn khả dụng

| Mốc | RECEIVING | A-01 | B-01 | Tổng | Khả dụng |
|---|---|---|---|---|---|
| Đầu kỳ | 0 | 145 | 160 | 305 | 305 |
| Sau bước 1–4 | 0 | 145 | 160 | 305 | 305 |
| Sau bước 5 (Receiving) | 45 | 145 | 160 | **350** | 305 |
| Sau 9a (AO-001-M-DEN 20 → A-01) | 25 | 165 | 160 | 350 | 325 |
| Sau 9b (AO-002-L-TRG 10 → A-01) | 15 | 175 | 160 | 350 | 335 |
| Sau 9c (QUAN-002-L-DEN 15 → B-01) | 0 | 175 | 175 | 350 | 350 |

Sau đóng phiếu: ASN 45 = thực nhận đạt 45 = put-away 45, chênh lệch 0. Theo SKU: AO-001-M-DEN 70, AO-002-L-TRG 35, QUAN-002-L-DEN 35.

**Ledger xuyên suốt AO-001-M-DEN (A4 §4)** — tổng theo SKU: On-hand / Reserved / Available. **Lưu ý: ledger này bắt đầu từ trạng thái đã nhận hàng nên *nối tiếp kịch bản Inbound*.**

| # | Bước | On-hand | Reserved | Available |
|---|---|---|---|---|
| 0 | Đầu kỳ (A-01 = 50) | 50 | 0 | 50 |
| 1 | Receiving +20 (RECEIVING = 20) | 70 | 0 | **50** |
| 2 | Put-away 20 → A-01 | 70 | 0 | 70 |
| 3 | Order created ORD-DEMO-001 (30) | 70 | 0 | 70 |
| 4 | Reservation 30 tại A-01 | 70 | 30 | 40 |
| 5 | Picking → PICK-STG (A-01: 40/0/40; PICK-STG: 30/30/0) | 70 | 30 | 40 |
| 6 | Packing (1) → PACK-01 | 70 | 30 | 40 |
| 7 | Packing (2) → OUT-STG | 70 | 30 | 40 |
| 8 | Shipping 30 | 40 | 0 | 40 |
| 9 | Cycle count: hệ thống 40, thực tế 38 (chưa đổi tồn) | 40 | 0 | 40 |
| 10 | Adjustment −2 | 38 | 0 | 38 |

Công thức: 50 + 20 − 30 − 2 = 38. Toàn kho cùng mốc: sau Inbound 350 (A-01 175, B-01 175); sau Picking 30: A-01 145, PICK-STG 30, tổng 350; sau Shipping 30: tổng **320**; sau Adjustment −2: A-01 143, B-01 175, tổng **318** (= 305 + 45 − 30 − 2). Bảng cặp sau Inbound + Reservation 30 (A4 §2.3): AO-001-M-DEN @A-01 = 70/30/40; mọi cặp còn lại Reserved 0; tổng 350 / 30 / **320**.

**Kịch bản ngoại lệ ORD-DEMO-002 (A4 §3.3)** — tổng SKU AO-001-M-DEN: On-hand / Reserved / Available. Chạy trên **preset `exception`** (tổng kho đầu kỳ = 305 + 10 = **315**).

| Bước | Sự kiện | On-hand | Reserved | Available | Chi tiết cặp |
|---|---|---|---|---|---|
| 0 | Ban đầu | 60 | 0 | 60 | A-01 50; B-01 10 |
| 1 | Reservation 48 | 60 | 48 | 12 | A-01: 50/48/2; B-01: 10/0/10 |
| 2 | Pick thực tế 44 → PICK-STG | 60 | 48 | 12 | A-01: 6/4/2; PICK-STG: 44/44/0 |
| 3 | Phát hiện thiếu 4, khóa cặp A-01, tạo phiếu kiểm kê | 60 | 48 | 12 | — |
| 4 | Cycle count: HT 6, thực tế 0, lệch −6 (recount 0) | 60 | 48 | 12 | — |
| 5 | Hủy 4 Reserved | 60 | 44 | 16 | A-01: 6/0/6 |
| 6 | `ADJUST −6` tại A-01 | 54 | 44 | 10 | A-01: 0/0/0; B-01: 10/0/10 |
| 7 | Reservation lại 4 từ B-01 | 54 | 48 | 6 | B-01: 10/4/6 |
| 8 | Pick 4 từ B-01 | 54 | 48 | 6 | B-01: 6/0/6; PICK-STG: 48/48/0 |
| 9 | Packing (1)+(2) | 54 | 48 | 6 | OUT-STG 48 |
| 10 | Shipping 48 | 6 | 0 | 6 | B-01: 6/0/6 |

Công thức SKU: 60 − 48 − 6 = 6. **Công thức toàn kho (preset `exception`): 315 − 48 − 6 = 261.**

Test **bắt buộc** thêm: E1–E4; quét sai location/SKU; ship 2 lần; xác nhận Receiving 2 lần; reserve vượt Available; 2 kết nối song song cùng reserve (không bao giờ `reserved > on_hand`); rollback khi ép lỗi giữa chừng; đối soát `SUM(movements)` khớp `inventory_balances` cho mọi cặp sau mỗi kịch bản; "Available hiển thị = 0 ở staging/dock"; trigger chặn UPDATE/DELETE `stock_movements`; CHECK chặn `on_hand < 0`, `reserved > on_hand`; nhận thừa khi chưa duyệt không đổi tồn; duyệt Receiving khi có chênh lệch.

## 7. Thiết kế đề xuất (PROPOSED SOLUTION — được điều chỉnh nếu có lý do, nêu trong plan)

**Kiến trúc:** giữ PHP thuần (không thêm framework). Tách lớp service khỏi giao diện:

```
src/Service/InventoryService.php   # NƠI DUY NHẤT được sửa inventory_balances và ghi stock_movements
src/Service/{Receiving,Putaway,Outbound,Audit,Demo}Service.php
src/Repository/…  src/Support/{Csrf,Flash,Auth,Roles}.php
modules/*.php                      # chỉ nhận request → gọi service → render view
bin/migrate.php  bin/demo.php  bin/seed_demo.php
database/migrations/NNN_*.sql      # idempotent, có bảng schema_migrations; 000 = baseline (schema.sql hiện có)
database/seeds/{demo_main,demo_exception}.json
tests/                             # PHPUnit trên MySQL thật trong Docker, DB RIÊNG (login_test)
docs/{ARCHITECTURE,DATA_LABELS,DEMO_SCRIPT}.md
```

Không module nào được `UPDATE inventory_balances` hay `INSERT stock_movements` trực tiếp. Dùng Composer autoload `psr-4`. Cài PHPUnit bằng `docker compose run --rm web composer require --dev phpunit/phpunit` (vendor là named volume).

**Schema (tái dùng bảng WMS đã có trong `database/schema.sql`, bổ sung bằng migration):**
* `warehouse_locations`: `zone_type ∈ {storage, staging, dock}`, `is_allocatable`, `name`, `pick_sequence`. Seed đúng 7 location ở mục 2.
* `inventory_balances`: giữ UNIQUE (sku, location, status), thêm `alloc_locked`, `first_in_at` (phục vụ FIFO), và `CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)`.
* `stock_movements`: thêm `stock_status`, `reference_type/reference_id`, `user_id`, `note`, `changes_on_hand` (cờ), `movement_type` theo bảng mục 3, `qty > 0`; `from_location_id`/`to_location_id` nullable; trigger chặn UPDATE/DELETE (BR-10).
* `products`: thêm `size`, `color`, `uom` (hiển thị "Cái"). **`products.qty` không còn là nguồn sự thật**: ngừng ghi, mọi chỗ đọc đổi sang `SUM(inventory_balances.on_hand)`. Giữ cột ở trạng thái **deprecated** trong suốt Phase 1–6; **drop ở migration cuối** (Phase 7) khi `grep` sạch.
* Inbound: tái dùng `goods_receipts` / `goods_receipt_items` làm ASN/phiếu nhận (`asn_code`, trạng thái ở 5.1, ghi `expected_qty / counted_qty / qc_pass_qty / qc_fail_qty / received_qty / putaway_qty`, người duyệt chênh lệch/nhận thừa); bảng mới `qc_records`, `putaway_tasks`, và theo dõi **phần thiếu mở** (E1).
* Outbound: `outbound_orders` (kênh, địa chỉ, `tracking_id`, `status`, `hold_reason`), `outbound_order_lines` (`requested/reserved/picked/packed/shipped/backorder_qty`), `reservations` (order_line, sku, location, qty, status `active|picked|shipped|released`, rule=`FIFO`), `pick_lists`/`pick_list_lines`, `shipments`, `oms_outbox` (event gửi OMS — chỉ ghi, không gọi dịch vụ ngoài).
* Audit: mở rộng `inventory_audit` (hoặc bảng mới) với `location_id`, `trigger_type ∈ {CYCLE, PICK_SHORTAGE}`, `system_qty` chốt lúc tạo, `counted_qty`, `recount_qty`, `status (OPEN, COUNTED, RECOUNT, PENDING_APPROVAL, ADJUSTED, CLOSED_MATCH)`, `approved_by`, `movement_id`.
* Bảng cũ `stock_picking` / `stock_move`: **đóng băng** (ngừng ghi, ẩn khỏi menu hoặc để chỉ đọc có nhãn "kế thừa"). Không để hai hệ thống ghi tồn song song.
* Vai trò: thêm cột `role` cho `users` (`supervisor`, `operator`); hành động **cần Giám sát**: duyệt Receiving khi có chênh lệch và duyệt nhận thừa (E2), duyệt Adjustment (C5), mở khóa phân bổ. Tạo tài khoản demo bằng `bin/seed_demo.php` với mật khẩu lấy từ biến môi trường (`DEMO_USER_PASSWORD`), **không commit mật khẩu**.

**Hạ tầng DB (lưu ý từ hiện trạng):**
* `CREATE TRIGGER` bằng `app_user` có thể lỗi 1419 (binlog bật mặc định, không có SUPER). Giải pháp ưu tiên: thêm `command: --log-bin-trust-function-creators=1` cho service `db` trong Compose (chấp nhận cho demo) **hoặc** cho `bin/migrate.php` dùng kết nối root chỉ riêng khi migrate. **Kiểm tra ngay ở Phase 0**, nêu lựa chọn trong plan.
* `bin/migrate.php` phải chạy được trên **DB đã tồn tại** (volume cũ của các thành viên): migration `000_baseline` nhận ra `schema.sql` đã áp dụng (không chạy lại), các migration sau mới thêm.
* Test dùng DB riêng `login_test`; việc tạo DB và GRANT cho `app_user` cần root (viết lệnh/hướng dẫn rõ trong README, không giả định init script chạy lại). **Không bao giờ chạy Reset/test trên DB `login` chứa dữ liệu của tôi.**

## 8. Màn hình và hành vi

1. **Banner cố định mọi trang:** "DEMO – dữ liệu giả lập. Seedcom Logistics chỉ là bối cảnh; công cụ này không phản ánh hệ thống thật và không khẳng định Seedcom sử dụng."
2. **Tồn kho (Inventory Visibility):** bảng theo cặp SKU+Location (On-hand / Reserved / Available), tổng theo SKU và toàn kho (Tổng tồn vs Tồn khả dụng), lọc theo SKU/location, nhấn vào SKU để mở **ledger của SKU** (đúng như bảng ledger A4 §4).
3. **Inbound:** tạo ASN; check-in; nhập đếm + chênh lệch; QC (đạt/lỗi, lý do); xác nhận Receiving (Giám sát duyệt nếu có chênh lệch; duyệt riêng cho nhận thừa); danh sách task Put-away với **ô quét** location nguồn → SKU → location đích; đóng phiếu có bảng đối soát ASN – nhận – put-away.
4. **Outbound:** tạo đơn nhiều dòng; Reserve (hiển thị Available trước/sau, location được chọn, rule FIFO); **Pick List** có ô quét và ô "số thực lấy"; **Packing Station** quét đối soát, sinh Tracking ID, nhãn in được (trang HTML + `@media print`, không làm PDF); **Cửa xuất (DOCK)** quét tracking để ship; timeline trạng thái; xem `oms_outbox`.
5. **Audit:** tạo phiếu cycle count, đếm mù, recount, duyệt Adjustment; phiếu tự sinh từ pick thiếu hàng; khóa/mở khóa phân bổ hiển thị rõ.
6. **Stock Movement ledger toàn kho:** lọc theo SKU, loại, chứng từ, thời gian; xuất CSV; gộp hiển thị `LOAD`+`SHIP` thành "Shipping" (có thể bung chi tiết).
7. **Đối soát (Reconciliation):** với mọi cặp, so `OPENING + Σ movements` với `inventory_balances`; công thức `Tồn cuối = Đầu + Nhập − Xuất ± Adjustment` cho toàn kho; báo ✓/✗.
8. **Bằng chứng:** 6 kết quả ở mục 1, tính trực tiếp từ dữ liệu hiện tại.
9. **Bảng điều khiển Demo (rất quan trọng cho seminar):**
   * Chọn **preset**: `main` (A1), `after_inbound` (A1 + ASN-DEMO-001 đã put-away xong, tổng 350 — dùng cho kịch bản 2), hoặc `exception` (A1 nhưng AO-001-M-DEN: A-01 = 50, B-01 = 10, tổng kho 315 — gắn nhãn "kịch bản độc lập, giả định bổ sung theo A4 §3.3") và nút **Reset về đầu kỳ** (xóa dữ liệu giao dịch bằng `TRUNCATE`, nạp lại seed + `OPENING`, có xác nhận).
   * **Chế độ trình diễn từng bước** (stepper): mỗi nút "Bước tiếp theo" thực thi **đúng thao tác thật qua service** (không phải mô phỏng bằng dữ liệu cứng), hiển thị bảng **Trước / Sau**, cột **Kỳ vọng theo tài liệu** và **✓/✗**. Có 3 kịch bản: (1) Inbound ASN-DEMO-001; (2) Outbound + Audit ORD-DEMO-001 (ledger A4 §4) — **chạy nối tiếp kịch bản 1 hoặc từ preset `after_inbound`**; (3) Ngoại lệ ORD-DEMO-002 (preset `exception`, độc lập).
   * Các ngoại lệ E1–E4 có nút kích hoạt riêng để demo.
10. **Trang "Về dữ liệu":** phân loại **FACT / SIMULATION / PROPOSED SOLUTION** đúng như A1 (FACT: 4 DC, hơn 35.000 điểm phân phối, legacy WMS không theo kịp tăng trưởng/omni-channel — dẫn nguồn case study/press release công khai của Blue Yonder **ngày 15/08/2023**, **không trình bày là số liệu 2026**; mọi thứ khác là SIMULATION/PROPOSED). Ghi đầy đủ vào `docs/DATA_LABELS.md`.

Giữ phong cách UI hiện tại (sidebar tối, tiếng Việt), có thể tách CSS inline ra `assets/app.css`. Không thêm framework JS nặng. `modules/chat-ai.php` (Gemini): nếu chưa cấu hình phải degrade êm hoặc ẩn khỏi menu; nếu giữ, ngữ cảnh lấy từ `inventory_balances`.

## 9. Các phase (mỗi phase ≥ 1 commit; test xong mới sang phase sau)

* **Phase 0 — Nền tảng:** nhánh `feature/wms-demo` từ `main`; `bin/migrate.php` + `schema_migrations` + migration baseline `000`; cho Compose áp dụng migration khi khởi động (hoặc lệnh `docker compose run --rm web php bin/migrate.php`); xử lý quyền tạo trigger (mục 7); PHPUnit + autoload trong container; DB test riêng `login_test`; dọn `config/db.php` (**bỏ fallback sang root với mật khẩu mặc định** nếu có, đọc env); helper CSRF; thêm cột `role`.
* **Phase 1 — Schema, seed, InventoryService:** migration mục 7 (gồm trigger chặn UPDATE/DELETE, CHECK); seed 7 location + 8 SKU + `OPENING`; `InventoryService` với các phép nguyên tử (`receive`, `move`, `reserve`, `release`, `consumeReserved/ship`, `adjust`); bảng reconcile; **Reset + preset** (`DemoService`, `bin/seed_demo.php`). Test trigger/CHECK/idempotent/concurrency xanh trước khi sang Phase 2.
* **Phase 2 — Inbound:** ASN → Receiving → QC → Put-away → đóng phiếu + E1–E4 + duyệt chênh lệch + golden test bảng Inbound mục 6.
* **Phase 3 — Visibility, Ledger, Đối soát, Bằng chứng.**
* **Phase 4 — Outbound:** 4a tạo đơn + validate; 4b reservation FIFO; 4c pick list + quét; 4d packing + tracking; 4e shipping (`LOAD`+`SHIP`) + `oms_outbox`; 4f hủy đơn (hoàn `Reserved`, trả hàng staging về **location gốc ghi trong reservation** bằng movement). Golden test ledger A4 §4 các dòng 0–8.
* **Phase 5 — Audit:** cycle count C1–C6, đếm mù, recount, duyệt; ngoại lệ pick thiếu (ORD-DEMO-002) với golden test 11 bước (cả SKU lẫn toàn kho 315 → 261); ledger dòng 9–10.
* **Phase 6 — Demo Control Panel:** stepper cho 3 kịch bản, preset/reset, trang "Về dữ liệu", banner.
* **Phase 7 — Hoàn thiện:** thay sạch mọi chỗ còn đọc `products.qty` (`dashboard`, `products`, `reports`, `chat-ai`), rồi **drop cột `products.qty`** bằng migration cuối; cập nhật `README.md` (mục "Công việc còn lại" → "Đã hoàn thành / Hạn chế", gồm hạn chế FIFO mức cặp); `docs/ARCHITECTURE.md`, `docs/DEMO_SCRIPT.md` (kịch bản trình bày ~10–12 phút theo thứ tự: Visibility → Inbound → Outbound → Audit ngoại lệ → Đối soát/Bằng chứng).

## 10. Tiêu chí nghiệm thu

1. `docker compose down -v && docker compose up -d --build` trên máy sạch chạy được, migration tự áp dụng (kể cả trigger), đăng nhập được, đi hết 3 kịch bản bằng giao diện **và** bằng `php bin/demo.php <preset>` (CLI chạy hết kịch bản, in bảng, thoát mã ≠ 0 nếu có số không khớp). `bin/migrate.php` cũng chạy được trên DB đã tồn tại (không `down -v`).
2. Toàn bộ golden test ở mục 6 xanh; mọi test bắt buộc ở cuối mục 6 xanh.
3. Trang Đối soát ✓ cho mọi cặp sau **mỗi** kịch bản; trang Bằng chứng ✓ cho cả 6 kết quả.
4. Không còn chỗ nào ghi trực tiếp `products.qty` hay `inventory_balances` ngoài `InventoryService` (kiểm bằng `grep` và đưa vào test/CI script).
5. Banner và trang "Về dữ liệu" có mặt; không đâu trong UI/README/docs ám chỉ Seedcom dùng công cụ này.

## 11. Ràng buộc chung và đáp án mặc định cho câu hỏi mở

* **Bảo mật:** prepared statement; escape output; CSRF cho mọi POST; kiểm tra đăng nhập và vai trò ở mọi module; không lộ stack trace; không commit `.env`/secret/mật khẩu demo; không dùng lại secret Google từng lộ (chủ tài khoản cần thu hồi key đó; kiểm tra lịch sử git xem còn sót không và báo tôi).
* Giữ nguyên thông tin tác giả và giấy phép mã kế thừa (FlowLink-SCM, nchphuong06).
* Không thêm phụ thuộc lớn mà chưa nêu lý do trong plan; **không** thêm lot/hạn dùng/FEFO, **không** thêm location ngoài 7 location của A1.
* Không chạy `docker compose down -v` hay xóa dữ liệu của tôi khi chưa hỏi (riêng lệnh Reset trong ứng dụng thì được, nhưng phải có xác nhận, và chỉ trên DB demo).
* **Đáp án mặc định cho các câu hỏi mở** (dùng luôn; chỉ hỏi lại nếu có lý do mạnh để đổi, và nêu trong plan):

| # | Câu hỏi | Mặc định |
|---|---|---|
| 1 | Đơn pick thiếu | Giữ `PICKING` + cờ ngoại lệ/`hold_reason`; không thêm trạng thái mới |
| 2 | Giám sát duyệt gì | Duyệt khi có **bất kỳ chênh lệch** lúc xác nhận Receiving (A2 bước 5); nhận thừa bắt buộc duyệt rõ ràng (E2); đường chuẩn không cần duyệt |
| 3 | `products.qty` | Deprecated (ngừng ghi) đến Phase 6; drop ở migration cuối Phase 7 khi `grep` sạch |
| 4 | Hủy đơn sau khi đã pick | Trả hàng staging về **location gốc** ghi trong reservation, bằng movement |
| 5 | PDF/in | Không làm PDF; trang HTML + `@media print` cho Pick List và nhãn |
| 6 | Tên kênh/địa chỉ đơn | "Kênh online (mô phỏng)"; "Địa chỉ mẫu – không có thật" |

## 12. Git & GitHub

* Remote `https://github.com/quynhthu121/WMS_Tool`, nhánh mặc định `main` (commit gần nhất: merge PR #2 `integration/base-wms`).
* Làm trên `feature/wms-demo`; commit nhỏ, tiếng Việt: `feat(inbound): …`, `fix(audit): …`, `test(outbound): …`, `docs: …`.
* **Không push thẳng `main`.** Push nhánh, mở **Pull Request** vào `main`: tóm tắt, ảnh chụp màn hình các màn chính (Tồn kho, Inbound, Outbound, Audit, Đối soát, Bằng chứng, Demo Control Panel), cách chạy, kết quả test mục 10, hạn chế. Nếu `gh` chưa đăng nhập hoặc push bị từ chối, **dừng và hướng dẫn tôi lệnh cần chạy** thay vì vòng qua.
* Trước khi push: `git status` sạch, `.env` không bị track, `docker compose config --quiet` và toàn bộ test pass.

## 13. Việc đầu tiên bạn làm bây giờ

1. Đọc 5 tài liệu nguồn và repo theo mục 0 (kể cả `database/init/*.sql`).
2. Vào Plan Mode và đưa tôi: (a) bảng **hiện trạng → thay đổi** theo từng file (file, hiện trạng, thay đổi đề xuất, rủi ro); (b) quyết định về `products.qty`, `stock_picking/stock_move`, mapping cột của `goods_receipts`, cách chặn UPDATE/DELETE `stock_movements` (mỗi mục 2–3 phương án và **một đề xuất rõ**); (c) thiết kế migration (thứ tự chạy, baseline, backfill, kiểm tra toàn vẹn sau migrate, rollback, xử lý quyền trigger); (d) các **mâu thuẫn/thiếu sót** bạn phát hiện giữa tài liệu và hiện trạng (kèm trích nguồn), gồm quyết định về `database/init/01-base-tables.sql`; (e) câu hỏi còn lại (đánh dấu câu nào chặn tiến độ, câu nào có thể giả định tạm).
3. Chờ tôi xác nhận rồi mới bắt đầu Phase 0. Nếu tôi chỉ duyệt một phần, chỉ làm phần đó.
