<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Pilihan\Kueri;

use App\Domain\Katalog\Data\KonteksKatalogPos;
use App\Domain\Katalog\Kontrak\BagianKatalogPos;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Pilihan\Model\KelompokPilihan;
use App\Domain\Katalog\Pilihan\Model\Pilihan;
use App\Domain\Katalog\Pilihan\Model\ProdukKelompokPilihan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Bagian katalog POS `KelompokPilihan`, `Pilihan`, `ProdukKelompokPilihan` (F-03 D.3). Tanpa `sejak`: semua baris
 * (pemasangan ke produk yang sudah dihapus tidak dikirim); dengan `sejak`: baris yang berubah sejak itu. Baris yang
 * dihapus dikirim lewat `Terhapus` (jejak `PenghapusanKatalog`).
 */
final class PilihanUntukPos implements BagianKatalogPos
{
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function AmbilBagian(KonteksKatalogPos $konteks): array
    {
        $uuidKelompok = KelompokPilihan::query()->where('IdTenant', $konteks->idTenant)->pluck('Uuid', 'Id');
        $kelompok = $this->Saring(KelompokPilihan::query(), $konteks)->orderBy('Urutan')->orderBy('Id')->get();
        $pilihan = $this->Saring(Pilihan::query(), $konteks)->orderBy('IdKelompokPilihan')->orderBy('Urutan')->orderBy('Id')->get();
        $tautan = $this->Saring(ProdukKelompokPilihan::query(), $konteks)
            ->when($konteks->sejak === null, fn (Builder $kueri) => $kueri->whereIn('IdProduk', Produk::query()->select('Id')))
            ->orderBy('IdProduk')->orderBy('Urutan')->get();
        $idProduk = $pilihan->pluck('IdProduk')->merge($tautan->pluck('IdProduk'))->filter()->unique()->values()->all();
        $uuidProduk = Produk::query()->withTrashed()->whereKey($idProduk)->pluck('Uuid', 'Id');

        return [
            'KelompokPilihan' => array_values($kelompok->map(fn (KelompokPilihan $baris): array => [
                'Uuid' => $baris->Uuid,
                'Nama' => $baris->Nama,
                'MinimalPilih' => $baris->MinimalPilih,
                'MaksimalPilih' => $baris->MaksimalPilih,
                'Urutan' => $baris->Urutan,
            ])->all()),
            'Pilihan' => array_values($pilihan->map(fn (Pilihan $baris): array => [
                'Uuid' => $baris->Uuid,
                'UuidKelompokPilihan' => $uuidKelompok->get($baris->IdKelompokPilihan),
                'Nama' => $baris->Nama,
                'Harga' => $baris->Harga,
                // X8 (v7.86): harga per kanal ojol, null = sama dengan Harga. Aplikasi lama mengabaikan bidang ini.
                'HargaKanal' => $baris->HargaKanal === [] ? null : $baris->HargaKanal,
                'UuidProdukBahan' => $baris->IdProduk === null ? null : $uuidProduk->get($baris->IdProduk),
                'Jumlah' => $baris->Jumlah,
                'Aktif' => $baris->Aktif,
                'Urutan' => $baris->Urutan,
            ])->all()),
            'ProdukKelompokPilihan' => array_values($tautan->map(fn (ProdukKelompokPilihan $baris): array => [
                'Uuid' => $baris->Uuid,
                'UuidProduk' => $uuidProduk->get($baris->IdProduk),
                'UuidKelompokPilihan' => $uuidKelompok->get($baris->IdKelompokPilihan),
                'Urutan' => $baris->Urutan,
            ])->all()),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $kueri
     * @return Builder<TModel>
     */
    private function Saring(Builder $kueri, KonteksKatalogPos $konteks): Builder
    {
        return $kueri->where($kueri->qualifyColumn('IdTenant'), $konteks->idTenant)
            ->when($konteks->sejak !== null, fn (Builder $isi) => $isi->where($kueri->qualifyColumn('DiubahPada'), '>=', $konteks->sejak));
    }
}
