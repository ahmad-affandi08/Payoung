<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Tenant\Data\PesanKomunikasi;
use App\Domain\Tenant\Enum\BahasaKomunikasi;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use App\Domain\Tenant\Enum\StatusTemplateKomunikasi;
use App\Domain\Tenant\Model\TemplateKomunikasi;

/**
 * Merakit pesan komunikasi platform dari templat (PGL-07, P-06): versi terbit terbaru untuk peristiwa + kanal + bahasa;
 * bila tidak ada, versi terbit bahasa Indonesia; bila tetap tidak ada, teks bawaan kode (sama dengan teks sebelum fitur
 * templat ada). Variabel `{Nama}` yang dikenal peristiwa itu diganti nilainya; tanda kurung yang tidak dikenal dibiarkan
 * apa adanya (versi terbit sudah divalidasi saat disimpan, jadi ini hanya jaring pengaman).
 */
final class PenyusunPesanKomunikasi
{
    /**
     * @param  array<string, string>  $variabel  nama variabel → nilai
     */
    public function Susun(PeristiwaKomunikasi $peristiwa, KanalKomunikasi $kanal, array $variabel, BahasaKomunikasi $bahasa = BahasaKomunikasi::Indonesia): PesanKomunikasi
    {
        $templat = $this->CariTerbit($peristiwa, $kanal, $bahasa)
            ?? ($bahasa === BahasaKomunikasi::Indonesia ? null : $this->CariTerbit($peristiwa, $kanal, BahasaKomunikasi::Indonesia));

        if ($templat !== null) {
            return new PesanKomunikasi(
                $kanal === KanalKomunikasi::Email ? self::Render((string) $templat->Subjek, $variabel) : null,
                $kanal !== KanalKomunikasi::WhatsApp ? self::Render((string) $templat->Judul, $variabel) : null,
                self::Render($templat->Isi, $variabel),
                'Templat',
                $templat->Versi,
            );
        }

        $bawaan = $peristiwa->AmbilBawaan($kanal, $bahasa);

        return new PesanKomunikasi(
            $bawaan['Subjek'] === null ? null : self::Render($bawaan['Subjek'], $variabel),
            $bawaan['Judul'] === null ? null : self::Render($bawaan['Judul'], $variabel),
            self::Render($bawaan['Isi'], $variabel),
            'Bawaan',
            null,
        );
    }

    /**
     * Mengganti `{Nama}` dengan nilainya. Nilai tidak dirender ulang, jadi `{...}` di dalam nilai tidak diperlakukan sebagai
     * variabel.
     *
     * @param  array<string, string>  $variabel
     */
    public static function Render(string $teks, array $variabel): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z]+)\}/', fn (array $cocok): string => $variabel[$cocok[1]] ?? $cocok[0], $teks);
    }

    /**
     * Nama variabel yang dipakai di teks (urutan kemunculan, tanpa duplikat).
     *
     * @return list<string>
     */
    public static function AmbilVariabelDipakai(string $teks): array
    {
        preg_match_all('/\{([A-Za-z]+)\}/', $teks, $cocok);

        return array_values(array_unique($cocok[1]));
    }

    private function CariTerbit(PeristiwaKomunikasi $peristiwa, KanalKomunikasi $kanal, BahasaKomunikasi $bahasa): ?TemplateKomunikasi
    {
        return TemplateKomunikasi::query()
            ->where('KodePeristiwa', $peristiwa->value)
            ->where('Kanal', $kanal->value)
            ->where('Bahasa', $bahasa->value)
            ->where('Status', StatusTemplateKomunikasi::Terbit->value)
            ->orderByDesc('Versi')
            ->first();
    }
}
