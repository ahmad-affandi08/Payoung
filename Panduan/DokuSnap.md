# DOKU SNAP untuk tagihan langganan (D-159)

Tenant membayar tagihan langganan di **halaman bayar Payoung** (bukan halaman DOKU): QRIS dinamis, virtual account per bank,
DANA, dan ShopeePay. DOKU Checkout tetap ada untuk kartu kredit dan metode lain. Panduan ini untuk operator Payoung (peran
Teknis atau Super Admin) yang memasang SNAP di konsol.

> **Status jujur:** semua bentuk permintaan mengikuti dokumen DOKU SNAP dan diuji dengan tiruan respons. Belum pernah
> diuji ke sandbox atau produksi DOKU sungguhan. Lakukan uji sandbox (langkah 5) sebelum membuka ke tenant, dan baca
> daftar "Yang harus dicocokkan di sandbox" di bawah.

## 1. Isi pengaturan di konsol

Konsol › Integrasi › **Akun DOKU Payoung (induk)** (lingkungan Staging untuk sandbox, Produksi untuk uang sungguhan).
Cara bayar yang pengaturannya kosong **tidak ditawarkan** ke tenant, jadi boleh diisi bertahap.

| Isian | Dari mana | Efek |
|---|---|---|
| Client ID, Secret key | dasbor DOKU › API Keys | sudah dipakai Checkout; SNAP memakai yang sama |
| Merchant ID QRIS, Terminal ID QRIS, Kode pos QRIS | DOKU setelah QRIS disetujui (halaman QR Payment) | QRIS muncul |
| Bank virtual account | dokumen DOKU per bank | satu bank per entri: `Label\|KODE_CHANNEL\|PartnerServiceId\|Prefiks`, dipisah titik koma. Contoh: `BNI\|VIRTUAL_ACCOUNT_BNI\|98829172\|3` |
| Bayar dengan DANA / ShopeePay | layanan harus aktif di akun DOKU | tombol e-wallet muncul bila `Aktif` |

Menyimpan pengaturan mengembalikan status integrasi ke "Belum diuji" dan menonaktifkannya (aturan BR-P05.4): uji koneksi
lalu aktifkan lagi.

## 2. Buat kunci SNAP dan daftarkan ke DOKU

1. Di kartu integrasi, klik **Buat kunci SNAP**. Server membuat pasangan RSA 2048. Kunci pribadi tersimpan terenkripsi
   dan tidak pernah tampil; kunci publik tampil di kartu.
2. Klik **Salin kunci publik**, buka dasbor DOKU › API Keys › **Edit Merchant Public Key**, tempel, simpan.
3. Klik **Uji koneksi** di konsol. Keterangan harus memuat `SNAP: Token SNAP diterima DOKU`. Bila `SNAP belum siap`,
   kunci publik belum terdaftar atau Client ID salah. Checkout tidak terpengaruh oleh hasil SNAP.

Membuat ulang kunci memutus SNAP sampai kunci publik yang baru didaftarkan ulang; Checkout tidak terganggu.

## 3. Setel alamat di dasbor DOKU

Ganti `dashboard.payoung.id` dengan domain tenant Anda.

| Pengaturan DOKU | Nilai |
|---|---|
| Pengaturan SNAP › Token URL | `https://dashboard.payoung.id/webhook/billing/snap/authorization/v1/access-token/b2b` |
| URL notifikasi SNAP (VA, e-wallet) dan QRIS Notify URL | `https://dashboard.payoung.id/webhook/billing/snap` |
| URL notifikasi Checkout (tetap) | `https://dashboard.payoung.id/webhook/billing/doku` |

DOKU menambahkan jalur tetap ke URL notifikasi (misalnya `/v1.0/transfer-va/payment`); semua jalur di bawah
`/webhook/billing/snap/` ditampung. **Jangan ubah QRIS Notify URL bawaan DOKU sebelum uji sandbox berhasil**, karena URL itu
mungkin dipakai layanan lain di akun yang sama.

## 4. Cara kerja pelunasan (supaya tahu apa yang aman)

- Pelunasan **hanya** dari jawaban API status DOKU yang kita tanyakan sendiri (permintaan bertanda tangan).
- Notifikasi dari DOKU hanya **pemicu** untuk menanyakan status; isinya tidak dipercaya. Notifikasi palsu atau berbeda
  format dari dugaan paling buruk diabaikan, tidak pernah melunasi.
- Tiga jaring pengaman bila notifikasi tidak tiba: halaman bayar menanyakan status tiap 6 detik, halaman tagihan
  menanyakannya saat dibuka, dan rekonsiliasi terjadwal (`tagihan:rekonsiliasi-gerbang`, tiap 15 menit).
- Kode bayar berlaku 60 menit. Selama masih berlaku, menekan cara bayar lain menampilkan kode yang sama (mencegah dua kode
  bayar untuk satu tagihan). Setelah lewat batas (ditambah jeda 15 menit) pembayaran ditolak otomatis dan tenant bisa memilih lagi.
- Jumlah yang dibayar berbeda dari total tagihan tidak dilunasi otomatis; masuk antrean verifikasi manual.

## 5. Uji sandbox (wajib sebelum produksi)

1. Lingkungan Staging aktif, tenant uji membuat tagihan Pro bulanan.
2. Pilih **QRIS**, lalu bayar dengan [simulator QRIS DOKU](https://sandbox.doku.com/qris-simulator/). Tagihan harus lunas
   dalam beberapa detik (polling) atau paling lama 15 menit (rekonsiliasi).
3. Ulangi untuk satu bank virtual account dan untuk e-wallet yang diaktifkan.
4. Bila ada yang tidak lunas, lihat `storage/logs` untuk baris `Notifikasi SNAP tanpa pembayaran yang cocok` dan
   periksa daftar di bawah.

## Yang harus dicocokkan di sandbox

Dokumen DOKU tidak menjawab ini dengan pasti; kode memakai dugaan paling aman dan salah satunya bisa perlu penyesuaian.

1. **Penanda lunas virtual account.** Kode menganggap lunas bila `virtualAccountData.paymentFlagStatus` = `00` (atau,
   bila kolom itu tidak ada, `paymentFlagReason` berisi kata sukses/lunas). Contoh di dokumen DOKU menampilkan "Pending"
   dengan `paidAmount` terisi, sehingga kolom penandanya perlu dikonfirmasi. Bila VA yang sudah dibayar tidak lunas,
   periksa jawaban `/orders/v1.0/transfer-va/status` dan sesuaikan `PenguraiStatusSnap::UraiVirtualAccount`.
2. **Nilai `customerNo` untuk VA yang dibuat DOKU (DGPC).** Kode mengirim awalan dari pengaturan bank; DOKU yang menambahkan sisanya.
3. **Path ShopeePay.** Dokumen menyebut `/direct-debit/core/v1/debit/payment-host-to-host` dan `.../v2/...` dalam satu halaman;
   kode memakai v1. Bila ditolak, ubah konstanta di `GerbangBillingSnap`.
4. **Format notifikasi.** Path, tanda tangan, dan badan notifikasi QRIS tidak ada di dokumen yang dibaca; ditangani dengan
   desain pemicu di atas. Jawaban kita memakai `responseCode` bentuk `200` + kode layanan + `00` (VA 25, QRIS 52, e-wallet 56).
5. **Status e-wallet** memakai `/orders/v1.0/debit/status` dengan `serviceCode` `55`.

## Logo cara bayar (D-160, D-161)

Halaman bayar menampilkan logo QRIS, DANA, ShopeePay, dan tiap bank dari `Aplikasi/Web/resources/js/Aset/Bayar/` (nama berkas PascalCase seperti `Bni.svg`; kode:
`qris`, `dana`, `shopeepay`, `bni`, `bri`, `mandiri`, `bsi`, `permata`, `cimb`, `bca`, `danamon`, `maybank`, `btn`, `bjb`,
`sinarmas`, `bank`; `.svg`/`.png`/`.webp`; SVG wajib memuat `xmlns`). Bank yang berkasnya belum ada tampil sebagai lencana teks netral.

**Asal & atribusi:** berkas yang ada sekarang berasal dari paket komunitas `idn-finlogos` oleh Hafidz Noor Fauzi
(https://github.com/hafidznoor/idn-finlogos), lisensi CC BY-NC 4.0. Merek dagangnya milik masing-masing pemegang. Lisensi
non-komersial itu **tidak mengizinkan** pemakaian pada layanan berbayar tanpa izin pemegang merek, jadi sebelum peluncuran
komersial timpa semua berkas dengan logo resmi (minta ke account manager DOKU atau pedoman merek bank), lalu `npm run build`.

## Mematikan SNAP

Kosongkan isian cara bayar di konsol (Merchant ID QRIS, bank VA, set DANA/ShopeePay ke Mati). Tenant otomatis hanya
melihat tombol DOKU Checkout. Pembayaran SNAP yang sudah berjalan tetap bisa dilunasi lewat rekonsiliasi selama kunci SNAP masih ada.

## Referensi kode

`app/Domain/Integrasi/Snap/` (klien, gerbang, pengurai status, pembuat kunci), `ProsesPemicuSnapLangganan` (pemicu notifikasi),
`WebhookBillingSnapKontroler`, `PanelBayarSnap.tsx` (halaman bayar). Tes: `PembayaranSnapTes`, `KunciSnapTes`, `SnapTes`.
