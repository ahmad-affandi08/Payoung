<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Organisasi\Model\UndanganPengguna;

/**
 * Daftar anggota & undangan menunggu di tenant aktif untuk halaman Pengguna & Peran (F-02). Tabel tanpa
 * `MilikTenant` (`TenantPengguna`, `UndanganPengguna`) selalu disaring `IdTenant` secara eksplisit.
 */
final class DaftarAnggota
{
    /**
     * @return list<array{Uuid: string, Nama: string, Email: string|null, Pemilik: bool, UuidPeran: string|null, NamaPeran: string|null, SemuaOutlet: bool, UuidOutlet: list<string>, Status: string, DinonaktifkanPada: string|null}>
     */
    public function AmbilAnggota(int $idTenant): array
    {
        $anggota = TenantPengguna::query()->where('IdTenant', $idTenant)->with('Pengguna')->get();
        $peran = Peran::query()->get()->keyBy('Id');
        $uuidOutlet = $this->AmbilUuidOutletPerPengguna();

        return array_values($anggota
            ->sortBy(fn (TenantPengguna $baris) => $baris->Status->value.'|'.mb_strtolower($baris->Pengguna->Nama))
            ->map(fn (TenantPengguna $baris): array => [
                'Uuid' => $baris->Pengguna->Uuid,
                'Nama' => $baris->Pengguna->Nama,
                'Email' => $baris->Pengguna->Email,
                'Pemilik' => $baris->Pemilik,
                'UuidPeran' => $baris->IdPeran === null ? null : $peran->get($baris->IdPeran)?->Uuid,
                'NamaPeran' => $baris->IdPeran === null ? null : $peran->get($baris->IdPeran)?->Nama,
                'SemuaOutlet' => $baris->Pemilik || $baris->SemuaOutlet,
                'UuidOutlet' => $uuidOutlet[$baris->IdPengguna] ?? [],
                'Status' => $baris->Status->value,
                'DinonaktifkanPada' => $baris->DinonaktifkanPada?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array{Uuid: string, Email: string, NamaPeran: string|null, SemuaOutlet: bool, JumlahOutlet: int, BerlakuSampai: string, Pengundang: string}>
     */
    public function AmbilUndanganMenunggu(int $idTenant): array
    {
        $peran = Peran::query()->pluck('Nama', 'Id');

        return array_values(UndanganPengguna::query()
            ->where('IdTenant', $idTenant)
            ->whereNull('DiterimaPada')
            ->whereNull('DibatalkanPada')
            ->where('BerlakuSampai', '>', now())
            ->with('Pengundang:Id,Nama')
            ->orderByDesc('Id')
            ->get()
            ->map(fn (UndanganPengguna $undangan): array => [
                'Uuid' => $undangan->Uuid,
                'Email' => $undangan->Email,
                'NamaPeran' => is_string($nama = $peran->get($undangan->IdPeran)) ? $nama : null,
                'SemuaOutlet' => $undangan->SemuaOutlet,
                'JumlahOutlet' => count($undangan->DaftarIdOutlet ?? []),
                'BerlakuSampai' => $undangan->BerlakuSampai->toIso8601String(),
                'Pengundang' => $undangan->Pengundang->Nama,
            ])
            ->all());
    }

    /**
     * F-18: anggota aktif tenant (Id, Uuid, Nama) untuk ditautkan ke data karyawan, urut nama.
     *
     * @return list<array{Id: int, Uuid: string, Nama: string}>
     */
    public function AmbilPilihanAktif(int $idTenant): array
    {
        return array_values(TenantPengguna::query()
            ->where('IdTenant', $idTenant)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->with('Pengguna')
            ->get()
            ->map(fn (TenantPengguna $baris): array => ['Id' => $baris->Pengguna->Id, 'Uuid' => $baris->Pengguna->Uuid, 'Nama' => $baris->Pengguna->Nama])
            ->sortBy(fn (array $a): string => mb_strtolower($a['Nama']))
            ->values()
            ->all());
    }

    /**
     * D-101: Uuid → Id semua anggota tenant (aktif maupun nonaktif), untuk menggabungkan akun dengan data karyawan.
     *
     * @return array<string, int>
     */
    public function AmbilPetaIdPengguna(int $idTenant): array
    {
        $hasil = [];

        foreach (TenantPengguna::query()->where('IdTenant', $idTenant)->with('Pengguna:Id,Uuid')->get() as $baris) {
            $hasil[$baris->Pengguna->Uuid] = $baris->Pengguna->Id;
        }

        return $hasil;
    }

    /**
     * Nama pelaku untuk log audit. Hanya pengguna yang pernah menjadi anggota tenant ini yang ditampilkan.
     *
     * @param  list<int>  $idPengguna
     * @return array<int, string>
     */
    public function AmbilNamaPengguna(int $idTenant, array $idPengguna): array
    {
        $anggota = TenantPengguna::query()->where('IdTenant', $idTenant)->whereIn('IdPengguna', $idPengguna)->pluck('IdPengguna')->all();

        /** @var array<int, string> */
        return Pengguna::query()->whereKey($anggota)->pluck('Nama', 'Id')->all();
    }

    /**
     * @return array<int, list<string>>
     */
    private function AmbilUuidOutletPerPengguna(): array
    {
        $hasil = [];

        foreach (OutletPengguna::query()->join('Outlet', 'Outlet.Id', '=', 'OutletPengguna.IdOutlet')->get(['OutletPengguna.IdPengguna', 'Outlet.Uuid']) as $baris) {
            $hasil[(int) $baris->getAttribute('IdPengguna')][] = (string) $baris->getAttribute('Uuid');
        }

        return $hasil;
    }
}
