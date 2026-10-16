<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\Penawaran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan penawaran (F-21 bagian 3, D-168) dengan alasan wajib. Boleh dari Draf, Menunggu keputusan, atau Diterima.
 * Penawaran yang sudah dijadikan invoice yang masih berlaku (draf atau terbit) ditolak: batalkan invoicenya dulu supaya
 * tidak ada invoice yatim yang merujuk penawaran gugur. Membatalkan yang sudah dibatalkan mengembalikannya (idempoten).
 */
final class BatalkanPenawaran
{
    public const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanBatalWajib, PenawaranTidakDitemukan, PenawaranTidakBisaDibatalkan, PenawaranSudahDiInvoicekan
     */
    public function Jalankan(string $uuidPenawaran, string $alasan, int $idPengguna): Penawaran
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_MINIMAL || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanBatalWajib', 'Alasan pembatalan wajib diisi, '.self::PANJANG_ALASAN_MINIMAL.' sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($uuidPenawaran, $alasan, $idPengguna): Penawaran {
            $penawaran = Penawaran::query()->where('Uuid', $uuidPenawaran)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('PenawaranTidakDitemukan', 'Penawaran tidak ditemukan.');

            if ($penawaran->Status === StatusPenawaran::Dibatalkan) {
                return $penawaran;
            }

            if (! $penawaran->Status->BisaBerubahKe(StatusPenawaran::Dibatalkan)) {
                throw new PelanggaranAturanBisnis('PenawaranTidakBisaDibatalkan', "Penawaran yang sudah {$penawaran->Status->AmbilLabel()} tidak bisa dibatalkan.");
            }

            if ($penawaran->IdInvoice !== null) {
                $invoice = Invoice::query()->whereKey($penawaran->IdInvoice)->first();

                if ($invoice !== null && $invoice->Status !== StatusInvoice::Dibatalkan) {
                    throw new PelanggaranAturanBisnis('PenawaranSudahDiInvoicekan', 'Penawaran ini sudah dijadikan invoice '.($invoice->Nomor ?? '(draf)').'. Batalkan invoice itu dulu.');
                }
            }

            $asal = $penawaran->Status;
            $penawaran->UbahStatus(StatusPenawaran::Dibatalkan);
            $penawaran->fill([
                'AlasanBatal' => $alasan,
                'DibatalkanOleh' => $idPengguna,
                'DibatalkanPada' => CarbonImmutable::now(),
                'DiubahOleh' => $idPengguna,
            ])->save();

            $this->riwayat->Catat(Penawaran::JENIS_DOKUMEN, $penawaran->Id, $asal->value, StatusPenawaran::Dibatalkan->value, $idPengguna, $alasan);
            $this->audit->Catat('penawaran.batal', $penawaran, nilaiLama: ['Status' => $asal->value], nilaiBaru: [
                'Status' => StatusPenawaran::Dibatalkan->value,
                'Nomor' => $penawaran->Nomor,
                'Alasan' => $alasan,
            ], idPengguna: $idPengguna);

            return $penawaran;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
