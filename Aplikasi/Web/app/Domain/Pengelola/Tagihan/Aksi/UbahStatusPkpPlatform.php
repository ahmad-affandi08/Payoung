<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Layanan\PengaturanPenagihanPlatform;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin mengubah status PKP Payoung untuk penagihan (P-08, D-114). PKP menyala = PPN ditagihkan memakai tarif
 * terbit (CLAUDE.md #12); mati = tanpa PPN, sebelum SPPKP terbit. Hanya berlaku untuk tagihan yang terbit setelahnya:
 * angka tagihan lama tidak berubah (CLAUDE.md #8). Wajib beralasan dan teraudit.
 */
final class UbahStatusPkpPlatform
{
    public function __construct(
        private readonly PengaturanPenagihanPlatform $pengaturan,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, bool $pkp, string $alasan): bool
    {
        if (! $pelaku->PunyaIzin(IzinPengelola::TagihanPengaturan)) {
            throw new PelanggaranAturanBisnis('BR-P08.17', 'Status PKP hanya bisa diubah Super Admin.');
        }

        return DB::transaction(function () use ($pelaku, $pkp, $alasan): bool {
            $lama = $this->pengaturan->PlatformPkp();
            PengaturanPlatform::query()->updateOrCreate(
                ['Kunci' => PengaturanPenagihanPlatform::KUNCI_PKP],
                ['Nilai' => $pkp ? '1' : '0', 'IdPenggunaPengelolaPengubah' => $pelaku->Id],
            );
            $this->audit->Catat(
                'tagihan.pkp.ubah',
                null,
                nilaiLama: ['Pkp' => $lama],
                nilaiBaru: ['Pkp' => $pkp],
                alasan: trim($alasan),
                idPelaku: $pelaku->Id,
            );

            return $pkp;
        });
    }
}
