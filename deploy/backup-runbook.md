# Backup dan uji pemulihan

Backup harian terenkripsi: database MySQL, `storage/app/private`, `.env`/APP_KEY, kode release dan lockfile. Kredensial backup tidak berada dalam repositori. Simpan salinan di lokasi berbeda dengan akses admin terbatas. API key terenkripsi masih memerlukan APP_KEY asli saat restore.

Jangan menghapus PDF asli, template, bukti halaman atau Excel final secara otomatis tanpa kebijakan retensi BPS. Tidak ada pembersihan rekursif otomatis pada versi ini.

Rehearsal wajib sebelum produksi:

1. Restore pada database dan direktori staging yang terpisah, bukan menimpa produksi.
2. Matikan akses publik dan outbound AI sementara agar tidak menimbulkan biaya saat restore.
3. Cocokkan checksum PDF/template/Excel, jumlah halaman, hubungan owner/assignee, history dan versi ekspor.
4. Pastikan APP_KEY dapat mendekripsi konfigurasi AI tanpa menampilkan secret.
5. Aktifkan koneksi AI staging yang disetujui secara eksplisit. Putuskan worker saat suatu publikasi 180 halaman baru 179 selesai, hidupkan kembali, dan verifikasi halaman terakhir diproses tanpa menduplikasi temuan.
6. Uji pegawai lain tetap tidak bisa mengunduh file yang tidak ditugaskan.
7. Catat waktu restore, cakupan data, checksum, petugas dan hasil. Restore shared hosting/Redis sungguhan belum dilakukan dari lingkungan pengembangan ini.
