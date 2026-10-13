<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Pengelola\Integrasi\Kueri\PeringatanIntegrasi;
use App\Domain\Pengelola\Operasional\Layanan\PengaturanAlertOperasional;
use App\Domain\Pengelola\Operasional\Model\AlertOperasional;
use App\Domain\Pengelola\Operasional\Model\CatatanBackup;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Isi dasbor operasional dasar (P-11 Fase 0): detak scheduler, antrean, job gagal, backup & uji restore, alert, dan
 * kesehatan server (status integrasi P-05, ukuran database, ruang disk), serta (PGL-20) error rate & p95 API dan outbox
 * macet. Crash-free sessions per versi menyusul bersama pelaporan crash dari aplikasi.
 */
final class DasborOperasional
{
    public function __construct(
        private readonly KondisiOperasional $kondisi,
        private readonly TugasGagal $tugasGagal,
        private readonly PeringatanIntegrasi $peringatanIntegrasi,
        private readonly RingkasanMetrikApi $metrik,
        private readonly PerangkatOutboxMacet $outboxMacet,
        private readonly PengaturanAlertOperasional $pengaturanAlert,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(): array
    {
        $penjadwal = $this->kondisi->AmbilPenjadwal();
        $antrean = $this->kondisi->AmbilAntrean();
        $backup = $this->kondisi->AmbilBackup();

        return [
            'Penjadwal' => [
                'TerakhirPada' => $penjadwal['TerakhirPada']?->toIso8601String(),
                'UmurDetik' => $penjadwal['UmurDetik'],
                'Sehat' => $penjadwal['Sehat'],
                'BatasMenit' => (int) config('operasional.BatasDetakPenjadwalMenit'),
            ],
            'Antrean' => [
                ...$antrean,
                'BatasMenit' => (int) config('operasional.BatasUmurTugasTertuaMenit'),
            ],
            'TugasGagal' => [
                'Total' => $this->tugasGagal->Hitung(),
                'Data' => $this->tugasGagal->AmbilDaftar((int) config('operasional.JumlahTugasGagalDitampilkan')),
            ],
            'Backup' => [
                'BackupTerakhir' => $backup['BackupTerakhir'] === null ? null : self::PetakanBackup($backup['BackupTerakhir']),
                'UjiRestoreTerakhir' => $backup['UjiRestoreTerakhir'] === null ? null : self::PetakanBackup($backup['UjiRestoreTerakhir']),
                'Sehat' => $backup['Sehat'],
                'BatasJam' => (int) config('operasional.BatasUmurBackupJam'),
                'Riwayat' => $this->AmbilRiwayatBackup(),
            ],
            // PGL-20: error rate & p95 per kelompok API, dan perangkat dengan outbox macet.
            'Metrik' => [
                'Jendela' => (int) config('operasional.JendelaMetrikMenit'),
                'Terkini' => $this->metrik->Ambil((int) config('operasional.JendelaMetrikMenit')),
                'SatuJam' => $this->metrik->Ambil(60),
                'BatasErrorRatePersen' => (string) config('operasional.BatasErrorRatePersen'),
                'BatasP95PosMs' => (int) config('operasional.BatasP95PosMs'),
                'MinimumPermintaan' => (int) config('operasional.MinimumPermintaanMetrik'),
            ],
            'OutboxMacet' => [
                'BatasJam' => (int) config('operasional.BatasOutboxMacetJam'),
                'Daftar' => $this->outboxMacet->AmbilDaftar(),
            ],
            'AlertWa' => ['Nomor' => $this->pengaturanAlert->NomorWa(), 'Maks' => PengaturanAlertOperasional::MAKS_NOMOR],
            'Alert' => [
                'Aktif' => $this->AmbilAlert(true),
                'Riwayat' => $this->AmbilAlert(false),
            ],
            'Kesehatan' => [
                'PeringatanIntegrasi' => $this->peringatanIntegrasi->Ambil(),
                'UkuranDatabaseByte' => $this->AmbilUkuranDatabase(),
                'Disk' => $this->AmbilDisk(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function AmbilRiwayatBackup(): array
    {
        $catatan = CatatanBackup::query()->orderByDesc('SelesaiPada')->orderByDesc('Id')->limit(10)->get();
        $idPelaku = array_values(array_filter($catatan->pluck('IdPenggunaPengelola')->all(), 'is_int'));
        $nama = $idPelaku === [] ? collect() : PenggunaPengelola::query()->whereKey($idPelaku)->pluck('Nama', 'Id');

        return array_values($catatan->map(fn (CatatanBackup $baris): array => [
            ...self::PetakanBackup($baris),
            'DicatatOleh' => $baris->IdPenggunaPengelola === null ? 'Skrip server' : (string) ($nama[$baris->IdPenggunaPengelola] ?? '-'),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private static function PetakanBackup(CatatanBackup $catatan): array
    {
        return [
            'Uuid' => $catatan->Uuid,
            'Jenis' => $catatan->Jenis->value,
            'LabelJenis' => $catatan->Jenis->AmbilLabel(),
            'Hasil' => $catatan->Hasil->value,
            'SelesaiPada' => $catatan->SelesaiPada->toIso8601String(),
            'UkuranByte' => $catatan->UkuranByte,
            'Lokasi' => $catatan->Lokasi,
            'Keterangan' => $catatan->Keterangan,
            'Sumber' => $catatan->Sumber->value,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function AmbilAlert(bool $aktif): array
    {
        return array_values(AlertOperasional::query()
            ->when($aktif, fn ($kueri) => $kueri->whereNull('SelesaiPada'), fn ($kueri) => $kueri->whereNotNull('SelesaiPada'))
            ->orderByDesc('Id')
            ->limit(10)
            ->get()
            ->map(fn (AlertOperasional $alert): array => [
                'Id' => $alert->Id,
                'Kunci' => $alert->Kunci->value,
                'Label' => $alert->Kunci->AmbilLabel(),
                'Tingkat' => $alert->Tingkat->value,
                'Pesan' => $alert->Pesan,
                'MulaiPada' => $alert->MulaiPada->toIso8601String(),
                'SelesaiPada' => $alert->SelesaiPada?->toIso8601String(),
                'EmailTerkirimPada' => $alert->EmailTerkirimPada?->toIso8601String(),
                'WaTerkirimPada' => $alert->WaTerkirimPada?->toIso8601String(),
            ])
            ->all());
    }

    private function AmbilUkuranDatabase(): ?int
    {
        try {
            $hasil = DB::selectOne('SELECT SUM(DATA_LENGTH + INDEX_LENGTH) AS Ukuran FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

            return is_object($hasil) && is_numeric($hasil->Ukuran ?? null) ? (int) $hasil->Ukuran : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ruang disk partisi storage aplikasi. Null bila fungsi dimatikan hosting.
     *
     * @return array{SisaByte: int, TotalByte: int}|null
     */
    private function AmbilDisk(): ?array
    {
        $path = storage_path();
        $sisa = function_exists('disk_free_space') ? @disk_free_space($path) : false;
        $total = function_exists('disk_total_space') ? @disk_total_space($path) : false;

        if (! is_float($sisa) || ! is_float($total) || $total <= 0) {
            return null;
        }

        return ['SisaByte' => (int) $sisa, 'TotalByte' => (int) $total];
    }
}
