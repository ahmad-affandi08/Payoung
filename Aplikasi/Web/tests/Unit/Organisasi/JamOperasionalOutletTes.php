<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Layanan\JamOperasionalOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** Senin sampai Sabtu 08:00–21:00, Minggu libur. */
function JamWarung(): array
{
    $hari = ['Buka' => '08:00', 'Tutup' => '21:00'];

    return ['Senin' => $hari, 'Selasa' => $hari, 'Rabu' => $hari, 'Kamis' => $hari, 'Jumat' => $hari, 'Sabtu' => $hari, 'Minggu' => null];
}

it('tanpa pengaturan outlet selalu buka dan tidak ada pesan tutup', function (): void {
    $malam = CarbonImmutable::parse('2026-10-14 03:00', 'Asia/Jakarta');

    expect(JamOperasionalOutlet::CekBuka(null, $malam))->toBeTrue()
        ->and(JamOperasionalOutlet::CariBukaBerikut(null, $malam))->toBeNull()
        ->and(JamOperasionalOutlet::SusunPesanTutup(null, $malam))->toBeNull();
});

it('buka tepat pada jam buka dan tutup tepat pada jam tutup', function (): void {
    $jam = JamWarung();
    // Rabu 14 Oktober 2026.
    expect(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-14 07:59')))->toBeFalse()
        ->and(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-14 08:00')))->toBeTrue()
        ->and(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-14 20:59')))->toBeTrue()
        ->and(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-14 21:00')))->toBeFalse();
});

it('hari libur tutup seharian; buka berikutnya dicari ke hari buka terdekat', function (): void {
    $jam = JamWarung();
    // Minggu 18 Oktober 2026 siang.
    $minggu = CarbonImmutable::parse('2026-10-18 12:00');
    expect(JamOperasionalOutlet::CekBuka($jam, $minggu))->toBeFalse()
        ->and(JamOperasionalOutlet::CariBukaBerikut($jam, $minggu)?->toDateTimeString())->toBe('2026-10-19 08:00:00')
        ->and(JamOperasionalOutlet::SusunPesanTutup($jam, $minggu))->toBe('Buka lagi besok pukul 08.00.');
    // Rabu pukul 22.00 → besok pagi; Rabu pukul 05.00 → hari ini.
    expect(JamOperasionalOutlet::SusunPesanTutup($jam, CarbonImmutable::parse('2026-10-14 22:00')))->toBe('Buka lagi besok pukul 08.00.')
        ->and(JamOperasionalOutlet::SusunPesanTutup($jam, CarbonImmutable::parse('2026-10-14 05:00')))->toBe('Buka lagi hari ini pukul 08.00.')
        // Sabtu malam → Minggu libur → Senin (bukan besok).
        ->and(JamOperasionalOutlet::SusunPesanTutup($jam, CarbonImmutable::parse('2026-10-17 22:00')))->toBe('Buka lagi Senin pukul 08.00.');
});

it('jam yang melewati tengah malam tetap berlaku sampai jam tutup keesokan harinya', function (): void {
    $jam = JamWarung();
    $jam['Jumat'] = ['Buka' => '18:00', 'Tutup' => '02:00'];
    // Jumat 16 Oktober 2026 19.00 buka; Sabtu 17 Oktober 01.30 masih buka; 02.00 tutup; Sabtu jam 08.00 buka dengan jam Sabtu.
    expect(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-16 19:00')))->toBeTrue()
        ->and(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-17 01:30')))->toBeTrue()
        ->and(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-17 02:00')))->toBeFalse()
        ->and(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-16 10:00')))->toBeFalse()
        ->and(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-17 08:00')))->toBeTrue();
    // Tutup 00:00 berarti sampai tengah malam.
    $jam['Kamis'] = ['Buka' => '08:00', 'Tutup' => '00:00'];
    expect(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-15 23:30')))->toBeTrue()
        ->and(JamOperasionalOutlet::CekBuka($jam, CarbonImmutable::parse('2026-10-16 00:00')))->toBeFalse();
});

it('normalkan: hari tak disebut jadi libur; jam tidak sah, buka = tutup, dan semua libur ditolak; null = tidak diatur', function (): void {
    $hasil = JamOperasionalOutlet::Normalkan(['Senin' => ['Buka' => '08:00', 'Tutup' => '17:00']]);
    expect($hasil)->not->toBeNull()
        ->and(array_keys((array) $hasil))->toBe(array_values(JamOperasionalOutlet::HARI))
        ->and($hasil['Senin'])->toBe(['Buka' => '08:00', 'Tutup' => '17:00'])
        ->and($hasil['Selasa'])->toBeNull()
        ->and(JamOperasionalOutlet::Normalkan(null))->toBeNull()
        ->and(JamOperasionalOutlet::Normalkan(['Senin' => ['Buka' => '', 'Tutup' => ''], 'Selasa' => ['Buka' => '09:00', 'Tutup' => '10:00']])['Senin'])->toBeNull();

    foreach ([
        'jam tidak sah' => ['Senin' => ['Buka' => '25:00', 'Tutup' => '17:00']],
        'format salah' => ['Senin' => ['Buka' => '8.00', 'Tutup' => '17:00']],
        'tutup kosong' => ['Senin' => ['Buka' => '08:00', 'Tutup' => '']],
        'buka sama tutup' => ['Senin' => ['Buka' => '08:00', 'Tutup' => '08:00']],
        'semua libur' => ['Senin' => null],
    ] as $kasus => $masukan) {
        expect(fn () => JamOperasionalOutlet::Normalkan($masukan))->toThrow(PelanggaranAturanBisnis::class, '', $kasus);
    }
});

it('tidak menggeser objek waktu mutable milik pemanggil (now() Laravel)', function (): void {
    $lokal = Carbon::parse('2026-10-18 12:00', 'Asia/Jakarta');
    $awal = $lokal->toDateTimeString();

    JamOperasionalOutlet::CekBuka(JamWarung(), $lokal);
    JamOperasionalOutlet::SusunPesanTutup(JamWarung(), $lokal);
    JamOperasionalOutlet::CariBukaBerikut(JamWarung(), $lokal);

    expect($lokal->toDateTimeString())->toBe($awal);
});
