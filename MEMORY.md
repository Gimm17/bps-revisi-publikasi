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

- **Kode & Unit/Feature Test**: 100% lulus (100/100 PHPUnit tests, 2.309 assertions).
- **TypeScript & Lint**: `vue-tsc --noEmit` 0 errors; Laravel Pint bersih.
- **E2E & UI**: Lulus seluruh skenario pengujian Playwright (responsif 1440px s.d. 390px, tanpa overflow, WCAG A/AA).
- **Keamanan**: Privatisasi berkas PDF/Excel, rate limiting, SSRF protection dengan DNS pinning, sanitasi output Excel dari formula injection.
- **Hosting Aktif (cPanel Shared Hosting)**:
  - URL: `https://bps-cek-publikasi.pinnhost.my.id/`
  - Stack: PHP 8.4 (`alt-php84`), MariaDB, Database Queue, Poppler CLI EL9 di `bps-publication-tools`.
  - Status Terakhir: **Rules V2 (`bps-2026.10.18-v2`) telah berhasil dideploy penuh pada 18 Oktober 2026**.
  - Migrasi diterapkan: `2026_10_18_000001_create_supplemental_check_run_tables`.
  - Cron Workers Aktif: `general`, `ai-1`, `ai-2`, dan `references`.
  - Aset Produksi: Vite bundle versi terbaru aktif dilayani.
- **Catatan Operasional**: Pemeriksaan AI sesungguhnya memerlukan kredensial 9Router/LimitRouter aktif yang telah lulus `testVision`. Rujukan eksternal dapat diintegrasikan dengan Brave Search / Browserless via Pengaturan > Referensi Eksternal.

---

## 9. Detail Arsitektur Superpowers Rules V2 (18 Oktober 2026)

Implementasi **Publication Rules V2** (`docs/superpowers/plans/2026-10-18-publication-rules-v2.md`) memperluas kapabilitas audit dokumen publikasi BPS dengan 5 pemeriksaan tambahan tanpa memecah atau me-reset hasil audit V1 yang telah ada:

### 1. Pemeriksaan Tambahan (Supplemental Checks)
- **Komparasi Visual Kover vs Halaman Judul (`CoverTitleComparator`)**:
  - Kode Aturan: `COVER.TITLE.LAYOUT`.
  - Mengambil pasangan gambar resolusi tinggi kover dan halaman judul (`vision-pair`), lalu mengirimkannya ke model multimodal Vision LLM dengan prompt `resources/prompts/cover-title-v1.md`.
  - Memverifikasi hierarki tipografi, konsistensi penulisan nama wilayah, tahun, dan penempatan logo BPS.
- **Tata Letak Cetak & Halaman Kosong (`PrintLayoutChecker` & `PageAnatomyIndexer`)**:
  - Kode Aturan: `PAGINATION.PRINT_BLANK` dan `CATALOGUE.PAGE_COUNT`.
  - Menganalisis anatomi dokumen secara deterministik: penomoran romawi/Arab, memastikan halaman romawi genap pada layout cetak 2-up kosong jika bab baru dimulai pada halaman ganjil, mendeteksi trailing blanks, serta memvalidasi kesesuaian jumlah halaman katalog dengan halaman terakhir publikasi.
- **Verifikasi Riwayat Volume Edisi Sebelumnya (`VolumeHistoryChecker`)**:
  - Kode Aturan: `VOLUME.PREVIOUS_EDITION`.
  - Menghubungkan nomor volume saat ini ($N$) dengan edisi tahun sebelumnya ($N-1$). Memastikan bahwa kenaikan volume tahunan konsisten dan tidak melompat.
- **Pencarian & Inspeksi Rujukan Publikasi Resmi (`PublicationSearchClient` & `PublicationBrowserClient`)**:
  - Kode Aturan: `OFFICIAL.REFERENCE`.
  - Mengintegrasikan Brave Search API (`BraveSearchClient`) dan headless browser (`BrowserlessPublicationClient`) untuk memverifikasi keabsahan data rujukan BPS dengan pembatasan domain ketat (`ReferenceUrlPolicy`: hanya domain `.bps.go.id` yang diizinkan).
  - Berjalan secara asinkron di antrean khusus `references` via `FetchPublicationReference`.
- **Pelaporan Ketercakupan Kejujuran (`CoverageService`)**:
  - Menggabungkan temuan dari audit per halaman, pemeriksaan silang global, dan pemeriksaan tambahan terhadap matriks `audit-rules/coverage.json` (21 bagian pedoman BPS).
  - Membedakan secara transparan antara aturan yang dievaluasi penuh (*evaluated*), didukung sebagian (*partial*), dan belum didukung (*unsupported*).

### 2. Skema Data Additive
- `publication_check_runs`: Menyimpan batch eksekusi pemeriksaan tambahan berversi (`rule_version`, `status`, `summary`).
- `publication_checks`: Status dan metrik individu per jenis pemeriksaan (`cover_title`, `print_layout`, `volume_history`, `official_reference`).
- `publication_references`: Menyimpan URL rujukan eksternal, judul rujukan, status verifikasi, dan payload artifak bukti.
- `reference_provider_configs`: Kredensial dan endpoint aman untuk provider pencarian eksternal.
- `finding_assessments`: Evaluasi temuan yang dihasilkan dari pemeriksaan tambahan.

### 3. Komponen Antarmuka Pengguna (UI)
- `AdditionalChecksPanel.vue`: Panel ringkasan pemeriksaan tambahan di workspace `Show.vue`.
- `CoverTitleComparison.vue`: Tampilan komparasi berdampingan kover vs halaman judul dengan overlay bounding box.
- `ReferenceEvidencePanel.vue`: Tampilan daftar rujukan resmi BPS dengan tautan terverifikasi dan cuplikan bukti.
- Tab konfigurasi provider rujukan eksternal di halaman `Settings.vue`.

---

## 10. Catatan Operasional Deployment cPanel

- **Akses Hosting**:
  - Server IP: `195.88.211.25` | Port SSH: `6699` | User: `pinnhost`
  - URL Produksi: `https://bps-cek-publikasi.pinnhost.my.id/`
  - Direktori Aplikasi: `/home/pinnhost/apps/bps-publication-audit`
  - Direktori Dokumen Publik: `/home/pinnhost/apps/bps-publication-audit/public`
  - Direktori Alat Poppler: `/home/pinnhost/apps/bps-publication-tools`
  - Direktori Cadangan: `/home/pinnhost/apps/bps-publication-backup`
- **Konfigurasi PHP & Handler**:
  - CLI: `/opt/alt/php84/usr/bin/php`
  - Handler Apache/LiteSpeed: `alt-php84`
  - Upload Limits: `upload_max_filesize = 150M`, `post_max_size = 170M`, `memory_limit = 512M`.
- **Worker Crontab Aktif**:
  ```crontab
  * * * * * /usr/bin/flock -n /home/pinnhost/apps/bps-publication-audit/storage/framework/scheduler.lock /opt/alt/php84/usr/bin/php /home/pinnhost/apps/bps-publication-audit/artisan schedule:run >> /home/pinnhost/apps/bps-publication-audit/storage/logs/scheduler.log 2>&1
  * * * * * /bin/bash /home/pinnhost/apps/bps-publication-audit/deploy/audit-worker.sh general >> /home/pinnhost/apps/bps-publication-audit/storage/logs/worker-general.log 2>&1
  * * * * * /bin/bash /home/pinnhost/apps/bps-publication-audit/deploy/audit-worker.sh ai-1 >> /home/pinnhost/apps/bps-publication-audit/storage/logs/worker-ai-1.log 2>&1
  * * * * * /bin/bash /home/pinnhost/apps/bps-publication-audit/deploy/audit-worker.sh ai-2 >> /home/pinnhost/apps/bps-publication-audit/storage/logs/worker-ai-2.log 2>&1
  * * * * * /bin/bash /home/pinnhost/apps/bps-publication-audit/deploy/audit-worker.sh references >> /home/pinnhost/apps/bps-publication-audit/storage/logs/worker-references.log 2>&1
  ```
- **Prosedur Update Mandiri di Masa Mendatang**:
  1. Build aset lokal: `npm run build` dan jalankan test `php artisan test`.
  2. Cadangkan database dan kode remote ke `apps/bps-publication-backup`.
  3. Upload file release (tanpa `.env`, `vendor`, `storage`, atau `.htaccess` cPanel).
  4. Hapus cache bootstrap lama: `rm -f bootstrap/cache/*.php`.
  5. Jalankan migrasi: `/opt/alt/php84/usr/bin/php artisan migrate --force`.
  6. Optimasi ulang: `/opt/alt/php84/usr/bin/php artisan optimize:clear && /opt/alt/php84/usr/bin/php artisan config:cache && /opt/alt/php84/usr/bin/php artisan route:cache && /opt/alt/php84/usr/bin/php artisan view:cache`.
  7. Kirim sinyal restart antrean: `/opt/alt/php84/usr/bin/php artisan queue:restart`.

---

## 11. Halaman Interaktif Pedoman & Katalog Aturan (/rules)

Ditambahkan pada 19 Oktober 2026 sebagai sarana edukasi, transparansi logika, dan referensi visual baku bagi penelaah publikasi BPS:

- **Route & Controller**:
  - URL: `/rules` (dan alias `/guidelines`), Controller: `App\Http\Controllers\RuleGuideController@index`.
  - Service: `App\Audit\RuleDocumentationService`.
  - Halaman Vue: `resources/js/Pages/Rules/Index.vue`.
  - Tautan Navigasi: Menu "Aturan Periksa" pada header `AppLayout.vue`.
- **Fitur Utama Halaman**:
  1. **Visual Pipeline 6 Tahap**: Menjelaskan alur dari Ingestion PDF, Rasterisasi 160/240 DPI, Vision AI per halaman, Dual-Image Pair, Pemeriksaan Silang & Web Rujukan, hingga Ekspor Excel BPS.
  2. **Panduan Visual Baku Interaktif**:
     - *Mockup Kover & Judul*: Letak nomor katalog, ISSN tanpa titik dua, judul dwibahasa, logo BPS flat tanpa bayangan, dan barcode kover belakang.
     - *Mockup Format Tabel Terbuka*: 3 garis horizontal utama, tanpa garis vertikal, kolom satuan unit, dan konsistensi matematis baris total.
     - *Mockup Tata Letak Cetak 2-Up*: Alasan pendahuluan romawi genap, bab baru selalu di halaman kanan (ganjil), running title ganjil/genap, dan halaman kosong penyeimbang.
  3. **Katalog Terstruktur 38 Aturan**:
     - Filter pencarian cepat real-time, filter kategori (6 kategori), filter tingkat keparahan (`wajib`, `saran`, `verifikasi`), dan filter metode eksekusi.
     - Kartu akordeon detail memuat: ringkasan, cara kerja pemeriksaan, logika algoritma, kondisi lolos vs kondisi pelanggaran, contoh kasus sesuai standar vs contoh kesalahan (Do's & Don'ts), serta rekomendasi perbaikan teknis.
- **Pengujian & QA**:
  - Test feature: `Tests\Feature\RuleGuideTest` (3 tests, 61 assertions, 100% lulus).
  - TypeScript typecheck: `vue-tsc --noEmit` 0 errors.
  - Produksi: Ter-compile di `public/build/assets/Index-C1C514cf.js` dan aktif dideploy di `https://bps-cek-publikasi.pinnhost.my.id/rules`.

---

## 12. Sistem Multi-Agent Paralel (Pool Hingga 5 Agen) & Mode Verifikasi Silang (Cross-Verification)

Diimplementasikan dan diluncurkan ke server produksi pada 19 Oktober 2026 untuk meningkatkan ketelitian audit visual, keandalan model AI, dan throughput pemeriksaan:

### A. Arsitektur Pool 5 Agen AI Paralel
- **Slot Independen (1 s/d 5)**:
  - Setiap slot memiliki konfigurasi mandiri: `name`, `base_url`, `api_key` (terenkripsi di server), `model`, `enabled` (toggle switch ON/OFF), `max_concurrency` (1–5 halaman bersamaan), dan `config_hash`.
  - Status Agen: `untested`, `testing`, `ready`, atau `failed`.
  - Host diizinkan (*whitelisted*): `9router.com`, `api.9router.com`, `limitrouter.com`, `api.limitrouter.com`.
- **Standar Wajib Kemampuan Vision**:
  - Seluruh agen yang aktif **wajib lulus uji visual** sebelum dapat digunakan dalam pemeriksaan publikasi.
  - Pengujian dijalankan via job antrean `TestAiAgent` yang memverifikasi:
    1. *Synthetic Visual Challenge*: Membaca teks acak dengan warna latar dinamis untuk memastikan model tidak sekadar membaca teks ASCII melainkan memproses raster visual.
    2. *Reference PDF Inspection*: Membaca halaman 37 dokumen pedoman publikasi resmi BPS (judul bab, header, nomor halaman cetak).
- **Pengujian Multi-Agent Terpadu & Polling**:
  - Tombol **"Uji Semua Agent Aktif"**: Memicu pengujian paralel seluruh agen aktif secara simultan.
  - Endpoint live status: `GET /settings/ai-agents/status` dipolling frontend setiap 2,5 detik hingga seluruh pengujian selesai.
  - Fitur **"Salin dari Agent 1"**: Memudahkan setup instan untuk slot 2–5 dengan menyalin konfigurasi dasar tanpa mengetik ulang.
- **Penyalur Beban (*AgentPoolSelector*)**:
  - Mendistribusikan halaman audit secara seimbang (*load-balanced*) di antara agen-agen yang berstatus `ready`.
  - Menghindari agen yang sedang terkena cooldown batas kuota / HTTP 429 (`AiCapacity`).

### B. Mode Verifikasi Silang (*Cross-Verification*)
- **Sasaran Halaman Berisiko Tinggi (*High Risk Pages*)**:
  - Halaman struktur utama: Kover luar (`cover`), Kover dalam / Halaman Judul (`title`), Katalog BPS (`catalogue`), Tim Penyusun (`team`), Kata Pengantar (`preface`), Daftar Isi (`toc`), dan Kover Belakang (`back_cover`).
  - Halaman yang memuat data tabel statistik (`inventory.tables` terdeteksi atau terdapat temuan kategori `TABLE.*`).
- **Heuristik Khusus Perataan Kolom Tabel (*Table Alignment*)**:
  - Berdasarkan pedoman BPS, perataan sel tabel memiliki aturan ketat:
    - Kolom label / teks wajib **rata kiri** (*left-aligned*).
    - Kolom angka statistik numerik wajib **rata kanan** (*right-aligned*).
    - Kolom kode wilayah, lambang, atau simbol wajib **rata tengah** (*centered*).
  - Karena secara visual selisih alignment sering kali samar (*subtle*), `CrossVerifier::checkTableAlignmentSpecifics` memindai alignment kolom dan teks pendukung untuk mendeteksi ketidaksesuaian format `TABLE.FORMAT`.
- **Rekonsiliasi Temuan & Kebijakan Badge**:
  - Dua agen AI yang berbeda memeriksa halaman yang sama secara terpisah.
  - Temuan yang disepakati kedua agen mendapatkan badge `2 Agen Sepakat` (`confirmed`).
  - Temuan yang hanya ditemukan salah satu agen mendapatkan badge `1 Agen` (`single_agent`).
  - Sesuai keputusan penelaah (*"KASIH BADGE AJA"*), temuan satu agen tidak memblokir kelulusan audit otomatis, melainkan diberi badge jelas dan filter khusus pada antarmuka penelaahan agar reviewer dapat memprioritaskan peninjauan.

### C. Skema Database
- **Tabel Baru**:
  - `ai_agents`: Slot (1..5), nama, base_url, api_key, model, enabled, status, test_message, vision_tested_at, config_hash, max_concurrency.
  - `publication_ai_agents`: Snapshot relasi agen yang aktif saat publikasi mulai diperiksa beserta `config_hash_at_start`.
  - `page_verifications`: Hasil verifikasi silang per halaman (`publication_page_id`, `ai_agent_id`, `status`, `result`, `agreement`, `error`).
- **Kolom Baru**:
  - `publications.cross_verify_mode`: Mode verifikasi (`high_risk`, `all_pages`, `off`).
  - `publication_pages.ai_agent_id`: ID agen utama yang membaca halaman.
  - `findings.agreement`: Status kesepakatan (`confirmed` / `single_agent`).
  - `findings.agent_ids`: JSON array ID agen yang mengonfirmasi temuan.
  - `ai_usage_records.ai_agent_id`: Tracking biaya & token per agen.

### D. Status Deployment & Operasional cPanel
- **Aplikasi Online**: `https://bps-cek-publikasi.pinnhost.my.id/` (HTTP 200 OK).
- **Database Backup**: Terverifikasi non-zero byte (`db-before-multiagent-*.sql`, 1,8 MB).
- **Crontab Worker**:
  - `audit-worker.sh general` (antrean `default`)
  - `audit-worker.sh ai-1`, `ai-2`, `ai-3` (antrean `ai,verify`)
  - `audit-worker.sh verify` (antrean `verify,ai`)
  - `audit-worker.sh references` (antrean `references`)
- **Hasil Pengujian**:
  - Unit & Feature Test: **110 tests, 2.402 assertions, 100% LULUS**.
  - TypeScript Typecheck: `vue-tsc --noEmit` 0 error.
  - Asset Build: Vite production build berhasil.

---

## 13. Resolusi Masalah Audit Terhenti di Halaman 114 & Otomatisasi Aturan V2 Global Pipeline

### A. Diagnosa & Resolusi Halaman Macet (114/118 Halaman)
Pada audit publikasi riil BPS (Kecamatan Dalam Angka 118 halaman), audit sempat terhenti di 114 halaman karena 4 halaman mengalami kegagalan berulang (`failed` / `partial_failure`):
1. **PDF Halaman 9 & 10 (Kata Pengantar & Preface)**:
   - **Penyebab**: Terpicunya filter keselamatan `recitation` Google Gemini saat model mencoba mentranskripsi paragraf teks pembuka standar BPS secara *verbatim* (>150 token). Gemini memutus respons dengan `finish_reason: "recitation"` dan `content: null`. Pada versi sebelumnya, `NineRouterClient.php` mengklasifikasikan `content: null` sebagai kesalahan non-retryable fatal (`failed_image`).
   - **Solusi**:
     - `NineRouterClient.php`: Menangani alasan pemutusan `recitation`, `safety`, dan `block` sebagai error yang dapat ditangani atau di-retry, bukan fatal `failed_image`.
     - `resources/prompts/page-audit-v1.md` & `app/Audit/PromptBuilder.php`: Memodifikasi instruksi prompt khusus halaman Kata Pengantar dan Preface agar AI mengekstrak struktur penting (judul, tanggal pengesahan, jabatan/penandatangan) dan membuat ringkasan singkat per paragraf, menghindari transkripsi kata-per-kata yang memicu filter hak cipta/recitation.
2. **PDF Halaman 15 & 17 (Daftar Tabel xiii & xv)**:
   - **Penyebab**: Baris terbawah pada daftar tabel (tabel 4.4.2 pada hal 15 dan tabel 7.2 pada hal 17) pada naskah asli PDF BPS mengalami pemotongan teks atau tidak mencantumkan nomor halaman target cetak. `PageResultValidator.php` sebelumnya menolak keras `target_page: null` jika `readability === 'readable'`.
   - **Solusi**:
     - `PromptBuilder.php` & `PageResultValidator.php`: Mengizinkan `target_page: null` dengan status `readability: 'uncertain'` apabila nomor halaman pada baris daftar tabel memang tidak tercetak pada dokumen sumber.
     - `app/Jobs/AnalyzePage.php`: Pada resolusi tinggi (240 DPI / `high_dpi`), halaman dengan status `uncertain` tidak lagi digagalkan sebagai `partial_failure`. Temuannya disimpan dan halaman diselesaikan (`completed`) disertai catatan verifikasi manual (*review flag*), sehingga tidak memblokir kelanjutan audit publikasi.

### B. Otomatisasi Pemeriksaan V2 ke Dalam Pipeline Global
Sebelumnya, pemeriksaan aturan V2 (Perbandingan Kover vs Judul, Riwayat Volume, dan Layout Cetak) memerlukan klik tombol manual di antarmuka web.
- **Integrasi Otomatis**:
  - `app/Jobs/FinalizeAudit.php`: Ketika seluruh halaman publikasi selesai diproses (`all_completed`), sistem secara otomatis memicu `AdditionalCheckCoordinator::start($publication)` jika aturan V2 diaktifkan (`config('audit.rules_v2_enabled')`).
  - `app/Audit/AdditionalCheckCoordinator.php`: Mengotomatiskan eksekusi seluruh rangkaian pemeriksaan tambahan (`executeCoverTitle`, `executeVolume`, `executePrintLayout`) dan memulihkan pemeriksaan yang tertunda (*pending*).
  - **Otomatisasi Ekspor Excel BPS**: Setelah seluruh pemeriksaan V2 selesai (`status === 'completed'`), metode `advance()` pada `AdditionalCheckCoordinator.php` otomatis memicu `AuditCoordinator::export($publication)`. Publikasi langsung berpindah ke status `review_ready` dengan file laporan Excel resmi BPS yang siap diunduh tanpa intervensi manual pengguna.
  - `resources/js/Components/AdditionalChecksPanel.vue`: Antarmuka diperbarui untuk mencerminkan status otomatisasi (badge *"Otomatis Berjalan"* dan panduan bahwa pemeriksaan V2 terintegrasi dalam alur utama).

### C. Hasil Deployment & Verifikasi di Live Server (cPanel)
- Perubahan dideploy dan diuji langsung pada server produksi (`https://bps-cek-publikasi.pinnhost.my.id/`).
- Kedua publikasi uji berhasil diproses hingga tuntas **118 / 118 halaman (100% Selesai)**:
  - `kecamatan ampana tete dalam angka 2026`: 118/118 halaman selesai, 3 pemeriksaan V2 tuntas, file ekspor Excel v3 tergenerate otomatis.
  - `kecamatan ampana kota dalam angka 2026`: 118/118 halaman selesai, 3 pemeriksaan V2 tuntas, status `review_ready` dengan file Excel tergenerate otomatis.

---

## 14. Resolusi Antrean Worker cPanel & Diagnosa Pengujian Agen AI yang Tertahan

### A. Akar Masalah Pengujian Berputar Terus (*Pending Stalled*)
Pengujian pembacaan gambar AI sempat tampak tidak selesai (*stuck* 30 menit) dengan status `Sedang menguji koneksi dan pembacaan visual PDF di background antrean...`:
1. **Pekerja Antrean Default Tidak Aktif di Crontab cPanel**:
   - Job pengujian koneksi visual (`App\Jobs\TestAiAgent`) serta job sistem lainnya (seperti `WriteWorkbook` dan `RenderPage`) masuk ke antrean `default`.
   - Pada saat diperiksa, konfigurasi crontab di server cPanel hanya menjalankan worker untuk antrean `verify` dan `ai-3`, tanpa worker `general` (yang bertugas mengonsumsi antrean `default`).
   - Akibatnya, job `TestAiAgent` menumpuk di database antrean dengan `attempts: 0` dan tidak pernah dieksekusi oleh worker mana pun.

### B. Resolusi & Penguatan Ketahanan Antrean
1. **Penyempurnaan Worker Script (`deploy/audit-worker.sh`)**:
   - Seluruh worker `ai-*` dan `verify` kini menyertakan antrean `default` sebagai *fallback*:
     - `ai-1` .. `ai-5`: `task_queue="ai,verify,default"`
     - `verify`: `task_queue="verify,ai,default"`
     - `references`: `task_queue="references,default"`
   - Dengan konfigurasi ini, jika tidak ada tugas audit per halaman, worker akan otomatis mengambil dan mengeksekusi job sistem dan pengujian AI dari antrean `default`.
2. **Restorasi Crontab Lengkap di Server cPanel**:
   - Mengaktifkan seluruh worker cron: `schedule:run`, `general`, `ai-1`, `ai-2`, `ai-3`, `verify`, dan `references`.
3. **Eksekusi Job Tertahan**:
   - Semua job pengujian yang tertahan langsung diproses hingga selesai.

### C. Hasil Pengujian Model AI
- **Slot 1 (Agent 1 - Utama)**: `gemini-3.8-flash` via LimitRouter -> **LULUS** (`ready`).
- **Slot 2 (Agent 2)**: `gemini-3.8-flash` via LimitRouter -> **LULUS** (`ready`).
- **Slot 3 (Agent 3)**: `gemini-2.8-flash` via 9Router -> **GAGAL (HTTP 404)**. Model `gemini-2.8-flash` tidak ditemukan pada katalog 9Router (solusi: ganti nama model menjadi `gemini-2.5-flash` atau `gemini-1.5-flash`).

---

## 15. Optimalisasi SEO Lengkap, Share Link Thumbnail (OpenGraph/Twitter), Indexasi Google Engine, & Responsif Mobile

### A. Desain Share Link Thumbnail (OpenGraph & Twitter Card)
- **Aset Visual Resolusi Tinggi (1200x630 px)**:
  - Dihasilkan via Pillow (`public/images/og-thumbnail.png` dan salinan `public/og-image.png`).
  - Mengikuti palet resmi BPS: Mint `#BADFDB`, Cream `#FCF9EA`, Coral `#FFA4A4`, dan Ink `#302B2A`.
  - Memuat logo brand BPS ("Ruang Periksa Publikasi"), badge *"Standar V2 BPS 2026"*, judul utama, sub-judul, dan 3 kartu fitur visual (*Multi-Agent Vision*, *32 Aturan Pedoman*, *Format Excel Resmi B–M*).
  - Dilengkapi favicon resolusi tinggi: `favicon-32x32.png`, `apple-touch-icon.png` (180x180 px), dan `site.webmanifest`.

### B. Meta Tags SEO & Schema.org JSON-LD (Google Search Indexable)
- **Tag Head Lengkap (`resources/views/app.blade.php`)**:
  - `meta description`: Menjelaskan fungsi audit visual publikasi resmi BPS berbasis Vision AI multimodal.
  - `meta keywords`: Kata kunci relevan audit BPS (*Kecamatan Dalam Angka, KcDA, Kendali Mutu Publikasi, Vision AI, Excel BPS*).
  - `meta robots`: `index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1`.
  - `canonical link`: Mengarah otomatis ke URL halaman saat ini.
  - `og:image` & `twitter:image`: Mengarah ke URL absolut `https://bps-cek-publikasi.pinnhost.my.id/images/og-thumbnail.png` dengan rasio 1200x630 px dan format Twitter `summary_large_image`.
  - **Schema.org Structured Data**: Dibungkus `@verbatim` Blade untuk mencegah parsing simbol `@` sebagai directive Blade; memuat skema `WebApplication` dan `WebSite` dengan publisher `Badan Pusat Statistik`.

### C. Keterbukaan Indexasi Googlebot & Akses Tamu
- **`public/robots.txt`**: Mengizinkan perayapan halaman publik (`/`, `/rules`, `/guidelines`, `/login`, `/build/`, `/images/`), membatasi direktori internal/upload privat, dan merujuk sitemap.
- **`public/sitemap.xml`**: Format standar Google Image Sitemap untuk mengindeks halaman publik beserta gambar thumbnail.
- **Akses Publik Halaman Aturan (`/rules` & `/guidelines`)**:
  - Dipindahkan keluar dari middleware `auth` pada `routes/web.php` agar crawler Googlebot dan pengunjung publik dapat langsung mengakses katalog 21 bab pedoman BPS tanpa terhalang 302 redirect ke `/login`.
  - `AppLayout.vue` & `types.ts`: Diperbarui agar aman menangani `auth.user` nullable (menampilkan tombol *"Masuk"* saat guest, tanpa runtime error `page.props.auth.user.name`).

### D. Penyempurnaan Tata Letak Responsif Mobile (<768px, <640px, <480px, <420px)
- **Navigasi Header**: Main navigation dan logo brand kini menggunakan smooth horizontal scroll tanpa scrollbar mengganggu pada layar kecil (<640px), subtitle brand disembunyikan agar tombol tindakan tetap pas di layar.
- **Multi-Agent Slot Selector**: Slot tombol agen 1–5 pada halaman Pengaturan diubah menjadi pill horizontal-scrollable (`overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch`) sehingga tidak bertumpuk berantakan di layar ponsel.
- **Katalog Aturan (`Rules/Index.vue`)**:
  - Filter pencarian dan dropdown kategori/urgensi/mode ditata rapi secara vertikal full-width pada mobile (<768px).
  - Kartu metrik hero menyesuaikan ke grid 2-kolom (<768px) dan 1-kolom (<480px).
  - Visual mockup dan diagram perataan tabel dapat di-scroll horizontal tanpa menyebabkan overflow halaman.
  - Banner panduan footer otomatis beralih ke tata letak vertikal dengan tombol full-width.

### E. Verifikasi & Status Deployment cPanel
- **Test Suite**: 110 tests, 2.400 assertions **100% LULUS**.
- **Vite Compilation**: Berhasil dicompile tanpa error/warning (`npm run build`).
- **Server Deployment**: Seluruh file dan aset build dideploy ke cPanel (`pinnhost@195.88.211.25:6699`).
- **Live Smoke Test (HTTP 200 OK)**:
  - `https://bps-cek-publikasi.pinnhost.my.id/robots.txt` (200 OK)
  - `https://bps-cek-publikasi.pinnhost.my.id/sitemap.xml` (200 OK)
  - `https://bps-cek-publikasi.pinnhost.my.id/site.webmanifest` (200 OK)
  - `https://bps-cek-publikasi.pinnhost.my.id/images/og-thumbnail.png` (200 OK, 66.518 bytes image/png)
  - `https://bps-cek-publikasi.pinnhost.my.id/rules` (200 OK)
  - `https://bps-cek-publikasi.pinnhost.my.id/login` (200 OK)


---

## 17. Resolusi Halaman Macet di 99% (Halaman 102 dari 103) & Penanganan Keterbacaan Uncertain

### A. Diagnosa & Akar Masalah Stuck di 99% (`partial_failure`)
Pada publikasi `kecamatan tanantovea dalam angka 2026` (`0245ac8e-9a4d-48e4-92cb-6703546773a1`, 103 halaman), audit terhenti di 102/103 halaman (99%) dengan status `partial_failure`:
1. **Penyebab Kontrak Prompt & PDF Asli BPS**:
   - Halaman fisik 14 adalah Daftar Tabel (halaman romawi `xii`).
   - Pada naskah asli PDF BPS, baris entri terbawah (Tabel 4.1.4) terpotong/bersambung ke halaman berikutnya sehingga tidak mencantumkan nomor halaman target cetak pada halaman 14.
   - Kontrak prompt AI (`PromptBuilder.php` & `PageResultValidator.php`) secara tegas mewajibkan: apabila nomor halaman entri daftar tabel belum tercantum/terpotong, gunakan `target_page: null` dan `readability: 'uncertain'`. Jika AI mengisi `readable`, validator melempar exception: *"Nomor halaman tujuan entri daftar belum terbaca. Gunakan uncertain, jangan menebak nomor halaman."*
   - Model Vision AI (Agent 2) mengekstrak halaman 14 dengan sangat akurat dan menemukan 2 temuan nyata:
     - Salah ketik judul: *"Tertulis 'Jumlah Kepalad Sekolah dan Pendidik Menurut Tingkat Pendidikan' (terdapat salah ketik 'Kepalad') - LANGUAGE.EJAAN"*.
     - Entri terputus: *"Entri Tabel 4.1.4 di bagian bawah halaman terputus: hanya memuat judul bahasa Indonesia tanpa judul bahasa Inggris, tanpa titik penuntun, dan tanpa nomor halaman tujuan - CONTENTS.MATCH"*.
   - Mengikuti kontrak prompt, AI mengembalikan `readability: 'uncertain'`.
2. **Kelemahan Penanganan High DPI di `app/Jobs/AnalyzePage.php`**:
   - Kode awal `AnalyzePage.php` memeriksa `if ($result['readability'] !== 'readable')`.
   - Pada resolusi standar (160 DPI), sistem menaikkan resolusi ke 240 DPI (`high_dpi`) dan melakukan render ulang.
   - Namun, karena pemotongan entri tabel 4.1.4 ada pada naskah PDF asli (bukan masalah resolusi gambar), pada 240 DPI model tetap mengembalikan `readability: 'uncertain'`.
   - Pada kondisi `dpi >= 240`, `AnalyzePage.php` mengeksekusi `$publication->update(['status' => 'partial_failure'])` dan langsung keluar (`return;`).
   - Akibatnya:
     - Temuan AI (salah ketik `Kepalad` dan entri terputus) **dibuang dan tidak pernah disimpan ke database**.
     - `page_type` dan `printed_page` tetap `NULL`.
     - Status halaman tetap `needs_verification` (tidak pernah `complete`), sehingga syarat kelengkapan publikasi (`AuditCoordinator::canComplete`) selalu bernilai `false`.
     - Pemeriksaan silang, pemeriksaan aturan V2, dan ekspor Excel final tidak pernah berjalan.

### B. Solusi Arsitektural (`app/Jobs/AnalyzePage.php`)
1. **Pemisahan `unreadable` vs `uncertain`**:
   - `unreadable`: Hanya jika halaman benar-benar tidak terbaca sama sekali (misal scan hitam, file korup). Jika masih di bawah 240 DPI diekskalasi; jika sudah di 240 DPI dan tetap `unreadable`, baru ditandai `needs_verification` dan publikasi `partial_failure`.
   - `uncertain`: Menandakan halaman terbaca dan terstruktur, tetapi memiliki catatan keraguan (misal teks sel kecil atau nomor halaman terpotong). Jika masih di 160 DPI diekskalasi ke 240 DPI. Jika sudah di 240 DPI, sistem **tetap menyimpan seluruh temuan AI**, menyimpan `page_type` dan `printed_page`, menyelesaikan halaman (`status: 'complete'`), menyertakan catatan verifikasi manual pada field `error`, dan memanggil `$coordinator->afterPage($publication)` sehingga alur audit berlanjut tuntas.
2. **Pembaruan Test Unit & Feature**:
   - `PipelineTest.php`: Memvalidasi bahwa halaman `uncertain` pada resolusi tinggi berpindah ke status `complete`, mencatat temuan, dan mengizinkan publikasi selesai tanpa `partial_failure` (7 tests, 31 assertions, 100% lulus).

### C. Verifikasi & Hasil Live Server cPanel
1. File `app/Jobs/AnalyzePage.php` dideploy ke server produksi cPanel dan cache dioptimasi (`optimize:clear` & `optimize`).
2. Halaman 14 publikasi Tanantovea diproses:
   - Status Halaman 14: `complete`, Jenis: `table_list` (Daftar Tabel), Halaman Cetak: `xii`.
   - 2 temuan halaman 14 (salah ketik `'Kepalad'` dan entri terputus) berhasil disimpan.
3. Pipeline otomatis berjalan:
   - Progres publikasi: **103 / 103 halaman (100% Selesai)**.
   - Status publikasi: `review_ready`.
   - Total temuan: **290 temuan** (sebelumnya 288).
   - Seluruh pemeriksaan V2 (`cover_title`, `print_layout`, `volume`) selesai (`completed`).
   - Ekspor Excel resmi BPS versi 4 tergenerate lengkap dan siap diunduh di dashboard.








