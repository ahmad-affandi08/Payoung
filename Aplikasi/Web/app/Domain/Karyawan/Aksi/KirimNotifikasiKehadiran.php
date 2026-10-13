<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Kueri\AturanKehadiranTenant;
use App\Domain\Karyawan\Kueri\CutiAktifKaryawan;
use App\Domain\Karyawan\Layanan\PenilaiKehadiran;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\KontakAnggotaTenant;
use App\Domain\Organisasi\Kueri\KonteksTindakanPengguna;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Notifikasi kehadiran lewat WhatsApp (F-18 bagian 5, D-44; notifikasi tenant hanya WhatsApp, D-33). Dijalankan tiap
 * 5 menit per tenant, dalam konteks tenant, dan aman diulang (tiap jadwal ditandai sekali kirim):
 *
 * - **Pengingat shift** ke karyawan (nomor HP akun tertaut) `PengingatShiftMenitSebelum` menit sebelum `JamMulai`,
 *   bila belum absen. Tanpa akun/nomor sah = dilewati dan ditandai supaya tidak dipindai ulang.
 * - **Peringatan ke pengelola** `PeringatanPengelolaSetelahMenit` menit setelah `JamMulai`: karyawan terjadwal yang
 *   **belum masuk**, atau yang masuk **terlambat** (melewati toleransi). Penerima = anggota ber-izin `karyawan.lihat`
 *   yang boleh mengakses outlet jadwal, satu pesan berisi semua kejadian putaran itu. Yang tepat waktu hanya ditandai.
 *
 * Jadwal yang tercakup cuti/izin berlaku (berbayar atau tidak) dilewati tanpa penanda: karyawan memang tidak
 * diharapkan masuk, dan bila cutinya dibatalkan sebelum shift berakhir, notifikasi berjalan normal di putaran berikutnya.
 *
 * Penanda (`PengingatShiftPada`, `PeringatanKehadiranPada`) hanya diisi bila pesan benar-benar terkirim atau memang
 * tidak ada yang bisa dikirimi, jadi kegagalan sementara dicoba lagi di putaran berikutnya.
 */
final class KirimNotifikasiKehadiran
{
    public function __construct(
        private readonly AturanKehadiranTenant $aturan,
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly KontakAnggotaTenant $kontak,
        private readonly KonteksTindakanPengguna $konteks,
        private readonly ZonaWaktuOutlet $zona,
        private readonly PetaUuidOutlet $outlet,
        private readonly ProfilTenant $profil,
        private readonly PenilaiKehadiran $penilai,
        private readonly CutiAktifKaryawan $cuti,
    ) {}

    /** @return array{Pengingat: int, Peringatan: int} jumlah pesan terkirim */
    public function Jalankan(int $idTenant, CarbonImmutable $sekarang): array
    {
        $hasil = ['Pengingat' => 0, 'Peringatan' => 0];
        $aturan = $this->aturan->Ambil();

        if (! $aturan['PengingatShiftAktif'] && ! $aturan['PeringatanPengelolaAktif']) {
            return $hasil;
        }

        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null || ! $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
            return $hasil;
        }

        $sekarang = $sekarang->utc();
        $jadwal = JadwalKerja::query()
            ->whereBetween('Tanggal', [$sekarang->subDays(2)->toDateString(), $sekarang->addDay()->toDateString()])
            ->where(fn ($k) => $k->whereNull('PengingatShiftPada')->orWhereNull('PeringatanKehadiranPada'))
            ->get();

        if ($jadwal->isEmpty()) {
            return $hasil;
        }

        $karyawan = Karyawan::query()->whereKey($jadwal->pluck('IdKaryawan')->unique()->all())->where('Status', StatusKaryawan::Aktif->value)->get()->keyBy('Id');
        $idOutlet = array_values(array_unique(array_map('intval', $jadwal->pluck('IdOutlet')->all())));
        $selisih = $this->zona->AmbilSelisihDetik($idOutlet);
        $namaOutlet = [];

        foreach ($this->outlet->AmbilRingkas($idOutlet) as $o) {
            $namaOutlet[$o['Id']] = $o['Nama'];
        }

        $namaUsaha = $this->profil->Ambil($idTenant)['Nama'];
        $anggota = $this->kontak->AmbilWhatsapp($idTenant);
        $nomorAnggota = [];

        foreach ($anggota as $a) {
            $nomorAnggota[$a['Id']] = $a['NoHp'];
        }

        $cuti = $this->cuti->AmbilPerTanggal(
            $sekarang->subDays(2)->toDateString(),
            $sekarang->addDay()->toDateString(),
            array_values(array_map('intval', $jadwal->pluck('IdKaryawan')->unique()->all())),
        );
        $kejadian = [];
        $ditandai = [];

        foreach ($jadwal as $j) {
            $k = $karyawan->get($j->IdKaryawan);

            if ($k === null || isset($cuti[$j->IdKaryawan][$j->Tanggal->toDateString()])) {
                continue;
            }

            $detik = $selisih[$j->IdOutlet] ?? 25200;
            $batas = PenilaiKehadiran::AmbilBatasShift($j->Tanggal->toDateString(), $j->JamMulai, $j->JamSelesai);
            $mulai = $batas['Mulai']->subSeconds($detik);
            $selesai = $batas['Selesai']->subSeconds($detik);
            $masuk = Absensi::query()
                ->where('IdKaryawan', $j->IdKaryawan)
                ->where('MasukPada', '>=', $mulai->subHours(4))
                ->where('MasukPada', '<=', $selesai)
                ->orderBy('MasukPada')
                ->first();

            if ($aturan['PengingatShiftAktif'] && $j->PengingatShiftPada === null && $masuk === null
                && $sekarang->greaterThanOrEqualTo($mulai->subMinutes($aturan['PengingatShiftMenitSebelum'])) && $sekarang->lessThan($mulai)) {
                $nomor = $k->IdPengguna === null ? null : ($nomorAnggota[$k->IdPengguna] ?? null);

                if ($nomor === null || $this->KirimPengingat($pengirim, $nomor, $k, $j, $namaUsaha, $namaOutlet[$j->IdOutlet] ?? 'outlet', $mulai, $sekarang)) {
                    $j->forceFill(['PengingatShiftPada' => $sekarang])->save();
                    $hasil['Pengingat'] += $nomor === null ? 0 : 1;
                }
            }

            if ($aturan['PeringatanPengelolaAktif'] && $j->PeringatanKehadiranPada === null
                && $sekarang->greaterThanOrEqualTo($mulai->addMinutes($aturan['PeringatanPengelolaSetelahMenit'])) && $sekarang->lessThanOrEqualTo($selesai)) {
                $terlambat = $masuk === null ? 0 : $this->penilai->Nilai(
                    $j->Tanggal->toDateString(),
                    $j->JamMulai,
                    $j->JamSelesai,
                    CarbonImmutable::instance($masuk->MasukPada)->utc()->addSeconds($detik),
                    null,
                    $aturan['ToleransiTerlambatMenit'],
                    $aturan['ToleransiPulangCepatMenit'],
                    $aturan['LemburSetelahMenit'],
                )['TerlambatMenit'];

                if ($masuk === null || $terlambat > 0) {
                    $kejadian[] = ['Jadwal' => $j, 'Teks' => $masuk === null
                        ? "• {$k->Nama} belum masuk (shift {$j->JamMulai}–{$j->JamSelesai}, ".($namaOutlet[$j->IdOutlet] ?? 'outlet').')'
                        : "• {$k->Nama} terlambat {$terlambat} menit (shift {$j->JamMulai}, ".($namaOutlet[$j->IdOutlet] ?? 'outlet').')'];
                } else {
                    $ditandai[] = $j;
                }
            }
        }

        foreach ($ditandai as $j) {
            $j->forceFill(['PeringatanKehadiranPada' => $sekarang])->save();
        }

        if ($kejadian !== []) {
            $hasil['Peringatan'] = $this->KirimPeringatan($pengirim, $idTenant, $anggota, $kejadian, $namaUsaha, $sekarang);
        }

        return $hasil;
    }

    private function KirimPengingat(PengirimWhatsapp $pengirim, string $nomor, Karyawan $k, JadwalKerja $j, string $namaUsaha, string $namaOutlet, CarbonImmutable $mulai, CarbonImmutable $sekarang): bool
    {
        $menit = max(0, intdiv($mulai->getTimestamp() - $sekarang->getTimestamp(), 60));
        $teks = "Halo {$k->Nama}, shift Anda di {$namaUsaha} ({$namaOutlet}) mulai pukul {$j->JamMulai}, sekitar {$menit} menit lagi. Jangan lupa absen masuk.";

        if ($k->TokenAbsen !== null) {
            $slug = $this->profil->AmbilSlug($k->IdTenant);
            $teks .= "\nAbsen dari HP: ".AlamatDomain::BuatUrlAbsolutPemasaran("/{$slug}/absen/{$k->TokenAbsen}");
        }

        return $this->Kirim($pengirim, new PesanWhatsapp($nomor, $teks));
    }

    /**
     * @param  list<array{Id: int, Nama: string, NoHp: string, Pemilik: bool}>  $anggota
     * @param  list<array{Jadwal: JadwalKerja, Teks: string}>  $kejadian
     */
    private function KirimPeringatan(PengirimWhatsapp $pengirim, int $idTenant, array $anggota, array $kejadian, string $namaUsaha, CarbonImmutable $sekarang): int
    {
        $terkirim = 0;
        $adaPenerima = [];
        $sudahTerkirim = [];

        foreach ($anggota as $a) {
            $konteks = $this->konteks->Buat($idTenant, $a['Id'], $sekarang);

            if (! $konteks->CekIzin(IzinTenant::KaryawanLihat->value)) {
                continue;
            }

            $milik = array_values(array_filter($kejadian, fn (array $e): bool => $konteks->idOutletBoleh === null || in_array($e['Jadwal']->IdOutlet, $konteks->idOutletBoleh, true)));

            foreach ($milik as $e) {
                $adaPenerima[$e['Jadwal']->Id] = true;
            }

            if ($milik === []) {
                continue;
            }

            $teks = "Peringatan kehadiran {$namaUsaha}:\n".implode("\n", array_column($milik, 'Teks'))."\nCek di ".AlamatDomain::BuatUrlAbsolutTenant('/kelola/karyawan/absensi');

            if ($this->Kirim($pengirim, new PesanWhatsapp($a['NoHp'], $teks))) {
                $terkirim++;

                foreach ($milik as $e) {
                    $sudahTerkirim[$e['Jadwal']->Id] = true;
                }
            }
        }

        foreach ($kejadian as $e) {
            // Ditandai bila sudah terkirim ke seseorang, atau memang tidak ada penerima yang sah (jangan dipindai ulang tiap 5 menit).
            if (isset($sudahTerkirim[$e['Jadwal']->Id]) || ! isset($adaPenerima[$e['Jadwal']->Id])) {
                $e['Jadwal']->forceFill(['PeringatanKehadiranPada' => $sekarang])->save();
            }
        }

        return $terkirim;
    }

    private function Kirim(PengirimWhatsapp $pengirim, PesanWhatsapp $pesan): bool
    {
        try {
            return $pengirim->Kirim($pesan)->berhasil;
        } catch (Throwable $galat) {
            report($galat);

            return false;
        }
    }
}
