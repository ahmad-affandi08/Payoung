<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Layanan;

use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\KontakAnggotaTenant;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Mengirim jadwal satu minggu satu outlet ke tiap karyawan lewat WhatsApp (F-18, D-251, D-33). Satu pesan per karyawan
 * berisi semua shift minggu itu. Karyawan tanpa akun bernomor WhatsApp sah dilewati. Dibaca dalam konteks tenant.
 */
final class PengirimJadwalMingguan
{
    private const HARI = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    private const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __construct(
        private readonly KontakAnggotaTenant $kontak,
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly ProfilTenant $profil,
        private readonly PetaUuidOutlet $outlet,
    ) {}

    /**
     * Penerima yang akan dikirimi: karyawan aktif dengan jadwal di outlet pada minggu itu dan nomor WhatsApp akun tertaut.
     *
     * @return array<int, array{Karyawan: Karyawan, NoHp: string, Jadwal: list<JadwalKerja>}> id karyawan → data
     */
    public function AmbilPenerima(int $idTenant, int $idOutlet, CarbonImmutable $senin): array
    {
        $jadwal = JadwalKerja::query()
            ->where('IdOutlet', $idOutlet)
            ->whereBetween('Tanggal', [$senin->toDateString(), $senin->addDays(6)->toDateString()])
            ->orderBy('Tanggal')
            ->orderBy('JamMulai')
            ->get()
            ->groupBy('IdKaryawan');

        if ($jadwal->isEmpty()) {
            return [];
        }

        $nomor = [];

        foreach ($this->kontak->AmbilWhatsapp($idTenant) as $a) {
            $nomor[$a['Id']] = $a['NoHp'];
        }

        $hasil = [];

        foreach (Karyawan::query()->whereKey($jadwal->keys()->all())->where('Status', StatusKaryawan::Aktif->value)->get() as $k) {
            $noHp = $k->IdPengguna === null ? null : ($nomor[$k->IdPengguna] ?? null);

            if ($noHp !== null) {
                $hasil[$k->Id] = ['Karyawan' => $k, 'NoHp' => $noHp, 'Jadwal' => $jadwal->get($k->Id)?->all() ?? []];
            }
        }

        return $hasil;
    }

    /** @return int jumlah pesan terkirim */
    public function Kirim(int $idTenant, int $idOutlet, CarbonImmutable $senin): int
    {
        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            return 0;
        }

        $namaUsaha = $this->profil->Ambil($idTenant)['Nama'];
        $namaOutlet = $this->outlet->AmbilRingkas([$idOutlet])[0]['Nama'] ?? 'outlet';
        $rentang = self::FormatTanggal($senin).' sampai '.self::FormatTanggal($senin->addDays(6));
        $terkirim = 0;

        foreach ($this->AmbilPenerima($idTenant, $idOutlet, $senin) as $p) {
            $baris = array_map(
                fn (JadwalKerja $j): string => '• '.self::HARI[$j->Tanggal->dayOfWeekIso - 1].' '.self::FormatTanggal(CarbonImmutable::parse($j->Tanggal->toDateString(), 'UTC')).': '.$j->JamMulai.'–'.$j->JamSelesai,
                $p['Jadwal'],
            );
            $teks = "Halo {$p['Karyawan']->Nama}, jadwal kerja Anda di {$namaUsaha} ({$namaOutlet}) untuk {$rentang}:\n".implode("\n", $baris)."\nLihat dan ajukan tukar shift di ".AlamatDomain::BuatUrlAbsolutTenant('/kelola/absen');

            try {
                if ($pengirim->Kirim(new PesanWhatsapp($p['NoHp'], $teks))->berhasil) {
                    $terkirim++;
                }
            } catch (Throwable $galat) {
                report($galat);
            }
        }

        return $terkirim;
    }

    private static function FormatTanggal(CarbonImmutable $tanggal): string
    {
        return $tanggal->day.' '.self::BULAN[$tanggal->month - 1];
    }
}
