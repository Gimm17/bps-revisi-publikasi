# Pemeriksaan Publikasi Berbasis Bukti — Rules V2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Metode eksekusi dipilih pengguna setelah rencana ditinjau; dokumen ini bukan izin untuk langsung coding atau deploy.

**Goal:** Menerapkan pedoman terbaru melalui verifikasi riwayat volume, perbandingan visual kover–halaman judul, dan pemeriksaan susunan halaman cetak yang dapat dilacak buktinya, tanpa merusak pemeriksaan lama.

**Architecture:** Pertahankan pembacaan AI per halaman dan tambahkan check run berversi untuk pemeriksaan lintas halaman/sumber. PHP mengoordinasikan aturan deterministik, AI vision, pencarian rujukan, browser eksternal, penyimpanan bukti, review, serta ekspor Excel; tidak ada browser otomatis atau OCR Python baru di shared hosting. Hasil dan snapshot lama tetap utuh; pemeriksaan tambahan publikasi lama dijalankan atas tindakan pengguna.

**Tech Stack:** Laravel 13/PHP 8.4, Vue 3.5/Inertia 2, database queue/cache, MariaDB produksi, PhpSpreadsheet, Poppler yang sudah tersedia, AI vision melalui 9Router/LimitRouter. PHPUnit dan Playwright mengikuti proyek. Brave Search + Browserless REST merupakan rekomendasi adaptor eksternal, belum layanan yang sudah diaktifkan.

**Spec:** [Pedoman sumber, pembaruan 18 Oktober 2026](../../../../PEDOMAN-REVISI-DAN-PEMERIKSAAN-PUBLIKASI.md), terutama §2.6, §4, §5.5, §15, §17–21; persetujuan pengguna untuk browser eksternal; kewajiban mempertahankan hasil tersimpan, Excel editable, shared hosting cPanel, dan UI sederhana. Jika pedoman berubah lagi, bekukan snapshot baru dan tinjau ulang delta sebelum implementasi.

**Status:** Rencana untuk persetujuan. Belum mengubah aplikasi, database, kredensial, GitHub, atau produksi.

## Global Constraints

- Kode dikerjakan di `web/`; seluruh path implementasi di bawah relatif terhadap folder tersebut, kecuali disebut lain.
- Aturan tetap ditanam pengembang dalam codebase; tidak membuat rule-builder atau menu administrasi aturan.
- Prioritas: instruksi terbaru pengguna → instrumen → keputusan terkonfirmasi → contoh rujukan → pola evaluasi. Contoh tidak otomatis menjadi aturan universal.
- Katalog memakai `ISSN: XXXX-XXXX`; bagian lain `ISSN XXXX-XXXX`. Larangan titik dua ini khusus label ISSN, bukan seluruh tanda baca publikasi.
- Nama Indonesia memakai `Badan Pusat Statistik`, bukan singkatan `BPS`; bentuk Inggris resmi diperbolehkan.
- Running title: ganjil judul bab, genap judul publikasi; gunakan nomor tercetak, bukan ordinal PDF.
- Volume sebelumnya harus dibaca dari bukti visual seri/wilayah yang tepat. Tidak menghitung volume dari katalog, nomor publikasi, tahun kalender, atau tahun pembentukan daerah.
- Pendahuluan romawi berakhir genap; konten Arab terakhir ganjil diikuti tepat dua halaman putih sebelum kover belakang, genap tepat satu. Halaman putih akhir bukan tambahan jumlah Arab pada katalog.
- Semua halaman target tetap memiliki status pembacaan tersendiri. Tambahan pemeriksaan tidak mengganti pembacaan seluruh halaman dengan sampling.
- Tidak mengubah angka statistik tanpa sumber; tidak membuat ISSN pengganti; tidak mengesahkan data sumber yang tidak diperiksa.
- Hasil lama, keputusan reviewer, PDF asli, snapshot aturan/prompt, dan Excel versi terdahulu tidak ditimpa atau dihapus.
- Login dan kebijakan akses pegawai yang sudah ada tetap digunakan; admin saja yang mengelola koneksi eksternal.
- Tetap memakai shared hosting; tanpa Redis, Supervisor, Python OCR, atau Chromium lokal baru. Tidak menambah dependency tanpa persetujuan.
- Seluruh panggilan AI, termasuk pembanding dua gambar dan pembacaan rujukan, melewati batas kapasitas AI global yang sama; default tetap **2**.
- Tidak mencatat password, API key, token pada URL, atau file dokumen lengkap ke log. Tidak memasukkan kredensial ke repo, prompt, atau frontend.
- Pengujian memakai database terisolasi dan HTTP fake; bukan database produksi atau API berbayar tanpa persetujuan.
- Palet dan komponen UI lama dipertahankan: `#FFBDBD`, `#FFA4A4`, `#BADFDB`, `#FCF9EA`; konfirmasi memakai dialog custom, bukan `window.confirm`.

## Review Focus

1. **Hasil lama dan pekerjaan aktif:** menjalankan aturan baru atau retry tidak menghapus halaman selesai, keputusan review, maupun Excel lama. Dibuktikan Task 1 dan 8.
2. **Sumber salah/menyesatkan:** kecamatan bernama sama di kabupaten lain, snippet tahun, atau nomor publikasi tidak boleh menjadi bukti volume. Dibuktikan Task 4 dan 5.
3. **Akses berhasil tetapi isi gagal dibaca:** PDF preview error, kover buram, CAPTCHA, dan respons AI rusak menghasilkan keterbatasan spesifik, bukan status sesuai. Dibuktikan Task 3, 5, dan 7.
4. **Nomor tercetak tidak sama dengan ordinal PDF:** halaman pembatas tanpa nomor dan halaman putih wajib tidak dianggap kesalahan atau tambahan Arab katalog. Dibuktikan Task 2 dan 6.
5. **Retry dan ekspor bersamaan:** job ganda/respons terlambat tidak menggandakan temuan atau menghasilkan Excel dengan coverage palsu; bukti eksternal tidak menjadi formula Excel. Dibuktikan Task 8 dan 9.

---

## 1. Hasil audit awal dan batas pekerjaan

Audit source menunjukkan aturan sekarang `bps-2026.10.05-v1`, sedangkan pedoman root diperbarui 18 Oktober. `CreatePublication` menyimpan snapshot aturan/prompt per publikasi dan `PromptBuilder` membacanya kembali. Karena itu, memperbarui satu file pedoman bawaan saja tidak menerapkan aturan baru ke publikasi lama.

| Area | Kondisi source saat perencanaan | Target V2 |
|---|---|---|
| Pembacaan halaman | Satu gambar per request, hasil terstruktur dan validasi | Tetap seluruh halaman; metadata tambahan untuk anatomi/halaman kosong |
| Volume | Pemeriksaan identitas internal | Tambah bukti edisi sebelumnya dari sumber resmi yang cocok |
| Layout judul | Aturan tersedia dalam instruksi, belum pembanding dua gambar khusus | Perbandingan visual berpasangan dengan lokasi bukti kedua halaman |
| Halaman cetak | Pemeriksaan penomoran umum | Mesin susunan romawi, batas Arab, halaman putih akhir, dan jumlah katalog |
| Finalisasi | Terutama mensyaratkan seluruh halaman selesai | Pisahkan coverage halaman, silang internal, dan check run tambahan |
| Temuan/bukti | Bukti terutama berupa halaman PDF target | Bukti target dan rujukan eksternal berbeda tipe dan jelas asalnya |
| Excel | Template dan ekspor sementara sudah tersedia | Tambah status cakupan/rujukan; pertahankan border dan perlindungan sel kosong |
| Publikasi lama | Snapshot lama tidak berubah | Tombol pemeriksaan tambahan, tidak reset semua halaman |

**Yang tidak termasuk:** editor PDF, penggantian angka otomatis, crawler semua situs BPS, agent browser bebas, pembelian layanan eksternal, training model, login ChatGPT Plus, redesign total, atau pemeriksaan ulang massal otomatis.

Kehadiran kalimat aturan di prompt belum membuktikan fitur sudah terimplementasi. Task 1 membuat matriks seluruh bagian pedoman untuk membedakan `deterministic`, `ai_page`, `ai_pair`, `external_evidence`, `manual`, serta `unsupported`.

### Kriteria hasil yang jujur

- `complete` pada proses berarti job berhasil menghasilkan keputusan, bukan publikasi bebas kesalahan.
- Keputusan terpisah: `conform`, `nonconform`, `needs_verification`, `not_applicable`.
- `not_applicable` harus disertai alasan dan dasar aturan; layanan belum disetel bukan alasan tidak berlaku.
- Kegagalan teknis tidak berubah menjadi `conform`. Setelah retry habis, catat status teknis dan `needs_verification` yang terlihat.
- Semua pengecekan yang dapat dijalankan selesai otomatis; laporan boleh berisi temuan/keterbatasan. Penguncian final memerlukan review manusia, bukan asumsi AI benar.
- Hasil lama + tiga pemeriksaan tambahan dilabeli **cakupan gabungan**, bukan klaim seluruh aturan V2 telah dijalankan ulang pada semua halaman.

## 2. Flow end-to-end

```text
Upload baru / pilih pemeriksaan tambahan pada publikasi lama
  → Validasi akses, kunci, identitas wilayah, checksum dan versi aturan
  → Buat check run + snapshot tetap + daftar pemeriksaan wajib
  ├─ Pembacaan target per halaman [pipeline lama / reuse hasil lama]
  │    → indeks anatomi, nomor tercetak, identitas, konten/halaman kosong
  ├─ Perbandingan kover–halaman judul [AI dua gambar]
  ├─ Susunan cetak + jumlah katalog [deterministik + bukti halaman]
  └─ Rujukan volume
       → cari seri/tahun tepat → cocokkan wilayah → browser laman resmi
       → metadata + kover terbaca ATAU tombol unduh/pratinjau PDF
       → baca volume → simpan bukti → bandingkan bersyarat
  → Gabungkan hasil + tandai konflik dan batas pemeriksaan
  → Excel otomatis berversi / Excel sementara jika masih ada proses gagal
  → Pegawai meninjau dan mengedit temuan → ekspor versi berikutnya
  → Reviewer mengunci hasil dengan cakupan dan keterbatasan tercatat
```

Antrean cabang boleh berjalan bersamaan hanya jika input sudah tersedia. Tidak menahan transaksi database selama jaringan/AI/browser berlangsung. Tidak membuat loop polling finalisasi yang terus-menerus memenuhi antrean.

### Publikasi lama

1. UI menampilkan versi aturan lama dan tawaran **Periksa tambahan dengan aturan terbaru**.
2. Dialog menampilkan tiga cakupan baru, halaman yang mungkin dibaca ulang secara terarah, dan kemungkinan biaya API.
3. Publikasi terkunci harus dibuka kembali oleh pihak berwenang sebelum tambahan menjadi bagian hasil aktif. Excel final lama tetap tersimpan.
4. Buat run baru; jangan mengganti `publication.rule_version`, snapshot lama, atau hasil per halaman lama secara diam-diam.
5. Pakai observasi lama jika memadai. Jika field baru belum ada, baca hanya halaman terkait ke hasil check run tambahan, bukan menimpa `publication_pages.result` lama.
6. Publikasi yang pemeriksaan halamannya masih berjalan boleh menyelesaikan pekerjaan lama; tambahan menunggu input yang diperlukan. Tidak mengantrekan ulang halaman `complete`.
7. Duplikasi klik mengembalikan run aktif yang sama. Run baru setelah run selesai memerlukan tindakan eksplisit dan alasan retry/revisi.

## 3. Perilaku tiga fitur utama

### 3.1 Riwayat volume dan rujukan BPS

**Identitas pencocokan:** jenis seri, kecamatan, kabupaten/kota, provinsi, tahun edisi. Katalog/nomor publikasi/ISSN disimpan sebagai field terpisah, bukan disulap menjadi volume. `region` bebas saat ini belum cukup; tambahkan konteks wilayah terstruktur opsional untuk data lama, wajib dilengkapi sebelum rujukan dinyatakan cocok.

Urutan otomatis KCDA:

1. Bentuk query persis `Kecamatan [nama kecamatan] Dalam Angka [tahun sebelumnya]`; query kedua tahun berjalan hanya bila dibutuhkan. Maksimal dua query per percobaan run.
2. Ambil maksimal lima hasil per query dalam urutan provider; periksa hasil resmi yang cocok paling atas. Tolak kemiripan nama yang salah wilayah/tahun/jenis. Simpan alasan kandidat ditolak; maksimal tiga laman kandidat dibuka per percobaan.
3. Buka dengan browser eksternal. Tutup popup layanan/promosi biasa dengan selector teruji; tidak menghapus lapisan pelindung atau melewati login/CAPTCHA.
4. Ambil identitas dan metadata resmi; screenshot metadata bukan bukti bahwa volume telah terbaca.
5. Bila kover terlihat jelas, baca volume/tahun dari kover dan simpan potongan/screenshot dengan posisi asal. Beri cakupan `cover_only`.
6. Bila gambar kover gagal/buram, klik **Unduh Publikasi**; tangani tab baru, respons PDF asli, unduhan, atau preview yang benar-benar terbaca. Bila PDF diperoleh, render halaman 1 dan kandidat halaman identitas dengan Poppler yang ada; maksimal enam halaman rujukan dirender pada percobaan awal.
7. Jika hanya kover terbaca, halaman identitas rujukan tetap `not_examined`, bukan sesuai. Jika kedua bagian terbaca tetapi berbeda volume, hasil konflik → perlu verifikasi.
8. Nilai harapan `volume_sebelumnya + 1` hanya untuk seri tahunan berurutan tanpa bukti perubahan kebijakan. Jeda terbit, penomoran ulang, seri berbeda, atau metadata konflik → tidak memberikan angka final otomatis.
9. Bandingkan volume target satu per satu pada kover, judul, katalog, dan Tim Penyusun; laporkan komponen yang tidak terbaca/hilang secara terpisah.
10. Bila belum ada bukti terbaca, gunakan teks **Perlu verifikasi — volume edisi sebelumnya belum terbaca** dan kendala spesifik.

Untuk `kabda`, `provda`, dan `other`, jangan otomatis memakai pola KCDA atau mengarang pola pencarian baru. Tawarkan rujukan manual; otomatisasi query jenis lain menunggu aturan/pola yang disetujui.

**Kasus kalibrasi, bukan hardcode:** bukti pengguna menyatakan kover Ampana Kota 2025 Volume 31; urutan normal mengharapkan 2026 Volume 32. Nomor katalog `1102001.7209050` dan nomor publikasi `72090.25009` bukan volume. Angka 32 tidak berlaku ke kecamatan lain. Dokumen pedoman sendiri bukan bukti bahwa sistem baru telah mengakses URL/screenshot asli.

**Fallback manual:** unggah PNG/JPEG/PDF beserta URL sumber, judul/tahun, konteks wilayah, dan keterangan halaman yang terlihat. Asal `user_upload` selalu tampil. Reviewer mencocokkan sumber; unggahan tidak otomatis mendapat label rujukan resmi terverifikasi hanya karena pengguna mengisi URL BPS.

### 3.2 Perbandingan visual kover–halaman judul

- Deteksi halaman bertipe kover depan dan judul; PDF 1 dan 3 merupakan susunan umum, bukan angka yang dipaksakan untuk semua dokumen.
- Request khusus berisi dua gambar berlabel peran dan ordinal PDF. Model wajib lulus preflight dua gambar; uji vision satu gambar yang sudah ada belum cukup.
- Komponen: judul Indonesia/Inggris, baris/pemenggalan, posisi/perataan, ukuran relatif, gaya/jenis font yang terlihat, jarak, hierarki, tahun, volume, katalog, ISSN, logo, penerbit.
- Halaman judul: putih, tanpa foto/ilustrasi latar kover; logo Badan Pusat Statistik dan nama penerbit tetap wajib.
- Bedakan komposisi dari kontras warna. Jangan menandai penyesuaian warna agar terbaca di atas putih sebagai perbedaan komposisi.
- Baseline adalah **kover yang sesuai aturan**. Jika kover salah, koreksi keduanya secara selaras; jangan menyuruh menyalin font dekoratif/ISSN salah.
- Setiap perbedaan wajib mencantumkan kedua halaman dan komponen. Bounding box dinormalisasi 0–1, jika tersedia, untuk menyorot lokasi; bukan klaim pengukuran tipografi presisi.
- Nama font/ukuran point tidak dapat dipastikan hanya dari raster; pakai deskripsi relatif atau `needs_verification`, bukan tebakan font eksak.
- Kata `Catalogue` italic yang benar tetap dipertahankan; kondisi satu label tidak menentukan kebenaran layout seluruh halaman.
- Hasil `conform` berarti komponen wajib teramati dan terbaca; gambar buram/komponen tidak ditemukan tidak boleh menghasilkan lulus otomatis.

### 3.3 Susunan halaman cetak dan jumlah katalog

Bangun indeks yang membedakan `physical_page`, `printed_page`, `numbering_system`, `counted_position`, `page_type`, `has_content`, `has_running_title`, `has_visible_number`, dan `blank_kind`. Nilai yang belum diketahui harus `null/uncertain`, bukan `false`.

| Kondisi | Hasil yang diharapkan |
|---|---|
| Romawi terakhir xxi dan satu blank penutup pendahuluan | Dihitung sampai xxii, tanpa nomor/running title pada blank |
| Romawi terakhir xxii | Tidak wajib blank tambahan untuk menggenapkan |
| Arab terakhir 91 + dua blank putih + kover belakang | Sesuai; Arab katalog tetap 91 |
| Arab terakhir 92 + satu blank putih + kover belakang | Sesuai; Arab katalog tetap 92 |
| Arab 91 + satu blank | Kurang satu halaman putih |
| Arab 92 + dua blank | Satu halaman putih berlebih terhadap aturan ini |
| Blank sesudah kover depan dengan ornamen ringan | Tidak otomatis salah; bukan aturan blank akhir |
| Pembatas bab tanpa nomor tercetak | Tetap halaman yang dihitung; bukan blank |
| Halaman akhir ber-watermark/nomor/ornamen | Bukan putih kosong murni; butuh temuan dengan bukti |
| Anchor penomoran/batas isi tidak terbaca | Perlu verifikasi; jangan menebak jumlah |

Jumlah katalog dibentuk dari anatomi dan anchor penomoran terverifikasi, bukan `page_count - angka tetap`. Jika blank romawi yang diwajibkan belum ada, pisahkan **susunan aktual** dan **usulan sesudah perbaikan**. Jangan mengklaim blank tersebut sudah ada.

Format tetap `xxii+91 halaman/pages`, tanpa spasi sekitar `+`, tanpa `hal`. Aturan awal bagian/bab pada halaman ganjil diperiksa terpisah; tidak dianggap otomatis lulus hanya karena jumlah blank akhir benar.

## 4. Kontrak data dan versi

### 4.1 Penyimpanan tambahan, migrasi additive

| Entitas/perubahan | Field utama dan batas tanggung jawab |
|---|---|
| `publication_check_runs` | UUID, publication_id, requested_by, mode `new/supplemental`, baseline_rule_version, target_rule_version, prompt_version, pdf_sha256, rules_path/hash, scope JSON, status, input_revision, started/completed_at, summary/error JSON |
| `publication_checks` | check_run_id, check_type, input_hash, execution_status, decision, result/evidences JSON, attempts, lease_until, error_code/message, timestamps; unique `(check_run_id, check_type, input_hash)` |
| `publication_references` | check_run_id, series/jurisdiction/year, source_url, metadata JSON, provenance, access_status, read_status, scope, artifact_manifest JSON, fetched_at, reviewed_by/at; bukti file privat dengan SHA-256 |
| `reference_provider_configs` | provider Search/Browser, endpoint yang diizinkan, encrypted key, readiness/tested_at, created_by; konfigurasi tidak mengirim balik key |
| `publications.context` | JSON nullable: province, regency, district, region_codes jika tersedia; tidak mengisi data lama dengan tebakan |
| `findings.check_run_id` | Nullable; temuan lama tetap sah tanpa run baru |
| `finding_assessments` | finding_id, check_run_id, proposed_decision, reason, evidences, reviewer_id/reviewed_at; audit ulang mengusulkan pembatalan/koreksi tanpa menimpa keputusan lama |
| `exports.validation` | Tambah snapshot coverage run, versi aturan/prompt, daftar batas pemeriksaan dan rujukan; JSON lama tetap dapat dibaca |

Pakai foreign key, indeks publication/run/status, casts yang konsisten, dan skema kompatibel MariaDB serta SQLite. Snapshot rule/prompt/artifact immutable setelah dicatat; file baru ditulis atomik lalu direferensikan. File sementara yang gagal dibersihkan terbatas pada direktori run tersebut.

Versi target: `bps-2026.10.18-v2`; page prompt baru `page-v2`, pair prompt `cover-title-v1`, reference prompt `volume-reference-v1`. Publikasi lama tidak sekadar diganti label menjadi V2.

### 4.2 Bukti terdiskriminasi

```ts
type PublicationEvidence = {
  kind: 'publication_page'; physical_page: number;
  printed_page?: string | null; quote?: string;
  bbox?: [number, number, number, number];
};
type ReferenceEvidence = {
  kind: 'reference_artifact'; reference_id: string; artifact_id: string;
  artifact_page?: number | null; source_url: string | null;
  provenance: 'official_browser' | 'user_upload'; quote?: string;
};
type AuditEvidence = PublicationEvidence | ReferenceEvidence;
```

Legacy evidence tanpa `kind` dinormalisasi menjadi `publication_page` bila ordinalnya valid. Halaman 1 PDF tahun sebelumnya adalah `artifact_page: 1`, bukan `physical_page: 1` target. Tidak ada halaman palsu 0. URL yang belum diverifikasi tidak ditampilkan sebagai sumber resmi terverifikasi.

### 4.3 State dan finalisasi

- Execution: `pending → running → completed`; kegagalan sementara `retry_wait`, habis retry `failed`; penghentian eksplisit `cancelled`.
- Decision terpisah, tidak boleh menggunakan execution status untuk menyatakan kepatuhan.
- `CoverageService::summarize(Publication $publication): array` menghasilkan `page_coverage`, `base_crosscheck`, `additional_checks`, `limitations`, `report_mode`, `can_export`, `can_lock`.
- `report_mode`: `partial` untuk pekerjaan wajib masih pending/running/failed; `complete_with_limitations` bila semua pekerjaan terminal tetapi masih ada verifikasi belum tuntas; `complete` bila semua cakupan wajib menghasilkan keputusan yang memadai. Ini label laporan pemeriksaan, bukan pengesahan isi publikasi.
- Keterbatasan yang tidak dapat diselesaikan boleh dicatat dan diakui reviewer dengan alasan untuk menyimpan laporan terkunci **dengan keterbatasan**; jangan mengubahnya menjadi `conform` atau menyebut volume terverifikasi.
- Cakupan supplemental selalu menyebut baseline lama dan scope tambahan. Klaim seluruh aturan V2 membutuhkan full run V2, bukan hanya tiga check tambahan.
- Revisi temuan/input/rujukan meningkatkan revision dan membatalkan kelayakan Excel terbaru sampai snapshot baru dibuat; Excel lama tetap dapat diunduh sebagai versi lama.
- Hak penguncian yang sudah ada tetap berlaku, ditambah tidak ada pekerjaan wajib aktif/gagal dan seluruh temuan/assessment/keterbatasan telah direview.

## 5. Integrasi eksternal, keamanan, dan batas biaya

### Pilihan adaptor

Rekomendasi: **Brave Web Search** untuk hasil berurutan, **Browserless REST** untuk browser yang berjalan di layanan eksternal. PHP cukup mengirim HTTPS; tidak memasang Node/Puppeteer pada cPanel. Pisahkan `PublicationSearchClient` dan `PublicationBrowserClient` agar provider dapat diganti tanpa mengubah aturan volume.

Brave menggunakan autentikasi header; Browserless memakai token pada query URL sehingga sanitasi exception/request log harus menghapus query rahasia. Multi-langkah popup → metadata → klik unduh dijalankan dalam satu fungsi browser terkontrol atau alur pengulangan terbatas, bukan mengandalkan cookie lintas request REST yang stateless. Rujukan implementasi: [Brave Quickstart](https://api-dashboard.search.brave.com/documentation/quickstart), [Browserless REST](https://docs.browserless.io/rest-apis/intro).

Gunakan unduhan asli/respons PDF atau bukti kover yang terbaca. Endpoint Browserless `/pdf` mencetak laman menjadi PDF; hasil itu **bukan PDF publikasi asli**. Rujukan: [Download API](https://docs.browserless.io/rest-apis/download), [PDF API](https://docs.browserless.io/rest-apis/pdf-api).

### Prasyarat sebelum fitur eksternal dinyalakan

- Pengguna menyediakan key layanan search/browser atau menyetujui provider alternatif; izin browser eksternal bukan izin membeli paket.
- Verifikasi paket mendukung endpoint, durasi, concurrency, penyimpanan hasil dan ketentuan pemanfaatan yang diperlukan. Tidak mengasumsikan layanan gratis.
- Preflight dari server memeriksa koneksi, metadata BPS, screenshot terbaca, download/preview fallback, dan pengembalian kesalahan yang aman.
- Biaya search, sesi browser, dan token AI ditampilkan terpisah; catat unit pemakaian. Jangan menampilkan estimasi rupiah jika tarif belum dikonfigurasi/diverifikasi.
- Fitur yang belum siap tetap menghasilkan status perlu verifikasi dengan fallback manual, tidak menghambat pembacaan PDF target.

### Batas operasional awal — konfigurasi, bukan janji performa

| Komponen | Nilai awal |
|---|---|
| AI global | 2 request in-flight, termasuk page/pair/reference |
| Browser global | 1 sesi; terpisah dari slot AI |
| Search | Maksimal 2 query per percobaan run, maksimal 1 request/detik atau lebih rendah sesuai provider |
| Kandidat | Maksimal 5 hasil/query, 3 laman kandidat/percobaan; berhenti di kecocokan resmi pertama |
| Browser | Session maksimum 90 detik; HTTP 100 detik; job 120 detik; maksimal 2 percobaan jaringan/session per langkah |
| AI tambahan | Timeout 180 detik; maksimal 3 request aktual/check termasuk koreksi format; tidak menghitung antrean menunggu kapasitas sebagai request |
| Retry | 429 mengikuti Retry-After; 5xx/timeout backoff; kredensial salah/CAPTCHA tidak diulang terus |
| Artifact | PNG/JPEG maksimal 10 MB; PDF maksimal 150 MiB/500 halaman; maksimal 6 halaman rujukan dirender awal |
| Cache rujukan | Kandidat reuse sampai 7 hari untuk identitas tepat; bukti snapshot tetap immutable dan harus lolos pemeriksaan versi/input |
| Tombol mulai/retry | Rate limit server 3 aksi/menit per pengguna–publikasi; idempotency tetap wajib |

Saat kebutuhan melampaui batas, tampilkan alasan dan opsi verifikasi manual/retry eksplisit; jangan diam-diam membuka sesi tanpa batas. Budget gate terpusat menjumlah percobaan, bukan reset counter setiap job retry. Provider gratis/berbayar tidak diuji berulang otomatis saat halaman settings dibuka.

### Guardrail

- HTTPS dan domain BPS yang benar: cocok batas hostname `bps.go.id`/subdomainnya, bukan substring. Host unduhan/CDN hanya ditambah pengembang setelah diverifikasi; tidak otomatis mengizinkan semua redirect.
- Validasi URL, redirect, popup/tab baru, iframe, download, dan subresource browser. Tolak localhost, IP privat/link-local/metadata, skema non-HTTP, userinfo, host menipu, dan URL bertoken sensitif pada log.
- Validasi resolusi pada jalur fetch lokal dan pastikan pembatasan egress/redirect browser remote memadai melalui preflight; validasi hostname di PHP saja bukan jaminan menutup DNS rebinding pada browser remote.
- Browser menjalankan script milik pengembang; pengguna/AI/isi laman tidak boleh mengirim JavaScript arbitrer atau instruksi tool. Tidak ada kredensial produksi dalam context browser.
- Teks PDF, HTML BPS, screenshot, dan hasil search adalah data tak tepercaya, bukan instruksi sistem. Terapkan JSON schema dan allowlist tindakan.
- CAPTCHA/login/akses ditolak → berhenti dan beri kendala; jangan mengaktifkan endpoint bypass sebagai bagian fitur ini.
- Validasi MIME sebenarnya, signature PDF/gambar, ukuran streaming, jumlah halaman, path aman, serta timeout renderer. HTML error dengan ekstensi PDF ditolak.
- Artifact disimpan privat; route unduh/preview memakai policy publikasi. Escape HTML UI dan netralkan formula injection ketika menulis string ke Excel.
- Kunci terenkripsi di server; edit key kosong berarti mempertahankan nilai; audit log hanya metadata aman. Tidak mengirim key AI kepada browser/search provider.

## 6. Peta file dan kontrak antarmodul

Struktur mengikuti `app/Audit` yang sudah ada; hindari refactor besar atau repository generik yang tidak diperlukan.

| Kelompok | File yang dibuat | File yang diubah |
|---|---|---|
| Versi/cakupan | `audit-rules/coverage.json`, `resources/prompts/page-audit-v2.md`; migration additive; model `PublicationCheckRun`, `PublicationCheck`, `PublicationReference`, `ReferenceProviderConfig`, `FindingAssessment` di `app/Models/` | `audit-rules/pedoman.md`, `config/audit.php`, `app/Services/CreatePublication.php`, `app/Audit/RuleRegistry.php`, model terkait |
| Kontrak/index | `app/Audit/EvidenceValidator.php`, `app/Audit/PageAnatomyIndexer.php` | `PromptBuilder.php`, `PageResultValidator.php`, `FindingWriter.php`, `resources/js/types.ts` |
| Provider | `app/Audit/PublicationSearchClient.php`, `BraveSearchClient.php`, `PublicationBrowserClient.php`, `BrowserlessPublicationClient.php`, `ReferenceUrlPolicy.php`; `resources/browser/bps-publication.js` | `app/Providers/AppServiceProvider.php`, `config/services.php`, `.env.example` tanpa key nyata |
| Pemeriksa | `app/Audit/VolumeHistoryChecker.php`, `PrintLayoutChecker.php`, `CoverTitleComparator.php`; `resources/prompts/cover-title-v1.md`, `resources/prompts/volume-reference-v1.md` | `RuleRegistry.php`; `CrossChecker.php` hanya bila butuh adapter input, tidak menaruh panggilan jaringan di sana |
| Orkestrasi | `app/Audit/AdditionalCheckCoordinator.php`, `CoverageService.php`; jobs `FetchPublicationReference`, `AnalyzeReferenceVolume`, `CompareCoverTitle`, `CheckPrintLayout` di `app/Jobs/` | `AuditCoordinator.php`, `FinalizeAudit.php`, `WriteWorkbook.php`; recovery/worker command aktual bila diperlukan |
| Web | `app/Http/Controllers/PublicationCheckController.php`, `PublicationReferenceController.php`; request `StartAdditionalChecksRequest`, `StorePublicationReferenceRequest`; komponen `AdditionalChecksPanel.vue`, `ReferenceEvidencePanel.vue`, `CoverTitleComparison.vue` di `resources/js/Components/` | `routes/web.php`, `PublicationController.php`, `PublicationRequest.php`, settings controller/request aktual, `PublicationPolicy.php`, `Pages/Publications/Create.vue`, `Show.vue`, `Pages/Settings.vue` |
| Excel | — | `app/Audit/WorkbookWriter.php`, `FindingWriter.php`, `WriteWorkbook.php` |

Prompt existing berada di `resources/prompts/page-audit-v1.md`; pertahankan file itu untuk kompatibilitas, buat page-v2 terpisah dan pilih berdasarkan versi run/publikasi. Settings existing berada di `app/Http/Controllers/SettingsController.php`; request provider baru bernama `app/Http/Requests/ReferenceConfigRequest.php`. Runbook existing berada di `deploy/deployment-runbook.md`, `deploy/release-checklist.md`, `deploy/backup-runbook.md`, dan `deploy/audit-worker.sh`; perluas yang relevan, bukan membuat sistem deploy kedua.

Kontrak PHP memakai array shape terdokumentasi, bukan DTO framework baru:

- `PageAnatomyIndexer::build(array $pageResults): array` → indeks `pages`, `anchors`, `uncertainties`; setiap page memiliki field §3.3.
- `PublicationSearchClient::search(string $query, int $limit = 5): array` → list `{rank,title,url}`; snippet opsional bukan bukti volume.
- `PublicationBrowserClient::inspect(string $url): array` → `{access_status,read_status,metadata,artifacts,error_code}`; file artifact/manifest dipersist oleh job dalam batas run.
- `VolumeHistoryChecker::compare(array $currentIdentity, array $reference): array` → `{decision,expected_volume,components,evidences,limitations}`.
- `PrintLayoutChecker::check(array $anatomy): array` → `{decision,actual,expected,findings,limitations}`.
- `CoverTitleComparator::compare(PublicationCheckRun $run, int $coverPage, int $titlePage): array` → `{decision,components,evidences,limitations}` melalui client AI existing.
- `AdditionalCheckCoordinator::start(Publication $publication, User $actor, array $scope): PublicationCheckRun`; `advance(PublicationCheckRun $run): void`; `retry(PublicationCheck $check, User $actor): void`.
- `CoverageService::summarize(Publication $publication): array` → kontrak §4.3, dipakai UI, export, dan lock yang sama agar tidak berbeda hitungan.

Tambahkan kode aturan `VOLUME.HISTORY`, `TITLE.COVER_LAYOUT`, `PRINT.ROMAN_END`, `PRINT.TRAILING_BLANKS`; gunakan `IDENTITY.CONSISTENCY` dan `CATALOGUE.PAGE_COUNT` untuk aturan lama yang sesuai. Semua kode terdaftar di validator; jangan membuat temuan dengan kode bebas.

## 7. Implementation backlog — urutan eksekusi

Task 1 → 2 → {3 → 4 → 5, 6, 7} → 8 → 9 → 10 → 11. Cabang hanya menyatakan dependensi; bukan instruksi otomatis membuat banyak agent. Semua langkah di bawah masih belum dikerjakan.

### Task 1 — Bekukan aturan, inventaris cakupan, dan dasar run aman

**Files:** `audit-rules/coverage.json`, `audit-rules/pedoman.md`, `config/audit.php`, migration/model §4.1, `app/Services/CreatePublication.php`, `tests/Feature/RuleVersioningTest.php`.

**Interfaces:** menghasilkan model/snapshot run dan versi §4.1; belum menyalakan feature flag. Matriks per aturan memuat `spec_ref`, `rule_code`, `implementation_mode`, `verification_test`, `status`; aturan unresolved tetap manual, bukan nonconform wajib.

- [ ] Tulis `test_supplemental_version_does_not_relabel_or_destroy_legacy_audit` dan `test_all_guideline_sections_have_coverage_entries`. Assertion inti:

```php
$this->assertSame('bps-2026.10.05-v1', $publication->fresh()->rule_version);
$this->assertSame('bps-2026.10.18-v2', $run->target_rule_version);
$this->assertSame($beforePageResults, $publication->fresh()->pages->pluck('result', 'id')->all());
$this->assertSame($beforeExportHashes, $publication->fresh()->exports->pluck('checksum', 'id')->all());
```

- [ ] RED: `php artisan test --compact --filter=RuleVersioningTest`; harus gagal karena kontrak run/matriks belum tersedia.
- [ ] Implementasikan migration additive, model, snapshot/hash dan matriks **seluruh §1–21**. Salin pedoman authoritative secara utuh untuk paket baru; simpan asal/versi dan jangan menimpa snapshot publikasi lama. Matikan `AUDIT_RULES_V2_ENABLED`, `AUDIT_VISUAL_PAIR_ENABLED`, `AUDIT_EXTERNAL_REFERENCES_ENABLED` secara default selama pembangunan.
- [ ] GREEN: jalankan perintah RED kembali; matriks membedakan aturan sudah diuji, prompt-only, manual, dan gap. Test coverage memeriksa setiap nomor bagian 1 sampai 21 memiliki entri tidak kosong dan setiap kode executable menunjuk test yang ada. Semua gap executable yang ditemukan harus diberi task/test sebelum flag V2 aktif; jangan menyamarkan gap dengan label sudah diterapkan. Test menyertakan sel/keputusan review lama serta snapshot rules/prompt yang tetap identik.
- [ ] Commit terbatas file task: `feat: version supplemental audit runs without resetting legacy results`.

### Task 2 — Kontrak observasi halaman dan bukti yang tidak ambigu

**Files:** `PageAnatomyIndexer.php`, `EvidenceValidator.php`, prompt/schema page aktual, `PromptBuilder.php`, `PageResultValidator.php`, `FindingWriter.php`, `resources/js/types.ts`, `tests/Feature/PageAnatomyTest.php`.

**Interfaces:** konsumsi hasil lama/page-v2; menghasilkan indeks §3.3 dan union evidence §4.2. Hasil lama tanpa field baru menjadi unknown, bukan dianggap halaman kosong.

- [ ] Tulis `test_unnumbered_divider_is_counted_and_unknown_is_not_blank`, `test_external_page_is_not_target_physical_page`, dan `test_legacy_evidence_normalizes_without_fabricated_pages`:

```php
$index = $indexer->build($pageResults);
$this->assertFalse($index['pages'][5]['blank_kind'] === 'pure_white');
$this->assertNull($index['pages'][6]['has_content']);
$this->assertSame('reference_artifact', $referenceEvidence['kind']);
$this->assertArrayNotHasKey('physical_page', $referenceEvidence);
```

- [ ] RED: `php artisan test --compact --filter=PageAnatomyTest`; gagal karena normalisasi/index baru belum ada.
- [ ] Implementasikan signature §6; validasi bounds bbox, halaman 1..page_count, reference/artifact milik run yang benar, roman canonical termasuk `xxi/xxii`; `IIII`/nilai ambigu → uncertainty. Page-v2 menyatakan keberadaan ornamen/watermark/nomor terpisah dari teks utama. Jangan mengandalkan OCR teks kosong untuk menyatakan gambar putih.
- [ ] GREEN: ulang perintah; tambahan case ISSN pada gambar, nomor tidak terbaca, dan jenis halaman ambigu harus mempertahankan uncertainty tanpa mengarang isi.
- [ ] Commit: `feat: add compatible anatomy and typed evidence contracts`.

### Task 3 — Adaptor eksternal terkontrol dan preflight

**Files:** interface/client/policy/script provider §6, `ReferenceProviderConfig`, settings config/controller/request aktual, `tests/Feature/ReferenceProviderTest.php`.

**Interfaces:** `search()` dan `inspect()` §6, hasil terstruktur access/read terpisah; binding di `AppServiceProvider`. Script browser hanya buatan pengembang dan menerima URL yang lolos policy sebagai data context.

- [ ] Tulis `test_browser_access_success_does_not_imply_read_success`, `test_redirect_private_host_is_rejected`, `test_provider_error_redacts_query_token`:

```php
$this->assertSame('opened', $inspection['access_status']);
$this->assertSame('unreadable', $inspection['read_status']);
$this->assertFalse($urlPolicy->allows('https://bps.go.id.attacker.invalid/publication'));
$this->assertStringNotContainsString($secretToken, $sanitizedError);
```

- [ ] RED: `php artisan test --compact --filter=ReferenceProviderTest`; gagal karena adaptor/policy belum ada.
- [ ] Implementasikan client HTTP tanpa SDK baru, timeout/budget §5, encryption/redaction, request interception/redirect guard browser, metadata/screenshot dan fallback download. Brave Web Search memakai hasil berurutan; jangan memakai jawaban rangkuman mesin pencari sebagai volume. Pisahkan 401/403, rate-limit, timeout, CAPTCHA, download_invalid dan preview_unreadable. Preflight tidak otomatis membeli atau menjalankan saat settings dibuka.
- [ ] GREEN: ulang perintah memakai `Http::fake()` dan fixture browser; verifikasi key tidak ada di props/log, payload HTML berkedok PDF ditolak, dan layanan belum dikonfigurasi menghasilkan keterbatasan yang bisa ditindaklanjuti.
- [ ] Commit: `feat: add guarded search and remote browser adapters`.

### Task 4 — Resolusi rujukan resmi, wilayah, dan provenance

**Files:** `FetchPublicationReference.php`, `PublicationReference.php`, `ReferenceUrlPolicy.php`, `PublicationRequest.php`, request/controller reference, `tests/Feature/ReferenceResolutionTest.php`, fixture `tests/Fixtures/References/`.

**Interfaces:** konsumsi client Task 3 dan context wilayah; menghasilkan reference dengan artifact manifest/hash yang dapat dipakai Task 5. Gunakan hasil rank pertama yang cocok, bukan hasil nama mirip.

- [ ] Tulis `test_same_named_district_in_other_regency_is_not_used`, `test_only_permitted_queries_are_sent`, `test_uploaded_screenshot_keeps_user_provenance`:

```php
$this->assertSame('Kecamatan Ampana Kota Dalam Angka 2025', $queries[0]);
$this->assertCount(0, $acceptedWrongRegencyReferences);
$this->assertSame('user_upload', $uploadedReference->provenance);
$this->assertNull($uploadedReference->reviewed_at);
```

- [ ] RED: `php artisan test --compact --filter=ReferenceResolutionTest`; gagal karena alur resolusi belum ada.
- [ ] Implementasikan pencocokan exact identity bertahap, pengambilan metadata dan fallback sesuai §3.1, hash/artifact privat, rank/rejection trail dan batas §5. Data wilayah lama kosong → needs_verification, bukan tebakan kabupaten. Unggahan screenshot divalidasi dan menunggu review asal/kecocokan; jenis publikasi selain KCDA tidak diberi query otomatis baru.
- [ ] GREEN: ulang perintah; uji cover-only, PDF terbuka tetapi gagal render, host unduh di luar allowlist, source URL hilang, dan query tahun berjalan opsional tanpa memperluas pencarian.
- [ ] Commit: `feat: resolve official references with jurisdiction and evidence provenance`.

### Task 5 — Pemeriksa volume internal dan historis

**Files:** `VolumeHistoryChecker.php`, `AnalyzeReferenceVolume.php`, `resources/prompts/volume-reference-v1.md`, `RuleRegistry.php`, `tests/Feature/VolumeHistoryTest.php`.

**Interfaces:** `compare()` §6; input reference meliputi series_match, jurisdiction_match, year, frequency, prior_volume, identity_conflict, continuity_status dan evidences. `expected_volume` nullable.

- [ ] Tulis `test_annual_volume_31_expects_32_not_publication_number`, `test_unreadable_reference_never_invents_expected_volume`, `test_series_reset_requires_verification`:

```php
$result = $checker->compare($currentIdentity, $annualVolume31Reference);
$this->assertSame(32, $result['expected_volume']);
$this->assertSame('nonconform', $result['decision']);
$this->assertNull($checker->compare($currentIdentity, $unreadableReference)['expected_volume']);
$this->assertSame('needs_verification', $checker->compare($currentIdentity, $renumberedReference)['decision']);
```

- [ ] RED: `php artisan test --compact --filter=VolumeHistoryTest`; gagal karena checker belum ada.
- [ ] Implementasikan pembacaan reference dengan AI existing + kapasitas global; validator menuntut quote/gambar sumber yang benar. Perbandingan numerik deterministik mengikuti syarat §3.1. Laporkan empat komponen target dan konflik rujukan secara eksplisit. Gunakan teks keterbatasan persis dari pedoman ketika prior_volume belum terbaca.
- [ ] GREEN: ulang perintah; test sumber volume16 mengharapkan17, bukan32, dan snippet/nomor publikasi tanpa gambar tidak cukup. Test respons AI malformed tidak meluluskan check.
- [ ] Commit: `feat: verify publication volume against evidenced prior editions`.

### Task 6 — Pemeriksa romawi, blank cetak, dan jumlah katalog

**Files:** `PrintLayoutChecker.php`, `CheckPrintLayout.php`, `RuleRegistry.php`, `tests/Feature/PrintLayoutTest.php`, fixture anatomi `tests/Fixtures/PrintLayout/`.

**Interfaces:** `check(array $anatomy): array`; memakai indeks Task 2, tanpa jaringan dalam checker. Bila observasi kurang, coordinator meminta pembacaan tambahan terbatas halaman terkait, bukan mengarang blank/nomor.

- [ ] Tulis `test_odd_arabic_content_requires_two_blanks_not_added_to_catalogue`, `test_even_arabic_content_requires_one_blank`, `test_odd_roman_end_is_completed_to_even`, `test_ornamented_tail_is_not_pure_blank`:

```php
$result = $checker->check($roman21PlusBlankArabic91TwoBlankFixture);
$this->assertSame('conform', $result['decision']);
$this->assertSame('xxii+91 halaman/pages', $result['expected']['catalogue_page_count']);
$this->assertSame(2, $result['expected']['trailing_white_pages']);
$this->assertSame(1, $checker->check($arabic92Fixture)['expected']['trailing_white_pages']);
```

- [ ] RED: `php artisan test --compact --filter=PrintLayoutTest`; gagal karena checker belum ada.
- [ ] Implementasikan algoritme §3.3, termasuk pembatas tanpa nomor yang tetap dihitung, front blank tidak dihitung, batas romawi/Arab, blank romawi dihitung, konten penutup/lampiran yang mungkin melewati daftar pustaka, dan kover belakang tidak dihitung. Bukti sertakan anchor dan seluruh halaman ekor terkait. Koreksi katalog aktual/usulan dipisahkan.
- [ ] GREEN: ulang perintah; data provider mencakup seluruh sembilan baris tabel §3.3, nomor tercetak berbeda ordinal, halaman hilang, roman tidak valid, ekstra blank, dan uncertain tidak dianggap putih. Jangan menuntut blank tambahan pada fixture yang sudah benar.
- [ ] Commit: `feat: validate print blanks and catalogue pagination from page anatomy`.

### Task 7 — Preflight dua gambar dan pembanding layout

**Files:** `CoverTitleComparator.php`, `CompareCoverTitle.php`, `resources/prompts/cover-title-v1.md`, existing AI client/preflight service, `tests/Feature/CoverTitleComparisonTest.php`.

**Interfaces:** `compare()` §6; hasil setiap komponen memuat observed_cover, observed_title, decision dan bukti kedua halaman. Input halaman dipilih dari anatomi, bukan hardcode.

- [ ] Tulis `test_pair_request_contains_both_labeled_images`, `test_white_title_retains_logo_and_excludes_cover_photo`, `test_blurred_pair_is_not_conform`:

```php
$this->assertCount(2, $sentImageParts);
$this->assertSame([1, 3], $comparedPhysicalPages);
$this->assertSame('needs_verification', $blurredResult['decision']);
$this->assertSame('conform', $whiteTitleResult['components']['logo']['decision']);
```

- [ ] RED: `php artisan test --compact --filter=CoverTitleComparisonTest`; gagal karena pembanding belum ada.
- [ ] Implementasikan pair prompt/validator, preflight dua gambar berbeda dengan jawaban terverifikasi, batas global AI dan bukti §3.2. Kover yang salah tidak menjadi expected mentah. Penamaan font/point yang tidak didukung tidak disimpan sebagai fakta. Model tanpa dukungan multi-image menampilkan kendala, bukan fallback satu gambar yang disebut pair check selesai.
- [ ] GREEN: ulang perintah; fixture mencakup judul sebenarnya pada PDF5, perataan/baris berbeda, Catalogue italic sudah benar, kontras saja berbeda, logo hilang, dan respons tidak memuat satu gambar.
- [ ] Commit: `feat: compare cover and title layout using validated image pairs`.

### Task 8 — Coordinator, retry, idempotency, dan finalisasi gabungan

**Files:** `AdditionalCheckCoordinator.php`, `CoverageService.php`, `AuditCoordinator.php`, `FinalizeAudit.php`, jobs tambahan, recovery command aktual, `tests/Feature/AdditionalCheckWorkflowTest.php`.

**Interfaces:** `start/advance/retry/summarize` §6. Check terjadwal setelah dependency siap; event penyelesaian mendorong `advance`, bukan busy-wait. Job lama tetap dapat menyelesaikan pekerjaan tanpa menulis hasil run baru.

- [ ] Tulis `test_supplemental_run_preserves_114_completed_pages`, `test_duplicate_jobs_are_idempotent`, `test_late_result_cannot_replace_newer_run`, `test_page_complete_does_not_hide_pending_reference_check`:

```php
$this->assertSame(114, $publication->fresh()->pages()->where('status', 'complete')->count());
$this->assertSame($beforeReviewedFindings, $reviewedFindingsAfter);
$this->assertSame(1, $matchingChecks->count());
$this->assertSame('partial', $coverage['report_mode']);
$this->assertFalse($coverage['can_lock']);
```

- [ ] RED: `php artisan test --compact --filter=AdditionalCheckWorkflowTest`; gagal karena coverage tambahan belum terintegrasi.
- [ ] Implementasikan lock publication→run/check konsisten, unique key/input hash, lease/attempt budget, compare-and-store revision setelah network call, terminal callback idempotent, dan timeout recovery check tanpa reset pages. Request referensi memakai antrean `references` satu worker; pembacaan/pair/reference-AI memakai antrean `ai` dan `AiCapacity` yang sama. Jangan menahan slot AI saat menunggu browser. Semua request retry berbiaya tercatat.
- [ ] GREEN: ulang perintah; uji budget habis, 429 cooldown, restart worker, partial export saat check aktif, publikasi locked, dan check failed memerlukan tindakan. Angka114 di sini fixture regresi, bukan klaim kondisi produksi terkini.
- [ ] Commit: `feat: coordinate supplemental checks with safe retries and truthful coverage`.

### Task 9 — Temuan, assessment dua arah, dan Excel berversi

**Files:** `FindingWriter.php`, `FindingAssessment.php`, `WorkbookWriter.php`, `WriteWorkbook.php`, export controller/model aktual, `tests/Feature/SupplementalExportTest.php`.

**Interfaces:** konsumsi union evidence dan coverage; output memakai template existing, versi export baru dan checksum. Assessment lama diputus reviewer, bukan otomatis menghapus finding.

- [ ] Tulis `test_reference_evidence_exports_without_fake_target_pages`, `test_excel_preserves_empty_identity_columns_and_extends_border`, `test_old_review_requires_explicit_assessment_acceptance`:

```php
$this->assertNull($sheet->getCell('B7')->getValue());
$this->assertNull($sheet->getCell('K'.$lastBodyRow)->getValue());
$this->assertSame('thin', $sheet->getStyle('M'.$lastBodyRow)->getBorders()->getBottom()->getBorderStyle());
$this->assertStringContainsString('Perlu verifikasi', $exportedLimitations);
$this->assertSame($oldExportSha256, $oldExport->fresh()->checksum);
```

- [ ] RED: `php artisan test --compact --filter=SupplementalExportTest`; gagal karena formatter/coverage tambahan belum ada.
- [ ] Implementasikan format bukti: halaman target dan halaman artifact rujukan tidak tercampur; cantumkan judul/tahun, URL, asal bukti, volume prior/current/expected bersyarat, serta batas cover-only. Tambah ringkasan run/version/coverage dan keterbatasan pada area keterangan template tanpa mengubah struktur utama. Temuan diklasifikasi wajib/saran/verifikasi dan pembatalan usulan; jangan menghitung semua catatan sebagai kesalahan terkonfirmasi.
- [ ] GREEN: ulang perintah; scan **seluruh** B7:Kakhir yang wajib kosong, nomor30 sisa, sel jauh di bawah laporan, wrapping/tinggi baris/print area, string panjang, formula injection `= + - @`, URL, dan file bisa dibuka ulang. Gunakan explicit string untuk teks tak tepercaya; pertahankan nilai numerik/formula template yang memang sah. Partial dan complete_with_limitations mendapat label berbeda.
- [ ] Commit: `feat: export versioned supplemental findings with traceable references`.

### Task 10 — UI sederhana, akses, dan review bukti

**Files:** controller/request/routes/policy dan tiga komponen §6; `Create.vue`, `Show.vue`, `Settings.vue`, `types.ts`; `tests/Feature/AdditionalCheckAccessTest.php`, `tests/Browser/additional-checks.spec.ts`, fixture browser existing.

**Interfaces:** gunakan endpoint authenticated berikut, mengikuti named route dan policy existing, bukan API publik baru:

| Method/path | Fungsi |
|---|---|
| `POST /publications/{publication}/checks` | Mulai run tambahan, scope allowlist, idempotent |
| `POST /publications/{publication}/checks/{check}/retry` | Retry check milik publikasi yang sama |
| `POST /publications/{publication}/references` | Upload bukti manual, bukan arbitrary fetch bebas |
| `GET /publications/{publication}/references/{reference}/artifacts/{artifact}` | Stream bukti privat dengan policy |
| `PATCH /publications/{publication}/assessments/{assessment}` | Reviewer menerima/menolak usulan koreksi dengan alasan |

Settings provider menggunakan pola route/settings admin yang sudah ada. Props show memuat ringkasan run/coverage; artifact besar dimuat sesuai kebutuhan, bukan seluruh base64 pada Inertia props.

- [ ] Tulis `test_foreign_publication_reference_is_forbidden` dan browser test `supplemental checks show honest coverage and preserve existing findings`:

```ts
await expect(page.getByRole('button', { name: 'Periksa tambahan dengan aturan terbaru' })).toBeVisible();
await expect(page.getByText('Cakupan gabungan')).toBeVisible();
await expect(page.getByText('Volume edisi sebelumnya belum terbaca', { exact: false })).toBeVisible();
await expect(page.getByRole('button', { name: 'Buat Excel sementara' })).toBeEnabled();
```

- [ ] RED: `php artisan test --compact --filter=AdditionalCheckAccessTest` dan `npm run test:e2e -- tests/Browser/additional-checks.spec.ts`; gagal karena route/panel belum ada. Fixture browser memakai database terisolasi sesuai konfigurasi existing.
- [ ] Implementasikan panel tambahan di **Ruang pemeriksaan**, bukti/riwayat di **Pengelolaan**, hasil di **Hasil Excel**; tidak menambah menu utama. Settings tetap dalam tab koneksi/sistem existing. Dialog mulai/retry menunjukkan scope/biaya; status teks + ikon, bukan warna saja. Mobile memakai panel bertumpuk/toggle; comparison dua gambar bisa berganti peran tanpa teks terlalu kecil. Fokus keyboard, label, loading, error retry jelas, dan dialog Escape/focus trap memakai komponen reusable.
- [ ] GREEN: ulang perintah RED, `npm run typecheck`, `npm run build`; browser mengecek viewport desktop dan mobile, cancel dialog tidak membuat run, scoped IDOR ditolak, key tidak dikirim frontend, dan label bukan persentase semua aturan jika hanya pages selesai.
- [ ] Commit: `feat: expose supplemental checks and evidence review in existing workspace`.

### Task 11 — Regresi, kalibrasi, dan release bertahap

**Files:** tests Task 1–10, `tests/Fixtures/Golden/manifest.json` bila diperlukan, `docs/evaluation-results.md`, `deploy/deployment-runbook.md`, `deploy/release-checklist.md`, `deploy/backup-runbook.md`, `deploy/audit-worker.sh`.

**Interfaces:** seluruh kontrak di atas stabil; tidak ada hardcode credentials/identitas produksi. Feature flag dan backup memungkinkan rollback kode tanpa menghilangkan bukti baru.

- [ ] Tulis `test_guideline_regressions_preserve_confirmed_exceptions` pada `tests/Feature/RuleV2RegressionTest.php`:

```php
$this->assertCount(0, $findingsForCorrectCatalogueIssnColon);
$this->assertCount(0, $findingsForValidPodes2024SourceYear);
$this->assertCount(0, $findingsForAllowedLayoutersLabel);
$this->assertSame('needs_verification', $unreadablePriorVolumeDecision);
```

- [ ] RED: `php artisan test --compact --filter=RuleV2RegressionTest`; tambahkan case bermakna yang gagal bila ada aturan prompt-only/gap yang teridentifikasi Task1. Bila behavior sudah benar, test harus langsung lulus; jangan sengaja merusak implementasi untuk memperoleh RED.
- [ ] Tutup gap matriks yang belum punya test/behavior: ISSN checksum tanpa nomor pengganti, BPS Indonesia vs Inggris, label tanpa spasi `/`, running-title parity, tabel/gambar hierarki independen, total bersyarat, superskrip vs minus/nol, dan angka sumber tidak diubah otomatis. Gap di luar tiga fitur utama yang material menjadi task tersendiri sebelum flag penuh diaktifkan, bukan diam-diam dianggap selesai.
- [ ] GREEN: `php artisan test --compact`, `php vendor/bin/pint --dirty --format agent`, `npm run typecheck`, `npm run build`, `npm run test:e2e`. Catat hasil aktual, bukan mengambil hitungan tes dari pekerjaan sebelumnya. Uji kompatibilitas migration dan locking pada database MariaDB staging juga, karena SQLite tidak merepresentasikan seluruh perilaku produksi.
- [ ] Kalibrasi terarah satu publikasi representatif dengan truth set manual: volume tiap komponen, pasangan visual, anchor romawi/Arab, dan seluruh halaman akhir. Pisahkan fixture contoh dari bukti aktual. API live dibatasi satu preflight integrasi per jalur setelah key/budget disetujui; jangan menjalankan ulang seluruh 120–180 halaman hanya untuk membuktikan integrasi tambahan.
- [ ] Commit: `test: verify rules v2 regressions and staged release readiness`.
- [ ] Setelah pengguna menyetujui hasil implementasi dan readiness gate, jalankan prosedur release §8; planning ini sendiri tidak menjalankan langkah deploy tersebut.

## 8. Strategi deploy cPanel dan rollback

### Tahap A — Fondasi kompatibel

1. Ambil backup database, file aplikasi, `APP_KEY`/konfigurasi secara privat, storage PDF/rules/prompt/artifact/Excel dan daftar cron. Catat checksum/jumlah halaman selesai/temuan/keputusan/export kondisi saat deploy; jangan memakai angka dari screenshot lama sebagai baseline terkini.
2. Pastikan backup dapat dipulihkan dan direktori release/backup tepat. Jangan menjalankan `migrate:fresh`, reset database, hapus storage, `queue:clear`, atau retry semua job.
3. Deploy kode kompatibel dengan semua flag baru mati; jalankan migration additive dan build asset. Pekerjaan lama tetap mempunyai snapshot yang dapat dibaca kode baru.
4. Restart worker secara graceful setelah job aktif selesai; jangan mematikan proses yang sedang menulis hasil. Pertahankan worker existing default + dua AI, batas lock/timeout dan heartbeat yang sudah berjalan.

### Tahap B — Aturan lokal dan visual

1. Aktifkan run/index/print checker untuk satu publikasi uji yang dipilih; periksa perbedaan hash/record untuk membuktikan hasil lama tetap utuh.
2. Jalankan preflight pair model yang dipakai; baru aktifkan pair checker. Mengganti model harus menginvalidasi readiness pair model/config lama, tanpa menghapus hasil run lama.
3. Uji Excel sementara dan laporan lengkap/berketerbatasan memakai snapshot baru; cek unduh versi lama masih berfungsi.

### Tahap C — Rujukan eksternal

1. Masukkan key melalui settings admin; cek endpoint dan paket, egress, ketentuan penyimpanan, serta budget.
2. Tambahkan satu worker `references` dengan pengunci cron anti-overlap dan konfigurasi timeout yang cocok. Seluruh queue `retry_after` harus lebih panjang daripada timeout job terkait + margin; verifikasi nilai existing sebelum mengubah. Jangan menurunkan jaminan anti-job-ganda worker AI.
3. Jalankan preflight metadata, cover, fallback download/preview, serta kegagalan terkendali. Baru aktifkan `AUDIT_EXTERNAL_REFERENCES_ENABLED`.
4. Aktifkan V2 default untuk upload baru setelah matriks aturan dan check wajib siap. Publikasi lama tetap opt-in; tidak ada backfill massal otomatis.

### Rollback

- Matikan flag fitur bermasalah dan hentikan dispatch baru kategori tersebut; biarkan job yang valid selesai atau tandai dibatalkan dengan audit trail.
- Pulihkan versi kode sebelumnya yang kompatibel dengan skema additive jika diperlukan. Jangan drop tabel/kolom yang sudah menyimpan bukti dan jangan menimpa hasil baru menggunakan backup lama hanya karena UI bermasalah.
- Snapshot/hasil/Excel lama tetap dapat dibaca. Check yang belum selesai tampil sebagai keterbatasan, bukan diubah menjadi lulus.
- Pemulihan database dari backup hanya untuk insiden kehilangan/kerusakan data yang terkonfirmasi, setelah menghitung dampak terhadap pekerjaan yang masuk sesudah backup dan mendapat persetujuan.

## 9. Acceptance criteria akhir

- [ ] Seluruh §1–21 mempunyai pemetaan cakupan yang jujur; aturan belum dikonfirmasi tidak dipaksakan sebagai kewajiban.
- [ ] Upload baru membekukan versi aturan/prompt; run tambahan mempertahankan semua hasil lama dan tidak mengantrekan ulang halaman selesai.
- [ ] Volume terverifikasi dari seri/tahun/wilayah yang tepat; keterbatasan cover-only dan provenance unggahan selalu terlihat.
- [ ] Akses laman/PDF dan keberhasilan membaca isi dipisah; gagal baca tidak pernah memberi nilai volume dugaan.
- [ ] Pair checker benar-benar mengirim dua gambar yang tepat dan membuktikan perbedaan kedua halaman, tanpa menyalin kesalahan kover.
- [ ] Contoh `xxi → xxii`, `91 + dua blank`, dan `92 + satu blank` lulus; katalog tidak menghitung blank akhir/kover belakang sebagai Arab.
- [ ] Retry bounded/idempotent; restart worker tidak menggandakan hasil; AI seluruh cabang tetap maksimal dua in-flight.
- [ ] Pemeriksaan halaman dan pemeriksaan tambahan memiliki coverage terpisah; run tambahan tidak diklaim sebagai audit ulang penuh V2.
- [ ] Ekspor otomatis setelah pekerjaan wajib terminal sesuai mode; Excel sementara tetap tersedia ketika proses belum lengkap. Tidak ada hasil parsial yang dilabel lengkap.
- [ ] Hasil bisa diedit/review; usulan pembatalan temuan lama tidak langsung menghapus keputusan reviewer.
- [ ] Excel mempertahankan struktur template, border sampai baris terakhir, wrapping/print area, metadata, sel wajib kosong, dan versi lama; tidak ada nilai30 massal/formula injection.
- [ ] UI sederhana, mobile dan keyboard usable; bukti hanya diakses pegawai yang berhak; key tidak terekspos.
- [ ] Backup/rollback dan readiness provider sudah diverifikasi sebelum rollout, dengan catatan hasil tes aktual.

## 10. Titik persetujuan dan handoff

**Dapat dimulai sesudah rencana disetujui:** Task1–2 dan checker lokal dengan mock/fixture; tidak memerlukan key provider baru.

**Memerlukan kesiapan tambahan sebelum live:** pemilihan/aktivasi paket search-browser oleh pengguna, key yang disimpan aman, preflight dari shared hosting, izin budget penggunaan, dan satu publikasi kalibrasi yang ditentukan. Pengembangan adaptor dapat memakai fake sampai syarat tersebut terpenuhi.

**Rekomendasi eksekusi:** Native bertahap dalam sesi yang sama, dengan review setiap checkpoint dan review independen akhir bila pengguna mengizinkan. Alasannya, kontrak run/evidence/coverage saling bergantung dan harus terintegrasi konsisten; tidak perlu banyak agent browser bebas. Alternatif subagent-driven tersedia jika pengguna memilih review terpisah per task, dengan biaya konteks lebih besar.

**Batas estimasi:** pekerjaan dipaketkan menjadi 11 unit review, bukan janji selesai dalam durasi tertentu. Lama eksekusi terutama dipengaruhi gap matriks aktual, kompatibilitas multi-image model, kesiapan layanan eksternal, dan variasi laman/preview BPS. Jangan menjanjikan kecepatan atau akurasi 100% sebelum kalibrasi.

**Self-review rencana:** cakupan tambahan dipetakan ke Task4–7; pelestarian data Task1/8; kontrak bukti Task2; keamanan provider Task3/4; Excel Task9; akses/UI Task10; regresi/rollout Task11. Lima kelas kegagalan di Review Focus memiliki tes yang disebutkan. Tidak ada pembelian, akses produksi, atau coding fitur yang dijalankan sebagai bagian penulisan rencana ini.
