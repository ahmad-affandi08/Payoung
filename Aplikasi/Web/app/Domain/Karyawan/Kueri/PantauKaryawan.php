<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * OWN-10 (Aplikasi Pemilik): pantau karyawan hari ini dan bulan berjalan dalam satu jawaban.
 *
 * - `Kehadiran`: absensi tanggal bisnis hari ini (memakai pemetaan rekap absensi: jam, jadwal, terlambat, sumber) dan
 *   karyawan yang dijadwalkan hari ini tetapi belum absen masuk.
 * - `Komisi`: komisi bersih per karyawan bulan berjalan, terbesar dulu.
 * - `Target`: progres target penjualan bulan berjalan (outlet & karyawan).
 *
 * Tanggal hari ini = tanggal zona waktu tenant. Pengguna terbatas outlet hanya melihat outletnya.
 */
final class PantauKaryawan
{
    public function __construct(
        private readonly DaftarAbsensi $absensi,
        private readonly LaporanKomisi $komisi,
        private readonly ProgresTargetPenjualan $target,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PetaUuidOutlet $outlet,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array<string, mixed>
     */
    public function Ambil(?array $idOutletBoleh, CarbonImmutable $sekarang): array
    {
        $hariIni = $this->tanggalBisnis->Hitung(null, $sekarang)->toDateString();
        $bulan = substr($hariIni, 0, 7);
        $hadir = $this->absensi->Ambil(DataPermintaanTabel::Dari(
            ['saring' => ['TanggalBisnis' => "{$hariIni}..{$hariIni}"], 'perHalaman' => 100],
            DaftarAbsensi::KOLOM_URUT,
            'MasukPada',
            DaftarAbsensi::KOLOM_SARING,
        ), $idOutletBoleh)['Data'];

        $sudahAbsen = Absensi::query()->where('TanggalBisnis', $hariIni)->pluck('IdKaryawan')->all();
        $belum = JadwalKerja::query()
            ->where('Tanggal', $hariIni)
            ->whereNotIn('IdKaryawan', $sudahAbsen)
            ->whereIn('IdKaryawan', Karyawan::query()->wajibAbsen()->select('Id'))
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderBy('JamMulai')
            ->get();
        $namaKaryawan = Karyawan::query()->whereKey($belum->pluck('IdKaryawan')->all())->pluck('Nama', 'Id');
        $namaOutlet = [];

        foreach ($this->outlet->AmbilRingkas(array_values(array_unique(array_map('intval', $belum->pluck('IdOutlet')->all())))) as $o) {
            $namaOutlet[$o['Id']] = $o['Nama'];
        }

        // D-251: karyawan dengan beberapa shift hari ini tampil sekali, jam shiftnya digabung.
        $belumMasuk = array_values($belum->groupBy(fn (JadwalKerja $j): string => $j->IdKaryawan.'|'.$j->IdOutlet)->map(function ($shift): array {
            /** @var JadwalKerja $pertama */
            $pertama = $shift->first();

            return [
                'NamaKaryawan' => (string) ($namaKaryawan[$pertama->IdKaryawan] ?? ''),
                'NamaOutlet' => $namaOutlet[$pertama->IdOutlet] ?? null,
                'Jadwal' => $shift->map(fn (JadwalKerja $j): string => "{$j->JamMulai}–{$j->JamSelesai}")->implode(', '),
            ];
        })->all());

        $komisi = [];
        $bersih = $this->komisi->AmbilBersihPerKaryawan("{$bulan}-01", CarbonImmutable::parse("{$bulan}-01")->endOfMonth()->toDateString());
        $nama = Karyawan::query()->whereKey(array_keys($bersih))->pluck('Nama', 'Id');

        foreach ($bersih as $idKaryawan => $jumlah) {
            $nilai = Uang::Dari($jumlah);

            if (! $nilai->BernilaiNol() && ! $nilai->BernilaiNegatif()) {
                $komisi[] = ['NamaKaryawan' => (string) ($nama[$idKaryawan] ?? ''), 'Komisi' => $jumlah];
            }
        }

        usort($komisi, fn (array $a, array $b): int => Uang::Dari($b['Komisi'])->Bandingkan(Uang::Dari($a['Komisi'])));

        return [
            'Tanggal' => $hariIni,
            'Periode' => $bulan,
            'Ringkasan' => [
                'Hadir' => count($hadir),
                'SedangBekerja' => count(array_filter($hadir, fn (array $a): bool => $a['JamKeluar'] === null)),
                'Terlambat' => count(array_filter($hadir, fn (array $a): bool => $a['TerlambatMenit'] > 0)),
                'BelumMasuk' => count($belumMasuk),
            ],
            'Kehadiran' => array_values(array_map(fn (array $a): array => [
                'NamaKaryawan' => $a['NamaKaryawan'],
                'NamaOutlet' => $a['NamaOutlet'],
                'Jadwal' => $a['Jadwal'],
                'JamMasuk' => $a['JamMasuk'],
                'JamKeluar' => $a['JamKeluar'],
                'TerlambatMenit' => $a['TerlambatMenit'],
                'Sumber' => $a['Sumber'],
            ], $hadir)),
            'BelumMasuk' => $belumMasuk,
            'Komisi' => $komisi,
            'Target' => array_values(array_map(fn (array $t): array => [
                'LabelCakupan' => $t['LabelCakupan'],
                'NamaSasaran' => $t['NamaSasaran'],
                'Nilai' => $t['Nilai'],
                'Realisasi' => $t['Realisasi'],
                'Persen' => $t['Persen'],
                'Proyeksi' => $t['Proyeksi'],
            ], $this->target->Ambil($bulan, $idOutletBoleh))),
        ];
    }
}
