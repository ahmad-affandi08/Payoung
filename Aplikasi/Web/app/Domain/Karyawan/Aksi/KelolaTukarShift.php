<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Enum\StatusTukarShift;
use App\Domain\Karyawan\Kueri\CutiAktifKaryawan;
use App\Domain\Karyawan\Layanan\PemeriksaBentrokJadwal;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\PermintaanTukarShift;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tukar shift antar karyawan (F-18, D-251). Alur: pemohon mengajukan dari halaman Absen (satu shiftnya, plus opsional shift
 * rekan sebagai balasan) lalu rekan menerima atau menolak lalu pengelola (izin `karyawan.kelola`) menyetujui atau menolak.
 * Jadwal baru berubah saat disetujui, dan saat itu semuanya diperiksa ulang (jadwal masih milik yang sama, belum lewat,
 * tidak bertumpuk, tidak sedang cuti, tidak bentrok dengan outlet lain). Audit `tukar-shift.*`.
 */
final class KelolaTukarShift
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly CutiAktifKaryawan $cuti,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
    ) {}

    public function Ajukan(Karyawan $pemohon, string $uuidJadwal, string $uuidRekan, ?string $uuidJadwalRekan, string $alasan): PermintaanTukarShift
    {
        return DB::transaction(fn (): PermintaanTukarShift => $this->BuatPermintaan($pemohon, $uuidJadwal, $uuidRekan, $uuidJadwalRekan, $alasan), 3);
    }

    /**
     * Baris jadwal dikunci sebelum pemeriksaan permintaan ganda, jadi dua pengajuan bersamaan untuk shift yang sama
     * tidak lolos berbarengan.
     */
    private function BuatPermintaan(Karyawan $pemohon, string $uuidJadwal, string $uuidRekan, ?string $uuidJadwalRekan, string $alasan): PermintaanTukarShift
    {
        $jadwal = JadwalKerja::query()->where('Uuid', $uuidJadwal)->where('IdKaryawan', $pemohon->Id)->lockForUpdate()->first();
        $rekan = Karyawan::query()->where('Uuid', $uuidRekan)->first();

        if (! $jadwal instanceof JadwalKerja) {
            throw new PelanggaranAturanBisnis('JadwalTidakDitemukan', 'Shift yang akan ditukar tidak ditemukan di jadwal Anda.', 'UuidJadwal');
        }

        if (! $rekan instanceof Karyawan || $rekan->Id === $pemohon->Id || $rekan->Status !== StatusKaryawan::Aktif) {
            throw new PelanggaranAturanBisnis('RekanTidakValid', 'Pilih rekan lain yang masih aktif.', 'UuidRekan');
        }

        $this->PastikanBelumLewat($jadwal);
        $jadwalRekan = null;

        if ($uuidJadwalRekan !== null) {
            $jadwalRekan = JadwalKerja::query()->where('Uuid', $uuidJadwalRekan)->where('IdKaryawan', $rekan->Id)->where('IdOutlet', $jadwal->IdOutlet)->lockForUpdate()->first();

            if (! $jadwalRekan instanceof JadwalKerja) {
                throw new PelanggaranAturanBisnis('JadwalTidakDitemukan', 'Shift rekan yang diminta tidak ditemukan di outlet yang sama.', 'UuidJadwalRekan');
            }

            $this->PastikanBelumLewat($jadwalRekan);

            if ($jadwalRekan->Tanggal->toDateString() === $jadwal->Tanggal->toDateString() && $jadwalRekan->JamMulai === $jadwal->JamMulai) {
                throw new PelanggaranAturanBisnis('ShiftSama', 'Kedua shift dimulai di tanggal dan jam yang sama, tidak perlu ditukar.', 'UuidJadwalRekan');
            }
        }

        $sudah = PermintaanTukarShift::query()
            ->whereIn('Status', StatusTukarShift::NilaiTerbuka())
            ->where(fn ($q) => $q->whereIn('IdJadwalPemohon', array_filter([$jadwal->Id, $jadwalRekan?->Id]))->orWhereIn('IdJadwalRekan', array_filter([$jadwal->Id, $jadwalRekan?->Id])))
            ->exists();

        if ($sudah) {
            throw new PelanggaranAturanBisnis('PermintaanSudahAda', 'Shift ini sudah ada dalam permintaan tukar yang masih berjalan.', 'UuidJadwal');
        }

        $permintaan = PermintaanTukarShift::query()->create([
            'IdJadwalPemohon' => $jadwal->Id,
            'IdKaryawanPemohon' => $pemohon->Id,
            'IdKaryawanRekan' => $rekan->Id,
            'IdJadwalRekan' => $jadwalRekan?->Id,
            'Status' => StatusTukarShift::MenungguRekan,
            'Alasan' => mb_substr(trim($alasan), 0, 200),
        ]);
        $this->audit->Catat('tukar-shift.ajukan', $permintaan, nilaiBaru: ['Pemohon' => $pemohon->Id, 'Rekan' => $rekan->Id, 'Tanggal' => $jadwal->Tanggal->toDateString()]);

        return $permintaan;
    }

    public function Tanggapi(Karyawan $rekan, string $uuid, bool $terima): PermintaanTukarShift
    {
        return DB::transaction(function () use ($rekan, $uuid, $terima): PermintaanTukarShift {
            $permintaan = $this->CariTerbuka($uuid);

            if ($permintaan->IdKaryawanRekan !== $rekan->Id || $permintaan->Status !== StatusTukarShift::MenungguRekan) {
                throw new PelanggaranAturanBisnis('TidakBerhak', 'Permintaan ini bukan untuk Anda atau sudah ditanggapi.', 'Umum');
            }

            $permintaan->forceFill([
                'Status' => $terima ? StatusTukarShift::MenungguPengelola : StatusTukarShift::Ditolak,
                'RekanMenanggapiPada' => now(),
                'AlasanKeputusan' => $terima ? null : 'Ditolak oleh rekan.',
                'DiputuskanPada' => $terima ? null : now(),
            ])->save();
            $this->audit->Catat($terima ? 'tukar-shift.terima' : 'tukar-shift.tolak-rekan', $permintaan);

            return $permintaan;
        }, 3);
    }

    public function Batalkan(Karyawan $pemohon, string $uuid): PermintaanTukarShift
    {
        return DB::transaction(function () use ($pemohon, $uuid): PermintaanTukarShift {
            $permintaan = $this->CariTerbuka($uuid);

            if ($permintaan->IdKaryawanPemohon !== $pemohon->Id) {
                throw new PelanggaranAturanBisnis('TidakBerhak', 'Hanya pemohon yang bisa membatalkan permintaan ini.', 'Umum');
            }

            $permintaan->forceFill(['Status' => StatusTukarShift::Dibatalkan, 'DiputuskanPada' => now()])->save();
            $this->audit->Catat('tukar-shift.batal', $permintaan);

            return $permintaan;
        }, 3);
    }

    /**
     * @param  list<int>|null  $idOutletBoleh  outlet yang boleh diakses pengelola, null = semua. Permintaan untuk shift di
     *                                          luar akses itu diperlakukan tidak ada.
     */
    public function Putuskan(string $uuid, bool $setuju, ?string $alasan, int $idPengguna, ?array $idOutletBoleh = null): PermintaanTukarShift
    {
        return DB::transaction(function () use ($uuid, $setuju, $alasan, $idPengguna, $idOutletBoleh): PermintaanTukarShift {
            // Urutan gembok sama dengan penyimpanan jadwal (karyawan dulu, baru baris lain) supaya tidak saling menunggu.
            $awal = PermintaanTukarShift::query()->where('Uuid', $uuid)->first();

            if ($awal instanceof PermintaanTukarShift) {
                Karyawan::query()->whereIn('Id', [$awal->IdKaryawanPemohon, $awal->IdKaryawanRekan])->orderBy('Id')->lockForUpdate()->get(['Id']);
            }

            $permintaan = PermintaanTukarShift::query()->where('Uuid', $uuid)->lockForUpdate()->first();

            if (! $permintaan instanceof PermintaanTukarShift || $permintaan->Status !== StatusTukarShift::MenungguPengelola) {
                throw new PelanggaranAturanBisnis('TidakBisaDiputuskan', 'Permintaan tidak ditemukan atau belum/sudah diputuskan.', 'Umum');
            }

            if ($idOutletBoleh !== null) {
                $idOutlet = JadwalKerja::query()->whereKey($permintaan->IdJadwalPemohon)->value('IdOutlet');

                if ($idOutlet === null || ! in_array((int) $idOutlet, $idOutletBoleh, true)) {
                    throw new PelanggaranAturanBisnis('TidakBisaDiputuskan', 'Permintaan tidak ditemukan atau belum/sudah diputuskan.', 'Umum');
                }
            }

            if ($setuju) {
                $this->Tukar($permintaan);
            }

            $permintaan->forceFill([
                'Status' => $setuju ? StatusTukarShift::Disetujui : StatusTukarShift::Ditolak,
                'AlasanKeputusan' => $alasan === null || trim($alasan) === '' ? null : mb_substr(trim($alasan), 0, 200),
                'DiputuskanOleh' => $idPengguna,
                'DiputuskanPada' => now(),
            ])->save();
            $this->audit->Catat($setuju ? 'tukar-shift.setujui' : 'tukar-shift.tolak', $permintaan, idPengguna: $idPengguna);

            return $permintaan;
        }, 3);
    }

    private function Tukar(PermintaanTukarShift $permintaan): void
    {
        $milikPemohon = JadwalKerja::query()->whereKey($permintaan->IdJadwalPemohon)->lockForUpdate()->first();
        $milikRekan = $permintaan->IdJadwalRekan === null ? null : JadwalKerja::query()->whereKey($permintaan->IdJadwalRekan)->lockForUpdate()->first();

        if (! $milikPemohon instanceof JadwalKerja || $milikPemohon->IdKaryawan !== $permintaan->IdKaryawanPemohon
            || ($permintaan->IdJadwalRekan !== null && (! $milikRekan instanceof JadwalKerja || $milikRekan->IdKaryawan !== $permintaan->IdKaryawanRekan))) {
            throw new PelanggaranAturanBisnis('JadwalBerubah', 'Jadwal sudah berubah sejak permintaan diajukan. Tolak permintaan ini dan minta diajukan ulang.', 'Umum');
        }

        $this->PastikanBelumLewat($milikPemohon);
        $abaikan = array_values(array_filter([$milikPemohon->Id, $milikRekan?->Id]));
        $this->PastikanMuat($permintaan->IdKaryawanRekan, $milikPemohon, $abaikan);

        if ($milikRekan instanceof JadwalKerja) {
            $this->PastikanBelumLewat($milikRekan);
            $this->PastikanMuat($permintaan->IdKaryawanPemohon, $milikRekan, $abaikan);
        }

        $milikPemohon->forceFill(['IdKaryawan' => $permintaan->IdKaryawanRekan])->save();
        $milikRekan?->forceFill(['IdKaryawan' => $permintaan->IdKaryawanPemohon])->save();
    }

    /** Penerima shift tidak boleh sedang cuti, bentrok di outlet lain, atau bertumpuk dengan shiftnya sendiri. @param list<int> $abaikan */
    private function PastikanMuat(int $idPenerima, JadwalKerja $shift, array $abaikan): void
    {
        $tanggal = $shift->Tanggal->toDateString();
        $nama = Karyawan::query()->whereKey($idPenerima)->value('Nama') ?? 'Karyawan';

        if (isset($this->cuti->AmbilPerTanggal($tanggal, $tanggal, [$idPenerima])[$idPenerima][$tanggal])) {
            throw new PelanggaranAturanBisnis('SedangCuti', "{$nama} sedang cuti atau izin pada {$tanggal}.", 'Umum');
        }

        $tetangga = JadwalKerja::query()
            ->where('IdKaryawan', $idPenerima)
            ->whereNotIn('Id', $abaikan)
            ->whereBetween('Tanggal', [CarbonImmutable::parse($tanggal)->subDay()->toDateString(), CarbonImmutable::parse($tanggal)->addDay()->toDateString()])
            ->get();

        if ($tetangga->contains(fn (JadwalKerja $j): bool => $j->Tanggal->toDateString() === $tanggal && $j->IdOutlet !== $shift->IdOutlet)) {
            throw new PelanggaranAturanBisnis('JadwalBentrok', "{$nama} sudah dijadwalkan di outlet lain pada {$tanggal}.", 'Umum');
        }

        $semua = [
            ...$tetangga->map(fn (JadwalKerja $j): array => ['Tanggal' => $j->Tanggal->toDateString(), 'JamMulai' => $j->JamMulai, 'JamSelesai' => $j->JamSelesai])->values()->all(),
            ['Tanggal' => $tanggal, 'JamMulai' => $shift->JamMulai, 'JamSelesai' => $shift->JamSelesai],
        ];

        if (PemeriksaBentrokJadwal::Cari($semua) !== null) {
            throw new PelanggaranAturanBisnis('JadwalBertumpuk', "Shift itu bertumpuk dengan jadwal {$nama} pada {$tanggal}.", 'Umum');
        }
    }

    private function PastikanBelumLewat(JadwalKerja $jadwal): void
    {
        if ($jadwal->Tanggal->toDateString() < $this->tanggalBisnis->HitungKalender($jadwal->IdOutlet)->toDateString()) {
            throw new PelanggaranAturanBisnis('ShiftSudahLewat', 'Shift yang sudah lewat tidak bisa ditukar.', 'UuidJadwal');
        }
    }

    private function CariTerbuka(string $uuid): PermintaanTukarShift
    {
        $permintaan = PermintaanTukarShift::query()->where('Uuid', $uuid)->lockForUpdate()->first();

        if (! $permintaan instanceof PermintaanTukarShift || ! $permintaan->Status->CekTerbuka()) {
            throw new PelanggaranAturanBisnis('PermintaanTidakAktif', 'Permintaan tidak ditemukan atau sudah selesai.', 'Umum');
        }

        return $permintaan;
    }
}
