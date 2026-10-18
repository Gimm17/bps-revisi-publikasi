# Hasil Evaluasi dan Kalibrasi Aturan Audit V2 (bps-2026.10.18-v2)

Dokumen ini mencatat ringkasan evaluasi teknis, verifikasi regresi, dan kesiapan rilis bertahap dari pembaruan aturan audit publikasi BPS V2.

---

## 1. Ringkasan Implementasi dan Metrik

- **Versi Baseline:** `bps-2026.10.05-v1` (Prompt: `page-v1`)
- **Versi Tambahan (V2):** `bps-2026.10.18-v2` (Prompt: `page-v2`, `cover-title-v1`, `volume-reference-v1`)
- **Arsitektur Migrasi:** Additive migration tanpa reset data atau truncate (`2026_10_18_000001_create_supplemental_check_run_tables.php`).
- **Penyimpanan Snapshot:** Snapshot aturan authoritative dibekukan pada `audit-rules/pedoman-v2.md` dan matriks `audit-rules/coverage.json`.

### Metrik Pengujian
- Total Unit & Feature Test: **97 tests, 2.300+ assertions** (100% lulus).
- Browser / End-to-End Test: `tests/Browser/additional-checks.spec.ts` dan `journey.spec.ts` lulus.
- Static Typecheck: `vue-tsc --noEmit` lulus tanpa error.
- Asset Build: `vite build` produksi berhasil.

---

## 2. Cakupan Tiga Fitur Utama

### A. Verifikasi Nomor Volume Bersejarah (`VOLUME.HISTORY`)
- **Logika:** Menguji nomor volume publikasi terhadap edisi resmi tahun sebelumnya ($T-1$) dari wilayah yang sama.
- **Nilai Harapan:** $V_{expected} = V_{prior} + 1$.
- **Kejujuran Bukti:**
  - Status akses (`opened`/`blocked`) dan status baca (`readable`/`unreadable`) dipisahkan.
  - Jika volume rujukan tidak terbaca atau terjadi CAPTCHA, keputusan ditetapkan sebagai `needs_verification` (Perlu verifikasi manual).
  - Tidak pernah mengarang nomor volume atau mengasumsikan kepatuhan tanpa bukti gambar/teks yang tervalidasi.

### B. Keselarasan Visual Kover dan Halaman Judul (`TITLE.COVER_LAYOUT`, `TITLE.WHITE_BACKGROUND`)
- **Logika:** Evaluasi pasangan gambar dua halaman secara serentak (dual-image multimodal) melalui `NineRouterClient`.
- **Komponen yang Dievaluasi:**
  1. Keberadaan dan keselarasan Logo BPS.
  2. Latar belakang putih murni pada halaman judul dalam (tanpa foto kover).
  3. Keselarasan tata letak dan hierarki tipografi.
  4. Kesamaan teks judul dan tahun.
- **Antarmuka Pengguna:** Komponen `CoverTitleComparison.vue` memungkinkan perbandingan side-by-side, pertukaran posisi tampilan, dan pemeriksaan visual komponen.

### C. Tata Letak Cetak & Halaman Blank (`PRINT.ROMAN_END`, `PRINT.TRAILING_BLANKS`)
- **Logika:**
  - Halaman romawi ganjil diselesaikan menjadi genap dengan 1 halaman blank.
  - Halaman Arab ganjil memerlukan 2 halaman blank murni di akhir dokumen sebelum kover belakang.
  - Halaman Arab genap memerlukan 1 halaman blank murni di akhir dokumen sebelum kover belakang.
  - Format katalog mencantumkan `[romawi]+[arab] halaman/pages` tanpa menghitung kover belakang atau blank akhir sebagai bagian dari halaman Arab.

---

## 3. Penanganan Bukti dan Format Excel

- **Union Evidences:** Bukti halaman publikasi (`publication_page`) dan bukti dokumen rujukan (`reference_artifact`) tidak tertukar. Dokumen rujukan tidak diberi label halaman palsu (`PDF hlm. X` target).
- **Integritas Template Excel:**
  - Kolom B7:K(akhir) tetap kosong sesuai template resmi.
  - Border thin diperpanjang secara utuh hingga baris laporan terakhir.
  - Formula injection dinetralkan dengan penulisan explicit string (`DataType::TYPE_STRING`).
  - Laporan memiliki label yang jujur: `SEMENTARA BELUM LENGKAP`, `DENGAN KETERBATASAN`, atau `Pemeriksaan Lengkap`.
- **Keputusan Peninjau:** Usulan pembatalan temuan oleh sistem (`FindingAssessment`) memerlukan persetujuan eksplisit dari peninjau sebelum temuan diubah atau ditolak.

---

## 4. Kesiapan Deployment cPanel

1. **Jalur Worker Terpisah:** Antrean `references` dialokasikan untuk satu proses worker tersendiri guna mencegah blocking pada antrean AI utama.
2. **Feature Flags:** Semua fitur eksternal V2 dikendalikan oleh flag lingkungan:
   - `AUDIT_RULES_V2_ENABLED`
   - `AUDIT_VISUAL_PAIR_ENABLED`
   - `AUDIT_EXTERNAL_REFERENCES_ENABLED`
3. **Rollback Aman:** Rollback kode tidak akan merusak data karena skema database bersifat aditif dan snapshot lama tetap terbaca.
