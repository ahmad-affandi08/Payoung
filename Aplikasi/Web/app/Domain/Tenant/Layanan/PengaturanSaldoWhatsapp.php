<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Model\PengaturanPlatform;

/**
 * Pengaturan saldo WhatsApp yang diatur Super Admin dari konsol (D-300): harga per pesan broadcast dan minimal isi
 * saldo. Tanpa angka di kode: selama belum diatur, harga per pesan 0 (broadcast gratis) dan minimal isi saldo
 * Rp 10.000 (batas teknis agar pembayaran gerbang masuk akal).
 */
final class PengaturanSaldoWhatsapp
{
    public const KUNCI_HARGA = 'whatsapp.harga-per-pesan-broadcast';

    public const KUNCI_MINIMAL = 'whatsapp.top-up-minimal';

    public const MINIMAL_BAWAAN = '10000';

    public const MAKS_HARGA = '100000';

    public const MAKS_MINIMAL = '10000000';

    public function HargaPerPesan(): Uang
    {
        return Uang::Dari($this->Baca(self::KUNCI_HARGA) ?? '0');
    }

    public function TopUpMinimal(): Uang
    {
        return Uang::Dari($this->Baca(self::KUNCI_MINIMAL) ?? self::MINIMAL_BAWAAN);
    }

    /** Broadcast berbayar hanya bila konsol sudah menetapkan harga per pesan lebih dari nol. */
    public function BroadcastBerbayar(): bool
    {
        return ! $this->HargaPerPesan()->BernilaiNol();
    }

    private function Baca(string $kunci): ?string
    {
        $nilai = PengaturanPlatform::query()->where('Kunci', $kunci)->value('Nilai');

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }
}
