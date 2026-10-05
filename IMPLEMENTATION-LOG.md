# Pelaksanaan sistem pemeriksaan publikasi

Rencana: `../docs/superpowers/plans/2026-10-05-publication-audit-platform.md`.

## Keputusan implementasi

- Pengguna mengizinkan pembangunan langsung di `web`; direktori desain pengguna dipertahankan. Repositori baru menggunakan cabang implementation/publication-audit.
- Spesifikasi proyek mengungguli contoh dalam skill: koneksi memakai 9Router, akses berdasarkan pemilik/penugasan publikasi, dan Redis tersedia untuk produksi. Database queue/cache digunakan untuk pengujian lokal karena Redis lokal belum tersedia; konfigurasi produksi tetap mendukung Redis.
- Desain desktop dan mobile dari Stitch menjadi rujukan visual. Data contoh pada HTML tidak disalin sebagai hasil pemeriksaan sungguhan.
- ChatGPT Plus bersifat kondisional dan dinonaktifkan sampai klien resmi tersedia. Integrasi utama tidak menunggu fitur ini.
- Pengujian provider memakai transport HTTP palsu; evaluasi akurasi pada publikasi nyata memerlukan kredensial/model 9Router. Tidak ada klaim nilai precision/recall produksi sebelum evaluasi tersebut.

## Kemajuan

- Pemeriksaan awal: PHP 8.4.16, Node 22.23.1, Composer 2.9.4, Poppler tersedia. Desain desktop/mobile telah dibuka dan ditinjau.
- Task 1–4: fondasi Laravel 13, akun/role/penugasan, upload privat, manifest dan snapshot aturan/prompt selesai; pengujian Access/Workflow/Engine lulus.
- Task 5–8: 9Router vision, uji challenge + halaman PDF rujukan, schema/evidence validator, retry DPI, queue idempotency/resume dan cross-check selesai. Pipeline test menggunakan PDF nyata + HTTP fake; bukan evaluasi akurasi model.
- Task 9–10: editor dengan optimistic version/history, keputusan/arsip manual, ekspor otomatis berversi, clear template B–K dan border seluruh baris. Laporan dibuka ulang; tes 151 temuan dan formula-injection lulus.
- Task 11: UI Stitch desktop/mobile diimplementasikan: login, dashboard, unggah, rail halaman, viewer/bbox/zoom, editor, filter, exports, admin. Enam Playwright journey/responsive tests lulus pada 1440/1024/768/390px.
- Task 12: encrypted key, HTTPS host allowlist/DNS pin, login throttle, headers/CSP, secure production config, heartbeat, health/resume CLI dan runbook deploy/backup. Restore hosting dan stress concurrency belum dilakukan.
- Task 13: disabled sesuai entry gate; tidak ada OAuth klien resmi/private-client atau kredensial browser ChatGPT.
- Task 14: pemetaan 12 acuan dan scorer adjudikasi tersedia. Gold normalization/adjudikasi manusia dan live model run belum selesai; tidak ada klaim recall/precision.
- Pengujian penuh terakhir sebelum hardening final: 37 PHPUnit test / 1627 assertions lulus; typecheck dan build lulus. Hasil final diperbarui setelah review.

## Rulings

- Ruling: gunakan database queue/cache lokal dan Redis configurable produksi — Redis lokal tidak terpasang tetapi hosting dikonfirmasi mendukung — risiko: perilaku multi-worker Redis wajib direhearsal pada staging.
- Ruling: gabungkan progress/workspace/export pada satu halaman dan admin dalam tiga tab — sesuai permintaan navigasi sederhana — risiko: kepadatan layar kecil ditangani tab mobile.
- Ruling: generator XLSX aplikasi memakai PhpSpreadsheet sesuai rencana Laravel, bukan runtime artifact desktop — aplikasi harus dapat deploy mandiri di PHP hosting — risiko: native Excel layout tetap diperiksa lewat pilot; unit test membuka ulang dan memeriksa border/isi.
- Ruling: live model/gold/hosting release gate tidak dianggap selesai — API key dan lingkungan hosting belum tersedia — risiko: akurasi AI belum dapat dinyatakan siap produksi.
- Bootstrap Laravel mengharuskan Boost; dependency dev/guidelines telah dipasang, tidak ada remote MCP/credential ditambahkan. Skill tambahan yang disebut guideline generated tidak tersedia pada tool session; menggunakan skill Laravel/Inertia/TDD yang tersedia dan dokumentasi resmi.
