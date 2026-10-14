<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Model\FakturPajakPenjualan;
use Illuminate\Support\Facades\DB;

/**
 * Nomor Faktur Pajak (NSFP dari Coretax) pada Faktur Pajak per transaksi kasir (TAX-07). Nomor ini baru ada setelah
 * faktur diunggah dan disetujui di Coretax, jadi diisi kemudian; dicatat nilai lama/baru di audit karena menjadi rujukan
 * nota retur pajak.
 */
final class UbahNomorFakturPajakPenjualan
{
    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     *
     * @throws PelanggaranAturanBisnis FakturPajakTidakDitemukan, FakturPajakDibatalkan
     */
    public function Jalankan(string $uuid, ?string $nomor, int $idPengguna, ?array $idOutletBoleh): FakturPajakPenjualan
    {
        return DB::transaction(function () use ($uuid, $nomor, $idPengguna, $idOutletBoleh): FakturPajakPenjualan {
            $faktur = FakturPajakPenjualan::query()->where('Uuid', $uuid)->lockForUpdate()->first();

            if ($faktur === null || ($idOutletBoleh !== null && ! in_array($faktur->IdOutlet, $idOutletBoleh, true))) {
                throw new PelanggaranAturanBisnis('FakturPajakTidakDitemukan', 'Faktur Pajak tidak ditemukan.', 'Umum');
            }

            if ($faktur->Status !== StatusDokumenTerposting::Diposting) {
                throw new PelanggaranAturanBisnis('FakturPajakDibatalkan', 'Faktur Pajak yang sudah dibatalkan tidak bisa diubah.', 'Umum');
            }

            $nomor = $nomor === null || trim($nomor) === '' ? null : trim($nomor);
            $lama = $faktur->NomorFakturPajak;

            if ($lama === $nomor) {
                return $faktur;
            }

            $faktur->NomorFakturPajak = $nomor;
            $faktur->DiubahOleh = $idPengguna;
            $faktur->save();
            $this->audit->Catat('penjualan.faktur-pajak.nomor', $faktur, ['NomorFakturPajak' => $lama], ['NomorFakturPajak' => $nomor], idPengguna: $idPengguna);

            return $faktur;
        });
    }
}
