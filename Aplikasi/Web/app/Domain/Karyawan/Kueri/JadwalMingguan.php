<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Enum\StatusTukarShift;
use App\Domain\Karyawan\Layanan\PenghitungKekuranganStaf;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\KebutuhanStaf;
use App\Domain\Karyawan\Model\PermintaanTukarShift;
use App\Domain\Karyawan\Model\PolaJadwalKerja;
use App\Domain\Karyawan\Model\ShiftKerja;
use Carbon\CarbonImmutable;

/**
 * Isian jadwal mingguan satu outlet (F-18): baris = karyawan aktif berpangkalan di outlet ini atau yang punya jadwal di
 * outlet ini minggu tersebut; kolom = Senin–Minggu. Sel jadwal di outlet lain ditandai (tidak bisa diubah dari
 * sini). `Cuti` memuat hari cuti/izin yang berlaku pada minggu itu (tanggal → jenis & berbayar) untuk tampil di sel jadwal.
 * Sejak D-251 satu sel memuat daftar shift (boleh lebih dari satu per hari) dan jawaban juga membawa daftar shift bernama,
 * pola berulang tiap karyawan, kebutuhan staf outlet, kekurangan staf minggu itu, dan permintaan tukar shift yang
 * menunggu keputusan pengelola.
 */
final class JadwalMingguan
{
    public function __construct(private readonly CutiAktifKaryawan $cuti) {}

    /**
     * @return array{
     *     Hari: list<string>,
     *     Baris: list<array{Uuid: string, Nama: string, Jabatan: string|null, Aktif: bool, Jadwal: array<string, list<array{Uuid: string, JamMulai: string, JamSelesai: string, OutletLain: bool, UuidShift: string|null}>>, Cuti: array<string, array{Jenis: string, Berbayar: bool}>, Pola: list<array{HariKe: int, JamMulai: string, JamSelesai: string, UuidShift: string|null}>}>,
     *     ShiftKerja: list<array{Uuid: string, Nama: string, JamMulai: string, JamSelesai: string, Aktif: bool, Urutan: int}>,
     *     Kebutuhan: list<array{HariKe: int, JamMulai: string, JamSelesai: string, Minimal: int}>,
     *     Kekurangan: list<array{Tanggal: string, JamMulai: string, JamSelesai: string, Minimal: int, Terjadwal: int, Kurang: int}>,
     *     TukarShift: list<array{Uuid: string, Pemohon: string, Rekan: string, Tanggal: string, JamMulai: string, JamSelesai: string, TukarDengan: string|null, Alasan: string}>
     * }
     */
    public function Ambil(int $idOutlet, CarbonImmutable $senin): array
    {
        $hari = [];

        for ($i = 0; $i < 7; $i++) {
            $hari[] = $senin->addDays($i)->toDateString();
        }

        // Sehari sebelum Senin ikut dibaca: shift malam Minggu lalu menutupi dini hari Senin.
        $jadwalLuas = JadwalKerja::query()->whereBetween('Tanggal', [$senin->subDay()->toDateString(), $hari[6]])->orderBy('JamMulai')->get();
        $jadwal = $jadwalLuas->filter(fn (JadwalKerja $j): bool => $j->Tanggal->toDateString() >= $hari[0]);
        $idDiOutlet = $jadwal->where('IdOutlet', $idOutlet)->pluck('IdKaryawan')->unique()->all();
        $pola = PolaJadwalKerja::query()->where('IdOutlet', $idOutlet)->orderBy('HariKe')->orderBy('JamMulai')->get()->groupBy('IdKaryawan');
        $karyawan = Karyawan::query()
            ->where(fn ($k) => $k->where(fn ($q) => $q->where('IdOutlet', $idOutlet)->where('Status', StatusKaryawan::Aktif->value))->orWhereIn('Id', $idDiOutlet))
            ->orderBy('Nama')
            ->get();
        $idKaryawan = array_values(array_map('intval', $karyawan->pluck('Id')->all()));
        $cuti = $this->cuti->AmbilPerTanggal($hari[0], $hari[6], $idKaryawan);
        $shift = ShiftKerja::query()->orderBy('Urutan')->orderBy('JamMulai')->orderBy('Nama')->get();
        $uuidShift = $shift->pluck('Uuid', 'Id');
        $baris = [];

        foreach ($karyawan as $k) {
            $sel = [];

            foreach ($hari as $tanggal) {
                $sel[$tanggal] = $jadwal
                    ->filter(fn (JadwalKerja $j): bool => $j->IdKaryawan === $k->Id && $j->Tanggal->toDateString() === $tanggal)
                    ->map(fn (JadwalKerja $j): array => [
                        'Uuid' => $j->Uuid,
                        'JamMulai' => $j->JamMulai,
                        'JamSelesai' => $j->JamSelesai,
                        'OutletLain' => $j->IdOutlet !== $idOutlet,
                        'UuidShift' => $j->IdShiftKerja === null ? null : ($uuidShift[$j->IdShiftKerja] ?? null),
                    ])
                    ->values()
                    ->all();
            }

            $baris[] = [
                'Uuid' => $k->Uuid,
                'Nama' => $k->Nama,
                'Jabatan' => $k->Jabatan,
                'Aktif' => $k->Status === StatusKaryawan::Aktif,
                'Jadwal' => $sel,
                'Cuti' => $cuti[$k->Id] ?? [],
                'Pola' => $pola->get($k->Id, collect())->map(fn (PolaJadwalKerja $p): array => [
                    'HariKe' => $p->HariKe,
                    'JamMulai' => $p->JamMulai,
                    'JamSelesai' => $p->JamSelesai,
                    'UuidShift' => $p->IdShiftKerja === null ? null : ($uuidShift[$p->IdShiftKerja] ?? null),
                ])->values()->all(),
            ];
        }

        $kebutuhan = KebutuhanStaf::query()->where('IdOutlet', $idOutlet)->orderBy('HariKe')->orderBy('JamMulai')->get()
            ->map(fn (KebutuhanStaf $b): array => ['HariKe' => $b->HariKe, 'JamMulai' => $b->JamMulai, 'JamSelesai' => $b->JamSelesai, 'Minimal' => $b->Minimal])
            ->values()
            ->all();

        return [
            'Hari' => $hari,
            'Baris' => $baris,
            'ShiftKerja' => $shift->map(fn (ShiftKerja $s): array => ['Uuid' => $s->Uuid, 'Nama' => $s->Nama, 'JamMulai' => $s->JamMulai, 'JamSelesai' => $s->JamSelesai, 'Aktif' => $s->Aktif, 'Urutan' => $s->Urutan])->values()->all(),
            'Kebutuhan' => $kebutuhan,
            'Kekurangan' => $kebutuhan === [] ? [] : $this->HitungKekurangan($kebutuhan, $jadwalLuas->where('IdOutlet', $idOutlet), $hari, $senin, $idKaryawan),
            'TukarShift' => $this->AmbilTukarShift($idOutlet),
        ];
    }

    /**
     * @param  list<array{HariKe: int, JamMulai: string, JamSelesai: string, Minimal: int}>  $kebutuhan
     * @param  \Illuminate\Support\Collection<int, JadwalKerja>  $jadwalOutlet
     * @param  list<string>  $hari
     * @param  list<int>  $idKaryawan
     * @return list<array{Tanggal: string, JamMulai: string, JamSelesai: string, Minimal: int, Terjadwal: int, Kurang: int}>
     */
    private function HitungKekurangan(array $kebutuhan, $jadwalOutlet, array $hari, CarbonImmutable $senin, array $idKaryawan): array
    {
        $cuti = $this->cuti->AmbilPerTanggal($senin->subDay()->toDateString(), $hari[6], $idKaryawan);
        $perTanggal = [];

        foreach ($jadwalOutlet as $j) {
            $tanggal = $j->Tanggal->toDateString();

            if (isset($cuti[$j->IdKaryawan][$tanggal])) {
                continue;
            }

            $perTanggal[$tanggal][] = ['IdKaryawan' => $j->IdKaryawan, 'JamMulai' => $j->JamMulai, 'JamSelesai' => $j->JamSelesai];
        }

        return PenghitungKekuranganStaf::Hitung($kebutuhan, $perTanggal, $hari);
    }

    /**
     * Permintaan tukar shift yang sudah disetujui rekan dan menunggu pengelola, untuk shift di outlet ini.
     *
     * @return list<array{Uuid: string, Pemohon: string, Rekan: string, Tanggal: string, JamMulai: string, JamSelesai: string, TukarDengan: string|null, Alasan: string}>
     */
    private function AmbilTukarShift(int $idOutlet): array
    {
        $permintaan = PermintaanTukarShift::query()->where('Status', StatusTukarShift::MenungguPengelola->value)->orderBy('Id')->get();

        if ($permintaan->isEmpty()) {
            return [];
        }

        $jadwal = JadwalKerja::query()->whereKey($permintaan->pluck('IdJadwalPemohon')->merge($permintaan->pluck('IdJadwalRekan')->filter())->all())->get()->keyBy('Id');
        $nama = Karyawan::query()->whereKey($permintaan->pluck('IdKaryawanPemohon')->merge($permintaan->pluck('IdKaryawanRekan'))->unique()->all())->pluck('Nama', 'Id');
        $hasil = [];

        foreach ($permintaan as $p) {
            $j = $jadwal->get($p->IdJadwalPemohon);

            if (! $j instanceof JadwalKerja || $j->IdOutlet !== $idOutlet) {
                continue;
            }

            $balasan = $p->IdJadwalRekan === null ? null : $jadwal->get($p->IdJadwalRekan);
            $hasil[] = [
                'Uuid' => $p->Uuid,
                'Pemohon' => (string) ($nama[$p->IdKaryawanPemohon] ?? ''),
                'Rekan' => (string) ($nama[$p->IdKaryawanRekan] ?? ''),
                'Tanggal' => $j->Tanggal->toDateString(),
                'JamMulai' => $j->JamMulai,
                'JamSelesai' => $j->JamSelesai,
                'TukarDengan' => $balasan instanceof JadwalKerja ? $balasan->Tanggal->toDateString().' '.$balasan->JamMulai.'–'.$balasan->JamSelesai : null,
                'Alasan' => $p->Alasan,
            ];
        }

        return $hasil;
    }
}
