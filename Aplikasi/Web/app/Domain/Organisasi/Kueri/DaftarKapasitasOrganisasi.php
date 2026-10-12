<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Organisasi\Model\TenantPengguna;

/**
 * Outlet dan anggota aktif untuk memilih yang tetap aktif saat paket diturunkan (BR-P04.4, audit E5). Hanya baca,
 * dalam konteks tenant aktif (`Outlet` ber-scope tenant; `TenantPengguna` disaring `IdTenant` eksplisit).
 */
final class DaftarKapasitasOrganisasi
{
    /**
     * @return list<array{Uuid: string, Nama: string, JumlahPerangkat: int}>
     */
    public function AmbilOutletAktif(): array
    {
        $perangkat = Perangkat::query()->whereNull('DicabutPada')->selectRaw('IdOutlet, COUNT(*) as Jumlah')->groupBy('IdOutlet')->pluck('Jumlah', 'IdOutlet');

        return array_values(Outlet::query()
            ->where('Status', StatusOrganisasi::Aktif->value)
            ->orderBy('Id')
            ->get(['Id', 'Uuid', 'Nama'])
            ->map(fn (Outlet $outlet): array => [
                'Uuid' => $outlet->Uuid,
                'Nama' => $outlet->Nama,
                'JumlahPerangkat' => (int) ($perangkat[$outlet->Id] ?? 0),
            ])->all());
    }

    /**
     * @return list<int>
     */
    public function AmbilIdOutletAktif(): array
    {
        return array_values(array_map('intval', Outlet::query()->where('Status', StatusOrganisasi::Aktif->value)->orderBy('Id')->pluck('Id')->all()));
    }

    /**
     * @return list<array{Uuid: string, Nama: string, Email: string|null, Pemilik: bool}>
     */
    public function AmbilAnggotaAktif(int $idTenant): array
    {
        return array_values(TenantPengguna::query()
            ->where('IdTenant', $idTenant)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->with('Pengguna:Id,Uuid,Nama,Email')
            ->orderByDesc('Pemilik')
            ->orderBy('Id')
            ->get()
            ->map(fn (TenantPengguna $baris): array => [
                'Uuid' => $baris->Pengguna->Uuid,
                'Nama' => $baris->Pengguna->Nama,
                'Email' => $baris->Pengguna->Email,
                'Pemilik' => $baris->Pemilik,
            ])->all());
    }
}
