# Kampanye email ke calon pelanggan (D-216)

Fitur di konsol: **Tenant › Kampanye prospek** (hanya Super Admin). Untuk mengirim email perkenalan ke daftar calon pelanggan,
misalnya hasil impor dari portal data terbuka, dengan cara yang menjaga reputasi domain Payoung.

## Yang perlu disiapkan sekali

### 1. Pengirim terpisah (dianjurkan)
Bila kartu **Email kampanye** belum diisi, kampanye memakai **email bawaan yang sudah terpasang** (Integrasi › Email atau `.env`) dengan
alamat pengirim bawaannya (D-217). Itu cukup untuk mulai dengan volume kecil, tetapi memakai domain yang sama dengan email transaksional
(verifikasi, tagihan, struk). Tanpa pengirim apa pun (atau hanya `log`/`array`) kampanye tidak bisa dijalankan.
Pengirim terpisah lebih aman dan **didahulukan** bila diisi. Sebabnya: bila banyak penerima menandai email sebagai spam, yang rusak adalah reputasi domain pengirimnya. Kalau itu domain yang
sama dengan email verifikasi, pendaftar baru berhenti menerima email verifikasi.

1. Siapkan domain atau subdomain lain, misal `info.payoung.id`, dan buat kotak pengirim di penyedia email massal atau SMTP hosting.
2. Atur **SPF, DKIM, dan DMARC** untuk domain itu di DNS (panduan ada di penyedia emailnya). Tanpa ini email masuk spam.
3. Konsol → **Integrasi** → kartu **Email kampanye (domain pengirim terpisah)** → isi host SMTP, port, nama pengguna, kata sandi,
   alamat pengirim (`@` domain terpisah itu), dan nama pengirim. Simpan, **Uji koneksi**, lalu aktifkan.

### 2. Kontak
**Kampanye prospek › Kontak › Impor kontak.**
- Berkas CSV dengan kolom bernama `Email` dan `Sektor` (kode seperti `FNB-CAF` atau nama sektor seperti "Kafe & kedai kopi").
  Kolom lain (nama, telepon, alamat) diabaikan dan **tidak disimpan**.
- **Sumber data wajib diisi.** Tulisannya dicantumkan di setiap email ("Alamat email Anda kami peroleh dari …").
- Alamat yang sudah ada, termasuk yang pernah berhenti berlangganan, tidak dimasukkan lagi. Orang yang berhenti tidak bisa
  masuk kembali lewat impor ulang.

## Membuat dan menjalankan kampanye
1. **Buat kampanye**: nama (hanya untuk Anda), subjek, isi, sektor penerima, dan batas kirim per hari.
   - Boleh memakai `{sektor}` di subjek dan isi; diganti nama sektor penerima ("kafe & kedai kopi").
   - Tautan berhenti berlangganan dan sumber alamat ditambahkan otomatis di bagian bawah email.
2. **Simpan sebagai draf**, lalu **Kirim email uji ke saya**. Email uji masuk ke alamat Anda sendiri lewat pengirim kampanye yang sama.
   Periksa tampilannya dan apakah masuk kotak masuk atau spam.
3. **Jalankan kampanye.** Penerima ditetapkan saat itu: semua kontak aktif di sektor terpilih.

## Cara pengirimannya (sengaja pelan)
- Paling banyak 5 email tiap 5 menit, dan tidak lebih dari **batas per hari** (bawaan 40) per kampanye.
- Hanya **pukul 08.00–17.00 WIB**. Di luar jam itu menunggu.
- Kontak yang berhenti setelah kampanye dijalankan **dilewati**. Alamat yang ditolak server tujuan (tidak ada) ditandai tidak aktif.
- **Lima kegagalan berturut-turut menjeda kampanye otomatis** dengan alasannya. Periksa Integrasi › Email kampanye, lalu **Lanjutkan**.
- Menjeda atau membatalkan kapan saja. Email yang sudah terkirim tidak bisa ditarik.

Saran: mulai dengan batas 20–40 per hari selama beberapa hari. Naikkan pelan-pelan hanya bila tidak ada balasan marah atau
keluhan spam. Satu dua keluhan di awal sudah cukup untuk menurunkan reputasi pengirim baru.

## Berhenti berlangganan
Setiap email memuat tautan "Berhenti berlangganan" dan header yang membuat Gmail dan Yahoo menampilkan tombol berhenti sendiri.
Tautannya bertanda tangan: tidak bisa ditebak, dan halamannya tidak menampilkan alamat email. Yang berhenti masuk daftar penekan
permanen (status "Berhenti berlangganan") dan tidak pernah dikirimi lagi oleh kampanye mana pun.

Bila seseorang membalas email dan meminta berhenti, buka **Kontak**, cari alamatnya, lalu **Tandai berhenti**. Bila ia meminta
datanya **dihapus**, pilih **Hapus kontak**. Hapus hanya untuk permintaan itu: alamat yang dihapus bisa masuk lagi lewat impor,
sedangkan yang ditandai berhenti tidak.

## Catatan hukum dan etika
Alamat dari portal data pemerintah dipublikasikan untuk statistik, bukan pemasaran, jadi memakainya untuk email promosi bukan
berdasar persetujuan. Fitur ini menyediakan pengamannya (sumber jelas, berhenti satu klik, daftar penekan, batas harian kecil,
pengirim terpisah), tetapi tidak menjamin kepatuhan hukum. Untuk kampanye berkelanjutan, minta tinjauan penasihat hukum (UU PDP).
Tidak ada pelacak buka atau klik di email, sengaja.
