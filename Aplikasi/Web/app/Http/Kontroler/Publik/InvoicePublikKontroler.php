<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Publik;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\UrlLogoPublik;
use App\Domain\Penjualan\Aksi\CatatInvoiceDilihat;
use App\Domain\Penjualan\Aksi\KirimKonfirmasiPembayaranInvoice;
use App\Domain\Penjualan\Kueri\DokumenKuitansiInvoice;
use App\Domain\Penjualan\Kueri\InvoicePublik;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Permintaan\Publik\KonfirmasiPembayaranInvoicePermintaan;
use Illuminate\Http\RedirectResponse;
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
    public function Tampilkan(string $kodeInvoice, Request $permintaan, KonteksTenant $konteks, ProfilTenant $profil, InvoicePublik $kueri, CatatInvoiceDilihat $catat, UrlLogoPublik $logo): SymfonyResponse
    {
        $kode = KodeInvoicePublik::Urai($kodeInvoice);
        $dokumen = null;
        $urlLogo = null;

        if ($kode !== null && $profil->CekAda($kode['IdTenant'])) {
            $konteks->Atur($kode['IdTenant']);

            try {
                $invoice = $kueri->Cari($kode['KodePublik']);

                if ($invoice !== null) {
                    $dokumen = $kueri->Ambil($invoice);
                    $urlLogo = $logo->Ambil($kode['IdTenant']);

                    if ($permintaan->user() === null) {
                        $catat->Jalankan($invoice);
                    }
                }
            } finally {
                $konteks->Kosongkan();
            }
        }

        return Inertia::render('Publik/InvoiceDigital', ['Dokumen' => $dokumen, 'KodeInvoice' => $dokumen === null ? null : $kodeInvoice, 'UrlLogo' => $urlLogo])
            ->toResponse($permintaan)
            ->setStatusCode($dokumen === null ? 404 : 200);
    }

    /**
     * Kuitansi satu pembayaran invoice (F-21 bagian 5), tanpa login. Kuncinya kode rahasia invoice; Uuid pembayaran hanya
     * memilih pembayaran mana. Kode salah, draf, dan pembayaran yang bukan milik invoice ini dijawab 404 yang sama.
     */
    public function Kuitansi(string $kodeInvoice, string $pembayaran, Request $permintaan, KonteksTenant $konteks, ProfilTenant $profil, InvoicePublik $kueri, DokumenKuitansiInvoice $dokumenKuitansi, UrlLogoPublik $logo): SymfonyResponse
    {
        $kode = KodeInvoicePublik::Urai($kodeInvoice);
        $dokumen = null;
        $urlLogo = null;

        if ($kode !== null && $profil->CekAda($kode['IdTenant'])) {
            $konteks->Atur($kode['IdTenant']);

            try {
                $invoice = $kueri->Cari($kode['KodePublik']);
                $dokumen = $invoice === null ? null : $dokumenKuitansi->Ambil($invoice, $pembayaran, untukPublik: true);
                $urlLogo = $dokumen === null ? null : $logo->Ambil($kode['IdTenant']);
            } finally {
                $konteks->Kosongkan();
            }
        }

        return Inertia::render('Publik/KuitansiInvoice', ['Dokumen' => $dokumen, 'KodeInvoice' => $kodeInvoice, 'UrlLogo' => $urlLogo])
            ->toResponse($permintaan)
            ->setStatusCode($dokumen === null ? 404 : 200);
    }

    /**
     * Pelanggan menyatakan sudah membayar (F-21 bagian 4, D-169): hanya klaim yang menunggu verifikasi penagih, tidak
     * membukukan apa pun. Kode salah, draf, dan usaha yang ditutup dijawab 404 yang sama; pelanggaran aturan bisnis
     * (sisa, tanggal, batas klaim) kembali sebagai galat bidang lewat penangan galat umum.
     */
    public function KonfirmasiBayar(string $kodeInvoice, KonfirmasiPembayaranInvoicePermintaan $permintaan, KonteksTenant $konteks, ProfilTenant $profil, InvoicePublik $kueri, KirimKonfirmasiPembayaranInvoice $kirim, PencatatAudit $audit): RedirectResponse
    {
        // Pelaku publik tidak punya akun: audit mencatat pelaku kosong beserta IP/agen untuk menelusuri penyalahgunaan.
        $audit->AturKonteks(null, $permintaan->ip(), $permintaan->userAgent());
        $kode = KodeInvoicePublik::Urai($kodeInvoice);
        abort_if($kode === null || ! $profil->CekAda($kode['IdTenant']), 404);
        $konteks->Atur($kode['IdTenant']);

        try {
            $invoice = $kueri->Cari($kode['KodePublik']);
            abort_if($invoice === null, 404);
            $kirim->Jalankan($invoice->Id, (string) $permintaan->validated('Nama'), $permintaan->AmbilTanggal(), $permintaan->AmbilJumlah(), $permintaan->validated('Catatan'), $permintaan->AmbilBukti());
        } finally {
            $konteks->Kosongkan();
        }

        return back()->with('Kilat', 'Terima kasih, konfirmasi pembayaran Anda sudah kami terima. Penjual akan memeriksanya.');
    }
}
