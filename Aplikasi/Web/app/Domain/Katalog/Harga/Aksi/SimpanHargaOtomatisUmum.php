<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Harga\Data\DataAturanHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;
use App\Domain\Katalog\Harga\Model\AturanHargaOtomatis;
use App\Domain\Katalog\Harga\Model\PengaturanHargaOtomatis;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use Illuminate\Support\Facades\DB;

/**
 * Satu simpan untuk seluruh layar Harga otomatis (D-266): saklar, batas persetujuan, untung utama (aturan "Semua
 * produk"), pembulatan, arah, dan daftar untung khusus per kategori atau produk. Daftar untung khusus yang dikirim
 * **menggantikan** daftar yang ada: yang baru ditambah, yang sudah ada diperbarui, yang tidak lagi dikirim dihapus.
 * Pembulatan dan arah berlaku sama untuk semua aturan, jadi pemilik toko tidak mengatur hal yang sama berkali-kali.
 * Semua atau tidak sama sekali (satu transaksi), lewat aksi pengaturan, aturan, dan hapus yang sudah ada supaya validasi
 * dan audit sama.
 */
final class SimpanHargaOtomatisUmum
{
    public function __construct(
        private readonly UbahPengaturanHargaOtomatis $pengaturan,
        private readonly SimpanAturanHargaOtomatis $aturan,
        private readonly HapusAturanHargaOtomatis $hapus,
    ) {}

    /**
     * @param  list<DataAturanHargaOtomatis>  $khusus  hanya cakupan Kategori atau Produk; pembulatan dan arahnya diabaikan (mengikuti pengaturan utama)
     *
     * @throws PelanggaranAturanBisnis BatasTidakValid, UntungTidakValid, PembulatanTidakDikenal, AturanSudahAda, KategoriTidakDikenal, ProdukTidakDikenal, ProdukTidakBisaOtomatis
     */
    public function Jalankan(bool $aktif, string $batasPersen, string $persenUntung, int $pembulatan, ArahHargaOtomatis $arah, array $khusus, int $idPengguna): PengaturanHargaOtomatis
    {
        $diminta = [];

        foreach ($khusus as $k) {
            $kunci = $k->cakupan->value.':'.($k->cakupan === CakupanAturanHarga::Kategori ? $k->uuidKategori : $k->uuidProduk);

            if ($k->cakupan === CakupanAturanHarga::Semua || isset($diminta[$kunci])) {
                throw new PelanggaranAturanBisnis('AturanSudahAda', 'Satu kategori atau produk hanya boleh punya satu untung khusus.', 'Khusus');
            }

            $diminta[$kunci] = $k;
        }

        return DB::transaction(function () use ($aktif, $batasPersen, $persenUntung, $pembulatan, $arah, $diminta, $idPengguna): PengaturanHargaOtomatis {
            $hasil = $this->pengaturan->Jalankan($aktif, $batasPersen, $idPengguna);

            $umum = AturanHargaOtomatis::query()->where('Cakupan', CakupanAturanHarga::Semua->value)->first();
            $this->aturan->Jalankan($umum?->Uuid, new DataAturanHargaOtomatis(CakupanAturanHarga::Semua, null, null, $persenUntung, $pembulatan, $arah, true), $idPengguna);

            // Untung khusus yang ada, dikenali dengan kunci yang sama seperti yang dikirim dari layar.
            $ada = AturanHargaOtomatis::query()->where('Cakupan', '!=', CakupanAturanHarga::Semua->value)->get();
            $uuidKategori = Kategori::query()->whereIn('Id', $ada->pluck('IdKategori')->filter()->all())->pluck('Uuid', 'Id');
            $uuidProduk = Produk::query()->whereIn('Id', $ada->pluck('IdProduk')->filter()->all())->pluck('Uuid', 'Id');
            $peta = [];

            foreach ($ada as $a) {
                $peta[$a->Cakupan->value.':'.($a->Cakupan === CakupanAturanHarga::Kategori ? $uuidKategori[$a->IdKategori] : $uuidProduk[$a->IdProduk])] = $a;
            }

            foreach ($peta as $kunci => $a) {
                if (! isset($diminta[$kunci])) {
                    $this->hapus->Jalankan($a->Uuid, $idPengguna);
                }
            }

            foreach ($diminta as $kunci => $k) {
                $this->aturan->Jalankan(
                    isset($peta[$kunci]) ? $peta[$kunci]->Uuid : null,
                    new DataAturanHargaOtomatis($k->cakupan, $k->uuidKategori, $k->uuidProduk, $k->persenUntung, $pembulatan, $arah, true),
                    $idPengguna,
                );
            }

            return $hasil;
        });
    }
}
