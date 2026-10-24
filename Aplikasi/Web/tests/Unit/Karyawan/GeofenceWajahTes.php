<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Layanan\PencocokWajah;
use App\Domain\Karyawan\Layanan\PengukurJarak;

/*
 * F-18 bagian 4 (D-37): jarak geofence & kemiripan wajah dihitung dengan desimal presisi tetap.
 */

it('jarak GPS dalam meter sama dengan haversine untuk jarak outlet (±1 m)', function (): void {
    $pengukur = new PengukurJarak;

    // Monas → Bundaran HI ≈ 2.217 m; dua titik ±88 m di Surakarta; titik yang sama = 0.
    expect($pengukur->HitungMeter('-6.1753924', '106.8271528', '-6.1949000', '106.8230000'))->toBe(2217)
        ->and($pengukur->HitungMeter('-7.5560000', '110.8310000', '-7.5560000', '110.8318000'))->toBe(88)
        ->and($pengukur->HitungMeter('-7.5560000', '110.8310000', '-7.5560000', '110.8310000'))->toBe(0);
});

it('kemiripan wajah: identik = 1, berlawanan = 0, dihitung terhadap rata-rata sidik terdaftar; panjang lain diabaikan', function (): void {
    $pencocok = new PencocokWajah;
    $a = array_map(fn (int $i): int => ($i % 7) * 100 - 300, range(0, 127));
    $b = array_map(fn (int $n): int => -$n, $a);
    $mirip = array_map(fn (int $n, int $i): int => $n + ($i % 3 === 0 ? 20 : 0), $a, range(0, 127));

    expect((string) $pencocok->HitungKemiripan($a, [$a]))->toBe('1.0000')
        ->and((string) $pencocok->HitungKemiripan($a, [$b]))->toBe('0')
        ->and($pencocok->HitungKemiripan($a, [$a, $mirip])->isGreaterThan('0.99'))->toBeTrue()
        // Rata-rata menggantikan "yang tertinggi" (D-188): satu sidik terdaftar yang berlawanan menetralkan yang lain.
        ->and((string) $pencocok->HitungKemiripan($a, [$a, $b]))->toBe('0')
        ->and((string) $pencocok->HitungKemiripan($a, [array_slice($a, 0, 64), $a]))->toBe('1.0000')
        ->and((string) $pencocok->HitungKemiripan($a, [array_slice($a, 0, 64)]))->toBe('0');
});

it('sidik wajah tidak sah ditolak: terlalu pendek, bukan bilangan bulat, nilai di luar batas, semua nol', function (): void {
    $pencocok = new PencocokWajah;

    foreach ([range(1, 10), array_fill(0, 128, 0.5), array_fill(0, 128, 200000), array_fill(0, 128, 0), 'bukan-larik'] as $sidik) {
        expect(fn () => $pencocok->Validasi($sidik))->toThrow(PelanggaranAturanBisnis::class);
    }

    expect($pencocok->Validasi(array_fill(0, 128, 5)))->toHaveCount(128);
});

it('sidik wajah orang lain yang agak mirip tidak lolos ambang 0,70; sidik yang sama dengan sedikit selisih tetap lolos', function (): void {
    $pencocok = new PencocokWajah;
    $acuan = array_map(fn (int $i): int => (int) round(3000 * sin($i * 0.37) + 800), range(0, 1023));
    // Orang lain: sebagian besar komponen berbeda, tetapi ada komponen bersama (rata-rata deskriptor yang positif).
    $lain = array_map(fn (int $n, int $i): int => (int) round(0.45 * $n + 3000 * sin($i * 1.31 + 2)), $acuan, range(0, 1023));
    $sama = array_map(fn (int $n, int $i): int => $n + (($i * 37) % 301) - 150, $acuan, range(0, 1023));

    expect($pencocok->CekCocok($pencocok->HitungKemiripan($lain, [$acuan, $acuan, $acuan])))->toBeFalse()
        ->and($pencocok->CekCocok($pencocok->HitungKemiripan($sama, [$acuan, $acuan, $acuan])))->toBeTrue();
});
