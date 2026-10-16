<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Enum\JenisPengirimanInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;

/**
 * Aksi massal daftar invoice (F-21): terbitkan draf terpilih, kirim invoice ke WhatsApp, atau kirim pengingat. Berbeda dari
 * dokumen yang saling bergantung, tiap invoice berdiri sendiri, jadi kegagalan satu invoice (tanpa nomor HP, periode
 * terkunci, sudah lunas) **tidak membatalkan yang lain**: invoice diproses satu per satu dan hasilnya dirangkum menjadi
 * jumlah berhasil dan alasan yang dilewati. Tiap invoice tetap lewat Aksi satuannya, sehingga semua aturan (periode
 * terkunci, jeda kirim, nomor sah) berlaku sama dengan aksi satuan.
 */
final class ProsesInvoiceMassal
{
    public const MAKS = 50;

    public const AKSI_TERBITKAN = 'terbitkan';

    public const AKSI_KIRIM = 'kirim';

    public const AKSI_INGATKAN = 'ingatkan';

    public function __construct(
        private readonly TerbitkanInvoice $terbitkan,
        private readonly AntrekanPengirimanInvoice $antrekan,
    ) {}

    /**
     * @param  list<string>  $uuid
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return array{Berhasil: int, Dilewati: array<string, int>}
     *
     * @throws PelanggaranAturanBisnis AksiTidakDikenal, PilihanKosong, TerlaluBanyak, InvoiceTidakDikenal
     */
    public function Jalankan(string $aksi, int $idTenant, array $uuid, int $idPengguna, ?array $idOutletBoleh = null): array
    {
        if (! in_array($aksi, [self::AKSI_TERBITKAN, self::AKSI_KIRIM, self::AKSI_INGATKAN], true)) {
            throw new PelanggaranAturanBisnis('AksiTidakDikenal', 'Aksi massal tidak dikenal.', 'Aksi');
        }

        $uuid = array_values(array_unique($uuid));

        if ($uuid === []) {
            throw new PelanggaranAturanBisnis('PilihanKosong', 'Pilih minimal satu invoice.', 'Uuid');
        }

        if (count($uuid) > self::MAKS) {
            throw new PelanggaranAturanBisnis('TerlaluBanyak', 'Maksimal '.self::MAKS.' invoice sekali proses.', 'Uuid');
        }

        $invoice = Invoice::query()->whereIn('Uuid', $uuid)->orderBy('Id')->get();
        $diizinkan = $idOutletBoleh === null ? $invoice : $invoice->filter(fn (Invoice $i): bool => in_array($i->IdOutlet, $idOutletBoleh, true));

        if ($diizinkan->count() !== count($uuid)) {
            throw new PelanggaranAturanBisnis('InvoiceTidakDikenal', 'Sebagian invoice tidak ditemukan. Muat ulang halaman.', 'Uuid');
        }

        $berhasil = 0;
        $dilewati = [];

        foreach ($diizinkan as $satu) {
            try {
                match ($aksi) {
                    self::AKSI_TERBITKAN => $this->Terbitkan($satu, $idPengguna),
                    self::AKSI_KIRIM => $this->antrekan->Jalankan($idTenant, $satu, $idPengguna),
                    default => $this->antrekan->Jalankan($idTenant, $satu, $idPengguna, JenisPengirimanInvoice::Pengingat),
                };
                $berhasil++;
            } catch (PelanggaranAturanBisnis $galat) {
                $dilewati[$galat->getMessage()] = ($dilewati[$galat->getMessage()] ?? 0) + 1;
            }
        }

        return ['Berhasil' => $berhasil, 'Dilewati' => $dilewati];
    }

    private function Terbitkan(Invoice $invoice, int $idPengguna): void
    {
        if ($invoice->Status !== StatusInvoice::Draf) {
            throw new PelanggaranAturanBisnis('BukanDraf', 'Hanya draf yang bisa diterbitkan.', 'Uuid');
        }

        $this->terbitkan->Jalankan($invoice->Uuid, $idPengguna);
    }
}
