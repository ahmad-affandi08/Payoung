<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pelanggan\Kueri\IdentitasPajakPelanggan;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Layanan\PenyusunFakturPajakCoretax;
use App\Domain\Penjualan\Layanan\PenyusunFakturPajakPenjualan;
use App\Domain\Penjualan\Model\FakturPajakPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Menerbitkan Faktur Pajak atas satu transaksi kasir yang diminta pembeli ber-NPWP/NIK (TAX-07, PRD v6.27, D-147).
 * Tanpa dampak stok & jurnal. Penjualan yang tidak bisa diekspor ke Coretax ditolak sejak awal dengan semua alasannya
 * (`PenyusunFakturPajakPenjualan`), supaya kasir tidak menjanjikan faktur yang kemudian tidak bisa dibuat. Satu
 * penjualan hanya punya satu Faktur Pajak aktif; mengulang permintaan untuk pelanggan yang sama mengembalikan
 * yang sudah ada (idempoten), pelanggan lain ditolak sampai yang lama dibatalkan.
 */
final class TerbitkanFakturPajakPenjualan
{
    public function __construct(
        private readonly PenyusunFakturPajakPenjualan $penyusun,
        private readonly PenyusunFakturPajakCoretax $coretax,
        private readonly IdentitasPelanggan $pelanggan,
        private readonly IdentitasPajakPelanggan $identitasPajak,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     *
     * @throws PelanggaranAturanBisnis PenjualanTidakDitemukan, PelangganTidakDitemukan, UsahaBukanPkp, FakturPajakTidakBisaDibuat, FakturPajakSudahAda
     */
    public function Jalankan(string $uuidPenjualan, string $uuidPelanggan, ?string $catatan, int $idPengguna, ?array $idOutletBoleh): FakturPajakPenjualan
    {
        $penjualan = Penjualan::query()->where('Uuid', $uuidPenjualan)->first();

        if ($penjualan === null || ($idOutletBoleh !== null && ! in_array($penjualan->IdOutlet, $idOutletBoleh, true))) {
            throw new PelanggaranAturanBisnis('PenjualanTidakDitemukan', 'Penjualan tidak ditemukan.', 'Umum');
        }

        $idPelanggan = $this->pelanggan->CariId($uuidPelanggan)
            ?? throw new PelanggaranAturanBisnis('PelangganTidakDitemukan', 'Pelanggan tidak ditemukan.', 'UuidPelanggan');

        [, $masalahPenjual] = $this->coretax->PeriksaPenjual();

        if ($masalahPenjual !== []) {
            throw new PelanggaranAturanBisnis('UsahaBukanPkp', implode(' ', $masalahPenjual), 'Umum');
        }

        $hasil = $this->penyusun->Susun($penjualan, $this->identitasPajak->AmbilBanyak([$idPelanggan])[$idPelanggan] ?? null);

        if ($hasil['Faktur'] === null) {
            throw new PelanggaranAturanBisnis('FakturPajakTidakBisaDibuat', 'Faktur Pajak belum bisa dibuat: '.implode(' ', $hasil['Masalah']), 'Umum');
        }

        $catatan = $catatan === null || trim($catatan) === '' ? null : mb_substr(trim($catatan), 0, 500);

        try {
            return DB::transaction(function () use ($penjualan, $idPelanggan, $catatan, $idPengguna): FakturPajakPenjualan {
                $aktif = FakturPajakPenjualan::query()->where('IdPenjualan', $penjualan->Id)
                    ->where('Status', StatusDokumenTerposting::Diposting->value)->lockForUpdate()->first();

                if ($aktif !== null) {
                    if ($aktif->IdPelanggan === $idPelanggan) {
                        return $aktif;
                    }

                    throw new PelanggaranAturanBisnis('FakturPajakSudahAda', 'Penjualan ini sudah punya Faktur Pajak untuk pelanggan lain. Batalkan dulu bila salah pelanggan.', 'Umum');
                }

                $faktur = FakturPajakPenjualan::query()->create([
                    'IdOutlet' => $penjualan->IdOutlet,
                    'IdPenjualan' => $penjualan->Id,
                    'IdPelanggan' => $idPelanggan,
                    'Tanggal' => $penjualan->TanggalBisnis->toDateString(),
                    'Catatan' => $catatan,
                    'DibuatOleh' => $idPengguna,
                ]);
                $this->riwayat->Catat(FakturPajakPenjualan::JENIS_DOKUMEN, $faktur->Id, null, StatusDokumenTerposting::Diposting->value, $idPengguna);
                $this->audit->Catat('penjualan.faktur-pajak.terbit', $faktur, nilaiBaru: [
                    'NomorPenjualan' => $penjualan->Nomor,
                    'UuidPelanggan' => $this->pelanggan->AmbilUuid($idPelanggan),
                ], idPengguna: $idPengguna);

                return $faktur;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua permintaan berbarengan: pemenang sudah menulis, kita bandingkan hasilnya.
            $ada = FakturPajakPenjualan::query()->where('IdPenjualan', $penjualan->Id)->where('Status', StatusDokumenTerposting::Diposting->value)->firstOrFail();

            if ($ada->IdPelanggan !== $idPelanggan) {
                throw new PelanggaranAturanBisnis('FakturPajakSudahAda', 'Penjualan ini sudah punya Faktur Pajak untuk pelanggan lain. Batalkan dulu bila salah pelanggan.', 'Umum');
            }

            return $ada;
        }
    }
}
