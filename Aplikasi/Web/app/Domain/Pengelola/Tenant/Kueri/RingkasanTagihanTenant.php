<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;

/**
 * Tagihan langganan satu tenant untuk tab Tagihan di tampilan 360° (P-07, P-08): `BATAS` tagihan terbaru beserta jumlah
 * dan total yang masih menunggu pembayaran. Tagihan adalah catatan platform (`KueriDataPlatform`), jadi tidak membuka
 * data usaha tenant. Hanya untuk pemegang `tagihan.lihat` (diperiksa pemanggil).
 */
final class RingkasanTagihanTenant
{
    public const BATAS = 12;

    public function __construct(private readonly KonteksPengelola $konteks) {}

    /**
     * @return array{Data: list<array<string, mixed>>, JumlahTerbuka: int, TotalTerbuka: string}
     */
    public function Ambil(Tenant $tenant): array
    {
        $dasar = fn () => $this->konteks->KueriDataPlatform(TagihanLangganan::class)->where('IdTenant', $tenant->Id);
        $total = Uang::Nol();

        foreach ($dasar()->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->pluck('Total') as $jumlah) {
            $total = $total->Tambah(Uang::Dari((string) $jumlah));
        }

        return [
            'Data' => array_values($dasar()->orderByDesc('Id')->limit(self::BATAS)->get()->map(fn (TagihanLangganan $t): array => [
                'Uuid' => $t->Uuid,
                'Nomor' => $t->Nomor,
                'Jenis' => $t->Jenis->value,
                'LabelJenis' => $t->Jenis->AmbilLabel(),
                'Status' => $t->Status->value,
                'LabelStatus' => $t->Status->AmbilLabel(),
                'Total' => $t->Total,
                'TerbitPada' => $t->TerbitPada->toIso8601String(),
                'JatuhTempoPada' => $t->JatuhTempoPada->toIso8601String(),
                'DibayarPada' => $t->DibayarPada?->toIso8601String(),
            ])->all()),
            'JumlahTerbuka' => $dasar()->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->count(),
            'TotalTerbuka' => $total->KeString(),
        ];
    }
}
