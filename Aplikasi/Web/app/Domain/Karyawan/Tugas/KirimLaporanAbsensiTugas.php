<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Tugas;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Layanan\PelaporKaryawanWhatsapp;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * D-299 tahap 3: kabar absen masuk atau pulang karyawan ke penerima laporan WhatsApp outlet. Setelah commit, lewat
 * antrean (aturan #10), tanpa coba ulang supaya penerima tidak menerima pesan ganda.
 */
final class KirimLaporanAbsensiTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idAbsensi,
        public readonly string $arah,
    ) {}

    public function handle(KonteksTenant $konteks, PelaporKaryawanWhatsapp $pelapor, ZonaWaktuOutlet $zona, PetaUuidOutlet $outlet): void
    {
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $absensi = Absensi::query()->find($this->idAbsensi);
            $karyawan = $absensi === null ? null : Karyawan::query()->find($absensi->IdKaryawan);

            if ($absensi === null || $karyawan === null || ! $pelapor->AdaPenerima($absensi->IdOutlet)) {
                return;
            }

            $waktu = $this->arah === 'masuk' ? $absensi->MasukPada : $absensi->KeluarPada;

            if ($waktu === null) {
                return;
            }

            $jam = $waktu->copy()->utc()->addSeconds((int) ($zona->AmbilSelisihDetik([$absensi->IdOutlet])[$absensi->IdOutlet] ?? 0))->format('H:i');
            $namaOutlet = $outlet->AmbilRingkas([$absensi->IdOutlet])[0]['Nama'] ?? 'outlet';
            $kalimat = $this->arah === 'masuk' ? 'absen masuk' : 'absen pulang';
            $pelapor->Kirim(JenisPesanWhatsapp::LaporanAbsensi, $absensi->IdOutlet, "{$karyawan->Nama} {$kalimat} pukul {$jam} di {$namaOutlet}.");
        } finally {
            $konteks->Atur($sebelumnya);
        }
    }
}
