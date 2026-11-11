<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Aksi\TetapkanHargaDasarProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use Brick\Math\BigDecimal;

/**
 * Merencanakan harga jual baru satu produk dari modalnya (D-266), tanpa menyimpan apa pun. Dipakai bersama oleh harga
 * otomatis (`PenerapHargaOtomatis`) dan "Harga dari harga beli" massal, supaya keduanya memilih produk, satuan, dan
 * hasil hitungan yang sama.
 *
 * Hanya produk berstok biasa yang aktif dan tidak diarsipkan, dengan harga yang tidak diketik kasir. Harga yang diubah
 * adalah harga dasar (jumlah minimum 1) di satuan jual utama. Modal per satuan jual = modal per satuan dasar × konversi.
 */
final class PerencanaHargaDariModal
{
    public const DILEWATI_JENIS = 'DilewatiJenis';

    public const TANPA_HARGA_BELI = 'TanpaHargaBeli';

    public const TANPA_HARGA = 'TanpaHarga';

    public const SAMA = 'Sama';

    public const BERUBAH = 'Berubah';

    /**
     * @param  array{Modal: string, IdPenerimaanBarang: int}|null  $modalDasar  modal per satuan dasar; null = belum pernah diterima
     * @return array{Status: string, Satuan: ProdukSatuan|null, Lama: Uang|null, ModalJual: BigDecimal|null, Baru: Uang|null}
     */
    public function Rencanakan(Produk $produk, ?array $modalDasar, BigDecimal $persenUntung, int $pembulatan): array
    {
        $kosong = ['Status' => '', 'Satuan' => null, 'Lama' => null, 'ModalJual' => null, 'Baru' => null];

        if ($produk->Jenis !== JenisProduk::Stok || $produk->HargaTerbuka || ! $produk->Aktif || $produk->DiarsipkanPada !== null) {
            return [...$kosong, 'Status' => self::DILEWATI_JENIS];
        }

        if ($modalDasar === null) {
            return [...$kosong, 'Status' => self::TANPA_HARGA_BELI];
        }

        $satuan = TetapkanHargaDasarProduk::AmbilSatuanJualUtama($produk);
        $dasar = $satuan === null ? null : ProdukHarga::query()
            ->where('IdProdukSatuan', $satuan->Id)
            ->whereNull('IdDaftarHarga')
            ->get()
            ->first(fn (ProdukHarga $h): bool => BigDecimal::of($h->JumlahMinimum)->isEqualTo(1));

        if ($satuan === null || $dasar === null || Uang::Dari($dasar->Harga)->BernilaiNol()) {
            return [...$kosong, 'Status' => self::TANPA_HARGA];
        }

        $lama = Uang::Dari($dasar->Harga);
        $modalJual = BigDecimal::of($modalDasar['Modal'])->multipliedBy(BigDecimal::of($satuan->KonversiKeDasar));
        $baru = PenghitungHargaDariModal::Hitung($modalJual, $persenUntung, $pembulatan);

        if ($baru->BernilaiNol() || $baru->BernilaiNegatif()) {
            return [...$kosong, 'Status' => self::TANPA_HARGA_BELI, 'Satuan' => $satuan, 'Lama' => $lama, 'ModalJual' => $modalJual];
        }

        return [
            'Status' => $baru->SamaDengan($lama) ? self::SAMA : self::BERUBAH,
            'Satuan' => $satuan,
            'Lama' => $lama,
            'ModalJual' => $modalJual,
            'Baru' => $baru,
        ];
    }
}
