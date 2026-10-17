<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Layanan\PenyelarasSatuanProduk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * F-03 (BR-03.1): hapus satu barcode dari halaman detail produk. Jejak penghapusan (`PenghapusanKatalog`) ikut
 * tercatat supaya perangkat kasir membuang barcode itu di sinkron berikutnya. Produknya tidak berubah.
 */
final class HapusBarcodeProduk
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly PenyelarasSatuanProduk $penyelaras,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(ProdukBarcode $barcode): void
    {
        DB::transaction(function () use ($barcode): void {
            $this->penguncian->Kunci($this->konteks->Wajib());
            $barcode = ProdukBarcode::query()->whereKey($barcode->Id)->lockForUpdate()->first();

            if ($barcode === null) {
                return;
            }

            $this->audit->Catat('produk.barcode.hapus', $barcode, ['IdProduk' => $barcode->IdProduk, 'Barcode' => $barcode->Barcode], null);
            $this->penyelaras->HapusBarcode($barcode);
        });
    }
}
