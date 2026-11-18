USE fundilink_marketplace;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(15) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('client', 'worker') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_phone_unique (phone)
) ENGINE=InnoDB;

ALTER TABLE workers
  ADD COLUMN user_id INT UNSIGNED NULL,
  ADD UNIQUE KEY workers_user_id_unique (user_id),
  ADD CONSTRAINT workers_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE jobs
  ADD COLUMN client_id INT UNSIGNED NULL,
  ADD INDEX jobs_client_idx (client_id),
  ADD CONSTRAINT jobs_client_fk FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE bookings
  ADD COLUMN client_id INT UNSIGNED NULL,
  ADD INDEX bookings_client_idx (client_id),
  ADD CONSTRAINT bookings_client_fk FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE SET NULL;
