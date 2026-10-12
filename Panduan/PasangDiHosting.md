# Memasang Payoung di Hosting (hPanel, SSH)

Panduan untuk hosting bersama (Hostinger/Niagahoster hPanel) dengan tiga domain (D-20):

| Domain | Isi | Kunci `.env` |
|---|---|---|
| `payoung.id` | situs pemasaran (D-21) | `DOMAIN_PEMASARAN=payoung.id` |
| `dashboard.payoung.id` | back-office tenant, API aplikasi kasir & pemilik, struk digital | `DOMAIN_TENANT=dashboard.payoung.id`, `APP_URL=https://dashboard.payoung.id` |
| `console.payoung.id` | Platform Pengelola (tim internal) | `PENGELOLA_DOMAIN=console.payoung.id` |

Ketiganya dilayani **satu** aplikasi Laravel (`Aplikasi/Web`). Yang boleh terlihat dari internet hanya folder `Aplikasi/Web/public`.

Contoh jalur di bawah memakai akun `u704813174`. Ganti bila berbeda.

---

## 0. PENTING: keluarkan kode dari `public_html` sekarang

Kalau seluruh repo ada di `public_html`, siapa pun bisa mengunduh PRD, kode, dan folder `.git`. Pindahkan dulu sebelum langkah lain:

```bash
mkdir -p ~/domains/payoung.id/aplikasi
cd ~/domains/payoung.id/public_html
ls -la                       # catat isinya, termasuk file tersembunyi (.git, .github, .claude, .gitignore)
shopt -s dotglob
for f in *; do
  case "$f" in
    dashboard|console) ;;            # folder subdomain dibiarkan dulu
    *) mv "$f" ../aplikasi/ ;;
  esac
done
shopt -u dotglob
ls -la                       # sekarang hanya tersisa dashboard dan console
```

## 1. Cek kemampuan server

Hasil di hosting payoung.id (26/09/2026): PHP 8.3.33, Composer 2.9.8, **MariaDB 11.8** (migrasi & seed berhasil), **tanpa Node**, fungsi `exec()` dimatikan, ekstensi `sodium` perlu diaktifkan manual di hPanel.

```bash
php -v                  # wajib 8.3 atau lebih baru
php -m | grep -Ei 'intl|bcmath|gd|zip|sodium|pdo_mysql|mbstring|fileinfo'
composer -V             # Composer 2
node -v; npm -v         # Node 20+ (untuk build tampilan). Boleh tidak ada, lihat langkah 5
mysql --version         # catat: MySQL 8 atau MariaDB
```

- Versi PHP untuk web & SSH diatur di **hPanel → Lanjutan → Konfigurasi PHP** → pilih **8.3**, lalu aktifkan ekstensi di atas.
- Kalau `php -v` di SSH masih versi lama, pakai jalur lengkap, misal `/opt/alt/php83/usr/bin/php`, di semua perintah `php` di bawah.

## 2. Buat database

hPanel → **Database → MySQL** → buat database, pengguna, dan kata sandi. Contoh nama: `u704813174_payoung`, pengguna `u704813174_payoung`. Simpan kata sandinya di tempat aman.

## 3. Pasang dependensi & `.env`

```bash
cd ~/domains/payoung.id/aplikasi/Aplikasi/Web
composer install --no-dev --optimize-autoloader
cp .env.example .env
nano .env
```

Ubah nilai berikut (sisanya biarkan):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dashboard.payoung.id
LOG_LEVEL=error

DOMAIN_PEMASARAN=payoung.id
DOMAIN_TENANT=dashboard.payoung.id
PENGELOLA_DOMAIN=console.payoung.id

DB_HOST=localhost
DB_DATABASE=u704813174_payoung
DB_USERNAME=u704813174_payoung
DB_PASSWORD=(kata sandi dari langkah 2)
```

Lalu:

```bash
php artisan key:generate
```

> Jangan pernah mengirim isi `.env` ke siapa pun atau memasukkannya ke Git.

## 4. Isi database

```bash
php artisan migrate --force
php artisan db:seed --force                  # peran, satuan, wilayah, pajak, katalog paket, template sektor
php artisan panduan-awal:siapkan-bawaan
php artisan katalog:lengkapi-fitur --kering   # setelah update: lihat fitur katalog yang belum masuk paket
php artisan katalog:lengkapi-fitur            # terapkan (aditif, aman diulang; mis. persetujuan jarak jauh di paket Bisnis)
php artisan katalog:terapkan --kering        # setelah update: lihat harga paket/add-on/fitur baru dari rilis (D-86)
php artisan penyimpanan:ukur --semua          # sekali setelah rilis v5.56: hitung pemakaian penyimpanan berkas semua tenant (selanjutnya otomatis tiap 03.10 WIB)
php artisan katalog:terapkan                 # terapkan satu kali: harga promo peluncuran + harga normal terjadwal, add-on, fitur; berlaku untuk semua tenant di tagihan berikutnya (aman diulang)
php artisan template:terapkan --kering      # setelah tarif PPN terbit: lihat template sektor yang akan terbit dari rilis (D-88)
php artisan template:terapkan               # terapkan: template baru & versi baru LANGSUNG TERBIT tanpa draf; tenant lama tidak berubah; berhenti dengan kode gagal bila ada yang tidak lolos validasi (aman diulang)
php artisan legal:siapkan-bawaan             # naskah awal dokumen legal (draf; terbitkan dari konsol setelah ditinjau)
php artisan legal:siapkan-pembaruan          # setelah update: draf versi berikutnya untuk dokumen terbit yang masih berisi naskah lama
php artisan kasir:siapkan-kategori-biaya     # setelah update (D-106): kategori biaya bawaan + akun beban untuk tenant lama; tenant baru mendapatkannya dari template sektor
php artisan situs:segarkan-bawaan            # setelah update teks situs pemasaran bawaan (mis. v5.49): perbarui konten halaman bawaan
php artisan storage:link                     # bila galat "undefined function exec()": ln -s ../storage/app/public public/storage
php artisan pengelola:buat-super-admin --nama="Nama Anda" --email="email@anda"   # akun pertama konsol; kata sandi ditanyakan
```

Kalau `migrate` gagal (terutama di MariaDB), salin pesan galatnya dan kirimkan. Jangan menjalankan `migrate:fresh`.

## 5. Build tampilan (CSS/JS)

Kalau `node -v` ada (20+):

```bash
npm ci
npm run build
```

Kalau Node tidak tersedia di server (kasus hosting payoung.id), **tidak perlu build dan zip manual lagi**.

**Saat ini `public/build` ikut di-commit** (lihat catatan di `Aplikasi/Web/.gitignore`), jadi `git pull` di
langkah 8 sudah membawa aset tampilan sekaligus dan langkah ini bisa dilewati. Karena aset dan kode berasal
dari commit yang sama, keduanya otomatis cocok.

Cara di bawah dipakai bila `public/build` kembali di-ignore. Setiap push ke `main` yang lolos CI juga
menerbitkan `public/build` sebagai aset rilis bertag tetap `aset-terbaru` (job `rilis` di
`.github/workflows/CekKepatuhan.yml`). Repo ini publik, jadi server bisa mengambilnya tanpa token:

```bash
cd ~/domains/payoung.id/aplikasi/Aplikasi/Web/public
curl -fL -o build.zip https://github.com/ahmad-affandi08/Payoung/releases/download/aset-terbaru/public-build.zip
curl -fL -o build.zip.sha256 https://github.com/ahmad-affandi08/Payoung/releases/download/aset-terbaru/public-build.zip.sha256
sed -i 's|public-build.zip|build.zip|' build.zip.sha256
sha256sum -c build.zip.sha256          # wajib: pastikan berkas utuh sebelum dipasang
rm -rf build && unzip -q build.zip && rm build.zip build.zip.sha256
```

**Cocokkan dengan kode di server.** Aset harus berasal dari commit yang sama dengan kode yang sedang jalan.
Halaman rilis `aset-terbaru` menyebut SHA commit-nya; bandingkan dengan `git rev-parse HEAD` di server. Kalau
berbeda, `git pull` dulu lalu ambil ulang asetnya.

Kalau Node **ada** di komputer lain dan Anda ingin membangun sendiri dari commit yang sama, cara lama tetap sah:
`npm ci && npm run build`, lalu salin folder `public/build` (±360 file, ±3 MB) ke server.

## 6. Hubungkan tiga domain ke folder `public`

```bash
W=~/domains/payoung.id/aplikasi/Aplikasi/Web/public
cd ~/domains/payoung.id/public_html

ls -la dashboard console         # pastikan kosong / hanya file bawaan hosting
rm -rf dashboard console
rm -f default.php index.html .htaccess

# domain utama: isi public_html = tautan ke isi folder public
for f in index.php .htaccess favicon.ico apple-touch-icon.png robots.txt build storage; do
  ln -s "$W/$f" "$f"
done

# subdomain: folder subdomain = tautan ke folder public
ln -s "$W" dashboard
ln -s "$W" console
ls -la
```

Semua domain sekarang menjalankan `index.php` yang sama. Laravel membedakan tampilannya dari nama domain (D-20).

## 7. SSL

hPanel → **Keamanan → SSL** → pasang SSL gratis untuk `payoung.id`, `www.payoung.id`, `dashboard.payoung.id`, `console.payoung.id`. Aktifkan **Force HTTPS**. Arahkan `www.payoung.id` ke `payoung.id` (hPanel → Domain → Pengalihan).

## 7a. Proksi tepercaya (wajib bila di belakang Cloudflare atau reverse proxy)

Di balik Cloudflare/proksi, aplikasi hanya tahu IP pengguna, skema `https`, dan host asli dari header `X-Forwarded-*`. Isi `PROKSI_TEPERCAYA` di `.env` supaya header itu dipercaya **hanya dari proksi kita**:

- `PROKSI_TEPERCAYA=` (kosong) = tidak ada proksi. Bila situs sebenarnya di belakang proksi, semua pengguna akan tampak berasal dari IP proksi: batas laju saling mengunci, log audit salah, dan HSTS/tautan bertanda tangan memakai skema `http`.
- `PROKSI_TEPERCAYA=*` = percaya pada pemanggil langsung. Hanya aman bila server **tidak bisa dijangkau selain lewat proksi** (firewall hanya menerima rentang Cloudflare).
- `PROKSI_TEPERCAYA=173.245.48.0/20,103.21.244.0/22,…` = daftar IP/CIDR proksi (rentang Cloudflare: https://www.cloudflare.com/ips/). Ini pilihan terbaik; perbarui bila Cloudflare mengubah rentangnya.

Setelah mengubah: `php artisan config:clear && php artisan config:cache`, lalu periksa `curl -sI https://dashboard.payoung.id/sehat` memuat `strict-transport-security`.

## 7b. Nilai lingkungan produksi (jangan menyalin contoh pengembangan)

Contoh pengembangan (`Aplikasi/Web/.env.example`) bernilai `APP_DEBUG=true` (jejak galat & data sensitif tampil), `LOG_LEVEL=debug`, dan `MAIL_MAILER=log` (email **tidak terkirim**). Untuk produksi pakai nilai di bawah, isi yang bertanda `GANTI` (audit PAY-P2-09). Aplikasi mencatat peringatan kritis di log (sekali sehari) bila produksi berjalan dengan `APP_DEBUG=true`, `MAIL_MAILER=log`, atau cookie sesi tidak aman.

```
APP_ENV=production
APP_KEY=                      # php artisan key:generate
APP_DEBUG=false
APP_URL=https://dashboard.payoung.id
DOMAIN_PEMASARAN=payoung.id
DOMAIN_TENANT=dashboard.payoung.id
PENGELOLA_DOMAIN=consol.payoung.id
PROKSI_TEPERCAYA=GANTI        # bagian 7a
TENANT_TOLAK_ID_BERBEDA=false # ubah ke true setelah log "Penulisan lintas tenant terdeteksi" bersih beberapa minggu
LOG_LEVEL=warning
DB_CONNECTION=mysql           # MySQL 8 (kontrak); MariaDB 11.8 hanya bila job CI backend-mariadb hijau
DB_DATABASE=GANTI
DB_USERNAME=GANTI
DB_PASSWORD=GANTI
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=smtp              # atau atur dari konsol (P-05 Integrasi)
```

## 8. Cron (jadwal & antrean)

hPanel → **Tingkat Lanjut → Cron Job** → pilih **Kustom** (mode "PHP" memakai `/usr/bin/php` yang belum tentu 8.3), jadwal sekali per menit (`* * * * *`):

```
/opt/alt/php83/usr/bin/php /home/u704813174/domains/payoung.id/aplikasi/Aplikasi/Web/artisan schedule:run >> /dev/null 2>&1
```

Periksa dengan `php artisan schedule:list` (jam tampil dalam UTC) dan dasbor Operasional di konsol.

Scheduler juga menjalankan antrean (`queue:work --stop-when-empty`), jadi tidak perlu proses lain.

**Pusat Unduhan (D-124):** ekspor besar disiapkan di akhir permintaan yang sama (bukan lewat antrean), jadi tidak butuh pengaturan tambahan selain `php artisan migrate --force`. Syaratnya PHP berjalan lewat **PHP-FPM atau LiteSpeed** (bawaan Hostinger) agar respons dilepas ke peramban lebih dulu; di `mod_php` peramban menunggu sampai berkas selesai. Berkas disimpan di disk `TENANT_DISK_UNDUHAN` (bawaan `local`, privat) dan dibersihkan harian oleh `unduhan:bersihkan`; pastikan `max_execution_time` tidak memutus proses terlalu cepat untuk laporan sangat besar (kode memanggil `set_time_limit(0)`, tetapi sebagian hosting mengabaikannya).

## 9. Percepat

```bash
cd ~/domains/payoung.id/aplikasi/Aplikasi/Web
php artisan optimize          # cache config, rute, view
```

Setiap kali `.env` diubah, jalankan lagi `php artisan optimize`. Kalau ada galat aneh setelah perubahan, jalankan `php artisan optimize:clear`.

## 10. Periksa

| Buka | Hasil yang diharapkan |
|---|---|
| `https://payoung.id` | beranda situs pemasaran Payoung |
| `https://payoung.id/harga` | halaman harga |
| `https://dashboard.payoung.id/masuk` | halaman masuk tenant |
| `https://console.payoung.id/masuk` | halaman masuk Platform Pengelola |
| `https://dashboard.payoung.id/sehat` | status OK |

Kalau muncul "500 Server Error": `tail -50 storage/logs/laravel.log` lalu kirimkan pesannya (tanpa kata sandi).

## 11. Langkah pertama di konsol (`console.payoung.id`)

1. Masuk dengan akun Super Admin, aktifkan 2FA (wajib, aplikasi authenticator).
2. **Tim internal → Tambah anggota:** tambahkan minimal satu orang lagi dengan kata sandi awal (tidak perlu email aktif; ia wajib menggantinya saat pertama masuk). Tarif pajak dan harga paket memakai persetujuan dua orang (pengaju ≠ peninjau).
3. **Legal:** terbitkan Syarat & Ketentuan dan Kebijakan Privasi. Pendaftaran tenant baru tertutup sampai keduanya berlaku.
4. **Katalog → Harga paket:** ajukan lalu tinjau harga. Paket baru tampil di `payoung.id/harga` setelah harganya terbit.
5. **Integrasi:** atur penyedia email (verifikasi email & reset kata sandi), CAPTCHA pendaftaran, lalu WhatsApp bila dipakai.
6. **Situs pemasaran → Pengaturan:** nomor WhatsApp, email, media sosial, tautan unduh aplikasi, kode verifikasi Google.
7. Daftarkan `https://payoung.id/peta-situs` di Google Search Console.

## 12. Memperbarui ke versi terbaru

```bash
cd ~/domains/payoung.id/aplikasi
git pull origin main                       # bila folder .git & akses GitHub tersedia; kalau tidak, unggah ulang kode
cd Aplikasi/Web
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan organisasi:siapkan-peran       # izin baru masuk ke peran bawaan semua tenant (aman diulang)
php artisan panduan-awal:siapkan-bawaan    # metode Tunai & data bawaan tenant lama (aman diulang)
npm ci && npm run build                    # atau unggah public/build hasil build di komputer sendiri
php artisan optimize
php artisan queue:restart
```

`php artisan optimize` menyimpan cache **rute beserta middleware-nya**. Kalau perubahan menyentuh `routes/`,
jalankan `php artisan optimize:clear` lebih dulu; tanpa itu rute lama yang ter-cache masih dipakai walau kodenya
sudah diperbarui.

**Kalau copy situs pemasaran ikut berubah**, jalankan juga:

```bash
php artisan situs:segarkan-bawaan          # menimpa halaman payoung.id dengan isi bawaan terbaru
```

Halaman `payoung.id` diambil dari baris `HalamanSitus` di database, dan `SiapkanHalamanSitusBawaan` sengaja tidak
menyentuh halaman yang sudah ada. Jadi perubahan copy di kode **tidak** tampil sampai perintah di atas dijalankan.
Perintah ini **menimpa**: suntingan yang dibuat lewat konsol pada halaman tersebut akan hilang, karena itu ia
meminta konfirmasi lebih dulu (pakai `--paksa` di skrip, atau `--halaman=beranda` untuk satu halaman saja).

Peran bawaan (Admin, Manajer Outlet, Supervisor, Kasir, Staf Gudang, dst.) hanya menerima izin baru setelah
`organisasi:siapkan-peran` dijalankan. Peran kustom buatan tenant tidak diubah; izin barunya dicentang sendiri di
back-office menu Pengguna › Peran.

Selama pembaruan, pengunjung bisa diberi halaman perawatan: `php artisan down`, lalu `php artisan up` setelah selesai.

## Aplikasi Kasir & Pemilik (Flutter)

Build dengan alamat server produksi:

```bash
flutter build apk --release -t lib/UtamaProduksi.dart --dart-define=ALAMAT_SERVER=https://dashboard.payoung.id/
```

Tautan unduh hasilnya diisi di konsol → Situs pemasaran → Pengaturan → Tautan unduh aplikasi.

## Pencatatan backup (alert P-11)

Skrip backup server (cron harian) harus memanggil `php artisan pengelola:catat-backup` sesudah backup berhasil, supaya dasbor
operasional konsol tahu kapan backup terakhir. Tanpa panggilan ini alert backup di dasbor akan terus merah. Perintah ini
tidak membuat backup, hanya mencatat waktunya; backup basis data dan folder `storage/app` tetap diatur di hosting.

## Rekonsiliasi pembayaran langganan

Dua jadwal berjalan lewat `schedule:run`: `tagihan:rekonsiliasi-gerbang` tiap 15 menit (pembayaran DOKU yang webhook-nya
tidak tiba) dan `tagihan:rekonsiliasi-gerbang --arsip` tiap hari 03.10 WIB (pembayaran yang masih Menunggu lewat 7 hari;
yang tidak dibayar ditutup, yang dibayar dengan jumlah berbeda tetap menunggu verifikasi manual di konsol).

