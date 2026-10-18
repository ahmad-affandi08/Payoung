<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran;

/**
 * Hal yang boleh diminta ke sebuah gerbang QRIS (D-179). Penyedia berbeda-beda kemampuannya; kode pemanggil bertanya
 * `GerbangPembayaran::CekMendukung()` dulu, bukan menebak dari nama penyedia. Kemampuan yang tidak didukung ditolak
 * dengan `GalatGerbang` yang pesannya jelas, bukan menunggu galat HTTP.
 */
enum KemampuanGerbang: string
{
    /** Membuat QRIS dinamis (`BuatQris`) dan mengecek statusnya (`CekStatus`). Semua adaptor wajib. */
    case QrisDinamis = 'QrisDinamis';

    /** `CekStatus` cukup dengan nomor pesanan, tanpa id referensi dari gerbang (audit P0 F-02). */
    case CekStatusDariNomorPesanan = 'CekStatusDariNomorPesanan';

    /** Mengembalikan uang atas pembayaran yang sudah lunas (`Refund`). */
    case Refund = 'Refund';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::QrisDinamis => 'QRIS dinamis',
            self::CekStatusDariNomorPesanan => 'Cek status dari nomor pesanan',
            self::Refund => 'Pengembalian dana',
        };
    }
}
