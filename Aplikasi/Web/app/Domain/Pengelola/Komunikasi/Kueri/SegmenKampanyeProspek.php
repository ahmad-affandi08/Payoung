<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Kueri;

use App\Domain\Pengelola\Komunikasi\Enum\SektorProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\Komunikasi\Model\KontakProspek;

/** Penerima kampanye prospek (D-216): kontak `Aktif` di sektor yang dipilih. */
final class SegmenKampanyeProspek
{
    /**
     * Membersihkan pilihan sektor mentah menjadi daftar kode yang dikenal, tanpa duplikat.
     *
     * @return list<string>
     */
    public static function BersihkanSektor(mixed $mentah): array
    {
        $kode = [];

        foreach (is_array($mentah) ? $mentah : [] as $nilai) {
            $sektor = is_string($nilai) ? SektorProspek::tryFrom($nilai) : null;

            if ($sektor !== null) {
                $kode[$sektor->value] = true;
            }
        }

        return array_keys($kode);
    }

    /** @param list<string> $sektor */
    public function Hitung(array $sektor): int
    {
        return $sektor === [] ? 0 : KontakProspek::query()->where('Status', StatusKontakProspek::Aktif->value)->whereIn('Sektor', $sektor)->count();
    }

    /**
     * Jumlah kontak per sektor yang punya kontak, untuk pilihan sektor di formulir kampanye.
     *
     * @return list<array{Nilai: string, Label: string, Aktif: int, Total: int}>
     */
    public function HitungPerSektor(): array
    {
        $hitungan = KontakProspek::query()
            ->selectRaw('Sektor, COUNT(*) AS Total, SUM(CASE WHEN Status = ? THEN 1 ELSE 0 END) AS Aktif', [StatusKontakProspek::Aktif->value])
            ->groupBy('Sektor')
            ->get()
            ->keyBy(fn (KontakProspek $k): string => $k->Sektor->value);
        $hasil = [];

        foreach (SektorProspek::cases() as $sektor) {
            $baris = $hitungan->get($sektor->value);

            if ($baris !== null) {
                $hasil[] = ['Nilai' => $sektor->value, 'Label' => $sektor->AmbilLabel(), 'Aktif' => (int) $baris->getAttribute('Aktif'), 'Total' => (int) $baris->getAttribute('Total')];
            }
        }

        return $hasil;
    }
}
