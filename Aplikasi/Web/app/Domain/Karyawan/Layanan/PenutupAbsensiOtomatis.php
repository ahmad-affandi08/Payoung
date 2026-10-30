<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Karyawan\Kueri\AturanKehadiranTenant;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menutup absensi yang lupa absen keluar (F-18, D-250) supaya jam kerjanya tidak menyambung ke hari berikutnya.
 *
 * - **Batas** = jam selesai shift yang dimaksud absen itu pada tanggal masuk (shift yang melewati tengah malam berakhir
 *   keesokan harinya; bila ada beberapa shift sehari dipilih yang paling dekat dengan jam masuk, `PemilihShiftAbsensi`).
 *   Tanpa jadwal, atau jadwalnya sudah berakhir sebelum karyawan masuk, batasnya akhir hari kalender outlet (23.59).
 * - **Ditutup** setelah `batas + TutupOtomatisSetelahMenit` (ruang bagi yang memang lembur). Jam keluar yang dicatat adalah
 *   **batas itu sendiri**, bukan jam sistem menutupnya, jadi lama kerja tidak membengkak. Lembur yang tidak diabsen tidak
 *   dihitung.
 * - Absensi ditandai `KeluarOtomatis` dan `PerluDicek` dengan catatan, supaya pengelola bisa mengoreksi jamnya
 *   (`absensi.koreksi`) dan tampil di Kotak Tindakan. Audit `absensi.keluar-otomatis`.
 *
 * Dipanggil jadwal (`karyawan:tutup-absensi-terbuka`) dan di awal absen masuk/halaman absen karyawan, jadi karyawan
 * tidak pernah tertahan oleh catatan kemarin. Saat karyawan absen masuk lagi, shift pertamanya yang lupa keluar langsung
 * ditutup begitu jam selesainya lewat dan ia punya shift berikutnya hari itu (shift terpisah), tanpa menunggu masa tunggu. Dibaca dan ditulis dalam konteks tenant. Aman diulang.
 */
final class PenutupAbsensiOtomatis
{
    public const BATAS_PER_PUTARAN = 500;

    public function __construct(
        private readonly AturanKehadiranTenant $aturan,
        private readonly ZonaWaktuOutlet $zona,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * Jam keluar otomatis (UTC) satu absensi terbuka: batas shift menurut jadwal, atau akhir hari kalender outlet.
     * Murni: semua masukan sudah di tangan pemanggil. `$jadwal` = jadwal karyawan pada tanggal masuk (boleh kosong).
     *
     * @param  Collection<int, JadwalKerja>  $jadwal
     */
    public static function HitungBatasUtc(CarbonImmutable $masukUtc, int $selisihDetik, Collection $jadwal): CarbonImmutable
    {
        return self::PilihShiftDanBatas($masukUtc, $selisihDetik, $jadwal)['Batas'];
    }

    /**
     * @param  Collection<int, JadwalKerja>  $jadwal
     * @return array{Shift: JadwalKerja|null, Batas: CarbonImmutable} `Shift` kosong bila batasnya akhir hari
     */
    public static function PilihShiftDanBatas(CarbonImmutable $masukUtc, int $selisihDetik, Collection $jadwal): array
    {
        $masukLokal = $masukUtc->utc()->addSeconds($selisihDetik);
        $shift = PemilihShiftAbsensi::Pilih($jadwal, $masukLokal);
        $batasLokal = null;

        if ($shift !== null) {
            $selesai = PenilaiKehadiran::AmbilBatasShift($masukLokal->toDateString(), $shift->JamMulai, $shift->JamSelesai)['Selesai'];

            if ($selesai->greaterThan($masukLokal)) {
                $batasLokal = $selesai;
            } else {
                $shift = null;
            }
        }

        $batasLokal ??= $masukLokal->setTime(23, 59, 0);

        return ['Shift' => $shift, 'Batas' => $batasLokal->subSeconds($selisihDetik)];
    }

    /**
     * @param  int|null  $idKaryawan  null = semua karyawan tenant (jadwal berkala), selain itu hanya karyawan itu
     * @param  bool  $untukAbsenMasuk  karyawan sedang absen masuk: shift terdahulu yang jam selesainya lewat ditutup walau masa
     *                                  tunggu belum habis bila ia punya shift berikutnya hari itu
     * @return int jumlah absensi yang ditutup
     */
    public function TutupYangLewat(CarbonImmutable $sekarang, ?int $idKaryawan = null, bool $untukAbsenMasuk = false): int
    {
        $aturan = $this->aturan->Ambil();

        if (! $aturan['TutupOtomatisAktif']) {
            return 0;
        }

        $terbuka = Absensi::query()
            ->whereNull('KeluarPada')
            // Saat karyawan absen masuk, catatan lama ikut diperiksa walau belum melewati masa tunggu (shift berikutnya dimulai).
            ->where('MasukPada', '<', $untukAbsenMasuk ? $sekarang->utc() : $sekarang->utc()->subMinutes($aturan['TutupOtomatisSetelahMenit']))
            ->when($idKaryawan !== null, fn ($kueri) => $kueri->where('IdKaryawan', $idKaryawan))
            ->orderBy('Id')
            ->limit(self::BATAS_PER_PUTARAN)
            ->get();

        if ($terbuka->isEmpty()) {
            return 0;
        }

        $selisih = $this->zona->AmbilSelisihDetik(array_values(array_unique(array_map('intval', $terbuka->pluck('IdOutlet')->all()))));
        $jadwal = JadwalKerja::query()
            ->whereIn('IdKaryawan', $terbuka->pluck('IdKaryawan')->unique()->all())
            ->whereIn('Tanggal', $terbuka->map(fn (Absensi $a): string => $this->TanggalMasukLokal($a, $selisih)->toDateString())->unique()->values()->all())
            ->get();
        $jumlah = 0;

        foreach ($terbuka as $absensi) {
            $detik = $selisih[$absensi->IdOutlet] ?? 25200;
            $tanggal = $this->TanggalMasukLokal($absensi, $selisih)->toDateString();
            $jadwalHari = $jadwal->filter(fn (JadwalKerja $j): bool => $j->IdKaryawan === $absensi->IdKaryawan && $j->Tanggal->toDateString() === $tanggal)->values();
            ['Shift' => $shift, 'Batas' => $batas] = self::PilihShiftDanBatas(CarbonImmutable::instance($absensi->MasukPada), $detik, $jadwalHari);
            $adaShiftBerikutnya = $untukAbsenMasuk && $shift !== null
                && $jadwalHari->contains(fn (JadwalKerja $j): bool => $j->JamMulai > $shift->JamMulai);
            $habisMasaTunggu = $sekarang->greaterThanOrEqualTo($batas->addMinutes($aturan['TutupOtomatisSetelahMenit']));

            if (! $habisMasaTunggu && ! ($adaShiftBerikutnya && $sekarang->greaterThanOrEqualTo($batas))) {
                continue;
            }

            if ($this->Tutup($absensi->Id, $batas, $detik)) {
                $jumlah++;
            }
        }

        return $jumlah;
    }

    /** @param array<int, int> $selisih */
    private function TanggalMasukLokal(Absensi $absensi, array $selisih): CarbonImmutable
    {
        return CarbonImmutable::instance($absensi->MasukPada)->utc()->addSeconds($selisih[$absensi->IdOutlet] ?? 25200);
    }

    private function Tutup(int $idAbsensi, CarbonImmutable $batasUtc, int $selisihDetik): bool
    {
        return DB::transaction(function () use ($idAbsensi, $batasUtc, $selisihDetik): bool {
            $absensi = Absensi::query()->whereKey($idAbsensi)->lockForUpdate()->first();

            if ($absensi === null || $absensi->KeluarPada !== null) {
                return false;
            }

            // Jam keluar tidak pernah mendahului jam masuk (masuk setelah batas, misalnya tepat 23.59).
            $masuk = CarbonImmutable::instance($absensi->MasukPada)->utc();
            $keluar = $batasUtc->lessThan($masuk) ? $masuk : $batasUtc;
            $jamLokal = $keluar->addSeconds($selisihDetik)->format('H:i');
            $catatan = "Lupa absen keluar, ditutup otomatis pukul {$jamLokal}";
            $lama = ['KeluarPada' => null, 'KeluarOtomatis' => false, 'PerluDicek' => $absensi->PerluDicek];

            $absensi->forceFill([
                'KeluarPada' => $keluar,
                'KeluarOtomatis' => true,
                'PerluDicek' => true,
                'CatatanPeriksa' => mb_substr($absensi->CatatanPeriksa === null ? $catatan : "{$absensi->CatatanPeriksa}; {$catatan}", 0, 200),
            ])->save();
            $this->audit->Catat('absensi.keluar-otomatis', $absensi, $lama, [
                'KeluarPada' => $keluar->toIso8601ZuluString(),
                'KeluarOtomatis' => true,
                'PerluDicek' => true,
            ], $absensi->IdTenant);

            return true;
        });
    }
}
