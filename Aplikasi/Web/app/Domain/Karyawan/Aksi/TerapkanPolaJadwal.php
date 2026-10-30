<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Karyawan\Data\DataJadwalMingguan;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Kueri\CutiAktifKaryawan;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\PolaJadwalKerja;
use App\Domain\Karyawan\Model\ShiftKerja;
use Carbon\CarbonImmutable;

/**
 * Terapkan pola jadwal berulang ke satu minggu di satu outlet (F-18, D-251). Hanya karyawan aktif, hari yang masih
 * kosong, dan hari tanpa cuti/izin berlaku yang diisi. Hari yang bertumpuk atau bentrok dengan outlet lain dilewati.
 * Seluruh minggu diproses dalam satu transaksi (`SimpanJadwalMingguan::JalankanLonggar`).
 * `$hanyaMingguKosong` (perintah terjadwal): karyawan yang minggu itu sudah punya jadwal apa pun dibiarkan, supaya hari
 * yang sengaja dikosongkan pengelola tidak terisi lagi.
 */
final class TerapkanPolaJadwal
{
    public function __construct(
        private readonly SimpanJadwalMingguan $simpan,
        private readonly CutiAktifKaryawan $cuti,
    ) {}

    /** @return int jumlah hari (karyawan + tanggal) yang terisi */
    public function Jalankan(int $idOutlet, CarbonImmutable $senin, ?int $idPengguna, bool $hanyaMingguKosong = false): int
    {
        $pola = PolaJadwalKerja::query()->where('IdOutlet', $idOutlet)->orderBy('HariKe')->get()->groupBy('IdKaryawan');

        if ($pola->isEmpty()) {
            return 0;
        }

        $aktif = Karyawan::query()->whereIn('Id', $pola->keys()->all())->where('Status', StatusKaryawan::Aktif->value)->get();
        $awal = $senin->toDateString();
        $akhir = $senin->addDays(6)->toDateString();
        $ada = JadwalKerja::query()->whereIn('IdKaryawan', $aktif->pluck('Id')->all())->whereBetween('Tanggal', [$awal, $akhir])->get(['IdKaryawan', 'Tanggal']);
        $cuti = $this->cuti->AmbilPerTanggal($awal, $akhir, array_values(array_map('intval', $aktif->pluck('Id')->all())));
        $uuidShift = ShiftKerja::query()->pluck('Uuid', 'Id');
        $sel = [];

        foreach ($aktif as $k) {
            if ($hanyaMingguKosong && $ada->contains('IdKaryawan', $k->Id)) {
                continue;
            }

            $hariAda = $ada->where('IdKaryawan', $k->Id)->map(fn (JadwalKerja $j): string => $j->Tanggal->toDateString())->flip();

            foreach ($pola->get($k->Id, collect())->groupBy('HariKe') as $hariKe => $barisHari) {
                $tanggal = $senin->addDays(((int) $hariKe) - 1)->toDateString();

                if ($hariAda->has($tanggal) || isset($cuti[$k->Id][$tanggal])) {
                    continue;
                }

                $sel[] = [
                    'UuidKaryawan' => $k->Uuid,
                    'Tanggal' => $tanggal,
                    'Shift' => $barisHari->map(fn (PolaJadwalKerja $p): array => ['JamMulai' => $p->JamMulai, 'JamSelesai' => $p->JamSelesai, 'UuidShift' => $p->IdShiftKerja === null ? null : ($uuidShift[$p->IdShiftKerja] ?? null)])->values()->all(),
                ];
            }
        }

        return $this->simpan->JalankanLonggar(new DataJadwalMingguan($idOutlet, $senin, $sel, $idPengguna))['Berubah'];
    }
}
