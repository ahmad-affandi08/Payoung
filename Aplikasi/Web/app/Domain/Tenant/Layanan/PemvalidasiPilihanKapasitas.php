<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;

/**
 * Memeriksa pilihan Owner tentang outlet dan anggota yang tetap aktif saat paket mengecil (BR-P04.4): jumlahnya tidak
 * boleh melebihi batas paket tujuan, Pemilik selalu ikut, dan tanpa kelebihan pilihan tidak diperlukan.
 */
final class PemvalidasiPilihanKapasitas
{
    /**
     * Memeriksa pilihan outlet/anggota. Tanpa kelebihan pada outlet dan pengguna, pilihan tidak diperlukan (null).
     *
     * @param  array{Batas: array<string, int|null>, Lebih: array<string, int>}  $kelebihan
     * @param  list<array{Uuid: string, Nama: string, JumlahPerangkat: int}>  $outletAktif
     * @param  list<array{Uuid: string, Nama: string, Email: string|null, Pemilik: bool}>  $anggotaAktif
     * @param  list<string>  $uuidOutlet
     * @param  list<string>  $uuidAnggota
     * @return array{0: list<string>|null, 1: list<string>|null}
     */
    public function Periksa(array $kelebihan, array $outletAktif, array $anggotaAktif, array $uuidOutlet, array $uuidAnggota): array
    {
        $outletTetap = null;
        $anggotaTetap = null;

        if ($kelebihan['Lebih']['Outlet'] > 0) {
            $batas = (int) $kelebihan['Batas']['Outlet'];
            $outletTetap = array_values(array_unique(array_intersect($uuidOutlet, array_column($outletAktif, 'Uuid'))));

            if ($outletTetap === [] || count($outletTetap) > $batas) {
                throw new PelanggaranAturanBisnis('PilihanOutletTidakSah', "Pilih 1 sampai {$batas} outlet yang tetap aktif. Outlet lain diarsipkan (datanya tetap ada).", 'UuidOutletTetap');
            }
        }

        if ($kelebihan['Lebih']['Pengguna'] > 0) {
            $batas = (int) $kelebihan['Batas']['Pengguna'];
            $pemilik = array_column(array_filter($anggotaAktif, fn (array $a): bool => $a['Pemilik']), 'Uuid');
            $anggotaTetap = array_values(array_unique(array_merge($pemilik, array_intersect($uuidAnggota, array_column($anggotaAktif, 'Uuid')))));

            if (count($anggotaTetap) > $batas) {
                throw new PelanggaranAturanBisnis('PilihanAnggotaTidakSah', "Paket tujuan mencakup {$batas} pengguna, termasuk Pemilik. Kurangi pilihan Anda.", 'UuidAnggotaTetap');
            }
        }

        return [$outletTetap, $anggotaTetap];
    }
}
