<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Model\Pengguna;
use Illuminate\Support\Facades\Hash;

/**
 * Memastikan pengguna yang sedang masuk memang pemilik akun sebelum tindakan yang tidak bisa dibalik (misal penghapusan
 * data, UU PDP): kata sandinya diminta ulang. Akun yang masuk dengan Google dan belum pernah mengatur kata sandi
 * (`KataSandiOtomatis`) tidak punya kata sandi untuk diminta; identitasnya sudah dibuktikan oleh sesi Google, dan
 * tindakan tetap dikonfirmasi dengan frasa tertulis serta email peringatan ke semua Owner.
 */
final class PastikanKataSandiPengguna
{
    public function Jalankan(Pengguna $pengguna, ?string $kataSandi): void
    {
        if ($pengguna->KataSandiOtomatis) {
            return;
        }

        if ($kataSandi === null || $kataSandi === '' || ! Hash::check($kataSandi, $pengguna->KataSandi)) {
            throw new PelanggaranAturanBisnis('KataSandiSalah', 'Kata sandi salah.', 'KataSandi');
        }
    }
}
