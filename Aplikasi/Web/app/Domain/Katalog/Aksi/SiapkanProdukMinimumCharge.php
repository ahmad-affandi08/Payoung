<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Data\DataProduk;
use App\Domain\Katalog\Data\DataSatuanProduk;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Enum\SumberPerubahanKatalog;
use App\Domain\Katalog\Kueri\ProdukMinimumCharge;
use App\Domain\Katalog\Layanan\OpsiKelompokPajakKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FnB minimum charge (§9.1, D-144): menyiapkan produk Jasa "Selisih minimum charge" (SKU `MINIMUM-CHARGE`) yang menjadi
 * baris tagihan saat belanja meja kurang dari minimumnya. Produk biasa di katalog (pemilik boleh mengganti namanya atau
 * kelompok pajaknya), harga terbuka (kasir menaruh selisihnya), dan tidak tampil sebagai ubin di kasir. Idempoten:
 * produk yang sudah ada dipakai apa adanya (diaktifkan lagi bila diarsipkan) dan **tidak menghabiskan kuota `BatasSku`**
 * (sumber `Sistem`). Kelompok pajak awal = PB1 (PBJT) bila ada, lalu PPN, lalu kelompok pertama.
 */
final class SiapkanProdukMinimumCharge
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly SimpanProduk $simpanProduk,
        private readonly PastikanSatuan $pastikanSatuan,
        private readonly OpsiKelompokPajakKatalog $kelompokPajak,
    ) {}

    /**
     * @return string Uuid produk
     */
    public function Jalankan(): string
    {
        return DB::transaction(function (): string {
            $this->penguncian->Kunci($this->konteks->Wajib());
            $ada = Produk::query()->withTrashed()->where('Sku', ProdukMinimumCharge::SKU)->first();

            if ($ada !== null && $ada->DihapusPada === null) {
                if (! $ada->Aktif) {
                    $ada->forceFill(['Aktif' => true])->save();
                }

                return $ada->Uuid;
            }

            $satuan = $this->pastikanSatuan->Jalankan('pcs', true);
            $idPajak = $this->PilihKelompokPajak();

            if ($satuan === null || $idPajak === null) {
                throw new PelanggaranAturanBisnis('ProdukMinimumChargeBelumSiap', 'Minimum charge butuh satuan dan kelompok pajak. Terapkan template usaha atau buat kelompok pajak dulu.', 'MinimumCharge');
            }

            $data = new DataProduk(
                uuid: (string) Str::ulid(),
                nama: 'Selisih minimum charge',
                namaStruk: 'Minimum charge',
                sku: ProdukMinimumCharge::SKU,
                jenis: JenisProduk::Jasa,
                idKategori: null,
                merek: null,
                idSatuanDasar: $satuan->Id,
                pelacakan: PelacakanProduk::Tidak,
                idKelompokPajak: $idPajak,
                hargaTermasukPajak: null,
                bolehMinus: null,
                tampilDiPos: false,
                tampilOnline: false,
                satuan: [new DataSatuanProduk(
                    idProdukSatuan: null,
                    idSatuan: $satuan->Id,
                    konversiKeDasar: Kuantitas::Dari(1),
                    defaultJual: true,
                    defaultBeli: false,
                    barcode: [],
                    hargaAwal: [],
                )],
                atributVarian: [],
                bolehUbahHarga: true,
                sumber: SumberPerubahanKatalog::Sistem,
                hargaTerbuka: true,
            );

            return $this->simpanProduk->Jalankan(null, $data)->Uuid;
        });
    }

    private function PilihKelompokPajak(): ?int
    {
        $opsi = $this->kelompokPajak->AmbilOpsi();

        foreach (['KenaPbjt', 'KenaPpn'] as $kategori) {
            foreach ($opsi as $o) {
                if ($o['Kategori'] === $kategori) {
                    return $o['Id'];
                }
            }
        }

        return $opsi[0]['Id'] ?? null;
    }
}
