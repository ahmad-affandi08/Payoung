<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Katalog\Data\DataSaringProduk;
use App\Domain\Katalog\Enum\StatusProduk;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use App\Domain\Katalog\Layanan\PohonKategoriTenant;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar produk back-office (F-03 E.2) untuk `TabelData` (D-16). Anak varian tidak tampil (ada di bawah induknya). `kata`
 * mencocokkan Nama/SKU (sebagian) atau barcode (persis); kategori termasuk sub-kategorinya.
 */
final class DaftarProduk
{
    public const KOLOM_URUT = ['Nama', 'Sku', 'DiubahPada'];

    public const KOLOM_SARING = ['Kategori', 'Jenis', 'Status'];

    /**
     * Daftar untuk `TabelData` (D-16): saring dari `DataSaringProduk`, urut & paginasi dari permintaan tabel.
     *
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataSaringProduk $saring, DataPermintaanTabel $permintaan): array
    {
        return PenerapKueriTabel::Terapkan(
            $this->BuatKueri($saring),
            $permintaan,
            ['Nama' => 'Nama', 'Sku' => 'Sku', 'DiubahPada' => 'DiubahPada'],
            fn (Collection $produk): array => $this->Petakan(array_values($produk->all())),
        );
    }

    /**
     * @return Builder<Produk>
     */
    private function BuatKueri(DataSaringProduk $saring): Builder
    {
        $kata = trim($saring->kata);
        $pola = PenerapKueriTabel::PolaCari($kata);

        return Produk::query()
            ->whereNull('IdInduk')
            ->when($kata !== '', fn ($kueri) => $kueri->where(fn ($dalam) => $dalam
                ->where('Nama', 'like', $pola)
                ->orWhere('Sku', 'like', $pola)
                ->orWhereIn('Id', ProdukBarcode::query()->where('Barcode', $kata)->select('IdProduk'))
                ->orWhereIn('Id', Produk::query()->whereIn('Id', ProdukBarcode::query()->where('Barcode', $kata)->select('IdProduk'))->whereNotNull('IdInduk')->select('IdInduk'))))
            ->when($saring->idKategori !== null, fn ($kueri) => $kueri->whereIn('IdKategori', PohonKategoriTenant::Muat()->AmbilIdDenganTurunan((int) $saring->idKategori)))
            ->when($saring->jenis !== null, fn ($kueri) => $kueri->where('Jenis', $saring->jenis?->value))
            ->when($saring->status === StatusProduk::Aktif, fn ($kueri) => $kueri->whereNull('DiarsipkanPada'))
            ->when($saring->status === StatusProduk::Diarsipkan, fn ($kueri) => $kueri->whereNotNull('DiarsipkanPada'));
    }

    /**
     * Baris daftar (tipe FE `BarisProduk`) tanpa N+1.
     *
     * @param  list<Produk>  $produk
     * @return list<array<string, mixed>>
     */
    public function Petakan(array $produk): array
    {
        $id = array_map(fn (Produk $p): int => $p->Id, $produk);
        $namaKategori = Kategori::query()->whereIn('Id', array_filter(array_map(fn (Produk $p): ?int => $p->IdKategori, $produk)))->pluck('Nama', 'Id');
        $jumlahVarian = Produk::query()->whereIn('IdInduk', $id)->selectRaw('IdInduk, count(*) as Jumlah')->groupBy('IdInduk')->pluck('Jumlah', 'IdInduk');
        $simbol = Satuan::query()->whereIn('Id', array_map(fn (Produk $p): int => $p->IdSatuanDasar, $produk))->pluck('Simbol', 'Id');
        $hargaDasar = $this->AmbilHargaDasar($id);

        return array_map(fn (Produk $p): array => [
            'Uuid' => $p->Uuid,
            'Nama' => $p->Nama,
            'Sku' => $p->Sku,
            'Jenis' => $p->Jenis->value,
            'LabelJenis' => $p->Jenis->AmbilLabel(),
            'NamaKategori' => $p->IdKategori === null ? null : $namaKategori->get($p->IdKategori),
            'Merek' => $p->Merek,
            'HargaDasar' => $hargaDasar[$p->Id] ?? null,
            'SimbolSatuan' => (string) ($simbol->get($p->IdSatuanDasar) ?? ''),
            'JumlahVarian' => (int) ($jumlahVarian->get($p->Id) ?? 0),
            'TampilDiPos' => $p->TampilDiPos,
            'Favorit' => $p->Favorit,
            'DiubahPada' => $p->DiubahPada?->toIso8601String(),
            'Status' => $p->AmbilStatus()->value,
            'UrlGambarKecil' => PenyimpanGambarProduk::BuatUrl($p, 'kecil'),
        ], $produk);
    }

    /**
     * Harga dasar (`JumlahMinimum` terkecil, tanpa daftar harga) satuan jual bawaan per produk.
     *
     * @param  list<int>  $idProduk
     * @return array<int, string>
     */
    public function AmbilHargaDasar(array $idProduk): array
    {
        if ($idProduk === []) {
            return [];
        }

        $satuanJual = ProdukSatuan::query()->whereIn('IdProduk', $idProduk)->where('DefaultJual', true)->pluck('Id', 'IdProduk');
        $hasil = [];

        foreach (ProdukHarga::query()->whereIn('IdProdukSatuan', $satuanJual->values()->all())->whereNull('IdDaftarHarga')->orderBy('JumlahMinimum')->orderBy('Id')->get() as $harga) {
            $hasil[$harga->IdProduk] ??= $harga->Harga;
        }

        return $hasil;
    }
}
