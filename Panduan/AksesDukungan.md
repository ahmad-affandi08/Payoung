# Panduan: Akses Dukungan (PGL-16, P-09, D-132)

Tim dukungan Payoung bisa membantu langsung di akun toko **hanya bila Owner mengizinkan**, dengan batas waktu dan cakupan yang Owner tentukan. Petugas tidak pernah memegang kata sandi, PIN, atau kredensial toko.

## Untuk Owner (Pengaturan › Akses dukungan)

1. Buka **Pengaturan › Akses dukungan** (khusus Owner).
2. Pilih lama berlaku (1, 24, atau 72 jam) dan cakupan:
   - **Baca saja**: petugas hanya melihat. Semua tombol simpan/ubah ditolak dengan pesan "Izin akses hanya baca."
   - **Baca & ubah**: petugas boleh membantu mengubah data (misalnya merapikan produk).
3. Biarkan kotak **Buka data pribadi pelanggan** kosong kecuali petugas memang perlu melihat nomor HP/email/alamat pelanggan. Selama kosong, data pribadi tersamar (••••) dan **cetak serta unduhan ditutup**.
4. Klik **Beri izin akses**, lalu beri tahu petugas lewat tiket bantuan. Hanya satu izin yang berlaku pada satu waktu.
5. **Cabut izin sekarang** kapan saja: petugas yang sedang masuk langsung keluar.
6. Riwayat izin, sesi petugas, dan **jejak** setiap sesi (halaman yang dibuka, mana yang mengubah, mana yang ditolak) ada di halaman yang sama. Isi data yang dilihat petugas tidak disimpan.

Yang **tidak pernah** bisa dilakukan petugas, apa pun cakupannya: melihat/mengubah kata sandi dan PIN, mengelola pengguna/peran/perangkat, langganan dan tagihan, token API/webhook, pengaturan pembayaran, ekspor data lengkap, dan mengubah izin akses dukungan itu sendiri.

## Untuk tim Payoung (konsol)

1. Buka **Tenant › (pilih tenant)**, panel **Akses dukungan**.
2. Bila Owner sudah memberi izin yang berlaku, klik **Masuk sebagai tenant** (peran Dukungan dan Super Admin). Back-office tenant terbuka di tab baru dengan bilah merah "Anda mengakses akun … sebagai Dukungan". Alamatnya sekali pakai dan hangus dalam 60 detik. Sesi paling lama 4 jam atau sampai izin habis.
3. Klik **Akhiri sesi** di bilah merah setelah selesai.
4. Setiap halaman yang dibuka dan setiap aksi tercatat atas nama Anda di log audit tenant dan log audit pengelola.

### Akses darurat (hanya Super Admin)

Untuk **insiden keamanan** atau **permintaan hukum** saat tidak ada izin dari Owner: pilih kategori, tulis alasan (minimal 20 karakter), durasi 1 sampai 4 jam, dan cakupan. Dibutuhkan kode 2FA yang baru. Owner langsung dikirimi email berisi alasannya. Data pribadi pelanggan tetap tersamar dan akses hanya bisa dipakai Super Admin yang membukanya. Super Admin boleh menutupnya lebih awal; izin yang diberi Owner hanya bisa dicabut Owner.

## Alat bantu dukungan (konsol)

Panel **Alat bantu dukungan** di tampilan tenant (izin `dukungan.alat.pakai`: Dukungan dan Super Admin) membantu tanpa masuk ke back-office. Alat hanya jalan **selama izin akses dukungan tenant berlaku**; alat yang mengubah data butuh cakupan **Baca & ubah**.

| Alat | Jenis | Catatan |
|---|---|---|
| Status perangkat & antrean kirim | baca | Versi, terakhir terhubung, jumlah item belum terkirim; ditandai bila tertunda > 2 jam |
| Cabut perangkat | ubah | Meminta 2FA baru. Setelah dicabut Owner membuat kode aktivasi baru bila perangkat dipakai lagi ("reset") |
| Minta log perangkat, baca log | ubah, baca | Aplikasi Kasir mengunggah log lokal (tersaring) saat terhubung; berlaku 7 hari, log disimpan 30 hari |
| Bangun ulang saldo stok | ubah | Meminta 2FA baru. Memperbaiki `SaldoStok`/sisa batch dari buku stok; tenant > 5.000 pasangan lewat `php artisan persediaan:bangun-ulang-saldo` |
| Bangun ulang ringkasan harian | ubah | Rentang paling lama 31 hari, tidak melewati hari ini |
| Kirim ulang pesan WhatsApp gagal | ubah | Nomor tersamar; membuat pesan baru, kuota WhatsApp tenant berlaku |
| Kirim ulang email verifikasi | ubah | Sekali per pengguna per 10 menit |

Bantu impor data: petugas dengan sesi **Baca & ubah** memakai halaman impor tenant yang biasa.

Setiap alat yang mengubah data dicatat lengkap dengan keadaan **sebelum dan sesudah** (BR-P09.3). Owner membacanya di **Pengaturan › Akses dukungan › Alat bantu yang dijalankan**; hasilnya tidak memuat data pribadi.

## Di server production

- Jalankan migrasi: `php artisan migrate --force` (migrasi `2026_11_29_000196_BuatTabelAksesDukungan` dan `2026_11_29_000197_BuatTabelHasilAlatDukungan`).
- Segarkan cache: `php artisan optimize:clear && php artisan optimize`. Hasil build frontend (`public/build`) sudah ikut di Git, jadi cukup `git pull`; `npm run build` hanya perlu bila server membangun sendiri.
- Penjadwal harus aktif (`schedule:run` tiap menit): perintah `dukungan:akhiri-kedaluwarsa` berjalan tiap 15 menit untuk mengisi akhir sesi yang waktunya habis (aksesnya sendiri sudah tertutup per permintaan).
- Edisi Lisensi (Payoung Mandiri) tidak memiliki fitur ini.

## Catatan untuk pengembang

- Penyamaran memakai **nama kunci** (`Domain/Dukungan/Layanan/PenyamarDataPribadi`). Bila membuat respons dengan kunci data pribadi pelanggan baru, tambahkan ke kelas itu; `CakupanPenyamarDataPribadiTes` akan gagal bila ada kunci berpola data pribadi yang belum tercakup.
- Halaman cetak/unduhan yang memang tidak berisi data pribadi dan perlu tersedia bagi petugas tanpa izin data pribadi harus didaftarkan di `PenyamarResponsDukungan::RUTE_GAMBAR_AMAN` (hanya gambar) atau dibuat sebagai respons Inertia/JSON.
- Alat bantu baru: bungkus lewat `PelaksanaAlatDukungan` (tambahkan kasus di `AlatDukungan`), pakai Aksi/Layanan yang sama dengan jalur biasa, dan isi `Sebelum`/`Sesudah` hanya dengan angka, pengenal, dan kode. Penjadwal harian `dukungan:bersihkan-log-perangkat` menghapus log perangkat > 30 hari.
