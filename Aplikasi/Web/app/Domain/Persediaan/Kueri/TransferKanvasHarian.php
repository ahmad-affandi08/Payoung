<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Model\TransferStok;
use Carbon\CarbonImmutable;

/**
 * Transfer muat & bongkar satu kendaraan kanvas pada satu hari bisnis (D-95): muat = transfer yang tujuannya bak
 * kendaraan, bongkar = transfer yang asalnya bak kendaraan. Dipakai halaman Grosir › Kanvas untuk memberi jalan ke
 * detail dan cetak surat jalan tanpa mencari di daftar transfer. Hanya baca; stoknya tetap dari buku stok.
 */
final class TransferKanvasHarian
{
    public function __construct(private readonly InfoGudang $infoGudang) {}

    /**
     * @return list<array{Uuid: string, Nomor: string|null, Arah: string, Status: string, LabelStatus: string, NamaLawan: string, JumlahBaris: int, BisaCetak: bool}>
     */
    public function Ambil(int $idGudang, CarbonImmutable $tanggal): array
    {
        $daftar = TransferStok::query()
            ->whereDate('Tanggal', $tanggal->toDateString())
            ->where(fn ($q) => $q->where('IdGudangAsal', $idGudang)->orWhere('IdGudangTujuan', $idGudang))
            ->orderBy('Id')
            ->get();
        $lawan = $this->infoGudang->AmbilBanyak(array_values(array_unique(array_map(
            static fn (TransferStok $t): int => $t->IdGudangAsal === $idGudang ? $t->IdGudangTujuan : $t->IdGudangAsal,
            $daftar->all(),
        ))));

        return array_values($daftar->map(static function (TransferStok $t) use ($idGudang, $lawan): array {
            $bongkar = $t->IdGudangAsal === $idGudang;
            $idLawan = $bongkar ? $t->IdGudangTujuan : $t->IdGudangAsal;

            return [
                'Uuid' => $t->Uuid,
                'Nomor' => $t->Nomor,
                'Arah' => $bongkar ? 'Bongkar' : 'Muat',
                'Status' => $t->Status->value,
                'LabelStatus' => $t->Status->AmbilLabel(),
                'NamaLawan' => $lawan[$idLawan]->nama ?? '',
                'JumlahBaris' => $t->JumlahBaris,
                'BisaCetak' => $t->Status->value !== 'Draf',
            ];
        })->all());
    }
}
