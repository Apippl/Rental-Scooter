-- Migrasi 2026-10-12: perbaikan audit total (idempotent, aman dijalankan ulang)
-- Jalankan: "C:\xampp\mysql\bin\mysql.exe" -u root rental_scooter < migration_20261012_audit_fix.sql

-- 1. Perbaiki data status kosong (akibat sql_mode non-STRICT + tanpa whitelist dulu)
UPDATE scooters SET status='available'
WHERE status='' OR status IS NULL OR status NOT IN ('available','rented','maintenance');

-- 2. Perbaiki view usang (dulu AVG(users.rating), sekarang AVG(ratings.rating))
DROP VIEW IF EXISTS v_global_rating;
CREATE VIEW v_global_rating AS
  SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total_raters FROM ratings;

-- 3. Catatan: aktifkan STRICT mode agar ENUM invalid gagal (bukan jadi '').
--    Ini harus di my.ini (persist) + runtime. Contoh untuk my.ini [mysqld]:
--    sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION
--    Untuk sesi ini saja (tidak persist):
--    SET GLOBAL sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION';

-- 4. Pastikan folder proteksi ada (dijalankan via PHP, bukan SQL):
--    uploads/id_cards/.htaccess -> Require all denied (sudah diperbaiki)
--    uploads/returns/.htaccess  -> Require all denied (baru)
--    akses via admin/view_idcard.php & admin/view_return.php saja.
