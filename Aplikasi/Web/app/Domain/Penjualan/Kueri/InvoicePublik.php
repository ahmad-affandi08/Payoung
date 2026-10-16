<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Carbon\CarbonImmutable;

/**
 * Invoice untuk halaman publik `/i/{kodeInvoice}` (F-21), di dalam konteks tenant yang sudah dipasang pemanggil. Hanya
 * invoice terbit atau dibatalkan yang tampil (draf belum menjadi tagihan, jadi 404); invoice yang dibatalkan tetap
 * dibuka dengan tanda "Dibatalkan" supaya pelanggan yang menyimpan tautan lama tidak membayar tagihan yang sudah gugur.
 */
final class InvoicePublik
{
    public function __construct(
        private readonly DokumenInvoice $dokumen,
        private readonly ProfilTenant $profil,
    ) {}

    public function Cari(string $kodePublik): ?Invoice
    {
        $invoice = Invoice::query()->where('KodePublik', $kodePublik)->first();

        return $invoice !== null && $invoice->Status !== StatusInvoice::Draf ? $invoice : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function Ambil(Invoice $invoice): array
    {
        $zona = $this->profil->Ambil($invoice->IdTenant)['ZonaWaktu'];

        return $this->dokumen->Ambil($invoice, CarbonImmutable::now($zona)->toDateString(), untukPublik: true);
    }
}
