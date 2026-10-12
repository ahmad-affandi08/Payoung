<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### F-00 · Registrasi Tenant & Langganan

**Tujuan:** Calon pelanggan membuat akun usaha (tenant) dan memulai masa trial.
**Aktor:** Calon Owner, Sistem.
**Pemicu:** Klik "Daftar Gratis" di landing page (atau tautan mitra/referral, P-12).
**Prasyarat:** P-02 s.d. P-06 selesai: paket & batas tersedia (P-04), template sektor terbit (P-03), tarif pajak & wilayah (P-02), email & CAPTCHA aktif (P-05), S&K, Kebijakan Privasi, dan Perjanjian Pemrosesan Data terbit (P-06).

**Langkah:**
1. Isi nama, email, no. WhatsApp, password, nama usaha, (opsional) kode mitra/referral, lalu **centang persetujuan S&K, Kebijakan Privasi, dan Perjanjian Pemrosesan Data** versi yang berlaku (tercatat di `PersetujuanDokumenLegal`).
2. Verifikasi email (link) **atau** OTP WhatsApp.
3. Sistem membuat baris di tabel `Tenant`, `Pengguna` (peran Owner), `Langganan` (status `Trial`, durasi & paket sesuai konfigurasi P-04), `AtribusiMitra` bila ada kode mitra, outlet default "Outlet Utama", gudang default.
4. Redirect ke Onboarding Wizard (F-01).

**Aturan Bisnis:**
- BR-00.1 Email dan nomor WA unik per user. Satu user **boleh** menjadi anggota beberapa tenant (konsultan/akuntan), dengan pemilih tenant setelah login.
- BR-00.2 Slug tenant unik, dibuat otomatis dari nama usaha. Dipakai untuk URL toko online `/{slugTenant}`.
- BR-00.3 Trial tidak butuh kartu kredit. Di akhir trial, tenant turun ke paket Gratis (fitur terbatas), bukan dihapus.
- BR-00.4 Rate-limit registrasi per IP (anti-spam) + CAPTCHA (Cloudflare Turnstile). Default 5 percobaan per jam per IP. Di Produksi, registrasi ditutup bila CAPTCHA belum aktif (P-05); lingkungan non-produksi boleh tanpa CAPTCHA.
- BR-00.5 Tombol Daftar langsung membentuk tenant (AC di bawah) dan masuk sebagai Owner; email verifikasi (tautan bertanda tangan, berlaku 24 jam) dikirim bersamaan dan banner pengingat tampil sampai terverifikasi. OTP WhatsApp menyusul bersama integrasi WhatsApp BSP. Email yang sudah terdaftar tidak bisa dipakai mendaftar lagi; menambah usaha kedua untuk pengguna yang sama dibangun bersama F-02 (undangan & pemilih tenant). **Email asal-asalan (v5.09):** saat daftar dan saat ganti email, alamat disaring (`PemeriksaAlamatEmail`): salah ketik penyedia populer (gmial.com → gmail.com), domain contoh/uji, email sekali pakai, dan domain tanpa server surat (cek DNS MX/A, `tenant.PeriksaDnsEmail`, mati di lingkungan test). Salah ketik yang lolos penyaring diperbaiki lewat **Ganti email** di Keamanan akun (`/kelola/keamanan`): konfirmasi kata sandi, tautan bertanda tangan (berlaku sama dengan verifikasi, memuat hash email lama) dikirim ke **alamat baru**; email akun baru berganti dan langsung terverifikasi setelah tautan dibuka, dicatat di log audit (`akun.email-diganti`). Alamat yang sudah dipakai akun lain dijawab sama dengan yang berhasil (tanpa email terkirim, §25 no. 18); akun buatan Google tanpa kata sandi harus mengatur kata sandi dulu.
- BR-00.6 Paket trial: paket yang dipilih di halaman harga (`?paket=KODE`) bila aktif dan bukan harga negosiasi, selain itu paket bawaan registrasi (konfigurasi, default `PRO`). Durasi trial = `Paket.MasaTrialHari`; paket tanpa masa trial (misal `GRATIS`) langsung berstatus `Gratis`. Registrasi ditolak bila S&K dan Kebijakan Privasi belum berlaku (BR-P06.2) atau paket tidak aktif.
- BR-00.7 Transisi `Langganan.Status` yang sah: Trial → Aktif/Gratis; Aktif → Tertunggak/Berhenti/Gratis (Gratis hanya lewat turun paket mandiri BR-P04.4); Tertunggak → Aktif/Ditangguhkan; Ditangguhkan → Aktif/Gratis/Berhenti; Gratis → Aktif. Akhir trial diproses perintah terjadwal tiap jam (agar tenant tidak menikmati trial hingga sehari lebih lama): langganan pindah ke paket Gratis (konfigurasi, default `GRATIS`).
- BR-00.8 2FA TOTP akun tenant (§20.2): diaktifkan dari back-office (`/kelola/keamanan`) dengan memindai QR lalu memasukkan kode pertama; 8 kode pemulihan sekali pakai disimpan terenkripsi dan ditampilkan sekali. Akun ber-2FA masuk dua langkah: kata sandi benar belum membuat sesi masuk, hanya "masuk tertunda" 10 menit sampai kode TOTP atau kode pemulihan terverifikasi (maks. 5 percobaan per 5 menit; kode TOTP yang sudah dipakai ditolak). Menonaktifkan 2FA wajib konfirmasi kata sandi. Owner tenant yang paketnya memuat fitur `keamanan.2fa-wajib` (Bisnis ke atas) tidak bisa membuka menu `/kelola` lain sebelum 2FA aktif dan tidak bisa menonaktifkannya; kewajiban untuk Admin/Akuntan menyusul bersama peran tenant (F-02). Masuk dengan Google menggantikan TOTP untuk akun yang memakainya (BR-00.11, D-57).
- BR-00.9 Lupa kata sandi: `/lupa-kata-sandi` mengirim tautan atur ulang (token sekali pakai, berlaku 60 menit) dengan jawaban yang sama untuk email terdaftar maupun tidak; permintaan dibatasi 10 per jam per IP dan 3 email per jam per alamat. Setelah kata sandi diganti, token "ingat saya" diganti, sesi lain berakhir, email dianggap terverifikasi (tautan membuktikan kepemilikan email), dan pemilik akun menerima email pemberitahuan.
- BR-00.10 Registrasi dengan email atau nomor WhatsApp yang sudah dipakai ditolak dengan satu pesan umum untuk keduanya ("Email atau nomor WhatsApp tidak dapat dipakai. Jika ini milik Anda, masuk atau atur ulang kata sandi."), tanpa data yang tercipta, dan pemilik akun yang cocok menerima email pemberitahuan upaya pendaftaran (paling banyak satu per jam per akun). Pesan masuk dan lupa kata sandi juga tidak membedakan akun terdaftar atau tidak (UU PDP, §25 no. 18).
- BR-00.11 **Masuk dengan Google (D-57)** menggantikan 2FA: pemilik toko dapat mendaftar, masuk, dan menautkan akun Google dari web (`/masuk`, `/daftar`, Keamanan akun) maupun masuk dari Aplikasi Owner. Hanya email Google terverifikasi yang diterima; akun dicocokkan lewat `GoogleSub`, lalu email. Sesi/token hasil Google memenuhi kewajiban 2FA (BR-00.8) tanpa kode TOTP. Menautkan ke akun yang masih memakai kata sandi awal buatan admin menggantikan kata sandi itu dengan acak (`KataSandiOtomatis`) dan mencabut token Owner lama; pengguna bisa memasang kata sandi sendiri dari Keamanan akun tanpa kata sandi lama, dan hanya setelah itu boleh melepas Google. Aplikasi Owner tidak membuat akun baru lewat Google (butuh data usaha dan persetujuan S&K). Kredensial diatur di P-05 jenis `LoginSosial` (konsol, atau `lisensi:atur-integrasi LoginSosial` di edisi Lisensi); tanpa integrasi aktif tombol Google tidak tampil.

**State Machine `Langganan.Status`:**
```
Trial → Aktif → Tertunggak → Ditangguhkan → Berhenti
    ↘ Gratis (jika trial habis tanpa bayar)
```
- `Tertunggak`: grace period 7 hari. Semua fitur jalan, tampil banner.
- `Ditangguhkan`: hanya bisa login, melihat laporan, export data, dan membayar tagihan. POS terkunci, kecuali tenant memilih turun ke paket Gratis (maks 1 outlet, 1 perangkat). Data tenant **tidak pernah dihapus** selama 12 bulan setelah `Berhenti`, dan tenant selalu bisa export datanya.
- Transaksi offline yang dibuat sebelum status berubah tetap diterima saat sinkron.

**AC:**
```gherkin
Given calon pengguna mengisi form registrasi dengan data valid
When ia menekan "Daftar"
Then tenant, user owner, outlet "Outlet Utama", gudang default, dan langganan berstatus Trial 14 hari terbentuk
And ia diarahkan ke Onboarding Wizard
```
