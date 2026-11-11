<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Layanan;

use App\Domain\Katalog\Model\Kategori;

/**
 * Pohon kategori tenant aktif dalam memori (jumlah kategori kecil): peta induk, kedalaman, jalur nama, turunan.
 * Dipakai Aksi kategori (kedalaman ≤ 3, tanpa siklus) dan kueri daftar/saringan produk.
 */
final class PohonKategoriTenant
{
    /** @var array<int, int|null> */
    private array $induk = [];

    /** @var array<int, string> */
    private array $nama = [];

    public static function Muat(): self
    {
        $pohon = new self;

        foreach (Kategori::query()->get(['Id', 'IdInduk', 'Nama']) as $kategori) {
            $pohon->induk[$kategori->Id] = $kategori->IdInduk;
            $pohon->nama[$kategori->Id] = $kategori->Nama;
        }

        return $pohon;
    }

    /** Kedalaman kategori (akar = 1). */
    public function HitungKedalaman(int $id): int
    {
        $kedalaman = 0;
        $kunjungan = [];

        for ($saat = $id; $saat !== null && ! isset($kunjungan[$saat]); $saat = $this->induk[$saat] ?? null) {
            $kunjungan[$saat] = true;
            $kedalaman++;
        }

        return $kedalaman;
    }

    /**
     * Kategori ini lalu induk-induknya sampai akar (yang terdekat lebih dulu). Dipakai aturan yang diwariskan ke
     * subkategori, misal harga otomatis.
     *
     * @return list<int>
     */
    public function AmbilIdDenganLeluhur(int $id): array
    {
        $hasil = [];

        for ($saat = $id; $saat !== null && ! in_array($saat, $hasil, true); $saat = $this->induk[$saat] ?? null) {
            $hasil[] = $saat;
        }

        return $hasil;
    }

    /** Tinggi subpohon mulai dari kategori ini (tanpa anak = 1). */
    public function HitungTinggi(int $id): int
    {
        $tinggi = 1;

        foreach ($this->AmbilIdAnak($id) as $anak) {
            $tinggi = max($tinggi, 1 + $this->HitungTinggi($anak));
        }

        return $tinggi;
    }

    /**
     * Id kategori ini beserta semua turunannya.
     *
     * @return list<int>
     */
    public function AmbilIdDenganTurunan(int $id): array
    {
        $hasil = [$id];

        foreach ($this->AmbilIdAnak($id) as $anak) {
            array_push($hasil, ...$this->AmbilIdDenganTurunan($anak));
        }

        return $hasil;
    }

    /** Jalur nama "Minuman › Kopi". */
    public function AmbilJalur(int $id): string
    {
        $bagian = [];
        $kunjungan = [];

        for ($saat = $id; $saat !== null && ! isset($kunjungan[$saat]); $saat = $this->induk[$saat] ?? null) {
            $kunjungan[$saat] = true;
            array_unshift($bagian, $this->nama[$saat] ?? '');
        }

        return implode(' › ', $bagian);
    }

    /**
     * @return list<int>
     */
    private function AmbilIdAnak(int $id): array
    {
        return array_keys(array_filter($this->induk, fn (?int $induk): bool => $induk === $id));
    }
}
