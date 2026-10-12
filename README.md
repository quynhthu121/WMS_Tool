# WMS Tool

Dự án WMS phục vụ seminar của nhóm tại UIT, kế thừa source FlowLink-SCM và bổ sung cấu trúc dữ liệu cho các nghiệp vụ quản lý kho.

## 1. Nguồn kế thừa

- Repo nhóm: https://github.com/quynhthu121/WMS_Tool
- Source nền: https://github.com/nchphuong06/FlowLink-SCM
- Commit nguồn dùng để tích hợp: `79ad75e2`.
- Database Core được kế thừa từ nhánh `feature/db-core` của WMS_Tool.

Source ứng dụng được nhập từ phiên bản trên, sau đó chỉnh cấu hình Docker, biến môi trường, collation và mã hóa dữ liệu mẫu. Các commit cũ của FlowLink-SCM được tra cứu tại repo nguồn; không được nhập vào lịch sử WMS_Tool trong lần tích hợp này.

Giữ các thông tin tác giả và giấy phép có trong mã kế thừa. README của source gốc: https://github.com/nchphuong06/FlowLink-SCM/blob/79ad75e2/README.md

## 2. Công nghệ và cấu hình

- PHP 8.1, Apache.
- MySQL 8.0, InnoDB.
- Composer, cài thư viện theo `composer.lock`.
- Docker Compose.
- Adminer để quản lý database.
- Database dùng chung: `login`.
- Website: http://localhost:8890
- Adminer: http://localhost:8891

## 3. Các thư mục và file chính

| Đường dẫn | Vai trò |
|---|---|
| `index.php`, `landing.php` | Điểm vào ứng dụng và trang giới thiệu |
| `classes/` | Xử lý đăng ký, đăng nhập |
| `modules/` | Các chức năng kế thừa |
| `views/` | Giao diện |
| `config/db.php` | Kết nối database và đọc cấu hình môi trường |
| `_installation/` | Các script SQL của source nền |
| `database/schema.sql` | Khởi tạo sáu bảng WMS mới |
| `database/init/01-base-tables.sql` | Bản khởi tạo tối giản của Database Core; không được Compose hiện tại sử dụng |
| `Dockerfile` | Build môi trường PHP và thư viện |
| `docker-compose.yml` | Cấu hình web, database và Adminer |
| `.env.example` | Mẫu cấu hình |
| `.gitignore`, `.dockerignore` | Loại cấu hình riêng khỏi Git và Docker build context |

## 4. Database

Database tích hợp có 12 bảng:

- Kế thừa: `users`, `products`, `partners`, `stock_picking`, `stock_move`, `inventory_audit`.
- WMS mới: `warehouses`, `warehouse_locations`, `inventory_balances`, `stock_movements`, `goods_receipts`, `goods_receipt_items`.

Compose chạy các script theo thứ tự:

1. `_installation/02-create-and-fill-users-table.sql`
2. `_installation/04-create-partners-and-inventory-audit.sql`
3. `database/schema.sql`

Các bảng nghiệp vụ kho dùng `utf8mb4_unicode_ci`. Script nạp sản phẩm mẫu có `SET NAMES utf8mb4`.

Không chạy toàn bộ file trong `_installation/` một cách tự động: source cũ có các định nghĩa bảng trùng nhau. Dùng đúng danh sách script trong Compose.

## 5. Chạy trên máy thành viên

Yêu cầu: Git, Docker Desktop và Docker Compose. Docker Desktop phải chạy được Linux engine.

### Clone repo

```bash
git clone https://github.com/quynhthu121/WMS_Tool.git
cd WMS_Tool
```

### Tạo cấu hình riêng

Trong PowerShell:

```powershell
Copy-Item .env.example .env
```

Mở `.env` và đặt hai mật khẩu riêng cho:

- `DB_ROOT_PASSWORD`
- `DB_PASSWORD`

Các biến Google và Gemini có thể để trống khi kiểm tra đăng nhập thông thường và nghiệp vụ kho. Chỉ dùng Google hoặc AI sau khi cấu hình dịch vụ tương ứng.

Không commit `.env` hoặc điền secret thật vào `.env.example`.

### Build và khởi động

```bash
docker compose config --quiet
docker compose build web
docker compose up -d
docker compose ps
```

Kết quả mong đợi: `db` có trạng thái `healthy`; `web` và `adminer` có trạng thái `Up`.

Mở website, tạo tài khoản mới tại `/register.php` và đăng nhập bằng tên đăng nhập, mật khẩu. Tài khoản và dữ liệu trên mỗi máy được lưu trong database cục bộ của máy đó.

### Đăng nhập Adminer

| Trường | Giá trị |
|---|---|
| Hệ thống | MySQL |
| Máy chủ | `db` |
| Người dùng | `app_user` |
| Mật khẩu | Giá trị `DB_PASSWORD` trong `.env` |
| Database | `login` |

## 6. Dừng và mở lại

Dừng container:

```bash
docker compose stop
```

Mở lại:

```bash
docker compose up -d
```

Dữ liệu nằm trong Docker volume. `docker compose down -v` xóa volume và dữ liệu.

Các script khởi tạo chỉ chạy khi volume database mới được tạo. Sửa file SQL không tự cập nhật database đã có; cần migration khi thay đổi cấu trúc.

## 7. Kết quả kiểm tra hiện tại

Đã kiểm tra trên máy tích hợp ngày 12/10/2026:

- Build image web thành công.
- Ba dịch vụ khởi động được.
- Database tạo đủ 12 bảng, sử dụng InnoDB.
- Trang giới thiệu và dashboard hiển thị được.
- Đăng nhập thành công.
- Trang Sản phẩm đọc được ba sản phẩm mẫu.
- Tên sản phẩm mẫu đã được sửa để hiển thị đúng tiếng Việt.

## 8. Công việc còn lại

Đây là phiên bản source nền tích hợp, chưa nghiệm thu toàn bộ WMS.

- Giao diện kế thừa vẫn dùng `products.qty` để quản lý tồn kho.
- Cần tích hợp các module với `inventory_balances` và `stock_movements`.
- Cần thống nhất cách sử dụng bảng cũ `stock_picking`, `stock_move` với các bảng WMS mới.
- Cần hoàn thiện nghiệp vụ kho, ràng buộc số lượng và migration theo task của nhóm.
- Chưa nghiệm thu đầy đủ luồng nhập kho, put-away, picking và packing.
- Google Login và Gemini chưa được cấu hình, kiểm thử trong lần tích hợp này.
- Secret Google từng xuất hiện trong source gốc cần được chủ tài khoản thu hồi hoặc thay mới.