USE fundilink_marketplace;

ALTER TABLE users
  MODIFY COLUMN role ENUM('client', 'worker', 'admin') NOT NULL,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
