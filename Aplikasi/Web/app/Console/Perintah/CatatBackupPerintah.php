<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Operasional\Aksi\CatatHasilBackup;
use App\Domain\Pengelola\Operasional\Data\DataCatatanBackup;
use App\Domain\Pengelola\Operasional\Enum\HasilBackup;
use App\Domain\Pengelola\Operasional\Enum\JenisCatatanBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Dipanggil skrip backup server (§14.4 03:00 WIB, §14.5) setelah backup atau uji restore selesai, misal:
 *
 *     php artisan pengelola:catat-backup --hasil=Berhasil --ukuran=52428800 --lokasi=backup/2026-09-24.sql.gz
 *     php artisan pengelola:catat-backup --jenis=UjiRestore --hasil=Gagal --keterangan="Checksum tidak cocok"
 */
final class CatatBackupPerintah extends Command
{
    protected $signature = 'pengelola:catat-backup
        {--jenis=Backup : Backup atau UjiRestore}
        {--hasil=Berhasil : Berhasil atau Gagal}
        {--selesai= : Waktu selesai ISO-8601 (bawaan: sekarang)}
        {--ukuran= : Ukuran berkas dalam byte}
        {--lokasi= : Path/nama objek hasil backup, tanpa kredensial}
        {--keterangan= : Catatan singkat, misal pesan masalah}';

    protected $description = 'Mencatat hasil backup atau uji restore ke dasbor operasional (P-11).';

    public function handle(CatatHasilBackup $catat): int
    {
        $isian = [
            'Jenis' => $this->option('jenis'),
            'Hasil' => $this->option('hasil'),
            'Selesai' => $this->option('selesai'),
            'Ukuran' => $this->option('ukuran'),
            'Lokasi' => $this->option('lokasi'),
            'Keterangan' => $this->option('keterangan'),
        ];
        $validasi = Validator::make($isian, [
            'Jenis' => ['required', Rule::enum(JenisCatatanBackup::class)],
            'Hasil' => ['required', Rule::enum(HasilBackup::class)],
            'Selesai' => ['nullable', 'date'],
            'Ukuran' => ['nullable', 'integer', 'min:0'],
            'Lokasi' => ['nullable', 'string', 'max:500'],
            'Keterangan' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validasi->fails()) {
            foreach ($validasi->errors()->all() as $pesan) {
                $this->error($pesan);
            }

            return self::FAILURE;
        }

        try {
            $catatan = $catat->Jalankan(new DataCatatanBackup(
                jenis: JenisCatatanBackup::from((string) $isian['Jenis']),
                hasil: HasilBackup::from((string) $isian['Hasil']),
                selesaiPada: is_string($isian['Selesai']) && $isian['Selesai'] !== '' ? Carbon::parse($isian['Selesai']) : now(),
                ukuranByte: is_numeric($isian['Ukuran']) ? (int) $isian['Ukuran'] : null,
                lokasi: is_string($isian['Lokasi']) && $isian['Lokasi'] !== '' ? $isian['Lokasi'] : null,
                keterangan: is_string($isian['Keterangan']) && $isian['Keterangan'] !== '' ? $isian['Keterangan'] : null,
            ));
        } catch (PelanggaranAturanBisnis $galat) {
            $this->error($galat->getMessage());

            return self::FAILURE;
        }

        $this->info("{$catatan->Jenis->AmbilLabel()} {$catatan->Hasil->value} tercatat.");

        return self::SUCCESS;
    }
}
