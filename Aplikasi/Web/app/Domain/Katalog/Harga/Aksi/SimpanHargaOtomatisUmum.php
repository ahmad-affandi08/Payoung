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
 * Satu simpan untuk layar Harga otomatis (D-266): saklar, batas aman, untung umum (aturan "Semua produk"), pembulatan,
 * dan arah. Pembulatan dan arah berlaku sama untuk semua aturan, jadi pengecualian per kategori atau produk ikut
 * disesuaikan dan pemilik toko tidak perlu mengatur tiga hal yang sama berkali-kali. Aturan umum dibuat bila belum ada.
 * Semua atau tidak sama sekali (satu transaksi), memakai aksi pengaturan dan aturan yang sudah ada supaya validasi dan
 * audit sama.
 */
final class SimpanHargaOtomatisUmum
{
    public function __construct(
        private readonly UbahPengaturanHargaOtomatis $pengaturan,
        private readonly SimpanAturanHargaOtomatis $aturan,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis BatasTidakValid, UntungTidakValid, PembulatanTidakDikenal
     */
    public function Jalankan(bool $aktif, string $batasPersen, string $persenUntung, int $pembulatan, ArahHargaOtomatis $arah, int $idPengguna): PengaturanHargaOtomatis
    {
        return DB::transaction(function () use ($aktif, $batasPersen, $persenUntung, $pembulatan, $arah, $idPengguna): PengaturanHargaOtomatis {
            $hasil = $this->pengaturan->Jalankan($aktif, $batasPersen, $idPengguna);

            $umum = AturanHargaOtomatis::query()->where('Cakupan', CakupanAturanHarga::Semua->value)->first();
            $this->aturan->Jalankan($umum?->Uuid, new DataAturanHargaOtomatis(CakupanAturanHarga::Semua, null, null, $persenUntung, $pembulatan, $arah, true), $idPengguna);

            foreach (AturanHargaOtomatis::query()->where('Cakupan', '!=', CakupanAturanHarga::Semua->value)->get() as $lain) {
                if ($lain->Pembulatan === $pembulatan && $lain->Arah === $arah) {
                    continue;
                }

                $uuidKategori = $lain->IdKategori === null ? null : Kategori::query()->whereKey($lain->IdKategori)->value('Uuid');
                $uuidProduk = $lain->IdProduk === null ? null : Produk::query()->whereKey($lain->IdProduk)->value('Uuid');
                $this->aturan->Jalankan($lain->Uuid, new DataAturanHargaOtomatis($lain->Cakupan, $uuidKategori, $uuidProduk, rtrim(rtrim((string) $lain->PersenUntung, '0'), '.'), $pembulatan, $arah, $lain->Aktif), $idPengguna);
            }

            return $hasil;
        });
    }
}
