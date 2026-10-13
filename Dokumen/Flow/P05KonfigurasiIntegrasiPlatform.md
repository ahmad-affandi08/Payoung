<!-- DIBUAT OTOMATIS dari PRD.md oleh Alat/PecahPrd.py. JANGAN DIEDIT LANGSUNG: ubah PRD.md lalu jalankan ulang skrip. -->

### P-05 · Konfigurasi Integrasi Platform

**Tujuan:** Semua layanan pihak ketiga milik platform terhubung, aman, dan terpantau.
**Aktor:** Teknis, Super Admin.

| Integrasi | Milik | Keterangan |
|---|---|---|
| Payment gateway untuk **tagihan langganan** | Platform | Akun merchant {{APP}} sendiri (P-08) |
| Payment gateway untuk **transaksi tenant** | Tenant | Kredensial per tenant, diisi tenant di back-office. Pengelola hanya mengatur daftar gateway yang didukung |
| Email transaksional (SMTP/layanan email) | Platform | Verifikasi, tagihan, notifikasi |
| WhatsApp BSP | Platform | Nomor pengirim, **status persetujuan template pesan** dari Meta |
| FCM (push Android & iOS) | Platform | Service account, sertifikat APNs |
| Penyimpanan objek | Platform | File installer, lampiran besar, backup |
| CAPTCHA, Sentry, uptime monitor | Platform | Anti-spam, error tracking, pemantauan |

**Langkah:** input kredensial (langsung terenkripsi) → **tes koneksi** → aktifkan per lingkungan (staging/produksi) → pemantauan berkala (P-11) → **rotasi kunci** terjadwal.

**Aturan Bisnis:**
- BR-P05.1 Kredensial tidak pernah ditampilkan ulang secara utuh (hanya 4 karakter terakhir).
- BR-P05.2 Perubahan kredensial produksi hanya oleh Super Admin/Teknis, wajib alasan, tercatat di audit.
- BR-P05.3 Kegagalan tes koneksi berkala memicu alert ke Teknis dan banner status di Platform Pengelola. Uji berkala berjalan tiap jam untuk integrasi aktif di lingkungan server itu; alert email dikirim sekali saat status berubah dari berhasil menjadi gagal (bukan tiap jam), ke anggota Teknis aktif (bila tidak ada, ke Super Admin).
- BR-P05.4 Konfigurasi baru atau yang kredensial/pengaturannya berubah berstatus `BelumDiuji` dan tidak bisa diaktifkan sebelum tes koneksi berhasil. Konfigurasi aktif yang diubah langsung nonaktif sampai diuji ulang, sehingga sistem tidak pernah memakai kredensial yang belum terbukti. Perubahan apa pun pada lingkungan Produksi (simpan, aktifkan, nonaktifkan) wajib alasan.
- BR-P05.5 Setiap konfigurasi punya masa rotasi (default 90 hari sejak kredensial terakhir diganti). Lewat masa itu, banner Platform Pengelola mengingatkan Teknis untuk mengganti kunci.
- BR-P05.6 Kredensial hanya didekripsi di server saat dipakai atau diuji; halaman, log audit, dan respons tidak pernah memuatnya. Log audit mencatat nama kolom kredensial yang berubah, bukan nilainya. Mengosongkan kolom kredensial saat menyunting berarti nilai lama dipertahankan.

**Rincian katalog penyedia P-05 (v2.04, atas permintaan pemilik produk):**
- **Pilih penyedia:** setiap jenis integrasi punya satu konfigurasi per lingkungan (Staging/Produksi) dengan satu penyedia terpilih dari katalog `PenyediaIntegrasi`. Formulir konsol menampilkan daftar penyedia, bidang pengaturan & kredensial milik penyedia itu (bidang opsional ditandai), nilai bawaan yang terisi otomatis, dan keterangan penyedia. **Ganti penyedia** membuang kredensial lama (wajib diisi ulang), mengembalikan status ke Belum diuji dan menonaktifkan konfigurasi sampai lolos uji (BR-P05.4); audit mencatat penyedia. Konfigurasi lama (sebelum v2.04) memakai penyedia bawaan jenisnya.
- **Email transaksional (15 penyedia, semua lewat relay SMTP resmi penyedia):** SMTP umum/hosting, Amazon SES (region Jakarta bawaan), Mailgun, Twilio SendGrid, Brevo, Postmark, Resend, Mailjet, Mailtrap Email Sending, Zoho ZeptoMail, Elastic Email, Gmail/Google Workspace (sandi aplikasi), Microsoft 365, Zoho Mail, Hostinger. Satu jalur kirim (mailer SMTP Laravel) dan satu penguji (login SMTP tanpa mengirim).
- **Gerbang pembayaran (QRIS dinamis) — jenis baru** *(sejarah katalog awal; sejak v5.12 / D-82 hanya DOKU yang aktif, adaptor lain dihapus)*: Midtrans (Core API QRIS, notifikasi SHA512), Xendit (QR Codes API DYNAMIC, token callback), Tripay (closed payment QRIS, HMAC-SHA256), Duitku (API v2, kanal QRIS SP/NQ/GQ/SQ, tanda tangan MD5 sesuai protokol Duitku), iPaymu (direct payment QRIS, HMAC; notifikasi tanpa tanda tangan selalu dikonfirmasi ulang ke iPaymu), DOKU (Checkout — halaman bayar QRIS, tanda tangan HMACSHA256). Mode Sandbox/Produksi per konfigurasi. Uji koneksi memanggil endpoint yang aman (saldo, daftar kanal, atau status pesanan fiktif) tanpa membuat transaksi. Adaptor runtime `App\Domain\Integrasi\GerbangPembayaran` (port `GerbangPembayaran`: `BuatQris`, `CekStatus`, `UraiWebhook`) dibaca dari `config('integrasi.GerbangPembayaran')`.
- **WhatsApp — jenis baru:** **resmi** WhatsApp Cloud API (Meta Graph `/{phone-number-id}/messages`; di luar jendela 24 jam wajib templat yang disetujui Meta, nama templat struk diisi di konsol) dan **tidak resmi** berbasis WhatsApp Web: Fonnte, Wablas (domain server + secret key opsional), StarSender, Watzap. Penyedia tidak resmi ditandai peringatan: murah dan mudah, tetapi nomor bisa diblokir WhatsApp bila mengirim massal; pakai nomor khusus. Adaptor runtime `App\Domain\Integrasi\Whatsapp` (port `PengirimWhatsapp`), nomor Indonesia dirapikan ke format 62.
- **Kerahasiaan:** pesan hasil uji & galat adaptor disaring dari kredensial (BR-P05.6).
- **Catatan regulasi:** diputuskan di **D-19** (v2.06): gerbang pembayaran tidak lagi satu akun platform, melainkan akun merchant milik tiap toko. Lihat rincian v2.06 di bawah.

**Rincian gerbang pembayaran milik toko (v2.06, D-19):**
- **Konsol platform** tidak lagi menyimpan kredensial gerbang pembayaran (menyimpan jenis `GerbangPembayaran` ditolak `GerbangPerTenant`; slot lama diabaikan). Halaman integrasi menampilkan **katalog penyedia untuk toko** (`KatalogGerbangPembayaran`, tanpa baris = diizinkan): per penyedia status Bisa dipilih/Dilarang, jumlah toko terhubung/aktif/uji gagal, dan notifikasi webhook sah/ditolak 24 jam terakhir (dibaca lewat `KonteksPengelola::JalankanLintasTenant`, hanya kolom status). **Melarang** penyedia wajib alasan (`LogAuditPengelola` `integrasi.gerbang.larang`/`izinkan`); toko yang memakainya tidak bisa membuat tagihan QRIS baru (409 `GerbangBelumAktif`), tagihan lama tetap bisa dicek/dilunasi.
- **Back-office toko** `/kelola/pembayaran/gerbang` (menu Kasir › Gerbang pembayaran; izin baru `pembayaran.gerbang.atur`, bawaan Pemilik & Admin): pilih penyedia yang diizinkan, lingkungan Sandbox/Produksi, bidang pengaturan & kredensial penyedia. Tabel `GerbangPembayaranTenant` (satu per toko): Penyedia, Lingkungan, Pengaturan, **Kredensial terenkripsi** (hanya 4 karakter terakhir tampil, BR-P05.1; kosong saat menyunting = dipertahankan; ganti penyedia = wajib diisi ulang), StatusUji (`BelumDiuji/Berhasil/Gagal`), PesanUji (disaring dari kredensial), Aktif, TokenWebhook (`{IdTenant basis-36}-{acak 40}`), WebhookDiterimaPada/WebhookDitolakPada. Pola BR-P05.4: perubahan isian = Belum diuji & nonaktif; **Aktifkan** hanya setelah **Uji koneksi** berhasil (tanpa transaksi). Log audit toko `gerbang-pembayaran.*` hanya memuat nama bidang kredensial yang diganti. Halaman menampilkan URL webhook untuk disalin ke dasbor penyedia dan catatan bahwa dana langsung ke rekening toko & MDR QRIS mengikuti ketentuan BI.
- **Runtime:** tagihan QRIS memakai gerbang aktif toko (penyedia masih diizinkan); cek status memakai konfigurasi toko selama penyedianya sama dengan penyedia tagihan. **Webhook** `POST /webhook/{penyedia}/{tokenWebhook}`: token menetapkan toko (tanpa query lintas tenant), penyedia harus sama & aktif (404), tanda tangan diverifikasi dengan kredensial toko (401), dan nomor pesanan harus milik toko pemilik webhook (notifikasi sah dari toko lain = `Diterima: false`). Rute lama `/webhook/{penyedia}` selalu 404 `PenyediaTidakAktif`.
- Panduan Awal metode pembayaran menautkan ke halaman gerbang bila belum aktif. Kontrak API POS `/api/pos/v1/qris*` tidak berubah.
- **Tahap berikutnya (belum; diubah D-82):** opsi sub-merchant untuk toko yang belum punya akun merchant kini lewat DOKU (sub account / Partner API, D-83), bukan xenPlatform/Midtrans.


**Lingkup Fase 0 (PGL-05):** Email (SMTP), CAPTCHA (Cloudflare Turnstile, BR-00.4), penyimpanan objek (S3-compatible). Konfigurasi dengan lingkungan yang sama dengan server (Staging untuk server non-produksi) diterapkan ke aplikasi saat berjalan. WhatsApp BSP, Sentry/uptime, dan daftar gateway tenant ditambahkan bersama flow pemakainya; **FCM sudah masuk sejak v2.69** (jenis `Push`, penyedia `Fcm`) bersama OWN-03, dan **gateway billing sejak v2.70** (jenis `GerbangBilling`, penyedia `MidtransBilling`) bersama P-08.

**Masuk dengan Google (D-57, v4.70):** jenis integrasi `LoginSosial` dengan penyedia `Google` (Client ID web, Client ID tambahan untuk aplikasi Android/iOS, Client Secret terenkripsi; uji koneksi menukar kode palsu dan menganggap `invalid_grant` sebagai kredensial sah). Tidak ada penyedia cadangan; bila nonaktif, tombol Google tidak tampil di web dan Aplikasi Owner. Panduan: `Panduan/LoginGoogle.md`.
