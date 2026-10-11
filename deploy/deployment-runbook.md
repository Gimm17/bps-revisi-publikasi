# Deployment shared hosting BPS

## Gerbang rilis

Aplikasi telah dapat dijalankan lokal. Jangan menyatakan akurasi pemeriksaan produksi telah terbukti sebelum uji 9Router sungguhan dan evaluasi publikasi acuan. Tidak ada secret atau akun ChatGPT yang ditanam dalam kode.

## Kebutuhan server

- PHP 8.3+ dengan PDO MySQL, mbstring, intl, GD, zip, curl, openssl; PHP CLI yang sama versinya.
- MySQL 8, Redis + ekstensi phpredis, Supervisor, cron, pdfinfo dan pdftoppm.
- Composer 2, akses SSH; Node 22+ untuk build (boleh build di komputer pengembang).
- HTTPS; direktori aplikasi berada DI LUAR document root, document root hanya `web/public`.
- Ruang disk minimum 5 GB untuk pilot. Ukur kebutuhan nyata dari jumlah publikasi dan DPI; jangan mengasumsikan kapasitas hosting berdasarkan spesifikasi saja.

## Instalasi

1. Upload kode `web` beserta lockfile, template, pedoman, dan aset build. Jangan upload `.env` lokal, database SQLite lokal, `tests`, direktori desain referensi, log, atau `storage/app/private/AKSES-LOKAL.md`.
2. Jalankan `composer install --no-dev --optimize-autoloader`. Build dengan `npm ci && npm run typecheck && npm run build` pada mesin build. Node tidak diperlukan untuk melayani aset hasil build.
3. Buat `.env` privat dari `.env.example`, kemudian isi konfigurasi berikut melalui akses hosting yang aman:

```dotenv
APP_NAME="Ruang Periksa"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DOMAIN-BPS
APP_LOCALE=id
HASH_DRIVER=argon2id
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=DATABASE
DB_USERNAME=USER_APLIKASI
DB_PASSWORD=SECRET_PRIVAT
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_SAME_SITE=lax
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=SECRET_PRIVAT
REDIS_QUEUE_RETRY_AFTER=300
AI_ALLOWED_HOSTS=HOST-9ROUTER-YANG-DISETUJUI
PDFINFO_BINARY=/usr/bin/pdfinfo
PDFTOPPM_BINARY=/usr/bin/pdftoppm
LOG_LEVEL=warning
```

4. Jalankan `php artisan key:generate` HANYA pada instalasi baru, `php artisan migrate --force`, `php artisan optimize`. Simpan APP_KEY dalam backup terenkripsi; mengganti tanpa migrasi akan membuat API key lama tidak dapat didekripsi.
5. Beri izin tulis hanya pada `storage` dan `bootstrap/cache` untuk user proses aplikasi, bukan 777. Jangan membuat symlink storage privat ke public. Batasi user database runtime; gunakan akun migration terpisah bila kebijakan hosting memungkinkan.
6. Aktifkan HTTPS di webserver dan redirect HTTP. Jika berada di reverse proxy, percayai hanya IP proxy yang diketahui (konfigurasi pengembang), jangan wildcard trust proxy sembarang. CSP, nosniff, frame restriction, dan cookie aman sudah disiapkan aplikasi. HSTS hanya dikirim pada request HTTPS.
7. Sesuaikan `upload_max_filesize=150M`, `post_max_size=160M`, `memory_limit=512M`, serta batas body webserver. Worker `timeout=230` harus LEBIH KECIL dari `retry_after=300`. Supervisor di contoh memakai dua worker; mulai satu bila RAM/kuota rendah, ukur dahulu sebelum menambah.
8. Tambahkan cron tiap menit: `* * * * * /usr/bin/php /home/ACCOUNT/publication-audit/artisan schedule:run >> /dev/null 2>&1`. Jadwalkan worker heartbeat dan pantau keterlambatannya.
9. Jalankan `php artisan audit:create-admin --email=EMAIL-ADMIN --name="Nama Admin"`. Password diminta tersembunyi, minimal 12 karakter. Akun lokal tidak dipakai di produksi.
10. Admin membuka Pengaturan > Koneksi AI, memasukkan base URL, API key dan model vision. Host harus persis termasuk `AI_ALLOWED_HOSTS`; aplikasi memblokir redirect, IP literal, dan DNS privat saat produksi. Koneksi baru wajib lulus dua tes gambar: tantangan acak dan halaman PDF rujukan. Tes menggunakan kuota API. Publikasi menyimpan snapshot koneksi, pedoman dan prompt untuk keterlacakan.
11. Jalankan `php artisan audit:health`, uji akun pegawai, upload satu PDF, worker, retry, editor, ekspor, penugasan, dan penguncian. Periksa seluruh status halaman, bukan hanya file Excel.

## Update aplikasi

Backup dahulu. Aktifkan maintenance bila migration memerlukan. Deploy release baru, install dependencies/build, `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart`, lalu matikan maintenance. Jangan memakai `migrate:fresh`, mengganti APP_KEY, atau menimpa storage/database pegawai.

## Pemulihan

- Kuota/model/HTTP gagal: benahi koneksi atau kuota; pada publikasi klik Lanjutkan / Coba ulang halaman. Hasil halaman selesai tetap dipertahankan. Jika kunci snapshot lama dicabut, admin menyimpan serta menguji koneksi baru; pemeriksa memilih **Gunakan koneksi AI terbaru** dan menyetujui pergantian model/biaya. Pergantian dicatat, hanya boleh saat partial_failure dan tidak ada halaman sedang dibaca.
- Worker restart: job reserved dilepas setelah retry_after; unique/overlap locks mencegah duplikasi. Tunggu expiry lock (hingga 15 menit), lalu `php artisan audit:resume UUID` untuk publikasi tertentu. Jangan flush Redis bersama layanan lain.
- Ekspor gagal: baca status pada tab Excel, cek disk dan template, lalu buat versi baru. Versi lama tidak ditimpa.
- Peninjauan belum selesai: jangan bypass final lock. Reviewer menyelesaikan keputusan, membuat Excel revisi terakhir, kemudian mengunci.
- Opsi cron worker terbatas: `queue:work --stop-when-empty --max-time=50 --timeout=230` tidak cocok bila hosting mematikan proses AI sebelum timeout. Wajib buktikan proses panjang berjalan; bila tidak, worker perlu dipindahkan ke layanan terpisah yang disetujui.

## Pemantauan

Pantau heartbeat >3 menit, failed jobs >0, lama antrean, error provider, token tercatat, disk <2 GB, durasi halaman dan ekspor. Jumlah token bukan tagihan; biaya bergantung pada penyedia. Tidak ada fallback berbayar otomatis atau login ChatGPT tidak resmi.

File scanning: integrasikan ClamAV pada boundary upload bila tersedia. Saat ini aplikasi memvalidasi signature PDF, Poppler parse, encryption/page limit, MIME, arsip XLSX, ukuran terkompresi/uncompressed dan macro. Tidak mengklaim pemeriksaan antivirus. Update Poppler/dependencies secara berkala; jalankan worker dengan privilege minimum.

## Instalasi cPanel aktif — 11 Oktober 2026

- Domain: `bps-cek-publikasi.pinnhost.my.id`; SSH server `195.88.211.25:6699`, akun `pinnhost`. Password tidak disimpan di source atau runbook.
- Aplikasi: `/home/pinnhost/apps/bps-publication-audit`; document root: direktori `public` di dalam aplikasi. Folder subdomain awal dan konfigurasi sebelum deploy dipertahankan.
- PHP CLI/web: alt-php84; aplikasi production/debug=false, cookie secure/encrypted dan HTTPS redirect. Database/user terpisah: `pinnhost_bpscek`; password dibuat acak di server.
- Redis/Supervisor tidak tersedia. Database queue/cache/session dan satu worker cron dipakai. `flock` mencegah worker ganda. Worker memakai timeout230 < retry_after300; max-time300 diperiksa antarkerja, bukan batas keras yang memotong job berjalan. Cron proyek lain dipertahankan.
- Alat PDF privat di `/home/pinnhost/apps/bps-publication-tools`. Poppler-utils dan libpoppler paket AlmaLinux EL9 `21.01.0-24.el9_8.1` diunduh dari repository resmi; checksum diverifikasi terhadap metadata repo. Wrapper memakai library privat, tidak mengganti library/server global.
- PHP dependency install memakai lockfile/no-dev; aset dibangun lokal. Environment dan akses admin awal mode0600. `.env` dan dokumen privat tidak dapat diunduh melalui domain.
- Upgrade: backup database/storage/APP_KEY terlebih dahulu; deploy aset, pertahankan `.env`, PHP handler dan pengalihan HTTPS pada public/.htaccess. Jangan mengunggah kredensial lokal atau menimpa folder tools/backup.
- Masih perlu API vision nyata, evaluasi gold, pilot publikasi dan uji beban. Jangan menyamakan deployment berhasil dengan recall/precision model yang sudah terjamin.
