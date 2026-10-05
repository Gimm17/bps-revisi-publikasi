# Ruang Periksa — publikasi Badan Pusat Statistik

Aplikasi internal untuk memeriksa seluruh halaman PDF, menyimpan bukti, menyunting temuan, dan membuat Excel sesuai template pemeriksaan. Laravel 13 + Vue 3/Inertia; tidak memakai Python/local OCR. Desain desktop/mobile mengikuti referensi Stitch pengguna dengan palet krem, mint, koral dan blush.

## Menjalankan lokal

Kebutuhan: PHP 8.3+ (GD, zip, curl, mbstring, intl, PDO SQLite/MySQL), Composer 2, Node 22+, pdfinfo dan pdftoppm pada PATH.

```powershell
cd E:\BPS\REVISI-PUBLIKASI\web
composer install
npm ci
# Instalasi baru saja: salin .env.example ke .env, lalu key:generate.
php artisan migrate
npm run build
php artisan audit:local-access
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal kedua: `php artisan queue:work --sleep=3 --tries=3 --timeout=230`. Terminal ketiga untuk heartbeat: `php artisan schedule:work`. Development frontend opsional: `npm run dev`.

URL http://127.0.0.1:8000. Akses admin awal tersimpan **privat** di `storage/app/private/AKSES-LOKAL.md`; password acak, tidak ditanam dalam source. Perintah tidak mengubah akun yang sudah ada. Ganti password lewat Pengaturan > Pegawai. Jangan mengunggah akun/database/credential lokal ke hosting.

## Alur penggunaan

1. Admin membuat akun pegawai dengan peran Pemeriksa/Reviewer/Admin.
2. Admin menyimpan 9Router base URL, API key, dan model vision pada Pengaturan > Koneksi AI. Untuk host sendiri, pengembang menambahkan hostname persis ke `AI_ALLOWED_HOSTS` pada `.env`, lalu `php artisan optimize:clear`.
3. Jalankan Uji pembacaan gambar. Tantangan visual acak dan halaman PDF template sungguhan harus terbaca. Ini memakai kuota layanan; koneksi text-only tidak lulus.
4. Pegawai unggah PDF dan opsional XLSX. Default memakai `resources/templates/base.xlsx` dari template pengguna. Jumlah halaman divalidasi dari PDF asli. Dokumen, prompt dan snapshot pedoman disimpan privat.
5. Klik Mulai pemeriksaan. Worker menyiapkan setiap gambar dengan Poppler, meminta AI mentranskripsi teks dan inventaris tabel/layout, lalu memvalidasi respons dan bukti. Halaman salah/tidak terbaca/respons terpotong tidak dianggap selesai. Resolusi dinaikkan untuk pembacaan tidak yakin; gagal dapat dicoba ulang.
6. Setelah SELURUH halaman selesai, sistem memeriksa silang identitas, running title, daftar isi, nomor tabel, total dan barcode. Angka yang memerlukan sumber ditandai verifikasi, tidak diganti otomatis.
7. Excel otomatis dibuat dan dibuka ulang untuk validasi border/outline serta pencegahan nilai template “30”. Detail temuan pada L–M; B–K area temuan kosong. File lama tidak ditimpa.
8. Pegawai meninjau bukti, menyunting, menerima/menolak/menandai verifikasi, atau menambahkan temuan manual berbukti. Tiap perubahan mempunyai versi/history. Buat Excel baru setelah perubahan; versi lama ditandai belum memuat revisi terbaru.
9. Reviewer mengunci hasil saat seluruh keputusan selesai dan Excel revisi terakhir tersedia. Admin dapat menugaskan pegawai lain; akses dokumen dibatasi pemilik/penugasan/admin.

Jika API key lama dicabut saat pemeriksaan belum selesai: admin menyimpan dan menguji koneksi baru. Pada publikasi berstatus Perlu dilanjutkan, pilih **Gunakan koneksi AI terbaru** dan setujui perubahan model/penyedia/biaya. Pergantian dicatat, halaman selesai dipertahankan, dan proses tidak boleh sedang membaca halaman ketika koneksi diganti. Tombol Lanjutkan biasa tetap memakai snapshot koneksi lama.

## Batasan penting

**Kode telah diuji; akurasi model nyata belum dibuktikan.** API key 9Router pengguna belum tersedia. Uji otomatis memakai respons AI terkontrol dan PDF sungguhan untuk membuktikan orkestrasi, bukan precision/recall statistik. Pilot Ampana Kota dan evaluasi 12 publikasi dengan label gold manusia wajib sebelum produksi. Lihat `docs/evaluation-results.md` dan `deploy/release-checklist.md`.

ChatGPT Plus bukan API key dan tidak otomatis membayar 9Router. Sign in with ChatGPT untuk aplikasi privat masih disabled sampai jalur resmi private-client tersedia. Tidak ada password/cookie/unofficial token ChatGPT atau fallback berbayar diam-diam.

## Pengujian

```powershell
php artisan test
npm run typecheck
npm run build
php tests/Browser/prepare.php
npm run test:e2e
composer audit
npm audit
```

Browser tests menggunakan Chrome dan SQLite terpisah di `storage/framework/testing/browser.sqlite`, bukan database pegawai. Fixture diberi judul Publikasi Pengujian Browser. Pipeline test menguji pembacaan, validasi dan ekspor dengan HTTP fake. `audit:evaluate PATH_GOLD.json` menghitung hasil pasangan gold–AI yang sudah diadjudikasi manusia.

## Deploy dan pemeliharaan

- `deploy/deployment-runbook.md`: shared hosting, HTTPS, MySQL/Redis, Supervisor, host API, limits.
- `deploy/supervisor-audit.conf.example`: timeout 230 < retry_after 300.
- `deploy/backup-runbook.md`: backup APP_KEY/database/bukti dan rehearsal restore.
- `deploy/release-checklist.md`: gerbang akurasi/keamanan/pilot.
- `audit:health`: Poppler, disk, antrean, heartbeat dan status vision.
- `audit:resume UUID`: lanjutkan publikasi tanpa mengulang halaman selesai.
- `audit:create-admin`: admin produksi, password diminta tersembunyi.

Aturan berada di `audit-rules/pedoman.md` dan `app/Audit/RuleRegistry.php`; kontrak pembacaan pada `resources/prompts/page-audit-v1.md`. Naikkan versi aturan ketika pedoman berubah. `resources/templates/vision-sample.pdf` hanya rujukan uji koneksi, bukan sumber temuan publikasi yang diunggah.
