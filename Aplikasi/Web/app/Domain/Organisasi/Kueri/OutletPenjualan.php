<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Data\DataOutletPenjualan;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;

/**
 * API baca publik Organisasi untuk penjualan POS (F-07b): outlet & perangkat pengirim, dan lokasi stok Toko pertama
 * outlet (jenis `Toko`, aktif didahulukan, lalu Id terkecil). Null bila outlet atau perangkat bukan milik tenant aktif.
 */
final class OutletPenjualan
{
    public function Ambil(int $idOutlet, int $idPerangkat): ?DataOutletPenjualan
    {
        $outlet = Outlet::query()->whereKey($idOutlet)->first();
        $perangkat = Perangkat::query()->whereKey($idPerangkat)->first();

        if ($outlet === null || $perangkat === null) {
            return null;
        }

        return new DataOutletPenjualan(
            idOutlet: $outlet->Id,
            uuidOutlet: $outlet->Uuid,
            kodeOutlet: $outlet->Kode,
            namaOutlet: $outlet->Nama,
            alamat: $outlet->Alamat,
            telepon: null,
            kodeKota: $outlet->KodeKota,
            zonaWaktu: $outlet->ZonaWaktu,
            jamTutupBuku: substr($outlet->JamTutupBuku, 0, 5),
            idPerangkat: $perangkat->Id,
            uuidPerangkat: $perangkat->Uuid,
            kodePerangkat: $perangkat->Kode,
            idGudangToko: $this->AmbilIdGudangToko($idOutlet),
            namaMerek: Merek::query()->whereKey($outlet->IdMerek)->value('Nama'),
            idMerek: (int) $outlet->IdMerek,
        );
    }

    /** Lokasi stok Toko pertama outlet (aktif didahulukan, lalu Id terkecil); null bila belum ada. */
    public function AmbilIdGudangToko(int $idOutlet): ?int
    {
        $id = Gudang::query()
            ->where('IdOutlet', $idOutlet)
            ->where('Jenis', JenisGudang::Toko->value)
            ->orderByRaw('CASE WHEN `Status` = ? THEN 0 ELSE 1 END', [StatusOrganisasi::Aktif->value])
            ->orderBy('Id')
            ->value('Id');

        return $id === null ? null : (int) $id;
    }

    /** F-17: sakelar toko online outlet ini (kolom `Outlet.TokoOnlineAktif`). */
    public function CekTokoOnlineAktif(int $idOutlet): bool
    {
        return Outlet::query()->whereKey($idOutlet)->value('TokoOnlineAktif') === true;
    }

    /**
     * F-09 retur: lokasi stok jenis `Rusak` pertama outlet (aktif didahulukan, lalu Id terkecil), null bila tidak ada.
     */
    public function AmbilIdGudangRusak(int $idOutlet): ?int
    {
        $id = Gudang::query()
            ->where('IdOutlet', $idOutlet)
            ->where('Jenis', JenisGudang::Rusak->value)
            ->orderByRaw('CASE WHEN `Status` = ? THEN 0 ELSE 1 END', [StatusOrganisasi::Aktif->value])
            ->orderBy('Id')
            ->value('Id');

        return is_int($id) ? $id : null;
    }
}
