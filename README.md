# 🛵 Rental Scooter — PHP Native

Aplikasi rental scooter full **PHP native (tanpa framework)** + MySQL. Proyek pertama — fokus belajar auth, CRUD, upload file, transaksi booking, dan hardening keamanan dasar.

**Repo:** `Apippl/Rental-Scooter` · **Stack:** PHP 8 (mysqli), MySQL/MariaDB, HTML, CSS, JS + SweetAlert2

---

## ✨ Fitur

**User:**
- Register / Login (hash `password_hash`, rate-limit, CSRF, auto-upgrade hash MD5 lama)
- Katalog + pencarian scooter, detail + kalender tanggal terisi
- Booking dengan upload KTP/SIM (JPG/PNG/WEBP/PDF, maks 3 MB, validasi MIME + nama acak, anti-webshell)
- Riwayat sewa + peringatan overdue / H-2 + rating per-sewa (1–5 ★ + ulasan)
- Lupa / reset password via token 1 jam (hash SHA-256, anti-enumeration)
- Blokir otomatis jika admin memblokir di tengah sesi

**Admin:**
- Dashboard (statistik unit, user, pending/active, revenue, rating global)
- Confirm / Complete (denda otomatis per-hari) / Cancel dengan transaksi + `FOR UPDATE`
- Kelola scooter (tambah/edit/hapus + upload gambar 5 MB, whitelist type/status)
- Kelola user (block/unblock, reset password acak)
- Riwayat + filter status/tanggal + export CSV + audit log
- Lihat KTP via proxy aman (`view_idcard.php`), foto kembali via `view_return.php`

---

## 🧱 Struktur

```
scooter/
├── admin/          # dashboard, CRUD scooter, aksi rental, kelola user, proxy KTP/return
├── user/           # login, register, dashboard, detail, book, history, rating, forgot/reset
├── config/
│   ├── db.php                          # koneksi (dukung .env) + security headers
│   ├── .env.example                    # contoh config (copy ke .env, jangan commit .env)
│   ├── migration_20261007.sql          # migrasi ratings/denda/block/reset/settings
│   └── migration_20261012_audit_fix.sql# perbaikan audit (status kosong + view)
├── css/            # stylesheet per halaman
├── uploads/
│   ├── .htaccess             # blokir eksekusi PHP
│   ├── id_cards/.htaccess    # Require all denied (akses via proxy saja)
│   └── returns/.htaccess     # idem untuk foto pengembalian
└── index.php       # redirect ke user/index.php
```

---

## ⚙️ Syarat

- XAMPP Windows (Apache 2.4 + PHP 8.2 + MariaDB 10.4) atau setara
- Browser modern

---

## 🚀 Instalasi lokal (XAMPP)

1. **Clone / copy** ke `C:\xampp\htdocs\scooter`
2. **Buat database** `rental_scooter` via phpMyAdmin (`http://localhost/phpmyadmin`)
3. **Import tabel dasar** (users, scooters, rentals — sesuaikan dump kamu), lalu jalankan migrasi berurutan:
   ```sql
   -- di phpMyAdmin > SQL, atau via mysql:
   SOURCE config/migration_20261007.sql;
   SOURCE config/migration_20261012_audit_fix.sql;
   ```
   Atau PowerShell:
   ```powershell
   Get-Content config/migration_20261007.sql | & "C:\xampp\mysql\bin\mysql.exe" -u root rental_scooter
   Get-Content config/migration_20261012_audit_fix.sql | & "C:\xampp\mysql\bin\mysql.exe" -u root rental_scooter
   ```
4. **Konfigurasi DB** — pilih salah satu:
   - Edit `config/db.php` langsung, atau
   - Copy `config/.env.example` → `config/.env` lalu isi:
     ```
     DB_HOST=localhost
     DB_USER=root
     DB_PASS=
     DB_NAME=rental_scooter
     ```
5. **Jalankan:** start Apache + MySQL di XAMPP → buka `http://localhost/scooter`
6. **Daftar akun baru** via Register. Untuk akun admin, ubah manual di DB:
   ```sql
   UPDATE users SET role='admin' WHERE username='namamu';
   ```

> Catatan: aktifkan STRICT mode di `my.ini` agar ENUM invalid gagal (bukan jadi `''`):
> `sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`

---

## 🔒 Keamanan yang sudah diterapkan

- Prepared statement (mysqli) + `htmlspecialchars` output + `escapeHtml` di JS
- CSRF token di semua form POST (login, register, booking, admin action) + `hash_equals`
- Upload: cek ekstensi + `finfo` MIME + `getimagesize`, nama acak, `.htaccess` anti-eksekusi, proxy KTP/return (anti traversal + `nosniff`)
- Session: `httponly`, `SameSite=Lax`, `Secure` otomatis saat HTTPS, timeout 30 mnt, `regenerate_id` saat login
- Rate-limit sesi (login/register/forgot) + pesan error generik anti-enumeration
- Aksi admin POST-only + whitelist `type/status` + state machine rental
- Header: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`

---

## 🗺️ Roadmap

- [ ] Pembayaran (QRIS/Midtrans) + struk PDF
- [ ] Logout POST + CSRF penuh
- [ ] Rate-limit per-IP di DB (bukan sesi saja)
- [ ] SMTP beneran untuk reset password

---

## 👤 Author

**Apippl** — proyek pertama, PHP native → berikutnya FastAPI + React.
