# Kalibrasi pencocokan wajah absensi (D-188)

Absensi web mencocokkan wajah di **server** (`PencocokWajah`): kosinus antara sidik wajah saat absen dan **rata-rata**
sidik terdaftar, dibandingkan dengan `config('karyawan.AmbangKemiripanWajah')` (env `AMBANG_KEMIRIPAN_WAJAH`).
Sidik berasal dari model `faceres` pustaka Human di peramban (1.024 angka).

## Mengapa 0,70 (dulu 0,60)

Laporan pemilik produk (24/10/2026): wajah teman cocok dengan wajah yang didaftarkan. Diukur dengan data nyata:
**LFW (Labeled Faces in the Wild), 80 orang × 4 foto**, mendaftar dengan 3 foto lalu menguji foto ke-4 (80 uji sah,
6.320 uji orang lain). Model dijalankan di Chromium dengan konfigurasi yang sama dengan halaman absen.

| Aturan | FAR (orang lain lolos) | TAR (orang sendiri lolos) |
|---|---|---|
| Lama: kosinus ≥ 0,60, terbaik dari 3 foto | **1,35 %** (≈ 1 dari 74 orang asing) | 91 % |
| Terbaik dari 3 foto, ≥ 0,70 | 0,032 % (≈ 1 dari 3.120) | 60 % |
| **Baru: kosinus rata-rata ≥ 0,70** | **0,032 % (≈ 1 dari 3.120)** | **79 %** |
| Rata-rata ≥ 0,65 | 0,705 % (≈ 1 dari 142) | 90 % |
| Rata-rata ≥ 0,75 | ≈ 0 % | 54 % |

Catatan membaca angka:

- LFW adalah foto bebas (cahaya, sudut, usia bervariasi) sehingga TAR di absen sungguhan, yang frontal dan terang,
  lebih tinggi. FAR di antara rekan yang mirip (satu kelompok etnis, satu keluarga) lebih buruk dari rata-rata LFW.
- Model alternatif Human diuji pada data yang sama: `faceres-deep` lebih buruk (EER 9 % vs 5 %), sedangkan
  `mobilefacenet` dan `mobileface` menghasilkan sidik yang tidak membedakan wajah (tidak layak). `faceres` tetap dipakai.
- Model ringan ini **tidak menjamin** menolak orang yang sangat mirip. Pencegah titip absen yang lebih kuat adalah
  kombinasi GPS (radius outlet), jadwal kerja (D-44), dan peninjauan absen `PerluDicek` oleh pengelola.

## Menyetel di lapangan

1. Buka Karyawan › Absensi › Kalibrasi wajah: sebaran kemiripan absen yang **diterima** dan **ditolak** 30 hari terakhir.
2. Karyawan sendiri sering ditolak (diterima terendah mendekati ambang) → turunkan ambang 0,05, atau minta daftar ulang
   di tempat terang dengan wajah lurus.
3. Rekan yang tidak berhak bisa lolos → naikkan ambang 0,05 (mis. 0,75) lewat `AMBANG_KEMIRIPAN_WAJAH`.
4. Setelah mengganti model wajah atau metode hitung, semua karyawan **harus daftar ulang** (sidik berpanjang lain
   tidak dibandingkan).

## Mengulang pengukuran

Unduh `lfw-funneled.tgz` (figshare 5976015), ambil orang dengan ≥ 4 foto, jalankan Human (`human.detect` dengan
`face.description` aktif, `cacheSensitivity: 0`) pada tiap foto di peramban, simpan `face[0].embedding`, lalu hitung
kosinus rata-rata pendaftaran (3 foto) terhadap foto ke-4 untuk pasangan sah dan pasangan orang lain; pilih ambang dari
kurva FAR/TAR.
