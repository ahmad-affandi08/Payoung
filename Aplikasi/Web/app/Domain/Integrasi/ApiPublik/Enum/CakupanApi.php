<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\ApiPublik\Enum;

/** X7 cakupan (scope) token API publik v1 (PRD §16.1): baca (bagian 1) dan tulis `stok:tulis` (bagian 4). */
enum CakupanApi: string
{
    case ProdukBaca = 'produk:baca';
    case StokBaca = 'stok:baca';
    case PenjualanBaca = 'penjualan:baca';
    case PelangganBaca = 'pelanggan:baca';
    case StokTulis = 'stok:tulis';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::ProdukBaca => 'Baca produk, satuan, barcode & harga jual',
            self::StokBaca => 'Baca saldo stok per lokasi',
            self::PenjualanBaca => 'Baca penjualan beserta baris & pembayaran',
            self::PelangganBaca => 'Baca data pelanggan (nama, nomor HP, email)',
            self::StokTulis => 'Buat penyesuaian stok (masuk/keluar) dari sistem lain; di atas batas nilai menunggu persetujuan',
        };
    }

    /** @return list<string> */
    public static function AmbilSemuaNilai(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
