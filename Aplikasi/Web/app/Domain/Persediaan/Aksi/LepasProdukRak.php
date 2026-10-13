<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Layanan\PemeriksaOpnameRak;
use App\Domain\Persediaan\Model\ProdukRak;
use App\Domain\Persediaan\Model\RakGudang;
use Illuminate\Support\Facades\DB;

/**
 * F-05c INV-07: melepas produk dari rak (produk kembali tanpa rak di lokasi stok itu). Ditolak selama opname per rak itu
 * berjalan. Idempoten. Audit `rak-gudang.lepas-produk`.
 */
final class LepasProdukRak
{
    public function __construct(
        private readonly PemeriksaOpnameRak $pemeriksa,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<int>  $idProduk
     */
    public function Jalankan(RakGudang $rak, array $idProduk, int $idPengguna): int
    {
        return DB::transaction(function () use ($rak, $idProduk, $idPengguna): int {
            $rak = RakGudang::query()->whereKey($rak->Id)->lockForUpdate()->firstOrFail();
            $opname = $this->pemeriksa->CariOpnameAktif([$rak->Id]);

            if ($opname !== null) {
                throw new PelanggaranAturanBisnis('RakSedangDiopname', "Rak {$rak->Nama} masih diopname ({$opname}). Isi rak tidak bisa diubah sampai selesai.");
            }

            $jumlah = ProdukRak::query()->where('IdRak', $rak->Id)->whereIn('IdProduk', $idProduk)->delete();

            if ($jumlah > 0) {
                $this->audit->Catat('rak-gudang.lepas-produk', $rak, null, ['JumlahProduk' => $jumlah], idPengguna: $idPengguna);
            }

            return $jumlah;
        });
    }
}
