<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;

/**
 * Pencarian jasa & produk tanpa stok untuk baris invoice (F-21): hanya `Jasa` dan `NonStok`, aktif, yang Nama/SKU-nya
 * mengandung `kata`. Membawa harga dasar satuan dasar (harga saran yang boleh diubah di baris), satuan, dan kelompok
 * pajak bawaan produk. Barang berstok sengaja tidak muncul: menagihnya adalah urusan surat jalan grosir (BR-12.2).
 */
final class CariProdukInvoice
{
    public function __construct(private readonly DaftarKelompokPajak $kelompokPajak) {}

    /**
     * @return list<array{Uuid: string, Nama: string, Sku: string|null, Jenis: string, SimbolSatuan: string|null, Harga: string|null, UuidKelompokPajak: string|null, HargaTermasukPajak: bool|null}>
     */
    public function Cari(string $kata, int $batas = 20): array
    {
        $kata = trim($kata);
        $pola = '%'.addcslashes($kata, '%_\\').'%';
        $produk = Produk::query()
            ->whereNull('DiarsipkanPada')
            ->where('Aktif', true)
            ->whereIn('Jenis', [JenisProduk::Jasa->value, JenisProduk::NonStok->value])
            ->when($kata !== '', fn ($kueri) => $kueri->where(fn ($dalam) => $dalam->where('Nama', 'like', $pola)->orWhere('Sku', 'like', $pola)))
            ->orderBy('Nama')
            ->orderBy('Id')
            ->limit(max(1, min(50, $batas)))
            ->get();

        if ($produk->isEmpty()) {
            return [];
        }

        $satuanDasar = ProdukSatuan::query()->whereIn('IdProduk', $produk->modelKeys())->orderBy('KonversiKeDasar')->orderBy('Id')->get()->groupBy('IdProduk')
            ->map(fn ($daftar) => $daftar->first());
        $harga = ProdukHarga::query()
            ->whereIn('IdProdukSatuan', $satuanDasar->pluck('Id')->all())
            ->whereNull('IdDaftarHarga')
            ->where('JumlahMinimum', '1')
            ->pluck('Harga', 'IdProdukSatuan');
        $simbol = Satuan::query()->whereIn('Id', $produk->pluck('IdSatuanDasar')->all())->pluck('Simbol', 'Id');
        $uuidKelompok = [];

        foreach ($this->kelompokPajak->AmbilOpsi() as $opsi) {
            $uuidKelompok[$opsi['Id']] = $opsi['Uuid'];
        }

        return array_values($produk->map(function (Produk $p) use ($satuanDasar, $harga, $simbol, $uuidKelompok): array {
            $ps = $satuanDasar->get($p->Id);
            $hargaDasar = $ps === null ? null : $harga->get($ps->Id);

            return [
                'Uuid' => $p->Uuid,
                'Nama' => $p->Nama,
                'Sku' => $p->Sku,
                'Jenis' => $p->Jenis->value,
                'SimbolSatuan' => $simbol->get($p->IdSatuanDasar),
                'Harga' => $hargaDasar === null ? null : (string) $hargaDasar,
                'UuidKelompokPajak' => $p->IdKelompokPajak === null ? null : ($uuidKelompok[$p->IdKelompokPajak] ?? null),
                'HargaTermasukPajak' => $p->HargaTermasukPajak,
            ];
        })->all());
    }
}
