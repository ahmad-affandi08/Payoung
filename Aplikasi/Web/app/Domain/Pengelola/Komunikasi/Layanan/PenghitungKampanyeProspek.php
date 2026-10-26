<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Layanan;

use App\Domain\Pengelola\Komunikasi\Enum\StatusPenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Model\KampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Model\PenerimaKampanyeProspek;

/** Hitungan hasil kampanye prospek selalu dibaca dari baris penerimanya, bukan dijumlahkan bertahap (D-216). */
final class PenghitungKampanyeProspek
{
    /**
     * @return array{Menunggu: int, Diproses: int, Terkirim: int, Gagal: int, Dilewati: int}
     */
    public static function Hitung(KampanyeProspek $kampanye): array
    {
        $hitungan = PenerimaKampanyeProspek::query()
            ->where('IdKampanyeProspek', $kampanye->Id)
            ->selectRaw('Status, COUNT(*) AS Jumlah')
            ->groupBy('Status')
            ->pluck('Jumlah', 'Status');
        $ambil = static fn (StatusPenerimaSiaran $status): int => (int) ($hitungan[$status->value] ?? 0);

        return [
            'Menunggu' => $ambil(StatusPenerimaSiaran::Menunggu),
            'Diproses' => $ambil(StatusPenerimaSiaran::Diproses),
            'Terkirim' => $ambil(StatusPenerimaSiaran::Terkirim),
            'Gagal' => $ambil(StatusPenerimaSiaran::Gagal),
            'Dilewati' => $ambil(StatusPenerimaSiaran::Dilewati),
        ];
    }

    /**
     * Memperbarui kolom hitungan kampanye dari baris penerima; mengembalikan hitungannya.
     *
     * @return array{Menunggu: int, Diproses: int, Terkirim: int, Gagal: int, Dilewati: int}
     */
    public static function Segarkan(KampanyeProspek $kampanye): array
    {
        $hitung = self::Hitung($kampanye);
        $kampanye->update([
            'JumlahTerkirim' => $hitung['Terkirim'],
            'JumlahGagal' => $hitung['Gagal'],
            'JumlahDilewati' => $hitung['Dilewati'],
        ]);

        return $hitung;
    }
}
