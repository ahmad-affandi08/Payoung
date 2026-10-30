<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Periode layanan dari tagihan yang lunas (P-08 langkah 3 "periode diperpanjang"). Murni, tanpa kueri.
 *
 * - Perpanjangan menyambung dari akhir periode berjalan (termasuk saat Tertunggak, sehingga masa tenggang tidak
 *   menjadi hari gratis). Bila akhir periode sudah tidak diketahui, mulai saat pembayaran diterima.
 * - Aktivasi dimulai saat pembayaran diterima.
 * - Akhir periode = mulai + N bulan (D-249: lama bebas) tanpa melompati akhir bulan (31 Jan + 1 bulan = 28/29 Feb).
 */
final class PenghitungPeriodeLangganan
{
    /**
     * @return array{Mulai: CarbonImmutable, Selesai: CarbonImmutable}
     */
    public function Hitung(JenisTagihanLangganan $jenis, int $jumlahBulan, CarbonInterface $diterimaPada, ?CarbonInterface $periodeSelesaiBerjalan): array
    {
        $mulai = $jenis === JenisTagihanLangganan::Perpanjangan && $periodeSelesaiBerjalan !== null
            ? CarbonImmutable::instance($periodeSelesaiBerjalan)
            : CarbonImmutable::instance($diterimaPada);

        return ['Mulai' => $mulai, 'Selesai' => $mulai->addMonthsNoOverflow($jumlahBulan)];
    }
}
