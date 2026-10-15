# Logo cara bayar (halaman bayar langganan)

`Komponen/Langganan/LogoBayar.tsx` memuat semua berkas `*.svg`, `*.png`, dan `*.webp` di folder ini lewat nama berkasnya. Nama berkas **PascalCase** sesuai konvensi (`Bni.svg`, `ShopeePay.svg`); pencocokan tidak membedakan huruf besar/kecil:

| Berkas | Dipakai untuk |
|---|---|
| `qris` | QRIS |
| `dana`, `shopeepay` | DANA, ShopeePay |
| `bni`, `bri`, `mandiri`, `bsi`, `permata`, `cimb`, `bca`, `danamon`, `maybank`, `btn`, `bjb`, `sinarmas` | bank virtual account (dicocokkan dari nama atau kode channel yang diisi operator di konsol) |

Berkas yang belum ada tampil sebagai lencana teks netral (misal "BNI"), bukan gambar tiruan.

## Asal berkas (penting)

Atas permintaan pemilik produk (15/10/2026, D-161), berkas di folder ini diambil dari paket komunitas
[`idn-finlogos`](https://github.com/hafidznoor/idn-finlogos) (kumpulan: Hafidz Noor Fauzi; SVG diberi `xmlns` agar tampil
sebagai `<img>`, bentuk logonya tidak diubah). Lisensi kumpulannya **CC BY-NC 4.0** (`LisensiLogo.txt`): atribusi wajib
(dipenuhi di berkas ini dan di `Panduan/DokuSnap.md`) dan **tidak boleh untuk tujuan komersial**. Logo itu sendiri adalah merek
dagang pemiliknya (BNI, BRI, Mandiri, BSI, Permata, CIMB Niaga, BCA, Danamon, Maybank, BTN, bjb, Sinarmas, DANA, ShopeePay, QRIS),
dan paket itu sendiri menyatakan pemakaian di produk komersial perlu izin pemegang merek.

**Sebelum Payoung dijual secara komersial**, ganti berkas-berkas ini dengan logo resmi:
1. Minta paket logo saluran pembayaran ke account manager DOKU (biasa tersedia untuk merchant) atau ke pedoman merek tiap bank.
2. Timpa berkas dengan nama yang sama (SVG berisi `xmlns`; rasio bebas, ditampilkan `object-contain` di kotak 64x40 px).
3. Jalankan `npm run build`. Tidak ada kode yang perlu diubah.
