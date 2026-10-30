<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Data\DataJadwalMingguan;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\ShiftKerja;
use Carbon\CarbonImmutable;

/**
 * Isi massal jadwal (F-18, D-251, izin `karyawan.kelola`): satu shift bernama diterapkan ke banyak karyawan di banyak
 * hari sekaligus pada satu minggu. Hanya hari yang masih kosong yang diisi. Hari yang sudah punya jadwal (di outlet mana
 * pun) atau yang bertumpuk dengan shift hari sebelum/sesudahnya dilewati dan dihitung, bukan membatalkan seluruh isian.
 * Seluruh isian diproses dalam satu transaksi (`SimpanJadwalMingguan::JalankanLonggar`).
 */
final class IsiMassalJadwal
{
    public function __construct(private readonly SimpanJadwalMingguan $simpan) {}

    /**
     * @param  list<string>  $uuidKaryawan
     * @param  list<string>  $tanggal  `YYYY-MM-DD` di minggu yang dimaksud
     * @return array{Terisi: int, Dilewati: int}
     */
    public function Jalankan(int $idOutlet, CarbonImmutable $senin, string $uuidShift, array $uuidKaryawan, array $tanggal, ?int $idPengguna): array
    {
        $shift = ShiftKerja::query()->where('Uuid', $uuidShift)->first();

        if (! $shift instanceof ShiftKerja || ! $shift->Aktif) {
            throw new PelanggaranAturanBisnis('ShiftTidakDitemukan', 'Shift tidak ditemukan atau sudah dinonaktifkan.', 'UuidShift');
        }

        $karyawan = Karyawan::query()->whereIn('Uuid', $uuidKaryawan)->where('Status', StatusKaryawan::Aktif->value)->get();
        $tanggal = array_values(array_unique($tanggal));
        $ada = JadwalKerja::query()
            ->whereIn('IdKaryawan', $karyawan->pluck('Id')->all())
            ->whereIn('Tanggal', $tanggal)
            ->get(['IdKaryawan', 'Tanggal'])
            ->map(fn (JadwalKerja $j): string => $j->IdKaryawan.'|'.$j->Tanggal->toDateString())
            ->flip();
        $sel = [];
        $dilewati = 0;

        foreach ($karyawan as $k) {
            foreach ($tanggal as $t) {
                if ($ada->has($k->Id.'|'.$t)) {
                    $dilewati++;

                    continue;
                }

                $sel[] = ['UuidKaryawan' => $k->Uuid, 'Tanggal' => $t, 'Shift' => [['JamMulai' => $shift->JamMulai, 'JamSelesai' => $shift->JamSelesai, 'UuidShift' => $shift->Uuid]]];
            }
        }

        $hasil = $this->simpan->JalankanLonggar(new DataJadwalMingguan($idOutlet, $senin, $sel, $idPengguna));

        return ['Terisi' => $hasil['Berubah'], 'Dilewati' => $dilewati + $hasil['Dilewati']];
    }
}
