<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Enum\StatusWajahKaryawan;
use App\Domain\Karyawan\Layanan\PencocokWajah;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\WajahKaryawan;

/**
 * Umpan balik langsung di layar absen (D-186): apakah sidik wajah yang sedang terlihat kamera cocok dengan wajah
 * terdaftar karyawan. Hanya jawaban ya/tidak: skor kemiripan dan sidik terdaftar tidak pernah keluar dari server,
 * dan tidak mencatat apa pun. Keputusan yang sah tetap diambil ulang saat absen dikirim (`CatatAbsensiWeb`).
 */
final class CekKecocokanWajah
{
    public function __construct(private readonly PencocokWajah $pencocok) {}

    /** False bila wajah belum terdaftar/disetujui atau tidak cocok. Sidik yang cacat melempar `PelanggaranAturanBisnis`. */
    public function Jalankan(Karyawan $karyawan, mixed $sidik): bool
    {
        $wajah = WajahKaryawan::query()
            ->where('IdKaryawan', $karyawan->Id)
            ->where('Status', StatusWajahKaryawan::Disetujui->value)
            ->first();
        $sidikValid = $this->pencocok->Validasi($sidik);

        return $wajah !== null && $this->pencocok->CekCocok($this->pencocok->HitungKemiripan($sidikValid, $wajah->SidikWajah));
    }
}
