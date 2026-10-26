<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Layanan\PenilaiKehadiran;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use Carbon\CarbonImmutable;

/**
 * Rekap kehadiran per karyawan untuk satu rentang tanggal bisnis, dasar lembur dan potongan di rekap gaji (F-18 bagian 5,
 * D-44). Hanya jadwal yang dihitung (absen tanpa jadwal tidak menghasilkan lembur/terlambat):
 *
 * - `TerlambatMenit`: jumlah menit terlambat (melewati toleransi) pada hari berjadwal.
 * - `LemburMenit`: jumlah menit lembur (keluar melewati ambang lembur) pada hari yang sudah absen keluar.
 * - `HariTidakMasuk`: hari berjadwal **sebelum hari ini** tanpa satu pun absensi. Hari ini tidak dihitung (shift belum
 *   tentu selesai). Hari yang tercakup cuti/izin berbayar yang berlaku tidak dihitung; cuti tidak berbayar tetap dihitung
 *   (dipotong sesuai tarif). Absensi yang tercatat pada hari cuti dinilai seperti biasa.
 */
final class RekapKehadiranPeriode
{
    public function __construct(
        private readonly AturanKehadiranTenant $aturan,
        private readonly PenilaiKehadiran $penilai,
        private readonly ZonaWaktuOutlet $zona,
        private readonly CutiAktifKaryawan $cuti,
    ) {}

    /**
     * @return array<int, array{TerlambatMenit: int, LemburMenit: int, HariTidakMasuk: int}> Id karyawan → rekap
     */
    public function Hitung(string $dari, string $sampai, CarbonImmutable $hariIni): array
    {
        $aturan = $this->aturan->Ambil();
        // D-180: karyawan yang tidak ikut absensi (manajer, supervisor, pemilik) tidak pernah dianggap terlambat atau alpa.
        $jadwal = JadwalKerja::query()->whereBetween('Tanggal', [$dari, $sampai])
            ->whereIn('IdKaryawan', Karyawan::query()->where('IkutAbsensi', true)->select('Id'))
            ->get();
        $absensi = Absensi::query()->whereBetween('TanggalBisnis', [$dari, $sampai])->orderBy('MasukPada')->get();
        $pertama = [];

        foreach ($absensi as $a) {
            $pertama[$a->IdKaryawan.'|'.$a->TanggalBisnis->toDateString()] ??= $a;
        }

        $cuti = $this->cuti->AmbilPerTanggal($dari, $sampai);
        $selisih = $this->zona->AmbilSelisihDetik(array_values(array_unique(array_map('intval', $absensi->pluck('IdOutlet')->all()))));
        $hasil = [];

        foreach ($jadwal as $j) {
            $tanggal = $j->Tanggal->toDateString();
            $hasil[$j->IdKaryawan] ??= ['TerlambatMenit' => 0, 'LemburMenit' => 0, 'HariTidakMasuk' => 0];
            $a = $pertama[$j->IdKaryawan.'|'.$tanggal] ?? null;

            if ($a === null) {
                if ($tanggal < $hariIni->toDateString() && ($cuti[$j->IdKaryawan][$tanggal]['Berbayar'] ?? false) !== true) {
                    $hasil[$j->IdKaryawan]['HariTidakMasuk']++;
                }

                continue;
            }

            $detik = $selisih[$a->IdOutlet] ?? 25200;
            $nilai = $this->penilai->Nilai(
                $tanggal,
                $j->JamMulai,
                $j->JamSelesai,
                CarbonImmutable::instance($a->MasukPada)->utc()->addSeconds($detik),
                $a->KeluarPada === null ? null : CarbonImmutable::instance($a->KeluarPada)->utc()->addSeconds($detik),
                $aturan['ToleransiTerlambatMenit'],
                $aturan['ToleransiPulangCepatMenit'],
                $aturan['LemburSetelahMenit'],
            );
            $hasil[$j->IdKaryawan]['TerlambatMenit'] += $nilai['TerlambatMenit'];
            $hasil[$j->IdKaryawan]['LemburMenit'] += $nilai['LemburMenit'];
        }

        return $hasil;
    }

    /**
     * Hari masuk per karyawan dalam rentang, dasar upah per hari masuk (D-219): jumlah tanggal bisnis berbeda yang punya
     * absensi, ditambah hari cuti/izin berbayar yang berjadwal dan belum terhitung. Tidak bergantung pada
     * `IkutAbsensi`: karyawan tanpa absensi mendapat 0 dan pengelola mengisinya sendiri selama draf.
     *
     * @return array<int, int> Id karyawan → hari masuk
     */
    public function HitungHariMasuk(string $dari, string $sampai): array
    {
        /** @var array<int, array<string, true>> $tanggal */
        $tanggal = [];

        foreach (Absensi::query()->whereBetween('TanggalBisnis', [$dari, $sampai])->get(['IdKaryawan', 'TanggalBisnis']) as $a) {
            $tanggal[$a->IdKaryawan][$a->TanggalBisnis->toDateString()] = true;
        }

        $jadwal = [];

        foreach (JadwalKerja::query()->whereBetween('Tanggal', [$dari, $sampai])->get(['IdKaryawan', 'Tanggal']) as $j) {
            $jadwal[$j->IdKaryawan][$j->Tanggal->toDateString()] = true;
        }

        foreach ($this->cuti->AmbilPerTanggal($dari, $sampai) as $idKaryawan => $hari) {
            foreach ($hari as $tgl => $c) {
                if ($c['Berbayar'] && isset($jadwal[$idKaryawan][$tgl])) {
                    $tanggal[$idKaryawan][$tgl] = true;
                }
            }
        }

        return array_map(static fn (array $hari): int => count($hari), $tanggal);
    }
}
