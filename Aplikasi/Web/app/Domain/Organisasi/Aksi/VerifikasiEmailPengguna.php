<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Layanan\PenandaVerifikasiEmail;
use App\Domain\Organisasi\Model\Pengguna;

/**
 * Menandai email terverifikasi dari tautan bertanda tangan (BR-00.5). Tanda tangan & masa berlaku diperiksa perantara
 * `signed`; di sini hash email dicocokkan.
 */
final class VerifikasiEmailPengguna
{
    public function __construct(private readonly PenandaVerifikasiEmail $penanda) {}

    public function Jalankan(Pengguna $pengguna, string $hash): void
    {
        if (! $this->penanda->CekCocok($pengguna, $hash)) {
            throw new PelanggaranAturanBisnis('TautanTidakValid', 'Tautan verifikasi tidak valid. Minta tautan baru.');
        }

        if ($pengguna->EmailDiverifikasiPada === null || $pengguna->EmailBelumDibuktikan) {
            $pengguna->forceFill(['EmailDiverifikasiPada' => $pengguna->EmailDiverifikasiPada ?? now(), 'EmailBelumDibuktikan' => false])->save();
        }
    }
}
