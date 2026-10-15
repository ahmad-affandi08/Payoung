<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Audit\Layanan;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Mengubah `NilaiLama`/`NilaiBaru` log audit (JSON mentah) menjadi daftar perubahan yang bisa dibaca: nama bidang
 * dalam kata biasa, nilai Ya/Tidak untuk boolean, waktu dalam WIB, dan hanya bidang yang benar-benar berubah. Nilai
 * rahasia ditutup (`••••`) walaupun seharusnya tidak pernah dicatat. Dipakai halaman log audit tenant dan pengelola.
 */
final class PenerjemahPerubahanAudit
{
    public const MAKS_BIDANG = 20;

    private const MAKS_PANJANG_NILAI = 160;

    private const POLA_RAHASIA = '/kata.?sandi|password|\bpin\b|token|rahasia|secret|kredensial|kunci.?pribadi|otp|kode.?pemulihan/i';

    /**
     * @param  array<string, mixed>|null  $lama
     * @param  array<string, mixed>|null  $baru
     * @return list<array{Bidang: string, Lama: string|null, Baru: string|null}>
     */
    public static function Terjemahkan(?array $lama, ?array $baru): array
    {
        $lama ??= [];
        $baru ??= [];
        $hasil = [];

        foreach (array_unique([...array_keys($lama), ...array_keys($baru)]) as $kunci) {
            $kunci = (string) $kunci;
            $adaLama = array_key_exists($kunci, $lama);
            $adaBaru = array_key_exists($kunci, $baru);

            if ($adaLama && $adaBaru && $lama[$kunci] === $baru[$kunci]) {
                continue;
            }

            $hasil[] = [
                'Bidang' => self::LabelBidang($kunci),
                'Lama' => $adaLama ? self::FormatNilai($kunci, $lama[$kunci]) : null,
                'Baru' => $adaBaru ? self::FormatNilai($kunci, $baru[$kunci]) : null,
            ];

            if (count($hasil) >= self::MAKS_BIDANG) {
                break;
            }
        }

        return $hasil;
    }

    /** "IdPenanggungJawab" menjadi "Penanggung jawab", "DibuatPada" menjadi "Dibuat pada", "harga_beli" menjadi "Harga beli". */
    public static function LabelBidang(string $kunci): string
    {
        $tanpaAwalan = (string) preg_replace('/^(?:Id|Uuid)(?=[A-Z])/', '', $kunci);
        $dipisah = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', str_replace(['_', '-'], ' ', $tanpaAwalan));
        $kata = mb_strtolower(trim($dipisah));

        return $kata === '' ? $kunci : mb_strtoupper(mb_substr($kata, 0, 1)).mb_substr($kata, 1);
    }

    public static function FormatNilai(string $kunci, mixed $nilai): string
    {
        if ($nilai === null || $nilai === '') {
            return '—';
        }

        if (preg_match(self::POLA_RAHASIA, $kunci) === 1) {
            return '••••';
        }

        if (is_bool($nilai)) {
            return $nilai ? 'Ya' : 'Tidak';
        }

        if (is_array($nilai)) {
            return self::Potong(self::FormatDaftar($nilai));
        }

        $teks = (string) $nilai;

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $teks) === 1) {
            try {
                return CarbonImmutable::parse($teks)->setTimezone('Asia/Jakarta')->translatedFormat('j M Y, H.i').' WIB';
            } catch (Throwable) {
                return self::Potong($teks);
            }
        }

        return self::Potong($teks);
    }

    /**
     * @param  array<mixed>  $nilai
     */
    private static function FormatDaftar(array $nilai): string
    {
        if ($nilai === []) {
            return '—';
        }

        $bagian = [];

        foreach ($nilai as $kunci => $isi) {
            $teks = is_array($isi) ? self::FormatDaftar($isi) : self::FormatNilai(is_string($kunci) ? $kunci : '', $isi);
            $bagian[] = is_string($kunci) ? self::LabelBidang($kunci).': '.$teks : $teks;
        }

        return implode(is_string(array_key_first($nilai)) ? '; ' : ', ', $bagian);
    }

    private static function Potong(string $teks): string
    {
        return mb_strlen($teks) > self::MAKS_PANJANG_NILAI ? mb_substr($teks, 0, self::MAKS_PANJANG_NILAI).'...' : $teks;
    }
}
