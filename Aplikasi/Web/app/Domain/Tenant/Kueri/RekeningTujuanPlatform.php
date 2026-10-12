<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Tenant\Layanan\PengaturanPenagihanPlatform;

/**
 * Rekening tujuan transfer tagihan langganan platform (P-08), diatur Super Admin di konsol (D-114); nilai awal dari
 * `config/tagihan.php` selama belum pernah disimpan dari konsol.
 */
final class RekeningTujuanPlatform
{
    public function __construct(private readonly PengaturanPenagihanPlatform $pengaturan) {}

    /**
     * @return list<array{Kode: string, NamaBank: string, NomorRekening: string, AtasNama: string}>
     */
    public function Ambil(): array
    {
        return $this->pengaturan->Rekening();
    }

    /**
     * @return array{Kode: string, NamaBank: string, NomorRekening: string, AtasNama: string}|null
     */
    public function Cari(string $kode): ?array
    {
        foreach ($this->Ambil() as $rekening) {
            if ($rekening['Kode'] === $kode) {
                return $rekening;
            }
        }

        return null;
    }
}
