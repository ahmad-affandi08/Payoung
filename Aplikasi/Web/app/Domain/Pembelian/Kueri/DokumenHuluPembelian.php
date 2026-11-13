<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dokumen hulu pembelian yang bisa dipilih untuk memulai dokumen turunannya (F-04, D-287): penerimaan barang untuk
 * retur, biaya tambahan, dan faktur; faktur untuk nota debit; permintaan yang sudah disetujui untuk dijadikan PO.
 * Halaman "buat" yang dibuka tanpa dokumen asal memakai daftar ini supaya pengguna memilih dari dokumen yang nyata,
 * bukan mencarinya sendiri di daftar. Bentuk tiap butir seragam (`ButirPilihDokumen` di FE): nomor, pihak, tanggal, dan
 * satu baris rincian berpemisah " | ". Semuanya hanya membaca, terbaru dulu, dibatasi outlet yang boleh diakses.
 */
final class DokumenHuluPembelian
{
    public const BATAS_BAWAAN = 100;

    public function __construct(private readonly PetaNamaPembelian $peta) {}

    /**
     * Penerimaan tercatat yang masih punya barang yang belum diretur (belanja stok ikut).
     *
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    public function PenerimaanUntukRetur(?array $idOutletBoleh, int $batas = self::BATAS_BAWAAN): array
    {
        return $this->SusunPenerimaan(array_values($this->KueriPenerimaanBerstok($idOutletBoleh)->limit($batas)->get()->all()));
    }

    /**
     * Penerimaan tercatat yang masih punya barang di tangan, kandidat biaya tambahan (ekspedisi, bea masuk, asuransi).
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    public function PenerimaanUntukBiayaTambahan(?array $idOutletBoleh, int $batas = self::BATAS_BAWAAN): array
    {
        return $this->SusunPenerimaan(array_values($this->KueriPenerimaanBerstok($idOutletBoleh)->limit($batas)->get()->all()));
    }

    /**
     * Penerimaan dari pemasok yang belum ditaut ke faktur mana pun (bukan belanja stok), semua pemasok sekaligus.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    public function PenerimaanBelumDifakturkan(?array $idOutletBoleh, int $batas = self::BATAS_BAWAAN): array
    {
        $penerimaan = PenerimaanBarang::query()
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->where('BelanjaStok', false)
            ->whereNotNull('IdPemasok')
            ->whereNull('IdFakturPembelian')
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderBy('Tanggal')
            ->orderBy('Id')
            ->limit($batas)
            ->get()
            ->all();

        return $this->SusunPenerimaan(array_values($penerimaan));
    }

    /**
     * Faktur yang belum dibatalkan, kandidat nota debit (selisih harga atau klaim tanpa barang kembali).
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    public function FakturUntukNotaDebit(?array $idOutletBoleh, int $batas = self::BATAS_BAWAAN): array
    {
        /** @var list<FakturPembelian> $faktur */
        $faktur = FakturPembelian::query()
            ->where('Status', '!=', StatusFakturPembelian::Dibatalkan->value)
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderByDesc('Tanggal')
            ->orderByDesc('Id')
            ->limit($batas)
            ->get()
            ->all();
        $pemasok = $this->peta->Pemasok(array_map(fn (FakturPembelian $f): ?int => $f->IdPemasok, $faktur));

        return array_map(function (FakturPembelian $f) use ($pemasok): array {
            $sisa = $f->AmbilSisa();

            return [
                'Uuid' => $f->Uuid,
                'Nomor' => $f->Nomor,
                'Pihak' => $f->IdPemasok === null ? 'Tanpa pemasok' : ($pemasok[$f->IdPemasok]['Nama'] ?? 'Pemasok dihapus'),
                'Tanggal' => $f->Tanggal->format('Y-m-d'),
                'Rincian' => 'Faktur pemasok '.$f->NomorFakturPemasok.' | total '.Uang::Dari((string) $f->Total)->FormatRupiah().' | sisa hutang '.($sisa->BernilaiNegatif() ? Uang::Nol() : $sisa)->FormatRupiah(),
                'Lencana' => ['Jenis' => $f->Status === StatusFakturPembelian::Lunas ? 'sukses' : 'netral', 'Teks' => $f->Status->AmbilLabel()],
            ];
        }, $faktur);
    }

    /**
     * Permintaan yang sudah disetujui tetapi belum dijadikan PO, tertua dulu karena paling lama menunggu.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    public function PermintaanSiapJadiPo(?array $idOutletBoleh, int $batas = self::BATAS_BAWAAN): array
    {
        /** @var list<PermintaanPembelian> $permintaan */
        $permintaan = PermintaanPembelian::query()
            ->withCount('Detail as JumlahBaris')
            ->where('Status', StatusPermintaanPembelian::Disetujui->value)
            ->whereNull('IdPesananPembelian')
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderBy('Tanggal')
            ->orderBy('Id')
            ->limit($batas)
            ->get()
            ->all();
        $pemasok = $this->peta->Pemasok(array_map(fn (PermintaanPembelian $p): ?int => $p->IdPemasok, $permintaan));
        $gudang = $this->peta->Gudang(array_map(fn (PermintaanPembelian $p): int => $p->IdGudang, $permintaan));

        return array_map(function (PermintaanPembelian $p) use ($pemasok, $gudang): array {
            $butuh = $p->DibutuhkanPada === null ? 'tanpa tenggat' : 'dibutuhkan '.$p->DibutuhkanPada->format('d/m/Y');
            $jumlah = (int) $p->getAttribute('JumlahBaris');

            return [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Pihak' => $p->IdPemasok === null ? 'Pemasok belum dipilih' : ($pemasok[$p->IdPemasok]['Nama'] ?? 'Pemasok dihapus'),
                'Tanggal' => $p->Tanggal->format('Y-m-d'),
                'Rincian' => ($gudang[$p->IdGudang]->nama ?? '').' | '.(string) $jumlah.' barang | '.$butuh,
                'Lencana' => null,
            ];
        }, $permintaan);
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<PenerimaanBarang>
     */
    private function KueriPenerimaanBerstok(?array $idOutletBoleh): Builder
    {
        return PenerimaanBarang::query()
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->whereHas('Detail', fn ($d) => $d->whereColumn('JumlahDasar', '>', 'JumlahDiretur'))
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderByDesc('Tanggal')
            ->orderByDesc('Id');
    }

    /**
     * @param  list<PenerimaanBarang>  $penerimaan
     * @return list<array{Uuid: string, Nomor: string, Pihak: string, Tanggal: string, Rincian: string, Lencana: array{Jenis: string, Teks: string}|null}>
     */
    private function SusunPenerimaan(array $penerimaan): array
    {
        $pemasok = $this->peta->Pemasok(array_map(fn (PenerimaanBarang $g): ?int => $g->IdPemasok, $penerimaan));
        $gudang = $this->peta->Gudang(array_map(fn (PenerimaanBarang $g): int => $g->IdGudang, $penerimaan));

        return array_map(fn (PenerimaanBarang $g): array => [
            'Uuid' => $g->Uuid,
            'Nomor' => $g->Nomor,
            'Pihak' => $g->IdPemasok === null ? 'Belanja tanpa pemasok' : ($pemasok[$g->IdPemasok]['Nama'] ?? 'Pemasok dihapus'),
            'Tanggal' => $g->Tanggal->format('Y-m-d'),
            'Rincian' => ($gudang[$g->IdGudang]->nama ?? '').' | nilai '.Uang::Dari((string) $g->TotalNilai)->FormatRupiah(),
            'Lencana' => $g->BelanjaStok ? ['Jenis' => 'netral', 'Teks' => 'Belanja stok'] : null,
        ], $penerimaan);
    }
}
