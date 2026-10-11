<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\JurnalSumber;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\PermintaanStok;
use App\Domain\Persediaan\Model\TransferStok;
use App\Domain\Persediaan\Model\TransferStokDetail;

/**
 * Detail transfer stok (tipe FE `PropsDetailTransferStok` bagian Transfer/Baris/Jurnal/Riwayat) dan isi form ubah
 * draf (`PropsFormTransferStok['Transfer']`). Tindakan & izin dilengkapi kontroler.
 */
final class DetailTransferStok
{
    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly InfoProdukStok $infoProduk,
        private readonly JurnalSumber $jurnalSumber,
        private readonly RiwayatDokumenPersediaan $riwayat,
        private readonly PelacakanTersedia $pelacakan,
    ) {}

    /**
     * @return array{Transfer: array<string, mixed>, Baris: list<array<string, mixed>>, Jurnal: list<array<string, mixed>>, Riwayat: list<array<string, mixed>>}
     */
    public function Ambil(TransferStok $t): array
    {
        $gudang = $this->infoGudang->AmbilBanyak(array_values(array_filter([$t->IdGudangAsal, $t->IdGudangTujuan, $t->IdGudangTransit])));
        [$riwayat, $nama] = $this->riwayat->Ambil(TransferStok::JENIS_DOKUMEN, $t->Id, fn (string $s): string => StatusTransferStok::tryFrom($s)?->AmbilLabel() ?? $s, [$t->DibuatOleh, $t->DikirimOleh, $t->DitutupOleh, $t->DibatalkanOleh]);

        $permintaan = $t->IdPermintaanStok === null ? null : PermintaanStok::query()->find($t->IdPermintaanStok);

        return [
            'Transfer' => [
                'Uuid' => $t->Uuid,
                'Permintaan' => $permintaan === null ? null : ['Uuid' => $permintaan->Uuid, 'Nomor' => $permintaan->Nomor],
                'Nomor' => $t->Nomor,
                'Status' => $t->Status->value,
                'LabelStatus' => $t->Status->AmbilLabel(),
                'Tanggal' => $t->Tanggal->format('Y-m-d'),
                'NamaGudangAsal' => $gudang[$t->IdGudangAsal]->nama ?? '',
                'NamaOutletAsal' => $gudang[$t->IdGudangAsal]->namaOutlet ?? null,
                'NamaGudangTujuan' => $gudang[$t->IdGudangTujuan]->nama ?? '',
                'NamaOutletTujuan' => $gudang[$t->IdGudangTujuan]->namaOutlet ?? null,
                'NamaGudangTransit' => $t->IdGudangTransit === null ? null : ($gudang[$t->IdGudangTransit]->nama ?? null),
                'Catatan' => $t->Catatan,
                'JumlahBaris' => $t->JumlahBaris,
                'TotalNilaiKirim' => $t->TotalNilaiKirim,
                'TotalNilaiDiterima' => $t->TotalNilaiDiterima,
                'TotalNilaiSusut' => $t->TotalNilaiSusut,
                'AlasanSelisih' => $t->AlasanSelisih,
                'AlasanBatal' => $t->AlasanBatal,
                'DibuatOleh' => $nama($t->DibuatOleh),
                'DibuatPada' => $t->DibuatPada?->toIso8601String() ?? '',
                'DikirimOleh' => $nama($t->DikirimOleh),
                'DikirimPada' => $t->DikirimPada?->toIso8601String(),
                'DiterimaPada' => $t->DiterimaPada?->toIso8601String(),
                'DitutupOleh' => $nama($t->DitutupOleh),
                'DitutupPada' => $t->DitutupPada?->toIso8601String(),
                'VersiDiubahPada' => $t->DiubahPada?->toIso8601String() ?? '',
            ],
            'Baris' => $this->AmbilBaris($t),
            'Jurnal' => $this->jurnalSumber->Ambil(JenisSumberJurnal::TransferStok, $t->Id),
            'Riwayat' => $riwayat,
        ];
    }

    /**
     * Isi form ubah draf (tipe FE `PropsFormTransferStok['Transfer']`).
     *
     * @return array{Uuid: string, UuidGudangAsal: string, UuidGudangTujuan: string, Tanggal: string, Catatan: string|null, VersiDiubahPada: string, Baris: list<array<string, mixed>>}
     */
    public function AmbilForm(TransferStok $t): array
    {
        $gudang = $this->infoGudang->AmbilBanyak([$t->IdGudangAsal, $t->IdGudangTujuan]);

        return [
            'Uuid' => $t->Uuid,
            'UuidGudangAsal' => $gudang[$t->IdGudangAsal]->uuid ?? '',
            'UuidGudangTujuan' => $gudang[$t->IdGudangTujuan]->uuid ?? '',
            'Tanggal' => $t->Tanggal->format('Y-m-d'),
            'Catatan' => $t->Catatan,
            'VersiDiubahPada' => $t->DiubahPada?->toIso8601String() ?? '',
            'Baris' => array_map(fn (array $b): array => [
                'UuidProduk' => $b['UuidProduk'],
                'NamaProduk' => $b['NamaProduk'],
                'Sku' => $b['Sku'],
                'SimbolSatuan' => $b['SimbolSatuan'],
                'BolehDesimal' => $b['BolehDesimal'],
                'Pelacakan' => $b['Pelacakan'],
                'Jumlah' => $b['JumlahDikirim'],
                'UuidBatchStok' => $b['UuidBatchStok'],
                'NomorBatch' => $b['NomorBatch'],
                'UuidNomorSeri' => $b['UuidNomorSeri'],
                'NomorSeri' => $b['NomorSeri'],
            ], $this->AmbilBaris($t)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function AmbilBaris(TransferStok $t): array
    {
        $detail = TransferStokDetail::query()->where('IdTransferStok', $t->Id)->orderBy('Urutan')->get();
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);
        $uuidBatch = $this->pelacakan->AmbilUuidBatch(array_values(array_filter($detail->pluck('IdBatchStok')->all(), 'is_int')));
        $uuidSeri = $this->pelacakan->AmbilUuidSeri(array_values(array_filter($detail->pluck('IdNomorSeri')->all(), 'is_int')));

        return array_values($detail->map(function (TransferStokDetail $d) use ($produk, $uuidBatch, $uuidSeri): array {
            $info = $produk[$d->IdProduk] ?? null;
            $sisa = $d->AmbilSisa();

            return [
                'Urutan' => $d->Urutan,
                'UuidProduk' => $info->uuid ?? '',
                'NamaProduk' => $d->NamaProduk,
                'Sku' => $d->Sku,
                'SimbolSatuan' => $info->simbolSatuan ?? '',
                'BolehDesimal' => $info->bolehDesimal ?? false,
                'Pelacakan' => $info?->pelacakan->value ?? 'Tidak',
                'JumlahDikirim' => $d->JumlahDikirim,
                'JumlahDiterima' => $d->JumlahDiterima,
                'JumlahSusut' => $d->JumlahSusut,
                'JumlahDitolak' => $d->JumlahDitolak,
                'JumlahSisa' => $sisa->KeString(),
                'NilaiKirim' => $d->NilaiKirim,
                'NilaiDiterima' => $d->NilaiDiterima,
                'NilaiSusut' => $d->NilaiSusut,
                'NilaiDitolak' => $d->NilaiDitolak,
                'UuidBatchStok' => $d->IdBatchStok === null ? null : ($uuidBatch[$d->IdBatchStok] ?? null),
                'NomorBatch' => $d->NomorBatch,
                'TanggalKedaluwarsa' => $d->TanggalKedaluwarsa?->format('Y-m-d'),
                'UuidNomorSeri' => $d->IdNomorSeri === null ? null : ($uuidSeri[$d->IdNomorSeri] ?? null),
                'NomorSeri' => $d->NomorSeri,
            ];
        })->all());
    }
}
