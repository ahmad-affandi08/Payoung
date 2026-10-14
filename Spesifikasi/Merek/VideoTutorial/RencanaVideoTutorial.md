# Rencana Video Tutorial Payoung: "Cara Menggunakan", per Fitur

Seri video panduan pemakaian Payoung, satu video untuk satu fitur, dikelompokkan per modul seperti pusat bantuan.
Dipakai tim produk, dukungan (P-09), dan pemasaran. Pelengkap dari `../VideoPromosi/RencanaKonten.md` (video promosi 30 detik).

> Tagline di intro/penutup mengikuti keputusan merek yang berlaku (lihat catatan tagline di `RencanaKonten.md`).

---

## 1. Tujuan & Bedanya dengan Video Promosi

| | Video promosi (`RencanaKonten.md`) | **Video tutorial (dokumen ini)** |
|---|---|---|
| Pertanyaan penonton | "Kenapa harus Payoung?" | "Bagaimana caranya di Payoung?" |
| Penonton | Calon pengguna | Pengguna yang sudah daftar: owner, manajer, kasir, gudang, akuntan |
| Durasi | 30 detik | **60–180 detik** (satu fitur tuntas), plus potongan "Tips 30 detik" |
| Gaya | Emosional, cepat, satu pesan | Tenang, runtut, langkah bernomor, bisa diikuti sambil membuka aplikasi |
| Tempat tayang | TikTok, Reels, iklan | **Pusat Bantuan** (`Kelola/Bantuan`), tombol "?" di tiap halaman, playlist YouTube per modul, pesan WA onboarding |

**Target:** pengguna baru bisa menyelesaikan satu tugas hanya dengan menonton satu video, tanpa menghubungi dukungan.
Ukurannya: tiket dukungan untuk topik yang sudah punya video turun (§12).

---

## 2. Prinsip

### 2.1 Akurat terhadap aplikasi dan aturan bisnis

- **Layar 100% asli** dari build rilis (sama seperti video promosi). Tidak ada mockup, gambar ulang, atau layar AI.
- **Label di video sama persis dengan label di aplikasi.** Kalau tombolnya bertulisan "Buka Shift", VO dan teks menyebut "Buka Shift", bukan "Mulai Shift".
- Setiap tutorial menunjukkan **aturan bisnis (BR) yang dirasakan pengguna**, misalnya kas keluar di atas batas butuh PIN supervisor (BR-06.4). Pengguna tidak kaget saat bertemu aturan itu di lapangan.
- Setiap tutorial menunjukkan **minimal satu keadaan tidak mulus** (§17.6.6): pesan galat, kosong, offline, atau butuh persetujuan, beserta cara mengatasinya.
- Istilah mengikuti kamus §13.7.1: Pemasok (bukan Supplier), Pelanggan, Outlet, Shift, Kas Awal.
- Ditinjau pemilik flow (produk) sebelum tayang. Bila aturan di PRD berubah, video ditandai "perlu rekam ulang".

### 2.2 Tenang dan bisa diikuti

- **Satu langkah = satu layar = satu kalimat VO.** Jeda 0,5–1 detik setelah setiap klik/ketuk supaya penonton sempat meniru.
- **Kecepatan VO 120–140 kata/menit** (lebih lambat dari promosi). Ketik dan klik di kecepatan manusia normal, tidak dipercepat.
- Tidak ada potongan cepat, tirai merek, atau musik yang mendominasi. Transisi hanya potong biasa atau geser halus antar-bab.
- Data sensitif (email, nomor HP, NPWP) memakai data contoh atau diburamkan.

### 2.3 Tidak AI slop

Berlaku semua aturan "Dilarang" di `RencanaKonten.md` §2.2. Khusus tutorial:
- **Pengisi suara manusia**, bukan TTS, dengan satu suara tetap untuk semua seri supaya terasa satu keluarga.
- Tidak ada "presenter AI", avatar, atau karakter kartun.
- Tidak ada teks mengambang di luar sorotan: setiap teks overlay menunjuk elemen nyata di layar.

---

## 3. Struktur Baku Satu Tutorial

| Bagian | Durasi | Isi |
|---|---|---|
| **Pembuka** | 5–8 dtk | Judul "Cara …", modul, aplikasi (ikon Web/Kasir/Owner), **prasyarat** (izin/peran, paket, tutorial sebelumnya). Tanda bunyi Payoung versi pendek (0,8 dtk) |
| **Konteks** | 5–10 dtk | Kapan fitur ini dipakai, dalam satu kalimat ("Setiap awal jaga, kasir mencatat uang di laci.") |
| **Langkah 1…n** | 10–25 dtk per langkah | Chip "Langkah 2/5" menempel di pojok. Zoom ke area kerja, sorot tombol, titik ketuk/kursor, VO satu kalimat |
| **Aturan penting** | 10–20 dtk | 1–2 BR yang dialami pengguna, diperagakan (bukan hanya disebut) |
| **Kalau ada masalah** | 10–20 dtk | Satu keadaan galat/kosong/offline yang umum dan cara mengatasinya |
| **Cek berhasil** | 5–10 dtk | Tempat melihat hasilnya (daftar, laporan, status) |
| **Penutup** | 5 dtk | "Selanjutnya: [tutorial berikutnya]" + alamat Pusat Bantuan |

Bab (chapter) YouTube dibuat dari judul langkah, supaya penonton bisa melompat ke langkah yang dicari.

---

## 4. Tata Letak & Bahasa Gerak

**Master utama 16:9 (1920×1080)** untuk back-office dan Kasir tablet/desktop.
**Master 9:16 (1080×1920)** untuk Aplikasi Owner, mode Pelayan, mode Gudang (HP), dan potongan "Tips 30 detik".

| Elemen | Aturan |
|---|---|
| Area layar | ≥ 80% bingkai. Browser tanpa bookmark/ekstensi; zoom browser 100%; lebar 1440 px (desktop) atau 390 px (HP) |
| Bingkai perangkat | Kasir: tablet 10" landscape (vektor datar navy). Owner: HP. Web: jendela browser polos |
| Chip langkah | Pojok kiri atas: "Langkah 2/5 · Isi kas awal". Tetap tampil sepanjang langkah |
| Sorotan | Cincin indigo `Brand` 3 px + area lain diredupkan 35%. Satu sorotan pada satu waktu |
| Zoom | 1,0× → maks 2,0× ke area kerja; kembali 1,0× di akhir langkah supaya penonton tahu posisinya di layar |
| Kursor/ketuk | Web: kursor sistem asli + lingkaran klik halus. Tablet/HP: titik sentuh 60 px |
| Teks overlay | Maks 6 kata, menunjuk elemen nyata. Font Atkinson Hyperlegible |
| Subtitle | Selalu ada (tertanam + `.srt`), 2 baris maks, di bawah, tidak menutupi tombol yang sedang dijelaskan |
| Label paket | Lencana kecil "Paket Pro" di pembuka bila fitur tidak ada di semua paket |

---

## 5. Audio

| Unsur | Aturan tutorial |
|---|---|
| **VO** | Manusia, satu pengisi suara tetap. Bahasa Indonesia santai dan sopan dengan sapaan **"kamu"** di seluruh seri (sudah diputuskan); hindari "Anda", "lo/gue", dan kata gaul yang cepat basi. Direkam di ruang kedap, −16 LUFS setelah master |
| **Backsound** | Lagu dasar suasana **Tenang** (`RencanaKonten.md` §5.2), sangat pelan: −28 s.d. −30 LUFS di bawah VO. Dimatikan saat langkah yang butuh konsentrasi (isi form panjang) |
| **Efek suara** | Hanya bunyi UI asli dan halus: klik, ketuk, bip pindai, printer, notifikasi Owner, denting "berhasil" (sekali per video di "Cek berhasil"). Tanpa whoosh |
| **Tanda bunyi** | Versi pendek 0,8 dtk di pembuka saja |
| **Master** | −16 LUFS, −1,5 dBTP (YouTube & Pusat Bantuan). Potongan "Tips 30 detik": −14 LUFS |

---

## 6. Alur Produksi "Tutorial sebagai Kode"

Tutorial cepat usang setiap UI berubah. Supaya murah direkam ulang, setiap tutorial punya **skenario otomatis**:

1. **Data:** tenant etalase di staging (Kedai Rina, Toko Sumber Rejeki, dll. seperti `RencanaKonten.md` §6), direset dengan seeder sebelum setiap rekaman.
2. **Skenario:**
   - Web → skrip Playwright per tutorial (klik & ketik dengan jeda manusia), rekam per bingkai.
   - Kasir/Owner (Flutter) → `integration_test` per tutorial di perangkat nyata, direkam `adb screenrecord`/perekam iOS/Windows.
3. **VO per langkah** direkam terpisah (`L01.wav`, `L02.wav`, …); durasi langkah di video mengikuti panjang VO + jeda.
4. **Rakit** di templat motion (chip langkah, sorotan, zoom, subtitle) → master → QA (§11).
5. **Deteksi usang:** saat rilis aplikasi baru, semua skenario dijalankan ulang di CI staging. Skenario yang gagal (tombol berubah/hilang) menandai tutorial "perlu rekam ulang" sebelum pengguna menemukan video yang salah.

```
Spesifikasi/Merek/VideoTutorial/
  RencanaVideoTutorial.md       ← dokumen ini
  Templat/                      ← chip langkah, sorotan, pembuka & penutup tutorial
  Tutorial/T-KSR-03/
    Naskah.md                   ← naskah VO per langkah + BR yang ditunjukkan
    Skenario.mjs | skenario_test.dart
    Manifes.json                ← versi aplikasi, tanggal rekam, peninjau, status (Aktif / Perlu rekam ulang)
```

Rekaman mentah, VO, dan MP4 final disimpan di penyimpanan aset tim, **bukan di git**.

---

## 7. Katalog Tutorial per Modul

**Kolom "Paket"** mengikuti §21 dan bersifat indikatif: yang berlaku adalah katalog paket final P-04.
**Kolom "Tayang"**: **G1** = wajib ada sebelum GA (jalur onboarding), **G2** = saat GA (fitur Fase 2), **G3** = setelah fiturnya rilis (Fase 3).
Layar dengan nama `Kelola/...` ada di `Aplikasi/Web/resources/js/Halaman/`; `Layar...` ada di `Aplikasi/Kasir/lib/Tampilan/`. Layar yang belum dibangun disebut sesuai flow-nya.

### 7.1 Memulai (Web)

> **Sudah dibuat:** potongan pendek 30 detik T-MUL-01 ada di `CaraDaftar30Detik.html` (lihat `README.md`), untuk media sosial dan halaman harga. Versi penuh 90 detik di bawah belum dibuat.

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-MUL-01 | Cara daftar akun Payoung | `Autentikasi/Daftar`, `PersetujuanLegal` | Isi nama, email, WA, kata sandi, nama usaha → centang S&K → Daftar → cek email verifikasi | Trial tanpa kartu kredit, lalu turun ke Gratis (BR-00.3); tautan verifikasi berlaku 24 jam (BR-00.5); pesan umum bila email/WA sudah dipakai (BR-00.10) | 90 dtk | Semua | G1 |
| T-MUL-02 | Cara menyelesaikan Panduan Awal | `Kelola/PanduanAwal/*` (ProfilUsaha, Sektor, Pajak, Produk, MetodePembayaran, Perangkat) | Profil usaha → pilih sektor & template → konfirmasi pajak → produk awal → metode bayar → perangkat & tes cetak → checklist "Langkah Berikutnya" | Template bersifat menambah, tidak menghapus data (BR-01.1); usulan pajak mengikuti kota & status PKP | 180 dtk | Semua | G1 |
| T-MUL-03 | Cara masuk, lupa kata sandi, dan pilih usaha | `Autentikasi/Masuk`, `LupaKataSandi`, `AturUlangKataSandi`, `PilihTenant` | Masuk → (lupa) minta tautan → atur ulang → pilih usaha bila punya lebih dari satu | Tautan atur ulang berlaku 60 menit (BR-00.9); satu akun bisa di beberapa usaha (BR-00.1) | 75 dtk | Semua | G1 |
| T-MUL-04 | Cara mengaktifkan verifikasi dua langkah | `Autentikasi/KeamananAkun`, `VerifikasiDuaFaktor` | Buka Keamanan → pindai QR dengan aplikasi autentikator → masukkan kode → simpan 8 kode pemulihan | Kode pemulihan hanya tampil sekali (BR-00.8) | 90 dtk | Semua (wajib di Bisnis) | G1 |
| T-MUL-05 | Kenali tampilan back-office & cara memakai tabel | `Kelola/Beranda`, contoh `Kelola/Produk/Daftar` (`TabelData`) | Navigasi menu → cari → saring & chip saring → urut kolom (Shift+klik) → atur kolom → ekspor | Saring tersimpan di URL, bisa dibagikan; beda "belum ada data" dan "tidak ada hasil" (§17.4.3) | 120 dtk | Semua | G1 |

### 7.2 Organisasi, Pengguna & Perangkat (Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-ORG-01 | Cara menambah outlet | `Kelola/Outlet/Daftar`, `Detail` | Tambah outlet → nama, kode 3–5 huruf, alamat, zona waktu, template, jam buka → simpan | Kode outlet terkunci setelah ada transaksi (BR-02.2); batas outlet per paket (BR-02.1) | 90 dtk | Starter+ (1 outlet), Pro 3, Bisnis 10 | G1 |
| T-ORG-02 | Cara mengatur lokasi stok di outlet | `Kelola/Outlet/Detail` | Tambah lokasi "Dapur", "Bar", "Gudang Belakang" → pilih jenis | Setiap outlet minimal punya 1 lokasi stok jual (BR-02.4) | 75 dtk | Semua | G1 |
| T-ORG-03 | Cara mengundang pengguna & mengatur peran | `Kelola/Pengguna/Daftar`, `Kelola/Peran/Daftar`, `Undangan/Terima` | Atur peran & izin → undang via email → pilih outlet → pengguna menerima undangan | Pengguna hanya melihat outlet yang ditugaskan; batas pengguna per paket | 120 dtk | Semua | G1 |
| T-ORG-04 | Cara membuat PIN kasir | `Kelola/Pin` | Pilih staf → buat PIN 6 digit | PIN dipakai untuk masuk cepat & persetujuan supervisor | 60 dtk | Semua | G1 |
| T-ORG-05 | Cara menambah dan mengaktifkan perangkat kasir | `Kelola/Perangkat/Daftar` (`DialogAktivasiPerangkat`), `LayarAktivasi` | Tambah perangkat (Kasir) → tampil kode 8 karakter + QR → di tablet pindai QR → perangkat aktif | Kode berlaku 15 menit (F-02 langkah 5); batas perangkat per paket; perangkat yang dicabut ditolak, transaksi offline sebelumnya tetap diterima (BR-02.3) | 120 dtk | Semua | G1 |

### 7.3 Produk, Harga & Pajak (Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-PRD-01 | Cara menambah produk | `Kelola/Produk/Form` | Nama, kategori, satuan, SKU/barcode, harga, kelompok pajak → simpan | SKU & barcode unik per usaha (BR-03.1); galat bila barcode sudah dipakai | 120 dtk | Semua (Gratis 100 SKU) | G1 |
| T-PRD-02 | Cara mengatur kategori dan satuan | `Kelola/Kategori/Daftar`, `Kelola/Satuan/Daftar` | Tambah kategori → tambah satuan & konversi (dus = 24 pcs) | Konversi satuan dipakai di kasir & pembelian | 90 dtk | Semua | G1 |
| T-PRD-03 | Cara membuat varian dan pilihan (modifier) | `Kelola/Produk/Pilihan`, `Kelola/KelompokPilihan` | Buat kelompok pilihan "Gula" (Normal/Less/No) → pasang ke produk → atur harga tambahan | Pilihan wajib vs opsional; harga pilihan ikut ke keranjang | 120 dtk | Semua | G1 |
| T-PRD-04 | Cara membuat produk resep/paket & melihat HPP | `Kelola/Produk/Komponen` | Pilih produk → tambah bahan & jumlah → lihat HPP | HPP resep = Σ bahan / yield (BR-03.5); resep baru tidak mengubah transaksi lama (BR-03.4) | 150 dtk | Starter+ | G1 |
| T-PRD-05 | Cara impor produk dari Excel atau aplikasi lain | `Kelola/Produk/Impor` (`WizardImpor`: langkah, pemetaan, kemajuan) | Unduh templat → isi → unggah → petakan kolom → perbaiki baris galat → proses | Validasi per baris + laporan galat bisa diunduh (BR-03.6) | 150 dtk | Semua | G1 |
| T-PRD-06 | Cara membuat daftar harga dan harga bertingkat | `Kelola/DaftarHarga/Daftar`, `Detail` | Buat daftar harga → pilih outlet/channel/tier → isi harga → atur harga per jumlah | Urutan prioritas harga: manual → promo → daftar harga → bertingkat → dasar (F-03) | 150 dtk | Bisnis (price list) | G2 |
| T-PRD-07 | Cara mengatur pajak produk | `Kelola/KelompokPajak/Daftar` | Buat/pilih kelompok pajak → inklusif/eksklusif → pasang ke produk | Tarif diambil dari tarif resmi bertanggal berlaku, tidak diketik manual | 90 dtk | Semua | G1 |
| T-PRD-08 | Cara mengarsipkan produk & melihat riwayat harga | `Kelola/Produk/Daftar`, `Form` | Arsipkan produk → lihat riwayat perubahan harga | Produk bertransaksi tidak bisa dihapus, hanya diarsipkan (BR-03.2); riwayat harga tercatat (BR-03.3) | 60 dtk | Semua | G1 |

### 7.4 Aplikasi Kasir: Persiapan (Kasir)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-KSR-01 | Cara memasang dan mengaktifkan aplikasi kasir | `LayarAktivasi` | Unduh aplikasi → buka → pindai QR / ketik kode dari back-office → data outlet terunduh | Kode kedaluwarsa → buat ulang di back-office | 90 dtk | Semua | G1 |
| T-KSR-02 | Cara masuk dengan PIN, ganti kasir, dan mengunci layar | `LayarPilihKasir`, bilah atas `RuangKerja`, `LayarKunci` | Pilih nama → PIN → ketuk nama kasir untuk ganti → tombol Kunci | Masuk PIN bisa tanpa internet (F-06b); ganti kasir tanpa tutup shift | 75 dtk | Semua | G1 |
| T-KSR-03 | Cara membuka shift dan mengisi kas awal | `LayarBukaShift` | Masukkan kas awal → (opsional) hitung per pecahan → Buka Shift | Satu perangkat satu shift terbuka (BR-06.1); total pecahan harus sama dengan kas awal; bisa offline (BR-06.3) | 75 dtk | Semua | G1 |
| T-KSR-04 | Kenali Ruang Kerja Kasir | `RuangKerja` (bilah atas, rel navigasi, area kerja, bilah status) | Tur bagian layar → arti ikon koneksi, tertunda sinkron, printer, jam shift | Bilah status selalu terlihat; ketuk untuk detail (§17.2.7) | 90 dtk | Semua | G1 |
| T-KSR-05 | Cara mengatur tampilan kasir | `LayarPengaturan` | Ukuran tampilan Normal/Besar → posisi keranjang kiri/kanan → kunci otomatis | Diatur per perangkat | 60 dtk | Semua | G1 |
| T-KSR-06 | Cara menyambungkan dan mengetes printer struk | `LayarPengaturan` (printer) | Pilih jenis (Bluetooth/USB/LAN/bawaan) → cari → pilih → tes cetak | Printer terputus tampil di bilah status | 90 dtk | Semua | G1 |

### 7.5 Aplikasi Kasir: Berjualan (Kasir)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-JUL-01 | Cara melakukan transaksi pertama | `LayarJual`, layar Bayar (F-08) | Ketuk/cari/pindai produk → ubah jumlah → Bayar → tunai & kembalian → cetak struk | Tidak bisa jualan tanpa shift aktif (BR-07.4); barang sama menambah jumlah | 120 dtk | Semua | G1 |
| T-JUL-02 | Cara memilih varian, pilihan, dan catatan item | `LayarJual` | Ketuk produk → pilih "Less sugar" → tambah catatan → simpan ke keranjang | Pilihan wajib harus diisi sebelum masuk keranjang | 75 dtk | Semua | G1 |
| T-JUL-03 | Cara memberi diskon | `LayarJual` (diskon item & pesanan) | Pilih baris → diskon % / nominal → (di atas batas) PIN supervisor | Diskon di atas batas peran butuh PIN supervisor (BR-07.3) | 90 dtk | Semua | G1 |
| T-JUL-04 | Cara memilih atau mendaftarkan pelanggan di kasir | `LayarJual` (panel Pelanggan) | Cari no. HP → pilih / daftar baru → harga tier & poin aktif | No. HP sebagai kunci pelanggan (F-16) | 75 dtk | Starter+ | G1 |
| T-JUL-05 | Cara menyimpan pesanan dan melanjutkannya | `LayarJual`, Order tersimpan | Simpan pesanan → layani pelanggan lain → buka Order tersimpan → lanjut bayar | Keranjang tersimpan di perangkat walau aplikasi tertutup (§17.2.7) | 75 dtk | Semua | G1 |
| T-JUL-06 | Cara menerima QRIS, EDC, dan transfer | layar Bayar | QRIS statis: tampilkan QR → konfirmasi. EDC: pilih bank, isi no. approval. Transfer: konfirmasi manual | QRIS statis & EDC bisa offline (F-08); QRIS dinamis otomatis (G2, batas waktu 15 menit, "Cek Status") | 120 dtk | Semua | G1 (dinamis G2) |
| T-JUL-07 | Cara membayar dengan beberapa metode (split payment) | layar Bayar | Total → tunai Rp50.000 → sisa QRIS → selesai | Pembulatan hanya untuk bagian tunai (BR-08.6) | 75 dtk | Semua | G1 |
| T-JUL-08 | Cara mencetak ulang atau mengirim struk digital | Riwayat transaksi | Buka riwayat → pilih transaksi → cetak ulang / kirim WA / tampilkan QR struk | Struk WA memakai kuota paket (G2) | 60 dtk | Semua (WA: Pro+) | G1 |
| T-JUL-09 | Cara berjualan saat internet mati dan mengecek sinkron | `LayarJual`, bilah status, `LayarStatusSinkron` | Internet mati → tetap jualan → lihat jumlah tertunda → internet kembali → sinkron → cek "Perlu Tindakan" | Sinkron otomatis tiap 30 dtk, tanpa dobel (BR-07.6); item ditolak bisa dikirim ulang (F-06b) | 120 dtk | Semua | G1 |
| T-JUL-10 | Cara memakai kasir dengan keyboard dan pemindai di Windows | `LayarJual` (desktop) | Pindai tanpa klik kolom cari → pintasan keyboard → tekan `?` untuk daftar pintasan | Input tanpa fokus (§17.2.7) | 75 dtk | Semua | G1 |

### 7.6 Kas & Shift (Kasir + Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-SHF-01 | Cara mencatat kas masuk dan kas keluar | `LayarKas` | Kas Keluar → pilih kategori → nominal → foto bukti → simpan | Di atas batas (bawaan Rp200.000) butuh PIN supervisor (BR-06.4) | 90 dtk | Semua | G1 |
| T-SHF-02 | Cara menutup shift dan menghitung kas | `LayarShift` | Tutup Shift → hitung per pecahan → isi non-tunai → lihat selisih → alasan bila perlu → cetak laporan shift | Kas seharusnya disembunyikan (blind close); selisih di atas toleransi butuh alasan & persetujuan (F-11) | 120 dtk | Semua | G1 |
| T-SHF-03 | Cara menyetor kas ke brankas atau bank | `LayarKas` / tutup shift (Setoran) | Pilih Setoran → nominal → tujuan → simpan | Setoran memindahkan saldo kas laci ke brankas/bank (F-11 langkah 6) | 60 dtk | Semua | G1 |
| T-SHF-04 | Cara memantau shift di back-office | `Kelola/Kasir/Shift/Daftar`, `Detail` | Saring per outlet/tanggal → buka detail → kas non-penjualan → tautan ke jurnal | Shift yang ditandai "Perlu Tinjauan" dan alasannya | 90 dtk | Semua | G1 |
| T-SHF-05 | Cara mengatur kategori kas dan pengaturan kasir | `Kelola/Kasir/KategoriKas`, `Kelola/Kasir/Pengaturan` | Tambah kategori kas & akunnya → atur batas kas keluar (0 = selalu minta persetujuan) | Kategori tidak dihapus, hanya dinonaktifkan | 90 dtk | Semua | G1 |

### 7.7 Void, Retur & Refund (Kasir)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-PSC-01 | Cara membatalkan (void) transaksi | Riwayat transaksi → Void | Pilih transaksi → Void → alasan → PIN supervisor → uang dikembalikan | Hanya di shift yang sama; stok & jurnal dibalik; transaksi lunas tidak bisa diedit (BR-09.1) | 90 dtk | Semua | G1 |
| T-PSC-02 | Cara membatalkan item pada pesanan terbuka | Order tersimpan → void item | Pilih item terkirim ke dapur → void item → alasan | Item yang sudah dikirim terkunci; masuk laporan void (BR-07.5) | 75 dtk | Pro+ (F&B) | G2 |
| T-PSC-03 | Cara menerima retur dan tukar barang | Retur penjualan | Cari struk asli → pilih item → kondisi layak jual/rusak → refund atau tukar | Dalam batas hari retur; barang rusak masuk lokasi "Barang Rusak" (F-09) | 120 dtk | Semua | G1 |
| T-PSC-04 | Cara refund pembayaran non-tunai | Retur → refund | Pilih metode refund → manual transfer bila gateway tidak mendukung | BR-09.2 | 60 dtk | Semua | G2 |

### 7.8 F&B: Meja, Dapur & Self-Order (Kasir, KDS, Web Publik)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-FNB-01 | Cara mengatur denah meja | Back-office denah (mode table) | Tambah area → tambah meja → susun denah | — | 90 dtk | Pro+ | G2 |
| T-FNB-02 | Cara membuka tagihan meja, pindah, dan gabung meja | Kasir mode table | Ketuk meja → pesan → kirim ke dapur → pindah/gabung meja | Baris terkirim ke dapur terkunci (BR-07.5) | 120 dtk | Pro+ | G2 |
| T-FNB-03 | Cara memisah tagihan (split bill) | layar Bayar mode table | Per item / per orang (bagi rata) / per nominal → bayar masing-masing | Menghasilkan beberapa dokumen pembayaran (BR-08.2) | 120 dtk | Pro+ | G2 |
| T-FNB-04 | Cara memakai layar dapur (KDS) | Aplikasi KDS | Aktivasi perangkat KDS → tiket masuk → Dimasak → Siap → Disajikan | Warna umur tiket: hijau < 10 menit, lalu kuning, merah (F-10) | 90 dtk | Pro+ | G2 |
| T-FNB-05 | Cara menandai menu habis | Kasir / KDS | Tandai habis → menu hilang di self-order | Tercermin di self-order dalam 15–30 dtk (BR-17.2) | 45 dtk | Pro+ | G2 |
| T-FNB-06 | Cara mengambil pesanan dengan HP pelayan | Mode Pelayan (HP) | Masuk PIN → pilih meja → pesan → kirim | — | 90 dtk | Pro+ | G2 |
| T-FNB-07 | Cara memakai self-order QR meja | Back-office (cetak QR), web publik self-order, Kasir | Cetak QR per meja → pelanggan pesan → pesanan masuk kasir & KDS → konfirmasi bila "bayar di kasir" | Status `MenungguKonfirmasi` bila belum bayar (F-17); memakai shift virtual harian (BR-17.1) | 120 dtk | Add-on | G2 |

### 7.9 Jasa & Grosir (Kasir + Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-JSA-01 | Cara menerima cucian dan memperbarui status laundry | Kasir mode service (laundry) | Terima → timbang/jumlah → cetak tiket → status Dicuci…Siap → Diambil | WA otomatis saat status Siap (F-10) | 120 dtk | Pro+ | G2 |
| T-JSA-02 | Cara mencatat booking dan staf di salon/barbershop | Kasir mode service (booking) | Buat booking → pilih staf & layanan → selesai → bayar | Komisi staf per layanan (F-18) | 120 dtk | Pro+ | G2 |
| T-JSA-03 | Cara menjual paket sesi (membership) | Kasir / back-office Pelanggan | Jual paket 10× → pakai sesi per kunjungan → cek sisa | Pendapatan diakui per sesi (F-16) | 90 dtk | Pro+ | G2 |
| T-GRS-01 | Cara menjual tempo dengan batas kredit | Kasir mode wholesale / SO | Pilih pelanggan → metode Tempo → cek sisa limit → simpan | Ditolak bila melebihi limit atau ada piutang lewat jatuh tempo, kecuali persetujuan (BR-12.1) | 120 dtk | Bisnis | G2 |
| T-GRS-02 | Cara mencatat pelunasan piutang | Back-office Piutang (F-12) | Pilih pelanggan → centang beberapa faktur → nominal (boleh sebagian) → simpan → lihat umur piutang | Umur piutang 0–30/31–60/61–90/>90 | 90 dtk | Bisnis | G2 |

### 7.10 Persediaan (Web + Gudang)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-STK-01 | Cara mengisi stok awal | `Kelola/Persediaan/StokAwal/Form`, `Daftar`, `Detail` | Pilih lokasi → produk, jumlah, HPP awal → simpan & posting | Dokumen terposting tidak bisa diedit, koreksi lewat dokumen pembalik (aturan #8) | 90 dtk | Starter+ | G1 |
| T-STK-02 | Cara mengimpor stok awal dari Excel | `Kelola/Persediaan/StokAwal/Impor/Daftar`, `Detail` | Unggah → petakan → perbaiki galat → proses | Laporan galat per baris | 90 dtk | Starter+ | G1 |
| T-STK-03 | Cara mengecek saldo dan kartu stok | `Kelola/Persediaan/Saldo`, `KartuStok` | Saring lokasi/produk → buka kartu stok → telusuri dokumen asal tiap mutasi | Saldo = jumlah semua mutasi (ledger); stok minus ditandai | 90 dtk | Starter+ | G1 |
| T-STK-04 | Cara mengatur pengaturan persediaan | `Kelola/Persediaan/Pengaturan` | Izinkan/larang stok minus → lokasi produksi untuk bahan resep | BR-05.2, BR-05.4 | 75 dtk | Starter+ | G1 |
| T-STK-05 | Cara mentransfer stok antar outlet | Transfer (F-05b) | Buat transfer → kirim → outlet tujuan terima (boleh sebagian) → selisih beralasan | Status Draf → Dikirim → Diterima | 120 dtk | Bisnis | G1 |
| T-STK-06 | Cara melakukan stock opname | Opname (F-05c) | Mulai opname (snapshot) → hitung fisik per rak → tinjau selisih → setujui | Transaksi tetap jalan selama opname (BR-05.3); opsi hitung buta | 150 dtk | Pro+ | G1 |
| T-STK-07 | Cara mencatat barang rusak/hilang (penyesuaian) | Penyesuaian (F-05d) | Pilih produk → alasan → jumlah → kirim persetujuan | Wajib alasan; di atas nilai tertentu butuh persetujuan | 75 dtk | Starter+ | G1 |
| T-STK-08 | Cara mencatat produksi/rakitan | Produksi (F-05e) | Buat order produksi → bahan terpakai → hasil jadi | HPP hasil = HPP bahan + overhead | 90 dtk | Pro+ | G2 |
| T-STK-09 | Cara mengelola batch dan tanggal kedaluwarsa | GRN + penjualan (F-05g) | Isi batch & ED saat terima → jual otomatis FEFO → notifikasi H-30/H-7 | FEFO | 90 dtk | Pro+ | G2 |
| T-STK-10 | Cara memakai mode Gudang di HP | Aplikasi mode Gudang | Aktivasi perangkat Gudang → pindai terima barang/opname/transfer | Bisa offline | 120 dtk | Pro+ | G2 |

### 7.11 Pemasok & Pembelian (Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-BLI-01 | Cara menambah pemasok | Pemasok (F-04) | Nama, kontak, termin bawaan → simpan | — | 60 dtk | Starter+ | G1 |
| T-BLI-02 | Cara membuat PO dan meminta persetujuan | PO (F-04) | Pemasok → lokasi tujuan → item × jumlah × harga → ongkir/PPN → termin → kirim PDF/WA | PO di atas batas butuh persetujuan Owner (F-04 langkah 3) | 150 dtk | Pro+ | G1 |
| T-BLI-03 | Cara menerima barang (GRN) | GRN (F-04) | Pilih PO → isi jumlah diterima (boleh sebagian) → batch/ED → foto surat jalan → posting | Tidak boleh melebihi PO kecuali toleransi (BR-04.1); stok & HPP rata-rata berubah saat diposting (BR-04.2) | 120 dtk | Starter+ | G1 |
| T-BLI-04 | Cara mencatat faktur pembelian | Faktur pembelian (F-04) | Cocokkan PO–GRN–faktur → selisih harga → simpan | Selisih harga menyesuaikan HPP (BR-04.4) | 120 dtk | Pro+ | G1 |
| T-BLI-05 | Cara membayar hutang ke pemasok | Hutang (F-04, F-12) | Daftar jatuh tempo → pilih faktur → bayar sebagian/sekaligus → akun kas/bank | — | 90 dtk | Starter+ | G1 |
| T-BLI-06 | Cara meretur barang ke pemasok | Retur pembelian (F-04) | Pilih faktur/GRN → item & alasan → stok & hutang berkurang | — | 75 dtk | Pro+ | G1 |

### 7.12 Pelanggan, Loyalti & Promo (Web + Kasir)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-PLG-01 | Cara mengelola data pelanggan dan tier | Pelanggan (F-16) | Tambah/impor pelanggan → tier → level harga → limit kredit | No. HP sebagai kunci | 90 dtk | Starter+ | G1 |
| T-PLG-02 | Cara mengatur program poin | Loyalti (F-16) | Rp X = 1 poin → pengali tier → masa berlaku poin → uji di kasir | Poin kedaluwarsa FIFO | 120 dtk | Pro+ | G2 |
| T-PLG-03 | Cara membuat promo | Promo engine (F-16) | Periode & jam → outlet & channel → kondisi (beli 2 Kopi) → aksi (jadi Rp30.000) → batas → prioritas | Promo dihitung otomatis di kasir, bisa offline; yang paling menguntungkan pelanggan dipilih (F-16) | 180 dtk | Pro+ | G2 |
| T-PLG-04 | Cara membuat voucher | Voucher (F-16) | Kode tunggal/massal → sekali/berulang → masa berlaku → tukar di kasir | — | 90 dtk | Pro+ | G2 |
| T-PLG-05 | Cara membaca laporan efektivitas promo | Laporan promo (F-16) | Pilih promo → jumlah pakai, nilai diskon, kenaikan penjualan | — | 60 dtk | Pro+ | G2 |

### 7.13 Akuntansi & Keuangan (Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-AKT-01 | Cara melihat jurnal otomatis dan dokumen asalnya | `Kelola/Akuntansi/Jurnal/Daftar`, `Detail` | Saring tanggal/sumber → buka jurnal → klik dokumen asal (penjualan/shift) | Σ Debit = Σ Kredit selalu; jurnal tidak diedit, dikoreksi lewat pembalik | 90 dtk | Starter+ (penuh: Pro+) | G1 |
| T-AKT-02 | Cara membaca bagan akun (COA) dan aturan posting | Bagan akun & aturan posting (F-13) | Lihat COA dari template sektor → pemetaan metode bayar ke akun | QRIS masuk "Piutang Pencairan QRIS" sampai dana cair (BR-08.3) | 120 dtk | Pro+ | G2 |
| T-AKT-03 | Cara mencatat biaya operasional | Biaya (F-13) | Kategori beban → nominal → akun kas/bank → lampiran | — | 75 dtk | Starter+ | G1 |
| T-AKT-04 | Cara mentransfer antar akun kas dan bank | Kas & Bank (F-13) | Dari akun → ke akun → nominal → simpan | — | 60 dtk | Pro+ | G2 |
| T-AKT-05 | Cara membuat jurnal manual | Jurnal umum (F-13) | Tambah baris debit/kredit → harus seimbang → simpan | Hanya peran Akuntan/Owner | 90 dtk | Pro+ | G2 |
| T-AKT-06 | Cara tutup harian dan tutup bulan | Tutup buku (F-15) | Tutup harian: pastikan semua shift tertutup & sinkron → Tutup bulan: kunci periode | Transaksi di periode terkunci ditolak (`PeriodeTerkunci`) | 120 dtk | Pro+ | G2 |

### 7.14 Laporan (Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-LAP-01 | Cara membaca dashboard beranda | `Kelola/Beranda` | Omzet vs kemarin → laba kotor → produk terlaris → stok kritis → performa outlet | — | 90 dtk | Semua | G1 |
| T-LAP-02 | Cara melihat laporan penjualan | Laporan penjualan (F-14) | Pilih periode (preset) → saring outlet/kasir/kategori/metode → ekspor | Ekspor besar diproses dan diunduh di Pusat Unduhan | 120 dtk | Semua (lengkap: Starter+) | G1 |
| T-LAP-03 | Cara membaca laporan laba rugi | Laporan L/R (F-14) | Periode → pendapatan, HPP, laba kotor, biaya, laba bersih → bandingkan periode | — | 90 dtk | Starter+ | G1 |
| T-LAP-04 | Cara membaca laporan persediaan | Laporan persediaan (F-05/F-14) | Nilai persediaan per lokasi → mutasi per periode | — | 90 dtk | Starter+ | G1 |
| T-LAP-05 | Cara memakai laporan anti-fraud | Laporan anti-fraud (BR-09.3) | Void/retur/diskon per kasir, jam, nominal → pola mencurigakan | Void segera setelah bayar tunai ditandai | 90 dtk | Bisnis | G2 |
| T-LAP-06 | Cara mengekspor laporan dan memakai Pusat Unduhan | Pusat Unduhan (F-14) | Ekspor → tunggu proses → unduh | — | 60 dtk | Semua | G1 |

### 7.15 Karyawan (Kasir + Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-KRY-01 | Cara membuat jadwal shift kerja | Jadwal (F-18) | Minggu → outlet → seret staf ke jadwal | — | 90 dtk | Pro+ | G2 |
| T-KRY-02 | Cara absen dengan selfie atau dari HP | Kasir (absensi) / aplikasi HP | Clock-in PIN + selfie → clock-out; dari HP dengan lokasi | Deteksi lokasi palsu di Android (F-18) | 75 dtk | Pro+ | G2 |
| T-KRY-03 | Cara mengatur komisi karyawan | Komisi (F-18) | Aturan per produk/layanan (% atau nominal) → staf di baris transaksi → rekap | Komisi bisa dibagi ke beberapa staf | 90 dtk | Pro+ | G2 |
| T-KRY-04 | Cara mencatat kasbon dan rekap gaji | Kasbon & rekap gaji (F-18) | Catat kasbon → rekap gaji: pokok + komisi + lembur − potongan → ekspor | Kasbon dipotong otomatis | 120 dtk | Pro+ | G2 |

### 7.16 Aplikasi Owner (HP, 9:16)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-OWN-01 | Cara memasang dan masuk Aplikasi Owner | Owner: masuk | Unduh → masuk akun → izinkan notifikasi → aktifkan biometrik | 2FA berlaku bila aktif | 75 dtk | Semua | G2 |
| T-OWN-02 | Cara membaca beranda Owner | Owner tab Beranda | Pilih Semua Outlet / satu outlet → omzet vs kemarin → grafik per jam → "Butuh tindakan" | — | 75 dtk | Semua | G2 |
| T-OWN-03 | Cara menyetujui atau menolak permintaan | Owner tab Persetujuan | Buka notifikasi → lihat detail & alasan → Setujui/Tolak + alasan → biometrik | Permintaan void, diskon, refund, kas keluar, PO, penyesuaian stok (§17.3.2) | 75 dtk | Bisnis (jarak jauh) | G2 |
| T-OWN-04 | Cara mengatur notifikasi | Owner tab Notifikasi | Pilih jenis (selisih kas, stok kritis, perangkat offline…) per outlet | — | 60 dtk | Semua | G2 |
| T-OWN-05 | Cara cek stok dan ubah harga dari HP | Owner tab Lainnya | Cari produk → lihat stok → ubah harga / tandai habis | Perubahan harga tercatat di riwayat (BR-03.3) | 75 dtk | Semua | G2 |
| T-OWN-06 | Cara memantau status perangkat kasir | Owner tab Lainnya | Daftar perangkat → online/offline, tertunda sinkron, versi aplikasi | — | 45 dtk | Semua | G2 |

### 7.17 Langganan, Keamanan & Bantuan (Web)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-LGN-01 | Cara melihat paket, naik paket, dan membayar tagihan | `Kelola/Langganan/Indeks`, `Tagihan` | Lihat paket & batas → pilih paket → bayar tagihan / unggah bukti transfer | Naik paket di tengah periode diprorata; turun paket berlaku periode berikutnya (F-19) | 120 dtk | Semua | G1 |
| T-LGN-02 | Cara memeriksa log audit | `Kelola/LogAudit/Daftar` | Saring pengguna/jenis → lihat nilai lama & baru, perangkat, IP | Tidak bisa dihapus (X14) | 75 dtk | Semua | G1 |
| T-LGN-03 | Cara menghubungi dukungan lewat tiket | `Kelola/Bantuan/Daftar`, `Buat`, `Tiket` | Buat tiket → kategori → lampiran → balas percakapan | — | 60 dtk | Semua | G1 |

### 7.18 Integrasi (Web, Fase 3)

| ID | Judul | Layar asli | Langkah inti | Aturan/keadaan yang ditunjukkan | Durasi | Paket | Tayang |
|---|---|---|---|---|---|---|---|
| T-INT-01 | Cara membuat token API dan webhook | Integrasi (F-20) | Buat token → atur izin → daftarkan URL webhook → uji event | Token hanya tampil sekali | 120 dtk | Bisnis | G3 |
| T-INT-02 | Cara mengekspor data e-Faktur/Coretax | Ekspor pajak (F-20, §12) | Periode → ekspor → unggah ke sistem pajak | — | 90 dtk | Pro+ | G3 |

**Jumlah:** 103 tutorial. **G1 (sebelum GA): 64**, G2 (saat GA): 37, G3 (setelah Fase 3): 2.

---

## 8. Contoh Naskah Lengkap

Tiga contoh berikut menjadi acuan gaya untuk semua naskah.

### 8.1 T-KSR-03 · Cara membuka shift dan mengisi kas awal (±75 dtk)

**Prasyarat:** perangkat sudah aktif (T-KSR-01), kasir punya PIN (T-ORG-04). **Data:** Kedai Rina, Outlet Solo, perangkat K01, kasir Sari.

| Waktu | Bagian | Visual (layar asli) | VO | Suara |
|---|---|---|---|---|
| 0:00–0:06 | Pembuka | Judul "Cara membuka shift dan mengisi kas awal", ikon Kasir, "Prasyarat: perangkat aktif, PIN kasir" | "Cara membuka shift dan mengisi kas awal." | Tanda bunyi pendek |
| 0:06–0:13 | Konteks | Tablet di meja kasir, `LayarPilihKasir` | "Setiap mulai jaga, kasir mencatat uang yang ada di laci. Ini disebut kas awal." | Backsound pelan |
| 0:13–0:24 | Langkah 1/3 · Masuk | Ketuk "Sari" → papan PIN, titik PIN terisi | "Ketuk namamu, lalu masukkan PIN enam digit." | Ketuk ×6 |
| 0:24–0:40 | Langkah 2/3 · Isi kas awal | `LayarBukaShift`, zoom ke kolom kas awal, ketik 500.000 | "Masukkan jumlah uang di laci. Contohnya lima ratus ribu rupiah." | Ketuk angka |
| 0:40–0:52 | Langkah 2/3 (lanjutan) | Ketuk "Hitung per pecahan", isi 100rb ×3, 50rb ×4 | "Kalau ingin lebih teliti, hitung per pecahan. Jumlahnya harus sama dengan kas awal." | Ketuk |
| 0:52–0:58 | Langkah 3/3 · Buka | Sorot tombol "Buka Shift" → ketuk | "Ketuk Buka Shift." | Ketuk |
| 0:58–1:06 | Kalau ada masalah | Contoh total pecahan Rp450.000 ≠ Rp500.000 → pesan galat asli | "Kalau jumlah pecahan tidak sama, aplikasi akan memberi tahu. Periksa lagi hitungannya." | — |
| 1:06–1:12 | Aturan penting | Wi-Fi mati di bilah status, shift tetap terbuka | "Shift tetap bisa dibuka walau internet mati. Datanya dikirim otomatis nanti." | — |
| 1:12–1:17 | Cek berhasil | `LayarJual` tampil, bilah status "Shift dibuka 07.02" | "Shift sudah terbuka. Kamu siap berjualan." | Denting berhasil |
| 1:17–1:22 | Penutup | "Selanjutnya: Cara melakukan transaksi pertama" | "Selanjutnya, pelajari cara melakukan transaksi pertama." | — |

### 8.2 T-SHF-01 · Cara mencatat kas masuk dan kas keluar (±90 dtk)

| Waktu | Bagian | Visual | VO |
|---|---|---|---|
| 0:00–0:06 | Pembuka | Judul, ikon Kasir, "Prasyarat: shift terbuka" | "Cara mencatat kas masuk dan kas keluar." |
| 0:06–0:13 | Konteks | B-roll kasir memberi uang ke kurir es batu | "Uang yang keluar-masuk laci selain penjualan, misalnya beli es batu, harus dicatat." |
| 0:13–0:28 | Langkah 1/3 | Rel navigasi → Kas → Kas Keluar | "Buka menu Kas, lalu pilih Kas Keluar." |
| 0:28–0:45 | Langkah 2/3 | Pilih kategori "Bahan Habis Pakai", nominal Rp35.000, foto nota | "Pilih kategori, isi nominal, lalu foto notanya sebagai bukti." |
| 0:45–0:52 | Langkah 3/3 | Simpan | "Ketuk Simpan." |
| 0:52–1:15 | Aturan penting | Kas Keluar Rp350.000 → dialog PIN supervisor → Dimas memasukkan PIN | "Kas keluar di atas dua ratus ribu rupiah perlu PIN supervisor. Batas ini bisa diubah pemilik di pengaturan kasir." |
| 1:15–1:22 | Cek berhasil | Daftar kas shift menampilkan dua catatan | "Semua catatan kas tampil di sini dan ikut dihitung saat tutup shift." |
| 1:22–1:28 | Penutup | "Selanjutnya: Cara menutup shift" | "Selanjutnya, pelajari cara menutup shift." |

### 8.3 T-PRD-05 · Cara impor produk dari Excel (±150 dtk)

| Waktu | Bagian | Visual | VO |
|---|---|---|---|
| 0:00–0:07 | Pembuka | Judul, ikon Web, "Prasyarat: izin kelola produk" | "Cara impor produk dari Excel." |
| 0:07–0:15 | Konteks | File Excel berisi 1.248 produk | "Punya daftar produk di Excel? Tidak perlu mengetik ulang satu per satu." |
| 0:15–0:30 | Langkah 1/5 | `Kelola/Produk/Impor` → Unduh templat | "Buka Produk, pilih Impor, lalu unduh templatnya." |
| 0:30–0:50 | Langkah 2/5 | Excel: salin kolom ke templat | "Salin datamu ke kolom yang sesuai. Nama dan harga wajib diisi." |
| 0:50–1:05 | Langkah 3/5 | Unggah file | "Unggah file yang sudah diisi." |
| 1:05–1:25 | Langkah 4/5 | Pemetaan kolom: "Harga Jual" → Harga | "Pastikan setiap kolom terhubung ke data yang benar." |
| 1:25–1:55 | Kalau ada masalah | Hasil validasi: 3 baris galat (barcode ganda), unduh laporan galat | "Baris yang bermasalah ditandai beserta alasannya. Unduh laporannya, perbaiki, lalu unggah lagi." |
| 1:55–2:10 | Langkah 5/5 | Proses → bilah kemajuan | "Ketuk Proses. Impor besar berjalan di latar, jadi kamu bisa lanjut bekerja." |
| 2:10–2:20 | Cek berhasil | `Kelola/Produk/Daftar` berisi produk baru, cari salah satunya | "Produk sudah masuk dan siap dijual di kasir." |
| 2:20–2:28 | Penutup | "Selanjutnya: Cara mengimpor stok awal" | "Selanjutnya, isi stok awalnya." |

---

## 9. Jalur Belajar (Playlist)

Tutorial disusun menjadi jalur sesuai peran, supaya pengguna tidak bingung harus mulai dari mana.

| Jalur | Untuk | Urutan |
|---|---|---|
| **Mulai dalam 1 hari** | Owner baru | T-MUL-01 → T-MUL-02 → T-ORG-04 → T-ORG-05 → T-KSR-01 → T-KSR-03 → T-JUL-01 → T-SHF-02 → T-LAP-01 |
| **Kasir hari pertama** | Kasir | T-KSR-02 → T-KSR-04 → T-KSR-03 → T-JUL-01 → T-JUL-02 → T-JUL-06 → T-JUL-07 → T-SHF-01 → T-JUL-09 → T-SHF-02 |
| **Supervisor/manajer** | Manajer outlet | T-JUL-03 → T-PSC-01 → T-PSC-03 → T-SHF-04 → T-STK-06 → T-STK-07 → T-BLI-03 |
| **Stok & pembelian** | Gudang, manajer | T-STK-01 → T-STK-03 → T-BLI-01 → T-BLI-02 → T-BLI-03 → T-BLI-04 → T-BLI-05 → T-STK-05 |
| **Keuangan** | Owner, akuntan | T-AKT-01 → T-AKT-03 → T-LAP-03 → T-LAP-04 → T-AKT-06 → T-LGN-02 |
| **F&B** | Kafe & resto | T-PRD-03 → T-PRD-04 → T-FNB-01 → T-FNB-02 → T-FNB-04 → T-FNB-03 → T-FNB-07 |
| **Pemilik di mana saja** | Owner (HP) | T-OWN-01 → T-OWN-02 → T-OWN-03 → T-OWN-04 → T-OWN-05 |

---

## 10. Distribusi di Dalam Aplikasi

| Tempat | Cara |
|---|---|
| **Tombol "?"** di setiap halaman back-office | Membuka panel berisi tutorial untuk halaman itu (peta halaman → ID tutorial disimpan di satu berkas konfigurasi) |
| **Checklist "Langkah Berikutnya"** di dashboard (F-01 langkah 7) | Setiap butir checklist punya tautan video |
| **Keadaan kosong** (`KeadaanKosong`) | Mis. daftar produk kosong → "Tonton: Cara menambah produk" |
| **Aplikasi Kasir → Pengaturan → Bantuan** | Jalur "Kasir hari pertama", bisa diputar di tablet |
| **Pusat Bantuan** (`Kelola/Bantuan`) | Artikel teks + video + bab, bisa dicari |
| **WA onboarding** | Hari 0: jalur "Mulai dalam 1 hari"; hari 3: "Kasir hari pertama"; hari 7: "Stok & pembelian" |
| **YouTube** | Satu playlist per modul (§7.1–7.18) + satu playlist per jalur (§9) |

Pemasangan tombol "?" dan tautan di aplikasi adalah perubahan produk tersendiri: diusulkan lewat flow terkait, tidak ikut dikerjakan dari dokumen ini.

---

## 11. Checklist QA per Tutorial

- [ ] Judul diawali "Cara …" dan sesuai satu tugas
- [ ] Prasyarat (izin, paket, tutorial sebelumnya) disebut di pembuka
- [ ] Semua layar dari build rilis; versi tercatat di `Manifes.json`
- [ ] Label tombol di VO/teks sama persis dengan aplikasi
- [ ] Sapaan memakai "kamu" (tidak ada "Anda")
- [ ] BR yang dirasakan pengguna diperagakan, dan sesuai PRD terbaru
- [ ] Ada satu keadaan tidak mulus dan cara mengatasinya
- [ ] Jeda setelah setiap aksi; VO 120–140 kata/menit; chip langkah selalu tampil
- [ ] Subtitle tertanam + `.srt`; data sensitif diburamkan
- [ ] Audio: backsound −28 s.d. −30 LUFS di bawah VO; master −16 LUFS
- [ ] Bab YouTube dan tautan "Selanjutnya" benar
- [ ] Ditinjau pemilik flow dan satu orang yang belum pernah memakai fitur itu (uji: bisa mengikuti tanpa bantuan?)

---

## 12. Jadwal & Ukuran Keberhasilan

| Waktu | Kegiatan |
|---|---|
| T−8 s.d. T−6 minggu | Templat tutorial, pengisi suara tetap, skenario otomatis modul Memulai & Kasir |
| T−6 s.d. T−1 | Produksi G1 (64 video, ±13 per minggu dengan skenario otomatis) |
| T (GA) | G1 lengkap di Pusat Bantuan; jalur "Mulai dalam 1 hari" & "Kasir hari pertama" di WA onboarding |
| T+1 s.d. T+6 | Produksi G2 mengikuti urutan rilis fitur Fase 2 |
| Setiap rilis | Jalankan ulang skenario; tutorial yang gagal ditandai "perlu rekam ulang" dan diperbarui ≤ 2 minggu |

| Metrik | Target | Keterangan |
|---|---|---|
| Tiket dukungan per topik bertutorial | Turun ≥ 30% dalam 2 bulan | Kategori tiket P-09 dipetakan ke ID tutorial |
| Onboarding selesai (checklist F-01) | Naik dibanding sebelum video | Dari data aktivasi tenant |
| Rata-rata persentase ditonton | ≥ 60% | Di bawah itu: naskah terlalu panjang atau langkah kurang jelas |
| Pencarian Pusat Bantuan tanpa hasil | Ditinjau tiap 2 minggu | Bahan tutorial baru |

---

## 13. Keputusan

**Sudah diputuskan**

- Sapaan di VO, teks layar, dan subtitle: **"kamu"**, konsisten di seluruh seri tutorial dan video promosi.

**Perlu diputuskan**

1. Pengisi suara tetap (satu orang) dan anggarannya.
2. Tempat hosting video di Pusat Bantuan: YouTube (tidak publik/publik) atau penyimpanan sendiri.
3. Siapa peninjau per modul (pemilik flow).
4. Urutan produksi G1 bila waktu tidak cukup: rekomendasi mulai dari jalur "Mulai dalam 1 hari" dan "Kasir hari pertama".
