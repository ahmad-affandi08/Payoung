<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Data;

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\LokasiProduksi;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Enum\SumberPerubahanKatalog;

/**
 * Isi lengkap produk dari form back-office atau impor (F-03 `SimpanProduk`). `uuid` = kunci idempotensi saat membuat.
 * `sku` kosong/null = dibuat otomatis. `hargaTermasukPajak`/`bolehMinus` null = ikut outlet/tenant.
 * `bolehUbahHarga` = pelaku memegang izin `produk.harga.ubah` (syarat `hargaAwal`).
 */
final readonly class DataProduk
{
    /**
     * @param  list<DataSatuanProduk>  $satuan
     * @param  list<DataAtributVarian>  $atributVarian
     */
    public function __construct(
        public string $uuid,
        public string $nama,
        public ?string $namaStruk,
        public ?string $sku,
        public JenisProduk $jenis,
        public ?int $idKategori,
        public ?string $merek,
        public int $idSatuanDasar,
        public PelacakanProduk $pelacakan,
        public ?int $idKelompokPajak,
        public ?bool $hargaTermasukPajak,
        public ?bool $bolehMinus,
        public bool $tampilDiPos,
        public bool $tampilOnline,
        public array $satuan,
        public array $atributVarian,
        public bool $bolehUbahHarga,
        public SumberPerubahanKatalog $sumber = SumberPerubahanKatalog::Manual,
        // F-07 mode service: lama layanan jasa (menit) untuk slot reservasi; hanya untuk jenis Jasa.
        public ?int $durasiMenit = null,
        // F-05h: masa garansi standar (bulan) produk bernomor seri; null = tanpa garansi.
        public ?int $masaGaransiBulan = null,
        // Kode Coretax (v3.11): kode barang/jasa 6 digit & unit `UM.00xx`; null = kode umum saat ekspor.
        public ?string $kodeBarangJasaCoretax = null,
        public ?string $kodeUnitCoretax = null,
        // K-25: harga diketik kasir; null = tidak diubah (impor & panduan awal).
        public ?bool $hargaTerbuka = null,
        // Apotek (§9.5): golongan obat, OWA, prekursor; null = tidak diubah (impor & sumber tanpa isian obat).
        public ?DataObatProduk $obat = null,
        // K-16: produk favorit di kasir; null = tidak diubah (klien lama, impor, panduan awal); produk baru = false.
        public ?bool $favorit = null,
        // BR-05.4: lokasi produksi produk Resep (Dapur/Bar); null = Toko. Hanya form back-office yang mengubahnya.
        public ?LokasiProduksi $lokasiProduksi = null,
    ) {}
}
