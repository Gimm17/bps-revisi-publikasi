# IMPLEMENTATION_PLAN — Ruang Periksa (Laravel)

**Tujuan dokumen:** rencana upgrade terukur agar proyek `E:\BPS\REVISI-PUBLIKASI\web` (Laravel 13 + Vue 3/Inertia) menjadi lebih **akurat**, **lebih cepat**, dan **lebih murah** — dengan mengambil ide yang terbukti dari proyek pembanding `evaluasi-kcda-bps` (Python/FastAPI).

**Tanggal disusun:** 2026-10-24
**Baseline versi aturan:** `bps-2026.10.05-v1` (prompt `page-v1`), target `bps-2026.10.18-v2`
**Status:** rencana, belum ada kode yang diubah.

---

## 0. Ringkasan Eksekutif

### 0.1 Kondisi saat ini (terukur, bukan perkiraan)

| Metrik | Nilai | Sumber |
|---|---|---|
| LOC PHP (`app/`) | 9.273 | hitung langsung |
| LOC Vue/TS (`resources/js/`) | 13.490 | hitung langsung |
| LOC tes | 4.934 (155 method, 45 file) | hitung langsung |
| Kode aturan (`RuleRegistry`) | **38 kode** (terverifikasi via regex) | `app/Audit/RuleRegistry.php` |
| Dokumen pedoman | 439 baris / 29.688 byte (`pedoman.md`), 468 baris (`pedoman-v2.md`) | `audit-rules/` |
| Kamus ejaan | 4.039 kata (124 + 1.068 + 2.847) | `resources/dictionaries/` |
| Halaman Vue | 10, terbesar 113 KB (`AgentOffice/Index.vue`) | `resources/js/Pages/` |
| Bundle JS terbesar | 279 KB `app-*.js` + 86 KB CSS | `public/build/assets/` |
| Metode AI aktif | vision per halaman, 9Router `/chat/completions` | `NineRouterClient.php` |
| Prompt caching | **tidak ada** | grep `cache_control` → 0 |
| Biaya sistem prompt | ~9.400 token × N halaman | lihat §4.1 |

### 0.2 Tiga masalah utama (urut dampak)

1. **Akar akurasi:** AI diberi `text_layer` PDF sebagai "rujukan" (`PromptBuilder.php:46-49`) padahal text layer bisa berisi **ghost text** yang tidak kasat mata. Ini sumber false positive utama. Proyek Python punya mekanisme khusus bernama *visual visibility arbitration* yang belum dimiliki Laravel.
2. **Akar kecepatan:** (a) `pdftoppm` dipanggil **2× per halaman** sebagai proses terpisah (`RenderPage.php:61,64`) → 300 proses untuk 150 halaman; (b) system prompt 9.400 token **dikirim ulang untuk setiap halaman** tanpa cache; (c) dashboard menembak `visibleTo()` **4 kali** (`PublicationController.php:41,48-50`).
3. **Akar biaya:** 1,41 juta token system prompt untuk publikasi 150 halaman. Pada model fallback `claude-sonnet-4-6` (config `audit.php:25`) itu ≈ **$4,22 per publikasi hanya untuk prompt yang berulang**, belum termasuk gambar dan output.

### 0.3 Prinsip rencana

- **Jangan ganti arsitektur.** AI vision tetap inti. Yang ditambah adalah lapisan deterministik di sekelilingnya.
- **Deterministik dulu, AI kemudian.** Apa pun yang bisa diputuskan tanpa AI (checksum, pola placeholder, aritmetika) diputuskan tanpa AI → hemat token, nol variansi.
- **Setiap perubahan harus punya alat ukur.** Tidak ada klaim "lebih akurat" tanpa gold dataset (§8).
- **Aditif, bukan destruktif.** Migrasi DB harus `Schema::table` yang nullable, agar rollback aman seperti preseden `2026_10_18_000001`.

---

## 1. Metode & Baseline yang Harus Dibekukan Dulu

Sebelum menyentuh kode, bekukan pengukuran. Tanpa ini, "lebih akurat" hanya opini.

### T1.1 — Buat gold dataset berlabel manusia
**Masalah:** `README.md:40` menyatakan sendiri *"akurasi model nyata belum dibuktikan"*. Sekarang DB kosong (`agents=0 ready=0 pubs=0 findings=0`).
**Aksi:**
- Kumpulkan **12 publikasi** KcDA (Banggai Kepulauan atau Tojo Una-Una) + 1 publikasi Kabupaten.
- Ambil 3 publikasi (Ampana Kota, Ratolindo, Wale Kepulauan — sudah ada XLSX koreksi di `E:\BPS\REVISI-PUBLIKASI\`) sebagai kalibrasi, sisanya uji.
- Untuk setiap publikasi, tandai temuan gold: `{page, rule_code, observed, expected, severity, verdict: true_positive | false_positive}`.
- Simpan sebagai `tests/gold/<kode-wilayah>.json` (format sudah dipakai `audit:evaluate`).
**Bukti file:** `app/Console/Commands/EvaluateGoldenDataset.php`, `app/Audit/Benchmark/GoldenMatcher.php` sudah siap.
**Acceptance:** `php artisan audit:evaluate tests/gold/ampana-kota.json` menghasilkan precision, recall, F1 baseline yang tercatat di `docs/evaluation-results.md`.
**Effort:** 2–3 hari (manual labeling, tidak bisa ditekan).

### T1.2 — Bekukan baseline performa
**Aksi:** catat untuk 3 publikasi representatif (60, 150, 300 halaman):
- waktu render (detik), waktu AI total, waktu ekspor
- jumlah request AI, token input/output, biaya USD menurut `AiTelemetryService`
- jumlah temuan, jumlah false positive menurut gold
**Output:** tabel baseline di §8.
**Effort:** 0,5 hari (ada DB telemetri, tinggal query).

### T1.3 — Tambahkan benchmark command
**Aksi:** buat `php artisan audit:benchmark {publication}` yang menjalankan pipeline pada PDF contoh dan mencetak: total durasi, durasi per tahap, token, biaya, jumlah temuan. Pakai `Http::fake()` untuk CI (pola sudah dipakai `tests/Feature/PipelineTest.php`).
**Effort:** 1 hari.

---

## 2. Diagnosa Akurasi — di mana penilaian saat ini bisa salah

### A.1 — `text_layer` menanchorkan AI ke teks yang mungkin tak kasat mata 🔴 Kritis

**Bukti:** `app/Audit/PromptBuilder.php:46-49`
```php
if (! empty($page->text_layer) && config('audit.text_layer_enabled', true)) {
    $snippet = mb_substr(trim((string) $page->text_layer), 0, 6000);
    $userPromptText .= "\n\nTEKS LAPISAN PDF (rujukan ejaan & angka, perhatikan gambar tetap sumber visual utama):\n```text\n".$snippet."\n```";
}
```
**Masalah:** pada PDF hasil InDesign/Canva, di balik gambar kover sering masih ada **text layer template lama** (mis. `KECAMATAN YYYYY`, `1102001.xxxx`, ISSN wilayah lain). Model membaca itu lalu melaporkan temuan yang **tidak terlihat oleh mata manusia** — false positive yang persis menjadi keluhan proyek Python (`pdf_reader_engine.py:1-33`).

**Aksi:**
1. Tambahkan `app/Audit/Text/TextLayerGate.php` yang menandai setiap baris text_layer sebagai `visible` / `suspect_invisible`:
   - Baris yang mengandung pola placeholder template (`YYYYY`, `XXXXX`, `1102001.xx`, `COVER DEPAN`, `Lorem ipsum`) **selalu** `suspect`.
   - Bila `Storage` punya gambar halaman, bandingkan panjang teks: jika text_layer > 3× karakter yang wajar untuk halaman itu dan halaman punya ≥1 gambar besar → `suspect`.
2. Ubah prompt menjadi dua blok terpisah:
   ```
   TEKS LAYER TERVERIFIKASI (boleh dipakai sebagai rujukan ejaan):
   ...
   TEKS LAYER MENCURIGAKAN — DILARANG dilaporkan sebagai temuan
   kecuali terlihat pada gambar:
   ...
   ```
3. Tambahkan satu baris tegas di system prompt: *"Jika teks layer bertentangan dengan gambar, gambar menang. Jangan laporkan teks yang tidak terlihat pada gambar."*
**File:** `app/Audit/Text/TextLayerGate.php` (baru), `PromptBuilder.php`, `RenderPage.php` (simpan hasil gate di `publication_pages`).
**Migrasi:** `add_text_layer_gate_to_publication_pages` (`text_layer_suspect_lines` JSON nullable).
**Dampak:** menghilangkan kelas false positive terbesar; juga memotong token karena baris suspect tidak dikirim.
**Acceptance:** pada gold dataset, false positive dengan `rule_code` `COVER.*`, `TITLE.*`, `CATALOGUE.*` turun ≥ 40%.

---

### A.2 — Halaman kover & daftar isi tidak pernah diverifikasi silang 🔴 Bug

**Bukti:** `app/Audit/CrossVerifier.php:35`
```php
$highRiskTypes = ['cover', 'title', 'catalogue', 'team', 'preface', 'toc', 'back_cover'];
```
Sedangkan `PageResultValidator.php:46` hanya mengizinkan:
```
front_cover, title, catalogue, team, preface, contents, table_list,
figure_list, chapter, table, narrative, bibliography, blank, back_cover
```
→ `'cover'` dan `'toc'` **tidak pernah cocok**. Artinya dalam mode `high_risk`, kover depan dan daftar isi **tidak divertifikasi agen kedua** — padahal itu halaman paling rawan.

**Aksi:** ganti menjadi konstanta yang diturunkan dari daftar valid:
```php
private const HIGH_RISK_PAGE_TYPES = ['front_cover', 'title', 'catalogue', 'team', 'preface', 'contents', 'back_cover'];
```
Tambahkan unit test yang mengunci kedua daftar agar tidak melenceng lagi.
**File:** `CrossVerifier.php`, `tests/Unit/CrossVerifierPageTypeTest.php`.
**Dampak:** verifikasi silang benar-benar aktif untuk 7 halaman kritis.
**Effort:** 2 jam.

---

### A.3 — Tidak ada deteksi "template mentah" / "salah wilayah" sebelum menghabiskan token 🔴 Kritis (biaya + akurasi)

**Bukti:** Laravel hanya mendeteksi via AI (`PageResultValidator` tidak punya konsep template mentah). Proyek Python punya dua detektor deterministik yang sangat bernilai:
- `extractor.py:1242-1372` — **raw template detector**: `COVER DEPAN`, `COVER BELAKANG`, `YYYYY`, `Kecamatan XXX`, `KABUPATEN/KOTA XXXX`, `NAMA TANPA GELAR`, `xxxxx • xxxxx`, `Lorem ipsum`, `1102001.xx`, `ISSN XXXX - XXXX`, `Dinas xxx`. Ambang: ≥10 residu = *pure template* (ditolak total), ≥5 residu di ≥3 seksi = *raw draft* (revisi total).
- `extractor.py:1076-1240` — **district identity mismatch**: bandingkan nama kecamatan di kover vs frekuensi nama kecamatan di batang tubuh, HJU, katalog, kata pengantar. Jika kover A tetapi isi didominasi B → **fatal**.

**Aksi:** pindahkan keduanya sebagai modul deterministik yang berjalan **setelah `RenderPage`, sebelum `AnalyzePage`**:
- `app/Audit/Deterministic/TemplateResidueDetector.php`
- `app/Audit/Deterministic/IdentityMismatchDetector.php`
- Kalau `pure_template` → set `publication.status = 'rejected_template'`, batalkan antrean AI, hasilkan laporan tanpa biaya.
- Kalau `identity_mismatch` → tetap jalankan AI tetapi suntikkan fakta ke prompt sebagai konteks.
**File baru** + `RenderPage.php` + `AuditCoordinator.php` + `RuleRegistry` (dua kode baru di bawah).
**Rule code baru:** `DOCUMENT.TEMPLATE_RESIDUE`, `IDENTITY.WILAYAH_MISMATCH`.
**Dampak:** menghemat 100% biaya untuk file template; menangkap kasus "hanya ganti kover" yang saat ini luput.
**Effort:** 3 hari.

---

### A.4 — Aturan deterministik di Laravel baru 2 dari 38 kode

**Bukti:** `app/Audit/RuleEvaluator.php` hanya mengimplementasikan `ISSN.COLON` dan `ISSN.CHECKSUM` (29 baris). 36 kode lain hanya bisa muncul dari AI.

**Aksi:** lihat §3 (matriks migrasi) — pindahkan 18 aturan Python yang murni deterministik menjadi `RuleEvaluator` yang diperluas.

---

### A.5 — Ejaan: Levenshtein O(n×kamus) berpotensi menimbulkan false positive

**Bukti:** `app/Audit/Text/SpellChecker.php:236-264` — untuk setiap token tak dikenal, iterasi seluruh 4.039 kata kamus dan hitung `levenshtein`. Dua masalah: (a) lambat; (b) nama desa, istilah lokal, dan singkatan daerah **tidak ada di kamus** → bisa disarankan sebagai "typo".

**Aksi:**
1. Indeks kamus `length → first_char → [words]` sehingga pencarian O(k) bukan O(n).
2. Tambahkan **whitelist dinamis per publikasi**: nama wilayah dari `Publication.region`, nama kecamatan/desa yang muncul ≥3× di text layer, dan nama institusi — semuanya dianggap sah.
3. Naikkan ambang: hanya laporkan bila `reason ∈ {known_typo, missing_hyphen, conjoined_words}`, dan jadikan `levenshtein_distance_1` sebagai `severity = 'saran'` dengan `confidence ≤ 0.6` (otomatis menjadi `verifikasi` karena `FindingWriter.php:30` menurunkan severity bila confidence < .80).
**Dampak:** false positive ejaan turun, waktu spellcheck turun >10×.
**Effort:** 1,5 hari.

---

### A.6 — Ringkasan aturan dwibahasa belum menyentuh tabel per bab

**Bukti:** `RuleRegistry` punya `LANGUAGE.BILINGUAL`, tetapi tidak ada implementasi deterministik; keputusan sepenuhnya dari AI per halaman, sehingga konsistensi antar-bab tidak terbaca.
**Aksi:** tambahkan pemeriksaan global di `FinalizeAudit` (setelah `CrossChecker`): untuk setiap tabel yang diinventaris AI, cek apakah `title` memuat `/` + padanan Inggris, dan apakah blok `Sumber/Source` + `Catatan/Note` dwibahasa. Laporkan sebagai satu temuan agregat per publikasi (bukan per halaman) agar tidak membanjiri laporan.
**Effort:** 1,5 hari.

---

## 3. Matriks Migrasi Aturan dari Proyek Python

Kolom **Prioritas**: P0 = langsung, P1 = fase menengah, P2 = opsional.
Semua implementasi **tanpa AI** (regex/geometri dari data yang sudah ada) kecuali ditandai.

| # | Aturan Python (sumber) | Kode Laravel baru | Yang diperiksa | Prio | Dampak |
|---|---|---|---|---|---|
| 1 | `extractor.py:1242-1372` raw template | `DOCUMENT.TEMPLATE_RESIDUE` | 10 pola template master | **P0** | Tolak template tanpa biaya AI |
| 2 | `extractor.py:1076-1240` identity mismatch | `IDENTITY.WILAYAH_MISMATCH` | Kover vs isi dominan | **P0** | Tangkap "hanya ganti kover" |
| 3 | `extractor.py:978-989` katalog cross-page | `CATALOGUE.CROSS_PAGE` | Nomor katalog beda antar halaman | **P0** | 15 hal. pertama |
| 4 | `extractor.py:886-934` ISSN cross-page | `ISSN.CROSS_PAGE` (perluas yg ada) | ISSN beda antar halaman + checksum per halaman | **P0** | Sudah ada checksum, belum lintas halaman |
| 5 | `extractor.py:995-1006` wrong year | `PAGINATION.WRONG_YEAR` | "Dalam Angka 2024" di publikasi 2026 | **P0** | Saltik tahun tersebar |
| 6 | `extractor.py:1011-1074` global typos | `LANGUAGE.EJAAN` (perluas) | 19 pola saltik khusus BPS | **P0** | Gratis, presisi tinggi |
| 7 | `extractor.py:995-1006` + `1823-1829` YYYYY running title | `DOCUMENT.RUNNING_TITLE_TEMPLATE` | YYYYY di running title | P1 | Bagian dari #1 |
| 8 | `extractor.py:2072-2446` tabel granular | `TABLE.*` (perluas) | titik di nomor/judul, kata "Tahun", Keterangan vs Catatan, km2, kolom melompat, superscript tanpa catatan, baris total di atas | **P0** | 9 sub-aturan bernilai tinggi |
| 9 | `extractor.py:2218-2235` tabel kosong/elipsis | `TABLE.EMPTY` | Sel kosong total, elipsis, nol vs en dash | **P0** | Deteksi "belum diisi" |
| 10 | `extractor.py:1941-1991` romawi & Bab 1 | sudah ada `PRINT.ROMAN_END` | — | — | Sudah tercakup v2 |
| 11 | `extractor.py:2040-2071` ulasan kosong | `CHAPTER.EMPTY_REVIEW` | Halaman ULASAN tanpa narasi | P1 | Deteksi template |
| 12 | `extractor.py:1795-1811` dummy kamera | `FIGURE.DUMMY` | Kotak placeholder ikon kamera | P1 | Butuh CV; alternatif: AI sudah bisa |
| 13 | `extractor.py:1854-1939` gambar XXX | `FIGURE.PLACEHOLDER` | "Gambar N xxx", sumber elipsis, judul di atas gambar | P1 | Bernilai tinggi |
| 14 | `extractor.py:2862-2911` lompatan nomor halaman | `PAGINATION.JUMP` | Nomor cetak tidak berurutan | **P0** | Deterministik penuh |
| 15 | `extractor.py:653-660` ukuran buku | `CATALOGUE.BOOK_SIZE` | `14,8 cm x 21 cm` (koma, spasi) | **P0** | Presisi tinggi |
| 16 | `extractor.py:230-267` font Arial Bold Italic | `COVER.TYPOGRAPHY` | Font penerbit di kover | P2 | Butuh info font → Poppler saja tidak cukup |
| 17 | `common.py:315-422` raster header OCR | — | — | **Tolak** | Butuh OCR; Laravel sudah pakai AI vision |
| 18 | `common.py:426-456` saran estetika | `VISUAL.TYPOGRAPHY` (perluas) | saran foto 3:4, ruang TTD 2.5–3 cm | P2 | Nilai tambah laporan |
| 19 | `common.py:458-512` dedup semantik | `FindingConsolidator` (perluas) | satu temuan per topik | **P0** | Kurangi duplikat laporan |
| 20 | `common.py:611-642` sanitasi unicode | `TextLayerNormalizer` (perluas) | BOM, zero-width, soft hyphen, ligatur | **P0** | 5 menit kerja, cegah regex gagal |
| 21 | `common.py:83-96` registry ISSN | tabel `issn_registry` | ISSN resmi per kecamatan | P1 | Hapus false positive "ISSN salah" |
| 22 | `extractor.py:156-168` heading detector | sudah ada | — | — | Sudah baik |
| 23 | `extractor.py:709-721` nama individu di katalog | `CATALOGUE.INDIVIDUAL_ROLES` | Penyusun/Penyunting/Pembuat Kover | P1 | Sering muncul di evaluasi |

**Catatan penting:** aturan Python berbasis **geometri PDF** (bbox, posisi Y, 8.5%/88%, bbox piksel) tidak bisa dipindahkan langsung karena Laravel tidak mem-parse PDF, hanya merender. Untuk aturan spasial (mis. posisi klaster katalog di pojok kanan atas), gunakan `bbox` yang **sudah dikembalikan AI** (`PageResultValidator.php:61,78`) — datanya tersedia, tinggal divalidasi deterministik.

---

## 4. Diagnosa Kecepatan & Biaya

### 4.1 — System prompt 9.400 token dikirim ulang per halaman 🔴 Dampak terbesar

**Bukti perhitungan:**
```
pedoman.md            29.688 byte  ≈ 7.422 token
page-audit-v1.md       3.907 byte  ≈   977 token
penegasan tambahan                  ≈ 1.000 token
------------------------------------------------
system prompt per halaman           ≈ 9.400 token
150 halaman × 9.400                 = 1.410.000 token
```
Verifikasi: `PromptBuilder.php:17-44` menyusun system prompt **setiap kali** `messages()` dipanggil, dan `messages()` dipanggil di `AnalyzePage.php:108`, `VerifyPage.php:90`, `CoverTitleComparator.php`.

**Dampak biaya (per publikasi 150 halaman, tanpa verifikasi silang):**

| Model | Input system saja | Estimasi total |
|---|---|---|
| gemini-2.5-flash ($0,075/1M) | $0,11 | ~$0,35 |
| claude-sonnet-4-6 ($3,00/1M) — *fallback default* | **$4,22** | **~$11,5** |
| dengan verifikasi silang (×2) | $8,44 | **~$23** |

**Aksi — tiga lapis:**

1. **Prompt caching eksplisit.** Kirim system prompt dalam blok ber-`cache_control: {"type":"ephemeral"}` untuk provider yang mendukung (Anthropic-style di 9Router), dan andalkan implicit caching Gemini. Ini memotong biaya baca cache 75–90% dan **memotong waktu prefill** (latensi turun signifikan pada panggilan ke-2 dan seterusnya).
2. **Pangkas ukuran prompt.** 7.422 token pedoman itu sebagian berupa contoh dan riwayat. Buat `audit-rules/pedoman-prompt.md` versi ringkas (target ≤ 2.500 token) yang hanya berisi aturan operasional, dan sisipkan pedoman lengkap **hanya untuk halaman yang tipenya relevan**:
   - halaman `catalogue` → §2, §5, §15
   - halaman `table` → §12
   - halaman `front_cover`/`title` → §2, §4
   Ini memotong token 50–70% **tanpa kehilangan aturan**, karena aturan tabel tidak membantu saat membaca kover.
3. **Cache artefak di disk.** `PromptBuilder.php:19` memanggil `Storage::disk('local')->get(...)` per halaman. Simpan hasil rakitan system prompt ke `Cache` ber-key `prompt:{publication_id}:{rule_version}` (TTL selama audit), lalu panggil `Cache::remember`.

**File:** `NineRouterClient.php` (susun payload dengan `cache_control`), `PromptBuilder.php`, `config/audit.php` (flag `prompt_cache_enabled`).
**Acceptance:** token input turun ≥ 60%; biaya per publikasi turun ≥ 50%; latensi p50 per halaman turun ≥ 25%.
**Effort:** 3 hari.

---

### 4.2 — `pdftoppm` dipanggil 2× per halaman sebagai proses terpisah 🟠

**Bukti:** `app/Jobs/RenderPage.php:61,64`
```php
$pdf->render(..., $page->physical_page, ..., $page->dpi);   // 160 DPI
$pdf->render(..., $page->physical_page, ..., 60);           // thumbnail
```
`PdfPreparer::render()` (`PdfPreparer.php:47`) memakai `-singlefile` → **satu proses per halaman per DPI**. Untuk 150 halaman = **300 spawn proses**, dan setiap spawn membuka ulang + mem-parse seluruh PDF.

**Aksi:**
1. Tambahkan `PdfPreparer::renderRange(string $pdf, int $from, int $to, string $outDir, int $dpi)` yang memanggil `pdftoppm -f {from} -l {to} -jpeg -r {dpi}` **tanpa** `-singlefile`, menghasilkan `{outDir}/page-0001.jpg`, dst. → **1 proses per rentang**.
2. Pisahkan batch: satu job `RenderBatch` untuk seluruh dokumen (rentang 1..N @ 160 DPI), lalu thumbnail dibuat dari gambar 160 DPI via GD (`imagecreatefromjpeg` + `imagescale`), bukan `pdftoppm` kedua.
3. Fallback: bila `RenderBatch` gagal (PDF besar/timeout), otomatis kembali ke per-halaman.
**Dampak:** 300 proses → 1–2 proses. Perkiraan waktu render turun 60–80% pada dokumen besar.
**Effort:** 2 hari.
**Risiko:** `-l N` dengan N besar memakan disk sementara; perlu pembersihan dan batas (mis. 500 halaman × 160 DPI ≈ 300 MB). Konfigurasi `disk_free` sudah dipantau `audit:health`.

---

### 4.3 — Dashboard menembak query berulang 🟡

**Bukti:** `PublicationController.php:41` (paginate) + `:48` + `:49` + `:50` — empat kali `Publication::visibleTo($request->user())`, masing-masing untuk `count()` berbeda.

**Aksi:** satu query agregat:
```php
$metrics = Publication::visibleTo($user)
    ->selectRaw("count(*) total,
        sum(status in ('processing','cross_checking','exporting')) active,
        sum(status in ('review_ready','final')) ready")
    ->first();
```
**Dampak:** 4 full scan → 1.
**Effort:** 2 jam.

---

### 4.4 — Telemetri memuat seluruh record ke memori 🟡

**Bukti:** `app/Services/AiTelemetryService.php:49` — `$records = $query->orderBy('id','desc')->get();` lalu loop PHP untuk total token/biaya (§60-90). Pada 1.000+ request ini berat di halaman Settings.

**Aksi:** pindahkan agregasi ke SQL (`selectRaw('sum(...)')`), `->get()` hanya untuk 50 baris terakhir yang ditampilkan. Simpan `cost_usd` sebagai kolom terhitung saat insert agar tidak menghitung ulang.
**Effort:** 1 hari.

---

### 4.5 — Halaman `show` memuat semua halaman setiap navigasi 🟡

**Bukti:** `PublicationController.php:76` — `$publication->pages()->with(['aiAgent','latestVerification.agent'])->get()` untuk **semua** halaman; `:83` memuat `currentPage` secara terpisah. Untuk 500 halaman, payload Inertia membengkak.

**Aksi:** kirim hanya `id, physical_page, status, page_type` untuk peta halaman; muat `result` penuh hanya untuk halaman aktif lewat endpoint JSON ringan (sudah ada pola `agent-office/state`).
**Effort:** 1,5 hari.

---

### 4.6 — Bundle frontend besar dan monolitik 🟡

**Bukti:** `AgentOffice/Index.vue` 113 KB, `Documentation/Index.vue` 71 KB, `Rules/Index.vue` 57 KB, `Settings.vue` 66 KB. `app-*.js` 279 KB.

**Aksi:**
1. `defineAsyncComponent` untuk panel berat (AgentOffice, Documentation, ReferenceEvidencePanel) — hanya dimuat saat dibuka.
2. Pecah `AgentOffice/Index.vue` menjadi komponen `AgentCard`, `OfficeCanvas`, `AgentControls` (logika animasi sudah terpisah di `composables/useAgentAnimation.js`).
3. Aktifkan `build.rollupOptions.output.manualChunks` untuk memisahkan vendor (`vue`, `@inertiajs`, `lucide`).
**Target:** bundle awal < 180 KB gzip; LCP dashboard < 1,5 s pada LAN.
**Effort:** 2 hari.

---

### 4.7 — Konsistensi polling status 🟡

**Bukti:** UI `Publications/Show.vue` memantau progres; setiap perubahan halaman menulis DB. Untuk 300 halaman, banyak query kecil.
**Aksi:** kirim progres lewat satu endpoint agregat `{done,total,current_type}` yang di-cache 2 detik (`Cache::remember`), bukan membaca semua baris.
**Effort:** 1 hari.

---

## 5. Peningkatan Arsitektur AI

### 5.1 — Model bertingkat (routing harga/kualitas) 🟢 Prioritas tinggi untuk biaya

**Idea:** halaman tidak sama nilainya. Halaman `blank`, `chapter` (pembatas), dan `narrative` polos tidak perlu model mahal.

**Aksi:** di `AnalyzePage`, setelah render, klasifikasikan halaman murah lebih dulu:
- `blank`/`chapter` → model murah (flash tier) atau bahkan deterministik penuh (cek: hampir putih + < 50 char teks) → **skip AI**.
- `table`/`catalogue`/`front_cover` → model terbaik.
Tambahkan kolom `AiAgent.tier` (`cheap|standard|premium`) dan perluas `AgentPoolSelector` untuk memilih berdasarkan `page_type` hasil klasifikasi ringan.
**Dampak:** 15–35% halaman tidak perlu model mahal.
**Effort:** 3 hari.

### 5.2 — Pra-klasifikasi halaman deterministik 🟢

**Masalah:** saat ini `page_type` ditentukan AI per halaman, sehingga agen dibayar untuk sesuatu yang bisa ditebak dari struktur.
**Aksi:** `app/Audit/PageClassifier.php`: gunakan text_layer + nama seksi + pola (`DAFTAR ISI`, `TIM PENYUSUN`, `KATA PENGANTAR`, `^1\.`, `Tabel \d`) untuk memberi *hint* `page_type` di prompt. Model tetap boleh mengoreksi, tapi 80% kasus sudah benar → output token turun dan konsistensi naik.
**Effort:** 2 hari.

### 5.3 — Verifikasi silang adaptif, bukan semua halaman 🟢

**Bukti:** mode `high_risk` menyapu semua halaman bertabel (`CrossVerifier.php:41-45`). Untuk publikasi 150 halaman dengan 100 halaman tabel, ini **menggandakan biaya**.
**Aksi:** verifikasi hanya bila (a) halaman tipe kritis, **atau** (b) AI memberi ≥1 temuan `severity=wajib` dengan `confidence < 0.9`, **atau** (c) halaman memuat tabel dengan `component_rows`+`total` yang tidak konsisten. Konfigurasi sudah ada `cross_verify_mode`; tambahkan mode `smart` (default baru).
**Dampak:** biaya verifikasi turun 50–70% dengan cakupan kasus rawan tetap utuh.
**Effort:** 2 hari.

### 5.4 — Guardrail keluaran: schema-first + repair sekali
**Bukti:** `NineRouterClient.php:122-130` sudah mem-parse JSON dan melempar `AiRequestException('format')`. `AnalyzePage.php:239-240` mengulang maksimum 2×.
**Aksi:** saat parse gagal, kirim **permintaan perbaikan** (bukan ulang penuh): "Respons sebelumnya bukan JSON valid. Kembalikan HANYA JSON yang sudah kamu hasilkan, tanpa markdown." Ini jauh lebih murah daripada membaca ulang gambar.
**Effort:** 1 hari.

### 5.5 — Identitas & referensi: jangan menebak
**Bukti:** `PromptBuilder.php:25-30` sudah memerintahkan jangan menebak; `VolumeHistoryChecker` + `BraveSearchClient` + `BrowserlessPublicationClient` ada tetapi flag default `false` (`config/audit.php:10`).
**Aksi:** aktifkan bertahap setelah §T1.1; beri label eksplisit `needs_verification` bila rujukan tidak terbaca (sudah dilakukan `CoverTitleComparator.php:35-45`).
**Effort:** 2 hari (setelah gold dataset siap).

---

## 6. Penguatan Determinsme (lapisan "gratis" sebelum AI)

Target akhir: **≥ 18 dari 38 rule code** dapat diputuskan tanpa AI sama sekali (saat ini 2).

| Lapis | Kapan | Isi |
|---|---|---|
| L0 — pra-AI | setelah `RenderPage` | template residue, identity mismatch, text-layer gate, klasifikasi halaman, sanitasi unicode |
| L1 — per halaman (AI) | `AnalyzePage` | pembacaan visual + temuan |
| L2 — pasca-AI deterministik | setelah `AnalyzePage` | ISSN checksum, cross-page ISSN/katalog, wrong year, pagination jump, book size, typo global, tabel granular |
| L3 — lintas dokumen | `FinalizeAudit` | identitas, running title, total tabel, klaim narasi, bilingual agregat |
| L4 — ekspor | `WriteWorkbook` | status kelayakan, penyusunan L–M |

Setiap aturan baru wajib punya: **kode di `RuleRegistry`**, **implementasi**, **minimal 2 unit test**, dan **entri di gold dataset**.

---

## 7. Yang TIDAK boleh diambil dari proyek Python

Penting agar tidak regresi:

| Jangan ambil | Alasan |
|---|---|
| Penilaian berbasis regex atas teks vektor saja | Itu justru akar masalah yang Anda hindari; AI vision lebih akurat untuk tata letak |
| `requests.post` tanpa timeout/retry/rate-limit | Laravel sudah jauh lebih matang di `NineRouterClient` |
| Tanpa autentikasi / CORS `*` | Regresi keamanan besar |
| Penyimpanan state di memori proses | Tidak kompatibel dengan worker cPanel |
| Nama file output tanpa `task_id` | Di Laravel sudah benar (versi ekspor di DB) |
| `KNOWN_BANGKEP_ISSN` hardcode 12 kecamatan | Gunakan tabel registry yang bisa dikelola admin |
| Overlay "AI fake" (fungsi ada tapi tidak dipanggil) | Jangan ulangi pola dead code |
| Ekstraksi piksel manual untuk deteksi logo/wajah | AI vision sudah menangani; menambah CV = biaya perawatan |

---

## 8. Rencana Pengukuran (Definition of Done)

### 8.1 Tabel baseline & target

| Metrik | Baseline (isi setelah T1.2) | Target |
|---|---|---|
| Precision (gold) | — | ≥ 0,90 |
| Recall (gold) | — | ≥ 0,85 |
| False positive kover/katalog | — | turun ≥ 40% |
| Waktu render 150 hlm | — | turun ≥ 60% |
| Token input per publikasi | ~1,41 juta (system saja) | **≤ 500 ribu** |
| Biaya per publikasi (sonnet fallback) | ~$11,5 | **≤ $5** |
| Biaya per publikasi (flash) | ~$0,35 | ≤ $0,20 |
| Latensi p50 per halaman | — | turun ≥ 25% |
| Bundle awal dashboard | 279 KB JS | ≤ 180 KB |
| Tes | 155 method | ≥ 200 method, tetap 100% lulus |

### 8.2 Checklist penerimaan per fase

Setiap fase ditutup hanya bila:
1. `php artisan test` lulus 100%.
2. `npm run typecheck` lulus.
3. `npm run build` lulus.
4. `php artisan audit:evaluate tests/gold/*.json` menghasilkan precision/recall yang **tidak turun** dibanding fase sebelumnya.
5. `docs/evaluation-results.md` diperbarui dengan angka nyata.
6. Migrasi aditif + rollback diuji (`php artisan migrate:rollback --step=1`).

---

## 9. Urutan Eksekusi & Estimasi

### FASE 1 — Fondasi & Akurasi Cepat (2 minggu)
| ID | Tugas | Hari |
|---|---|---|
| T1.1 | Gold dataset 12 publikasi | 3 |
| T1.2 | Baseline performa & biaya | 0,5 |
| T1.3 | `audit:benchmark` | 1 |
| A.2 | Fix `CrossVerifier` page types | 0,25 |
| A.1 | `TextLayerGate` + prompt dua blok | 2 |
| A.5 | SpellChecker indexing + whitelist | 1,5 |
| 3#5 | Wrong-year + typo global | 1 |
| 3#14 | Pagination jump | 0,75 |
| 3#15 | Book size | 0,5 |
| 3#20 | Sanitasi unicode | 0,25 |
| 3#19 | Dedup semantik | 1 |
| — | Tes + review | 2 |

**Hasil fase:** false positive dominan hilang; baseline akurasi terukur.

### FASE 2 — Determinisme Dokumen (2 minggu)
| ID | Tugas | Hari |
|---|---|---|
| A.3 | `TemplateResidueDetector` | 1,5 |
| A.3 | `IdentityMismatchDetector` | 1,5 |
| 3#3 | Katalog cross-page | 0,5 |
| 3#4 | ISSN cross-page | 0,75 |
| 3#8 | 9 sub-aturan tabel | 2,5 |
| 3#9 | Tabel kosong/elipsis | 1 |
| 3#13 | Gambar placeholder | 1 |
| 3#21 | Registry ISSN (migrasi + admin UI) | 2 |
| — | Tes + tes regresi aturan | 2 |

**Hasil fase:** ≥18 kode aturan deterministik; template mentah ditolak tanpa biaya.

### FASE 3 — Kecepatan (1,5 minggu)
| ID | Tugas | Hari |
|---|---|---|
| 4.2 | `renderRange` + thumbnail via GD | 2 |
| 4.3 | Query agregat dashboard | 0,25 |
| 4.4 | Telemetri SQL agregat | 1 |
| 4.5 | Payload `show` diringkas | 1,5 |
| 4.6 | Code splitting Vue + manualChunks | 2 |
| 4.7 | Endpoint progres agregat | 1 |
| — | Profiling & tes | 1,5 |

**Hasil fase:** render & muat halaman jauh lebih cepat.

### FASE 4 — Ekonomi & Kualitas AI (2 minggu)
| ID | Tugas | Hari |
|---|---|---|
| 4.1a | Prompt caching | 1,5 |
| 4.1b | `pedoman-prompt.md` ringkas + prompt per tipe halaman | 2,5 |
| 4.1c | Cache rakitan prompt | 0,5 |
| 5.2 | `PageClassifier` deterministik | 2 |
| 5.1 | Model bertingkat + `AiAgent.tier` | 2,5 |
| 5.3 | Mode `smart` cross-verify | 1,5 |
| 5.4 | Repair-once JSON | 1 |

**Hasil fase:** biaya turun ≥50%; latensi turun ≥25%.

### FASE 5 — Operasional & Mutu (1 minggu)
| ID | Tugas | Hari |
|---|---|---|
| 5.5 | Aktifkan referensi eksternal bertahap | 2 |
| A.6 | Bilingual agregat | 1 |
| 3#23 | Nama individu katalog | 0,5 |
| 3#18 | Saran estetika | 1 |
| — | Perbarui `docs/evaluation-results.md` + `deploy/release-checklist.md` | 1 |

**Total:** ±8,5 minggu kerja fokus (1 orang).

---

## 10. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Prompt caching tidak didukung provider di 9Router | Optimasi 4.1a gagal senyap | Deteksi lewat `usage.cached_tokens`; bila 0, fallback ke pemangkasan prompt (4.1b) |
| Pemangkasan pedoman menghilangkan aturan | Akurasi turun | Wajib lulus gate gold dataset per fase |
| `renderRange` gagal pada PDF rusak | Halaman tidak dirender | Pertahankan jalur per-halaman sebagai fallback otomatis |
| Model bertingkat salah klasifikasi | Halaman penting pakai model murah | Hanya untuk `blank`/`chapter` yang lolos uji deterministik ketat |
| Gold dataset bias pada 12 publikasi | Overfit | Sisakan 4 publikasi (termasuk kabupaten) sebagai *held-out* |
| Aturan deterministik menambah false positive | Regresi | Setiap aturan baru wajib punya tes negatif, bukan hanya tes positif |
| Migrasi DB di hosting produksi | Downtime | Semua migrasi aditif; uji rollback di staging sebelum deploy |

---

## 11. Aturan Main Implementasi

1. **Satu aturan = satu commit** dengan pesan `feat(rule): <KODE> <deskripsi>` agar mudah di-*revert*.
2. **Tidak menambah kode aturan tanpa tes positif dan negatif.**
3. **Tidak mengubah `PromptBuilder` tanpa menjalankan gold evaluation.**
4. **Setiap aturan baru didaftarkan di `RuleRegistry` lebih dulu**, baru diimplementasikan — agar validasi `PageResultValidator.php:72` tidak menolak keluaran AI.
5. **Versi aturan dinaikkan** (`config/audit.php:6`) setiap kali isi pedoman berubah, dan snapshot disimpan seperti preseden `pedoman-v2.md`.
6. **Kembalikan angka, bukan klaim.** `docs/evaluation-results.md` harus selalu memuat tabel sebelum/sesudah.

---

## 12. Lampiran — Peta File yang Akan Disentuh

**Baru**
```
app/Audit/Deterministic/TemplateResidueDetector.php
app/Audit/Deterministic/IdentityMismatchDetector.php
app/Audit/Deterministic/PageClassifier.php
app/Audit/Text/TextLayerGate.php
app/Jobs/RenderBatch.php
app/Console/Commands/AuditBenchmark.php
audit-rules/pedoman-prompt.md
tests/gold/*.json
tests/Unit/CrossVerifierPageTypeTest.php
tests/Unit/Deterministic/*.php
```

**Diubah (utama)**
```
app/Audit/RuleRegistry.php          +≥20 kode
app/Audit/RuleEvaluator.php         +≥18 implementasi deterministik
app/Audit/PromptBuilder.php         dua blok text layer + caching + prompt per tipe
app/Audit/NineRouterClient.php      cache_control + repair-once
app/Audit/CrossVerifier.php         fix page types + mode smart
app/Audit/AgentPoolSelector.php     pemilihan berbasis tier
app/Audit/Text/SpellChecker.php     indeks kamus + whitelist
app/Audit/Text/TextLayerNormalizer.php  unicode & ligatur
app/Audit/FindingConsolidator.php   dedup semantik
app/Audit/PdfPreparer.php           renderRange
app/Audit/AuditCoordinator.php      jalur rejected_template
app/Jobs/RenderPage.php             batch render, thumbnail GD
app/Jobs/FinalizeAudit.php          aturan lintas dokumen baru
app/Services/AiTelemetryService.php agregasi SQL
app/Services/CreatePublication.php  klasifikasi awal
app/Http/Controllers/PublicationController.php  query agregat + payload ringkas
config/audit.php                    flag baru (cache, tier, smart verify)
resources/js/Pages/AgentOffice/Index.vue  dipecah
docs/evaluation-results.md          angka sebelum/sesudah
```

**Migrasi (aditif, nullable)**
```
add_text_layer_gate_to_publication_pages
add_tier_to_ai_agents
add_cost_usd_to_ai_usage_records
create_issn_registry_table
add_pre_classified_type_to_publication_pages
```

---

## 13. Ringkasan Satu Paragraf

Proyek Laravel Anda sudah lebih matang dari pembandingnya di hampir semua dimensi rekayasa — kecuali satu: **belum ada lapisan deterministik yang melindungi AI dari bukti palsu**. Rencana ini menambahkan lapisan itu (gate text-layer, detektor template mentah, deteksi salah wilayah, 18 aturan deterministik), memperbaiki satu bug verifikasi silang yang membuat kover & daftar isi tidak pernah diverifikasi, lalu menekan biaya dan waktu lewat prompt caching, pemangkasan prompt per tipe halaman, render batch, dan model bertingkat. Semuanya diukur dengan gold dataset yang dibekukan lebih dulu, dan setiap fase hanya ditutup jika precision/recall tidak turun.
