# WMS Tool – Database Core (Sprint 1)

## 1. Giới thiệu

Database Core là phần cơ sở dữ liệu nền tảng của hệ thống Warehouse Management System (WMS) thuộc dự án WMS_Tool.

Mục tiêu của Sprint 1 là xây dựng schema cơ sở dữ liệu, xác định các bảng nghiệp vụ kho, thiết lập khóa ngoại và bảo đảm các module sử dụng chung một mô hình dữ liệu.

## 2. Công nghệ sử dụng

* MariaDB / MySQL
* SQL
* InnoDB Storage Engine
* Docker Compose (dự kiến sử dụng để khởi tạo database tự động vì máy người làm không tương thích với phiên bản Docker hiện tại)

## 3. Cấu trúc thư mục

WMS_Tool/
├── database/
│   ├── init/
│   │   └── 01-base-tables.sql
│   └── schema.sql
├── docker-compose.yml
├── .env.example
├── .gitignore
└── README.md


* `database/init/01-base-tables.sql`: tạo các bảng nền `products` và `partners` nếu chưa tồn tại.
* `database/schema.sql`: định nghĩa schema cho sáu bảng nghiệp vụ WMS.
* `docker-compose.yml`: cấu hình dịch vụ MariaDB và các file SQL khởi tạo.
* `.env.example`: mẫu biến môi trường để tham khảo khi cấu hình.
* `.gitignore`: quy định các file không đưa lên Git, bao gồm file `.env` chứa cấu hình cục bộ.

## 4. Cấu trúc database

Database hiện được kiểm tra trên môi trường MariaDB/XAMPP với tổng cộng 8 bảng.

| Bảng                  | Chức năng                                |
| --------------------- | ---------------------------------------- |
| `products`            | Lưu thông tin sản phẩm dùng chung        |
| `partners`            | Lưu thông tin đối tác                    |
| `warehouses`          | Quản lý kho                              |
| `warehouse_locations` | Quản lý vị trí trong kho                 |
| `inventory_balances`  | Theo dõi tồn kho theo sản phẩm và vị trí |
| `stock_movements`     | Ghi nhận các biến động hàng hóa          |
| `goods_receipts`      | Lưu thông tin phiếu nhập kho             |
| `goods_receipt_items` | Lưu chi tiết sản phẩm trong phiếu nhập   |

Các bảng sử dụng InnoDB để hỗ trợ khóa ngoại và bảo đảm tính toàn vẹn dữ liệu.

## 5. Khởi tạo database bằng MariaDB/XAMPP

### Bước 1: Khởi động dịch vụ

Mở XAMPP Control Panel và khởi động MySQL/MariaDB.

Mở phpMyAdmin tại địa chỉ tương ứng với cấu hình máy, ví dụ:

`http://localhost:8080/phpmyadmin`

### Bước 2: Tạo database

Trong phpMyAdmin, mở tab SQL và chạy:

```sql
CREATE DATABASE IF NOT EXISTS wms_tool
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

Sau đó chọn database `wms_tool`.

### Bước 3: Tạo bảng nền

Mở file `database/init/01-base-tables.sql`, sao chép nội dung SQL thuần và thực thi trong tab SQL của database `wms_tool`.

### Bước 4: Tạo các bảng WMS

Mở file `database/schema.sql`, sao chép nội dung SQL thuần và thực thi trong tab SQL của database `wms_tool`.

Lưu ý: Các file trên chứa lệnh `CREATE TABLE IF NOT EXISTS`; cần kiểm tra schema hiện có trước khi áp dụng vào database đã có dữ liệu. Không xóa database hoặc dữ liệu hiện tại chỉ để chạy lại các lệnh khởi tạo.

## 6. Kiểm tra database

### Kiểm tra tổng số bảng

```sql
SELECT COUNT(*) AS total_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'wms_tool'
  AND TABLE_TYPE = 'BASE TABLE';
```

Kết quả mong đợi trong môi trường đã kiểm tra: `8`.

### Kiểm tra storage engine

```sql
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'wms_tool'
ORDER BY TABLE_NAME;
```

Các bảng được kiểm tra cần sử dụng InnoDB.

### Kiểm tra khóa ngoại

```sql
SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'wms_tool'
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;
```

Kết quả đã kiểm tra trước đó có 9 khóa ngoại giữa các bảng WMS, sản phẩm và đối tác.

## 7. Docker Compose

Dự án có file `docker-compose.yml` để cấu hình MariaDB và tự động chạy các file SQL trong thư mục `/docker-entrypoint-initdb.d/`.

Để chạy Docker, máy cần cài đặt và khởi động được Docker Desktop hoặc một môi trường Docker tương thích. File `.env` phải được tạo từ `.env.example` và điền các giá trị phù hợp. Không commit file `.env` hoặc mật khẩu vào Git.

**Trạng thái kiểm thử:** Schema đã được kiểm tra trên MariaDB/XAMPP. Việc khởi tạo database bằng Docker chưa được xác minh thành công trên môi trường phát triển hiện tại.

Lưu ý: Các script khởi tạo của image MariaDB thường chỉ chạy khi khởi tạo thư mục dữ liệu database lần đầu. Thay đổi script không tự động áp dụng vào volume đã được khởi tạo trước đó.

## 8. Trạng thái Sprint 1

### Đã thực hiện

* Xây dựng schema cho sáu bảng nghiệp vụ WMS.
* Liên kết với hai bảng nền `products` và `partners`.
* Kiểm tra tổng cộng 8 bảng trong database.
* Kiểm tra 9 khóa ngoại.
* Xác nhận các bảng sử dụng InnoDB.
* Sửa lỗi Markdown bị lẫn trong file SQL.
* Commit và đồng bộ thay đổi trên GitHub.

### Còn lại

* Kiểm thử Docker Compose trên môi trường tương thích.
* Xác nhận quá trình khởi tạo database tự động không có lỗi.

## 9. Lưu ý khi phát triển

* Sử dụng chung schema database giữa các module.
* Không tạo thêm bảng tồn kho riêng biệt nếu chức năng đã được mô hình hóa bởi `inventory_balances`.
* Khi thay đổi schema, cập nhật các file SQL liên quan và kiểm tra lại khóa ngoại.
* Không đưa mật khẩu, thông tin kết nối riêng tư hoặc dữ liệu nhạy cảm lên GitHub.
