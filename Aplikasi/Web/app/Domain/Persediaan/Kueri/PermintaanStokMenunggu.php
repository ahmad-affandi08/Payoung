<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;

/**
 * Permintaan stok dari outlet lain yang masih menunggu dipenuhi (Diajukan atau Diproses sebagian) dan yang lokasi
 * asalnya boleh diakses pengguna, tertua dulu. Dipakai formulir transfer stok yang dibuka dari menu: transfer yang
 * mengirim barang untuk permintaan lebih baik dimulai dari permintaannya supaya baris terisi dan permintaan ikut
 * terpenuhi (D-105, D-287). Bentuk butir seragam dengan `DokumenHuluPembelian` (butir pilih dokumen di FE).
 */
final class PermintaanStokMenunggu
{
    public const BATAS_BAWAAN = 50;

    public function __construct(private readonly InfoGudang $infoGudang) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    public function Daftar(?array $idOutletBoleh, int $batas = self::BATAS_BAWAAN): array
    {
        /** @var list<PermintaanStok> $permintaan */
        $permintaan = PermintaanStok::query()
            ->whereIn('Status', [StatusPermintaanStok::Diajukan->value, StatusPermintaanStok::DiprosesSebagian->value])
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutletAsal', $idOutletBoleh ?? []))
            ->orderBy('Tanggal')
            ->orderBy('Id')
            ->limit($batas)
            ->get()
            ->all();
        $gudang = $this->infoGudang->AmbilBanyak(array_values(array_unique([
            ...array_map(fn (PermintaanStok $p): int => $p->IdGudangAsal, $permintaan),
            ...array_map(fn (PermintaanStok $p): int => $p->IdGudangTujuan, $permintaan),
        ])));

        return array_map(function (PermintaanStok $p) use ($gudang): array {
            $tujuan = $gudang[$p->IdGudangTujuan] ?? null;
            $asal = $gudang[$p->IdGudangAsal] ?? null;

            return [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Pihak' => $tujuan === null ? '' : ($tujuan->namaOutlet ?? $tujuan->nama),
                'Tanggal' => $p->Tanggal->format('Y-m-d'),
                'Rincian' => 'Diminta dari '.($asal->nama ?? '').' ke '.($tujuan->nama ?? '').' | '.(string) $p->JumlahBaris.' barang',
                'Lencana' => $p->Status === StatusPermintaanStok::DiprosesSebagian ? ['Jenis' => 'netral', 'Teks' => $p->Status->AmbilLabel()] : null,
            ];
        }, $permintaan);
    }
}
