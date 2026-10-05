# Checklist rilis

- [ ] HTTPS, cookie aman, document root public dan storage privat diverifikasi pada hosting.
- [ ] MySQL/Redis/Supervisor/Poppler diuji dengan akun proses sebenarnya.
- [ ] Uji vision 9Router nyata lulus; dense table dan teks kecil terbaca.
- [ ] Cakupan 12 publikasi acuan 100%, tidak ada halaman failed/uncertain.
- [ ] Label gold dari workbook atasan telah diadjudikasi manusia (bukan menyalin temuan lama sebagai fakta otomatis).
- [ ] Recall temuan wajib ≥95%, false-positive temuan wajib ≤10%, evidence completeness 100%, seluruh XLSX valid.
- [x] Uji browser lokal desktop/mobile dan peninjauan kode independen lulus (42 PHP + 7 browser tests; bukan pilot model nyata/hosting).
- [ ] Pengujian restart worker, backup/restore dan alert pada hosting selesai.
- [ ] Pilot pegawai, kuota/biaya, kebijakan retensi dan batas concurrency disetujui.
- [ ] Tidak ada password/key/database lokal ikut dalam deploy.
- [ ] ChatGPT-plan tetap disabled kecuali akses private-client resmi diberikan dan diuji.

Status saat pengembangan: kode dapat diuji lokal; evaluasi model nyata, hosting dan pilot belum dilakukan. Jangan mencentang gerbang yang belum dibuktikan.
