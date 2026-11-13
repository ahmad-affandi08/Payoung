# Rancangan WhatsApp tenant ala Majoo (usulan, menunggu persetujuan)

Dibuat 13 November 2026 atas permintaan pemilik produk: "ikuti perilaku Majoo, dari cara mendapatkan layanan sampai akhir, karena sekarang di Payoung terasa aneh".
Status: **RANCANGAN**, belum ada kode. Terkait D-33, D-49, D-108, D-111, D-179, F-16 (CRM-07), P-05.

## 1. Mengapa sekarang terasa aneh

Halaman Pengaturan › WhatsApp memaksa tenant memilih penyedia dan mengisi hal teknis: Phone number ID, versi Graph API,
token akses, dan sampai 14 nama templat Meta. Tenant biasa tidak tahu apa itu semua. Tidak ada nomor per outlet, tidak ada
pindai QR, tidak ada daftar jenis pesan yang bisa dinyalakan, dan biaya berupa kuota bulanan yang tidak bisa ditambah.

## 2. Cara kerja Majoo (sumber: panduan pengguna majoo.id, diperiksa 13/11/2026)

1. **Mendapatkan layanan:** fitur "Notifikasi Pelanggan via WhatsApp" dibeli sebagai add-on di Langganan.
2. **Nomor pengirim:** didaftarkan per outlet (outlet, fungsi, label, nomor, deskripsi), diverifikasi dengan **pindai QR**
   (Perangkat Terhubung WhatsApp), ada sakelar status ON/OFF, **maksimal 6 nomor per outlet**.
3. **Pesan otomatis ke pelanggan:** struk (otomatis atau manual oleh kasir), poin (bertambah, ditukar), deposit (beli, pakai,
   mau kedaluwarsa), reservasi (dibuat, diubah, pengingat). **Teks tidak bisa diubah**, hanya templat bawaan.
4. **Laporan otomatis ke karyawan:** tutup kasir, penjualan per jam, absensi.
5. **Broadcast pemasaran:** templat Marketing/Utility (header, isi berisi variabel, footer, tombol), penerima dari daftar
   pelanggan, kirim sekarang atau jadwal, Uji Broadcast, status Draft/Dalam Antrian/Selesai, statistik.
6. **Saldo WhatsApp:** top-up, lalu dialokasikan per outlet ke Kredit Broadcast, diverifikasi OTP.

## 3. Prinsip untuk Payoung

- **Tenant tidak pernah mengisi token, Phone number ID, atau nama templat.** Layanan dikelola Payoung di sisi platform.
- Urutannya selalu: aktifkan, sambungkan nomor (pindai QR), pilih pesan yang dinyalakan, isi saldo bila mau broadcast.
- Satu rumah: menu Pelanggan › **Pusat WhatsApp** (empat tab: Nomor pengirim, Pesan otomatis, Broadcast, Saldo).
  Pengaturan › WhatsApp lama diarahkan ke sana. Mode "pakai akun penyedia sendiri" tetap ada, terlipat di "Lanjutan".
- Teks pesan otomatis tetap bawaan (sama dengan Majoo). Yang bisa dipilih tenant: jenis pesan mana yang menyala dan lewat
  nomor mana.

## 4. Tahap pengerjaan

| Tahap | Isi | Menyentuh uang/pembukuan |
|---|---|---|
| 1 | **Aktivasi dan nomor pengirim per outlet**: kartu "Aktifkan WhatsApp", tabel nomor (outlet, label, fungsi, status sambungan, sakelar, maks 6 per outlet), tambah nomor, layar pindai QR dengan status langsung, uji kirim, peringatan bila terputus (Kotak Tindakan). Form penyedia lama pindah ke "Lanjutan". | Tidak |
| 2 | **Pesan otomatis ke pelanggan**: daftar jenis pesan dengan sakelar dan pratinjau teks bawaan, pilih nomor pengirim per outlet, riwayat pesan dan statusnya. Yang baru: poin dan deposit (struk, piutang, reservasi, pesanan online sudah ada). | Tidak |
| 3 | **Laporan otomatis ke karyawan**: tutup kasir, penjualan per jam, absensi, ke nomor penerima yang didaftarkan (maks 6 per outlet). | Tidak |
| 4 | **Saldo WhatsApp dan broadcast**: top-up lewat pembayaran, akun saldo di pembukuan (kewajiban), alokasi kredit per outlet dengan OTP, templat Marketing/Utility, jadwal, Uji Broadcast, statistik. | **Ya** (akun, jurnal, tagihan baru) |

Tahap 1 sampai 3 tidak menyentuh pembukuan. Tahap 4 butuh desain jurnal sendiri dan baru dimulai setelah tahap 1 sampai 3 stabil.

## 5. Keputusan bawaan (bisa diubah pemilik produk)

1. **Penyedia sambungan QR:** dibuat sebagai colokan (adaptor) seperti D-179, tanpa mengikat satu penyedia. Penyedia pertama
   dipilih setelah ada akun uji. Tanpa itu, pindai QR tidak bisa diuji di lingkungan ini.
2. **Akses layanan:** termasuk di paket Pro ke atas, add-on untuk Starter (memakai mekanisme add-on D-49 dan penegakan D-111).
3. **Kuota paket lama (D-108):** menjadi kuota gratis bulanan yang dipakai lebih dulu, sisanya dari saldo (setelah tahap 4).
4. **Harga per pesan dan minimal top-up:** diatur Super Admin dari konsol, tanpa angka di kode.
5. **Pesan yang berasal dari kampanye (CRM-07)** tetap memakai jam tenang 08.00 sampai 21.00 dan tautan berhenti berlangganan.

## 6. Yang belum diketahui

- Majoo tidak menyebut harga add-on, harga per pesan, atau cara kerja pindai QR di sisi teknis. Angka dan penyedia di atas
  adalah usulan Payoung, bukan salinan Majoo.
- Kemampuan penyedia WhatsApp tidak resmi untuk sesi QR per nomor belum diverifikasi di sini.
