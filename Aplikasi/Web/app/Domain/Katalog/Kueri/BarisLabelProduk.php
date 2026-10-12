<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use Illuminate\Support\Collection;

/**
 * PRD-10 (F-03): bahan halaman Cetak label. Satu baris = satu satuan produk (barcode terikat ke satuan, jadi botol dan
 * dus punya label sendiri). Hanya produk aktif yang bukan induk varian; induk varian yang dipilih diganti seluruh
 * variannya. Harga = harga dasar satuan itu (tanpa daftar harga, jumlah minimum terkecil), null bila belum ada.
 */
final class BarisLabelProduk
{
    /** Batas produk yang diminta lewat tautan/aksi massal. */
    public const MAKS_PRODUK = 200;

    /** Batas baris label dalam satu halaman cetak. */
    public const MAKS_BARIS = 500;

    /** Batas jumlah stiker per baris. */
    public const MAKS_JUMLAH = 500;

    /**
     * Semua satuan dari produk-produk ini (induk varian → variannya), urut sesuai urutan `$uuidProduk`.
     *
     * @param  list<string>  $uuidProduk
     * @return array{Baris: list<array<string, mixed>>, Terpotong: bool}
     */
    public function DariProduk(array $uuidProduk): array
    {
        $uuidProduk = array_values(array_unique($uuidProduk));
        $dipilih = Produk::query()->whereNull('DiarsipkanPada')->whereIn('Uuid', $uuidProduk)->get();
        $urutan = array_flip($uuidProduk);
        $induk = $dipilih->filter(fn (Produk $p): bool => $p->Jenis === JenisProduk::IndukVarian);
        $varian = $induk->isEmpty() ? collect() : Produk::query()->whereNull('DiarsipkanPada')->whereIn('IdInduk', $induk->pluck('Id')->all())->orderBy('Nama')->orderBy('Id')->get();
        $susunan = collect();

        foreach ($dipilih->sortBy(fn (Produk $p): int => $urutan[$p->Uuid] ?? PHP_INT_MAX) as $produk) {
            if ($produk->Jenis === JenisProduk::IndukVarian) {
                $susunan = $susunan->concat($varian->where('IdInduk', $produk->Id));
            } else {
                $susunan->push($produk);
            }
        }

        $susunan = $susunan->unique('Id')->values();
        $terpotong = false;
        $satuan = ProdukSatuan::query()->whereIn('IdProduk', $susunan->pluck('Id')->all())->orderBy('KonversiKeDasar')->orderBy('Id')->get()->groupBy('IdProduk');
        $baris = collect();

        foreach ($susunan as $produk) {
            foreach ($satuan->get($produk->Id) ?? [] as $satuanProduk) {
                $baris->push([$produk, $satuanProduk, 1]);
            }
        }

        if ($baris->count() > self::MAKS_BARIS) {
            $terpotong = true;
            $baris = $baris->take(self::MAKS_BARIS);
        }

        return ['Baris' => $this->Susun($baris), 'Terpotong' => $terpotong];
    }

    /**
     * Satuan tertentu dengan jumlah stiker masing-masing (mis. dari penerimaan barang). Uuid tidak dikenal atau produk
     * diarsipkan/induk varian dilewati; urutan mengikuti `$item`.
     *
     * @param  list<array{Uuid: string, Jumlah: int}>  $item
     * @return array{Baris: list<array<string, mixed>>, Terpotong: bool}
     */
    public function DariItem(array $item): array
    {
        $jumlah = [];

        foreach ($item as $i) {
            $jumlah[$i['Uuid']] = ($jumlah[$i['Uuid']] ?? 0) + $i['Jumlah'];
        }

        $satuan = ProdukSatuan::query()->whereIn('Uuid', array_keys($jumlah))->get()->keyBy('Uuid');
        $produk = Produk::query()->whereNull('DiarsipkanPada')->whereIn('Id', $satuan->pluck('IdProduk')->unique()->all())->get()->keyBy('Id');
        $baris = collect();
        $terpotong = false;

        foreach ($jumlah as $uuid => $banyak) {
            $s = $satuan->get($uuid);
            $p = $s === null ? null : $produk->get($s->IdProduk);

            if ($s !== null && $p !== null && $p->Jenis !== JenisProduk::IndukVarian) {
                $terpotong = $terpotong || $banyak > self::MAKS_JUMLAH;
                $baris->push([$p, $s, min(self::MAKS_JUMLAH, max(1, $banyak))]);
            }
        }

        $terpotong = $terpotong || $baris->count() > self::MAKS_BARIS;

        return ['Baris' => $this->Susun($baris->take(self::MAKS_BARIS)), 'Terpotong' => $terpotong];
    }

    /**
     * Pencarian untuk menambah baris: nama/SKU mengandung `kata`, atau barcode persis `kata`.
     *
     * @return list<array<string, mixed>>
     */
    public function Cari(string $kata, int $batas = 20): array
    {
        $kata = trim($kata);
        $pola = '%'.addcslashes($kata, '%_\\').'%';
        $produk = Produk::query()
            ->whereNull('DiarsipkanPada')
            ->where('Jenis', '!=', JenisProduk::IndukVarian->value)
            ->when($kata !== '', fn ($kueri) => $kueri->where(fn ($dalam) => $dalam
                ->where('Nama', 'like', $pola)
                ->orWhere('Sku', 'like', $pola)
                ->orWhereIn('Id', ProdukBarcode::query()->where('Barcode', $kata)->select('IdProduk'))))
            ->orderBy('Nama')
            ->orderBy('Id')
            ->limit(max(1, min(50, $batas)))
            ->get();
        $satuan = ProdukSatuan::query()->whereIn('IdProduk', $produk->modelKeys())->orderBy('KonversiKeDasar')->orderBy('Id')->get()->groupBy('IdProduk');
        $baris = collect();

        foreach ($produk as $p) {
            foreach ($satuan->get($p->Id) ?? [] as $s) {
                $baris->push([$p, $s, 1]);
            }
        }

        return $this->Susun($baris);
    }

    /**
     * @param  Collection<int, array{0: Produk, 1: ProdukSatuan, 2: int}>  $pasangan
     * @return list<array<string, mixed>>
     */
    private function Susun(Collection $pasangan): array
    {
        $idSatuanProduk = $pasangan->map(fn (array $p): int => $p[1]->Id)->all();
        $namaSatuan = Satuan::query()->whereIn('Id', $pasangan->map(fn (array $p): int => $p[1]->IdSatuan)->unique()->all())->get()->keyBy('Id');
        $barcode = ProdukBarcode::query()->whereIn('IdProdukSatuan', $idSatuanProduk)->orderBy('Id')->get()->groupBy('IdProdukSatuan');
        $harga = [];

        foreach (ProdukHarga::query()->whereIn('IdProdukSatuan', $idSatuanProduk)->whereNull('IdDaftarHarga')->orderBy('JumlahMinimum')->orderBy('Id')->get() as $h) {
            $harga[$h->IdProdukSatuan] ??= $h->Harga;
        }

        return array_values($pasangan->map(function (array $p) use ($namaSatuan, $barcode, $harga): array {
            [$produk, $satuanProduk, $jumlah] = $p;
            $unit = $namaSatuan->get($satuanProduk->IdSatuan);

            return [
                'Uuid' => $satuanProduk->Uuid,
                'UuidProduk' => $produk->Uuid,
                'Nama' => $produk->Nama,
                'Sku' => $produk->Sku,
                'NamaSatuan' => (string) $unit?->Nama,
                'SimbolSatuan' => (string) $unit?->Simbol,
                'KonversiKeDasar' => $satuanProduk->KonversiKeDasar,
                'Barcode' => array_values(($barcode->get($satuanProduk->Id) ?? collect())->map(fn (ProdukBarcode $b): string => $b->Barcode)->all()),
                'Harga' => $harga[$satuanProduk->Id] ?? null,
                'Jumlah' => $jumlah,
            ];
        })->all());
    }
}
