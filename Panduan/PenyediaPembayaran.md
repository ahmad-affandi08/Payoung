# Cara menambah atau mengganti penyedia pembayaran (D-179)

Payoung tidak terikat satu penyedia pembayaran. Semua kode di luar `Aplikasi/Web/app/Domain/Integrasi` bicara lewat **tiga
colokan**; DOKU hanyalah adaptor pertama di belakang masing-masing. Mengganti atau menambah penyedia berarti menulis
satu adaptor per colokan yang dipakai dan mendaftarkannya, **tanpa menyentuh aksi tenant, rekonsiliasi, kontroler,
halaman, atau tes yang sudah ada**.

> Tes arsitektur `tests/Arsitektur/PenyediaPembayaranTes.php` menjaga batas ini: kelas khusus DOKU (`GerbangBillingPlatform`,
> `GerbangBillingSnap`, `KlienSnap`, `KlienPartnerDoku`, `AdaptorDoku`, `ProtokolDoku`, dst.) hanya boleh dipakai di
> `Domain/Integrasi` dan penguji koneksi P-05. Jangan melonggarkan tes itu; pakai colokannya, atau tambahkan kemampuan
> yang kurang ke colokan.

## Tiga colokan

| Colokan | Untuk apa | Dipilih lewat | Adaptor DOKU |
|---|---|---|---|
| `Billing\PenyediaTagihanPlatform` | Payoung **menagih tenant** (tagihan langganan, P-08): Checkout/halaman bayar, status, webhook | `config('integrasi.PenyediaTagihan')`, pabrik `PembuatPenyediaTagihan` | `AdaptorTagihanDoku` (Checkout + SNAP) |
| `Merchant\PendaftarMerchantPembayaran` | **Mendaftarkan tenant** sebagai penerima QRIS (KYB, D-83) | `config('integrasi.PenyediaPendaftaranMerchant')`, pabrik `PembuatPendaftarMerchant` | `AdaptorPendaftarDoku` |
| `GerbangPembayaran\GerbangPembayaran` | **QRIS dinamis toko** (F-08): buat QR, cek status, webhook, (refund) | katalog `PenyediaGerbang`, pabrik `PembuatGerbangPembayaran` | `AdaptorDoku` |

Isian tiap penyedia (Client ID, secret, mode Sandbox/Produksi, kunci, dst.) tetap diatur di konsol (P-05) lewat katalog
`PenyediaIntegrasi`; adaptor membacanya dari `config('integrasi.*')` atau dari gerbang tenant, dan tidak menyimpan state.

## Langkah menambah penyedia (contoh: `Xendit`)

### A. Tagihan langganan (`PenyediaTagihanPlatform`)

1. Tambah penyedia di katalog P-05: case baru di `Pengelola\Integrasi\Enum\PenyediaIntegrasi` (jenis `GerbangBilling`),
   lengkap dengan bidang pengaturan, bidang kredensial, label, keterangan, dan kelas penguji koneksi
   (`Pengelola\Integrasi\Penguji\PengujiXendit` yang mengimplementasikan `PengujiKoneksi`). `match` di enum itu tanpa
   `default`, jadi lupa melengkapi akan gagal keras.
2. Tulis `Integrasi\Billing\AdaptorTagihanXendit implements PenyediaTagihanPlatform`. Metode yang tidak dimiliki penyedia
   (misal tidak ada halaman bayar Payoung) dijawab `CekHalamanBayarAktif(): false` dan `AmbilPilihanHalamanBayar(): []`;
   halaman tidak menawarkan jalur itu.
3. Daftarkan di `PembuatPenyediaTagihan`: tambahkan kode ke `PENYEDIA` dan satu baris di `match` `Buat()`.
4. Tulis rute webhook penyedia itu (kontroler tipis yang memanggil `CekTandaTanganSah`, `UraiNotifikasi`, `Konfirmasi`, lalu
   `TerimaNotifikasiBillingLangganan`). Rute DOKU (`/webhook/billing/doku`, `/webhook/billing/snap`) tetap jalan selama
   masih ada tagihan DOKU yang menggantung.
5. Setel `integrasi.PenyediaTagihan` ke kode baru (`config/integrasi.php`), aktifkan integrasinya di konsol, uji koneksi.

Aturan yang wajib dipenuhi adaptor (tertulis di docblock port):

- Tagihan **tidak pernah lunas** dari peramban atau isi notifikasi mentah. `Konfirmasi`/`TanyaStatus` selalu bertanya ke
  API status penyedia dengan permintaan bertanda tangan kita.
- `GalatGerbang` dengan `tidakPasti: true` bila transaksi mungkin sudah terbentuk di penyedia (waktu habis, 5xx, 408/409/425/429,
  jawaban sukses yang tidak terbaca). Hanya penolakan 4xx pasti yang `tidakPasti: false`.
- `nomorPesanan` memuat tenant (`NomorPesananBilling`), jadi webhook tanpa sesi bisa menetapkan tenant sebelum query.
- Tanpa state, tanpa kredensial di log atau pesan galat.

Pembayaran yang sedang berjalan di penyedia lama tetap diselesaikan oleh jalur lama sampai lunas atau kedaluwarsa
(`PembayaranLangganan.RefGateway` menyimpan nomor pesanannya); jangan ganti penyedia saat masih ada banyak yang `Menunggu`
tanpa mempertahankan rute webhook dan rekonsiliasi untuk penyedia lama.

### B. Pendaftaran merchant (`PendaftarMerchantPembayaran`)

1. Tambah penyedia ber-jenis `PendaftaranMerchant` di katalog P-05 (seperti langkah A.1).
2. Tulis `Integrasi\Merchant\AdaptorPendaftarXendit implements PendaftarMerchantPembayaran`:
   - `Kirim($pendaftaran, $bank)` mengunggah berkas KYC dan mendaftarkan bisnis. Catat kemajuan di baris pendaftaran
     (id berkas) supaya `Kirim` bisa dilanjutkan setelah galat tidak pasti; hapus berkas lokal setelah id-nya tersimpan.
   - `AmbilStatus($pendaftaran)` menjawab `HasilPendaftaranMerchant` dengan `StatusPendaftaranMerchant` Payoung
     (`Ditinjau`/`Aktif`/`Ditolak`) dan id bisnis/brand/kunci bersama. Kosakata penyedia diterjemahkan di adaptor.
   - Alamat callback status dibentuk adaptor sendiri dari `TokenCallback`.
   - Lempar `GalatPendaftaranMerchant` (`tidakPasti` menentukan boleh diulang atau tidak).
3. Daftarkan di `PembuatPendaftarMerchant`, setel `integrasi.PenyediaPendaftaranMerchant`.
4. Kolom penyedia pada `PendaftaranMerchantPembayaran` (`IdBisnisDoku`, `IdBrandDoku`, `KunciBersama`, `StatusDoku`, `IdFile*`)
   dipakai sebagai kolom "id bisnis/brand/status penyedia" oleh adaptor mana pun; kolom `Penyedia` menandai pemiliknya.
   Satu tenant punya satu baris per penyedia: setelah ganti penyedia, tenant mendaftar baru di penyedia itu, sedangkan
   QRIS lama tetap berjalan lewat gerbang tenant yang sudah aktif.

### C. QRIS dinamis toko (`GerbangPembayaran`)

1. Tambah case di `Integrasi\Enum\PenyediaGerbang` (kode, label, keterangan, bidang pengaturan, bidang kredensial; semuanya
   `match` tanpa `default`).
2. Tulis `Integrasi\GerbangPembayaran\Adaptor\AdaptorXendit extends AdaptorDasar` (`AdaptorDasar` sudah menangani mode,
   HTTP, penyaringan rahasia, dan klasifikasi galat). Isi `BuatQris`, `CekStatus`, `UraiWebhook`, `UjiKoneksi`.
3. Nyatakan kemampuannya: timpa `AmbilKemampuan()` bila lebih dari bawaan. Kemampuan:
   - `QrisDinamis` (wajib),
   - `CekStatusDariNomorPesanan` (cukup nomor pesanan untuk cek status; rekonsiliasi otomatis memakainya),
   - `Refund` (timpa juga `Refund()`; bawaan `AdaptorDasar` menolak dengan `GalatGerbang` yang menyuruh kembalikan dana
     manual). Kode pemanggil bertanya `CekMendukung()` dulu, tidak menebak dari nama penyedia.
4. Daftarkan di `PembuatGerbangPembayaran` (`PENYEDIA` dan `match` `Buat()`), dan tambahkan penyedianya di katalog P-05.

## Daftar periksa sebelum dianggap selesai

- [ ] Adaptor mengimplementasikan colokannya; tes unit adaptor memakai `Http::fake` dengan contoh respons asli penyedia.
- [ ] Tes alur (bayar, rekonsiliasi, webhook) di `tests/Fitur/Integrasi/PenyediaPembayaranBisaDitukarTes.php` sebagai contoh:
      penyedia palsu di `tests/Pendukung/Integrasi/PenyediaTagihanPalsu.php` menjalankan alur tagihan penuh tanpa kelas DOKU.
- [ ] `tests/Arsitektur/PenyediaPembayaranTes.php` hijau tanpa dilonggarkan.
- [ ] Nilai uang tetap `Uang` (brick/math), tanpa float.
- [ ] Rahasia tidak masuk log, pesan galat, atau respons API.
- [ ] Sandbox penyedia dicoba end-to-end sebelum dibuka ke tenant (catat di PRD bila belum, seperti `Panduan/DokuSnap.md`).
- [ ] PRD (riwayat versi + D-xx) dan baris status `CLAUDE.md` diperbarui.

## Yang sengaja belum diabstraksikan

- Webhook per penyedia tetap punya rute dan kontrolernya sendiri (bentuk tanda tangan dan badan setiap penyedia berbeda);
  bagian yang bersama (`TerimaNotifikasiBillingLangganan`, `TerimaCallbackPendaftaranMerchant`) sudah tunggal.
- Kunci SNAP (`BuatKunciSnapIntegrasi`) dan pengujian koneksi P-05 per penyedia (`PengujiDoku*`) khusus DOKU; penyedia lain
  membawa pengujinya sendiri.
- Aliran dana QRIS ke sub-akun/agregator (D-82/D-83) menunggu jawaban penyedia; itu soal kontrak bisnis, bukan soal kode.
