<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Persediaan\Layanan\Hpp\HppFifo;
use App\Domain\Persediaan\Layanan\Hpp\HppRataRataBergerak;
use App\Domain\Persediaan\Layanan\Hpp\KeadaanHpp;
use App\Domain\Persediaan\Layanan\Hpp\LapisanHpp;
use App\Domain\Persediaan\Layanan\Hpp\StrategiHpp;
use Brick\Math\BigDecimal;
use Tests\Pendukung\Persediaan\BantuanBuku;

/*
 * Contoh kerja DesainF05a C.3 #1–#5 sebagai dataset (vektor HPP khusus PHP; POS tidak menghitung HPP, DesainF05a F).
 * Tiap langkah: [masukan, harapan]. Masukan `[jumlah]` = berjalan, `[jumlah, nilai, hppSatuan?]` = ditentukan.
 * Harapan: TotalHpp, Selisih, Q, N, A (null = tidak dicek).
 */

/**
 * @param  list<array{0: array{0: string, 1?: string, 2?: string|null}, 1: array{0: string, 1: string, 2: string, 3: string, 4: string|null}}>  $langkah
 */
function JalankanContohHppUji(StrategiHpp $strategi, array $langkah): KeadaanHpp
{
    $keadaan = KeadaanHpp::BuatKosong();

    foreach ($langkah as $urutan => [$masukan, [$total, $selisih, $q, $n, $a]]) {
        $hasil = BantuanBuku::TerapkanLangkah($strategi, $keadaan, $masukan);
        $pesan = 'langkah '.($urutan + 1);

        expect($hasil->totalHpp->KeString())->toBe($total, $pesan.' TotalHpp')
            ->and($hasil->selisihHpp->KeString())->toBe($selisih, $pesan.' Selisih')
            ->and($keadaan->jumlah->KeString())->toBe($q, $pesan.' Q')
            ->and($keadaan->nilai->KeString())->toBe($n, $pesan.' N');

        if ($a !== null) {
            expect((string) $keadaan->hppRataRata)->toBe($a, $pesan.' A');
        }
    }

    return $keadaan;
}

/**
 * @return array<string, list<array{0: array{0: string, 1?: string, 2?: string|null}, 1: array{0: string, 1: string, 2: string, 3: string, 4: string|null}}>>
 */
function AmbilContohHppRataRata(): array
{
    return [
        '#1 stok awal 10 @ 1234.5678, jual 3, terima 5 senilai 6500, jual 12 (nilai habis tepat)' => [
            [['10', '12345.68', '1234.5678'], ['12345.68', '0.00', '10.0000', '12345.68', '1234.568000']],
            [['-3'], ['-3703.70', '0.00', '7.0000', '8641.98', '1234.568000']],
            [['5', '6500.00'], ['6500.00', '0.00', '12.0000', '15141.98', '1261.831667']],
            [['-12'], ['-15141.98', '0.00', '0.0000', '0.00', '1261.831667']],
        ],
        '#2 MA: 10 @ 1000, 5 @ 1200, jual 12' => [
            [['10', '10000.00', '1000'], ['10000.00', '0.00', '10.0000', '10000.00', '1000.000000']],
            [['5', '6000.00', '1200'], ['6000.00', '0.00', '15.0000', '16000.00', '1066.666667']],
            [['-12'], ['-12800.00', '0.00', '3.0000', '3200.00', '1066.666667']],
        ],
        '#3 BR-04.3: A 1000, Q 0, jual 4 (minus), terima 10 @ 1100 → selisih −400' => [
            [['5', '5000.00', '1000'], ['5000.00', '0.00', '5.0000', '5000.00', '1000.000000']],
            [['-5'], ['-5000.00', '0.00', '0.0000', '0.00', '1000.000000']],
            [['-4'], ['-4000.00', '0.00', '-4.0000', '-4000.00', '1000.000000']],
            [['10', '11000.00'], ['10600.00', '-400.00', '6.0000', '6600.00', '1100.000000']],
        ],
        '#4 melewati nol: Q 2, N 2000, A 1000, jual 5' => [
            [['2', '2000.00'], ['2000.00', '0.00', '2.0000', '2000.00', '1000.000000']],
            [['-5'], ['-5000.00', '0.00', '-3.0000', '-3000.00', '1000.000000']],
        ],
        '#5 pembulatan: 3 unit senilai 1000, jual 1+1+1 → 333.33, 333.33, 333.34' => [
            [['3', '1000.00'], ['1000.00', '0.00', '3.0000', '1000.00', '333.333333']],
            [['-1'], ['-333.33', '0.00', '2.0000', '666.67', '333.333333']],
            [['-1'], ['-333.33', '0.00', '1.0000', '333.34', '333.333333']],
            [['-1'], ['-333.34', '0.00', '0.0000', '0.00', '333.333333']],
        ],
        'BR-04.3 masuk saat minus tetapi masih minus: Q −10 terima 4 @ 1100 → Q −6 dinilai ulang pada 1100' => [
            [['1', '1000.00'], ['1000.00', '0.00', '1.0000', '1000.00', '1000.000000']],
            [['-11'], ['-11000.00', '0.00', '-10.0000', '-10000.00', '1000.000000']],
            [['4', '4400.00'], ['3400.00', '-1000.00', '-6.0000', '-6600.00', '1100.000000']],
            [['6', '6600.00'], ['6600.00', '0.00', '0.0000', '0.00', '1100.000000']],
        ],
        'HPP belum diketahui: jual sebelum ada stok dinilai 0' => [
            [['-2'], ['0.00', '0.00', '-2.0000', '0.00', null]],
            [['5', '7500.00'], ['4500.00', '-3000.00', '3.0000', '4500.00', '1500.000000']],
        ],
        'pembatalan stok awal (keluar ditentukan D = nilai asal) saat stok utuh: selisih 0' => [
            [['10', '12345.68', '1234.5678'], ['12345.68', '0.00', '10.0000', '12345.68', '1234.568000']],
            [['-10', '12345.68'], ['-12345.68', '0.00', '0.0000', '0.00', '1234.568000']],
        ],
        'keluar ditentukan saat Q′ > 0: −min(D, N), A′ = N′/Q′' => [
            [['10', '12345.68', '1234.5678'], ['12345.68', '0.00', '10.0000', '12345.68', '1234.568000']],
            [['5', '2796.30'], ['2796.30', '0.00', '15.0000', '15141.98', '1009.465333']],
            [['-10', '12345.68'], ['-12345.68', '0.00', '5.0000', '2796.30', '559.260000']],
        ],
        'keluar ditentukan saat Q′ < 0: dinilai berjalan, selisih = TotalHpp + D' => [
            [['2', '2000.00'], ['2000.00', '0.00', '2.0000', '2000.00', '1000.000000']],
            [['-3', '3300.00'], ['-3000.00', '300.00', '-1.0000', '-1000.00', '1000.000000']],
        ],
    ];
}

/**
 * @return array<string, list<array{0: array{0: string, 1?: string, 2?: string|null}, 1: array{0: string, 1: string, 2: string, 3: string, 4: string|null}}>>
 */
function AmbilContohHppFifo(): array
{
    return [
        '#2 FIFO: 10 @ 1000, 5 @ 1200, jual 12 → 10000 + 2400' => [
            [['10', '10000.00', '1000'], ['10000.00', '0.00', '10.0000', '10000.00', '1000.000000']],
            [['5', '6000.00', '1200'], ['6000.00', '0.00', '15.0000', '16000.00', '1066.666667']],
            [['-12'], ['-12400.00', '0.00', '3.0000', '3600.00', '1200.000000']],
        ],
        '#3 BR-04.3 FIFO: sisa minus dinilai pada lapisan terakhir, terima → lapisan (6, 6600)' => [
            [['5', '5000.00', '1000'], ['5000.00', '0.00', '5.0000', '5000.00', '1000.000000']],
            [['-5'], ['-5000.00', '0.00', '0.0000', '0.00', '1000.000000']],
            [['-4'], ['-4000.00', '0.00', '-4.0000', '-4000.00', '1000.000000']],
            [['10', '11000.00'], ['10600.00', '-400.00', '6.0000', '6600.00', '1100.000000']],
        ],
        '#4 melewati nol FIFO' => [
            [['2', '2000.00'], ['2000.00', '0.00', '2.0000', '2000.00', '1000.000000']],
            [['-5'], ['-5000.00', '0.00', '-3.0000', '-3000.00', '1000.000000']],
        ],
        '#5 pembulatan FIFO: lapisan 3 unit senilai 1000 → 333.33, 333.33, 333.34' => [
            [['3', '1000.00'], ['1000.00', '0.00', '3.0000', '1000.00', '333.333333']],
            [['-1'], ['-333.33', '0.00', '2.0000', '666.67', '333.335000']],
            [['-1'], ['-333.33', '0.00', '1.0000', '333.34', '333.340000']],
            [['-1'], ['-333.34', '0.00', '0.0000', '0.00', '333.340000']],
        ],
    ];
}

describe('F-05a contoh kerja HPP rata-rata bergerak (DesainF05a C.3, BR-04.2/04.3)', function (): void {
    it('contoh HPP rata-rata bergerak', function (string $nama): void {
        JalankanContohHppUji(new HppRataRataBergerak, AmbilContohHppRataRata()[$nama]);
    })->with(array_keys(AmbilContohHppRataRata()));

    it('masuk berjalan dinilai pada A, tanpa A dinilai 0 dan ditandai hppTidakDiketahui', function (): void {
        $strategi = new HppRataRataBergerak;
        $keadaan = KeadaanHpp::BuatKosong();

        $tanpaHpp = BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['2']);
        expect($tanpaHpp->hppTidakDiketahui)->toBeTrue()
            ->and($tanpaHpp->totalHpp->KeString())->toBe('0.00');

        BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['8', '8000.00']);
        $berjalan = BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['5']);
        expect($berjalan->hppTidakDiketahui)->toBeFalse()
            ->and($berjalan->totalHpp->KeString())->toBe('4000.00')
            ->and((string) $berjalan->hppSatuan)->toBe('800.000000');
    });
});

describe('F-05a contoh kerja HPP FIFO (DesainF05a C.3)', function (): void {
    it('contoh HPP FIFO', function (string $nama): void {
        JalankanContohHppUji(new HppFifo, AmbilContohHppFifo()[$nama]);
    })->with(array_keys(AmbilContohHppFifo()));

    it('#2 FIFO: HppSatuan keluar = Hpp(|TotalHpp|, q) dan lapisan L2 tersisa 3 / 3600.00', function (): void {
        $strategi = new HppFifo;
        $keadaan = KeadaanHpp::BuatKosong();
        BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['10', '10000.00', '1000']);
        BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['5', '6000.00', '1200']);
        $jual = BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['-12']);

        [$l1, $l2] = $keadaan->lapisan;

        expect((string) $jual->hppSatuan)->toBe('1033.333333')
            ->and($l1->CekHabis())->toBeTrue()
            ->and($l1->nilaiSisa->KeString())->toBe('0.00')
            ->and($l2->jumlahSisa->KeString())->toBe('3.0000')
            ->and($l2->nilaiSisa->KeString())->toBe('3600.00')
            ->and($keadaan->AmbilLapisanTerbuka())->toHaveCount(1);
    });

    it('pembalik ber-idMutasiAsal mengonsumsi tepat lapisan sumbernya, bukan lapisan tertua', function (): void {
        $keadaan = new KeadaanHpp(Kuantitas::Dari('15'), Uang::Dari('16000.00'), BigDecimal::of('1066.666667'), [
            new LapisanHpp(1, 101, null, null, Kuantitas::Dari('10'), Kuantitas::Dari('10'), BigDecimal::of('1000'), Uang::Dari('10000.00'), Uang::Dari('10000.00')),
            new LapisanHpp(2, 102, null, null, Kuantitas::Dari('5'), Kuantitas::Dari('5'), BigDecimal::of('1200'), Uang::Dari('6000.00'), Uang::Dari('6000.00')),
        ], BigDecimal::of('1200'));

        $hasil = (new HppFifo)->Terapkan($keadaan, BantuanBuku::BuatMasukanDitentukan('-5', '6000.00', null, 102));

        expect($hasil->totalHpp->KeString())->toBe('-6000.00')
            ->and($hasil->selisihHpp->KeString())->toBe('0.00')
            ->and($keadaan->lapisan[0]->jumlahSisa->KeString())->toBe('10.0000')
            ->and($keadaan->lapisan[1]->CekHabis())->toBeTrue()
            ->and($keadaan->nilai->KeString())->toBe('10000.00');
    });

    it('pembalik ber-idMutasiAsal ditolak LapisanSudahTerpakai bila lapisan sumber sudah terpakai', function (): void {
        $keadaan = new KeadaanHpp(Kuantitas::Dari('3'), Uang::Dari('3600.00'), BigDecimal::of('1200'), [
            new LapisanHpp(2, 102, null, null, Kuantitas::Dari('5'), Kuantitas::Dari('3'), BigDecimal::of('1200'), Uang::Dari('6000.00'), Uang::Dari('3600.00')),
        ], BigDecimal::of('1200'));

        $galat = BantuanBuku::TangkapPelanggaran(fn () => (new HppFifo)->Terapkan($keadaan, BantuanBuku::BuatMasukanDitentukan('-5', '6000.00', null, 102)));

        expect($galat->kode)->toBe('LapisanSudahTerpakai');

        expect($keadaan->lapisan[0]->jumlahSisa->KeString())->toBe('3.0000');
    });

    it('produk batch: keluar hanya mengonsumsi lapisan batch yang diminta', function (): void {
        $strategi = new HppFifo;
        $keadaan = KeadaanHpp::BuatKosong();
        $strategi->Terapkan($keadaan, BantuanBuku::BuatMasukanDitentukan('10', '195000.00', null, null, 7));
        $strategi->Terapkan($keadaan, BantuanBuku::BuatMasukanDitentukan('10', '210000.00', null, null, 8));

        $hasil = $strategi->Terapkan($keadaan, BantuanBuku::BuatMasukanBerjalan('-4', 8));

        expect($hasil->totalHpp->KeString())->toBe('-84000.00')
            ->and($keadaan->lapisan[0]->jumlahSisa->KeString())->toBe('10.0000')
            ->and($keadaan->lapisan[1]->jumlahSisa->KeString())->toBe('6.0000')
            ->and($keadaan->lapisan[1]->idBatchStok)->toBe(8);
    });

    it('masuk berjalan dinilai pada HPP lapisan terbaru', function (): void {
        $strategi = new HppFifo;
        $keadaan = KeadaanHpp::BuatKosong();
        BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['4', '4000.00']);
        BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['2', '2500.00']);

        $hasil = BantuanBuku::TerapkanLangkah($strategi, $keadaan, ['1']);

        expect($hasil->totalHpp->KeString())->toBe('1250.00')
            ->and($keadaan->lapisan)->toHaveCount(3);
    });
});
