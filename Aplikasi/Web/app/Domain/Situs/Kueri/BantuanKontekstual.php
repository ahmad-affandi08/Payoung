<?php

declare(strict_types=1);

namespace App\Domain\Situs\Kueri;

use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Situs\Enum\StatusArtikel;
use App\Domain\Situs\Model\ArtikelBantuan;
use Illuminate\Database\Eloquent\Builder;

/**
 * PLT-12: bantuan kontekstual di back-office. Untuk jalur halaman yang sedang dibuka (`/kelola/...`), artikel terbit
 * yang `Konteks`-nya adalah awalan jalur itu (yang paling spesifik dulu) dikembalikan sebagai `Halaman`; `Lainnya`
 * adalah artikel terbit lain (atau hasil cari judul/ringkasan) paling banyak `BATAS_LAINNYA`. Setiap item membawa tautan
 * absolut ke Pusat Bantuan publik.
 */
final class BantuanKontekstual
{
    public const BATAS_HALAMAN = 6;

    public const BATAS_LAINNYA = 8;

    /**
     * @return array{Halaman: list<array<string, mixed>>, Lainnya: list<array<string, mixed>>, UrlPusatBantuan: string}
     */
    public function Ambil(string $jalur, ?string $cari): array
    {
        $jalur = self::RapikanJalur($jalur);
        $cari = $cari === null ? '' : trim(mb_substr($cari, 0, 80));
        $semua = $this->KueriTerbit()->orderBy('Urutan')->orderBy('Judul')->get();
        $cocok = [];

        foreach ($semua as $a) {
            $skor = self::HitungKecocokan($a->Konteks, $jalur);

            if ($skor > 0) {
                $cocok[] = [$skor, $a];
            }
        }

        usort($cocok, fn (array $x, array $y): int => $y[0] <=> $x[0]);
        $halaman = array_slice(array_map(fn (array $c): ArtikelBantuan => $c[1], $cocok), 0, self::BATAS_HALAMAN);
        $idHalaman = array_map(fn (ArtikelBantuan $a): int => $a->Id, $halaman);

        $lainnya = $semua->reject(fn (ArtikelBantuan $a): bool => in_array($a->Id, $idHalaman, true));

        if ($cari !== '') {
            $kata = mb_strtolower($cari);
            $lainnya = $semua->filter(fn (ArtikelBantuan $a): bool => str_contains(mb_strtolower($a->Judul.' '.($a->Ringkasan ?? '').' '.($a->Kategori ?? '')), $kata));
        }

        return [
            'Halaman' => array_values(array_map(fn (ArtikelBantuan $a): array => $this->Petakan($a), $cari === '' ? $halaman : [])),
            'Lainnya' => array_values($lainnya->take(self::BATAS_LAINNYA)->map(fn (ArtikelBantuan $a): array => $this->Petakan($a))->all()),
            'UrlPusatBantuan' => AlamatDomain::BuatUrlAbsolutPemasaran('/pusat-bantuan'),
        ];
    }

    /** Hanya jalur back-office tanpa query/fragmen, huruf kecil, paling panjang 200 karakter; selain itu `/kelola`. */
    public static function RapikanJalur(string $jalur): string
    {
        $jalur = strtolower((string) strtok($jalur, '?#'));
        $jalur = '/'.trim($jalur, '/');

        if (strlen($jalur) > 200 || preg_match('#^'.ArtikelBantuan::POLA_KONTEKS.'$#', $jalur) !== 1) {
            return '/kelola';
        }

        return $jalur;
    }

    /**
     * Panjang awalan konteks terpanjang yang cocok dengan jalur (0 = tidak ada). Awalan cocok bila sama dengan jalur atau
     * jalur berlanjut dengan `/`, jadi `/kelola/produk` cocok untuk `/kelola/produk/label` tetapi tidak `/kelola/produksi`.
     *
     * @param  list<string>  $konteks
     */
    public static function HitungKecocokan(array $konteks, string $jalur): int
    {
        $terbaik = 0;

        foreach ($konteks as $awalan) {
            if (($jalur === $awalan || str_starts_with($jalur, $awalan.'/')) && strlen($awalan) > $terbaik) {
                $terbaik = strlen($awalan);
            }
        }

        return $terbaik;
    }

    /**
     * @return array<string, mixed>
     */
    private function Petakan(ArtikelBantuan $a): array
    {
        return [
            'Slug' => $a->Slug,
            'Judul' => $a->Judul,
            'Ringkasan' => $a->Ringkasan,
            'Kategori' => $a->Kategori,
            'UrlVideo' => $a->UrlVideo,
            'Url' => AlamatDomain::BuatUrlAbsolutPemasaran('/pusat-bantuan/'.$a->Slug),
        ];
    }

    /**
     * @return Builder<ArtikelBantuan>
     */
    private function KueriTerbit(): Builder
    {
        return ArtikelBantuan::query()->where('Status', StatusArtikel::Terbit->value)->whereNotNull('DiterbitkanPada');
    }
}
