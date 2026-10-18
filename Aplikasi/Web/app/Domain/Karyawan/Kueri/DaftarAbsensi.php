<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Karyawan\Enum\StatusKehadiran;
use App\Domain\Karyawan\Layanan\PenilaiKehadiran;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rekap absensi (F-18, `TabelData` mode server): saring tanggal bisnis, karyawan, outlet, dan belum keluar; cari nama
 * karyawan. Per baris: jam masuk/keluar menurut zona outlet, durasi, jadwal hari itu, menit terlambat, pulang cepat,
 * dan lembur (`PenilaiKehadiran` dengan `AturanKehadiran` tenant), status kehadiran, dan ada/tidaknya swafoto.
 */
final class DaftarAbsensi
{
    public const KOLOM_URUT = ['TanggalBisnis', 'MasukPada'];

    public const KOLOM_SARING = ['TanggalBisnis', 'Karyawan', 'Outlet', 'BelumKeluar', 'DiluarJadwal', 'PerluDicek'];

    public const URUT_BAWAAN = '-MasukPada';

    public function __construct(
        private readonly PetaUuidOutlet $outlet,
        private readonly ZonaWaktuOutlet $zona,
        private readonly AturanKehadiranTenant $aturan,
        private readonly PenilaiKehadiran $penilai,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $tanggal = $p->AmbilRentangTanggal('TanggalBisnis');
        $uuidOutlet = $p->AmbilDaftar('Outlet');
        $idOutlet = $uuidOutlet === [] ? null : array_values($this->outlet->AmbilIdDariUuid($uuidOutlet));
        $uuidKaryawan = $p->AmbilDaftar('Karyawan');
        $kueri = Absensi::query()
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($idOutlet !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutlet ?? []))
            ->when($uuidKaryawan !== [], fn (Builder $k) => $k->whereIn('IdKaryawan', Karyawan::query()->whereIn('Uuid', $uuidKaryawan)->select('Id')))
            ->when($tanggal['Dari'] !== null, fn (Builder $k) => $k->where('TanggalBisnis', '>=', $tanggal['Dari']))
            ->when($tanggal['Sampai'] !== null, fn (Builder $k) => $k->where('TanggalBisnis', '<=', $tanggal['Sampai']))
            ->when($p->AmbilBoolean('BelumKeluar') === true, fn (Builder $k) => $k->whereNull('KeluarPada'))
            ->when($p->AmbilBoolean('DiluarJadwal') === true, fn (Builder $k) => $k->where('DiluarJadwal', true))
            ->when($p->AmbilBoolean('PerluDicek') === true, fn (Builder $k) => $k->where('PerluDicek', true))
            ->when($p->cari !== '', fn (Builder $k) => $k->whereIn('IdKaryawan', Karyawan::query()->where('Nama', 'like', PenerapKueriTabel::PolaCari($p->cari))->select('Id')));

        return PenerapKueriTabel::Terapkan($kueri, $p, ['TanggalBisnis' => 'TanggalBisnis', 'MasukPada' => 'MasukPada'], fn (Collection $baris): array => $this->Petakan($baris));
    }

    /**
     * @param  Collection<int, Absensi>  $baris
     * @return list<array<string, mixed>>
     */
    private function Petakan(Collection $baris): array
    {
        $karyawan = Karyawan::query()->whereKey($baris->pluck('IdKaryawan')->unique()->all())->get(['Id', 'Uuid', 'Nama'])->keyBy('Id');
        $idOutlet = array_values(array_unique(array_map('intval', $baris->pluck('IdOutlet')->all())));
        $selisih = $this->zona->AmbilSelisihDetik($idOutlet);
        $namaOutlet = [];

        foreach ($this->outlet->AmbilRingkas($idOutlet) as $o) {
            $namaOutlet[$o['Id']] = $o['Nama'];
        }

        $jadwal = JadwalKerja::query()
            ->whereIn('IdKaryawan', $baris->pluck('IdKaryawan')->unique()->all())
            ->whereIn('Tanggal', $baris->map(fn (Absensi $a): string => $a->TanggalBisnis->toDateString())->unique()->values()->all())
            ->get();
        $aturan = $this->aturan->Ambil();

        return array_values($baris->map(function (Absensi $a) use ($karyawan, $selisih, $namaOutlet, $jadwal, $aturan): array {
            $detik = $selisih[$a->IdOutlet] ?? 25200;
            $masuk = CarbonImmutable::instance($a->MasukPada)->utc()->addSeconds($detik);
            $keluar = $a->KeluarPada === null ? null : CarbonImmutable::instance($a->KeluarPada)->utc()->addSeconds($detik);
            $tanggal = $a->TanggalBisnis->toDateString();
            $j = $jadwal->first(fn (JadwalKerja $j): bool => $j->IdKaryawan === $a->IdKaryawan && $j->Tanggal->toDateString() === $tanggal);
            $nilai = ['TerlambatMenit' => 0, 'PulangCepatMenit' => 0, 'LemburMenit' => 0];

            if ($j !== null) {
                $nilai = $this->penilai->Nilai($tanggal, $j->JamMulai, $j->JamSelesai, $masuk, $keluar, $aturan['ToleransiTerlambatMenit'], $aturan['ToleransiPulangCepatMenit'], $aturan['LemburSetelahMenit']);
            }

            $status = match (true) {
                $keluar === null => StatusKehadiran::BelumKeluar,
                $j === null => StatusKehadiran::TanpaJadwal,
                $nilai['TerlambatMenit'] > 0 => StatusKehadiran::Terlambat,
                $nilai['PulangCepatMenit'] > 0 => StatusKehadiran::PulangCepat,
                $nilai['LemburMenit'] > 0 => StatusKehadiran::Lembur,
                default => StatusKehadiran::TepatWaktu,
            };
            $k = $karyawan->get($a->IdKaryawan);

            return [
                'Uuid' => $a->Uuid,
                'TanggalBisnis' => $tanggal,
                'UuidKaryawan' => $k?->Uuid,
                'NamaKaryawan' => $k?->Nama,
                'NamaOutlet' => $namaOutlet[$a->IdOutlet] ?? null,
                'JamMasuk' => $masuk->format('H:i'),
                'JamKeluar' => $keluar?->format('H:i'),
                'KeluarBeda' => $keluar !== null && $keluar->toDateString() !== $masuk->toDateString(),
                'DurasiMenit' => $keluar === null ? null : (int) floor(($keluar->getTimestamp() - $masuk->getTimestamp()) / 60),
                'Jadwal' => $j === null ? null : "{$j->JamMulai}–{$j->JamSelesai}",
                'TerlambatMenit' => $nilai['TerlambatMenit'],
                'PulangCepatMenit' => $nilai['PulangCepatMenit'],
                'LemburMenit' => $nilai['LemburMenit'],
                'DiluarJadwal' => $a->DiluarJadwal,
                // D-180: lokasi janggal (di luar radius, GPS lemah, titik outlet belum diatur); absen tetap tercatat.
                'PerluDicek' => $a->PerluDicek,
                'CatatanPeriksa' => $a->CatatanPeriksa,
                'Status' => $status->value,
                'LabelStatus' => $status->AmbilLabel(),
                'AdaSwafotoMasuk' => $a->PathSwafotoMasuk !== null,
                'AdaSwafotoKeluar' => $a->PathSwafotoKeluar !== null,
                // v3.34: koreksi pengelola. Tanggal & jam mentah (waktu outlet) mengisi formulir koreksi.
                'Sumber' => $a->Sumber,
                'Dikoreksi' => $a->DikoreksiPada !== null,
                'AlasanKoreksi' => $a->AlasanKoreksi,
                // F-18 bagian 4 (D-37): absen dari HP pribadi — jarak ke outlet & kemiripan wajah (tanpa koordinat).
                'JarakMasukMeter' => $a->JarakMasukMeter,
                'JarakKeluarMeter' => $a->JarakKeluarMeter,
                'KemiripanWajahMasuk' => $a->KemiripanWajahMasuk,
                'KemiripanWajahKeluar' => $a->KemiripanWajahKeluar,
            ];
        })->all());
    }
}
