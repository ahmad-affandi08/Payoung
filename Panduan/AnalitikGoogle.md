# Menghubungkan Google Analytics ke konsol (D-215)

Setelah ini, data Google Analytics 4 situs `payoung.id` tampil langsung di konsol: **Analitik › Situs (Google Analytics)**.
Konsol hanya **membaca** data (cakupan `analytics.readonly`); ia tidak bisa mengubah apa pun di Google Analytics.

Yang perlu disiapkan satu kali (±10 menit). Lakukan dengan akun Google yang sama dengan pemilik properti GA4.

## 1. Catat ID properti GA4

1. Buka Google Analytics → **Admin** (ikon roda gigi) → kolom Property → **Property details**.
2. Salin **Property ID**: angka saja, misal `412345678`.
   Bukan **Measurement ID** yang berawalan `G-` (itu untuk pemasangan tag di situs; konsol menolaknya dengan pesan).

## 2. Buat akun layanan di Google Cloud

1. Buka <https://console.cloud.google.com> dan pilih proyek yang ada (boleh proyek yang sama dengan Firebase), atau buat proyek baru.
2. **APIs & Services → Library** → cari **Google Analytics Data API** → **Enable**.
3. **IAM & Admin → Service Accounts → Create service account**. Nama bebas, misal `payoung-analitik`. Tidak perlu memberi peran Cloud apa pun.
4. Buka akun layanan itu → tab **Keys → Add key → Create new key → JSON**. Berkas JSON terunduh. Simpan dengan aman; isinya kunci rahasia.
5. Catat **email akun layanan** (berakhiran `.iam.gserviceaccount.com`).

## 3. Beri akun layanan akses baca ke properti GA4

1. Google Analytics → **Admin → Property access management** → tombol **+ → Add users**.
2. Tempel email akun layanan, beri peran **Viewer**, hilangkan centang "Notify new users by email", lalu **Add**.

## 4. Isi di konsol Payoung

1. Konsol → **Integrasi** → kartu **Google Analytics (laporan di konsol)**, lingkungan Produksi.
2. **ID properti GA4**: angka dari langkah 1.
3. **Akun layanan Google**: buka berkas JSON dengan editor teks, salin **seluruh isinya** termasuk kurung kurawal, tempel.
4. Simpan → **Uji koneksi**. Hasil yang diharapkan: "Terhubung ke properti GA4 … sebagai …". Lalu aktifkan.
5. Buka **Analitik › Situs (Google Analytics)**.

Hanya Super Admin, Analis, Keuangan, serta Mitra & Penjualan (izin `analitik.lihat`) yang bisa membuka halaman ini.

## Isi halaman

| Tab | Isi (sama dengan yang ada di Google Analytics) |
|---|---|
| Ringkasan | Pengguna aktif, pengguna baru, sesi, sesi terlibat, tingkat keterlibatan, tingkat pentalan, rata-rata durasi sesi, tampilan halaman, jumlah event, event kunci; masing-masing dibanding periode sebelumnya yang sama panjang; grafik dan tabel tren harian |
| Real-time | Pengguna aktif 30 dan 5 menit terakhir, per menit, per negara, halaman yang sedang dilihat, perangkat, event; menyegarkan sendiri tiap 30 detik |
| Akuisisi | Saluran, sumber / medium, kampanye |
| Halaman | Halaman teratas (dengan judul) dan halaman landing |
| Event | Semua event dengan jumlah, pengguna, event per pengguna, dan event kunci |
| Pengguna | Negara, kota, bahasa, pengunjung baru dan kembali |
| Teknologi | Kategori perangkat, sistem operasi, peramban, resolusi layar, platform |

Periode dipilih di atas tab (bawaan 28 hari terakhir sampai kemarin, maksimum 365 hari).

## Hal yang perlu diketahui

- **Data tidak persis detik ini.** Google menuntaskan penghitungan sampai ±24 jam, jadi angka hari ini bisa masih naik. Real-time adalah satu-satunya yang hampir seketika.
- **Hemat kuota.** Tiap laporan disimpan sementara (10 menit; real-time 20 detik) dan satu tab hanya mengambil laporannya saat dibuka. Tombol **Segarkan data** melewati simpanan itu.
- **Tidak ada data pribadi.** Google Analytics hanya mengembalikan angka agregat. Usia, jenis kelamin, dan minat tidak ditampilkan karena baru tersedia bila Google Signals aktif dan sering tersembunyi oleh ambang privasi Google.
- **Kuota Google** (properti standar): 200.000 token per hari dan 40.000 per jam. Pemakaian normal jauh di bawahnya. Bila habis, halaman menampilkan "Kuota Google Analytics sedang habis".
- **Kunci rotasi.** Bila kunci akun layanan dicabut atau diganti, tempel JSON baru di Integrasi dan uji lagi. Kunci tersimpan terenkripsi dan tidak pernah ditampilkan ulang.

## Bila gagal

| Pesan | Penyebab dan cara memperbaiki |
|---|---|
| "Itu ID pengukuran (Measurement ID)…" | Yang ditempel ID `G-…`. Pakai Property ID angka dari langkah 1. |
| "Google menolak akses ke properti GA4 … Viewer" | Langkah 3 belum dilakukan untuk email akun layanan itu, atau Google Analytics Data API belum di-Enable (langkah 2.2). Perubahan akses kadang butuh beberapa menit. |
| "Properti GA4 … tidak ditemukan" | Property ID salah ketik, atau akun layanan diberi akses di properti yang lain. |
| "Google menolak akun layanan (invalid_grant…)" | Kunci sudah dicabut, berkas terpotong saat disalin, atau jam server melenceng jauh. |
| "Kunci privat … tidak bisa dipakai menandatangani" | Berkas JSON tidak utuh. Salin ulang seluruh isinya. |
