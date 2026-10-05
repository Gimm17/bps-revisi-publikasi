# Kontrak pembacaan halaman page-v1

Baca seluruh halaman, bukan hanya rangkumannya. Transkripsikan teks yang terbaca tanpa memperbaiki ejaan/nilai; jangan menebak teks kecil. Kembalikan JSON dengan struktur berikut:

```json
{
  "physical_page": 1,
  "printed_page": null,
  "page_type": "front_cover",
  "readability": "readable",
  "text": "teks lengkap yang benar-benar terbaca",
  "inventory": {
    "headings": [],
    "header": "",
    "footer": "",
    "chapter_title": "",
    "running_title_id": "",
    "running_title_en": "",
    "figures": [],
    "issn_barcode_present": null,
    "tables": []
  },
  "identity": {},
  "contents": [],
  "findings": []
}
```

- page_type salah satu front_cover, title, catalogue, team, preface, contents, table_list, figure_list, chapter, table, narrative, bibliography, blank, back_cover.
- readability readable hanya jika semua area relevan dapat dibaca. uncertain jika ada sel/teks kecil tidak jelas; unreadable jika sebagian besar tidak terbaca. Halaman kosong terkonfirmasi boleh readable dengan text kosong.
- printed_page adalah string nomor tercetak (romawi/Arab), atau null jika tidak ada. Jangan memakai nomor fisik sebagai tebakan nomor tercetak.
- identity hanya informasi publikasi ini yang benar-benar tercantum: title, year, volume, catalogue, issn, publisher, page_count. Jangan masukkan identitas publikasi yang dikutip pada daftar pustaka atau data tahun tabel sebagai tahun publikasi.
- inventory.tables setiap tabel berisi number, title, unit, year, headers, rows (teks sel lengkap), continued (boolean), bbox. Jika seluruh komponen benar-benar tersedia dan memakai unit sama, isi component_rows berupa objek nilai numerik per kolom dan total berupa objek numerik. Jangan menganggap subtotal sebagai komponen; jangan menjumlahkan rasio/persentase tanpa dasar.
- inventory.figures berisi number, title, type, source, visual_description. Logo dan barcode ikut inventaris.
- contents pada halaman daftar berisi type, number, title, target_page. Jangan menyalin nomor halaman yang tidak terbaca.
- Semua bbox memakai koordinat relatif [x1,y1,x2,y2] dari 0 sampai 1, berdasarkan gambar yang dikirim. Null bila lokasi tidak dapat ditentukan. Jangan buat koordinat fiktif.
- Setiap findings berisi rule_code, severity (wajib/saran/verifikasi), confidence (0–1), observed, expected, recommendation, quote, bbox. Wajib ada quote yang benar-benar ada pada text ATAU bbox area bukti. Kode harus dari registry.
- Temuan harus menyebut kondisi spesifik, saran perbaikan konkret, dan bukti. Jika tidak ada kesalahan, findings kosong; inventory tetap lengkap. Bila pedoman meminta verifikasi sumber, gunakan severity verifikasi dan jangan membuat angka pengganti.
- Pada halaman katalog ISSN memakai titik dua; bagian lain tanpa titik dua. Larangan itu khusus label ISSN, bukan semua tanda titik dua pada dokumen.
- Pada running title, parity mengikuti nomor halaman tercetak, bukan urutan fisik PDF. Halaman awal/romawi/kosong/pembatas dikecualikan sesuai pedoman.
- Evaluasi detail kecil: spasi di sekitar slash, en dash, kapitalisasi, italic Inggris, superskrip, bold total, nomor kolom, clip/overlap, footer/header, judul/satuan/waktu, dan konsistensi layout. Jangan mencatat koreksi pada elemen yang sudah benar.
