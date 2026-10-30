<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Data\DataJadwalMingguan;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Layanan\PemeriksaBentrokJadwal;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\ShiftKerja;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Simpan jadwal kerja satu minggu satu outlet (F-18, EMP-02, izin `karyawan.kelola`). Tiap sel (karyawan + tanggal)
 * membawa daftar shift (D-251): daftar itu menggantikan seluruh isi hari tersebut di outlet ini, daftar kosong = hapus.
 * Tanggal harus di minggu tersebut. Karyawan yang sudah dijadwalkan di outlet lain pada hari yang sama ditolak
 * (`JadwalBentrok`), dan shift milik satu karyawan tidak boleh saling tumpang tindih termasuk dengan shift malam hari
 * sebelumnya atau sesudahnya (`JadwalBertumpuk`). `JamSelesai` ≤ `JamMulai` = lewat tengah malam. Audit
 * `jadwal-kerja.simpan`. Galat selalu menunjuk `Sel.{i}.JamMulai` supaya layar bisa menandai selnya.
 */
final class SimpanJadwalMingguan
{
    public const POLA_JAM = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @return int jumlah sel (karyawan + hari) yang berubah
     */
    public function Jalankan(DataJadwalMingguan $data): int
    {
        $awal = $data->senin->toDateString();
        $akhir = $data->senin->addDays(6)->toDateString();
        $uuid = array_values(array_unique(array_map(fn (array $s): string => $s['UuidKaryawan'], $data->sel)));
        $karyawan = Karyawan::query()->whereIn('Uuid', $uuid)->get()->keyBy('Uuid');
        $uuidShift = [];

        foreach ($data->sel as $sel) {
            foreach ($sel['Shift'] as $s) {
                if ($s['UuidShift'] !== null) {
                    $uuidShift[$s['UuidShift']] = true;
                }
            }
        }

        $peta = $uuidShift === [] ? collect() : ShiftKerja::query()->whereIn('Uuid', array_keys($uuidShift))->get()->keyBy('Uuid');

        foreach ($data->sel as $i => $sel) {
            $k = $karyawan->get($sel['UuidKaryawan']);

            if (! $k instanceof Karyawan) {
                throw new PelanggaranAturanBisnis('KaryawanTidakDitemukan', 'Karyawan tidak ditemukan.', "Sel.{$i}.UuidKaryawan");
            }

            if ($sel['Tanggal'] < $awal || $sel['Tanggal'] > $akhir) {
                throw new PelanggaranAturanBisnis('TanggalTidakValid', 'Tanggal jadwal harus di minggu yang dipilih.', "Sel.{$i}.Tanggal");
            }

            if ($sel['Shift'] !== [] && $k->Status !== StatusKaryawan::Aktif) {
                throw new PelanggaranAturanBisnis('KaryawanNonaktif', "{$k->Nama} sudah nonaktif dan tidak bisa dijadwalkan.", "Sel.{$i}.UuidKaryawan");
            }

            $jamMulai = [];

            foreach ($sel['Shift'] as $s) {
                if (! preg_match(self::POLA_JAM, $s['JamMulai']) || ! preg_match(self::POLA_JAM, $s['JamSelesai']) || $s['JamMulai'] === $s['JamSelesai']) {
                    throw new PelanggaranAturanBisnis('JamTidakValid', "Jam kerja {$k->Nama} tidak valid (format JJ:MM, mulai ≠ selesai).", "Sel.{$i}.JamMulai");
                }

                if ($s['UuidShift'] !== null && ! $peta->has($s['UuidShift'])) {
                    throw new PelanggaranAturanBisnis('ShiftTidakDitemukan', 'Shift tidak ditemukan.', "Sel.{$i}.JamMulai");
                }

                if (isset($jamMulai[$s['JamMulai']])) {
                    throw new PelanggaranAturanBisnis('JadwalBertumpuk', "{$k->Nama} punya dua shift yang dimulai {$s['JamMulai']} pada {$sel['Tanggal']}.", "Sel.{$i}.JamMulai");
                }

                $jamMulai[$s['JamMulai']] = true;
            }

            if (PemeriksaBentrokJadwal::Cari(array_map(fn (array $s): array => ['Tanggal' => $sel['Tanggal'], 'JamMulai' => $s['JamMulai'], 'JamSelesai' => $s['JamSelesai']], $sel['Shift'])) !== null) {
                throw new PelanggaranAturanBisnis('JadwalBertumpuk', "Shift {$k->Nama} pada {$sel['Tanggal']} saling tumpang tindih.", "Sel.{$i}.JamMulai");
            }
        }

        return DB::transaction(function () use ($data, $karyawan, $peta, $awal, $akhir): int {
            $berubah = 0;

            foreach ($data->sel as $i => $sel) {
                /** @var Karyawan $k */
                $k = $karyawan->get($sel['UuidKaryawan']);

                if ($this->SimpanSel($k, $sel['Tanggal'], $sel['Shift'], $data->idOutlet, $peta, $i)) {
                    $berubah++;
                }
            }

            if ($berubah > 0) {
                $this->audit->Catat('jadwal-kerja.simpan', null, nilaiBaru: ['IdOutlet' => $data->idOutlet, 'Minggu' => "{$awal}..{$akhir}", 'JumlahBerubah' => $berubah], idPengguna: $data->idPengguna);
            }

            return $berubah;
        }, 3);
    }

    /**
     * @param  list<array{JamMulai: string, JamSelesai: string, UuidShift: string|null}>  $baru
     * @param  \Illuminate\Support\Collection<string, ShiftKerja>  $peta
     */
    private function SimpanSel(Karyawan $k, string $tanggal, array $baru, int $idOutlet, $peta, int $i): bool
    {
        $tetangga = JadwalKerja::query()
            ->where('IdKaryawan', $k->Id)
            ->whereBetween('Tanggal', [CarbonImmutable::parse($tanggal)->subDay()->toDateString(), CarbonImmutable::parse($tanggal)->addDay()->toDateString()])
            ->lockForUpdate()
            ->get();
        $ada = $tetangga->filter(fn (JadwalKerja $j): bool => $j->Tanggal->toDateString() === $tanggal);
        $diOutlet = $ada->where('IdOutlet', $idOutlet)->values();

        if ($baru !== [] && $ada->contains(fn (JadwalKerja $j): bool => $j->IdOutlet !== $idOutlet)) {
            throw new PelanggaranAturanBisnis('JadwalBentrok', "{$k->Nama} sudah dijadwalkan di outlet lain pada {$tanggal}.", "Sel.{$i}.JamMulai");
        }

        if ($baru !== []) {
            $lain = $tetangga
                ->reject(fn (JadwalKerja $j): bool => $j->Tanggal->toDateString() === $tanggal && $j->IdOutlet === $idOutlet)
                ->map(fn (JadwalKerja $j): array => ['Tanggal' => $j->Tanggal->toDateString(), 'JamMulai' => $j->JamMulai, 'JamSelesai' => $j->JamSelesai])
                ->values()
                ->all();
            $semua = [...$lain, ...array_map(fn (array $s): array => ['Tanggal' => $tanggal, 'JamMulai' => $s['JamMulai'], 'JamSelesai' => $s['JamSelesai']], $baru)];

            if (PemeriksaBentrokJadwal::Cari($semua) !== null) {
                throw new PelanggaranAturanBisnis('JadwalBertumpuk', "Shift {$k->Nama} pada {$tanggal} bertumpuk dengan shift hari sebelum atau sesudahnya.", "Sel.{$i}.JamMulai");
            }
        }

        $kunciBaru = [];

        foreach ($baru as $s) {
            $kunciBaru[$s['JamMulai']] = $s;
        }

        $berubah = false;

        foreach ($diOutlet as $j) {
            if (! isset($kunciBaru[$j->JamMulai])) {
                $j->delete();
                $berubah = true;
            }
        }

        foreach ($kunciBaru as $s) {
            $idShift = $s['UuidShift'] === null ? null : $peta->get($s['UuidShift'])?->Id;
            $lama = $diOutlet->firstWhere('JamMulai', $s['JamMulai']);

            if ($lama === null) {
                JadwalKerja::query()->create([
                    'IdKaryawan' => $k->Id,
                    'IdOutlet' => $idOutlet,
                    'IdShiftKerja' => $idShift,
                    'Tanggal' => $tanggal,
                    'JamMulai' => $s['JamMulai'],
                    'JamSelesai' => $s['JamSelesai'],
                ]);
                $berubah = true;

                continue;
            }

            if ($lama->JamSelesai !== $s['JamSelesai'] || $lama->IdShiftKerja !== $idShift) {
                $lama->forceFill(['JamSelesai' => $s['JamSelesai'], 'IdShiftKerja' => $idShift])->save();
                $berubah = true;
            }
        }

        return $berubah;
    }
}
