<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Enum;

/** Jenis mutasi saldo WhatsApp (D-300). Jumlah bertanda: tambah positif, kurang negatif. */
enum JenisMutasiSaldoWhatsapp: string
{
    case TopUp = 'TopUp';
    case Pemakaian = 'Pemakaian';
    case Pengembalian = 'Pengembalian';
    case Penyesuaian = 'Penyesuaian';
    case AlokasiKeluar = 'AlokasiKeluar';
    case AlokasiMasuk = 'AlokasiMasuk';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::TopUp => 'Isi saldo',
            self::Pemakaian => 'Pemakaian broadcast',
            self::Pengembalian => 'Pengembalian pesan gagal',
            self::Penyesuaian => 'Penyesuaian',
            self::AlokasiKeluar => 'Dialokasikan ke outlet',
            self::AlokasiMasuk => 'Kredit dari saldo',
        };
    }
}
