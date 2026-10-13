<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Organisasi\Aksi\RapikanNamaGudangBawaan;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use Illuminate\Console\Command;

/** Mengganti nama gudang bawaan lama "Gudang Outlet Utama" di semua tenant dengan "Gudang {nama outlet}" (idempoten). */
final class RapikanNamaGudangBawaanPerintah extends Command
{
    protected $signature = 'organisasi:rapikan-nama-bawaan';

    protected $description = 'Mengganti nama gudang bawaan lama "Gudang Outlet Utama" menjadi "Gudang {nama outlet}".';

    public function handle(KeanggotaanPengguna $keanggotaan, RapikanNamaGudangBawaan $rapikan): int
    {
        $daftar = $keanggotaan->AmbilSemuaIdTenant();
        $diganti = 0;

        foreach ($daftar as $idTenant) {
            $diganti += $rapikan->Jalankan($idTenant);
        }

        $this->info(count($daftar)." tenant diperiksa, {$diganti} gudang diganti namanya.");

        return self::SUCCESS;
    }
}
