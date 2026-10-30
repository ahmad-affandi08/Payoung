<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Karyawan\Data\DataJadwalMingguan;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\ShiftKerja;
use Carbon\CarbonImmutable;

/**
 * Salin jadwal minggu sebelumnya ke minggu [senin] di outlet yang sama (F-18, izin `karyawan.kelola`): hanya karyawan
 * aktif dan hanya hari yang masih kosong (hari yang sudah punya jadwal apa pun tidak ditimpa). Semua shift pada satu
 * hari disalin bersama (D-251). Hari yang bertumpuk dengan shift hari sebelumnya dilewati. Seluruh salinan diproses dalam satu
 * transaksi lewat `SimpanJadwalMingguan::JalankanLonggar`.
 */
final class SalinJadwalMingguLalu
{
    public function __construct(
        private readonly SimpanJadwalMingguan $simpan,
        private readonly PencatatAudit $audit,
    ) {}

    /** @return int jumlah hari (karyawan + tanggal) yang disalin. Audit `jadwal-kerja.salin`. */
    public function Jalankan(int $idOutlet, CarbonImmutable $senin, ?int $idPengguna): int
    {
        $aktif = Karyawan::query()->where('Status', StatusKaryawan::Aktif->value)->get(['Id', 'Uuid'])->keyBy('Id');
        $lalu = JadwalKerja::query()
            ->where('IdOutlet', $idOutlet)
            ->whereIn('IdKaryawan', $aktif->keys()->all())
            ->whereBetween('Tanggal', [$senin->subDays(7)->toDateString(), $senin->subDay()->toDateString()])
            ->orderBy('Tanggal')
            ->orderBy('JamMulai')
            ->get();

        if ($lalu->isEmpty()) {
            return 0;
        }

        $sudah = JadwalKerja::query()
            ->whereIn('IdKaryawan', $lalu->pluck('IdKaryawan')->unique()->all())
            ->whereBetween('Tanggal', [$senin->toDateString(), $senin->addDays(6)->toDateString()])
            ->get(['IdKaryawan', 'Tanggal'])
            ->map(fn (JadwalKerja $j): string => $j->IdKaryawan.'|'.$j->Tanggal->toDateString())
            ->flip();
        $uuidShift = ShiftKerja::query()->pluck('Uuid', 'Id');
        $sel = [];

        foreach ($lalu->groupBy(fn (JadwalKerja $j): string => $j->IdKaryawan.'|'.$j->Tanggal->toDateString()) as $kelompok) {
            $pertama = $kelompok->first();
            $tanggal = $pertama === null ? null : CarbonImmutable::parse($pertama->Tanggal->toDateString())->addDays(7)->toDateString();

            if ($pertama === null || $tanggal === null || $sudah->has($pertama->IdKaryawan.'|'.$tanggal)) {
                continue;
            }

            $sel[] = [
                'UuidKaryawan' => $aktif[$pertama->IdKaryawan]->Uuid,
                'Tanggal' => $tanggal,
                'Shift' => $kelompok->map(fn (JadwalKerja $j): array => ['JamMulai' => $j->JamMulai, 'JamSelesai' => $j->JamSelesai, 'UuidShift' => $j->IdShiftKerja === null ? null : ($uuidShift[$j->IdShiftKerja] ?? null)])->values()->all(),
            ];
        }

        $disalin = $this->simpan->JalankanLonggar(new DataJadwalMingguan($idOutlet, $senin, $sel, $idPengguna))['Berubah'];

        if ($disalin > 0) {
            $this->audit->Catat('jadwal-kerja.salin', null, nilaiBaru: ['IdOutlet' => $idOutlet, 'Senin' => $senin->toDateString(), 'Jumlah' => $disalin], idPengguna: $idPengguna);
        }

        return $disalin;
    }
}
