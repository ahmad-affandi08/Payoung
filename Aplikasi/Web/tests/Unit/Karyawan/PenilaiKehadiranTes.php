<?php

declare(strict_types=1);

use App\Domain\Karyawan\Layanan\PenilaiKehadiran;
use Carbon\CarbonImmutable;

/*
 * F-18 bagian 5 (D-44): penilaian absensi vs jadwal (fungsi murni, jam dinding outlet). Terlambat dan pulang cepat baru
 * dihitung setelah melewati toleransinya dan dilaporkan sebagai selisih penuh; lembur baru dihitung setelah ambang.
 */

function NilaiUji(string $masuk, ?string $keluar, string $mulai = '08:00', string $selesai = '16:00', string $tanggal = '2026-10-05'): array
{
    $waktu = fn (string $w): CarbonImmutable => CarbonImmutable::parse($w, 'UTC');

    return (new PenilaiKehadiran)->Nilai($tanggal, $mulai, $selesai, $waktu($masuk), $keluar === null ? null : $waktu($keluar), 5, 5, 30);
}

it('tepat waktu, di dalam toleransi: tidak ada terlambat, pulang cepat, maupun lembur', function (): void {
    expect(NilaiUji('2026-10-05 08:05:00', '2026-10-05 15:55:00'))->toBe(['TerlambatMenit' => 0, 'PulangCepatMenit' => 0, 'LemburMenit' => 0]);
});

it('terlambat melewati toleransi dihitung penuh dari jam mulai. Pulang cepat dihitung penuh dari jam selesai', function (): void {
    expect(NilaiUji('2026-10-05 08:06:00', '2026-10-05 15:50:00'))->toBe(['TerlambatMenit' => 6, 'PulangCepatMenit' => 10, 'LemburMenit' => 0]);
});

it('lembur hanya setelah ambang 30 menit dan dihitung penuh dari jam selesai', function (): void {
    expect(NilaiUji('2026-10-05 08:00:00', '2026-10-05 16:29:00')['LemburMenit'])->toBe(0)
        ->and(NilaiUji('2026-10-05 08:00:00', '2026-10-05 16:30:00')['LemburMenit'])->toBe(30)
        ->and(NilaiUji('2026-10-05 08:00:00', '2026-10-05 18:00:00')['LemburMenit'])->toBe(120);
});

it('belum absen keluar: hanya terlambat yang bisa dinilai', function (): void {
    expect(NilaiUji('2026-10-05 08:20:00', null))->toBe(['TerlambatMenit' => 20, 'PulangCepatMenit' => 0, 'LemburMenit' => 0]);
});

it('shift malam melewati tengah malam: selesai jatuh di hari berikutnya', function (): void {
    $batas = PenilaiKehadiran::AmbilBatasShift('2026-10-05', '22:00', '06:00');
    expect($batas['Mulai']->toDateTimeString())->toBe('2026-10-05 22:00:00')
        ->and($batas['Selesai']->toDateTimeString())->toBe('2026-10-06 06:00:00')
        ->and(NilaiUji('2026-10-05 22:00:00', '2026-10-06 05:30:00', '22:00', '06:00'))->toBe(['TerlambatMenit' => 0, 'PulangCepatMenit' => 30, 'LemburMenit' => 0])
        ->and(NilaiUji('2026-10-05 22:00:00', '2026-10-06 07:00:00', '22:00', '06:00')['LemburMenit'])->toBe(60);
});

it('jadwal yang jam selesainya sama dengan jam mulai dianggap 24 jam, bukan nol', function (): void {
    $batas = PenilaiKehadiran::AmbilBatasShift('2026-10-05', '08:00', '08:00');
    expect($batas['Selesai']->toDateTimeString())->toBe('2026-10-06 08:00:00');
});
