# Logo cara bayar (halaman bayar langganan)

`Komponen/Langganan/LogoBayar.tsx` memuat semua berkas `*.svg`, `*.png`, dan `*.webp` di folder ini lewat nama berkasnya:

| Berkas | Dipakai untuk |
|---|---|
| `qris` | QRIS |
| `dana`, `shopeepay` | DANA, ShopeePay |
| `bni`, `bri`, `mandiri`, `bsi`, `permata`, `cimb`, `bca`, `danamon`, `maybank`, `btn`, `bjb`, `sinarmas` | bank virtual account (dicocokkan dari nama atau kode channel yang diisi operator di konsol) |

Berkas yang belum ada tampil sebagai lencana teks netral (misal "BNI"), bukan gambar tiruan.

## Asal berkas (penting)

Logo bank dan dompet digital adalah merek dagang pemiliknya. **Repo ini sengaja tidak menyertakan logo apa pun** sampai
ada berkas dengan izin yang jelas:

1. Minta paket logo resmi saluran pembayaran ke account manager DOKU (mereka biasa menyediakannya untuk merchant), atau ke
   panduan merek tiap bank.
2. Simpan di folder ini dengan nama di tabel di atas (SVG lebih baik; rasio bebas, ditampilkan `object-contain` di kotak 64x40 px).
3. Jalankan `npm run build`. Tidak ada kode yang perlu diubah.

Paket npm komunitas `idn-finlogos` memuat banyak logo Indonesia, tetapi lisensi gambarnya CC BY-NC 4.0 (non-komersial)
dan memberi tahu bahwa pemakaian di produk komersial perlu izin pemilik merek; jangan disalin ke sini tanpa izin itu.
