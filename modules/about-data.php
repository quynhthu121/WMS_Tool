<?php
/**
 * Về dữ liệu: phân loại FACT / SIMULATION / PROPOSED SOLUTION theo A1 và tài liệu foundation.
 */

use App\Support\View;
?>
<div class="card">
    <h2>Phạm vi</h2>
    <p>Case study <strong>Seedcom Logistics</strong> chỉ là <strong>bối cảnh</strong> để nhóm xây dựng công cụ demo WMS cho seminar
        <em>Hệ thống quản lý nhà kho – UIT</em>. Mọi kịch bản, SKU, location, số lượng trong công cụ là <strong>mô phỏng của nhóm</strong>.
        Công cụ này <strong>không phản ánh hệ thống hay quy trình thật của Seedcom</strong> và <strong>không khẳng định Seedcom sử dụng nó</strong>.</p>
</div>

<div class="card">
    <h2><?= View::badge('FACT', 'info') ?> Thông tin có nguồn công khai</h2>
    <p class="hint">Chỉ lấy từ case study, không suy diễn. Số liệu quy mô dẫn theo case study / press release công khai của Blue Yonder ngày <strong>15/08/2023</strong> – <strong>không</strong> trình bày là số liệu quy mô hiện tại năm 2026.</p>
    <table class="data">
        <tr><th>Nội dung</th><th>Thông tin</th><th>Nguồn</th></tr>
        <tr><td>Lĩnh vực Seedcom</td><td>Công nghệ, bán lẻ, dịch vụ và sản xuất; tự mô tả là doanh nghiệp tiên phong mô hình New Retail tại Việt Nam.</td><td>Seedcom – Who we are</td></tr>
        <tr><td>Seedcom Logistics</td><td>Kết nối hoạt động phân phối giữa nhà cung cấp, kênh bán lẻ, merchant và người tiêu dùng.</td><td>Seedcom; Blue Yonder</td></tr>
        <tr><td>Hạ tầng kho</td><td>4 trung tâm phân phối (DC) có khu chuyên biệt cho hàng khô, hàng tươi và hàng cần kiểm soát nhiệt độ.</td><td>Blue Yonder (15/08/2023)</td></tr>
        <tr><td>Mạng lưới phục vụ</td><td>Hơn 35.000 điểm phân phối.</td><td>Blue Yonder (15/08/2023)</td></tr>
        <tr><td>Vấn đề</td><td>Legacy WMS không còn hỗ trợ tốt tốc độ tăng trưởng và yêu cầu omni-channel; triển khai WMS mới để tăng khả năng quan sát và kiểm soát gần thời gian thực.</td><td>Blue Yonder (15/08/2023)</td></tr>
        <tr><td>Không có trong case study</td><td class="muted">Diện tích kho, sức chứa, số vị trí kệ, nhân sự, khối lượng đơn/ngày, số SKU, tồn trung bình, quy trình kho chi tiết.</td><td>A1 mục 1</td></tr>
    </table>
</div>

<div class="card">
    <h2><?= View::badge('SIMULATION', 'warn') ?> Dữ liệu giả lập để demo</h2>
    <ul style="margin-left:18px">
        <li>1 kho demo <code>WH-DEMO</code> "Kho demo (mô phỏng)"; 7 location: RECEIVING, A-01, B-01, PICK-STG, PACK-01, OUT-STG, DOCK (A1 mục 2.2).</li>
        <li>8 SKU Áo/Quần và tồn đầu kỳ: A-01 = 145, B-01 = 160, tổng 305 (A1 mục 2.3–2.4). Nhóm hàng Áo/Quần là lựa chọn của nhóm, không có trong case study.</li>
        <li>Đơn vị tính Cái; quy tắc lấy hàng FIFO (hàng không có hạn dùng nên không dùng FEFO).</li>
        <li>ASN-DEMO-001 (45 cái), ORD-DEMO-001 (30 cái), cycle count −2 (A2, A3, A4 §4).</li>
        <li>Kịch bản độc lập ORD-DEMO-002 với <strong>giả định bổ sung</strong> của A4 §3.3: AO-001-M-DEN có thêm 10 cái tại B-01 (tổng kho 315).</li>
        <li>Kênh "Kênh online (mô phỏng)", địa chỉ "Địa chỉ mẫu – không có thật", nhà cung cấp mẫu, Tracking ID – đều là dữ liệu giả.</li>
    </ul>
</div>

<div class="card">
    <h2><?= View::badge('PROPOSED SOLUTION', 'ok') ?> Giải pháp do nhóm thiết kế cho prototype</h2>
    <ul style="margin-left:18px">
        <li>Câu bài toán, 3 năng lực nghiệp vụ (Inventory Visibility, Accuracy &amp; Traceability, Order Fulfillment Control), luồng Receiving → Shipping.</li>
        <li>Schema Inventory Balance theo SKU + Location, Stock Movement chỉ-INSERT, Reservation, Pick List, Cycle Count; business rules BR-1…BR-14.</li>
        <li>Quy ước "chỉ hàng ở location Storage mới được phân bổ cho đơn" (cột "Tính vào tồn khả dụng" của A1 là quy ước giả lập của nhóm).</li>
        <li>Vai trò Giám sát kho / Nhân viên kho và các điểm cần duyệt.</li>
    </ul>
</div>

<div class="card">
    <h2>Nguồn tham khảo</h2>
    <ol style="margin-left:18px" class="small">
        <li>Seedcom – Who we are. seedcom.vn/pages/who-we-are</li>
        <li>Blue Yonder – Seedcom Logistics customer case study. blueyonder.com/customers/seedcom</li>
        <li>Blue Yonder – Seedcom Digitally Transforms Warehousing Capabilities With Blue Yonder (15/08/2023). media.blueyonder.com</li>
        <li>Tài liệu nhóm: docs/source/foundation.docx, A1–A4 (xem docs/DATA_LABELS.md).</li>
    </ol>
</div>
