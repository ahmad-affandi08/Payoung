<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Enum\StatusTukarShift;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\PermintaanTukarShift;
use App\Domain\Organisasi\Kueri\LokasiAbsensiOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Carbon\CarbonImmutable;

/**
 * Jadwal pribadi karyawan di halaman Absen (F-18, D-251): shift 14 hari ke depan, rekan yang bisa diajak bertukar (beserta
 * shiftnya di outlet yang sama), permintaan tukar yang diajukan karyawan ini, dan yang menunggu tanggapannya. Hanya nama
 * dan jam, tanpa data pribadi rekan.
 */
final class JadwalSaya
{
    public const HARI_KE_DEPAN = 14;

    public function __construct(
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly LokasiAbsensiOutlet $lokasi,
    ) {}

    /**
     * @return array{
     *     Shift: list<array{Uuid: string, Tanggal: string, JamMulai: string, JamSelesai: string, NamaOutlet: string|null}>,
     *     Rekan: list<array{Uuid: string, Nama: string, Shift: list<array{Uuid: string, Tanggal: string, JamMulai: string, JamSelesai: string}>}>,
     *     Diajukan: list<array{Uuid: string, Status: string, Label: string, Tanggal: string, JamMulai: string, JamSelesai: string, Rekan: string, TukarDengan: string|null, AlasanKeputusan: string|null}>,
     *     Masuk: list<array{Uuid: string, Pemohon: string, Tanggal: string, JamMulai: string, JamSelesai: string, TukarDengan: string|null, Alasan: string}>
     * }
     */
    public function Ambil(Karyawan $karyawan): array
    {
        $idOutletAcuan = $karyawan->IdOutlet ?? 0;
        $mulai = $this->tanggalBisnis->HitungKalender($idOutletAcuan)->toDateString();
        $sampai = CarbonImmutable::parse($mulai, 'UTC')->addDays(self::HARI_KE_DEPAN - 1)->toDateString();
        $milik = JadwalKerja::query()->where('IdKaryawan', $karyawan->Id)->whereBetween('Tanggal', [$mulai, $sampai])->orderBy('Tanggal')->orderBy('JamMulai')->get();
        $idOutlet = $milik->pluck('IdOutlet')->unique()->values()->all();
        $rekan = $idOutlet === [] ? collect() : Karyawan::query()
            ->where('Id', '!=', $karyawan->Id)
            ->where('Status', StatusKaryawan::Aktif->value)
            ->whereIn('Id', JadwalKerja::query()->whereIn('IdOutlet', $idOutlet)->whereBetween('Tanggal', [$mulai, $sampai])->select('IdKaryawan'))
            ->orderBy('Nama')
            ->get();
        $jadwalRekan = $rekan->isEmpty() ? collect() : JadwalKerja::query()
            ->whereIn('IdKaryawan', $rekan->pluck('Id')->all())
            ->whereIn('IdOutlet', $idOutlet)
            ->whereBetween('Tanggal', [$mulai, $sampai])
            ->orderBy('Tanggal')
            ->orderBy('JamMulai')
            ->get()
            ->groupBy('IdKaryawan');
        $permintaan = PermintaanTukarShift::query()
            ->where(fn ($q) => $q->where('IdKaryawanPemohon', $karyawan->Id)->orWhere(fn ($x) => $x->where('IdKaryawanRekan', $karyawan->Id)->where('Status', StatusTukarShift::MenungguRekan->value)))
            ->where(fn ($q) => $q->whereIn('Status', StatusTukarShift::NilaiTerbuka())->orWhere('DiputuskanPada', '>=', now()->subDays(7)))
            ->orderByDesc('Id')
            ->limit(30)
            ->get();
        $jadwalPermintaan = JadwalKerja::query()->whereKey($permintaan->pluck('IdJadwalPemohon')->merge($permintaan->pluck('IdJadwalRekan')->filter())->all())->get()->keyBy('Id');
        $nama = Karyawan::query()->whereKey($permintaan->pluck('IdKaryawanPemohon')->merge($permintaan->pluck('IdKaryawanRekan'))->unique()->all())->pluck('Nama', 'Id');

        $diajukan = [];
        $masuk = [];

        foreach ($permintaan as $p) {
            $j = $jadwalPermintaan->get($p->IdJadwalPemohon);

            if (! $j instanceof JadwalKerja) {
                continue;
            }

            $balasan = $p->IdJadwalRekan === null ? null : $jadwalPermintaan->get($p->IdJadwalRekan);
            $tukarDengan = $balasan instanceof JadwalKerja ? $balasan->Tanggal->toDateString().' '.$balasan->JamMulai.'–'.$balasan->JamSelesai : null;

            if ($p->IdKaryawanPemohon === $karyawan->Id) {
                $diajukan[] = [
                    'Uuid' => $p->Uuid,
                    'Status' => $p->Status->value,
                    'Label' => $p->Status->AmbilLabel(),
                    'Tanggal' => $j->Tanggal->toDateString(),
                    'JamMulai' => $j->JamMulai,
                    'JamSelesai' => $j->JamSelesai,
                    'Rekan' => (string) ($nama[$p->IdKaryawanRekan] ?? ''),
                    'TukarDengan' => $tukarDengan,
                    'AlasanKeputusan' => $p->AlasanKeputusan,
                ];
            } else {
                $masuk[] = [
                    'Uuid' => $p->Uuid,
                    'Pemohon' => (string) ($nama[$p->IdKaryawanPemohon] ?? ''),
                    'Tanggal' => $j->Tanggal->toDateString(),
                    'JamMulai' => $j->JamMulai,
                    'JamSelesai' => $j->JamSelesai,
                    'TukarDengan' => $tukarDengan,
                    'Alasan' => $p->Alasan,
                ];
            }
        }

        return [
            'Shift' => array_values($milik->map(fn (JadwalKerja $j): array => [
                'Uuid' => $j->Uuid,
                'Tanggal' => $j->Tanggal->toDateString(),
                'JamMulai' => $j->JamMulai,
                'JamSelesai' => $j->JamSelesai,
                'NamaOutlet' => $this->lokasi->AmbilNama($j->IdOutlet),
            ])->all()),
            'Rekan' => array_values($rekan->map(fn (Karyawan $r): array => [
                'Uuid' => $r->Uuid,
                'Nama' => $r->Nama,
                'Shift' => array_values(($jadwalRekan->get($r->Id) ?? collect())->map(fn (JadwalKerja $j): array => [
                    'Uuid' => $j->Uuid,
                    'Tanggal' => $j->Tanggal->toDateString(),
                    'JamMulai' => $j->JamMulai,
                    'JamSelesai' => $j->JamSelesai,
                    ])->all()),
            ])->all()),
            'Diajukan' => $diajukan,
            'Masuk' => $masuk,
        ];
    }
}
