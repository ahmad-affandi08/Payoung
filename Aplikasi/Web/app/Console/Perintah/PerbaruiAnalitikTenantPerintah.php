<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\Tenant\Aksi\PerbaruiAnalitikTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** P-07 PGL-24: bahan analitik platform harian; `--sejak=YYYY-MM` mengisi ulang riwayat bulanan (jadwal di routes/console.php). */
final class PerbaruiAnalitikTenantPerintah extends Command
{
    protected $signature = 'analitik:perbarui {--sejak= : Isi ulang aktivitas bulanan mulai bulan ini (YYYY-MM)}';

    protected $description = 'Memperbarui bahan analitik platform: funnel, adopsi fitur, dan aktivitas bulanan (P-07, PGL-24).';

    public function handle(PerbaruiAnalitikTenant $perbarui): int
    {
        $sejak = null;
        $opsi = $this->option('sejak');

        if (is_string($opsi) && $opsi !== '') {
            try {
                $sejak = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $opsi) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $opsi) : null;
            } catch (Throwable) {
                $sejak = null;
            }

            if (! $sejak instanceof CarbonImmutable) {
                $this->error('Format --sejak harus YYYY-MM, misal 2026-01.');

                return self::FAILURE;
            }
        }

        $hasil = $perbarui->Jalankan($sejak);
        $this->info("{$hasil['Tenant']} tenant diproses untuk {$hasil['Bulan']} bulan.");

        return self::SUCCESS;
    }
}
