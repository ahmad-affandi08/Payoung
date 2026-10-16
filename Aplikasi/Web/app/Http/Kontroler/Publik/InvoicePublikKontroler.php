<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Penjualan\Aksi\CatatInvoiceDilihat;
use App\Domain\Penjualan\Kueri\InvoicePublik;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Halaman invoice publik `/i/{kodeInvoice}` (F-21), tanpa login. Tenant diambil dari kode lalu dipasang sebagai konteks
 * (seperti struk digital) sehingga pencarian tetap lewat scope `MilikTenant`; rahasia 32 karakter di kode menjadi satu-
 * satunya kunci. Kode yang salah, tenant yang ditutup, dan draf semuanya dijawab 404 yang sama. Pembukaan oleh staf yang
 * sedang masuk (pratinjau) tidak dihitung sebagai "dilihat pelanggan".
 */
final class InvoicePublikKontroler extends Kontroler
{
    public function Tampilkan(string $kodeInvoice, Request $permintaan, KonteksTenant $konteks, ProfilTenant $profil, InvoicePublik $kueri, CatatInvoiceDilihat $catat): SymfonyResponse
    {
        $kode = KodeInvoicePublik::Urai($kodeInvoice);
        $dokumen = null;

        if ($kode !== null && $profil->CekAda($kode['IdTenant'])) {
            $konteks->Atur($kode['IdTenant']);

            try {
                $invoice = $kueri->Cari($kode['KodePublik']);

                if ($invoice !== null) {
                    $dokumen = $kueri->Ambil($invoice);

                    if ($permintaan->user() === null) {
                        $catat->Jalankan($invoice);
                    }
                }
            } finally {
                $konteks->Kosongkan();
            }
        }

        return Inertia::render('Publik/InvoiceDigital', ['Dokumen' => $dokumen])
            ->toResponse($permintaan)
            ->setStatusCode($dokumen === null ? 404 : 200);
    }
}
