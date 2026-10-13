<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Layanan\PemeriksaOpnameRak;
use App\Domain\Persediaan\Model\ProdukRak;
use App\Domain\Persediaan\Model\RakGudang;
use Illuminate\Support\Facades\DB;

/**
 * F-05c INV-07: menempatkan produk berstok ke sebuah rak. Satu produk satu rak per lokasi stok, jadi produk yang sudah
 * ada di rak lain dipindahkan. Ditolak untuk rak nonaktif, produk tanpa stok/konsinyasi/terhapus, dan selama ada opname
 * per rak yang berjalan di rak tujuan maupun rak asal (`RakSedangDiopname`). Idempoten (produk yang sudah di rak itu
 * dilewati). Audit `rak-gudang.tetapkan-produk`.
 */
final class TetapkanProdukRak
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly InfoProdukStok $infoProduk,
        private readonly PemeriksaOpnameRak $pemeriksa,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<int>  $idProduk
     * @return array{Ditambah: int, Dipindah: int, Dilewati: int}
     */
    public function Jalankan(RakGudang $rak, array $idProduk, int $idPengguna): array
    {
        $idProduk = array_values(array_unique($idProduk));

        if ($idProduk === []) {
            throw new PelanggaranAturanBisnis('ProdukBelumDipilih', 'Pilih produk yang akan ditempatkan di rak.', 'Produk');
        }

        return DB::transaction(function () use ($rak, $idProduk, $idPengguna): array {
            $rak = RakGudang::query()->whereKey($rak->Id)->lockForUpdate()->firstOrFail();

            if (! $rak->Aktif) {
                throw new PelanggaranAturanBisnis('RakNonaktif', "Rak {$rak->Nama} nonaktif. Aktifkan dulu untuk mengubah isinya.");
            }

            $produk = $this->infoProduk->AmbilBanyak($idProduk);

            foreach ($idProduk as $id) {
                $info = $produk[$id] ?? null;

                if ($info === null || $info->dihapus || ! $info->jenis->CekPunyaStok() || $info->jenis === JenisProduk::Konsinyasi) {
                    throw new PelanggaranAturanBisnis('ProdukTanpaStok', 'Hanya produk berstok yang bisa ditempatkan di rak.', 'Produk');
                }
            }

            $ada = ProdukRak::query()->where('IdGudang', $rak->IdGudang)->whereIn('IdProduk', $idProduk)->lockForUpdate()->get()->keyBy('IdProduk');
            $rakTerlibat = array_values(array_unique([$rak->Id, ...$ada->pluck('IdRak')->map(fn ($r): int => (int) $r)->all()]));
            $opname = $this->pemeriksa->CariOpnameAktif($rakTerlibat);

            if ($opname !== null) {
                throw new PelanggaranAturanBisnis('RakSedangDiopname', "Ada stok opname per rak yang berjalan ({$opname}). Isi rak tidak bisa diubah sampai selesai.");
            }

            $hasil = ['Ditambah' => 0, 'Dipindah' => 0, 'Dilewati' => 0];
            $idTenant = $this->konteks->Wajib();

            foreach ($idProduk as $id) {
                $baris = $ada->get($id);

                if ($baris === null) {
                    ProdukRak::query()->create(['IdTenant' => $idTenant, 'IdRak' => $rak->Id, 'IdGudang' => $rak->IdGudang, 'IdProduk' => $id]);
                    $hasil['Ditambah']++;
                } elseif ($baris->IdRak === $rak->Id) {
                    $hasil['Dilewati']++;
                } else {
                    $baris->IdRak = $rak->Id;
                    $baris->save();
                    $hasil['Dipindah']++;
                }
            }

            if ($hasil['Ditambah'] + $hasil['Dipindah'] > 0) {
                $this->audit->Catat('rak-gudang.tetapkan-produk', $rak, null, ['JumlahProduk' => count($idProduk), ...$hasil], idPengguna: $idPengguna);
            }

            return $hasil;
        });
    }
}
