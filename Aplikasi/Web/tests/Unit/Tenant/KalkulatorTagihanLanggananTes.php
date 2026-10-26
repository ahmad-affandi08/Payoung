<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Enum\JenisKupon;
use App\Domain\Tenant\Layanan\KalkulatorTagihanLangganan;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Kalkulasi tagihan langganan (P-08 langkah 1, §12.2). Tarif selalu masukan (dari TarifPajak), tidak di kode.
 */

describe('KalkulatorTagihanLangganan', function (): void {
    it('PPN 12% dengan DPP nilai lain 11/12: DPP & PPN dibulatkan ke bawah ke rupiah penuh', function (): void {
        $rincian = (new KalkulatorTagihanLangganan)->Hitung(Uang::Dari('199000'), 1, tarifPersen: '12.000000', pengaliDppPembilang: 11, pengaliDppPenyebut: 12);

        expect($rincian->dasarPengenaanPajak->KeString())->toBe('182416.00')
            ->and($rincian->jumlahPpn->KeString())->toBe('21889.00')
            ->and($rincian->total->KeString())->toBe('220889.00')
            ->and($rincian->bulanDiskon)->toBe(0);
    });

    it('tanpa tarif (platform non-PKP): tanpa DPP & PPN, total = subtotal − diskon', function (): void {
        $rincian = (new KalkulatorTagihanLangganan)->Hitung(Uang::Dari('79000'), 1, JenisKupon::Nominal, '10000', 1);

        expect($rincian->diskon->KeString())->toBe('10000.00')
            ->and($rincian->jumlahPpn->BernilaiNol())->toBeTrue()
            ->and($rincian->total->KeString())->toBe('69000.00');
    });

    it('diskon persen memakai bagian bulan yang tercakup kupon, dibulatkan HalfUp. Tidak pernah melebihi subtotal', function (): void {
        $kalkulator = new KalkulatorTagihanLangganan;

        // 758.400 × 15% × 5/12 = 47.400
        expect($kalkulator->Hitung(Uang::Dari('758400'), 12, JenisKupon::Persen, '15', 5)->diskon->KeString())->toBe('47400.00')
            // 79.000 × 33,33% = 26.330,70 → 26.331
            ->and($kalkulator->Hitung(Uang::Dari('79000'), 1, JenisKupon::Persen, '33.33', 1)->diskon->KeString())->toBe('26331.00')
            // bulan kupon melebihi siklus dipotong ke jumlah bulan tagihan
            ->and($kalkulator->Hitung(Uang::Dari('79000'), 1, JenisKupon::Persen, '50', 6)->bulanDiskon)->toBe(1)
            ->and($kalkulator->Hitung(Uang::Dari('79000'), 1, JenisKupon::Nominal, '100000', 1)->diskon->KeString())->toBe('79000.00');
    });

    it('invariant untuk ribuan kombinasi: Total = Subtotal − Diskon + PPN, DPP = ⌊(Subtotal − Diskon) × p/q⌋, PPN = ⌊DPP × tarif⌋', function (): void {
        $kalkulator = new KalkulatorTagihanLangganan;
        $acak = new Randomizer(new Mt19937(20260923));

        for ($i = 0; $i < 2000; $i++) {
            $subtotal = Uang::Dari((string) $acak->getInt(1, 50_000_000));
            $jumlahBulan = $acak->getInt(0, 1) === 0 ? 1 : 12;
            $jenis = [null, JenisKupon::Persen, JenisKupon::Nominal][$acak->getInt(0, 2)];
            $nilai = $jenis === JenisKupon::Persen ? $acak->getInt(1, 100).'.'.$acak->getInt(0, 99) : $acak->getInt(0, 2_000_000).'.'.$acak->getInt(0, 99);
            [$p, $q] = [[1, 1], [11, 12], [2, 3]][$acak->getInt(0, 2)];
            $tarif = ['11', '12', '10.5'][$acak->getInt(0, 2)];

            $rincian = $kalkulator->Hitung($subtotal, $jumlahBulan, $jenis, $nilai, $acak->getInt(1, 24), $tarif, $p, $q);
            $bersih = $subtotal->Kurangi($rincian->diskon);
            $dppHarapan = BigRational::of($bersih->KeString())->multipliedBy(BigRational::ofFraction($p, $q))->toScale(0, RoundingMode::Down);
            $ppnHarapan = BigRational::of($rincian->dasarPengenaanPajak->KeString())->multipliedBy(BigRational::of($tarif))->dividedBy(100)->toScale(0, RoundingMode::Down);

            expect($rincian->total->SamaDengan($bersih->Tambah($rincian->jumlahPpn)))->toBeTrue()
                ->and($rincian->diskon->BernilaiNegatif())->toBeFalse()
                ->and($bersih->BernilaiNegatif())->toBeFalse()
                ->and($rincian->dasarPengenaanPajak->SamaDengan(Uang::Dari(BigDecimal::of($dppHarapan))))->toBeTrue()
                ->and($rincian->jumlahPpn->SamaDengan(Uang::Dari(BigDecimal::of($ppnHarapan))))->toBeTrue();
        }
    });

    it('menolak subtotal negatif dan pengali DPP tidak valid', function (): void {
        $kalkulator = new KalkulatorTagihanLangganan;

        expect(fn () => $kalkulator->Hitung(Uang::Dari('-1'), 1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $kalkulator->Hitung(Uang::Dari('1000'), 1, tarifPersen: '12', pengaliDppPembilang: 0))->toThrow(InvalidArgumentException::class);
    });
});
