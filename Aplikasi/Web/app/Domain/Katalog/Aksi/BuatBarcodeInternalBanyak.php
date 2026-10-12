<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukSatuan;
use Illuminate\Support\Facades\DB;

/**
 * PRD-10 (F-03, BR-03.1): buat barcode internal untuk banyak satuan sekaligus dari halaman Cetak label, hanya atas
 * pilihan sadar pengguna. Satuan yang sudah punya barcode tidak diberi barcode kedua (dikembalikan apa adanya), supaya
 * dua klik tidak menghasilkan dua nomor untuk satu barang. Semua atau tidak sama sekali.
 */
final class BuatBarcodeInternalBanyak
{
    public const MAKS = 200;

    public function __construct(private readonly BuatBarcodeInternal $buat) {}

    /**
     * @param  list<string>  $uuidProdukSatuan
     * @return list<array{Uuid: string, Barcode: string, Baru: bool}>
     */
    public function Jalankan(array $uuidProdukSatuan): array
    {
        $uuid = array_values(array_unique($uuidProdukSatuan));

        if ($uuid === [] || count($uuid) > self::MAKS) {
            throw new PelanggaranAturanBisnis('JumlahTidakSah', 'Pilih 1 sampai '.self::MAKS.' satuan produk.', 'Satuan');
        }

        return DB::transaction(function () use ($uuid): array {
            $satuan = ProdukSatuan::query()->whereIn('Uuid', $uuid)->orderBy('Id')->get();

            if ($satuan->count() !== count($uuid)) {
                throw new PelanggaranAturanBisnis('SatuanTidakDitemukan', 'Ada satuan produk yang tidak ditemukan. Muat ulang halaman, lalu coba lagi.', 'Satuan');
            }

            $diarsipkan = Produk::query()->whereIn('Id', $satuan->pluck('IdProduk')->unique()->all())->whereNotNull('DiarsipkanPada')->exists();

            if ($diarsipkan) {
                throw new PelanggaranAturanBisnis('ProdukDiarsipkan', 'Produk yang sudah diarsipkan tidak bisa diberi barcode.', 'Satuan');
            }

            $hasil = [];

            foreach ($satuan as $s) {
                $ada = ProdukBarcode::query()->where('IdProdukSatuan', $s->Id)->orderBy('Id')->value('Barcode');
                $barcode = is_string($ada) ? $ada : $this->buat->Jalankan($s)->Barcode;
                $hasil[$s->Uuid] = ['Uuid' => $s->Uuid, 'Barcode' => $barcode, 'Baru' => ! is_string($ada)];
            }

            return array_values(array_map(fn (string $u): array => $hasil[$u], $uuid));
        });
    }
}
