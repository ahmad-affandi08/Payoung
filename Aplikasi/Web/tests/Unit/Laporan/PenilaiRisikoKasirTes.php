<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Laporan\Layanan\PenilaiRisikoKasir;

/*
 * F-14 anti-fraud: skor risiko kasir transparan (alasan berteks), pembanding rata-rata semua kasir, batas 100.
 */

it('diskon & retur ≥ 2× rata-rata dengan minimal 3 kejadian. Kas kurang dalam toleransi = +10. Skor dibatasi 100', function (): void {
    $penilai = new PenilaiRisikoKasir;
    $hasil = $penilai->Nilai([
        1 => ['JumlahTransaksi' => 20, 'Kotor' => '1000000.00', 'JumlahVoid' => 0, 'VoidCepatTunai' => 0, 'JumlahRetur' => 6, 'JumlahBerdiskon' => 10, 'TotalDiskon' => '200000.00', 'BukaLaciManual' => 1, 'ShiftSelisihKurang' => 1, 'SelisihKurang' => '5000.00'],
        2 => ['JumlahTransaksi' => 80, 'Kotor' => '4000000.00', 'JumlahVoid' => 1, 'VoidCepatTunai' => 0, 'JumlahRetur' => 2, 'JumlahBerdiskon' => 4, 'TotalDiskon' => '40000.00'],
    ], Uang::Dari('10000'));

    expect($hasil[1]['Skor'])->toBe(40)
        ->and($hasil[1]['Tingkat'])->toBe('Sedang')
        ->and($hasil[1]['Alasan'])->toBe([
            'Diskon 20,0% dari penjualan kotor (rata-rata 4,8%)',
            '6 retur, 30,0% dari transaksi (rata-rata 8,0%)',
            'Kas kurang Rp 5.000 di 1 shift',
        ])
        ->and($hasil[2])->toBe(['Skor' => 0, 'Tingkat' => 'Rendah', 'Alasan' => []]);

    $puncak = $penilai->Nilai([
        7 => ['JumlahTransaksi' => 5, 'Kotor' => '100.00', 'JumlahVoid' => 5, 'VoidCepatTunai' => 5, 'JumlahRetur' => 5, 'JumlahBerdiskon' => 5, 'TotalDiskon' => '90.00', 'BukaLaciManual' => 9, 'ShiftSelisihKurang' => 2, 'SelisihKurang' => '900000.00'],
        8 => ['JumlahTransaksi' => 100, 'Kotor' => '100000.00', 'JumlahVoid' => 1, 'JumlahRetur' => 1, 'JumlahBerdiskon' => 1, 'TotalDiskon' => '10.00'],
    ], Uang::Dari('10000'));
    expect($puncak[7]['Skor'])->toBe(100)->and($puncak[7]['Tingkat'])->toBe('Tinggi');
});

it('satu kasir saja tanpa pembanding: rasio tidak menambah skor', function (): void {
    $hasil = (new PenilaiRisikoKasir)->Nilai([
        3 => ['JumlahTransaksi' => 10, 'Kotor' => '500000.00', 'JumlahVoid' => 5, 'VoidCepatTunai' => 0, 'JumlahRetur' => 0, 'JumlahBerdiskon' => 0, 'TotalDiskon' => '0.00'],
    ], Uang::Dari('10000'));

    expect($hasil[3]['Skor'])->toBe(0);
});
