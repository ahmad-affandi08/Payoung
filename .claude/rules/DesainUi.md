---
paths:
  - "Aplikasi/Web/resources/js/Halaman/**"
  - "Aplikasi/Web/resources/js/Komponen/**"
  - "Aplikasi/Web/resources/js/TataLetak/**"
  - "Aplikasi/*/lib/Fitur/**"
  - "Aplikasi/*/lib/Tampilan/**"
  - "Paket/SistemDesain/**"
---

# Checklist desain sebelum layar dianggap selesai (PRD §17.6.11)

- [ ] Layar tetap bisa dipahami jika semua warna dihapus.
- [ ] Warna hanya untuk aksi utama & status; status selalu disertai teks/ikon.
- [ ] Tanpa gradien (kecuali kepala sidebar merek `BrandGelap` → `Brand`, D-15), efek kaca, bayangan dekoratif, emoji, ilustrasi dekoratif (ilustrasi merek penjelas keadaan kosong diizinkan, D-68; ilustrasi jenis usaha di pemilih sektor diizinkan, D-178; ilustrasi merek Aplikasi Owner diizinkan, D-231).
- [ ] Tidak ada kartu yang lebih jelas bila dijadikan baris tabel.
- [ ] Halaman punya **satu rumah**: menu samping (kerja harian) atau Pengaturan (sekali atur), tidak keduanya; anggaran navigasi §17.4.10 masih terpenuhi (maks. 12 entri level utama, 7 sub-menu per grup).
- [ ] Web: setiap kelas `text-*` berasal dari token `@theme` (D-28); tidak ada kelas semantik karangan sendiri.
- [ ] Web: tombol aksi bisnis memakai `Tombol` (spinner & `aria-busy`), bukan `Button` shadcn mentah (D-28).
- [ ] Web: bilah yang menempel di `bottom-0` memakai `tepi-bawah-aman` supaya tidak tertimpa indikator home (D-28).
- [ ] Web: panel bagian memakai `Komponen/Kelola/Panel`; `Card` langsung tetap `rounded-panel` + `shadow-none` (D-28).
- [ ] Web: tabel memakai `TabelData` (TanStack Table + Query, §17.4.3) dengan cari, saring, urut, atur kolom, paginasi server, keadaan di URL.
- [ ] Web: rapi di 360 / 768 / 1280px tanpa gulir horizontal halaman (§17.4.4).
- [ ] POS: layar berada di bingkai Ruang Kerja Kasir, maksimal dua ketukan dari layar Jual, rapi di 360 / 800 / 1280dp (§17.2.7).
- [ ] Font & ukuran dari token §17.5; uang tabular rata kanan; kode memakai font Mono.
- [ ] Keadaan §17.6.6 lengkap (memuat, kosong, galat, offline, tertunda sinkron, butuh persetujuan, tanpa izin, data ekstrem).
- [ ] Pemisah dalam satu baris keterangan (jam, nama, metode bayar, jumlah) memakai garis tegak berspasi " | " (D-41), bukan titik tengah "·" atau bulatan "•". `•` hanya untuk menyamarkan rahasia (`••••7788`) dan daftar di pesan WhatsApp.
- [ ] Microcopy §17.6.7 & kamus istilah §13.7.1.
- [ ] Mode kepadatan benar: Nyaman (POS, KDS, Owner, web publik) / Ringkas (back-office, pengelola).
