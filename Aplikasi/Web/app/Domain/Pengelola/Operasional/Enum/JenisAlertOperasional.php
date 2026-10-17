<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Enum;

/**
 * Alert otomatis dasbor operasional (P-11, BR-P11.1, §14.5). Nilai = kolom `AlertOperasional.Kunci`.
 */
enum JenisAlertOperasional: string
{
    case PenjadwalBerhenti = 'PenjadwalBerhenti';
    case AntreanTertunda = 'AntreanTertunda';
    case BackupTerlambat = 'BackupTerlambat';
    case ErrorRateTinggi = 'ErrorRateTinggi';
    case LatensiPosTinggi = 'LatensiPosTinggi';
    case OutboxMacet = 'OutboxMacet';

    public function AmbilTingkat(): TingkatAlert
    {
        return match ($this) {
            self::PenjadwalBerhenti, self::AntreanTertunda, self::ErrorRateTinggi => TingkatAlert::Kritis,
            self::BackupTerlambat, self::LatensiPosTinggi, self::OutboxMacet => TingkatAlert::Peringatan,
        };
    }

    /**
     * Alert yang dinilai dari persentase/latensi lalu lintas API: sampelnya bisa kecil dan kondisinya naik-turun,
     * jadi dibuka dan ditutup dengan penundaan (`operasional.KonfirmasiMetrikMenit`, `PulihMetrikMenit`).
     */
    public function CekDariLaluLintasApi(): bool
    {
        return $this === self::ErrorRateTinggi || $this === self::LatensiPosTinggi;
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::PenjadwalBerhenti => 'Scheduler berhenti',
            self::AntreanTertunda => 'Antrean tertunda',
            self::BackupTerlambat => 'Backup terlambat',
            self::ErrorRateTinggi => 'Error rate API tinggi',
            self::LatensiPosTinggi => 'API POS lambat',
            self::OutboxMacet => 'Outbox perangkat macet',
        };
    }
}
