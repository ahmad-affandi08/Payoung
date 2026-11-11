<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Data;

/**
 * Ringkasan satu putaran harga otomatis (D-266): berapa harga yang diterapkan, berapa yang ditahan jadi usulan karena
 * melewati batas aman, berapa yang sudah pas, dan berapa produk yang dilewati (tanpa harga dasar, harga nol, dan
 * sebagainya). `perubahan` memuat nama dan harga lama/baru untuk kabar ke pemilik.
 */
final class HasilHargaOtomatis
{
    public int $diterapkan = 0;

    public int $diusulkan = 0;

    public int $tetap = 0;

    public int $dilewati = 0;

    /** @var list<array{Nama: string, Lama: string, Baru: string}> */
    public array $perubahan = [];

    public function CekAdaYangBerubah(): bool
    {
        return $this->diterapkan > 0 || $this->diusulkan > 0;
    }
}
