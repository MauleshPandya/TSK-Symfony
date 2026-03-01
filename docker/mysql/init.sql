-- Create test database
CREATE DATABASE IF NOT EXISTS fund_transfer_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON fund_transfer_test.* TO 'app'@'%';
FLUSH PRIVILEGES;
