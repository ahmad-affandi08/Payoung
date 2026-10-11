<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Kasir\Aksi\SiapkanKategoriBiayaBawaan;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use Illuminate\Console\Command;

/**
 * D-106: kategori kas keluar untuk biaya harian (Listrik, Transport, dst.) beserta akun bebannya bagi tenant yang
 * terdaftar sebelum fitur ini. Aditif & idempoten; jalankan sekali saat deploy.
 */
final class SiapkanKategoriBiayaPerintah extends Command
{
    protected $signature = 'kasir:siapkan-kategori-biaya';

    protected $description = 'Menyiapkan kategori kas keluar untuk biaya umum (listrik, transport, dll.) bagi tenant lama (D-106).';

    public function handle(KeanggotaanPengguna $keanggotaan, SiapkanKategoriBiayaBawaan $siapkan): int
    {
        $daftar = $keanggotaan->AmbilSemuaIdTenant();
        $jumlah = 0;

        foreach ($daftar as $idTenant) {
            $jumlah += $siapkan->JalankanUntukTenant($idTenant);
        }

        $this->info(count($daftar)." tenant diperiksa, {$jumlah} kategori ditambahkan.");

        return self::SUCCESS;
    }
}
