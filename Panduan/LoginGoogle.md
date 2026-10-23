# Panduan: Daftar & Masuk dengan Google (D-57)

Pemilik toko bisa mendaftar, masuk, dan menautkan akun dengan Google. Akun yang masuk lewat Google **menggantikan 2FA** (kode TOTP tidak diminta; Google yang memegang verifikasi dua langkah). Masuk email + kata sandi tetap berlaku dan tetap memakai 2FA bila aktif atau diwajibkan paket.

Fitur tidak aktif sampai Client ID Google diisi. Tanpa itu tombol "Masuk dengan Google" tidak tampil di web maupun Aplikasi Owner.

## 1. Siapkan di Google Cloud Console

1. Buka <https://console.cloud.google.com/>, pilih atau buat proyek, lalu **APIs & Services → OAuth consent screen**. Isi nama aplikasi (Payoung), email dukungan, domain, lalu tautan Kebijakan Privasi dan Syarat & Ketentuan. Terbitkan ke **Production** agar semua akun Google bisa masuk.
2. **Credentials → Create credentials → OAuth client ID → Web application.**
   - **Authorized redirect URI:** `https://<domain dashboard>/masuk/google/panggilan-balik`
     (cloud: `https://dashboard.payoung.id/masuk/google/panggilan-balik`; edisi Lisensi: domain server pembeli).
   - Catat **Client ID** dan **Client Secret**.
3. Untuk **Aplikasi Owner** buat dua Client ID lagi di proyek yang sama:
   - **Android:** package name `id.payoung.owner` dan sidik jari SHA-1 keystore rilis (dan SHA-1 Play App Signing setelah rilis ke Play Store).
   - **iOS:** bundle ID aplikasi Owner. Tambahkan *reversed client ID* sebagai URL scheme di `Info.plist` (`CFBundleURLTypes`) dan `GIDClientID`.
   Client ID tersebut dimasukkan ke bidang **Client ID tambahan** (poin 2) agar `aud` token dari aplikasi diterima server.

## 2. Isi di Payoung

- **Cloud (konsol Pengelola):** Integrasi → **Masuk dengan Google** → isi *Client ID*, *Client ID tambahan* (pisahkan koma bila lebih dari satu), *Client Secret* → **Uji koneksi** → aktifkan.
- **Edisi Lisensi (Payoung Mandiri):** `php artisan lisensi:atur-integrasi LoginSosial`, lalu `php artisan optimize:clear`.

Aplikasi Owner memakai **Client ID web** sebagai `serverClientId`; nilainya diambil dari `GET /api/pemilik/v1/masuk/google/konfigurasi`, jadi tidak perlu dikompilasi ke aplikasi.

## 3. Perilaku yang perlu diketahui

| Situasi | Hasil |
|---|---|
| Email Google belum terverifikasi | Ditolak. |
| Akun Payoung dengan email sama sudah ada | Ditautkan otomatis lewat email terverifikasi, bukan membuat akun baru. |
| Akun masih memakai kata sandi awal dari admin (D-22) | Kata sandi itu diganti acak dan token Owner dicabut; pengguna memasang kata sandi sendiri di Keamanan akun tanpa kata sandi lama. |
| Google belum ditautkan, masuk dari Aplikasi Owner | `404 AkunGoogleBelumTerdaftar`. Daftar atau tautkan dari web dulu. |
| Melepas Google | Hanya bila pengguna sudah punya kata sandi sendiri (Keamanan akun → Lepas Google). |
| Kewajiban 2FA paket Bisnis | Terpenuhi oleh sesi/token Google. Banner "Aktifkan verifikasi dua langkah" tidak tampil. |
| Akun Platform Pengelola dan PIN kasir | Tidak berubah (kata sandi + 2FA; PIN). |

## 4. Uji setelah dipasang

1. Buka `/masuk` di jendela samaran: tombol Google tampil, pilih akun, masuk ke dasbor tanpa kode 2FA.
2. `/daftar`: "Daftar dengan Google" → lengkapi nama usaha, sektor, setujui S&K.
3. Keamanan akun: panel Google menampilkan status tertaut dan tombol lepas.
4. Aplikasi Owner: layar masuk menampilkan "Masuk dengan Google" lalu dasbor terbuka.

Konfigurasi native Android/iOS dan uji dengan akun Google sungguhan dilakukan pada saat rilis aplikasi.
