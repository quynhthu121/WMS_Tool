-- 001 – Vai trò người dùng: supervisor (Giám sát kho) / operator (nhân viên kho).
-- down: ALTER TABLE users DROP CHECK chk_users_role, DROP COLUMN role;

ALTER TABLE users
    ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'operator' COMMENT 'supervisor | operator' AFTER user_phone,
    ADD CONSTRAINT chk_users_role CHECK (role IN ('supervisor', 'operator'));
