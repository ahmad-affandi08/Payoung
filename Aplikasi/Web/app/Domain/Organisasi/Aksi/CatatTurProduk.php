<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Organisasi\Enum\KodeTurProduk;
use App\Domain\Organisasi\Enum\StatusTurProduk;
use App\Domain\Organisasi\Model\TurProduk;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * PLT-12: mencatat bahwa satu pengguna menyelesaikan atau melewati sebuah tur produk. Idempoten: mencatat ulang tur yang
 * sama hanya memperbarui hasilnya (mis. tur dilewati lalu diulang sampai selesai). Preferensi pribadi, jadi tidak
 * diaudit.
 */
final class CatatTurProduk
{
    public function Jalankan(int $idPengguna, KodeTurProduk $kode, StatusTurProduk $status): void
    {
        $nilai = ['Status' => $status];

        try {
            TurProduk::query()->updateOrCreate(['IdPengguna' => $idPengguna, 'Kode' => $kode->value], $nilai);
        } catch (UniqueConstraintViolationException) {
            // Dua permintaan bersamaan (tombol diketuk dua kali): yang kalah cukup memperbarui baris yang sudah ada.
            TurProduk::query()->where('IdPengguna', $idPengguna)->where('Kode', $kode->value)->update($nilai);
        }
    }
}
