<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Layanan;

use Illuminate\Support\Facades\Cache;

/**
 * Kunci PIN kasir per perangkat + pengguna (§20.2): setelah `PercobaanPinMaksimal` kali salah (bawaan 5), PIN
 * pengguna itu di perangkat itu terkunci `MenitKunciPin` menit (bawaan 5). Percobaan dipesan sebelum PIN diperiksa
 * (`PesanPercobaan`), jadi permintaan serentak tidak bisa menembus batas. Hitungan direset saat PIN benar atau
 * setelah kunci dipasang. Disimpan di cache (bukan database) karena bersifat sementara.
 */
final class PenguncianPin
{
    /** Jendela hitungan gagal: percobaan salah yang lebih lama dari ini dilupakan. */
    private const MENIT_JENDELA_GAGAL = 15;

    public function AmbilSisaDetikKunci(int $idPerangkat, int $idPengguna): int
    {
        $sampai = Cache::get(self::KunciTerkunci($idPerangkat, $idPengguna));

        return is_int($sampai) ? max(0, $sampai - now()->getTimestamp()) : 0;
    }

    /**
     * Memesan satu percobaan SEBELUM PIN diperiksa. Hitungan dinaikkan lebih dulu (atomik di cache), bukan sesudah PIN
     * terbukti salah: bila baru dihitung sesudah salah, permintaan paralel yang masuk sebelum kunci terpasang semuanya
     * ikut memeriksa PIN, sehingga batas 5 percobaan bisa ditembus sebanyak jumlah permintaan serentak.
     *
     * @return bool false = percobaan melewati batas (kunci dipasang, jangan periksa PIN)
     */
    public function PesanPercobaan(int $idPerangkat, int $idPengguna): bool
    {
        $maksimal = (int) config('organisasi.PercobaanPinMaksimal');
        $kunciGagal = self::KunciGagal($idPerangkat, $idPengguna);
        Cache::add($kunciGagal, 0, now()->addMinutes(self::MENIT_JENDELA_GAGAL));

        if ((int) Cache::increment($kunciGagal) <= $maksimal) {
            return true;
        }

        $this->PasangKunci($idPerangkat, $idPengguna);

        return false;
    }

    /**
     * Mencatat satu PIN salah untuk percobaan yang sudah dipesan lewat `PesanPercobaan`.
     *
     * @return int sisa percobaan; 0 = PIN baru saja terkunci
     */
    public function CatatGagal(int $idPerangkat, int $idPengguna): int
    {
        $maksimal = (int) config('organisasi.PercobaanPinMaksimal');
        $gagal = (int) Cache::get(self::KunciGagal($idPerangkat, $idPengguna), 0);

        if ($gagal < $maksimal) {
            return max(1, $maksimal - $gagal);
        }

        $this->PasangKunci($idPerangkat, $idPengguna);

        return 0;
    }

    private function PasangKunci(int $idPerangkat, int $idPengguna): void
    {
        $menitKunci = (int) config('organisasi.MenitKunciPin');
        $sampai = now()->addMinutes($menitKunci);
        Cache::put(self::KunciTerkunci($idPerangkat, $idPengguna), $sampai->getTimestamp(), $sampai);
        Cache::forget(self::KunciGagal($idPerangkat, $idPengguna));
    }

    public function Bersihkan(int $idPerangkat, int $idPengguna): void
    {
        Cache::forget(self::KunciGagal($idPerangkat, $idPengguna));
        Cache::forget(self::KunciTerkunci($idPerangkat, $idPengguna));
    }

    private static function KunciGagal(int $idPerangkat, int $idPengguna): string
    {
        return "pin-kasir:gagal:{$idPerangkat}:{$idPengguna}";
    }

    private static function KunciTerkunci(int $idPerangkat, int $idPengguna): string
    {
        return "pin-kasir:kunci:{$idPerangkat}:{$idPengguna}";
    }
}
