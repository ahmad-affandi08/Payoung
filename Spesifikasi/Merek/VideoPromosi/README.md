# Video promosi Payoung (30 detik)

| Berkas | Isi | Sektor |
|---|---|---|
| `VideoPromosi30Detik.html` | Promosi umum (offline-first, stok & akuntansi, Aplikasi Owner) | semua |
| `PromoKelontong30Detik.html` | Aplikasi Kasir + back-office untuk toko kelontong | RTL-GEN |

Naskah `PromoKelontong30Detik.html` mengikuti halaman solusi resmi sektor itu di
`Aplikasi/Web/app/Domain/Situs/Layanan/SektorSitus.php` (entri `RTL-GEN`, slug
`solusi/toko-kelontong-minimarket`), jadi video dan halaman web mengatakan hal yang sama.
Alur ceritanya ada di bagian **Promo kelontong** di bawah.

Motion graphic HTML untuk video promosi awal: 1920×1080 (16:9), 30 detik, dengan musik latar dan efek suara.
Memakai logo dari `../Sumber/`, palet merek D-15, dan font Atkinson Hyperlegible (§17.5, disimpan lokal di `Huruf/`, lisensi SIL OFL).

Tagline resmi: **Smart Choice Your Business Partner**. Untuk animasi masuk, tagline yang tertanam di
`LogoHorizontal.png` ditutup lalu digambar kembali sebagai lapisan teks terpisah.

## Alur cerita

| Detik | Adegan | Pesan |
|---|---|---|
| 0–4,9 | Masalah | "Jualan lagi ramai… internet malah mati." Wi-Fi putus, stok selisih, rekap manual |
| 4,9–9,2 | Pengenalan | Bintang merek terbang ke logo, logo Payoung tersapu masuk, tagline muncul, lalu logo mengecil ke sudut |
| 9,2–15,6 | 01 Kasir offline-first | Tablet Payoung POS: luring, keranjang, bayar berhasil, lalu tersinkron tanpa dobel |
| 15,6–21,0 | 02 Stok & akuntansi otomatis | Penjualan → mutasi stok → jurnal seimbang (D = K) → laporan laba rugi |
| 21,0–26,3 | 03 Aplikasi Payoung Owner | Omzet 3 outlet, grafik, stok menipis, persetujuan void dari HP |
| 26,3–30 | Penutup | Logo + tagline, "Daftar gratis sekarang", Android · iPad & iPhone · Windows · Web |

## Suara

Semua suara disintesis di halaman dengan Web Audio (tanpa file audio), jadi bebas lisensi dan selalu sama:

- Musik latar: pad C–G–Am–F, 100 bpm, ketukan mulai di adegan fitur, akor penutup memudar di akhir.
- Adegan masalah: dengung rendah, detak jam, bunyi galat.
- Efek: whoosh untuk transisi, bunyi letup kartu, bip pemindai barang, klik tombol, "ka-ching" pembayaran,
  denting sinkron dan notifikasi, detak penghitung angka, riser + dentum sebelum penutup.

## Memutar

Buka `VideoPromosi30Detik.html` di peramban, lalu klik **Putar dengan suara**.
Spasi = putar/jeda, R = ulang, M = bisu, H = sembunyikan kontrol, panah kiri/kanan = geser 1 detik.

## Promo kelontong (`PromoKelontong30Detik.html`)

| Detik | Adegan | Pesan |
|---|---|---|
| 0–4,45 | Masalah | "Catatan bilang 48. Di rak cuma ada 41." Selisih tanpa jejak |
| 4,45–8,3 | Pengenalan | Logo + tiga poin resmi sektor: pindai tanpa klik tambahan, satuan dus/pak/pcs, saran restock |
| 8,3–14,9 | 01 Aplikasi Kasir | Tablet: tiga pindaian barcode masuk keranjang, lencana satuan PAK/DUS, total & Bayar |
| 14,9–20,95 | 02 Back-office | `/kelola/persediaan/kartu-stok`: masuk, keluar, saldo, dokumen asal, pencatat |
| 20,95–26,35 | 03 Back-office | `/kelola/laporan/stok?tab=restock` + draf PO otomatis tiap pagi 05.30 |
| 26,35–30 | Penutup | "Selisih stok berhenti jadi tebakan" + payoung.id |

Yang diperagakan ada sungguhan: pemindai langsung menambah baris keranjang, satuan jual per produk,
kartu stok (BR-05.1: jumlah positif di kolom Masuk, negatif **tanpa tanda** di kolom Keluar),
saran restock dari kecepatan jual (X6), dan draf PO otomatis dari stok di bawah minimum
(`DrafPoOtomatis`, D-23 D bagian 1).

Nama barang sengaja generik (bukan merek pihak ketiga) karena ini aset yang disiarkan.

## Merekam ke MP4

Rekaman dibuat bingkai demi bingkai, lalu audio diekspor dari halaman dan dinormalisasi ke −16 LUFS:

```bash
npm i -g playwright            # sekali, jika belum ada
pip install imageio-ffmpeg     # ffmpeg penuh (butuh libx264 + aac); lalu set FFMPEG ke path biner-nya
export FFMPEG=$(python3 -c "import imageio_ffmpeg; print(imageio_ffmpeg.get_ffmpeg_exe())")

# Promosi umum (bentuk lama tetap jalan)
NODE_PATH=$(npm root -g) node Spesifikasi/Merek/VideoPromosi/RekamVideo.mjs keluaran.mp4 30

# Berkas tertentu: [berkas.html] [keluaran.mp4] [fps]
NODE_PATH=$(npm root -g) node Spesifikasi/Merek/VideoPromosi/RekamVideo.mjs PromoKelontong30Detik.html
```

> ffmpeg bawaan Playwright (`/opt/pw-browsers/ffmpeg-*`) **tidak bisa dipakai**: build minimal itu
> hanya punya VP8, tanpa libx264 maupun AAC.

Hasil MP4 tidak disimpan di repo; buat ulang dengan perintah di atas.
