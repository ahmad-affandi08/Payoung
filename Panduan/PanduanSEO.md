# Panduan SEO payoung.id

Tujuan: orang yang mencari "aplikasi kasir", "aplikasi kasir toko sayur", atau "payoung" menemukan payoung.id di Google.
Dibuat 11 Oktober 2026 (D-89). Bagian A sudah ada di kode; bagian B–D hanya bisa dikerjakan pemilik produk.

## Yang perlu dipahami dulu

- Hasil pencarian seperti "majoo" (satu hasil utama + tautan Dashboard, Harga, Fitur di bawahnya) disebut **sitelinks**.
  Google memilihnya sendiri untuk merek yang sudah dikenal. Tidak ada tombol atau kode yang bisa memaksanya, dan situs baru
  umumnya baru mendapatkannya setelah berbulan-bulan dengan banyak pencarian nama merek.
- Target realistis bertahap: (1) mencari **"payoung"** menampilkan payoung.id di urutan pertama, (2) kata kunci
  jenis usaha ("aplikasi kasir toko sayur") masuk halaman 1–2, (3) sitelinks muncul sendiri sesudahnya.
- Domain baru butuh waktu: indeks pertama biasanya hitungan hari, naik peringkat hitungan bulan.

## A. Sudah ada di kode

- Judul & deskripsi unik per halaman, tag kanonik, Open Graph, kartu Twitter, `lang="id"`, diisi server (tanpa menunggu JavaScript).
- Kerangka HTML server per halaman (isi terbaca mesin pencari sebelum React jalan).
- Peta situs XML otomatis di `https://payoung.id/peta-situs` (halaman terbit + blog), `robots.txt` menunjuk ke sana.
- JSON-LD: Organisasi (nama, URL, logo) + WebSite (nama situs, bahasa), dan BlogPosting untuk artikel.
- Halaman per jenis usaha: kafe & resto, toko & retail, salon/laundry/jasa (3 halaman). Blog dengan kategori.
- Back-office, konsol, struk digital, dan API tidak diindeks (`X-Robots-Tag: noindex` / `robots.txt`), jadi tidak bersaing dengan situs pemasaran.
- Judul beranda bawaan: "Payoung | Aplikasi Kasir yang Tetap Jalan Walau Offline".

## B. Lakukan minggu ini (gratis, paling berpengaruh)

1. **Google Search Console** (search.google.com/search-console): tambahkan properti `payoung.id` (tipe Domain, verifikasi lewat DNS).
   Lalu isi kode verifikasi di konsol › Situs pemasaran › Pengaturan bila memakai verifikasi meta.
2. Di Search Console › **Sitemaps**: kirim `https://payoung.id/peta-situs`. Lalu **Pemeriksaan URL** untuk beranda, `/fitur`,
   `/harga` dan klik "Minta pengindeksan".
3. **Google Business Profile** (bisnis.google.com): daftarkan Payoung sebagai bisnis perangkat lunak, isi alamat/kontak, tautan situs.
4. **Nama merek konsisten** di mana-mana: "Payoung" (jangan "Payoung POS" di satu tempat dan "PAYOUNG" di tempat lain).
5. **Profil merek** (dipakai Google untuk mengenali bahwa Payoung itu entitas nyata): Instagram, Facebook, LinkedIn, YouTube,
   TikTok dengan nama yang sama, masing-masing menaut ke payoung.id di bio.
6. **Daftar di Play Store / App Store** dan tautkan dari situs. Halaman toko aplikasi sendiri muncul di Google.

## C. Konten (inilah yang membuat peringkat naik)

Google menilai halaman yang menjawab apa yang dicari orang. Rencana yang paling berdampak:

1. **Satu halaman per jenis usaha** (bukan satu halaman umum). Saat ini ada 3. Tambahkan lewat konsol › Situs pemasaran
   (editor halaman, tanpa kode): toko sayur & sembako, apotek, bengkel, grosir & distributor, gym & kursus, pet shop,
   florist, cuci kendaraan, bakery, fashion, elektronik, bahan bangunan. Judul contoh: "Aplikasi Kasir Toko Sayur & Sembako | Payoung".
   Isi minimal 400 kata nyata: masalah usaha itu, fitur yang relevan, tangkapan layar, contoh hitungan, tanya-jawab.
2. **Blog 2–4 artikel per bulan** untuk pertanyaan yang benar-benar dicari pemilik usaha, misalnya cara menghitung HPP,
   cara mengelola stok barang kedaluwarsa, perbedaan PPN dan PBJT untuk kafe, cara memilih aplikasi kasir, cara pakai
   QRIS di toko. Tulis dari pengalaman nyata, bukan salinan. Setiap artikel menaut ke halaman jenis usahanya dan ke `/daftar`.
3. **Halaman perbandingan** jujur ("Payoung vs buku kas manual", "aplikasi kasir gratis vs berbayar"), karena orang membandingkan sebelum memilih.
4. Pakai bahasa yang dipakai pelanggan: "aplikasi kasir", "mesin kasir", "program kasir", "software kasir", "aplikasi POS".
   Sebut sekali di judul/pembuka halaman, jangan diulang-ulang berlebihan.

## D. Tautan dari situs lain (backlink)

- Direktori usaha dan perangkat lunak Indonesia, komunitas UMKM, forum pemilik usaha, grup Facebook/WhatsApp pemilik toko.
- Pilih pengguna pertama yang puas lalu minta testimoni tertulis dan tautan ke situs mereka (dengan izin).
- Siaran pers / liputan media lokal untuk peluncuran, dan kerja sama dengan komunitas atau asosiasi pedagang.
- Hindari membeli paket tautan massal: berisiko hukuman Google.

## E. Kecepatan & kesehatan teknis (diperiksa tiap rilis)

- Cek di PageSpeed Insights (pagespeed.web.dev) untuk `https://payoung.id/` dan `/harga`, target skor seluler ≥ 80.
- Di Search Console › Pengindeksan, pastikan tidak ada "Dikecualikan: noindex" pada halaman pemasaran penting.
- Pastikan `www.payoung.id` dialihkan ke `payoung.id` (K12), dan hanya satu versi (https) yang aktif.

## F. Cara memantau

Search Console menunjukkan kata kunci yang membawa pengunjung, halaman yang diindeks, dan galat. Lihat tiap minggu selama
tiga bulan pertama. Catat: jumlah halaman terindeks, posisi rata-rata untuk "payoung" dan "aplikasi kasir [jenis usaha]",
dan jumlah pendaftar dari pencarian organik (GA4, setelah persetujuan cookie).
