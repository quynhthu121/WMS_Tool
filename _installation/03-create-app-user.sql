-- Tạo user ứng dụng cho MySQL và cấp quyền truy cập cho database `login`
CREATE USER IF NOT EXISTS 'app_user'@'%' IDENTIFIED BY 'app_password';
GRANT ALL PRIVILEGES ON `login`.* TO 'app_user'@'%';
FLUSH PRIVILEGES;
