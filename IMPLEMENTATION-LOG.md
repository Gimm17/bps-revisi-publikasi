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
- Peninjauan independen seluruh cabang: tidak ada Critical; empat Important direproduksi dengan tes gagal lalu diperbaiki dalam satu pass. Hasil akhir dicatat di bawah.

## Rulings

- Ruling: gunakan database queue/cache lokal dan Redis configurable produksi — Redis lokal tidak terpasang tetapi hosting dikonfirmasi mendukung — risiko: perilaku multi-worker Redis wajib direhearsal pada staging.
- Ruling: gabungkan progress/workspace/export pada satu halaman dan admin dalam tiga tab — sesuai permintaan navigasi sederhana — risiko: kepadatan layar kecil ditangani tab mobile.
- Ruling: generator XLSX aplikasi memakai PhpSpreadsheet sesuai rencana Laravel, bukan runtime artifact desktop — aplikasi harus dapat deploy mandiri di PHP hosting — risiko: native Excel layout tetap diperiksa lewat pilot; unit test membuka ulang dan memeriksa border/isi.
- Ruling: live model/gold/hosting release gate tidak dianggap selesai — API key dan lingkungan hosting belum tersedia — risiko: akurasi AI belum dapat dinyatakan siap produksi.
- Bootstrap Laravel mengharuskan Boost; dependency dev/guidelines telah dipasang, tidak ada remote MCP/credential ditambahkan. Skill tambahan yang disebut guideline generated tidak tersedia pada tool session; menggunakan skill Laravel/Inertia/TDD yang tersedia dan dokumentasi resmi.

## Review akhir dan pembuktian

- Final: fixed unlock tidak boleh mengubah draft/partial menjadi review_ready — test_unlock_cannot_bypass_completion_and_only_reopens_a_valid_final RED→GREEN. Transaksi mensyaratkan final+locked+100% hasil halaman.
- Final: fixed API key lama tidak dapat dipulihkan — test_explicit_provider_migration_preserves_completed_pages_and_records_the_change RED→GREEN. Rebind eksplisit, persetujuan biaya, config terbaru lulus vision, tidak boleh ada proses aktif, log id config lama/baru dan halaman selesai dipertahankan. Tidak ada pergantian provider diam-diam.
- Final: fixed teks banyak newline terpotong dalam Excel — test_newline_heavy_excel_content_is_split_without_clipping_or_lost_text RED→GREEN. Pemecahan juga mempertimbangkan batas 24 baris tampilan; seluruh karakter dipertahankan.
- Final: fixed koreksi evidence diabaikan — test_evidence_correction_is_saved_with_history_and_page_validation RED→GREEN. Multi-bukti dapat disunting, validasi halaman/quote/bbox, history sebelum/sesudah dan version lock tetap berlaku.
- Final: minor (deferred): penghitung backlog pada Pengaturan masih membaca tabel jobs lokal; pada Redis dapat menampilkan nol. Pantau backlog Redis melalui supervisor/monitor Redis sampai metrik driver-aware ditambahkan; audit:health juga tidak menggantikan monitoring Redis.
- Final: Ruling: hal yang reviewer tidak dapat menilai (AI nyata/gold, DNS/TLS produksi, Redis/MySQL concurrency, restore/restart hosting, visual Excel native) tetap gerbang rilis terbuka — tes lokal tidak membuktikan kondisi tersebut — risiko: kualitas/operasi produksi belum dijamin sampai pilot dan staging lulus.
- Final: Ruling: repo web baru tidak memiliki base branch atau remote untuk merge/PR — pertahankan cabang implementasi dan seluruh file di folder yang diminta; tidak push/deploy — risiko: integrasi/deploy masih langkah terpisah dengan otorisasi pengguna.

## Hasil verifikasi final (5 Oktober 2026)

- PHPUnit: 42/42 tes lulus, 1.688 assertions. Keempat Important sudah masuk suite penuh yang hijau setelah fix pass.
- Playwright: 7/7 lulus; login axe WCAG A/AA tanpa pelanggaran, upload manifest nyata, koreksi kutipan/riwayat/ekspor/download XLSX, viewport 1440/1024/768/390 tanpa overflow. Tangkapan desktop/mobile ditinjau ulang; data fixture hanya di database browser terpisah.
- Typecheck, production build dan Pint lulus. Composer audit: tidak ada advisory/abandoned; npm production audit: 0 vulnerabilities.
- Lokal: Poppler tersedia, worker+heartbeat aktif, antrean dan gagal 0. Vision belum dikonfigurasi/lulus; tidak ada pemeriksaan AI nyata atau hasil produksi yang dibuat.
- Source disimpan pada cabang implementation/publication-audit; environment, credential, database, test output dan desain pengguna tidak dimasukkan ke Git. Tidak ada push/PR/deploy.
