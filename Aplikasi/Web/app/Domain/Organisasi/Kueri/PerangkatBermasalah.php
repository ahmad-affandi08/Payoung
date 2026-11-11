<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Layanan\JamOperasionalOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Perangkat kasir yang perlu perhatian pemilik toko (D-269): (1) tidak terhubung ke server padahal outlet sedang buka,
 * (2) antrean kirimnya macet. Satu sumber untuk Kotak Tindakan, notifikasi operasional, dan Beranda Aplikasi Owner,
 * supaya ketiganya selalu sepakat.
 *
 * "Tidak terhubung" = perangkat aktif yang terakhir menghubungi server lebih dari `MENIT_TIDAK_TERHUBUNG` menit lalu
 * (atau belum pernah). Perangkat yang sengaja dimatikan saat toko tutup tidak dihitung: outlet harus **buka sekarang dan
 * sudah buka selama paling sedikit selama batas itu** (jadi perangkat yang belum sempat dinyalakan beberapa menit sesudah
 * jam buka tidak langsung dianggap bermasalah). Outlet tanpa jam operasional dianggap selalu buka. Antrean macet tidak
 * mengenal jam buka: data yang tertahan di perangkat tetap bermasalah walau tokonya tutup.
 */
final class PerangkatBermasalah
{
    public const MENIT_TIDAK_TERHUBUNG = 30;

    public function __construct(private readonly DaftarPerangkat $perangkat) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Nama: string, Kode: string, IdOutlet: int, TerakhirAktifPada: CarbonInterface|null}>
     */
    public function AmbilTidakTerhubung(?array $idOutletBoleh, ?CarbonImmutable $sekarang = null): array
    {
        $sekarang ??= CarbonImmutable::now();
        $kandidat = $this->perangkat->AmbilTidakAktif($idOutletBoleh, $sekarang->subMinutes(self::MENIT_TIDAK_TERHUBUNG));

        if ($kandidat === []) {
            return [];
        }

        $outlet = Outlet::query()->whereIn('Id', array_values(array_unique(array_column($kandidat, 'IdOutlet'))))->get(['Id', 'JamOperasional', 'ZonaWaktu'])->keyBy('Id');

        return array_values(array_filter($kandidat, function (array $p) use ($outlet, $sekarang): bool {
            $o = $outlet->get($p['IdOutlet']);

            if ($o === null) {
                return true;
            }

            $lokal = $sekarang->setTimezone($o->ZonaWaktu);

            return JamOperasionalOutlet::CekBuka($o->JamOperasional, $lokal)
                && JamOperasionalOutlet::CekBuka($o->JamOperasional, $lokal->subMinutes(self::MENIT_TIDAK_TERHUBUNG));
        }));
    }

    /**
     * Perangkat yang antrean kirimnya tidak kosong lebih lama dari `operasional.BatasOutboxMacetJam`: transaksi sudah
     * terjadi di kasir tetapi belum sampai ke server.
     *
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Nama: string, Kode: string, IdOutlet: int, JumlahOutbox: int, TertundaSejak: CarbonInterface}>
     */
    public function AmbilAntreanMacet(?array $idOutletBoleh): array
    {
        return array_values(Perangkat::query()
            ->when($idOutletBoleh !== null, fn ($kueri) => $kueri->whereIn('IdOutlet', $idOutletBoleh ?: [0]))
            ->whereNull('DicabutPada')
            ->whereNotNull('DiaktifkanPada')
            ->where('JumlahOutboxTertunda', '>', 0)
            ->whereNotNull('OutboxTertundaSejak')
            ->where('OutboxTertundaSejak', '<=', now()->subHours(self::BatasMacetJam()))
            ->orderBy('OutboxTertundaSejak')
            ->get(['Id', 'IdOutlet', 'Kode', 'Nama', 'JumlahOutboxTertunda', 'OutboxTertundaSejak'])
            ->map(fn (Perangkat $p): array => [
                'Nama' => $p->Nama,
                'Kode' => $p->Kode,
                'IdOutlet' => $p->IdOutlet,
                'JumlahOutbox' => $p->JumlahOutboxTertunda,
                'TertundaSejak' => $p->OutboxTertundaSejak,
            ])
            ->all());
    }

    /** Kalimat "terakhir terhubung" untuk daftar perangkat (Beranda Owner dan Kotak Tindakan). */
    public static function TulisTerakhirTerhubung(?CarbonInterface $terakhir, CarbonImmutable $sekarang): string
    {
        if ($terakhir === null) {
            return 'Belum pernah terhubung';
        }

        $menit = (int) $terakhir->diffInMinutes($sekarang, true);

        return match (true) {
            $menit < 120 => "Terakhir terhubung {$menit} menit lalu",
            $menit < 2880 => 'Terakhir terhubung '.intdiv($menit, 60).' jam lalu',
            default => 'Terakhir terhubung '.intdiv($menit, 1440).' hari lalu',
        };
    }

    /** Batas jam yang sama dengan alert konsol, supaya tenant dan tim Payoung memakai ukuran yang sama. */
    public static function BatasMacetJam(): int
    {
        return (int) config('operasional.BatasOutboxMacetJam');
    }
}
