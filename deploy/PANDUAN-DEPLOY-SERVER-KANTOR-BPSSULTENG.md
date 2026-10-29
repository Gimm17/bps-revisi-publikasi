# Panduan Deployment Server Kantor BPS Sulteng (Ruang Periksa)

Dokumen ini mencatat seluruh proses instalasi, deployment, arsitektur konfigurasi, kendala teknis, dan solusi tuntas saat memasang aplikasi **Ruang Periksa Publikasi BPS** pada server web hosting kantor (**IndoGlobal Control Panel** di `control.capella.idgx.net`) dengan domain **`https://ruangperiksa.bpssulteng.id`**.

Dokumen ini disusun sebagai panduan operasional dan *disaster recovery* (pemulihan cadangan) jika sewaktu-waktu server produksi utama sedang mengalami pemeliharaan (*maintenance*).

---

## 1. Ringkasan Lingkungan Server

| Parameter | Spesifikasi Server Kantor |
| :--- | :--- |
| **Control Panel** | IndoGlobal Control Panel (`https://control.capella.idgx.net`) |
| **User Akun** | `u12228jhr` |
| **Domain Induk** | `bpssulteng.id` |
| **Subdomain Cadangan** | `ruangperiksa.bpssulteng.id` |
| **IP Server** | `103.5.51.154` |
| **Direktori Web** | `/home1/u12228jhr/domain/bpssulteng.id/ruangperiksa.bpssulteng.id/web` |
| **Versi PHP** | PHP 8.4 (Kompatibel dengan Laravel 13.x / PHP 8.2+) |
| **Database Bawaan** | SQLite (`database/database.sqlite`) |
| **Session Driver** | `file` (Menghindari *locking* pada shared hosting) |
| **Hash Driver** | `bcrypt` (Standar terluas pada PHP shared hosting) |

---

## 2. Struktur Paket Rilis (`deploy_bps_audit.zip`)

Paket rilis dibuat langsung dari repositori lokal menggunakan kompresi `zipfile` Python dengan ukuran **56.9 MB** (berisi 10.642 file siap saji):

- **Aset Visual & Animasi (`public/assets/office/` ~30 MB):** Berisi ratusan *sprite frames* karakter animasi 5 Agen AI (animasi jalan, duduk, mengetik, aset meja dan kursi 2.5D).
- **Pustaka Vendor PHP (`vendor/` ~20 MB):** Seluruh pustaka Laravel 13, PhpSpreadsheet, dan Guzzle HTTP disertakan secara utuh sehingga server hosting **tidak perlu** menginstall Composer secara manual.
- **Frontend Terkompilasi (`public/build/`):** Seluruh kode antarmuka Vue 3, Inertia.js, dan Tailwind CSS sudah ter-*bundle* dan teroptimasi penuh.
- **Konfigurasi Otomatis (`.htaccess` & `.env`):** Sudah disiapkan dengan aturan *rewrite* Apache yang mengarahkan lalu lintas langsung ke folder `public/`.
- **Database Bawaan (`database/database.sqlite`):** Sudah terisi tabel-tabel migrasi dan akun admin awal.

---

## 3. Langkah-Langkah Deployment dari Nol

### Langkah 1: Pembuatan Subdomain
1. Login ke panel hosting: `https://control.capella.idgx.net/user/account/login`.
2. Buka menu **Subdomain & DNS**.
3. Buat subdomain baru:
   - **Subdomain:** `ruangperiksa`
   - **Domain:** `bpssulteng.id`
   - Direktori otomatis terbentuk di: `ruangperiksa.bpssulteng.id/web` (atau `public_html/ruangperiksa`).

### Langkah 2: Unggah & Ekstrak File ZIP
1. Masuk ke menu **File Manager**.
2. Masuk ke folder subdomain `ruangperiksa.bpssulteng.id` (atau folder `web` di dalamnya).
3. Hapus file `index.html` default (halaman placeholder *"Tak Ada Situs Web..."*).
4. Klik tombol **Upload**, unggah file `deploy_bps_audit.zip`.
5. Klik kanan file `deploy_bps_audit.zip` lalu pilih **Extract**.

### Langkah 3: Konfigurasi via Terminal Web Panel
Panel IndoGlobal menyediakan akses CLI langsung di browser.
1. Klik nama login di pojok kanan atas (`u12228jhr`) -> pilih **`>_ Terminal`**.
2. Pastikan posisi direktori berada di folder proyek:
   ```bash
   pwd
   # Output harus: /home1/u12228jhr/domain/bpssulteng.id/ruangperiksa.bpssulteng.id/web
   ```
3. Jalankan rangkaian perintah konfigurasi:
   ```bash
   # 1. Berikan izin tulis folder sistem
   chmod -R 777 storage bootstrap/cache database

   # 2. Hubungkan symlink storage publik
   php artisan storage:link

   # 3. Kunci path absolut database SQLite agar tidak error saat dipanggil Apache
   sed -i "s|DB_DATABASE=database/database.sqlite|DB_DATABASE=$(pwd)/database/database.sqlite|" .env

   # 4. Atur driver sesi ke file dan hashing ke bcrypt
   sed -i "s|SESSION_DRIVER=database|SESSION_DRIVER=file|" .env
   echo "HASH_DRIVER=bcrypt" >> .env

   # 5. Reset password admin awal ke format bcrypt
   cat << 'EOF' > reset_pwd.php
   <?php
   require __DIR__ . '/vendor/autoload.php';
   $app = require_once __DIR__ . '/bootstrap/app.php';
   $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

   $user = App\Models\User::first();
   $user->password = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
   $user->save();
   echo "SUKSES: Password admin siap digunakan!\n";
   EOF
   php reset_pwd.php && rm -f reset_pwd.php

   # 6. Bersihkan cache konfigurasi
   php artisan config:clear
   ```

---

## 4. Rincian Kendala yang Ditemui & Solusi Tuntasnya

### Kendala 1: Ukuran ZIP Mencapai ~57 MB
- **Gejala:** File ZIP terasa lebih berat dari perkiraan awal.
- **Penyebab:** Folder `public/assets/office/` memuat seluruh *spritesheet frame* animasi 2.5D untuk 5 Agen AI (~30 MB) dan folder `vendor/` disertakan utuh (~20 MB) agar server hosting tidak perlu menjalankan `composer install`.
- **Solusi:** Dipertahankan penuh agar fitur simulasi visual 5 Agen AI tetap hidup dan tidak ada aset yang hilang saat dibuka pengguna.

### Kendala 2: Error 500 Saat Pertama Kali Dibuka
- **Gejala:** Akses web menghasilkan `500 Server Error`.
- **Penyebab:** Web server Apache/PHP-FPM tidak memiliki izin tulis pada folder `storage/`, `bootstrap/cache/`, dan direktori `database/`.
- **Solusi:** Menjalankan `chmod -R 777 storage bootstrap/cache database` melalui terminal web.

### Kendala 3: Error 500 Saat Menekan Tombol Login (SQLite Relative Path)
- **Gejala:** Halaman login muncul, namun saat klik tombol *Login*, sistem melempar error 500.
- **Penyebab:** Konfigurasi `.env` menuliskan `DB_DATABASE=database/database.sqlite`. Karena Apache mengeksekusi dari `public/index.php`, PHP mencari `public/database/database.sqlite` (tidak ditemukan).
- **Solusi:** Mengubah path database menjadi absolut menggunakan `$(pwd)`:
  ```bash
  sed -i "s|DB_DATABASE=database/database.sqlite|DB_DATABASE=$(pwd)/database/database.sqlite|" .env
  ```

### Kendala 4: Sesi Database di Shared Hosting
- **Gejala:** Potensi *lock* database SQLite ketika sesi web ditulis secara paralel.
- **Penyebab:** Konfigurasi awal menggunakan `SESSION_DRIVER=database`.
- **Solusi:** Mengalihkan penyimpanan sesi ke file lokal (`storage/framework/sessions/`):
  ```bash
  sed -i "s|SESSION_DRIVER=database|SESSION_DRIVER=file|" .env
  ```

### Kendala 5: `RuntimeException: This password does not use the Argon2id algorithm`
- **Gejala:** Login gagal dengan pesan pengecualian algoritma hashing.
- **Penyebab:** Ekstensi PHP di shared hosting tidak mengaktifkan modul Argon2id secara penuh, atau hash password di database tidak cocok dengan driver hashing yang aktif.
- **Solusi:** Menyetel driver hash ke `bcrypt` yang didukung oleh semua versi PHP:
  ```bash
  echo "HASH_DRIVER=bcrypt" >> .env
  ```

### Kendala 6: `Class "Phar" not found` Saat Menjalankan `php artisan tinker`
- **Gejala:** Perintah `php artisan tinker` gagal dengan fatal error `Class "Phar" not found`.
- **Penyebab:** Di server shared hosting IndoGlobal, ekstensi `Phar` sengaja dinonaktifkan pada PHP CLI demi keamanan (*security policy*).
- **Solusi:** Tidak menggunakan *tinker*. Pembaruan record database dilakukan melalui eksekusi skrip PHP mandiri menggunakan `password_hash()` bawaan PHP (`reset_pwd.php`).

### Kendala 7: Peringatan Browser "Tidak Aman" (SSL Let's Encrypt In Progress)
- **Gejala:** Browser menandai URL dengan segitiga merah / *"Sambungan Anda ke situs ini tidak aman"*.
- **Penyebab:** Subdomain baru `ruangperiksa.bpssulteng.id` sedang dalam antrean *batch renewal* Let's Encrypt di menu **SSL/TLS Certificates** panel hosting (`[in progress]`).
- **Solusi:** 
  1. Pengguna dapat langsung menekan **Lanjutan** (*Advanced*) -> **Lanjutkan ke ruangperiksa.bpssulteng.id**.
  2. Dalam waktu 2–5 menit, bot Let's Encrypt menyelesaikan verifikasi ACME HTTP-01 dan status sertifikat otomatis berganti menjadi gembok hijau resmi.

---

## 5. Kredensial & Penggunaan Operasional

### Akses Masuk:
- **Alamat URL:** [https://ruangperiksa.bpssulteng.id](https://ruangperiksa.bpssulteng.id)
- **Email:** `admin@bps.local`
- **Password:** `password`

### Menghubungkan Kunci AI (Vision & BPS WebAPI):
Setelah login ke dashboard `ruangperiksa.bpssulteng.id`:
1. Masuk ke menu **Pengaturan** / **Atur Koneksi AI** (`/settings`).
2. Masukkan URL endpoint router AI (misal LimitRouter / 9Router) dan API Key Anda untuk 5 slot agen.
3. Kunci BPS WebAPI resmi sudah tertanam secara default di `.env` (`48d12534ec7c655e55239dde51e54869be3413d86dfc5ae1076e89258a4fc8ff`).
4. Klik **Simpan & Uji Koneksi**.
