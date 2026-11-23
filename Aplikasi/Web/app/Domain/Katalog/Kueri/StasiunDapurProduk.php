<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;

/**
 * Kueri publik F-10b: stasiun dapur tiap produk. D-310: stasiun yang diatur langsung di produk menang (varian memakai
 * stasiun produk induknya). Bila tidak ada, dari kategorinya (varian memakai kategori induknya): kategori tanpa stasiun
 * mewarisi stasiun kategori induk terdekat. null = belum diatur (dirutekan ke stasiun bawaan oleh pemanggil).
 */
final class StasiunDapurProduk
{
    /**
     * @param  list<int>  $idProduk
     * @param  bool  $abaikanProduk  true = hanya dari kategori (tanpa stasiun yang diatur langsung di produk)
     * @return array<int, int|null> IdStasiunDapur per IdProduk
     */
    public function AmbilPerProduk(array $idProduk, bool $abaikanProduk = false): array
    {
        if ($idProduk === []) {
            return [];
        }

        $produk = Produk::query()->withTrashed()->whereKey($idProduk)->get(['Id', 'IdInduk', 'IdKategori', 'IdStasiunDapur']);
        $idInduk = $produk->pluck('IdInduk')->filter()->unique()->values()->all();
        $induk = $idInduk === [] ? collect() : Produk::query()->withTrashed()->whereKey($idInduk)->get(['Id', 'IdKategori', 'IdStasiunDapur'])->keyBy('Id');
        $kategori = Kategori::query()->get(['Id', 'IdInduk', 'IdStasiunDapur'])->keyBy('Id');

        $stasiunKategori = function (?int $idKategori) use ($kategori): ?int {
            $langkah = 0;

            while ($idKategori !== null && $langkah++ < 5) {
                $baris = $kategori->get($idKategori);

                if ($baris === null) {
                    return null;
                }

                if ($baris->IdStasiunDapur !== null) {
                    return $baris->IdStasiunDapur;
                }

                $idKategori = $baris->IdInduk;
            }

            return null;
        };

        $hasil = [];

        foreach ($produk as $p) {
            $produkInduk = $p->IdInduk === null ? null : $induk->get($p->IdInduk);
            $langsung = $abaikanProduk ? null : ($p->IdStasiunDapur ?? $produkInduk?->IdStasiunDapur);

            if ($langsung !== null) {
                $hasil[$p->Id] = $langsung;

                continue;
            }

            $idKategori = $p->IdKategori ?? $produkInduk?->IdKategori;
            $hasil[$p->Id] = $stasiunKategori(is_int($idKategori) ? $idKategori : null);
        }

        return $hasil;
    }

    /**
     * Cetak struk bagian 4c: stasiun yang diatur langsung di kategori (tanpa warisan; perangkat menelusuri induknya
     * sendiri seperti [AmbilPerProduk]) untuk merutekan tiket dapur tercetak secara offline.
     *
     * @return array<string, int> IdStasiunDapur per Uuid kategori
     */
    public function AmbilPerKategori(): array
    {
        return Kategori::query()->whereNotNull('IdStasiunDapur')->pluck('IdStasiunDapur', 'Uuid')
            ->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * D-310: stasiun yang diatur langsung di produk (menimpa kategori) untuk merutekan tiket dapur tercetak di perangkat.
     * Varian ikut produk induknya di sisi perangkat, jadi hanya produk yang diatur yang muncul di sini.
     *
     * @return array<string, int> IdStasiunDapur per Uuid produk
     */
    public function AmbilPerProdukLangsung(): array
    {
        return Produk::query()->whereNotNull('IdStasiunDapur')->pluck('IdStasiunDapur', 'Uuid')
            ->map(fn ($id): int => (int) $id)->all();
    }
}
