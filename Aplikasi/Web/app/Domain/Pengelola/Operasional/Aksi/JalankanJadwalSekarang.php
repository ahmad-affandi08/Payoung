<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Operasional\Kueri\DaftarJadwalOtomatis;
use App\Domain\Pengelola\Operasional\Model\RiwayatJadwal;
use App\Domain\Pengelola\Operasional\Tugas\JalankanPerintahJadwalTugas;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan satu perintah terjadwal sekarang juga (D-209, P-11). Hanya perintah yang terdaftar di jadwal; perintah
 * yang sama tidak dijalankan dua kali bersamaan. Pekerjaannya diantrekan, hasilnya tampil di riwayat jadwal.
 */
final class JalankanJadwalSekarang
{
    /** Jalan manual yang belum selesai selama ini dianggap masih berjalan. */
    private const BATAS_BERJALAN_MENIT = 30;

    public function __construct(
        private readonly DaftarJadwalOtomatis $daftar,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, string $perintah): RiwayatJadwal
    {
        if (! $this->daftar->Dikenal($perintah)) {
            throw new PelanggaranAturanBisnis('P-11', 'Perintah ini tidak ada di jadwal otomatis, jadi tidak bisa dijalankan dari sini.', 'Perintah');
        }

        $baris = DB::transaction(function () use ($pelaku, $perintah): RiwayatJadwal {
            $sedangBerjalan = RiwayatJadwal::query()
                ->where('Perintah', $perintah)
                ->whereNull('SelesaiPada')
                ->where('MulaiPada', '>', Carbon::now()->subMinutes(self::BATAS_BERJALAN_MENIT))
                ->lockForUpdate()
                ->exists();

            if ($sedangBerjalan) {
                throw new PelanggaranAturanBisnis('P-11', 'Perintah ini masih berjalan. Tunggu selesai sebelum menjalankannya lagi.', 'Perintah');
            }

            $baris = RiwayatJadwal::query()->create([
                'Perintah' => $perintah,
                'Sumber' => RiwayatJadwal::SUMBER_MANUAL,
                'MulaiPada' => Carbon::now(),
                'IdPenggunaPengelola' => $pelaku->Id,
            ]);

            $this->audit->Catat('operasional.jadwal.jalankan', nilaiBaru: ['Perintah' => $perintah], idPelaku: $pelaku->Id);

            return $baris;
        });

        JalankanPerintahJadwalTugas::dispatch($baris->Id)->afterCommit();

        return $baris;
    }
}
