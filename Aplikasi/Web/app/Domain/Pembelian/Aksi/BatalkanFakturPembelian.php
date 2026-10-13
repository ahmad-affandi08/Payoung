<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\NotaDebitPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan faktur pembelian (F-04 fase 1, CLAUDE.md #8) yang belum dibayar dan belum diretur: jurnal J-04.2 dibalik
 * (cermin, `KunciSumber = Pembatalan`) bertanggal hari bisnis outlet, GRN-nya kembali "belum difakturkan". Faktur
 * belanja stok hanya dibatalkan lewat pembatalan belanjanya (`BagianBelanjaStok`). Alasan 5–255 karakter. Audit
 * `faktur-pembelian.batalkan`.
 *
 * Urutan kunci: GRN (urut Id) → faktur.
 */
final class BatalkanFakturPembelian
{
    public function __construct(
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly BalikkanJurnal $balikkan,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, BagianBelanjaStok, SudahDibayar, AdaNotaDebit, SudahDiretur
     */
    public function Jalankan(FakturPembelian $faktur, string $alasan, int $idPengguna): FakturPembelian
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($faktur, $alasan, $idPengguna): FakturPembelian {
            $grn = PenerimaanBarang::query()->where('IdFakturPembelian', $faktur->Id)->orderBy('Id')->lockForUpdate()->get();
            $terkunci = FakturPembelian::query()->whereKey($faktur->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status === StatusFakturPembelian::Dibatalkan) {
                return $terkunci;
            }

            if ($terkunci->BelanjaStok) {
                throw new PelanggaranAturanBisnis('BagianBelanjaStok', 'Faktur ini bagian dari belanja stok. Batalkan belanja stoknya dari halaman penerimaan barang.');
            }

            if (! Uang::Dari($terkunci->JumlahDibayar)->BernilaiNol()) {
                throw new PelanggaranAturanBisnis('SudahDibayar', 'Faktur yang sudah dibayar tidak bisa dibatalkan. Batalkan pembayarannya dulu.');
            }

            if (NotaDebitPembelian::query()->where('IdFakturPembelian', $terkunci->Id)->where('Status', StatusDokumenTerposting::Diposting->value)->exists()) {
                throw new PelanggaranAturanBisnis('AdaNotaDebit', 'Faktur yang sudah punya nota debit tidak bisa dibatalkan. Batalkan nota debitnya dulu.');
            }

            if (! Uang::Dari($terkunci->JumlahRetur)->BernilaiNol()) {
                throw new PelanggaranAturanBisnis('SudahDiretur', 'Faktur yang sudah punya retur tidak bisa dibatalkan. Batalkan returnya dulu.');
            }

            $tanggal = $this->tanggalBisnis->Hitung($terkunci->IdOutlet);
            $jurnal = $terkunci->IdJurnal === null ? null : $this->balikkan->Jalankan(
                $terkunci->IdJurnal,
                $tanggal,
                mb_substr("Pembatalan faktur pembelian {$terkunci->Nomor}", 0, 255),
                JenisSumberJurnal::FakturPembelian,
                $terkunci->Id,
                'Pembatalan',
                $idPengguna,
            );

            foreach ($grn as $g) {
                $g->IdFakturPembelian = null;
                $g->save();
            }

            $asal = $terkunci->Status;
            $terkunci->UbahStatus(StatusFakturPembelian::Dibatalkan);
            $terkunci->fill(['IdJurnalPembatalan' => $jurnal?->idJurnal, 'AlasanBatal' => $alasan, 'DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now()])->save();

            $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $terkunci->Id, $asal->value, StatusFakturPembelian::Dibatalkan->value, $idPengguna, $alasan);
            $this->audit->Catat('faktur-pembelian.batalkan', $terkunci, ['Status' => $asal->value], [
                'Status' => StatusFakturPembelian::Dibatalkan->value,
                'Nomor' => $terkunci->Nomor,
                'Alasan' => $alasan,
                'NomorJurnalPembatalan' => $jurnal?->nomor,
            ], idPengguna: $idPengguna);

            return $terkunci;
        }, 3);
    }
}
