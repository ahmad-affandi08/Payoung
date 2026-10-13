<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Enum;

/** v6.07 (F-04 BR-04.10): sebab nota debit pembelian. Semuanya tanpa barang kembali; barang kembali = retur pembelian. */
enum JenisNotaDebit: string
{
    case SelisihHarga = 'SelisihHarga';
    case DiskonSusulan = 'DiskonSusulan';
    case KlaimKerusakan = 'KlaimKerusakan';
    case KlaimKekurangan = 'KlaimKekurangan';
    case Lainnya = 'Lainnya';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::SelisihHarga => 'Selisih harga',
            self::DiskonSusulan => 'Diskon/rabat susulan',
            self::KlaimKerusakan => 'Klaim barang rusak/cacat',
            self::KlaimKekurangan => 'Klaim kekurangan barang',
            self::Lainnya => 'Lainnya',
        };
    }
}
