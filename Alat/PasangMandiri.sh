#!/usr/bin/env bash
# Satu perintah untuk memasang Payoung Mandiri (edisi Lisensi, D-35/D-36) di server/hosting pembeli.
# Skrip ini berdiri sendiri: kirim bersama paket rilis `payoung-mandiri-….tar.gz` dan berkas `*.lisensi`
# (paket pembeli sengaja tidak memuat folder Alat/). Langkah manualnya ada di Panduan/InstalasiLisensi.md.
#
#   bash PasangMandiri.sh --paket payoung-mandiri-xxx.tar.gz --lisensi tokoanda.lisensi
#
# Opsi:
#   --paket BERKAS     paket rilis Payoung Mandiri (wajib). `BERKAS.sha256` di sebelahnya, bila ada, diperiksa dulu
#   --lisensi BERKAS   berkas lisensi dari Payoung (wajib). Domain dibaca dari berkas ini
#   --tujuan FOLDER    tempat kode dipasang (bawaan ~/aplikasi). Harus belum ada atau kosong, dan di luar folder web
#   --web FOLDER       folder web domain (misal ~/public_html). Diisi tautan ke public/ bila document root tidak bisa
#                      diarahkan ke FOLDER-TUJUAN/public lewat panel hosting. File bawaan hosting yang bentrok dipindah
#                      ke FOLDER/.cadangan-bawaan-hosting/, tidak dihapus
#   --db-host H        bawaan localhost
#   --db-port P        bawaan 3306
#   --db-nama N        nama database (kosong = ditanya)
#   --db-pengguna U    pengguna database (kosong = ditanya)
#                      kata sandi database: variabel PASANG_DB_SANDI, atau ditanya (tersembunyi)
#   --cron             pasang jadwal tiap menit lewat `crontab` (bila tidak ada, barisnya dicetak untuk panel hosting)
#   --periksa          hanya periksa syarat server, berkas, dan koneksi database. Tidak mengubah apa pun
#
# PHP di hosting bersama sering bukan versi bawaan. Atur lewat variabel:
#   PHP=/opt/alt/php83/usr/bin/php bash PasangMandiri.sh --paket … --lisensi …
set -euo pipefail

PHP="${PHP:-php}"
PAKET=""
LISENSI=""
TUJUAN="$HOME/aplikasi"
WEB=""
DB_HOST="localhost"
DB_PORT="3306"
DB_NAMA=""
DB_PENGGUNA=""
PASANG_CRON=0
HANYA_PERIKSA=0
LANGKAH="persiapan"

Gagal() {
  printf '\nGAGAL: %s\n' "$*" >&2
  exit 1
}

Langkah() {
  LANGKAH="$*"
  printf '\n> %s\n' "$*"
}

trap 'printf "\nPemasangan berhenti di langkah: %s.\nPerbaiki penyebabnya, kosongkan folder tujuan dan hapus semua tabel di database, lalu ulangi.\n" "$LANGKAH" >&2' ERR

Butuh() {
  [ $# -ge 2 ] && [ -n "$2" ] || Gagal "opsi $1 butuh nilai"
}

while [ $# -gt 0 ]; do
  case "$1" in
    --paket) Butuh "$@"; PAKET="$2"; shift 2 ;;
    --lisensi) Butuh "$@"; LISENSI="$2"; shift 2 ;;
    --tujuan) Butuh "$@"; TUJUAN="$2"; shift 2 ;;
    --web) Butuh "$@"; WEB="$2"; shift 2 ;;
    --db-host) Butuh "$@"; DB_HOST="$2"; shift 2 ;;
    --db-port) Butuh "$@"; DB_PORT="$2"; shift 2 ;;
    --db-nama) Butuh "$@"; DB_NAMA="$2"; shift 2 ;;
    --db-pengguna) Butuh "$@"; DB_PENGGUNA="$2"; shift 2 ;;
    --cron) PASANG_CRON=1; shift ;;
    --periksa) HANYA_PERIKSA=1; shift ;;
    -h|--help) sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) Gagal "opsi tidak dikenal: $1 (lihat --help)" ;;
  esac
done

[ -n "$PAKET" ] || Gagal "--paket wajib diisi"
[ -n "$LISENSI" ] || Gagal "--lisensi wajib diisi"

# ---------------------------------------------------------------- 1. Periksa server & berkas
Langkah "Memeriksa server dan berkas"

command -v tar >/dev/null || Gagal "perintah tar tidak ada"
command -v "$PHP" >/dev/null || Gagal "PHP tidak ditemukan ($PHP). Isi variabel PHP dengan jalur PHP 8.3"
"$PHP" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' \
  || Gagal "PHP harus 8.3 atau lebih baru (terpasang: $("$PHP" -r 'echo PHP_VERSION;')). Atur versinya di panel hosting atau isi variabel PHP"

KURANG=""
for ekstensi in gd sodium pdo_mysql mbstring intl zip; do
  "$PHP" -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$ekstensi" || KURANG="$KURANG $ekstensi"
done
[ -z "$KURANG" ] || Gagal "ekstensi PHP belum aktif:$KURANG. Aktifkan di panel hosting (Konfigurasi PHP)"

[ -r "$PAKET" ] || Gagal "paket tidak bisa dibaca: $PAKET"
[ -r "$LISENSI" ] || Gagal "berkas lisensi tidak bisa dibaca: $LISENSI"

if [ -r "$PAKET.sha256" ]; then
  HARAPAN="$(awk '{print $1; exit}' "$PAKET.sha256")"
  NYATA="$(sha256sum "$PAKET" | awk '{print $1}')"
  [ "$HARAPAN" = "$NYATA" ] || Gagal "checksum paket tidak cocok. Unduh ulang paketnya"
  echo "Checksum paket cocok."
else
  echo "Peringatan: $PAKET.sha256 tidak ditemukan, checksum tidak diperiksa."
fi

DOMAIN="$(LISENSI="$LISENSI" "$PHP" -r '$d = json_decode((string) file_get_contents(getenv("LISENSI")), true); echo is_array($d) && is_string($d["Data"]["Domain"] ?? null) ? $d["Data"]["Domain"] : "";')"
[ -n "$DOMAIN" ] || Gagal "berkas lisensi tidak berisi domain, atau bukan berkas lisensi Payoung"
case "$DOMAIN" in
  *[!A-Za-z0-9.-]*) Gagal "domain di lisensi tidak wajar: $DOMAIN" ;;
esac
echo "Domain lisensi: $DOMAIN"

if [ -e "$TUJUAN" ] && [ -n "$(ls -A "$TUJUAN" 2>/dev/null)" ]; then
  Gagal "folder tujuan sudah berisi sesuatu: $TUJUAN. Untuk memperbarui pemasangan lama pakai langkah di Panduan/InstalasiLisensi.md bagian 7"
fi

# ---------------------------------------------------------------- 2. Database
Langkah "Memeriksa database"

[ -n "$DB_NAMA" ] || read -rp "Nama database: " DB_NAMA
[ -n "$DB_PENGGUNA" ] || read -rp "Pengguna database: " DB_PENGGUNA
if [ -z "${PASANG_DB_SANDI:-}" ]; then
  read -rsp "Kata sandi database: " PASANG_DB_SANDI
  echo
fi
[ -n "$DB_NAMA" ] && [ -n "$DB_PENGGUNA" ] || Gagal "nama dan pengguna database wajib diisi"

# Nilai lewat lingkungan, bukan argumen, supaya kata sandi tidak terlihat di daftar proses.
DB_H="$DB_HOST" DB_O="$DB_PORT" DB_N="$DB_NAMA" DB_U="$DB_PENGGUNA" DB_P="$PASANG_DB_SANDI" "$PHP" -r '
try {
    $pdo = new PDO("mysql:host=".getenv("DB_H").";port=".getenv("DB_O").";dbname=".getenv("DB_N").";charset=utf8mb4", getenv("DB_U"), getenv("DB_P"), [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query("SHOW TABLES")->fetch() !== false) { fwrite(STDERR, "Database tidak kosong. Pakai database baru agar tidak tercampur.\n"); exit(2); }
} catch (Throwable $e) { fwrite(STDERR, "Tidak bisa terhubung ke database: ".$e->getMessage()."\n"); exit(1); }
' || Gagal "database belum siap (lihat pesan di atas)"
echo "Database terhubung dan kosong."

if [ "$HANYA_PERIKSA" -eq 1 ]; then
  echo
  echo "Semua syarat terpenuhi. Jalankan ulang tanpa --periksa untuk memasang."
  exit 0
fi

# ---------------------------------------------------------------- 3. Pasang kode
Langkah "Mengekstrak paket ke $TUJUAN"
mkdir -p "$TUJUAN"
TUJUAN="$(cd "$TUJUAN" && pwd)"
tar -xzf "$PAKET" -C "$TUJUAN"
[ -f "$TUJUAN/artisan" ] && [ -f "$TUJUAN/vendor/autoload.php" ] && [ -f "$TUJUAN/public/build/manifest.json" ] \
  || Gagal "isi paket tidak lengkap. Pastikan memakai paket payoung-mandiri-… dari Payoung"
grep -q "Lisensi" "$TUJUAN/bootstrap/EdisiTerkunci.php" 2>/dev/null \
  || Gagal "ini bukan paket Payoung Mandiri (edisi Lisensi tidak terkunci). Minta paket payoung-mandiri-… ke Payoung"

Langkah "Menulis .env"
cd "$TUJUAN"
cp .env.example .env
APP_DOMAIN="$DOMAIN" DB_H="$DB_HOST" DB_O="$DB_PORT" DB_N="$DB_NAMA" DB_U="$DB_PENGGUNA" DB_P="$PASANG_DB_SANDI" "$PHP" -r '
$nilai = [
    "APP_ENV" => "production",
    "APP_DEBUG" => "false",
    "APP_URL" => "https://".getenv("APP_DOMAIN"),
    "LOG_LEVEL" => "warning",
    "DOMAIN_PEMASARAN" => "",
    "DOMAIN_TENANT" => "",
    "DB_CONNECTION" => "mysql",
    "DB_HOST" => getenv("DB_H"),
    "DB_PORT" => getenv("DB_O"),
    "DB_DATABASE" => getenv("DB_N"),
    "DB_USERNAME" => getenv("DB_U"),
    "DB_PASSWORD" => getenv("DB_P"),
    "SESSION_SECURE_COOKIE" => "true",
];
$baris = file(".env.example", FILE_IGNORE_NEW_LINES);
foreach ($nilai as $kunci => $isi) {
    $tulis = $kunci."=\"".addcslashes($isi, "\\\"$")."\"";
    $ada = false;
    foreach ($baris as $i => $b) {
        if (str_starts_with($b, $kunci."=")) { $baris[$i] = $tulis; $ada = true; }
    }
    if (! $ada) { $baris[] = $tulis; }
}
file_put_contents(".env", implode("\n", $baris)."\n");
'
chmod 600 .env
unset PASANG_DB_SANDI

Langkah "Membuat kunci aplikasi dan mengisi database"
"$PHP" artisan key:generate --force
"$PHP" artisan migrate --force
# exec() sering dimatikan di hosting bersama, jadi storage:link bisa gagal; tautan manual setara.
"$PHP" artisan storage:link || ln -s ../storage/app/public public/storage

Langkah "Memasang lisensi (akan menanyakan nama usaha dan data Owner)"
"$PHP" artisan lisensi:pasang "$(cd "$(dirname "$LISENSI")" && pwd)/$(basename "$LISENSI")"

Langkah "Mempercepat aplikasi"
"$PHP" artisan optimize

# ---------------------------------------------------------------- 4. Folder web & cron (opsional)
if [ -n "$WEB" ]; then
  Langkah "Menautkan $WEB ke folder public"
  mkdir -p "$WEB"
  CADANGAN="$WEB/.cadangan-bawaan-hosting"
  for f in $(cd "$TUJUAN/public" && ls -A) index.html default.php; do
    sasaran="$WEB/$f"
    if [ -L "$sasaran" ] && [ "$(readlink "$sasaran")" = "$TUJUAN/public/$f" ]; then continue; fi
    if [ -e "$sasaran" ] || [ -L "$sasaran" ]; then
      mkdir -p "$CADANGAN"
      mv "$sasaran" "$CADANGAN/"
      echo "Dipindah ke $CADANGAN: $f"
    fi
    [ -e "$TUJUAN/public/$f" ] && ln -s "$TUJUAN/public/$f" "$sasaran"
  done
fi

PHP_LENGKAP="$(command -v "$PHP")"
BARIS_CRON="* * * * * $PHP_LENGKAP $TUJUAN/artisan schedule:run >> /dev/null 2>&1"
CRON_TERPASANG=0
if [ "$PASANG_CRON" -eq 1 ]; then
  Langkah "Memasang jadwal (cron)"
  if command -v crontab >/dev/null; then
    CRON_LAMA="$(crontab -l 2>/dev/null | grep -vF "$TUJUAN/artisan schedule:run" || true)"
    printf '%s\n%s\n' "$CRON_LAMA" "$BARIS_CRON" | sed '/^$/d' | crontab -
    CRON_TERPASANG=1
  else
    echo "Perintah crontab tidak ada; pasang lewat panel hosting (lihat di bawah)."
  fi
fi

# ---------------------------------------------------------------- 5. Ringkasan
trap - ERR
echo
echo "=== Selesai ==="
"$PHP" artisan lisensi:info || true
echo
echo "Yang masih perlu dilakukan:"
if [ -z "$WEB" ]; then
  echo "  1. Panel hosting: arahkan document root $DOMAIN ke $TUJUAN/public (atau jalankan ulang bagian web dengan --web)."
else
  echo "  1. Pastikan $DOMAIN di panel hosting menunjuk ke $WEB."
fi
echo "  2. Aktifkan SSL (Let's Encrypt) untuk $DOMAIN dan nyalakan Force HTTPS."
if [ "$CRON_TERPASANG" -eq 0 ]; then
  echo "  3. Pasang cron tiap menit (panel hosting, mode Kustom):"
  echo "       $BARIS_CRON"
else
  echo "  3. Cron tiap menit sudah dipasang."
fi
echo "  4. Buka https://$DOMAIN/masuk, masuk sebagai Owner, aktifkan verifikasi dua langkah."
echo "  5. Pengaturan > Email & WhatsApp server: pilih penyedia lalu Uji & aktifkan."
echo "  6. Pengaturan > Perangkat: buat kode aktivasi kasir (QR-nya sudah membawa alamat server ini)."
echo "  7. Atur cadangan harian untuk database dan folder $TUJUAN/storage/app."
echo "  8. Simpan berkas lisensi dan cadangan di luar folder public."
