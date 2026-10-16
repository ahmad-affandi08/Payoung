<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Model\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan invoice (F-21, D-166), dengan alasan wajib.
 *
 * - **Draf**: cukup ditandai Dibatalkan (tanpa jurnal, tanpa nomor); invoice DP yang sempat dipesan dilepas.
 * - **Terbit**: jurnalnya dibalik (jurnal pembalik **bertanggal hari ini**, bukan tanggal invoice, supaya pembatalan
 *   jatuh di periode yang masih terbuka), piutangnya dibatalkan, dan invoice DP yang dipotongnya dilepas supaya bisa
 *   dipakai lagi. Ditolak bila piutangnya sudah dibayar walau sebagian (`PiutangSudahDibayar`): uang yang sudah masuk
 *   tidak boleh kehilangan dokumen dasarnya, batalkan dulu pelunasannya.
 * - **Invoice DP yang sudah dipotong** invoice akhir yang masih berlaku ditolak (`UangMukaSudahDipakai`): batalkan atau
 *   lepas dulu invoice akhirnya.
 */
final class BatalkanInvoice
{
    public const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly BalikkanJurnal $balikkanJurnal,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatPiutangPenjualan $pencatatPiutang,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanBatalWajib, InvoiceTidakDitemukan, PiutangSudahDibayar, UangMukaSudahDipakai, PeriodeTerkunci
     */
    public function Jalankan(string $uuidInvoice, string $alasan, int $idPengguna): Invoice
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_MINIMAL || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanBatalWajib', 'Alasan pembatalan wajib diisi, '.self::PANJANG_ALASAN_MINIMAL.' sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(fn (): Invoice => $this->Batalkan($uuidInvoice, $alasan, $idPengguna), max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    private function Batalkan(string $uuid, string $alasan, int $idPengguna): Invoice
    {
        $invoice = Invoice::query()->where('Uuid', $uuid)->lockForUpdate()->first()
            ?? throw new PelanggaranAturanBisnis('InvoiceTidakDitemukan', 'Invoice tidak ditemukan.');

        if ($invoice->Status === StatusInvoice::Dibatalkan) {
            return $invoice;
        }

        $asal = $invoice->Status;
        $jurnal = null;

        if ($asal === StatusInvoice::Terbit) {
            $this->PastikanBolehBatal($invoice);
            $jurnal = $invoice->IdJurnal === null ? null : $this->balikkanJurnal->Jalankan(
                $invoice->IdJurnal,
                $this->tanggalBisnis->Hitung($invoice->IdOutlet),
                mb_substr("Pembatalan invoice {$invoice->Nomor}", 0, 255),
                JenisSumberJurnal::Invoice,
                $invoice->Id,
                'Pembatalan',
                $idPengguna,
            );
            $this->pencatatPiutang->BatalkanInvoice($invoice->Id, $idPengguna);
        }

        // Invoice DP yang dipotong/dipesan invoice ini dilepas supaya bisa dipakai invoice lain.
        $dilepas = [];

        foreach (Invoice::query()->where('IdInvoiceAkhir', $invoice->Id)->orderBy('Id')->lockForUpdate()->get() as $dp) {
            $dilepas[] = $dp->Nomor;
            $dp->IdInvoiceAkhir = null;
            $dp->save();
        }

        $invoice->UbahStatus(StatusInvoice::Dibatalkan);
        $invoice->fill([
            'IdJurnalPembatalan' => $jurnal?->idJurnal,
            'AlasanBatal' => $alasan,
            'DibatalkanOleh' => $idPengguna,
            'DibatalkanPada' => CarbonImmutable::now(),
            'DiubahOleh' => $idPengguna,
        ])->save();

        $this->riwayat->Catat(Invoice::JENIS_DOKUMEN, $invoice->Id, $asal->value, StatusInvoice::Dibatalkan->value, $idPengguna, $alasan);
        $this->audit->Catat('invoice.batal', $invoice, nilaiLama: ['Status' => $asal->value], nilaiBaru: [
            'Status' => StatusInvoice::Dibatalkan->value,
            'Nomor' => $invoice->Nomor,
            'Alasan' => $alasan,
            'NomorJurnalPembatalan' => $jurnal?->nomor,
            'UangMukaDilepas' => $dilepas,
        ], idPengguna: $idPengguna);

        return $invoice;
    }

    private function PastikanBolehBatal(Invoice $invoice): void
    {
        if ($invoice->Jenis === JenisInvoice::UangMuka && $invoice->IdInvoiceAkhir !== null) {
            $akhir = Invoice::query()->whereKey($invoice->IdInvoiceAkhir)->first();

            if ($akhir !== null && $akhir->Status !== StatusInvoice::Dibatalkan) {
                throw new PelanggaranAturanBisnis(
                    'UangMukaSudahDipakai',
                    'Invoice uang muka ini sudah dipotong di invoice '.($akhir->Nomor ?? 'akhir (draf)').'. Batalkan atau lepas invoice akhir itu dulu.',
                );
            }
        }

        $halangan = $this->pencatatPiutang->PeriksaBisaBatalInvoice($invoice->Id);

        if ($halangan !== null) {
            throw new PelanggaranAturanBisnis('PiutangSudahDibayar', $halangan);
        }
    }
}
