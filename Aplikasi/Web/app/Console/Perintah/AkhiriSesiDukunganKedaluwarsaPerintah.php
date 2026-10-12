<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Dukungan\Aksi\AkhiriSesiDukunganKedaluwarsa;
use App\Domain\Dukungan\Model\SesiAksesDukungan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tiap 15 menit mengisi akhir sesi akses dukungan yang waktunya habis (PGL-16). Hanya tenant yang punya sesi belum
 * ditutup yang dikunjungi (pola D-81); galat satu tenant tidak menghentikan yang lain.
 */
final class AkhiriSesiDukunganKedaluwarsaPerintah extends Command
{
    protected $signature = 'dukungan:akhiri-kedaluwarsa';

    protected $description = 'Mencatat akhir sesi akses dukungan yang waktunya habis (PGL-16).';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, AkhiriSesiDukunganKedaluwarsa $akhiri): int
    {
        $sebelumnya = $konteks->Ambil();
        $jumlah = 0;

        try {
            foreach ($pengelola->IdTenantDenganPekerjaan(SesiAksesDukungan::class, fn ($q) => $q->whereNull('DiakhiriPada')->where(fn ($w) => $w->where('BerakhirPada', '<=', now())->orWhere(fn ($m) => $m->whereNull('TokenDipakaiPada')->where('TokenKedaluwarsaPada', '<=', now())))) as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    $jumlah += $akhiri->Jalankan();
                } catch (Throwable $galat) {
                    Log::warning('Sesi akses dukungan kedaluwarsa gagal dirapikan, dicoba lagi putaran berikutnya.', ['IdTenant' => $idTenant, 'Galat' => $galat->getMessage()]);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$jumlah} sesi akses dukungan dirapikan.");

        return self::SUCCESS;
    }
}
