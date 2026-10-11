<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStokDetail;
use App\Domain\Persediaan\Model\TransferStok;
use Brick\Math\BigDecimal;

/**
 * Detail permintaan stok (D-105; tipe FE `PropsDetailPermintaanStok` bagian Permintaan/Baris/Transfer/Riwayat). Tindakan
 * dan izin dilengkapi kontroler. `Transfer` = transfer yang menjawab permintaan ini (draf maupun yang sudah dikirim).
 */
final class DetailPermintaanStok
{
    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly InfoProdukStok $infoProduk,
        private readonly RiwayatDokumenPersediaan $riwayat,
    ) {}

    /**
     * @return array{Permintaan: array<string, mixed>, Baris: list<array<string, mixed>>, Transfer: list<array<string, mixed>>, Riwayat: list<array<string, mixed>>}
     */
    public function Ambil(PermintaanStok $p): array
    {
        $gudang = $this->infoGudang->AmbilBanyak([$p->IdGudangAsal, $p->IdGudangTujuan]);
        [$riwayat, $nama] = $this->riwayat->Ambil(PermintaanStok::JENIS_DOKUMEN, $p->Id, fn (string $s): string => StatusPermintaanStok::tryFrom($s)?->AmbilLabel() ?? $s, [$p->DibuatOleh, $p->DitutupOleh]);
        $detail = PermintaanStokDetail::query()->where('IdPermintaanStok', $p->Id)->orderBy('Urutan')->get();
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);

        return [
            'Permintaan' => [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Status' => $p->Status->value,
                'LabelStatus' => $p->Status->AmbilLabel(),
                'Tanggal' => $p->Tanggal->format('Y-m-d'),
                'NamaGudangAsal' => $gudang[$p->IdGudangAsal]->nama ?? '',
                'NamaOutletAsal' => $gudang[$p->IdGudangAsal]->namaOutlet ?? null,
                'UuidGudangAsal' => $gudang[$p->IdGudangAsal]->uuid ?? '',
                'NamaGudangTujuan' => $gudang[$p->IdGudangTujuan]->nama ?? '',
                'NamaOutletTujuan' => $gudang[$p->IdGudangTujuan]->namaOutlet ?? null,
                'UuidGudangTujuan' => $gudang[$p->IdGudangTujuan]->uuid ?? '',
                'Catatan' => $p->Catatan,
                'JumlahBaris' => $p->JumlahBaris,
                'Alasan' => $p->Alasan,
                'DibuatOleh' => $nama($p->DibuatOleh),
                'DibuatPada' => $p->DibuatPada?->toIso8601String() ?? '',
                'DitutupOleh' => $nama($p->DitutupOleh),
                'DitutupPada' => $p->DitutupPada?->toIso8601String(),
            ],
            'Baris' => array_values($detail->map(fn (PermintaanStokDetail $d): array => [
                'Urutan' => $d->Urutan,
                'UuidProduk' => $produk[$d->IdProduk]->uuid ?? '',
                'NamaProduk' => $d->NamaProduk,
                'Sku' => $d->Sku,
                'SimbolSatuan' => $produk[$d->IdProduk]->simbolSatuan ?? '',
                'BolehDesimal' => $produk[$d->IdProduk]->bolehDesimal ?? false,
                'Pelacakan' => $produk[$d->IdProduk]?->pelacakan->value ?? 'Tidak',
                'JumlahDiminta' => $d->JumlahDiminta,
                'JumlahDikirim' => $d->JumlahDikirim,
                'JumlahSisa' => $d->AmbilSisa()->KeString(),
            ])->all()),
            'Transfer' => array_values(TransferStok::query()->where('IdPermintaanStok', $p->Id)->orderBy('Id')->get()->map(fn (TransferStok $t): array => [
                'Uuid' => $t->Uuid,
                'Nomor' => $t->Nomor,
                'Tanggal' => $t->Tanggal->format('Y-m-d'),
                'Status' => $t->Status->value,
                'LabelStatus' => $t->Status->AmbilLabel(),
                'JumlahBaris' => $t->JumlahBaris,
            ])->all()),
            'Riwayat' => $riwayat,
        ];
    }

    /**
     * Isi awal form transfer untuk menjawab permintaan ini (tipe FE `PropsFormTransferStok['Awal']`): lokasi tujuan
     * dari permintaan, baris berisi sisa yang belum dikirim. Produk bernomor seri tidak diisi otomatis (satu baris per
     * nomor seri harus dipilih manual).
     *
     * @return array{UuidGudangAsal: string, UuidGudangTujuan: string, UuidPermintaanStok: string, NomorPermintaan: string, Baris: list<array<string, mixed>>}
     */
    public function AmbilAwalTransfer(PermintaanStok $p, string $uuidGudangAsal): array
    {
        $data = $this->Ambil($p);
        $baris = [];

        foreach ($data['Baris'] as $b) {
            if ($b['Pelacakan'] === 'Seri' || BigDecimal::of($b['JumlahSisa'])->isZero()) {
                continue;
            }

            $baris[] = [
                'UuidProduk' => $b['UuidProduk'],
                'NamaProduk' => $b['NamaProduk'],
                'Sku' => $b['Sku'],
                'SimbolSatuan' => $b['SimbolSatuan'],
                'BolehDesimal' => $b['BolehDesimal'],
                'Pelacakan' => $b['Pelacakan'],
                'Jumlah' => rtrim(rtrim($b['JumlahSisa'], '0'), '.'),
                'UuidBatchStok' => null,
                'NomorBatch' => null,
                'UuidNomorSeri' => null,
                'NomorSeri' => null,
            ];
        }

        return [
            'UuidGudangAsal' => $uuidGudangAsal,
            'UuidGudangTujuan' => $data['Permintaan']['UuidGudangTujuan'],
            'UuidPermintaanStok' => $p->Uuid,
            'NomorPermintaan' => $p->Nomor,
            'Baris' => $baris,
        ];
    }
}
