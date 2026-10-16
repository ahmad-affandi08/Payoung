<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Data;

/**
 * Hasil membersihkan sisa template sektor lama (ganti jenis usaha): yang dibuang (atau akan dibuang pada pratinjau)
 * dan yang dipertahankan beserta alasannya. Hanya data yang belum dipakai yang dibuang.
 */
final readonly class HasilPembersihanTemplate
{
    /**
     * @param  list<string>  $dihapus
     * @param  list<array{Nama: string, Alasan: string}>  $dipertahankan
     */
    public function __construct(public array $dihapus = [], public array $dipertahankan = []) {}
}
