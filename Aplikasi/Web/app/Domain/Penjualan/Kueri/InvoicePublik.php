<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusKonfirmasiPembayaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\KonfirmasiPembayaranInvoice;
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
        $hariIni = CarbonImmutable::now($zona)->toDateString();

        return [
            ...$this->dokumen->Ambil($invoice, $hariIni, untukPublik: true),
            'Konfirmasi' => $this->Konfirmasi($invoice, $hariIni),
        ];
    }

    /**
     * Formulir "sudah bayar" (F-21 bagian 4): tersedia selama invoice terbit dan masih bersisa. Klaim yang menunggu
     * ditampilkan hanya tanggal dan jumlahnya (bukan nama pengirim), supaya pembuka tautan tahu klaimnya sudah diterima.
     *
     * @return array{Bisa: bool, Sisa: string|null, HariIni: string, Menunggu: list<array{TanggalBayar: string, Jumlah: string}>}
     */
    private function Konfirmasi(Invoice $invoice, string $hariIni): array
    {
        $piutang = $invoice->Status === StatusInvoice::Terbit ? Piutang::query()->where('IdInvoice', $invoice->Id)->first() : null;
        $bisa = $piutang !== null && $piutang->Status->CekTerbuka();

        return [
            'Bisa' => $bisa,
            'Sisa' => $bisa ? $piutang->AmbilSisa()->KeString() : null,
            'HariIni' => $hariIni,
            'Menunggu' => $bisa ? array_values(KonfirmasiPembayaranInvoice::query()->where('IdInvoice', $invoice->Id)->where('Status', StatusKonfirmasiPembayaran::Menunggu->value)->orderBy('Id')->get()->map(fn (KonfirmasiPembayaranInvoice $k): array => [
                'TanggalBayar' => $k->TanggalBayar->format('Y-m-d'),
                'Jumlah' => $k->Jumlah,
            ])->all()) : [],
        ];
    }
}
