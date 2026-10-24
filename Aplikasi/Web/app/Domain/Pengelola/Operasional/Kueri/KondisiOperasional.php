<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Pengelola\Operasional\Enum\HasilBackup;
use App\Domain\Pengelola\Operasional\Enum\JenisAlertOperasional;
use App\Domain\Pengelola\Operasional\Enum\JenisCatatanBackup;
use App\Domain\Pengelola\Operasional\Model\CatatanBackup;
use App\Domain\Pengelola\Operasional\Model\DetakPenjadwal;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kondisi operasional saat ini (P-11): detak scheduler, antrean (tabel `jobs` bawaan Laravel), dan backup terakhir.
 * Dipakai banner Platform Pengelola, dasbor, dan pemeriksa alert (BR-P11.1). Selalu dihitung dari data terkini,
 * sehingga banner tetap benar walau scheduler (yang menjalankan pemeriksa) sendiri yang mati.
 */
final class KondisiOperasional
{
    public function __construct(
        private readonly RingkasanMetrikApi $metrik,
        private readonly PerangkatOutboxMacet $outboxMacet,
    ) {}

    /**
     * @return array{TerakhirPada: Carbon|null, UmurDetik: int|null, Sehat: bool}
     */
    public function AmbilPenjadwal(): array
    {
        $terakhir = DetakPenjadwal::query()->where('Nama', DetakPenjadwal::NAMA_UTAMA)->value('TerakhirPada');
        $terakhir = $terakhir === null ? null : Carbon::parse($terakhir);
        $umur = $terakhir === null ? null : max(0, (int) $terakhir->diffInSeconds(now(), true));

        return [
            'TerakhirPada' => $terakhir,
            'UmurDetik' => $umur,
            'Sehat' => $umur !== null && $umur <= (int) config('operasional.BatasDetakPenjadwalMenit') * 60,
        ];
    }

    /**
     * Jumlah job per antrean dan umur job tertua yang sudah waktunya diproses (job tertunda/delay tidak dihitung).
     *
     * @return array{PerAntrean: list<array{Antrean: string, Menunggu: int, Diproses: int, UmurTertuaDetik: int|null}>, UmurTertuaDetik: int|null, Sehat: bool}
     */
    public function AmbilAntrean(): array
    {
        $sekarang = now()->getTimestamp();
        $baris = $this->AmbilTabelTugas()
            ->selectRaw('queue AS Antrean, SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) AS Menunggu')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL THEN 0 ELSE 1 END) AS Diproses')
            ->selectRaw('MIN(CASE WHEN available_at <= ? THEN available_at END) AS TersediaTertua', [$sekarang])
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();
        $perAntrean = [];
        $umurTertua = null;

        foreach ($baris as $antrean) {
            $tersedia = is_numeric($antrean->TersediaTertua) ? (int) $antrean->TersediaTertua : null;
            $umur = $tersedia === null ? null : max(0, $sekarang - $tersedia);
            $umurTertua = $umur === null ? $umurTertua : max($umurTertua ?? 0, $umur);
            $perAntrean[] = [
                'Antrean' => (string) $antrean->Antrean,
                'Menunggu' => (int) $antrean->Menunggu,
                'Diproses' => (int) $antrean->Diproses,
                'UmurTertuaDetik' => $umur,
            ];
        }

        return [
            'PerAntrean' => $perAntrean,
            'UmurTertuaDetik' => $umurTertua,
            'Sehat' => $umurTertua === null || $umurTertua <= (int) config('operasional.BatasUmurTugasTertuaMenit') * 60,
        ];
    }

    /**
     * @return array{BackupTerakhir: CatatanBackup|null, UjiRestoreTerakhir: CatatanBackup|null, Sehat: bool}
     */
    public function AmbilBackup(): array
    {
        $backup = $this->AmbilBerhasilTerakhir(JenisCatatanBackup::Backup);
        $batas = now()->subHours((int) config('operasional.BatasUmurBackupJam'));

        return [
            'BackupTerakhir' => $backup,
            'UjiRestoreTerakhir' => CatatanBackup::query()
                ->where('Jenis', JenisCatatanBackup::UjiRestore->value)
                ->orderByDesc('SelesaiPada')
                ->orderByDesc('Id')
                ->first(),
            'Sehat' => $backup !== null && $backup->SelesaiPada->gte($batas),
        ];
    }

    /**
     * Masalah yang sedang terjadi, satu per jenis alert.
     *
     * @return array<string, string> Kunci JenisAlertOperasional => pesan.
     */
    public function AmbilMasalah(): array
    {
        $masalah = [];
        $penjadwal = $this->AmbilPenjadwal();
        $antrean = $this->AmbilAntrean();
        $backup = $this->AmbilBackup();

        if (! $penjadwal['Sehat']) {
            $masalah[JenisAlertOperasional::PenjadwalBerhenti->value] = $penjadwal['UmurDetik'] === null
                ? 'Scheduler belum pernah berdetak. Periksa cron `schedule:run` di server.'
                : 'Scheduler tidak berdetak sejak '.self::FormatUmur($penjadwal['UmurDetik']).' lalu. Periksa cron `schedule:run` di server.';
        }

        if (! $antrean['Sehat']) {
            $masalah[JenisAlertOperasional::AntreanTertunda->value] = 'Job antrean tertua sudah menunggu '
                .self::FormatUmur((int) $antrean['UmurTertuaDetik']).'. Worker antrean kemungkinan macet.';
        }

        if (! $backup['Sehat']) {
            $masalah[JenisAlertOperasional::BackupTerlambat->value] = $backup['BackupTerakhir'] === null
                ? 'Belum ada backup berhasil yang tercatat.'
                : 'Backup berhasil terakhir '.self::FormatUmur((int) $backup['BackupTerakhir']->SelesaiPada->diffInSeconds(now(), true))
                    .' lalu (batas '.config('operasional.BatasUmurBackupJam').' jam).';
        }

        return $masalah;
    }

    /**
     * Masalah dari metrik API dan outbox perangkat (P-11, PGL-20). Lebih mahal daripada [AmbilMasalah] (membaca
     * `MetrikApi` dan `Perangkat`), jadi hanya dipakai pemeriksa alert terjadwal, bukan banner tiap halaman.
     *
     * @return array<string, string> Kunci JenisAlertOperasional => pesan.
     */
    public function AmbilMasalahMetrik(): array
    {
        $masalah = [];
        $jendela = (int) config('operasional.JendelaMetrikMenit');
        $minimum = (int) config('operasional.MinimumPermintaanMetrik');
        $batasGalat = BigDecimal::of((string) config('operasional.BatasErrorRatePersen'));
        $batasP95 = (int) config('operasional.BatasP95PosMs');
        $galat = [];

        foreach ($this->metrik->Ambil($jendela) as $kelompok) {
            if ($kelompok['Jumlah'] < $minimum) {
                continue;
            }

            if (BigDecimal::of($kelompok['ErrorRatePersen'])->isGreaterThanOrEqualTo($batasGalat)) {
                $galat[] = "{$kelompok['Kelompok']} {$kelompok['ErrorRatePersen']}% ({$kelompok['GalatServer']} dari {$kelompok['Jumlah']})";
            }

            if ($kelompok['Kelompok'] === 'Pos' && ($kelompok['P95Lebih'] || ($kelompok['P95Ms'] ?? 0) > $batasP95)) {
                $masalah[JenisAlertOperasional::LatensiPosTinggi->value] = 'p95 API POS '.($kelompok['P95Lebih'] ? 'di atas 1.600' : "sampai {$kelompok['P95Ms']}")
                    ." ms dalam {$jendela} menit terakhir (target {$batasP95} ms, {$kelompok['Jumlah']} permintaan).";
            }
        }

        if ($galat !== []) {
            $masalah[JenisAlertOperasional::ErrorRateTinggi->value] = "Masalah server (5xx) dalam {$jendela} menit terakhir mencapai {$batasGalat}% atau lebih: ".implode('; ', $galat).'.';
        }

        $tenantMacet = $this->outboxMacet->HitungTenant();

        if ($tenantMacet > 0) {
            $masalah[JenisAlertOperasional::OutboxMacet->value] = "{$tenantMacet} tenant punya perangkat dengan transaksi belum terkirim lebih dari "
                .config('operasional.BatasOutboxMacetJam').' jam. Daftarnya ada di dasbor operasional.';
        }

        return $masalah;
    }

    public static function FormatUmur(int $detik): string
    {
        return match (true) {
            $detik < 120 => "{$detik} detik",
            $detik < 7200 => intdiv($detik, 60).' menit',
            $detik < 172800 => intdiv($detik, 3600).' jam',
            default => intdiv($detik, 86400).' hari',
        };
    }

    private function AmbilBerhasilTerakhir(JenisCatatanBackup $jenis): ?CatatanBackup
    {
        return CatatanBackup::query()
            ->where('Jenis', $jenis->value)
            ->where('Hasil', HasilBackup::Berhasil->value)
            ->orderByDesc('SelesaiPada')
            ->orderByDesc('Id')
            ->first();
    }

    private function AmbilTabelTugas(): Builder
    {
        $koneksi = config('queue.connections.database.connection');

        return DB::connection(is_string($koneksi) ? $koneksi : null)->table((string) config('queue.connections.database.table', 'jobs'));
    }
}
