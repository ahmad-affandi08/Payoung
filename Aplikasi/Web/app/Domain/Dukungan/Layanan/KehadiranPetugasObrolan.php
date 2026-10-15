<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

use App\Domain\Dukungan\Model\PetugasObrolan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kehadiran petugas untuk obrolan langsung (PLT-12, D-163). Petugas online bila `Siap`, detaknya (inbox terbuka berdetak
 * tiap 20 detik) masih dalam `dukungan.Obrolan.PetugasAktifDetik`, dan akunnya masih aktif. Dipakai halaman tenant
 * ("tim online") dan penangan email tiket baru (obrolan tidak mengirim email selagi ada petugas online).
 */
final class KehadiranPetugasObrolan
{
    /** Menyetel siap/tidak siap dan sekaligus mencatat detak. */
    public function Atur(int $idPenggunaPengelola, bool $siap): PetugasObrolan
    {
        return PetugasObrolan::query()->updateOrCreate(
            ['IdPenggunaPengelola' => $idPenggunaPengelola],
            ['Siap' => $siap, 'TerakhirAktifPada' => now()],
        );
    }

    /** Detak inbox yang terbuka; tidak menyalakan petugas yang sedang tidak siap. */
    public function Detak(int $idPenggunaPengelola): bool
    {
        $baris = PetugasObrolan::query()->where('IdPenggunaPengelola', $idPenggunaPengelola)->first();

        if ($baris === null || ! $baris->Siap) {
            return false;
        }

        $baris->TerakhirAktifPada = now();
        $baris->save();

        return true;
    }

    public function CekSiap(int $idPenggunaPengelola): bool
    {
        return $this->KueriOnline()->where('PetugasObrolan.IdPenggunaPengelola', $idPenggunaPengelola)->exists();
    }

    public function HitungOnline(): int
    {
        return $this->KueriOnline()->count();
    }

    /**
     * @return Builder<PetugasObrolan>
     */
    private function KueriOnline(): Builder
    {
        $batas = now()->subSeconds((int) config('dukungan.Obrolan.PetugasAktifDetik'));

        return PetugasObrolan::query()
            ->where('Siap', true)
            ->where('TerakhirAktifPada', '>=', $batas)
            // Tabel dibaca lewat query builder: domain Pengelola hanya boleh dipakai kode Pengelola (PengelolaTes).
            ->whereIn('IdPenggunaPengelola', DB::table('PenggunaPengelola')->where('Aktif', true)->select('Id'));
    }
}
