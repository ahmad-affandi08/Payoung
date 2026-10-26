<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Layanan;

use App\Domain\Akuntansi\Model\KunciPeriode;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Sinkron\Layanan\PenandaSinkronPos;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Menolak transaksi bertanggal di periode terkunci (`PeriodeTerkunci`, §11, DesainF05a C.5). Dipanggil jurnal dan
 * buku stok (H-9) di dalam transaksi posting: pembacaan memakai kunci bersama agar penguncian periode yang berjalan
 * bersamaan tidak terlewat.
 *
 * F-15/§18 (v1.81): transaksi yang sudah terjadi di aplikasi kasir (item sinkron POS, lihat `PenandaSinkronPos`) tidak
 * ditolak. `SesuaikanTanggalPosting` menggeser tanggal jurnal & mutasi stoknya ke hari pertama periode terbuka
 * berikutnya; dokumen POS tetap bertanggal bisnis asli dan ditandai tinjauan `PeriodeTerkunci` oleh penerimanya.
 */
final class PenjagaKunciPeriode
{
    /** Batas pencarian periode terbuka ke depan (bulan). */
    private const BATAS_GESER_BULAN = 60;

    public function __construct(private readonly PenandaSinkronPos $sinkronPos) {}

    private const NAMA_BULAN = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
        '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];

    /**
     * @throws PelanggaranAturanBisnis `PeriodeTerkunci` bila periode tanggal ini sudah dikunci
     */
    public function PastikanTerbuka(CarbonInterface $tanggal): void
    {
        $periode = $tanggal->format('Y-m');

        if (! $this->CariTerkunci($periode, true)) {
            return;
        }

        throw new PelanggaranAturanBisnis(
            'PeriodeTerkunci',
            'Periode '.self::FormatPeriode($periode).' sudah dikunci. Pakai tanggal di periode yang masih terbuka, atau minta Akuntan membuka kunci periode.',
            'Tanggal',
            detail: ['Periode' => $periode],
        );
    }

    /**
     * Tanggal posting jurnal/mutasi stok: [tanggal] bila periodenya terbuka. Periode terkunci: saat sinkron POS = hari
     * pertama periode terbuka berikutnya; selain itu ditolak `PeriodeTerkunci` (sama dengan `PastikanTerbuka`).
     */
    public function SesuaikanTanggalPosting(CarbonInterface $tanggal): CarbonImmutable
    {
        $tanggal = CarbonImmutable::parse($tanggal->toDateString());

        if (! $this->CariTerkunci($tanggal->format('Y-m'), true)) {
            return $tanggal;
        }

        if (! $this->sinkronPos->CekAktif()) {
            $this->PastikanTerbuka($tanggal);
        }

        return $this->CariTanggalTerbukaBerikutnya($tanggal);
    }

    /**
     * Alasan tinjauan untuk dokumen POS bertanggal di periode terkunci (null bila terbuka), misal
     * "PeriodeTerkunci: periode September 2026 sudah dikunci, jurnal & stok dibukukan 2026-10-01".
     */
    public function JelaskanPergeseran(CarbonInterface $tanggal): ?string
    {
        $periode = $tanggal->format('Y-m');

        if (! $this->CariTerkunci($periode, true)) {
            return null;
        }

        $posting = $this->CariTanggalTerbukaBerikutnya(CarbonImmutable::parse($tanggal->toDateString()));

        return 'PeriodeTerkunci: periode '.self::FormatPeriode($periode).' sudah dikunci, jurnal & stok dibukukan '.$posting->toDateString();
    }

    /**
     * @param  string  $periode  `YYYY-MM`
     *
     * @throws InvalidArgumentException bila periode tidak berformat `YYYY-MM`
     */
    public function CekTerkunci(string $periode): bool
    {
        return $this->CariTerkunci($periode, false);
    }

    /** "2026-09" → "September 2026". */
    public static function FormatPeriode(string $periode): string
    {
        [$tahun, $bulan] = explode('-', $periode) + ['', ''];

        return trim((self::NAMA_BULAN[$bulan] ?? $bulan).' '.$tahun);
    }

    /**
     * "2026-10-19", "2026-10-25" → "19–25 Oktober 2026"; lintas bulan "28 Oktober – 3 November 2026"; lintas tahun
     * "29 Desember 2026 – 4 Januari 2027".
     */
    public static function FormatRentang(string $dari, string $sampai): string
    {
        [$t1, $b1, $h1] = explode('-', $dari) + ['', '', ''];
        [$t2, $b2, $h2] = explode('-', $sampai) + ['', '', ''];
        $hari1 = (string) (int) $h1;
        $hari2 = (string) (int) $h2;
        $bulan1 = self::NAMA_BULAN[$b1] ?? $b1;
        $bulan2 = self::NAMA_BULAN[$b2] ?? $b2;

        if ($t1 !== $t2) {
            return "{$hari1} {$bulan1} {$t1} – {$hari2} {$bulan2} {$t2}";
        }

        return $b1 === $b2 ? "{$hari1}–{$hari2} {$bulan1} {$t1}" : "{$hari1} {$bulan1} – {$hari2} {$bulan2} {$t1}";
    }

    private function CariTanggalTerbukaBerikutnya(CarbonImmutable $tanggal): CarbonImmutable
    {
        $awal = $tanggal->startOfMonth();

        for ($i = 1; $i <= self::BATAS_GESER_BULAN; $i++) {
            $calon = $awal->addMonthsNoOverflow($i);

            if (! $this->CariTerkunci($calon->format('Y-m'), true)) {
                return $calon;
            }
        }

        throw new PelanggaranAturanBisnis('PeriodeTerkunci', 'Tidak ada periode terbuka untuk membukukan transaksi ini. Minta Akuntan membuka kunci periode.', 'Tanggal');
    }

    private function CariTerkunci(string $periode, bool $kunciBaca): bool
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode) !== 1) {
            throw new InvalidArgumentException("Periode {$periode} harus berformat YYYY-MM.");
        }

        return KunciPeriode::query()
            ->where('Periode', $periode)
            ->when($kunciBaca, fn ($kueri) => $kueri->sharedLock())
            ->first(['Id']) !== null;
    }
}
