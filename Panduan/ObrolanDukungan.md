# Obrolan langsung dukungan (PLT-12, D-163)

Panduan untuk petugas dukungan dan operator. Obrolan adalah **tiket berkanal Obrolan**: percakapannya tampil waktu nyata
bila ada petugas siap, dan tetap menjadi tiket biasa (SLA paket, email, eskalasi) bila tidak ada.

## Petugas

1. Buka konsol › **Dukungan › Buka obrolan langsung** (`/dukungan/obrolan`). Perlu izin `dukungan.tiket.lihat` untuk membaca
   dan `dukungan.tiket.tangani` untuk membalas.
2. Tekan **Siap menerima obrolan**. Selama halaman terbuka, ia berdetak tiap 20 detik; bila halaman ditutup atau koneksi
   putus lebih dari 60 detik, Anda dianggap offline dan tenant melihat "Tim belum online".
3. Obrolan yang paling lama menunggu ada di atas. Judul tab memuat jumlah yang menunggu, misalnya `(2) Obrolan langsung`.
4. Pilih satu obrolan, balas (Enter mengirim, Shift+Enter baris baru). Balasan pertama otomatis menjadikan Anda penanggung
   jawab tiket dan mengisi respons pertama SLA. **Ambil obrolan ini** menugaskan tanpa membalas; **Selesaikan obrolan**
   menandai tiket Selesai (pelapor bisa membukanya lagi dengan membalas dalam 7 hari).
5. Catatan internal dan lampiran tetap lewat halaman tiket (**Buka sebagai tiket**).

## Yang terjadi pada tenant

- Panel ada di lembar **Bantuan** (ikon tanda tanya di kepala back-office) untuk pengguna yang boleh menghubungi dukungan.
- Tidak ada petugas siap: layar berkata terus terang bahwa pesan tercatat sebagai tiket dan dibalas sesuai janji respons
  paket (di luar jam kerja disebutkan jam layanan). Email tiket baru ke tim tetap terkirim.
- Ada petugas siap: email tiket baru **tidak** dikirim (obrolan muncul di inbox). Balasan petugas tidak dikirimi
  email/notifikasi selagi pelapor sedang membuka obrolan (45 detik sejak polling terakhir); bila ia sudah menutup lembar,
  email dan notifikasi tetap dikirim seperti balasan tiket.
- Satu obrolan terbuka per pelapor, pesan maksimal 2.000 karakter.

## Pengaturan

`config/dukungan.php` › `Obrolan`: `PetugasAktifDetik` (60), `SegarkanDetik` (4), `MaksPanjangPesan` (2000), `DilihatDetik` (45),
`MaksObrolanKonsol` (60). Jam layanan memakai `JamKerja` yang sama dengan SLA.

## Batas yang perlu diketahui

- Hanya back-office tenant (edisi SaaS); belum di Aplikasi Kasir/Owner.
- Polling HTTP, bukan websocket: jeda sampai 4 detik, dan beban pada skala besar belum diuji.
- Tidak ada lampiran, indikator mengetik, atau suara/push untuk petugas.
- Akses lintas tenant inbox dicatat di audit sekali per 5 menit per petugas, bukan tiap polling.
