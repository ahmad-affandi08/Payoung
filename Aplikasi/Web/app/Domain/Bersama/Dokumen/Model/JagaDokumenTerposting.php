<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Dokumen\Model;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Penjaga aturan #8 di tingkat model: dokumen tidak pernah dihapus, dan setelah dikonfirmasi/diposting hanya kolom yang
 * disebut `AmbilKolomBolehBerubah()` yang boleh berubah. Null = semua kolom boleh berubah (masih draf).
 *
 * Rumah bersama untuk pola yang tadinya disalin tiga kali: `Pembelian\Model\JagaDokumenPembelian`,
 * `Penjualan\Model\JagaDokumenGrosir`, dan penjaga sebaris di `Pencairan`. Catatan di kedua trait lama sudah
 * berjanji menyatukannya begitu domain ketiga membutuhkannya; janji itu ditunaikan di sini. Tiga salinan dari aturan yang sama
 * adalah tiga tempat yang bisa menyimpang sendiri — dan yang menyimpang di sini berarti dokumen keuangan yang sudah
 * diposting bisa diubah di satu modul tetapi tidak di modul lain.
 *
 * Pesannya menyebut "dikonfirmasi/diposting" karena pemakainya dua macam: dokumen yang punya tahap draf (pesanan
 * pembelian & pesanan grosir, yang mengunci kolomnya setelah dikonfirmasi) dan dokumen yang langsung diposting saat
 * disimpan (penerimaan, faktur, retur, surat jalan, pembayaran, pencairan).
 */
trait JagaDokumenTerposting
{
    public static function bootJagaDokumenTerposting(): void
    {
        static::updating(function (Model $dokumen): void {
            /** @var self $dokumen */
            $boleh = $dokumen->AmbilKolomBolehBerubah();

            if ($boleh === null) {
                return;
            }

            $terlarang = array_diff(array_keys($dokumen->getDirty()), [...$boleh, 'DiubahPada']);

            if ($terlarang !== []) {
                throw new LogicException('Dokumen '.$dokumen->getTable().' yang sudah dikonfirmasi/diposting tidak bisa diubah: '.implode(', ', $terlarang).'. Koreksi lewat dokumen pembalik.');
            }
        });

        static::deleting(function (Model $dokumen): void {
            throw new LogicException('Dokumen '.$dokumen->getTable().' tidak pernah dihapus. Koreksi lewat pembatalan (dokumen pembalik).');
        });
    }

    /**
     * @return list<string>|null
     */
    abstract public function AmbilKolomBolehBerubah(): ?array;
}
