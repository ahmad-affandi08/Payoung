<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Tenant;

use App\Domain\Pengelola\Tenant\Kueri\LaporanAnalitikPlatform;
use App\Domain\Pengelola\Tenant\Layanan\PengumpulFiturTenant;
use App\Http\Kontroler\Kontroler;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analitik platform (P-07 PGL-24, `/analitik`): funnel pendaftaran, adopsi fitur per sektor, dan retensi kohort.
 * Izin `analitik.lihat`; hanya angka agregat, tanpa nama tenant atau data pribadi (Analis boleh melihat, §19.3).
 */
final class AnalitikKontroler extends Kontroler
{
    public function Tampilkan(Request $permintaan, LaporanAnalitikPlatform $laporan): Response
    {
        $sampai = self::BacaTanggal($permintaan->query('sampai'));
        $dari = self::BacaTanggal($permintaan->query('dari'));

        if ($dari !== null && $sampai !== null && $dari->greaterThan($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        $pilihan = $laporan->AmbilPilihanSektor();
        $sektorQuery = $permintaan->query('sektor');
        $sektor = is_string($sektorQuery) && in_array($sektorQuery, array_column($pilihan, 'Nilai'), true) ? $sektorQuery : null;

        return Inertia::render('Pengelola/Analitik/Tampil', [
            'Saring' => ['Dari' => $dari?->toDateString() ?? '', 'Sampai' => $sampai?->toDateString() ?? '', 'Sektor' => $sektor ?? ''],
            'PilihanSektor' => $pilihan,
            'Funnel' => $laporan->Funnel($dari, $sampai, $sektor),
            'Adopsi' => $laporan->AdopsiFitur(),
            'Retensi' => $laporan->RetensiKohort($sektor),
            'HariFitur' => PengumpulFiturTenant::HARI_TERBARU,
        ]);
    }

    private static function BacaTanggal(mixed $nilai): ?CarbonImmutable
    {
        if (! is_string($nilai) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) !== 1) {
            return null;
        }

        $tanggal = CarbonImmutable::createFromFormat('!Y-m-d', $nilai, 'Asia/Jakarta');

        return $tanggal instanceof CarbonImmutable && $tanggal->toDateString() === $nilai ? $tanggal : null;
    }
}
