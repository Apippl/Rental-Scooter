-- Migrasi 2026-10-07: quick-win + menengah (idempotent, aman dijalankan ulang)
-- Jalankan: mysql -u root rental_scooter < migration_20261007.sql

-- 1. Rating per-sewa (pengganti users.rating yang menimpa)
CREATE TABLE IF NOT EXISTS ratings (
  rating_id INT(11) NOT NULL AUTO_INCREMENT,
  rental_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  scooter_id INT(11) NOT NULL,
  rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  review TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (rating_id),
  UNIQUE KEY uq_ratings_rental (rental_id),
  KEY idx_ratings_user (user_id),
  KEY idx_ratings_scooter (scooter_id),
  CONSTRAINT fk_ratings_rental FOREIGN KEY (rental_id) REFERENCES rentals (rental_id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_scooter FOREIGN KEY (scooter_id) REFERENCES scooters (scooter_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Kolom denda + pengembalian di rentals
ALTER TABLE rentals
  ADD COLUMN IF NOT EXISTS late_days INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS late_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS returned_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS return_photo VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS return_note TEXT NULL;

-- 3. Blokir user
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS blocked_at DATETIME NULL;

-- 4. Token reset password
CREATE TABLE IF NOT EXISTS password_resets (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_pr_user (user_id),
  KEY idx_pr_expiry (expires_at),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Pengaturan global (denda/hari, maks sewa)
CREATE TABLE IF NOT EXISTS app_settings (
  kunci VARCHAR(64) NOT NULL,
  nilai VARCHAR(255) NOT NULL,
  PRIMARY KEY (kunci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (kunci, nilai) VALUES
  ('late_fee_per_day', '50000'),
  ('max_rent_days', '30');

-- 6. Backfill: rating lama per-user -> rental completed terakhir user tsb (sekali saja)
INSERT IGNORE INTO ratings (rental_id, user_id, scooter_id, rating, review, created_at)
SELECT r.rental_id, u.user_id, r.scooter_id, u.rating, u.rating_review, COALESCE(u.rated_at, NOW())
FROM users u
JOIN rentals r ON r.user_id = u.user_id AND r.status = 'completed'
WHERE u.rating IS NOT NULL
  AND r.rental_id = (
    SELECT r2.rental_id FROM rentals r2
    WHERE r2.user_id = u.user_id AND r2.status = 'completed'
    ORDER BY r2.end_date DESC, r2.rental_id DESC LIMIT 1
  );
