<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Data\KonteksKatalogPos;
use App\Domain\Katalog\Kontrak\BagianKatalogPos;
use App\Domain\Katalog\Layanan\OpsiKelompokPajakKatalog;
use App\Domain\Katalog\Layanan\PenyimpanGambarProduk;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\PaketSesi;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;

/**
 * Bagian katalog POS Tim 1 (F-03 D.3): `Produk`, `ProdukSatuan`, `ProdukBarcode`. F-16d bagian 2: `Produk.PaketSesi`
 * (null bila bukan paket sesi); menyimpan definisi paket menyentuh `DiubahPada` produknya sehingga ikut delta.
 * - Lengkap: produk yang belum dihapus (termasuk diarsipkan, `Aktif: false`) beserta satuan & barcodenya.
 * - Delta: baris dengan `DiubahPada ≥ sejak`, termasuk produk terhapus (`Dihapus: true`); satuan & barcode yang
 *   dihapus dikirim lewat `Terhapus`.
 * FK sebagai `Uuid{Tabel}`, jumlah sebagai string, waktu ISO UTC, URL gambar lewat rute POS berversi.
 */
final class ProdukUntukPos implements BagianKatalogPos
{
    public function __construct(private readonly OpsiKelompokPajakKatalog $kelompokPajak) {}

    public function AmbilBagian(KonteksKatalogPos $konteks): array
    {
        $delta = $konteks->sejak !== null;
        $produk = Produk::query()
            ->when($delta, fn ($kueri) => $kueri->withTrashed()->where('DiubahPada', '>=', $konteks->sejak))
            ->orderBy('Id')
            ->get();
        $satuan = ProdukSatuan::query()
            ->when($delta, fn ($kueri) => $kueri->where('DiubahPada', '>=', $konteks->sejak), fn ($kueri) => $kueri->whereHas('Produk'))
            ->orderBy('Id')
            ->get();
        $barcode = ProdukBarcode::query()
            ->when($delta, fn ($kueri) => $kueri->where('DiubahPada', '>=', $konteks->sejak), fn ($kueri) => $kueri->whereHas('Produk'))
            ->orderBy('Id')
            ->get();

        // Hanya Uuid produk yang dirujuk bagian ini (induk varian, pemilik satuan & barcode), bukan seluruh katalog.
        $idProdukDirujuk = $produk->pluck('IdInduk')->filter()
            ->merge($satuan->pluck('IdProduk'))
            ->merge($barcode->pluck('IdProduk'))
            ->unique()
            ->diff($produk->modelKeys())
            ->values()
            ->all();
        $uuidProduk = $produk->pluck('Uuid', 'Id')
            ->union($idProdukDirujuk === [] ? [] : Produk::query()->withTrashed()->whereIn('Id', $idProdukDirujuk)->pluck('Uuid', 'Id'));
        $uuidSatuan = Satuan::query()->pluck('Uuid', 'Id');
        $uuidKategori = Kategori::query()->pluck('Uuid', 'Id');
        $uuidProdukSatuan = ProdukSatuan::query()->whereIn('Id', $barcode->pluck('IdProdukSatuan')->all())->pluck('Uuid', 'Id');
        $uuidKelompokPajak = array_column($this->kelompokPajak->AmbilOpsi(), 'Uuid', 'Id');
        $dasarGambar = url('/api/pos/v1/katalog/gambar/{uuid}');
        // F-16d bagian 2: produk paket sesi (kasir mewajibkan pelanggan & jumlah bulat, tidak bisa diretur).
        $paketSesi = PaketSesi::query()->whereIn('IdProduk', $produk->modelKeys())->get()->keyBy('IdProduk');

        return [
            'Produk' => array_values($produk->map(fn (Produk $p): array => [
                'Uuid' => $p->Uuid,
                'Sku' => $p->Sku,
                'Nama' => $p->Nama,
                'NamaStruk' => $p->NamaStruk,
                'Jenis' => $p->Jenis->value,
                'UuidKategori' => $p->IdKategori === null ? null : $uuidKategori->get($p->IdKategori),
                'Merek' => $p->Merek,
                'UuidSatuanDasar' => $uuidSatuan->get($p->IdSatuanDasar),
                'Pelacakan' => $p->Pelacakan->value,
                // F-05h: masa garansi standar (bulan) produk bernomor seri, untuk kartu garansi di struk cetak.
                'MasaGaransiBulan' => $p->MasaGaransiBulan,
                'UuidKelompokPajak' => $p->IdKelompokPajak === null ? null : ($uuidKelompokPajak[$p->IdKelompokPajak] ?? null),
                'HargaTermasukPajak' => $p->HargaTermasukPajak,
                'BolehMinus' => $p->BolehMinus,
                'TampilDiPos' => $p->TampilDiPos,
                'TampilOnline' => $p->TampilOnline,
                // K-16: produk favorit pilihan pemilik (kasir menampilkannya di kategori "Favorit").
                'Favorit' => $p->Favorit,
                // K-25: kasir mengetik harga per transaksi (harga daftar jadi saran).
                'HargaTerbuka' => $p->HargaTerbuka,
                // Apotek (§9.5): golongan obat & wajib resep (kasir meminta resep/apoteker sebelum bayar).
                'GolonganObat' => $p->GolonganObat?->value,
                'ObatWajibApotek' => $p->ObatWajibApotek,
                'Prekursor' => $p->Prekursor,
                'WajibResep' => $p->CekWajibResep(),
                'UuidInduk' => $p->IdInduk === null ? null : $uuidProduk->get($p->IdInduk),
                'AtributVarian' => $p->AtributVarian,
                'UrlGambar' => PenyimpanGambarProduk::BuatUrl($p, 'besar', $dasarGambar),
                'UrlGambarKecil' => PenyimpanGambarProduk::BuatUrl($p, 'kecil', $dasarGambar),
                'Aktif' => $p->Aktif,
                'PaketSesi' => ($ps = $paketSesi->get($p->Id)) instanceof PaketSesi
                    ? ['JumlahSesi' => $ps->JumlahSesi, 'MasaBerlakuHari' => $ps->MasaBerlakuHari, 'Aktif' => $ps->Aktif]
                    : null,
                'Dihapus' => $p->DihapusPada !== null,
                'DiubahPada' => $p->DiubahPada?->toIso8601ZuluString('microsecond'),
            ])->all()),
            'ProdukSatuan' => array_values($satuan->map(fn (ProdukSatuan $s): array => [
                'Uuid' => $s->Uuid,
                'UuidProduk' => $uuidProduk->get($s->IdProduk),
                'UuidSatuan' => $uuidSatuan->get($s->IdSatuan),
                'KonversiKeDasar' => $s->KonversiKeDasar,
                'DefaultJual' => $s->DefaultJual,
                'DefaultBeli' => $s->DefaultBeli,
            ])->all()),
            'ProdukBarcode' => array_values($barcode->map(fn (ProdukBarcode $b): array => [
                'Uuid' => $b->Uuid,
                'UuidProduk' => $uuidProduk->get($b->IdProduk),
                'UuidProdukSatuan' => $uuidProdukSatuan->get($b->IdProdukSatuan),
                'Barcode' => $b->Barcode,
            ])->all()),
        ];
    }
}
