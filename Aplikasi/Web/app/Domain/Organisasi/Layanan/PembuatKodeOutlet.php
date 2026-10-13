<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Layanan;

use Illuminate\Support\Str;

/**
 * Kode outlet pertama yang mudah dikenali, diturunkan dari nama usaha (bukan lagi `UTAMA` untuk semua tenant): kode ini
 * muncul di nomor dokumen (`INV/RSA/2610/K01-0001`) dan kode perangkat (`RSA-K01`), jadi sebaiknya terbaca sebagai
 * singkatan usahanya. Aturan: kata bukan-inti (Toko, Warung, PT, CV, UD, ...) dibuang; satu kata → empat huruf
 * pertama; dua kata atau lebih → huruf pertama tiap kata (maksimal lima kata), dilengkapi konsonan berikutnya dari kata
 * terakhir sampai minimal tiga huruf (Raja Sayur → RSY). Hanya A–Z dan angka (kode outlet harus 3–5 karakter, diawali huruf, BR-02.2).
 * Tidak menjamin unik antar-tenant (tidak perlu: kode unik per tenant); pemilik bisa menggantinya selama belum ada
 * perangkat terpasang.
 */
final class PembuatKodeOutlet
{
    public const KODE_CADANGAN = 'UTAMA';

    /** Kata yang menjelaskan jenis/badan usaha, bukan namanya, sehingga tidak dipakai sebagai singkatan. */
    private const KATA_ABAIKAN = ['PT', 'CV', 'UD', 'PD', 'TOKO', 'WARUNG', 'KEDAI', 'USAHA', 'DAN', 'DI', 'THE', 'AND'];

    public static function Buat(string $namaUsaha): string
    {
        $kata = array_values(array_filter(
            preg_split('/[^A-Z0-9]+/', strtoupper(Str::ascii($namaUsaha))) ?: [],
            fn (string $k): bool => $k !== '',
        ));
        $inti = array_values(array_filter($kata, fn (string $k): bool => ! in_array($k, self::KATA_ABAIKAN, true)));
        $kata = $inti === [] ? $kata : $inti;

        if ($kata === []) {
            return self::KODE_CADANGAN;
        }

        $kode = count($kata) === 1
            ? substr($kata[0], 0, 4)
            : self::DariSingkatan($kata);

        return preg_match('/^[A-Z][A-Z0-9]{2,4}$/', $kode) === 1 ? $kode : self::KODE_CADANGAN;
    }

    /**
     * @param  list<string>  $kata
     */
    private static function DariSingkatan(array $kata): string
    {
        $kata = array_slice($kata, 0, 5);
        $kode = implode('', array_map(fn (string $k): string => $k[0], $kata));
        $terakhir = $kata[count($kata) - 1];

        // Kurang dari tiga huruf: tambah konsonan berikutnya dari kata terakhir (Sayur → Y), lalu huruf apa pun.
        foreach ([true, false] as $hanyaKonsonan) {
            for ($i = 1; $i < strlen($terakhir) && strlen($kode) < 3; $i++) {
                $vokal = in_array($terakhir[$i], ['A', 'I', 'U', 'E', 'O'], true);

                if (! $hanyaKonsonan || ! $vokal) {
                    $kode .= $terakhir[$i];
                }
            }
        }

        return $kode;
    }
}
