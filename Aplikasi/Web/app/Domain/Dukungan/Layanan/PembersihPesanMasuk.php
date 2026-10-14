<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

/**
 * Merapikan teks pesan masuk sebelum menjadi isi tiket (PGL-15): menyeragamkan baris baru, membuang karakter kendali,
 * memotong kutipan balasan (baris `>` dan semua yang mengikuti penanda "Pada … menulis:"/"On … wrote:"/"Original Message"; baris "Dari:" sengaja tidak dianggap penanda karena bisa isi sungguhan)
 * supaya riwayat email tidak menggandakan isi tiket, dan membatasi panjang. Tanpa HTML: isi tiket ditampilkan sebagai teks.
 */
final class PembersihPesanMasuk
{
    public const MAKS_ISI = 5000;

    public const MAKS_JUDUL = 150;

    private const POLA_NOMOR_TIKET = '/\bTKT-\d{4}-\d{6}\b/';

    private const POLA_PENANDA_KUTIPAN = '/^\s*(?:pada\b.{0,200}\bmenulis\s*:|on\b.{0,200}\bwrote\s*:|-{2,}\s*(?:original message|pesan asli|forwarded message)\b|_{5,}\s*$)/iu';

    public function RapikanIsi(string $isi): string
    {
        $teks = str_replace(["\r\n", "\r"], "\n", $isi);
        $teks = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $teks);
        $baris = [];

        foreach (explode("\n", $teks) as $satu) {
            if (preg_match(self::POLA_PENANDA_KUTIPAN, $satu) === 1 && $baris !== []) {
                break;
            }

            if (str_starts_with(ltrim($satu), '>')) {
                continue;
            }

            $baris[] = rtrim($satu);
        }

        $hasil = trim(implode("\n", $baris));
        $hasil = (string) preg_replace("/\n{3,}/", "\n\n", $hasil);

        return mb_substr($hasil, 0, self::MAKS_ISI);
    }

    /** Judul tiket dari subjek email (tanpa awalan Re:/Fwd: dan nomor tiket) atau awal isi pesan WhatsApp. */
    public function BuatJudul(string $subjek, string $isi): string
    {
        $judul = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subjek));
        $judul = trim((string) preg_replace('/^(?:\s*(?:re|fw|fwd|balasan|bls)\s*:\s*)+/iu', '', $judul));
        $judul = trim((string) preg_replace(self::POLA_NOMOR_TIKET, '', $judul), " \t:-[]");

        if (mb_strlen($judul) < 5) {
            $awal = trim((string) preg_replace('/\s+/u', ' ', $isi));
            $judul = mb_strlen($awal) > 80 ? mb_substr($awal, 0, 77).'...' : $awal;
        }

        return mb_substr($judul === '' ? 'Pesan masuk' : $judul, 0, self::MAKS_JUDUL);
    }

    /** Nomor tiket (`TKT-2026-000123`) dari subjek atau 500 karakter pertama isi; null bila tidak ada. */
    public function CariNomorTiket(string $subjek, string $isi): ?string
    {
        foreach ([$subjek, mb_substr($isi, 0, 500)] as $teks) {
            if (preg_match(self::POLA_NOMOR_TIKET, $teks, $cocok) === 1) {
                return $cocok[0];
            }
        }

        return null;
    }
}
