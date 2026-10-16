<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

use App\Domain\Pelanggan\Enum\StatusPiutang;

/**
 * Status penagihan invoice yang **diturunkan** dari `Piutang` dan tanggal (F-21, D-166), bukan kolom: Draf, Belum
 * dibayar, Dibayar sebagian, Lewat tempo, Lunas, Dihapus-buku, atau Dibatalkan. Invoice terbit tanpa piutang (tagihannya nol
 * karena seluruhnya tertutup uang muka) otomatis Lunas. Dihitung di satu tempat ini supaya daftar, detail, halaman publik,
 * dan pengingat tidak punya tafsir sendiri-sendiri.
 */
enum StatusBayarInvoice: string
{
    case Draf = 'Draf';
    case BelumDibayar = 'BelumDibayar';
    case DibayarSebagian = 'DibayarSebagian';
    case LewatTempo = 'LewatTempo';
    case Lunas = 'Lunas';
    case DihapusBuku = 'DihapusBuku';
    case Dibatalkan = 'Dibatalkan';

    /**
     * @param  StatusPiutang|null  $piutang  status piutang invoice (null = tidak berpiutang)
     * @param  string  $jatuhTempo  `YYYY-MM-DD`
     * @param  string  $hariIni  `YYYY-MM-DD`
     */
    public static function Tentukan(StatusInvoice $status, ?StatusPiutang $piutang, string $jatuhTempo, string $hariIni): self
    {
        if ($status === StatusInvoice::Draf) {
            return self::Draf;
        }

        if ($status === StatusInvoice::Dibatalkan) {
            return self::Dibatalkan;
        }

        return match ($piutang) {
            null, StatusPiutang::Lunas => self::Lunas,
            StatusPiutang::DihapusBuku => self::DihapusBuku,
            StatusPiutang::Dibatalkan => self::Dibatalkan,
            StatusPiutang::BelumLunas => $jatuhTempo < $hariIni ? self::LewatTempo : self::BelumDibayar,
            StatusPiutang::DibayarSebagian => $jatuhTempo < $hariIni ? self::LewatTempo : self::DibayarSebagian,
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::BelumDibayar => 'Belum dibayar',
            self::DibayarSebagian => 'Dibayar sebagian',
            self::LewatTempo => 'Lewat jatuh tempo',
            self::Lunas => 'Lunas',
            self::DihapusBuku => 'Dihapus-buku',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
