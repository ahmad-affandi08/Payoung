<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use App\Domain\Persediaan\Model\TransferStok;
use App\Domain\Persediaan\Model\TransferStokDetail;

/**
 * Modul Gudang aplikasi (POS-25, F-05b): dokumen persediaan yang dikerjakan staf gudang dari perangkat di lokasi stok
 * outlet perangkat. Transfer masuk = Dikirim/Diterima sebagian ke lokasi itu (sisa dalam perjalanan per baris). Stok
 * opname = Berlangsung di lokasi itu; jumlah sistem tidak dikirim untuk opname hitung buta. Jumlah satuan dasar.
 */
final class DokumenGudangPos
{
    public const BATAS_TRANSFER = 50;

    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly InfoProdukStok $infoProduk,
    ) {}

    /**
     * @param  list<int>  $idGudang
     * @return list<array<string, mixed>>
     */
    public function TransferMasuk(array $idGudang): array
    {
        if ($idGudang === []) {
            return [];
        }

        $transfer = TransferStok::query()
            ->whereIn('IdGudangTujuan', $idGudang)
            ->whereIn('Status', [StatusTransferStok::Dikirim->value, StatusTransferStok::DiterimaSebagian->value])
            ->orderBy('DikirimPada')
            ->orderBy('Id')
            ->limit(self::BATAS_TRANSFER)
            ->with('Detail')
            ->get();

        return array_values($transfer->map(fn (TransferStok $t): array => $this->PetakanTransfer($t))->all());
    }

    /**
     * @param  list<int>  $idGudang
     * @return array<string, mixed>|null
     */
    public function AmbilTransfer(string $uuid, array $idGudang): ?array
    {
        $t = TransferStok::query()->where('Uuid', strtoupper($uuid))->whereIn('IdGudangTujuan', $idGudang)->with('Detail')->first();

        return $t === null ? null : $this->PetakanTransfer($t);
    }

    /**
     * @param  list<int>  $idGudang
     * @return list<array<string, mixed>>
     */
    public function OpnameBerlangsung(array $idGudang): array
    {
        if ($idGudang === []) {
            return [];
        }

        $opname = StokOpname::query()
            ->whereIn('IdGudang', $idGudang)
            ->where('Status', StatusStokOpname::Berlangsung->value)
            ->orderBy('SnapshotPada')
            ->orderBy('Id')
            ->get();

        return array_values($opname->map(fn (StokOpname $o): array => $this->PetakanOpname($o))->all());
    }

    /**
     * @param  list<int>  $idGudang
     * @return array<string, mixed>|null
     */
    public function AmbilOpname(string $uuid, array $idGudang): ?array
    {
        $o = StokOpname::query()->where('Uuid', strtoupper($uuid))->whereIn('IdGudang', $idGudang)->first();

        return $o === null ? null : $this->PetakanOpname($o);
    }

    /**
     * @return array<string, mixed>
     */
    private function PetakanTransfer(TransferStok $t): array
    {
        $gudang = $this->infoGudang->AmbilBanyak([$t->IdGudangAsal, $t->IdGudangTujuan]);
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($t->Detail->pluck('IdProduk')->all())), true);

        return [
            'Uuid' => $t->Uuid,
            'Nomor' => $t->Nomor,
            'Tanggal' => $t->Tanggal->format('Y-m-d'),
            'DikirimPada' => $t->DikirimPada?->toIso8601ZuluString(),
            'Status' => $t->Status->value,
            'LabelStatus' => $t->Status->AmbilLabel(),
            'NamaAsal' => self::NamaLokasi($gudang[$t->IdGudangAsal] ?? null),
            'NamaTujuan' => self::NamaLokasi($gudang[$t->IdGudangTujuan] ?? null),
            'Catatan' => $t->Catatan,
            'Baris' => array_values($t->Detail->sortBy('Urutan')->map(function (TransferStokDetail $d) use ($produk): array {
                $info = $produk[$d->IdProduk] ?? null;
                $sisa = $d->AmbilSisa();

                return [
                    'Urutan' => $d->Urutan,
                    'UuidProduk' => $info?->uuid,
                    'NamaProduk' => $d->NamaProduk,
                    'Sku' => $d->Sku,
                    'SimbolSatuan' => $info->simbolSatuan ?? '',
                    'BolehDesimal' => $info->bolehDesimal ?? false,
                    'NomorBatch' => $d->NomorBatch,
                    'TanggalKedaluwarsa' => $d->TanggalKedaluwarsa?->format('Y-m-d'),
                    'NomorSeri' => $d->NomorSeri,
                    'JumlahDikirim' => $d->JumlahDikirim,
                    'JumlahDiterima' => $d->JumlahDiterima,
                    'Sisa' => ($sisa->BernilaiNegatif() ? Kuantitas::Nol() : $sisa)->KeString(),
                ];
            })->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function PetakanOpname(StokOpname $o): array
    {
        $gudang = $this->infoGudang->AmbilBanyak([$o->IdGudang]);
        $detail = StokOpnameDetail::query()->where('IdStokOpname', $o->Id)->orderBy('Urutan')->get();
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);

        return [
            'Uuid' => $o->Uuid,
            'Nomor' => $o->Nomor,
            'NamaLokasi' => self::NamaLokasi($gudang[$o->IdGudang] ?? null),
            'NamaKategori' => $o->NamaKategori,
            'NamaRak' => $o->NamaRak,
            'HitungButa' => $o->HitungButa,
            'SnapshotPada' => $o->SnapshotPada->toIso8601ZuluString(),
            'JumlahBaris' => $o->JumlahBaris,
            'JumlahDihitung' => $o->JumlahDihitung,
            'Baris' => array_values($detail->map(function (StokOpnameDetail $d) use ($o, $produk): array {
                $info = $produk[$d->IdProduk] ?? null;

                return [
                    'Urutan' => $d->Urutan,
                    'UuidProduk' => $info?->uuid,
                    'NamaProduk' => $d->NamaProduk,
                    'Sku' => $d->Sku,
                    'SimbolSatuan' => $info->simbolSatuan ?? '',
                    'BolehDesimal' => $info->bolehDesimal ?? false,
                    'Pelacakan' => $info === null ? 'Tidak' : $info->pelacakan->value,
                    'NomorBatch' => $d->NomorBatch,
                    'TanggalKedaluwarsa' => $d->TanggalKedaluwarsa?->format('Y-m-d'),
                    'NomorSeri' => $d->NomorSeri,
                    'JumlahSistem' => $o->HitungButa ? null : $d->JumlahSistem,
                    'JumlahFisik' => $d->JumlahFisik,
                ];
            })->all()),
        ];
    }

    private static function NamaLokasi(?DataInfoGudang $gudang): string
    {
        if ($gudang === null) {
            return '';
        }

        return $gudang->namaOutlet === null || $gudang->namaOutlet === '' ? $gudang->nama : "{$gudang->namaOutlet} | {$gudang->nama}";
    }
}
