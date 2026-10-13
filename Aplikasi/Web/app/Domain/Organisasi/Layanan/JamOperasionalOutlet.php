<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Aturan jam operasional outlet (F-e): jam per hari dalam jam lokal outlet, `Buka`/`Tutup` `HH:MM`. `Tutup` sama dengan
 * atau lebih awal dari `Buka` berarti tutup lewat tengah malam (misal 18:00–02:00, atau 08:00–00:00 sampai tengah malam).
 * Hari null = libur. Seluruhnya null (tidak diatur) = selalu buka. Fungsi murni, tanpa akses basis data.
 *
 * @phpstan-type JamHari array{Buka: string, Tutup: string}|null
 * @phpstan-type JamMingguan array<string, JamHari>
 */
final class JamOperasionalOutlet
{
    /** Urutan hari ISO (1 = Senin) → nama kunci JSON. */
    public const HARI = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    private const POLA_JAM = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * Merapikan masukan menjadi tujuh hari berurutan; hari yang tidak disebut atau berisi kosong dianggap libur.
     *
     * @param  array<string, mixed>|null  $mentah
     * @return JamMingguan|null null = tidak diatur
     *
     * @throws PelanggaranAturanBisnis JamTidakSah, JamSamaBukaTutup, SemuaHariLibur
     */
    public static function Normalkan(?array $mentah): ?array
    {
        if ($mentah === null) {
            return null;
        }

        $hasil = [];
        $adaBuka = false;

        foreach (self::HARI as $nama) {
            $hari = $mentah[$nama] ?? null;

            if (! is_array($hari) || (($hari['Buka'] ?? '') === '' && ($hari['Tutup'] ?? '') === '')) {
                $hasil[$nama] = null;

                continue;
            }

            $buka = is_string($hari['Buka'] ?? null) ? $hari['Buka'] : '';
            $tutup = is_string($hari['Tutup'] ?? null) ? $hari['Tutup'] : '';

            if (preg_match(self::POLA_JAM, $buka) !== 1 || preg_match(self::POLA_JAM, $tutup) !== 1) {
                throw new PelanggaranAturanBisnis('JamTidakSah', "Jam {$nama} harus berformat JJ:MM (24 jam), misalnya 08:00.", 'JamOperasional');
            }

            if ($buka === $tutup) {
                throw new PelanggaranAturanBisnis('JamSamaBukaTutup', "Jam buka dan tutup {$nama} tidak boleh sama.", 'JamOperasional');
            }

            $hasil[$nama] = ['Buka' => $buka, 'Tutup' => $tutup];
            $adaBuka = true;
        }

        if (! $adaBuka) {
            throw new PelanggaranAturanBisnis('SemuaHariLibur', 'Isi jam buka minimal satu hari, atau hapus pengaturan jam operasional.', 'JamOperasional');
        }

        return $hasil;
    }

    /**
     * Apakah outlet buka pada waktu lokal ini. Tanpa pengaturan = selalu buka.
     *
     * @param  JamMingguan|null  $jam
     */
    public static function CekBuka(?array $jam, CarbonInterface $lokal): bool
    {
        if ($jam === null) {
            return true;
        }

        // Salin tak-berubah: Carbon biasa (mutable) dari `now()` akan ikut bergeser oleh `subDay()`.
        $lokal = CarbonImmutable::instance($lokal);
        $menit = self::Menit($lokal->format('H:i'));
        $hariIni = $jam[self::HARI[$lokal->dayOfWeekIso]] ?? null;

        if ($hariIni !== null) {
            $buka = self::Menit($hariIni['Buka']);
            $tutup = self::Menit($hariIni['Tutup']);

            if ($tutup > $buka ? ($menit >= $buka && $menit < $tutup) : $menit >= $buka) {
                return true;
            }
        }

        // Jam kemarin yang melewati tengah malam masih berlaku sampai jam tutupnya hari ini.
        $kemarin = $jam[self::HARI[$lokal->subDay()->dayOfWeekIso]] ?? null;

        return $kemarin !== null && self::Menit($kemarin['Tutup']) <= self::Menit($kemarin['Buka']) && $menit < self::Menit($kemarin['Tutup']);
    }

    /**
     * Saat buka berikutnya setelah waktu lokal ini (dalam tujuh hari ke depan); null bila tidak diatur atau sedang buka.
     *
     * @param  JamMingguan|null  $jam
     */
    public static function CariBukaBerikut(?array $jam, CarbonInterface $lokal): ?CarbonImmutable
    {
        if ($jam === null || self::CekBuka($jam, $lokal)) {
            return null;
        }

        $sekarang = CarbonImmutable::instance($lokal);

        for ($selisih = 0; $selisih <= 7; $selisih++) {
            $tanggal = $sekarang->addDays($selisih);
            $hari = $jam[self::HARI[$tanggal->dayOfWeekIso]] ?? null;

            if ($hari === null) {
                continue;
            }

            [$jamBuka, $menitBuka] = array_map('intval', explode(':', $hari['Buka']));
            $calon = $tanggal->setTime($jamBuka, $menitBuka);

            if ($calon->greaterThan($sekarang)) {
                return $calon;
            }
        }

        return null;
    }

    /**
     * Kalimat untuk pembeli: "Buka lagi hari ini pukul 08.00" / "Buka lagi Selasa pukul 08.00".
     *
     * @param  JamMingguan|null  $jam
     */
    public static function SusunPesanTutup(?array $jam, CarbonInterface $lokal): ?string
    {
        $lokal = CarbonImmutable::instance($lokal);
        $berikut = self::CariBukaBerikut($jam, $lokal);

        if ($berikut === null) {
            return null;
        }

        $kapan = $berikut->isSameDay($lokal) ? 'hari ini' : ($berikut->isSameDay($lokal->addDay()) ? 'besok' : self::HARI[$berikut->dayOfWeekIso]);

        return "Buka lagi {$kapan} pukul ".str_replace(':', '.', $berikut->format('H:i')).'.';
    }

    private static function Menit(string $jam): int
    {
        [$j, $m] = array_map('intval', explode(':', $jam));

        return $j * 60 + $m;
    }
}
