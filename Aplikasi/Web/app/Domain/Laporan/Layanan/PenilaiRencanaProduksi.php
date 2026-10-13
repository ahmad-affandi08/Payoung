<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Layanan;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Rencana produksi harian untuk produk jadi (§9.11 Bakery), fungsi murni tanpa float. Metodenya sengaja sederhana:
 *
 * - **Permintaan hari itu** = rata-rata pemakaian pada hari-dalam-minggu yang sama selama `MINGGU_DASAR` minggu terakhir
 *   (hanya hari yang sudah lewat; hari tanpa penjualan dihitung nol). Tanpa satu pun hari sebanding, permintaan nol.
 * - **Pre-order** = jumlah pesanan yang diambil pada tanggal itu. Pre-order biasanya sudah ikut di riwayat penjualan
 *   (diambil lalu menjadi penjualan), jadi kebutuhan = yang **terbesar** dari keduanya, bukan penjumlahan.
 * - **Rekomendasi** = kebutuhan − saldo sekarang, tidak negatif; dibulatkan ke atas ke bilangan bulat bila satuan tidak
 *   boleh desimal.
 */
final class PenilaiRencanaProduksi
{
    public const MINGGU_DASAR = 4;

    /**
     * @param  array<string, string>  $pemakaianHarian  tanggal `Y-m-d` → jumlah terpakai (tanggal tanpa baris = nol)
     * @return array{Sampel: int, RataHariSama: string, PreOrder: string, Kebutuhan: string, Saldo: string, Rekomendasi: string}
     */
    public function Hitung(array $pemakaianHarian, CarbonImmutable $hariIni, CarbonImmutable $target, string $preOrder, string $saldo, bool $bolehDesimal): array
    {
        // Tanggal kalender murni: selisih hari tidak boleh tergelincir oleh jam atau zona waktu.
        $hariIni = CarbonImmutable::parse($hariIni->toDateString());
        $target = CarbonImmutable::parse($target->toDateString());
        $jumlah = BigDecimal::zero();
        $sampel = 0;

        for ($k = 1; $k <= self::MINGGU_DASAR; $k++) {
            $tanggal = $target->subWeeks($k);

            if ($tanggal->greaterThanOrEqualTo($hariIni)) {
                continue;
            }

            $sampel++;
            $jumlah = $jumlah->plus(BigDecimal::of($pemakaianHarian[$tanggal->toDateString()] ?? '0'));
        }

        $rata = $sampel === 0 ? BigDecimal::zero() : $jumlah->dividedBy($sampel, 4, RoundingMode::HalfUp);
        $pesanan = BigDecimal::of($preOrder);
        $kebutuhan = $rata->isGreaterThanOrEqualTo($pesanan) ? $rata : $pesanan;
        $stok = BigDecimal::of($saldo);
        $selisih = $kebutuhan->minus($stok);
        $rekomendasi = $selisih->isPositive() ? $selisih->toScale($bolehDesimal ? 4 : 0, RoundingMode::Up) : BigDecimal::zero();

        return [
            'Sampel' => $sampel,
            'RataHariSama' => (string) $rata->toScale(4),
            'PreOrder' => (string) $pesanan->toScale(4),
            'Kebutuhan' => (string) $kebutuhan->toScale(4),
            'Saldo' => (string) $stok->toScale(4),
            'Rekomendasi' => (string) $rekomendasi->toScale(4),
        ];
    }
}
