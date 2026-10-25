# Phân loại dữ liệu: FACT / SIMULATION / PROPOSED SOLUTION

Case study Seedcom Logistics chỉ là **bối cảnh** để xây dựng demo. Mọi kịch bản, SKU, location, số lượng là **mô phỏng của nhóm**. Công cụ này **không phản ánh hệ thống hay quy trình thật của Seedcom và không khẳng định Seedcom sử dụng nó.**

Nguồn: `docs/source/foundation.docx` (cập nhật 09/10/2026) và `docs/source/A1_boi_canh_du_lieu.txt`.

## FACT – chỉ lấy từ case study, có nguồn công khai

| Nội dung | Thông tin | Nguồn |
|---|---|---|
| Lĩnh vực Seedcom | Công nghệ, bán lẻ, dịch vụ và sản xuất | Seedcom – Who we are |
| Mô hình | Seedcom tự mô tả là doanh nghiệp tiên phong New Retail tại Việt Nam | Seedcom – Who we are |
| Seedcom Logistics | Kết nối phân phối giữa nhà cung cấp, kênh bán lẻ, merchant, người tiêu dùng | Seedcom; Blue Yonder |
| Hạ tầng kho | 4 trung tâm phân phối (DC), khu chuyên biệt hàng khô, hàng tươi, hàng kiểm soát nhiệt độ | Blue Yonder, case study / press release 15/08/2023 |
| Mạng lưới | Hơn 35.000 điểm phân phối | Blue Yonder, 15/08/2023 |
| Vấn đề | Legacy WMS không còn hỗ trợ tốt tăng trưởng và yêu cầu omni-channel; triển khai WMS mới để tăng quan sát và kiểm soát gần thời gian thực | Blue Yonder, 15/08/2023 |

**Lưu ý nguồn:** các số liệu quy mô dẫn theo case study / press release công khai của Blue Yonder ngày **15/08/2023**; **không** trình bày là số liệu quy mô hiện tại năm 2026.

**Không có trong case study:** diện tích kho, sức chứa, số vị trí kệ, nhân sự, khối lượng đơn/ngày, số SKU, tồn trung bình, quy trình kho chi tiết.

Nguồn tham khảo:
1. Seedcom – Who we are. seedcom.vn/pages/who-we-are
2. Blue Yonder – Seedcom Logistics customer case study. blueyonder.com/customers/seedcom
3. Blue Yonder – Seedcom Digitally Transforms Warehousing Capabilities With Blue Yonder (15/08/2023). media.blueyonder.com

## SIMULATION – dữ liệu giả lập để demo

| Dữ liệu | Giá trị | Nguồn tài liệu | Trong repo |
|---|---|---|---|
| Kho | `WH-DEMO` "Kho demo (mô phỏng)" | prompt v3 | `database/seeds/demo_main.json` |
| 7 location | RECEIVING, A-01, B-01, PICK-STG, PACK-01, OUT-STG, DOCK; chỉ A-01, B-01 phân bổ được | A1 §2.2 | `demo_main.json` |
| 8 SKU Áo/Quần, tồn đầu kỳ | A-01 = 145, B-01 = 160, tổng 305; nạp bằng movement `OPENING` | A1 §2.3–2.4 | `demo_main.json` |
| Quy tắc lấy hàng | FIFO (không lot, không hạn dùng) | A1 §2.1 | `OutboundService::planFifo` |
| ASN-DEMO-001 | AO-001-M-DEN 20, AO-002-L-TRG 10, QUAN-002-L-DEN 15 | A2 §2.1 | `ScenarioService::ASN_DEMO_001` |
| Ngoại lệ Inbound | E1 nhận 18/20, E2 nhận 12/10, E3 lỗi 2/15, E4 quét sai | A2 §3 | `ScenarioService::INBOUND_EXCEPTIONS` |
| ORD-DEMO-001 | 30 cái AO-001-M-DEN; cycle count 40 → 38 | A4 §4 | `DemoScenarios` |
| ORD-DEMO-002 (độc lập) | Giả định bổ sung: AO-001-M-DEN A-01 = 50, **B-01 = 10** (tổng kho 315); đơn 48, pick được 44 | A4 §3.3 | `demo_exception.json` |
| Kênh / địa chỉ / nhà cung cấp | "Kênh online (mô phỏng)", "Địa chỉ mẫu – không có thật", "Nhà cung cấp mẫu (mô phỏng)" | prompt v3 | `OutboundService`, `ScenarioService` |
| Tracking ID, mã phiếu, tài khoản demo | Sinh tự động, không có thật | – | – |
| 3 sản phẩm điện tử của source nền | Dữ liệu mẫu kế thừa, đánh dấu `is_legacy`, ngoài kịch bản | `_installation/02` | migration 002 |

## PROPOSED SOLUTION – do nhóm thiết kế cho prototype

- Câu bài toán, 3 năng lực nghiệp vụ, luồng Receiving → Shipping (foundation mục 3–4).
- Schema Inventory Balance, Stock Movement, Reservation, Pick List, Cycle Count; business rules BR-1…BR-14 ([ARCHITECTURE.md](ARCHITECTURE.md)).
- Quy ước "chỉ hàng ở Storage mới phân bổ cho đơn" (cột "Tính vào tồn khả dụng" của A1 là quy ước giả lập của nhóm).
- Vai trò Giám sát kho / Nhân viên kho và các điểm cần duyệt; trạng thái phiếu nhập, đơn xuất, phiếu kiểm kê.
- 6 kết quả prototype phải chứng minh và North Star (foundation mục 6) – trang **Bằng chứng**.
