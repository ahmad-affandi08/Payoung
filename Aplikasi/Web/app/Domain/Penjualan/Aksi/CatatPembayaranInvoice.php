<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Aksi\SimpanPembayaranPiutang;
use App\Domain\Pelanggan\Data\DataPembayaranPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use Carbon\CarbonImmutable;

/**
 * Mencatat pembayaran satu invoice terbit (F-21, D-166) yang diterima di luar tautan bayar (transfer, tunai, dan
 * sebagainya). Pelunasannya **memakai jalur pelunasan piutang F-12 yang sama** (`SimpanPembayaranPiutang`), jadi jurnal
 * (Dr kas/bank, Cr Piutang Usaha), nomor `BP/…`, periode terkunci, kuitansi, dan pembatalannya tidak punya versi kedua.
 *
 * `jumlah` = bagian tagihan yang dilunasi (boleh sebagian, paling banyak sisanya); `potonganPph` = bagian jumlah itu yang
 * dipotong pelanggan sebagai PPh dan dibuktikan dengan bukti potong, bukan uang yang masuk. Jumlah kosong = sisa penuh.
 */
final class CatatPembayaranInvoice
{
    public function __construct(private readonly SimpanPembayaranPiutang $simpanPembayaran) {}

    /**
     * @throws PelanggaranAturanBisnis InvoiceTidakDitemukan, InvoiceBelumTerbit, InvoiceTanpaPiutang, PiutangTidakTerbuka,
     *                                 plus galat pelunasan piutang (AkunKasBankWajib, MelebihiSisa, PphTidakValid, PeriodeTerkunci, …)
     */
    public function Jalankan(
        string $uuidInvoice,
        string $uuidAkun,
        CarbonImmutable $tanggal,
        ?Uang $jumlah,
        Uang $potonganPph,
        ?string $nomorBuktiPotong,
        ?string $catatan,
        int $idPengguna,
    ): PembayaranPiutang {
        $invoice = Invoice::query()->where('Uuid', $uuidInvoice)->first()
            ?? throw new PelanggaranAturanBisnis('InvoiceTidakDitemukan', 'Invoice tidak ditemukan.');

        if ($invoice->Status !== StatusInvoice::Terbit) {
            throw new PelanggaranAturanBisnis('InvoiceBelumTerbit', 'Pembayaran hanya bisa dicatat untuk invoice yang sudah terbit dan belum dibatalkan.');
        }

        $piutang = Piutang::query()->where('IdInvoice', $invoice->Id)->first()
            ?? throw new PelanggaranAturanBisnis('InvoiceTanpaPiutang', "Invoice {$invoice->Nomor} tidak punya tagihan (sudah tertutup uang muka), jadi tidak ada yang perlu dibayar.");

        if (! $piutang->Status->CekTerbuka()) {
            throw new PelanggaranAturanBisnis('PiutangTidakTerbuka', "Tagihan invoice {$invoice->Nomor} sudah {$piutang->Status->AmbilLabel()}.");
        }

        $pelanggan = Pelanggan::query()->whereKey($invoice->IdPelanggan)->firstOrFail();
        $dilunasi = $jumlah ?? $piutang->AmbilSisa();

        return $this->simpanPembayaran->Jalankan(new DataPembayaranPiutang(
            uuidPelanggan: $pelanggan->Uuid,
            uuidAkun: $uuidAkun,
            tanggal: $tanggal,
            alokasi: [$piutang->Uuid => $dilunasi],
            catatan: $catatan,
            idPengguna: $idPengguna,
            potonganPph: $potonganPph->BernilaiNol() ? [] : [$piutang->Uuid => $potonganPph],
            nomorBuktiPotong: $nomorBuktiPotong,
        ));
    }
}
