<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Komunikasi\Enum\KanalSiaran;
use App\Domain\Pengelola\Komunikasi\Enum\StatusSiaran;
use App\Domain\Pengelola\Komunikasi\Kueri\SegmenSiaran;
use App\Domain\Pengelola\Komunikasi\Model\PesanSiaran;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Menjadwalkan siaran Payoung ke pemilik tenant terpilih (D-210). Siaran langsung berstatus Terjadwal; tanpa jadwal ia
 * dikirim pada putaran pengirim berikutnya. Penerima tidak ditetapkan di sini melainkan saat pengiriman dimulai, supaya
 * tenant yang berubah status sebelum jadwal ikut mengikuti aturannya. Siaran tanpa penerima ditolak.
 */
final class BuatSiaran
{
    public function __construct(
        private readonly SegmenSiaran $segmen,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $segmenMentah
     */
    public function Jalankan(PenggunaPengelola $pelaku, string $judul, string $isi, KanalSiaran $kanal, array $segmenMentah, ?CarbonInterface $jadwal): PesanSiaran
    {
        $segmen = SegmenSiaran::Bersihkan($segmenMentah);
        $sekarang = Carbon::now();

        if ($jadwal !== null && $jadwal->lessThan($sekarang->copy()->subMinutes(5))) {
            throw new PelanggaranAturanBisnis('P-12', 'Waktu kirim sudah lewat. Pilih waktu yang akan datang atau kosongkan untuk kirim segera.', 'JadwalKirimPada');
        }

        if ($this->segmen->Hitung($segmen, $kanal)['Penerima'] === 0) {
            throw new PelanggaranAturanBisnis('P-12', 'Tidak ada penerima yang cocok dengan segmen ini. Ubah segmen atau kanalnya.', 'Segmen');
        }

        $siaran = PesanSiaran::query()->create([
            'Judul' => trim($judul),
            'Isi' => trim($isi),
            'Kanal' => $kanal,
            'Segmen' => $segmen,
            'Status' => StatusSiaran::Terjadwal,
            'JadwalKirimPada' => $jadwal === null || $jadwal->lessThan($sekarang) ? $sekarang : $jadwal,
            'IdPenggunaPengelola' => $pelaku->Id,
        ]);

        $this->audit->Catat(
            'komunikasi.siaran.buat',
            $siaran,
            nilaiBaru: ['Judul' => $siaran->Judul, 'Kanal' => $kanal->value, 'Segmen' => $segmen, 'JadwalKirimPada' => $siaran->JadwalKirimPada->toIso8601String()],
            idPelaku: $pelaku->Id,
        );

        return $siaran;
    }
}
