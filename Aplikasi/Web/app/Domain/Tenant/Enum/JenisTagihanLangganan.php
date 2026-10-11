<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Jenis tagihan langganan. `Aktivasi`: mulai berlangganan berbayar (dari Trial, Gratis, Ditangguhkan, atau ganti
 * paket sebelum aktif), periode dimulai saat pembayaran diterima. `Perpanjangan`: paket yang sama saat langganan
 * Aktif/Tertunggak, periode baru menyambung dari akhir periode berjalan. `Addon` (D-49): pembelian add-on di tengah
 * periode berjalan (prorata sampai akhir periode); tidak mengubah paket, siklus, atau periode langganan.
 * `NaikPaket` (D-98): pindah ke paket yang lebih mahal di tengah periode; menagih selisih harga prorata sampai akhir
 * periode berjalan, lalu paket langsung berganti. Siklus dan periode langganan tidak berubah.
 */
enum JenisTagihanLangganan: string
{
    case Aktivasi = 'Aktivasi';
    case Perpanjangan = 'Perpanjangan';
    case Addon = 'Addon';
    case NaikPaket = 'NaikPaket';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Aktivasi => 'Aktivasi langganan',
            self::Perpanjangan => 'Perpanjangan langganan',
            self::Addon => 'Pembelian add-on',
            self::NaikPaket => 'Naik paket',
        };
    }

    /**
     * Tagihan tengah periode (add-on, naik paket): berumur pendek, tidak menyambung periode, tidak dapat pengingat
     * jatuh tempo, dibatalkan otomatis bila tak dibayar, dan tidak menghalangi tagihan perpanjangan.
     */
    public function CekTengahPeriode(): bool
    {
        return $this === self::Addon || $this === self::NaikPaket;
    }
}
