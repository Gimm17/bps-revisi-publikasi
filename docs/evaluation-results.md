# Evaluasi dan keterbatasan

## Yang sudah diuji otomatis

PDF aktual pada fixture dirender Poppler; respons AI terkontrol memeriksa validasi schema, halaman, bukti, resolusi ulang, idempotensi, pemeriksaan silang dan ekspor. Uji 179/180 halaman memastikan tidak ada klaim selesai saat satu halaman tersisa. Excel dibuka ulang, sel B–K dipastikan kosong, semua baris mempunyai border, formula/error dicegah. Browser memeriksa login, upload, editor, history, re-export/download dan lebar 1440/1024/768/390px.

HTTP fake adalah pengujian orkestrasi, BUKAN bukti akurasi model 9Router. Tidak ada angka precision/recall publikasi nyata yang telah dicapai. API key, deployment hosting, backup restore sungguhan, stress test concurrency dan pilot pegawai belum tersedia/dilakukan.

## Dataset acuan

`tests/Fixtures/Golden/manifest.json` memetakan 12 PDF kecamatan ke workbook pemeriksaan terdahulu dan koreksi atasan Ampana Kota. Workbook lama adalah rujukan calon label; tidak otomatis menjadi ground truth. Periksa setiap butir terhadap PDF/pedoman, pisahkan saran opsional dan temuan salah, lalu adjudikasi kasus ambigu.

## Format gold normalisasi

```json
{
 "publication_id":"UUID dari aplikasi",
 "pdf_sha256":"checksum PDF asli",
 "adjudicated":true,
 "adjudicator":"nama reviewer",
 "expected":[{"id":"gold-001","rule_code":"ISSN.COLON","physical_page":4,"severity":"wajib","observed":"kondisi spesifik pada PDF","quote":"kutipan bukti","expected":"format benar"}],
 "matches":[{"gold_id":"gold-001","finding_id":123}]
}
```

Pairs `matches` ditetapkan manusia setelah membandingkan substansi, bukan hanya karena rule/page sama. Satu temuan tidak boleh dipasangkan dua kali. Temuan yang tidak berpasangan dihitung sebagai false positive; label gold tidak berpasangan sebagai false negative. Pemeriksaan memakai data sebelum penyuntingan manual pertama agar koreksi pemeriksa tidak meningkatkan skor AI secara semu.

Jalankan `php artisan audit:evaluate PATH_GOLD.json` untuk tiap publikasi setelah model sungguhan selesai. Target rilis: cakupan 100%, bukti 100%, recall wajib ≥95%, false-positive wajib ≤10%, workbook valid. Skor null berarti data pembanding belum cukup, bukan 100%. Evaluasi seluruh 12 dokumen dahulu; jangan menyesuaikan label gold demi membuat skor model bagus.

## Catatan implementasi

- Tidak ada local OCR/Python; AI membaca gambar halaman. Poppler hanya menyiapkan gambar. Dibaca AI tidak berarti kebal salah; kasus tidak terbaca harus dicoba ulang atau diverifikasi.
- Pedoman dan prompt disalin ke storage privat saat upload. Aturan jarang berubah tetapi perubahan wajib menaikkan versi codebase; pengujian aturan deterministik juga diperbarui.
- Rule deterministik melengkapi hasil AI, bukan pengganti audit visual. Cross-check angka selalu bertanda verifikasi, tidak mengarang angka sumber.
- ChatGPT Plus/OAuth belum diaktifkan. Tidak menyimpan cookie, password atau unofficial token ChatGPT.
