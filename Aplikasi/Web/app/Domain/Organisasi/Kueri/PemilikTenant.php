<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\TenantPengguna;

/**
 * Owner (pemilik) tenant: dipakai persetujuan ulang dokumen legal (BR-P06.5). Hanya keanggotaan berstatus Aktif yang
 * dihitung. 2FA wajib (§20.2) mencakup Owner, Admin, dan Akuntan, sehingga memakai `PemegangPeranTenant`.
 */
final class PemilikTenant
{
    public function CekPemilik(int $idPengguna, int $idTenant): bool
    {
        return TenantPengguna::query()
            ->where('IdPengguna', $idPengguna)
            ->where('IdTenant', $idTenant)
            ->where('Pemilik', true)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->exists();
    }

    /** Id Owner aktif tenant ini (pelaku dokumen yang dibuat sistem atas nama usaha, misal draf PO otomatis D-23 D). */
    public function AmbilIdPemilik(int $idTenant): ?int
    {
        $id = TenantPengguna::query()
            ->where('IdTenant', $idTenant)
            ->where('Pemilik', true)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->orderBy('Id')
            ->value('IdPengguna');

        return is_int($id) ? $id : null;
    }

    /**
     * Kontak Owner aktif satu tenant untuk pemberitahuan tagihan langganan (P-08); email bisa null hanya pada data
     * lama, nomor HP opsional.
     *
     * @return list<array{IdPengguna: int, Nama: string, Email: string|null, NoHp: string|null}>
     */
    public function AmbilKontak(int $idTenant): array
    {
        $idPemilik = TenantPengguna::query()
            ->where('IdTenant', $idTenant)
            ->where('Pemilik', true)
            ->where('Status', StatusKeanggotaan::Aktif->value)
            ->pluck('IdPengguna');

        return array_values(Pengguna::query()->whereIn('Id', $idPemilik)->orderBy('Id')->get(['Id', 'Nama', 'Email', 'NoHp'])
            ->map(fn (Pengguna $p): array => ['IdPengguna' => $p->Id, 'Nama' => $p->Nama, 'Email' => $p->Email, 'NoHp' => $p->NoHp])
            ->all());
    }

    /**
     * Semua pengguna yang menjadi Owner aktif setidaknya di satu tenant, sekali per pengguna.
     *
     * @return iterable<array{Id: int, Nama: string, Email: string}>
     */
    public function AmbilSemua(): iterable
    {
        $pemilik = TenantPengguna::query()
            ->select('IdPengguna')
            ->where('Pemilik', true)
            ->where('Status', StatusKeanggotaan::Aktif->value);

        foreach (Pengguna::query()->whereIn('Id', $pemilik)->lazyById(200, 'Id') as $pengguna) {
            // Pemilik selalu memakai email (D-22: hanya karyawan kasir yang boleh tanpa email).
            if ($pengguna->Email !== null) {
                yield ['Id' => $pengguna->Id, 'Nama' => $pengguna->Nama, 'Email' => $pengguna->Email];
            }
        }
    }
}
