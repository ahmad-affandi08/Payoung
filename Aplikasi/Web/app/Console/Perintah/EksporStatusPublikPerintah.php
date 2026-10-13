<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Tenant\Kueri\StatusPlatformPublik;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * P-11 PGL-21: menulis `status.json` (kondisi komponen, insiden aktif, riwayat) dan `index.html` statis ke satu folder,
 * untuk di-hosting terpisah (`status.{domain}`) supaya halaman status tetap hidup saat server utama bermasalah. Tujuan
 * dari argumen atau `config('pengelola.TujuanEksporStatus')`; dijadwalkan tiap menit hanya bila tujuan itu diatur.
 */
final class EksporStatusPublikPerintah extends Command
{
    protected $signature = 'status:ekspor {tujuan? : Folder tujuan (bawaan: STATUS_TUJUAN_EKSPOR)}';

    protected $description = 'Menulis status.json dan index.html statis halaman status publik (P-11, PGL-21).';

    public function handle(StatusPlatformPublik $status): int
    {
        $argumen = $this->argument('tujuan');
        $konfigurasi = config('pengelola.TujuanEksporStatus');
        $tujuan = is_string($argumen) && $argumen !== '' ? $argumen : (is_string($konfigurasi) && $konfigurasi !== '' ? $konfigurasi : null);

        if ($tujuan === null) {
            $this->error('Tentukan folder tujuan: argumen {tujuan} atau STATUS_TUJUAN_EKSPOR.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($tujuan);
        StatusPlatformPublik::LupakanTembolok();
        // Tulis lewat berkas sementara lalu ganti, supaya pembaca tidak pernah menerima JSON setengah jadi.
        $sementara = $tujuan.'/status.json.tmp';
        File::put($sementara, json_encode($status->Ambil(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::move($sementara, $tujuan.'/status.json');
        File::copy(resource_path('status-statis/index.html'), $tujuan.'/index.html');

        $this->info("Status diekspor ke {$tujuan}.");

        return self::SUCCESS;
    }
}
