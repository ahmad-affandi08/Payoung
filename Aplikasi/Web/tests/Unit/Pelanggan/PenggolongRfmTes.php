<?php

declare(strict_types=1);

use App\Domain\Pelanggan\Enum\SegmenRfm;
use App\Domain\Pelanggan\Layanan\PenggolongRfm;
use App\Domain\Pelanggan\Layanan\PengirimKampanyePesan;
use Carbon\CarbonImmutable;

/*
 * CRM-07: penggolong segmen RFM (urutan aturan, batas 20% teratas) dan jam tenang pengiriman kampanye.
 */

it('menggolongkan pelanggan ke segmen RFM sesuai urutan aturan', function (): void {
    $hariIni = CarbonImmutable::parse('2026-10-07');
    $bahan = fn (string $tanggal, int $seluruhnya, int $periode, string $total = '100000.00'): array => [
        'TanggalTerakhir' => $tanggal, 'JumlahSeluruhnya' => $seluruhnya, 'JumlahPeriode' => $periode, 'TotalPeriode' => $total,
    ];

    expect(PenggolongRfm::Golongkan(null, $hariIni, null))->toBe(SegmenRfm::BelumBelanja)
        ->and(PenggolongRfm::Golongkan($bahan('2026-03-01', 20, 0), $hariIni, null))->toBe(SegmenRfm::Hilang)
        ->and(PenggolongRfm::Golongkan($bahan('2026-07-01', 9, 9), $hariIni, null))->toBe(SegmenRfm::Berisiko)
        ->and(PenggolongRfm::Golongkan($bahan('2026-10-01', 6, 6), $hariIni, null))->toBe(SegmenRfm::Juara)
        // Jarang tetapi belanjanya termasuk 20% terbesar dan baru belanja = Juara.
        ->and(PenggolongRfm::Golongkan($bahan('2026-10-01', 2, 2, '900000.00'), $hariIni, '500000.00'))->toBe(SegmenRfm::Juara)
        ->and(PenggolongRfm::Golongkan($bahan('2026-08-20', 3, 3), $hariIni, null))->toBe(SegmenRfm::Setia)
        ->and(PenggolongRfm::Golongkan($bahan('2026-09-01', 1, 1), $hariIni, null))->toBe(SegmenRfm::Baru)
        ->and(PenggolongRfm::Golongkan($bahan('2026-09-01', 2, 2), $hariIni, null))->toBe(SegmenRfm::Potensial);
});

it('batas Juara = persentil 80 dari belanja > 0. Butuh minimal 5 pembeli', function (): void {
    expect(PenggolongRfm::HitungBatasJuara(['10.00', '20.00', '0.00', '30.00']))->toBeNull()
        ->and(PenggolongRfm::HitungBatasJuara(['50.00', '10.00', '40.00', '20.00', '30.00', '0.00']))->toBe('40.00');
});

it('jam tenang: kirim 08.00–20.59 waktu usaha, di luar itu tunggu sampai 08.00', function (): void {
    $wib = fn (string $jam): CarbonImmutable => CarbonImmutable::parse("2026-10-07 {$jam}", 'Asia/Jakarta');

    expect(PengirimKampanyePesan::HitungTungguJamTenang($wib('08:00')))->toBe(0)
        ->and(PengirimKampanyePesan::HitungTungguJamTenang($wib('20:59')))->toBe(0)
        ->and(PengirimKampanyePesan::HitungTungguJamTenang($wib('21:00')))->toBe(11 * 3600)
        ->and(PengirimKampanyePesan::HitungTungguJamTenang($wib('06:30')))->toBe(90 * 60);
});

it('teks pesan: {nama}/{toko} diganti dan selalu ditutup tautan berhenti berlangganan', function (): void {
    expect(PengirimKampanyePesan::SusunTeks('Halo {nama}, diskon 20% di {toko}!', 'Ani', 'Kopi Senja', 'https://contoh.id/b'))
        ->toBe("Halo Ani, diskon 20% di Kopi Senja!\n\n— Kopi Senja\nBerhenti menerima pesan promosi: https://contoh.id/b");
});
