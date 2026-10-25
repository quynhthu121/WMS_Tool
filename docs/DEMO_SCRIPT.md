# Kịch bản trình bày (~10–12 phút)

Thứ tự: **Visibility → Inbound → Outbound → Audit ngoại lệ → Đối soát / Bằng chứng.**

## Chuẩn bị (trước giờ trình bày)

1. `.env` có `DEMO_RESET_ENABLED=1` và `DEMO_USER_PASSWORD`; `docker compose up -d --build`.
2. `docker compose exec web php bin/seed_demo.php --preset=main`.
3. Mở hai cửa sổ trình duyệt: một đăng nhập `giamsat_demo` (Giám sát kho), một (ẩn danh) đăng nhập `nhanvien_demo` (Nhân viên kho).
4. Kiểm tra nhanh: `docker compose exec web php bin/demo.php main --db=login_test` in "mọi số khớp tài liệu".

## 0. Mở đầu – phạm vi (30 giây)

- Chỉ vào banner đỏ: dữ liệu giả lập; Seedcom Logistics chỉ là bối cảnh.
- Trang **Về dữ liệu**: FACT (4 DC, hơn 35.000 điểm phân phối – theo Blue Yonder 15/08/2023) / SIMULATION / PROPOSED SOLUTION.

## 1. Inventory Visibility (1,5 phút)

- **Bảng điều khiển Demo** → Reset preset `main`.
- **Tồn kho theo vị trí**: 7 location; A-01 = 145, B-01 = 160, tổng 305; Available = On-hand ở Storage.
- Nhấn `AO-001-M-DEN` → ledger: một dòng OPENING 50 tại A-01. "Số tồn được giải thích bằng giao dịch."

## 2. Inbound – Kịch bản 1 (2,5 phút)

- Bảng điều khiển Demo → **Kịch bản 1 – Bắt đầu (reset main)** → bấm **Bước tiếp theo** từng bước, đọc cột Trước / Sau / Kỳ vọng:
  - Bước 1–4: ASN, check-in, đếm, QC → 305 / 305 không đổi ("ASN chỉ là dự kiến").
  - Bước 5: Xác nhận Receiving → **350**, khả dụng vẫn **305** (hàng ở RECEIVING).
  - 9a / 9b / 9c: 325 → 335 → 350, tổng giữ **350** ("Put-away không tăng tồn").
  - Bước 10: đóng phiếu, 45 = 45 = 45.
- (Tùy thời gian) Ngoại lệ **E1**: Bảng điều khiển → E1; ở cửa sổ Nhân viên bấm "Xác nhận Receiving" → bị chặn; ở cửa sổ Giám sát → duyệt, tổng +18 chứ không +20. **E4**: gõ sai location đích → bị chặn, tồn không đổi.

## 3. Outbound – Kịch bản 2 (3 phút)

- **Kịch bản 2 – Nối tiếp Kịch bản 1** → Bước tiếp theo:
  - #3 Order created 70 / 0 / 70.
  - #4 Reservation: 70 / **30** / **40** ("On-hand không đổi, Available giảm").
  - #5–#7 Picking, Packing (1), (2): 70 / 30 / 40 không đổi ("chỉ đổi location; Reserved đi theo hàng").
  - #8 Shipping: **40 / 0 / 40**, toàn kho **320**.
- Mở **Xuất kho → ORD-DEMO-001**: dòng thời gian trạng thái, Reservation FIFO, nhãn vận đơn (In nhãn), sự kiện `ORDER_SHIPPED` trong oms_outbox.
- #9 Cycle count 40 → 38, #10 Adjustment −2: **38**, toàn kho **318** = 305 + 45 − 30 − 2.
- **Tồn kho → Ledger AO-001-M-DEN**: đúng bảng A4 §4 (50 → 70 → 70 → … → 40 → 38).

## 4. Audit ngoại lệ – Kịch bản 3 (2,5 phút)

- **Kịch bản 3 – Bắt đầu (reset exception)**: AO-001-M-DEN A-01 = 50, B-01 = 10 (giả định bổ sung của A4 §3.3), tổng 315.
  - 1 Reserve 48 tại A-01 (FIFO): 60 / 48 / 12.
  - 2–3 Pick được 44 → thiếu 4: đơn giữ PICKING + cờ ngoại lệ, **khóa phân bổ** A-01, tự tạo phiếu kiểm kê. Mở **Kiểm kê** cho thấy phiếu PICK_SHORTAGE, cặp bị khóa.
  - 4 Đếm 0, đếm lại 0 ⇒ −6.
  - 5 Hủy 4 Reserved: 60 / 44 / 16. 6 ADJUST −6: 54 / 44 / 10.
  - 7 Reserve lại 4 từ **B-01**: 54 / 48 / 6. 8–10 Pick, Pack, Ship 48: **6 / 0 / 6**, toàn kho **261** = 315 − 48 − 6.

## 5. Đối soát và Bằng chứng (1 phút)

- **Đối soát**: mọi cặp ✓ (balance = ledger), công thức toàn kho ✓.
- **Bằng chứng**: 6 kết quả ✓ tính trực tiếp từ dữ liệu (chạy Kịch bản 1 + 2 để có đủ dữ liệu cho cả 6).
- **Stock Movement**: thử lọc theo `ORD-DEMO-002`, xuất CSV; nhắc trigger chặn sửa/xóa ledger.

## Câu hỏi thường gặp

- *Sao Available sau Receiving không tăng?* Hàng ở RECEIVING chưa put-away vào Storage (A2-R3, R4).
- *Sao Picking không trừ tồn?* Hàng vẫn trong kho, chỉ đổi location; chỉ Shipping trừ On-hand.
- *Sửa sai số liệu thế nào?* Không sửa dòng ledger; tạo bút toán mới (kiểm kê → Adjustment có lý do, được duyệt).
