<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Data\DataAturanHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;
use App\Domain\Katalog\Harga\Layanan\PenghitungHargaDariModal;
use App\Domain\Katalog\Harga\Model\AturanHargaOtomatis;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * Menambah atau mengubah satu aturan harga otomatis (D-266). Satu aturan per cakupan: satu untuk Semua, satu per
 * kategori, satu per produk (tidak ada aturan ganda). Cakupan Produk hanya untuk produk berstok biasa yang harganya
 * tidak diketik kasir, karena hanya itu yang bisa mengikuti harga beli. Menyimpan tidak mengubah harga apa pun, harga
 * baru berubah di penerimaan barang berikutnya atau lewat "Terapkan sekarang". Audit `harga-otomatis.aturan.simpan`.
 */
final class SimpanAturanHargaOtomatis
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  string|null  $uuid  aturan yang diubah; null = aturan baru
     *
     * @throws PelanggaranAturanBisnis AturanTidakDikenal, KategoriTidakDikenal, ProdukTidakDikenal, ProdukTidakBisaOtomatis, AturanSudahAda, UntungTidakValid, PembulatanTidakDikenal
     */
    public function Jalankan(?string $uuid, DataAturanHargaOtomatis $data, int $idPengguna): AturanHargaOtomatis
    {
        $persen = PenghitungHargaDariModal::AmbilPersen($data->persenUntung);
        PenghitungHargaDariModal::PastikanPembulatanSah($data->pembulatan);

        return DB::transaction(function () use ($uuid, $data, $persen, $idPengguna): AturanHargaOtomatis {
            $this->penguncian->Kunci($this->konteks->Wajib());
            $aturan = $uuid === null ? new AturanHargaOtomatis : AturanHargaOtomatis::query()->where('Uuid', $uuid)->first();

            if ($aturan === null) {
                throw new PelanggaranAturanBisnis('AturanTidakDikenal', 'Aturan tidak ditemukan. Muat ulang halaman.', 'Uuid');
            }

            [$idKategori, $idProduk] = $this->AmbilSasaran($data);

            $ganda = AturanHargaOtomatis::query()
                ->where('Cakupan', $data->cakupan->value)
                ->when($idKategori !== null, fn ($q) => $q->where('IdKategori', $idKategori))
                ->when($idProduk !== null, fn ($q) => $q->where('IdProduk', $idProduk))
                ->when($aturan->exists, fn ($q) => $q->where('Id', '!=', $aturan->Id))
                ->exists();

            if ($ganda) {
                throw new PelanggaranAturanBisnis('AturanSudahAda', 'Sudah ada aturan untuk cakupan ini. Ubah aturan yang ada.', 'Cakupan');
            }

            $lama = $aturan->exists ? $this->Ringkas($aturan) : null;
            $aturan->Cakupan = $data->cakupan;
            $aturan->IdKategori = $idKategori;
            $aturan->IdProduk = $idProduk;
            $aturan->PersenUntung = (string) $persen;
            $aturan->Pembulatan = $data->pembulatan;
            $aturan->Arah = $data->arah;
            $aturan->Aktif = $data->aktif;
            $aturan->DiubahOleh = $idPengguna;
            $aturan->save();

            $this->audit->Catat('harga-otomatis.aturan.simpan', $aturan, $lama, $this->Ringkas($aturan), idPengguna: $idPengguna);

            return $aturan;
        });
    }

    /**
     * @return array{0: int|null, 1: int|null} IdKategori, IdProduk
     */
    private function AmbilSasaran(DataAturanHargaOtomatis $data): array
    {
        if ($data->cakupan === CakupanAturanHarga::Semua) {
            return [null, null];
        }

        if ($data->cakupan === CakupanAturanHarga::Kategori) {
            $kategori = $data->uuidKategori === null ? null : Kategori::query()->where('Uuid', $data->uuidKategori)->first();

            if ($kategori === null) {
                throw new PelanggaranAturanBisnis('KategoriTidakDikenal', 'Pilih kategori yang ada.', 'UuidKategori');
            }

            return [$kategori->Id, null];
        }

        $produk = $data->uuidProduk === null ? null : Produk::query()->where('Uuid', $data->uuidProduk)->first();

        if ($produk === null) {
            throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Pilih produk yang ada.', 'UuidProduk');
        }

        if ($produk->Jenis !== JenisProduk::Stok || $produk->HargaTerbuka) {
            throw new PelanggaranAturanBisnis('ProdukTidakBisaOtomatis', 'Hanya produk berstok yang harganya tidak diketik kasir yang bisa mengikuti harga beli.', 'UuidProduk');
        }

        return [null, $produk->Id];
    }

    /**
     * @return array<string, mixed>
     */
    private function Ringkas(AturanHargaOtomatis $aturan): array
    {
        return [
            'Cakupan' => $aturan->Cakupan->value,
            'IdKategori' => $aturan->IdKategori,
            'IdProduk' => $aturan->IdProduk,
            'PersenUntung' => (string) $aturan->PersenUntung,
            'Pembulatan' => $aturan->Pembulatan,
            'Arah' => $aturan->Arah->value,
            'Aktif' => $aturan->Aktif,
        ];
    }
}
