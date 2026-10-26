<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Penjualan\Model\FakturPenjualan;
use Illuminate\Support\Facades\DB;

/**
 * Nomor Faktur Pajak (NSFP dari Coretax) di faktur grosir tercatat. Satu-satunya kolom faktur yang boleh berubah
 * setelah posting (`FakturPenjualan::AmbilKolomBolehBerubah`); dikunci barisnya dan dicatat nilai lama/baru di audit
 * karena nomor ini masuk ekspor XML Faktur Pajak Keluaran.
 */
final class UbahNomorFakturPajak
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(string $uuidFaktur, ?string $nomor, int $idPengguna): FakturPenjualan
    {
        return DB::transaction(function () use ($uuidFaktur, $nomor, $idPengguna): FakturPenjualan {
            $faktur = FakturPenjualan::query()->where('Uuid', $uuidFaktur)->lockForUpdate()->firstOrFail();

            if ($faktur->Status !== StatusDokumenTerposting::Diposting) {
                throw new PelanggaranAturanBisnis('FakturDibatalkan', 'Faktur yang sudah dibatalkan tidak bisa diubah.', 'Umum');
            }

            $lama = $faktur->NomorFakturPajak;

            if ($lama === $nomor) {
                return $faktur;
            }

            $faktur->NomorFakturPajak = $nomor;
            $faktur->DiubahOleh = $idPengguna;
            $faktur->save();
            $this->audit->Catat('grosir.faktur-nomor-pajak', $faktur, ['NomorFakturPajak' => $lama], ['Nomor' => $faktur->Nomor, 'NomorFakturPajak' => $nomor], idPengguna: $idPengguna);

            return $faktur;
        });
    }
}
