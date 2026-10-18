# MEMORY: Ruang Periksa — Sistem Pemeriksaan Publikasi BPS

Dokumen memori ini mencatat pemahaman komprehensif, arsitektur, basis kode (*codebase*), logika bisnis, dan tumpukan teknologi (*technology stack*) dari proyek **Ruang Periksa (REVISI-PUBLIKASI)** pada folder `web`.

---

## 1. Ikhtisar & Tujuan Bisnis

- **Nama Aplikasi**: Ruang Periksa — Publikasi Badan Pusat Statistik (BPS).
- **Tujuan Utama**: Aplikasi web internal BPS untuk melakukan audit dan kendali mutu komprehensif terhadap dokumen publikasi PDF resmi (seperti *Kecamatan Dalam Angka / KcDA* dan *Kabupaten/Provinsi Dalam Angka*).
- **Tugas Kunci**:
  1. Memeriksa seluruh halaman PDF secara visual dan struktural tanpa OCR lokal Python, melainkan melalui Poppler CLI + Vision LLM API (9Router/LimitRouter).
  2. Menyimpan bukti visual otentik (*bounding box* dan kutipan teks).
  3. Menyediakan antarmuka penelaahan (*Human-in-the-Loop*) untuk menyetujui, menolak, mengedit bukti, atau menambah temuan manual dengan riwayat berversi.
  4. Menghasilkan laporan hasil telaah ke format Excel resmi BPS (template kolom B–M) tanpa merusak styling, outline, atau formula.

---

## 2. Technology Stack

### Backend
- **PHP**: 8.4 (kompatibel 8.3+)
- **Framework**: Laravel 13 (`laravel/framework: ^13.17`)
- **Adapter SPA**: Inertia.js Laravel (`inertiajs/inertia-laravel: 2.*`)
- **PDF Engine**: Poppler CLI (`pdfinfo` untuk metadata/manifest, `pdftoppm` untuk rasterisasi JPEG)
- **Spreadsheet**: PhpSpreadsheet (`phpoffice/phpspreadsheet: ^5.10`)
- **Database & Queue**: SQLite (testing & dev lokal), MariaDB/MySQL (production). Driver antrean: `database` (lokal & shared hosting), mendukung `redis` (Supervisor).

### Frontend
- **Framework**: Vue 3.5 (`vue: ^3.5.0`, Composition API dengan `<script setup lang="ts">`)
- **Client Adapter**: Inertia.js Vue 3 (`@inertiajs/vue3: ^2.3.0`)
- **Bahasa**: TypeScript 5.9 (`typescript: ^5.9.0`, `vue-tsc`)
- **Bundler**: Vite 8 (`vite: ^8.0.0`, `@vitejs/plugin-vue`)
- **Styling**: Tailwind CSS v4 (`@tailwindcss/vite`, `tailwindcss: ^4.0.0`) + `resources/css/design.css`
- **Design System**: Palet warna *Stitch* BPS (Krem `#FBF8F3`, Mint `#E6F2EF`, Koral `#D96B43`, Blush `#F7E5DE`, Dark `#2B2D2F`)
- **Ikon & Tipografi**: `lucide-vue-next`, `@fontsource/geist-sans`, `@fontsource/outfit`

### Testing & QA
- **Unit & Feature Test**: PHPUnit 12 (`phpunit/phpunit: ^12.5.12`) — **100 tests, 2.309 assertions** (100% lulus).
- **E2E Browser Test**: Playwright (`@playwright/test: ^1.58.0`) — multi-viewport (`journey.spec.ts`, `additional-checks.spec.ts`).
- **Aksesibilitas**: Axe-core (`@axe-core/playwright`) lulus WCAG A/AA.
- **Code Formatter**: Laravel Pint (`laravel/pint: ^1.27`).
- **Typecheck**: `vue-tsc --noEmit` lulus bersih tanpa error.

---

## 3. Struktur Direktori Utama

```
web/
├── app/
│   ├── Audit/                    # Core Domain & Audit Engine
│   │   ├── AiCapacity.php        # Concurrency limiter & cooldown per host
│   │   ├── AiRequestException.php# Exception domain AI (network, format, quota)
│   │   ├── AdditionalCheckCoordinator.php # Orkestrator pemeriksaan tambahan aturan V2
│   │   ├── AiCapacity.php        # Concurrency limiter & cooldown per host
│   │   ├── AiRequestException.php# Exception domain AI (network, format, quota)
│   │   ├── AuditCoordinator.php  # Orkestrator alur audit & ekspor
│   │   ├── BraveSearchClient.php # Search client publikasi pembanding BPS
│   │   ├── BrowserlessPublicationClient.php # Remote headless browser inspector
│   │   ├── CoverageService.php   # Perhitungan cakupan gabungan & kejujuran laporan
│   │   ├── CoverTitleComparator.php # Dual-image visual layout comparator
│   │   ├── CrossChecker.php      # Pemeriksaan silang global (identitas, TOC, total, barcode)
│   │   ├── EvaluationScorer.php  # Scoring precision/recall dataset acuan
│   │   ├── EvidenceValidator.php # Normalisasi union evidence (publication_page & reference_artifact)
│   │   ├── FindingWriter.php     # Penyimpanan temuan unik berbasis SHA-256 fingerprint
│   │   ├── NineRouterClient.php  # HTTP Client 9Router/LimitRouter + SSRF protection + testVision
│   │   ├── PageAnatomyIndexer.php# Indeks anatomi nomor romawi/Arab & trailing blank
│   │   ├── PageResultValidator.php# Validasi skema JSON AI, bounding box, dan quote match
│   │   ├── PdfPreparer.php       # Wrapper pdfinfo & pdftoppm
│   │   ├── PrintLayoutChecker.php# Validasi kelengkapan romawi genap & trailing blank cetak
│   │   ├── PromptBuilder.php     # Penyusun pesan prompt multimodal + aturan per halaman
│   │   ├── ReferenceUrlPolicy.php# Kebijakan keamanan URL & domain BPS
│   │   ├── RuleEvaluator.php     # Evaluator aturan deterministik (ISSN format & checksum)
│   │   ├── RuleRegistry.php      # Kamus 32 kode aturan audit & pedoman BPS V2
│   │   ├── VolumeHistoryChecker.php # Validasi volume edisi sebelumnya (N+1)
│   │   └── WorkbookWriter.php    # Generator XLSX resmi BPS + verifikasi post-write
│   ├── Http/Controllers/
│   │   ├── AuthController.php    # Login & Logout (throttle:login)
│   │   ├── FindingController.php # CRUD temuan, revisi, riwayat, version lock
│   │   ├── PublicationCheckController.php # Supplemental check runs, retry, manual reference, streaming
│   │   ├── PublicationController.php # Upload, alur periksa, viewer, ekspor, assign, lock/unlock
│   │   └── SettingsController.php# Kelola pegawai, simpan & uji koneksi AI & reference provider
│   ├── Jobs/
│   │   ├── AnalyzePage.php       # Job AI per halaman (antrean 'ai', timeout 220s, retry backoff)
│   │   ├── FetchPublicationReference.php # Job pencarian & inspeksi publikasi resmi BPS (antrean 'references')
│   │   ├── FinalizeAudit.php     # Job pemeriksaan silang setelah seluruh halaman selesai
│   │   ├── RenderPage.php        # Job Poppler rasterize PDF ke JPEG 160 DPI (antrean 'default')
│   │   ├── WorkerHeartbeat.php   # Cron heartbeat worker
│   │   └── WriteWorkbook.php     # Job generate Excel
│   ├── Models/                   # Publication, PublicationPage, Finding, FindingRevision,
│   │                             # PublicationCheckRun, PublicationCheck, PublicationReference,
│   │                             # ReferenceProviderConfig, FindingAssessment,
│   │                             # Export, AiProviderConfig, AiUsageRecord, AuditEvent, User
│   └── Services/
│       ├── AuditLogger.php       # Pencatat audit trail ke tabel audit_events
│       └── CreatePublication.php # Validasi upload PDF & template XLSX, buat halaman
├── audit-rules/
│   ├── coverage.json             # Matriks cakupan seluruh 21 bagian pedoman audit BPS
│   ├── pedoman.md                # Dokumentasi lengkap aturan telaah publikasi BPS V1
│   └── pedoman-v2.md             # Authoritative guideline BPS V2
├── database/migrations/          # Skema basis data terpadu (termasuk migrasi aditif supplemental check)
├── resources/
│   ├── css/design.css            # Sistem token & kelas desain UI Stitch
│   ├── js/
│   │   ├── Components/           # FindingEditor, StatusBadge, AdditionalChecksPanel,
│   │   │                         # CoverTitleComparison, ReferenceEvidencePanel, dll.
│   │   ├── Pages/
│   │   │   ├── Dashboard.vue     # Dasbor metrik & daftar publikasi
│   │   │   ├── Publications/     # Index.vue, Create.vue, Show.vue (Workspace viewer)
│   │   │   └── Settings.vue      # Pengaturan koneksi AI & pegawai
│   │   └── types.ts              # Kontrak tipe TypeScript antarmuka data
│   ├── prompts/
│   │   ├── cover-title-v1.md     # Prompt dual-image visual cover-title
│   │   ├── page-audit-v1.md      # Kontrak prompt vision LLM per halaman
│   │   └── volume-reference-v1.md# Prompt ekstraksi volume rujukan
│   └── templates/
│       ├── base.xlsx             # Template resmi BPS (kolom B–M)
│       └── vision-sample.pdf     # File PDF referensi uji visual
└── routes/web.php                # Definisi route terproteksi middleware auth & throttle
```

---

## 4. Alur Kerja Logika Inti (The 6 Stages)

### Tahap 1: Pengunggahan & Validasi (`CreatePublication`)
1. User mengunggah PDF publikasi (dan opsional file template Excel kustom).
2. `PdfPreparer::inspect()` menjalankan `pdfinfo`:
   - Validasi magic byte `%PDF-`.
   - Memastikan tidak terkunci password.
   - Validasi jumlah halaman (1 s.d. 500 halaman).
   - Ekstrak ukuran poin, SHA-256 checksum PDF.
3. Validasi XLSX template:
   - Perlindungan Zip Bomb (< 40MB extracted, < 2500 file).
   - Memastikan bebas dari macro/VBA (`vbaproject`).
   - Memastikan memiliki header baris 1–6 dan kolom B–M.
4. Membuat record `Publication` (UUID) dan membuat record `PublicationPage` untuk halaman fisik 1 s.d. $N$ dengan status `pending`. Menyimpan snapshot aturan dan prompt secara lokal agar audit konsisten dan terlindungi dari perubahan konfigurasi di masa depan.

### Tahap 2: Manajemen & Verifikasi AI Vision (`NineRouterClient`)
1. **Keamanan Jaringan & SSRF**:
   - URL wajib HTTPS, hostname harus masuk dalam whitelist `AI_ALLOWED_HOSTS` (`9router.com`, `limitrouter.com`).
   - Pada mode production, DNS IP di-resolve dan dicek agar tidak mengakses IP privat/link-local/loopback. IP di-pin via `CURLOPT_RESOLVE`.
2. **Uji Vision Wajib (`testVision`)**:
   - Tahap A (Dinamis): Membentuk gambar GD di memori dengan teks instruksi, kode visual acak (*random hex*), dan soal penjumlahan tabel desa. Model AI harus mengembalikan JSON dengan kode dan total yang tepat.
   - Tahap B (PDF Riil): Merender halaman 37 `vision-sample.pdf`. AI harus membaca visual heading "PENJELASAN TEKNIS" / "TECHNICAL NOTES", halaman tercetak "13", dan running title "PEMERINTAHAN".
   - Jika gagal, publikasi tidak dapat diproses.

### Tahap 3: Pemrosesan Halaman Asinkron (`RenderPage` & `AnalyzePage`)
1. **RenderPage**:
   - Menggunakan `pdftoppm` merender physical page ke file JPEG 160 DPI standar dan 60 DPI untuk thumbnail.
   - Mengubah status ke `ready` dan mendispatch `AnalyzePage` ke antrean khusus `ai`.
2. **AnalyzePage**:
   - Dibatasi `WithoutOverlapping('audit-page-'.$pageId)` dan `ShouldBeUnique`.
   - Mengirimkan JPEG terkompresi base64 bersama prompt sistem.
   - Validasi respons melalui `PageResultValidator`:
     - Skema JSON wajib memuat `physical_page`, `page_type`, `readability`, `inventory`, `identity`, `contents`, `findings`.
     - **Anti-Halusinasi**: Kutipan bukti (`quote`) temuan wajib ada di dalam teks transkripsi halaman; koordinat `bbox` wajib bernilai 0..1 dan valid ($y_1 < y_2, x_1 < x_2$).
     - Jika AI menjawab `uncertain` atau `unreadable`, DPI otomatis dinaikkan ke 240 DPI (`high_dpi`) dan halaman dirender ulang.
   - `RuleEvaluator` mengecek aturan lokal deterministik: format `ISSN: ` pada katalog, `ISSN ` pada kover, serta validasi checksum ISSN dengan algoritma Modulo 11.
   - `FindingWriter` menyimpan temuan ke database dengan deduplikasi sidik jari SHA-256 (`fingerprint`).

### Tahap 4: Pemeriksaan Silang Global (`FinalizeAudit` & `CrossChecker`)
Dipicu otomatis hanya ketika **100% halaman** selesai (`canComplete() === true`):
1. **Identitas**: Memastikan judul publikasi, tahun, nomor volume, nomor katalog, dan penerbit sama di kover, halaman judul, dan halaman katalog.
2. **Running Title**: Halaman tercetak ganjil harus menggunakan judul bab; halaman genap harus menggunakan judul publikasi.
3. **Daftar Isi vs Isi Dokumen**: Memastikan judul dan target halaman yang tercantum di Daftar Isi benar-benar cocok dengan isi halaman fisik target.
4. **Penomoran Tabel**:
   - Mendeteksi nomor tabel duplikat (yang bukan lanjutan).
   - Mendeteksi urutan nomor tabel yang melompat (misal: 2.1 ke 2.3).
5. **Kesesuaian Total Tabel**:
   - Menghitung jumlah matematis elemen baris komponen (`sum(component_rows)`).
   - Membandingkan dengan nilai yang tercetak pada baris `total`. Jika selisih $> 0.01$, menghasilkan temuan berstatus `verifikasi` (angka tidak diganti otomatis!).
6. **Barcode Kover Belakang**: Memastikan barcode ISSN terdeteksi di kover belakang jika publikasi memiliki ISSN.

### Tahap 5: Telaah & Adjudikasi Pemeriksa (`FindingController` & `Show.vue`)
1. Workspace menyajikan viewer gambar PDF dengan pan, zoom, rotasi, dan overlay kotak bukti (*bounding box*).
2. Temuan dapat difilter berdasarkan halaman saat ini atau seluruh halaman, keparahan (`wajib`, `saran`, `verifikasi`), dan status tinjauan (`unreviewed`, `accepted`, `rejected`, `needs_verification`).
3. **Optimistic Version Locking**: Field `version` pada tabel `findings` mencegah penimpaan data antar pemeriksa. Jika versi tidak cocok, request ditolak dengan pesan peringatan.
4. Seluruh riwayat perubahan tersimpan di `finding_revisions` (`before`, `after`, user_id, reason).
5. Mendukung penambahan temuan manual yang mewajibkan input halaman fisik dan kutipan bukti.

### Tahap 6: Generator Excel & Penguncian (`WorkbookWriter`)
1. Mengambil template `base.xlsx`, menghapus isi baris 7 ke bawah dan unmerge cell lama.
2. Kolom B–K dikosongkan (area ceklis template). Kolom L memuat bullet/kategori, dan kolom M memuat uraian detail temuan: aturan, yang ditemukan, yang seharusnya, rekomendasi revisi, dan kutipan bukti.
3. **Chunking Anti-Clipping**: Algoritma `chunks()` membagi teks panjang agar tidak melampaui 24 baris per row atau 409pt tinggi sel Excel.
4. **Safety Verification**: File XLSX yang selesai ditulis dibuka kembali di memori:
   - Memastikan kolom B–K benar-benar null (tidak ada nilai tersembunyi).
   - Memastikan border bawah pada kolom M utuh (thin border).
   - Memastikan tidak ada cell tipe formula atau error (mencegah formula injection).
5. **Export Parsial**: Jika ada halaman yang macet atau butuh laporan cepat, user dapat mengunduh Excel sementara berlabel *"SEMENTARA BELUM LENGKAP"* tanpa menghilangkan progres yang sudah dicapai.
6. **Locking**: Publikasi hanya dapat dikunci (`final`) jika 100% halaman selesai, tidak ada temuan berstatus `unreviewed` atau `needs_verification`, dan Excel revisi terbaru telah berhasil dibuat.

---

## 5. Model Data & Skema Database

- `users`: ID, nama, email, password, role (`examiner`, `reviewer`, `admin`), `is_active`.
- `ai_provider_configs`: ID, `base_url`, `api_key` (terenkripsi), `model`, `tested_at`, `vision_tested_at`, `created_by`.
- `publications`: UUID, `owner_id`, judul, daerah, tahun, jenis (`kcda`), `pdf_path`, `template_path`, `page_count`, `status` (`draft`, `processing`, `cross_checking`, `exporting`, `review_ready`, `final`, `partial_failure`), `manifest`, `revision`, `locked_at`.
- `publication_user`: Pivot penugasan pemeriksa tambahan ke publikasi.
- `publication_pages`: ID, `publication_id`, `physical_page`, `status` (`pending`, `rendering`, `ready`, `analyzing`, `complete`, `needs_verification`, `failed`), `image_path`, `thumbnail_path`, `dpi` (160/240), `attempts`, `page_type`, `printed_page`, `result` (JSON transkripsi/inventaris).
- `findings`: ID, `publication_id`, `fingerprint` (SHA-256), `rule_code`, `section`, `severity` (`wajib`, `saran`, `verifikasi`), `source` (`ai_visual`, `deterministic`, `cross_check`, `manual`), `confidence`, `observed`, `expected`, `recommendation`, `evidences` (JSON array: page, quote, bbox), `review_status` (`unreviewed`, `accepted`, `rejected`, `needs_verification`), `review_note`, `version`, `deleted`.
- `finding_revisions`: ID, `finding_id`, `user_id`, `before` (JSON), `after` (JSON), `reason`.
- `exports`: ID, `publication_id`, `version`, `publication_revision`, `status`, `path`, `checksum`, `finding_count`, `validation` (JSON), `created_by`.
- `audit_events`: ID, `user_id`, `publication_id`, `action`, `details` (JSON).
- `ai_usage_records`: ID, `publication_id`, `page_id`, `model`, `request_id`, `usage` (token prompt & completion), `duration_ms`, `status`.

---

## 6. Kode Aturan Audit BPS (Rule Registry)

| Kode Aturan | Deskripsi Singkat |
| :--- | :--- |
| `ISSN.COLON` | Pemakaian tanda titik dua pada ISSN (`ISSN: ` di katalog, `ISSN ` di kover) |
| `ISSN.CHECKSUM` | Validasi digit kontrol ISSN menggunakan Modulo 11 |
| `BPS.FULL_NAME` | Penulisan nama lengkap Badan Pusat Statistik |
| `IDENTITY.CONSISTENCY` | Konsistensi judul, tahun, katalog, dan penerbit di seluruh bagian |
| `COVER.COMPONENTS` | Kelengkapan dan hierarki elemen tata letak kover |
| `COVER.LOGO.NO_EFFECT`| Logo BPS resmi tanpa efek bayangan/gradien/distorsi digital |
| `COVER.ILLUSTRATION` | Keberadaan sumber ilustrasi dan kontras teks kover |
| `BACK.BARCODE` | Barcode ISSN resmi pada kover belakang |
| `CATALOGUE.COMPONENTS`| Kelengkapan nomor katalog, nomor publikasi, dan hak cipta |
| `CATALOGUE.LABELS` | Label katalog dalam format dwibahasa (Indonesia - Inggris) |
| `CATALOGUE.PAGE_COUNT`| Kesesuaian jumlah halaman tercetak dengan halaman terakhir |
| `TEAM.FORMAT` | Format tim penyusun, penyunting, dan penata letak |
| `PREFACE.FORMAT` | Format kata pengantar, tanggal pengesahan, dan tanda tangan |
| `CONTENTS.MATCH` | Kesesuaian judul dan nomor halaman daftar isi dengan isi dokumen |
| `RUNNING.TITLE` | Running title halaman ganjil (judul bab) dan genap (judul publikasi) |
| `PAGINATION.ORDER` | Urutan nomor halaman romawi dan angka arab |
| `TABLE.NUMBER` | Urutan penomoran tabel (tidak ganda dan tidak melompat) |
| `TABLE.TITLE` | Format judul tabel, satuan unit, dan periode waktu |
| `TABLE.FORMAT` | Format tabel terbuka, garis batas, dan alignment sel |
| `TABLE.SYMBOL` | Simbol statistik (`-`, `...`, `r`, `*`), catatan kaki, dan sumber |
| `TABLE.TOTAL` | Konsistensi matematis baris Total terhadap jumlahan komponen |
| `TABLE.CONSISTENCY` | Konsistensi data yang sama di tabel berbeda |
| `FIGURE.FORMAT` | Format penomoran, judul, sumber, dan grafik gambar |
| `FIGURE.DATA` | Kesesuaian visualisasi grafik dengan data tabel |
| `LANGUAGE.BILINGUAL` | Tata letak format teks dwibahasa (Indonesia miring/tegak vs Inggris) |
| `LANGUAGE.EJAAN` | Ejaan bahasa Indonesia baku (EYD) dan istilah teknis |
| `BIBLIOGRAPHY.FORMAT`| Format penulisan daftar pustaka |
| `VISUAL.LAYOUT` | Kerapian tata letak, margin, dan keterbacaan |
| `VISUAL.TYPOGRAPHY` | Konsistensi penggunaan jenis dan ukuran font |
| `MANUAL.REVIEW` | Temuan telaah manual yang ditambahkan oleh pemeriksa |

---

## 7. Perintah CLI Penting (Artisan Commands)

- `php artisan audit:local-access` : Membuat akun admin awal lokal secara privat di `storage/app/private/AKSES-LOKAL.md`.
- `php artisan audit:health` : Memeriksa ketersediaan Poppler (`pdfinfo`, `pdftoppm`), kapasitas disk, status antrean, dan heartbeat worker.
- `php artisan audit:resume {UUID}` : Melanjutkan publikasi yang terhenti/gagal tanpa mengulang halaman yang sudah complete.
- `php artisan audit:prepare-parallel` : Menyesuaikan metadata antrean sebelum mengaktifkan worker paralel.
- `php artisan audit:create-admin` : Membuat akun admin produksi dengan password interaktif tersembunyi.
- `php artisan audit:evaluate {PATH_GOLD.json}` : Menjalankan evaluasi akurasi antara hasil AI dan data gold standar manusia.

---

## 8. Ringkasan Status & Kesiapan Sistem

- **Kode & Unit Test**: 100% lulus (42/42 PHPUnit tests, 1.688 assertions).
- **E2E & UI**: Lulus seluruh skenario pengujian Playwright (responsif 1440px s.d. 390px, tanpa overflow, WCAG A/AA).
- **Keamanan**: Privatisasi berkas PDF/Excel, rate limiting, SSRF protection dengan DNS pinning, sanitasi output Excel dari formula injection.
- **Hosting Aktif**: `https://bps-cek-publikasi.pinnhost.my.id` (PHP 8.4, MariaDB, Database Queue).
- **Catatan Operasional**: Pemeriksaan AI sesungguhnya memerlukan kredensial 9Router aktif yang telah lulus `testVision`.
