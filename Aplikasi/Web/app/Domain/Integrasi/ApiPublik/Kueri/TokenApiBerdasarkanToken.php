<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\ApiPublik\Kueri;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\ApiPublik\Model\TokenApiTenant;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;

/**
 * Mencari token API publik dari `payoung_{IdTenant}_{rahasia}` (X7). Sama dengan device token: bagian `IdTenant` hanya
 * menetapkan scope pencarian; token ditemukan hanya bila hash rahasianya cocok di tenant itu. Tidak cocok, dicabut,
 * atau kedaluwarsa = null dan tenant aktif dikosongkan, begitu juga bila pembuat token bukan lagi anggota aktif berizin
 * `integrasi.api.kelola`. `TerakhirDipakaiPada` diperbarui paling sering sekali per menit.
 */
final class TokenApiBerdasarkanToken
{
    public function __construct(private readonly KonteksTenant $konteks, private readonly AksesPengguna $akses) {}

    public function Cari(string $token): ?TokenApiTenant
    {
        if (preg_match('/^payoung_(\d{1,19})_([A-Za-z0-9]{40})$/', $token, $cocok) !== 1) {
            return null;
        }

        $hash = TokenApiTenant::BuatHashToken($cocok[2]);
        $this->konteks->Atur((int) $cocok[1]);
        $baris = TokenApiTenant::query()->where('HashToken', $hash)->first();

        if ($baris === null || ! hash_equals($baris->HashToken, $hash) || ! $baris->CekBerlaku()) {
            $this->konteks->Kosongkan();

            return null;
        }

        // Token mewakili pembuatnya: anggota yang sudah dinonaktifkan atau kehilangan izin `integrasi.api.kelola` tidak boleh
        // meninggalkan token yang tetap membaca data pelanggan dan penjualan atau membuat penyesuaian stok.
        if (! $this->akses->CekIzin($baris->IdTenant, $baris->DibuatOleh, IzinTenant::IntegrasiApiKelola)) {
            $this->konteks->Kosongkan();

            return null;
        }

        if ($baris->TerakhirDipakaiPada === null || $baris->TerakhirDipakaiPada->lt(now()->subMinute())) {
            TokenApiTenant::query()->whereKey($baris->Id)->update(['TerakhirDipakaiPada' => now()]);
        }

        return $baris;
    }
}
