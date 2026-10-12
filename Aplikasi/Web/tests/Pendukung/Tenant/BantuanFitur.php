<?php

declare(strict_types=1);

namespace Tests\Pendukung\Tenant;

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Paket;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;

/**
 * D-111: hak fitur paket ditegakkan server (`WajibFiturPaket`). Test yang butuh fitur di luar paket bawaan tenant uji
 * (trial Pro tidak memuat transfer stok, daftar harga, grosir, anti-fraud, insight, ...) memberinya lewat override
 * konsol — jalur yang sama dengan pengecualian tenant di produksi — alih-alih mematikan penegakan.
 */
final class BantuanFitur
{
    /** Beri tenant fitur di luar paketnya selama 60 hari (override pengelola). */
    public static function Beri(int $idTenant, string ...$kunci): void
    {
        BantuanOrganisasi::AturKonteks($idTenant);
        $pembuat = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id;

        foreach ($kunci as $satu) {
            OverrideTenant::query()->firstOrCreate(
                ['IdTenant' => $idTenant, 'Jenis' => JenisOverride::Fitur, 'Kunci' => $satu],
                ['BerakhirPada' => now()->addDays(60), 'Alasan' => 'Uji fitur di luar paket', 'DibuatOleh' => $pembuat],
            );
        }
    }

    /** Pindahkan langganan tenant ke paket lain (GRATIS, STARTER, PRO, BISNIS, ENTERPRISE). */
    public static function PindahPaket(int $idTenant, string $kodePaket): void
    {
        Langganan::query()->where('IdTenant', $idTenant)->update(['IdPaket' => Paket::query()->where('Kode', $kodePaket)->value('Id')]);
        BantuanOrganisasi::AturKonteks($idTenant);
    }
}
