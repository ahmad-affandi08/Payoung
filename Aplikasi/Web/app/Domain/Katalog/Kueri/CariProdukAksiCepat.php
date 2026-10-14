<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use Brick\Math\BigDecimal;

/**
 * Pencarian produk untuk aksi cepat Aplikasi Owner (OWN-07): produk aktif yang Nama/SKU-nya mengandung `kata` (atau
 * barcodenya persis), dengan harga dasar satuan jual utamanya dan, bila outlet diberikan, status habis ("86") di outlet itu
 * untuk kanal kasir. Induk varian tampil tetapi tidak bisa diubah harganya (`BisaUbahHarga` false); tandai habis hanya
 * untuk yang bisa dijual langsung (`BisaTandaiHabis`).
 */
final class CariProdukAksiCepat
{
    public function __construct(private readonly ProdukHabisOutlet $habis) {}

    /**
     * @return list<array{Uuid: string, Nama: string, Sku: string|null, Jenis: string, HargaDasar: string|null, BisaUbahHarga: bool, BisaTandaiHabis: bool, Habis: bool|null}>
     */
    public function Cari(string $kata, ?int $idOutlet, int $batas = 20): array
    {
        $kata = trim($kata);
        $pola = '%'.addcslashes($kata, '%_\\').'%';
        $produk = Produk::query()
            ->whereNull('DiarsipkanPada')
            ->where('Aktif', true)
            ->when($kata !== '', fn ($kueri) => $kueri->where(fn ($dalam) => $dalam
                ->where('Nama', 'like', $pola)
                ->orWhere('Sku', 'like', $pola)
                ->orWhereIn('Id', ProdukBarcode::query()->where('Barcode', $kata)->select('IdProduk'))))
            ->orderBy('Nama')
            ->orderBy('Id')
            ->limit(max(1, min(50, $batas)))
            ->get();
        $satuan = ProdukSatuan::query()->whereIn('IdProduk', $produk->modelKeys())->orderByDesc('DefaultJual')->orderBy('Id')->get()->groupBy('IdProduk');
        $harga = ProdukHarga::query()
            ->whereIn('IdProduk', $produk->modelKeys())
            ->whereNull('IdDaftarHarga')
            ->orderBy('JumlahMinimum')
            ->get()
            ->filter(fn (ProdukHarga $h): bool => BigDecimal::of($h->JumlahMinimum)->isEqualTo(1))
            ->groupBy('IdProdukSatuan');
        $uuidHabis = $idOutlet === null ? [] : array_flip($this->habis->AmbilUuid($idOutlet));

        return array_values($produk->map(function (Produk $p) use ($satuan, $harga, $idOutlet, $uuidHabis): array {
            $induk = $p->Jenis === JenisProduk::IndukVarian;
            $utama = $satuan->get($p->Id)?->first();
            $dasar = $utama === null ? null : $harga->get($utama->Id)?->first()?->Harga;

            return [
                'Uuid' => $p->Uuid,
                'Nama' => $p->Nama,
                'Sku' => $p->Sku,
                'Jenis' => $p->Jenis->value,
                'HargaDasar' => $dasar,
                'BisaUbahHarga' => ! $induk && $dasar !== null,
                'BisaTandaiHabis' => ! $induk,
                'Habis' => $idOutlet === null || $induk ? null : array_key_exists($p->Uuid, $uuidHabis),
            ];
        })->all());
    }
}
