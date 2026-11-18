CREATE DATABASE IF NOT EXISTS fundilink_marketplace
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE fundilink_marketplace;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(15) NULL,
  email VARCHAR(120) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('client', 'worker', 'admin') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_phone_unique (phone),
  UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  trade VARCHAR(40) NOT NULL,
  area VARCHAR(180) NOT NULL,
  rate DECIMAL(12, 2) NOT NULL DEFAULT 0,
  experience TINYINT UNSIGNED NOT NULL DEFAULT 0,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  phone VARCHAR(40) NOT NULL,
  skills TEXT NOT NULL,
  bio TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY workers_user_id_unique (user_id),
  CONSTRAINT workers_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  trade VARCHAR(40) NOT NULL,
  location VARCHAR(180) NOT NULL,
  job_date DATE NOT NULL,
  budget DECIMAL(12, 2) NOT NULL DEFAULT 0,
  description TEXT NOT NULL,
  status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX jobs_trade_status_idx (trade, status),
  INDEX jobs_client_idx (client_id),
  CONSTRAINT jobs_client_fk FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NULL,
  worker_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  booking_date DATE NOT NULL,
  description TEXT NOT NULL,
  status ENUM('pending', 'confirmed', 'declined', 'completed') NOT NULL DEFAULT 'pending',
  rated TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX bookings_worker_idx (worker_id),
  INDEX bookings_client_idx (client_id),
  CONSTRAINT bookings_client_fk FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT bookings_worker_fk FOREIGN KEY (worker_id) REFERENCES workers (id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  worker_id INT UNSIGNED NOT NULL,
  booking_id INT UNSIGNED NULL,
  stars TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(2000) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY reviews_booking_unique (booking_id),
  INDEX reviews_worker_idx (worker_id),
  CONSTRAINT reviews_worker_fk FOREIGN KEY (worker_id) REFERENCES workers (id)
    ON DELETE CASCADE,
  CONSTRAINT reviews_booking_fk FOREIGN KEY (booking_id) REFERENCES bookings (id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO workers (id, name, trade, area, rate, experience, verified, phone, skills, bio)
SELECT 1, 'John Mwangi', 'Plumber', 'Thika', 1800, 9, 1, '0712000001',
       '["leak repair","water tanks","bathroom fitting"]',
       'Reliable plumber for homes and small businesses.'
WHERE NOT EXISTS (SELECT 1 FROM workers WHERE id = 1);
INSERT INTO workers (id, name, trade, area, rate, experience, verified, phone, skills, bio)
SELECT 2, 'Grace Atieno', 'Electrician', 'Nairobi', 2500, 7, 1, '0712000002',
       '["house wiring","solar install","meter boards"]',
       'Certified wireman, safe and tidy installations.'
WHERE NOT EXISTS (SELECT 1 FROM workers WHERE id = 2);
INSERT INTO workers (id, name, trade, area, rate, experience, verified, phone, skills, bio)
SELECT 3, 'Peter Kiprop', 'Mason', 'Eldoret', 2200, 15, 1, '0712000003',
       '["stone work","foundations","fencing"]',
       'Fifteen years building homes across Uasin Gishu.'
WHERE NOT EXISTS (SELECT 1 FROM workers WHERE id = 3);
INSERT INTO workers (id, name, trade, area, rate, experience, verified, phone, skills, bio)
SELECT 4, 'Hassan Omar', 'Mechanic', 'Mombasa', 2000, 11, 0, '0712000004',
       '["engine repair","diagnostics","brakes"]',
       'Mobile mechanic, comes to you.'
WHERE NOT EXISTS (SELECT 1 FROM workers WHERE id = 4);
INSERT INTO workers (id, name, trade, area, rate, experience, verified, phone, skills, bio)
SELECT 5, 'Mary Wanjiru', 'Painter', 'Thika', 1500, 5, 1, '0712000005',
       '["interior","exterior","wall finishes"]',
       'Clean lines and careful prep.'
WHERE NOT EXISTS (SELECT 1 FROM workers WHERE id = 5);
INSERT INTO workers (id, name, trade, area, rate, experience, verified, phone, skills, bio)
SELECT 6, 'Samuel Otieno', 'Welder', 'Kisumu', 2300, 8, 0, '0712000006',
       '["gates","grills","steel frames"]',
       'Custom gates and window grills.'
WHERE NOT EXISTS (SELECT 1 FROM workers WHERE id = 6);

INSERT INTO jobs (id, name, phone, trade, location, job_date, budget, description)
SELECT 1, 'Alice N.', '0722000011', 'Plumber', 'Thika, Makongeni',
       '2026-11-25', 3000, 'Replace leaking kitchen sink pipe.'
WHERE NOT EXISTS (SELECT 1 FROM jobs WHERE id = 1);
INSERT INTO jobs (id, name, phone, trade, location, job_date, budget, description)
SELECT 2, 'David K.', '0722000012', 'Mason', 'Juja',
       '2026-12-01', 25000, 'Build a 20m perimeter wall.'
WHERE NOT EXISTS (SELECT 1 FROM jobs WHERE id = 2);
INSERT INTO jobs (id, name, phone, trade, location, job_date, budget, description)
SELECT 3, 'Faith M.', '0722000013', 'Electrician', 'Nairobi, Kasarani',
       '2026-11-22', 0, 'Install 6 sockets and 2 light fittings.'
WHERE NOT EXISTS (SELECT 1 FROM jobs WHERE id = 3);

INSERT INTO reviews (worker_id, stars, comment)
SELECT 1, 5, 'Fixed our leak in an hour.'
WHERE NOT EXISTS (SELECT 1 FROM reviews WHERE worker_id = 1 AND comment = 'Fixed our leak in an hour.');
INSERT INTO reviews (worker_id, stars, comment)
SELECT 1, 4, 'Neat work, fair price.'
WHERE NOT EXISTS (SELECT 1 FROM reviews WHERE worker_id = 1 AND comment = 'Neat work, fair price.');
INSERT INTO reviews (worker_id, stars, comment)
SELECT 2, 5, 'Rewired our flat, no issues.'
WHERE NOT EXISTS (SELECT 1 FROM reviews WHERE worker_id = 2 AND comment = 'Rewired our flat, no issues.');
INSERT INTO reviews (worker_id, stars, comment)
SELECT 3, 5, 'Solid wall, finished early.'
WHERE NOT EXISTS (SELECT 1 FROM reviews WHERE worker_id = 3 AND comment = 'Solid wall, finished early.');
INSERT INTO reviews (worker_id, stars, comment)
SELECT 3, 5, 'Highly skilled.'
WHERE NOT EXISTS (SELECT 1 FROM reviews WHERE worker_id = 3 AND comment = 'Highly skilled.');
INSERT INTO reviews (worker_id, stars, comment)
SELECT 3, 4, 'Good crew.'
WHERE NOT EXISTS (SELECT 1 FROM reviews WHERE worker_id = 3 AND comment = 'Good crew.');
INSERT INTO reviews (worker_id, stars, comment)
SELECT 4, 4, 'Diagnosed the fault fast.'
WHERE NOT EXISTS (SELECT 1 FROM reviews WHERE worker_id = 4 AND comment = 'Diagnosed the fault fast.');
INSERT INTO reviews (worker_id, stars, comment)
SELECT 6, 4, 'Gate looks great.'
WHERE NOT EXISTS (SELECT 1 FROM reviews WHERE worker_id = 6 AND comment = 'Gate looks great.');
