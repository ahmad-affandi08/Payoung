<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Aksi\SimpanPembayaranPiutang;
use App\Domain\Pelanggan\Data\DataPembayaranPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusKonfirmasiPembayaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\KonfirmasiPembayaranInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat pembayaran satu invoice terbit (F-21, D-166) yang diterima di luar tautan bayar (transfer, tunai, dan
 * sebagainya). Pelunasannya **memakai jalur pelunasan piutang F-12 yang sama** (`SimpanPembayaranPiutang`), jadi jurnal
 * (Dr kas/bank, Cr Piutang Usaha), nomor `BP/…`, periode terkunci, kuitansi, dan pembatalannya tidak punya versi kedua.
 *
 * `jumlah` = bagian tagihan yang dilunasi (boleh sebagian, paling banyak sisanya); `potonganPph` = bagian jumlah itu yang
 * dipotong pelanggan sebagai PPh dan dibuktikan dengan bukti potong, bukan uang yang masuk. Jumlah kosong = sisa penuh.
 *
 * `uuidKonfirmasi` (F-21 bagian 4): bila pembayaran ini menerima klaim "sudah bayar" dari pelanggan, klaim itu ditandai
 * Diterima dan ditautkan ke pelunasannya di transaksi yang sama (galat apa pun membatalkan keduanya).
 */
final class CatatPembayaranInvoice
{
    public function __construct(
        private readonly SimpanPembayaranPiutang $simpanPembayaran,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis InvoiceTidakDitemukan, InvoiceBelumTerbit, InvoiceTanpaPiutang, PiutangTidakTerbuka,
     *                                 KonfirmasiTidakBerlaku, plus galat pelunasan piutang (AkunKasBankWajib, MelebihiSisa, PphTidakValid,
     *                                 PeriodeTerkunci, …)
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
        ?string $uuidKonfirmasi = null,
    ): PembayaranPiutang {
        return DB::transaction(fn (): PembayaranPiutang => $this->Catat($uuidInvoice, $uuidAkun, $tanggal, $jumlah, $potonganPph, $nomorBuktiPotong, $catatan, $idPengguna, $uuidKonfirmasi));
    }

    private function Catat(
        string $uuidInvoice,
        string $uuidAkun,
        CarbonImmutable $tanggal,
        ?Uang $jumlah,
        Uang $potonganPph,
        ?string $nomorBuktiPotong,
        ?string $catatan,
        int $idPengguna,
        ?string $uuidKonfirmasi,
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
        $konfirmasi = $uuidKonfirmasi === null ? null : $this->KunciKonfirmasi($invoice, $uuidKonfirmasi);

        $pembayaran = $this->simpanPembayaran->Jalankan(new DataPembayaranPiutang(
            uuidPelanggan: $pelanggan->Uuid,
            uuidAkun: $uuidAkun,
            tanggal: $tanggal,
            alokasi: [$piutang->Uuid => $dilunasi],
            catatan: $catatan,
            idPengguna: $idPengguna,
            potonganPph: $potonganPph->BernilaiNol() ? [] : [$piutang->Uuid => $potonganPph],
            nomorBuktiPotong: $nomorBuktiPotong,
        ));

        if ($konfirmasi !== null) {
            $konfirmasi->UbahStatus(StatusKonfirmasiPembayaran::Diterima);
            $konfirmasi->fill(['DiputuskanOleh' => $idPengguna, 'DiputuskanPada' => CarbonImmutable::now(), 'IdPembayaranPiutang' => $pembayaran->Id])->save();
            $this->audit->Catat('invoice.konfirmasi-terima', $invoice, nilaiBaru: ['Nomor' => $invoice->Nomor, 'Jumlah' => $konfirmasi->Jumlah, 'NomorPembayaran' => $pembayaran->Nomor], idPengguna: $idPengguna);
        }

        return $pembayaran;
    }

    private function KunciKonfirmasi(Invoice $invoice, string $uuid): KonfirmasiPembayaranInvoice
    {
        $konfirmasi = KonfirmasiPembayaranInvoice::query()->where('Uuid', $uuid)->where('IdInvoice', $invoice->Id)->lockForUpdate()->first();

        if ($konfirmasi === null || $konfirmasi->Status !== StatusKonfirmasiPembayaran::Menunggu) {
            throw new PelanggaranAturanBisnis('KonfirmasiTidakBerlaku', 'Konfirmasi pembayaran itu tidak ditemukan atau sudah diputuskan. Muat ulang halaman.', 'Umum', 409);
        }

        return $konfirmasi;
    }
}
