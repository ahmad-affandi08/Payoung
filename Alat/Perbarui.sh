#!/usr/bin/env bash
# Satu perintah untuk memperbarui server (hosting bersama / VPS): tarik kode, pasang dependensi, lalu `rilis:pasang`
# (migrasi + penyelarasan data tenant lama + cache). Jalankan dari mana saja di server:
#
#   bash Alat/Perbarui.sh                 # pembaruan biasa
#   bash Alat/Perbarui.sh --dengan-situs  # sekaligus menimpa halaman situs pemasaran dengan teks bawaan terbaru
#   bash Alat/Perbarui.sh --kering        # hanya lihat rencana, tidak mengubah apa pun
#
# PHP/Composer di hosting bersama sering bukan versi bawaan. Atur sekali di ~/.bashrc atau awali perintah:
#   PHP=/opt/alt/php83/usr/bin/php bash Alat/Perbarui.sh
set -euo pipefail

PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
AKAR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$AKAR"
if [ -d .git ] && [[ " $* " != *" --kering "* ]]; then
  echo "> git pull"
  git pull --ff-only origin main
fi

cd "$AKAR/Aplikasi/Web"
if [[ " $* " != *" --kering "* ]]; then
  echo "> composer install"
  "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
fi

"$PHP" artisan rilis:pasang "$@"
