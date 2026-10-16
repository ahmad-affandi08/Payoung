<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Status penawaran yang **ditampilkan** (diturunkan, bukan kolom): menambahkan `Kedaluwarsa` (terbit tetapi lewat masa
 * berlaku tanpa keputusan) dan `DiInvoicekan` (diterima dan sudah menjadi draf/terbit invoice) ke `StatusPenawaran`.
 * Satu tempat ini dipakai daftar, detail, halaman publik, dan Kotak Tindakan supaya tafsirnya sama.
 */
enum StatusTampilPenawaran: string
{
    case Draf = 'Draf';
    case MenungguKeputusan = 'MenungguKeputusan';
    case Diterima = 'Diterima';
    case DiInvoicekan = 'DiInvoicekan';
    case Ditolak = 'Ditolak';
    case Kedaluwarsa = 'Kedaluwarsa';
    case Dibatalkan = 'Dibatalkan';

    /**
     * @param  bool  $punyaInvoiceAktif  `IdInvoice` terisi dan invoice itu belum dibatalkan
     * @param  string  $berlakuSampai  `YYYY-MM-DD` (hari terakhir penawaran masih berlaku)
     * @param  string  $hariIni  `YYYY-MM-DD`
     */
    public static function Tentukan(StatusPenawaran $status, bool $punyaInvoiceAktif, string $berlakuSampai, string $hariIni): self
    {
        return match ($status) {
            StatusPenawaran::Draf => self::Draf,
            StatusPenawaran::Terbit => $berlakuSampai < $hariIni ? self::Kedaluwarsa : self::MenungguKeputusan,
            StatusPenawaran::Diterima => $punyaInvoiceAktif ? self::DiInvoicekan : self::Diterima,
            StatusPenawaran::Ditolak => self::Ditolak,
            StatusPenawaran::Dibatalkan => self::Dibatalkan,
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::MenungguKeputusan => 'Menunggu keputusan',
            self::Diterima => 'Diterima',
            self::DiInvoicekan => 'Sudah jadi invoice',
            self::Ditolak => 'Ditolak',
            self::Kedaluwarsa => 'Kedaluwarsa',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
