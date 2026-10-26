<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Dokumen\Enum;

/**
 * Status dokumen yang **langsung dicatat** saat disimpan dan hanya bisa dikoreksi lewat dokumen pembalik (aturan #8):
 * `Diposting → Dibatalkan`, dan `Dibatalkan` final.
 *
 * Dipakai oleh **seluruh** dokumen langsung-posting di repo ini:
 * - pembelian (F-04): penerimaan barang, faktur pembelian, pembayaran hutang, retur pembelian;
 * - grosir (F-12, §9.7): surat jalan (BR-12.2), faktur penjualan (BR-12.4), retur grosir (BR-12.7);
 * - pembayaran (F-08): pencairan dana (BR-08.4).
 *
 * Sebelumnya `Pembelian\Enum\StatusDokumenPembelian` dan `Penjualan\Enum\StatusDokumenGrosir` adalah dua salinan
 * identik dari aturan ini, dan pencairan akan menjadi salinan ketiga. Catatan di `JagaDokumenPembelian` &
 * `JagaDokumenGrosir` sudah berjanji menyatukannya begitu domain ketiga membutuhkannya; janji itu ditunaikan di sini.
 * Nilai tersimpannya sama persis ('Diposting'/'Dibatalkan'), jadi penyatuannya tanpa migrasi.
 *
 * **Status pembayaran tidak ada di sini**, dan itu disengaja: sisa tagihan, umur, dan pelunasan adalah milik `Piutang`
 * (BR-12.5) atau `Hutang`; menyalinnya ke dokumen berarti dua sumber kebenaran yang bisa berbeda begitu pelunasannya
 * dibatalkan.
 */
enum StatusDokumenTerposting: string
{
    case Diposting = 'Diposting';
    case Dibatalkan = 'Dibatalkan';

    public function BisaBerubahKe(self $tujuan): bool
    {
        return $this === self::Diposting && $tujuan === self::Dibatalkan;
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Diposting => 'Tercatat',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
