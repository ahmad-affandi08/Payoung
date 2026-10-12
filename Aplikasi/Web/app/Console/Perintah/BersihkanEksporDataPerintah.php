<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Aksi\BersihkanEksporDataKedaluwarsa;
use App\Domain\Tenant\Enum\StatusEksporDataTenant;
use App\Domain\Tenant\Model\EksporDataTenant;
use Illuminate\Console\Command;

/**
 * Harian menghapus berkas ekspor data usaha yang sudah kedaluwarsa (audit E5). Hanya tenant yang punya ekspor kedaluwarsa
 * yang dikunjungi.
 */
final class BersihkanEksporDataPerintah extends Command
{
    protected $signature = 'ekspor-data:bersihkan';

    protected $description = 'Menghapus berkas ekspor data usaha yang sudah kedaluwarsa.';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, BersihkanEksporDataKedaluwarsa $bersihkan): int
    {
        $sebelumnya = $konteks->Ambil();
        $jumlah = 0;

        try {
            foreach ($pengelola->IdTenantDenganPekerjaan(EksporDataTenant::class, fn ($q) => $q->where('Status', StatusEksporDataTenant::Siap->value)->where('KedaluwarsaPada', '<=', now())) as $idTenant) {
                $konteks->Atur($idTenant);
                $jumlah += $bersihkan->Jalankan();
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$jumlah} berkas ekspor kedaluwarsa dihapus.");

        return self::SUCCESS;
    }
}
