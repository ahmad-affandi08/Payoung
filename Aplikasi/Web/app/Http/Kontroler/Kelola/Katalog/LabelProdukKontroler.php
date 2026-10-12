<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Katalog;

use App\Domain\Katalog\Aksi\BuatBarcodeInternalBanyak;
use App\Domain\Katalog\Kueri\BarisLabelProduk;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Http\Permintaan\Kelola\Katalog\BuatBarcodeLabelPermintaan;
use App\Http\Permintaan\Kelola\Katalog\HalamanLabelProdukPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD-10 (F-03): halaman Cetak label harga/barcode. Cetaknya sendiri di peramban (lembar A4 stiker atau label gulung);
 * server hanya menyediakan baris (satuan produk + barcode + harga dasar), pencarian untuk menambah baris, dan pembuatan
 * barcode internal atas pilihan sadar pengguna.
 */
final class LabelProdukKontroler extends DasarKatalogKontroler
{
    public function Halaman(HalamanLabelProdukPermintaan $permintaan, BarisLabelProduk $baris): Response
    {
        $dariProduk = $baris->DariProduk($permintaan->AmbilUuidProduk());
        $dariItem = $baris->DariItem($permintaan->AmbilItem());
        $gabungan = [];

        foreach ([...$dariItem['Baris'], ...$dariProduk['Baris']] as $b) {
            $gabungan[$b['Uuid']] ??= $b;
        }

        return Inertia::render('Kelola/Produk/Label', [
            'Baris' => array_slice(array_values($gabungan), 0, BarisLabelProduk::MAKS_BARIS),
            'Terpotong' => $dariProduk['Terpotong'] || $dariItem['Terpotong'] || count($gabungan) > BarisLabelProduk::MAKS_BARIS,
            'Batas' => ['Baris' => BarisLabelProduk::MAKS_BARIS, 'JumlahPerBaris' => BarisLabelProduk::MAKS_JUMLAH, 'Total' => 5000],
            'Izin' => ['Kelola' => $this->CekIzin(IzinTenant::ProdukKelola)],
        ]);
    }

    public function Cari(Request $permintaan, BarisLabelProduk $baris): JsonResponse
    {
        return response()->json([
            'Data' => $baris->Cari(mb_substr(trim($permintaan->string('kata')->toString()), 0, 100), $permintaan->integer('batas', 20)),
        ]);
    }

    public function BuatBarcode(BuatBarcodeLabelPermintaan $permintaan, BuatBarcodeInternalBanyak $buat): JsonResponse
    {
        /** @var list<string> $satuan */
        $satuan = array_values(array_map(strval(...), (array) $permintaan->validated('Satuan')));

        return response()->json(['Data' => $buat->Jalankan($satuan)]);
    }
}
