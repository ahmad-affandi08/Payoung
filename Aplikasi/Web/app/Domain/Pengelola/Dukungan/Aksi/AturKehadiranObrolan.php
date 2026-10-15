<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Dukungan\Layanan\KehadiranPetugasObrolan;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;

/**
 * Petugas menyalakan atau mematikan kesiapan menjawab obrolan langsung (PLT-12, D-163). Menyalakan wajib berizin
 * menangani tiket. Dipanggil juga sebagai detak tiap 20 detik selagi inbox terbuka: petugas yang inbox-nya tertutup
 * otomatis dianggap offline setelah `dukungan.Obrolan.PetugasAktifDetik`. Tidak ada log audit (hanya status kehadiran,
 * tanpa data tenant dan sering berubah).
 */
final class AturKehadiranObrolan
{
    public function __construct(private readonly KehadiranPetugasObrolan $kehadiran) {}

    /**
     * @return array{Siap: bool, PetugasOnline: int}
     */
    public function Jalankan(PenggunaPengelola $pelaku, bool $siap): array
    {
        if ($siap && ! $pelaku->PunyaIzin(IzinPengelola::DukunganTiketTangani)) {
            throw new PelanggaranAturanBisnis('P-09', 'Anda tidak berizin menangani tiket dukungan.', 'Siap');
        }

        $this->kehadiran->Atur($pelaku->Id, $siap);

        return ['Siap' => $siap, 'PetugasOnline' => $this->kehadiran->HitungOnline()];
    }

    /**
     * @return array{Siap: bool, PetugasOnline: int}
     */
    public function Detak(PenggunaPengelola $pelaku): array
    {
        $this->kehadiran->Detak($pelaku->Id);

        return ['Siap' => $this->kehadiran->CekSiap($pelaku->Id), 'PetugasOnline' => $this->kehadiran->HitungOnline()];
    }
}
