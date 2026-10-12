<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Dukungan\Model\PenggunaDukungan;
use App\Domain\Organisasi\Model\Pengguna;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menyediakan identitas teknis petugas di sisi tenant (PGL-16, D-132): satu `Pengguna` tanpa email dan dengan kata
 * sandi acak yang tidak diketahui siapa pun (jadi tidak bisa masuk lewat halaman masuk, lupa kata sandi, maupun
 * Google), tanpa keanggotaan tenant mana pun. Hak aksesnya hanya muncul selama sesi akses dukungan yang sah
 * (`AksesPengguna` + `KonteksAksesDukungan`). Satu identitas per petugas dipakai di semua tenant; idempoten.
 */
final class SiapkanIdentitasDukungan
{
    /** @return int Id `Pengguna` identitas teknis */
    public function Jalankan(int $idPenggunaPengelola, string $namaPetugas): int
    {
        $ada = PenggunaDukungan::query()->where('IdPenggunaPengelola', $idPenggunaPengelola)->value('IdPengguna');

        if (is_int($ada)) {
            return $ada;
        }

        try {
            return DB::transaction(function () use ($idPenggunaPengelola, $namaPetugas): int {
                $pengguna = Pengguna::query()->create([
                    'Nama' => mb_substr('Dukungan Payoung | '.trim($namaPetugas), 0, 150),
                    'Email' => null,
                    'KataSandi' => Str::random(64),
                    'KataSandiOtomatis' => true,
                ]);
                PenggunaDukungan::query()->create(['IdPenggunaPengelola' => $idPenggunaPengelola, 'IdPengguna' => $pengguna->Id]);

                return $pengguna->Id;
            });
        } catch (UniqueConstraintViolationException $galat) {
            // Dua permintaan bersamaan untuk petugas yang sama: yang kalah memakai identitas pemenangnya.
            $pemenang = PenggunaDukungan::query()->where('IdPenggunaPengelola', $idPenggunaPengelola)->value('IdPengguna');

            if (is_int($pemenang)) {
                return $pemenang;
            }

            throw $galat;
        }
    }
}
