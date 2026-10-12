<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Layanan\PengukurPenyimpanan;
use App\Domain\Tenant\Model\PemakaianPenyimpanan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Console\Command;

/**
 * Mengukur ulang pemakaian penyimpanan berkas tenant (audit E2). Malam hari hanya tenant yang sudah punya baris
 * `PemakaianPenyimpanan` (pernah mengunggah berkas yang dibatasi paket) yang diukur; `--semua` mengukur setiap tenant,
 * dijalankan sekali setelah rilis ini supaya berkas lama ikut terhitung.
 */
final class UkurPenyimpananTenantPerintah extends Command
{
    protected $signature = 'penyimpanan:ukur {--semua : Ukur semua tenant, bukan hanya yang sudah punya catatan}';

    protected $description = 'Mengukur ulang pemakaian penyimpanan berkas per tenant untuk batas paket.';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, PengukurPenyimpanan $pengukur): int
    {
        $sebelumnya = $konteks->Ambil();
        $diukur = 0;

        try {
            $daftar = $this->option('semua')
                ? array_map('intval', Tenant::query()->orderBy('Id')->pluck('Id')->all())
                : $pengelola->IdTenantDenganPekerjaan(PemakaianPenyimpanan::class, fn ($q) => $q);

            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);
                $pengukur->Segarkan($idTenant);
                $diukur++;
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$diukur} tenant diukur penyimpanannya.");

        return self::SUCCESS;
    }
}
