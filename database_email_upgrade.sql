USE fundilink_marketplace;

ALTER TABLE users
  MODIFY COLUMN phone VARCHAR(15) NULL,
  ADD COLUMN email VARCHAR(120) NULL AFTER phone,
  ADD UNIQUE KEY users_email_unique (email);
