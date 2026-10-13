<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Grosir;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use Carbon\CarbonImmutable;

/**
 * Aturan validasi & konversi bersama formulir grosir (F-12, §9.7): uang string desimal ≤ 2 angka, jumlah ≤ 4 angka
 * (tidak pernah float, CLAUDE.md #7), tanggal `TTTT-BB-HH`.
 *
 * Kembaran `Pembelian\AturanPembelian`. Sengaja tidak diangkat ke satu tempat bersama dalam tugas ini, karena itu
 * berarti menyentuh enam formulir pembelian yang sudah berjalan tanpa alasan dari tugas grosir; kalau modul ketiga
 * membutuhkannya, keduanya disatukan.
 */
final class AturanGrosir
{
    public const UANG = 'regex:/^\d{1,16}(\.\d{1,2})?$/';

    public const JUMLAH = 'regex:/^\d{1,14}(\.\d{1,4})?$/';

    /**
     * @return array<string, string>
     */
    public static function Pesan(): array
    {
        return ['regex' => ':Attribute harus angka dengan pemisah desimal titik (uang maks. 2 desimal, jumlah maks. 4 desimal).'];
    }

    public static function Uang(mixed $nilai, string $bawaan = '0'): Uang
    {
        return Uang::Dari(is_string($nilai) && $nilai !== '' ? $nilai : $bawaan);
    }

    public static function Jumlah(mixed $nilai): Kuantitas
    {
        return Kuantitas::Dari(is_string($nilai) && $nilai !== '' ? $nilai : '0');
    }

    public static function Tanggal(mixed $nilai): ?CarbonImmutable
    {
        if (! is_string($nilai) || $nilai === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $nilai) ?: null;
    }

    public static function Teks(mixed $nilai): ?string
    {
        return is_string($nilai) && trim($nilai) !== '' ? trim($nilai) : null;
    }

    /**
     * Daftar teks (mis. nomor seri) yang sudah dipangkas; elemen kosong dibuang.
     *
     * @return list<string>
     */
    public static function DaftarTeks(mixed $nilai): array
    {
        if (! is_array($nilai)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $n): string => is_string($n) ? trim($n) : '', $nilai), fn (string $n): bool => $n !== ''));
    }
}
