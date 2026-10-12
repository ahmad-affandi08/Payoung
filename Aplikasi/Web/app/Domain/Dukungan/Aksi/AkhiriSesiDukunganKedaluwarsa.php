<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Aksi;

use App\Domain\Dukungan\Enum\AlasanAkhirSesiDukungan;
use App\Domain\Dukungan\Model\SesiAksesDukungan;

/**
 * Merapikan catatan sesi akses dukungan yang waktunya habis (PGL-16): sesi yang batasnya lewat, dan sesi yang tokennya
 * tidak pernah ditukar dalam 60 detik. Akses sudah tertutup sendiri oleh penjaga per permintaan; tugas ini hanya
 * mengisi `DiakhiriPada` dan alasannya supaya riwayat Owner dan konsol akurat. Berjalan dalam konteks satu tenant.
 */
final class AkhiriSesiDukunganKedaluwarsa
{
    public function __construct(private readonly AkhiriSesiAksesDukungan $akhiri) {}

    /** @return int jumlah sesi yang diakhiri */
    public function Jalankan(): int
    {
        $jumlah = 0;

        SesiAksesDukungan::query()
            ->whereNull('DiakhiriPada')
            ->where(fn ($kueri) => $kueri
                ->where('BerakhirPada', '<=', now())
                ->orWhere(fn ($belumDitukar) => $belumDitukar->whereNull('TokenDipakaiPada')->where('TokenKedaluwarsaPada', '<=', now())))
            ->orderBy('Id')
            ->each(function (SesiAksesDukungan $sesi) use (&$jumlah): void {
                if ($this->akhiri->Jalankan($sesi, AlasanAkhirSesiDukungan::Kedaluwarsa)) {
                    $jumlah++;
                }
            });

        return $jumlah;
    }
}
