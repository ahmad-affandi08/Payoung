<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Layanan\PenjagaPelakuRuteDapur;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\DaftarStasiunDapur;
use Illuminate\Support\Facades\DB;

/**
 * D-310 (F-10): mengatur stasiun dapur/bar satu menu dari kasir atau back-office. Stasiun produk menimpa stasiun
 * kategorinya (varian ikut produk induknya), berlaku untuk seluruh usaha. `null` = kembali ikut kategori. Varian
 * anak tidak diatur sendiri-sendiri: atur pada produk induknya. Idempoten. Dari back-office, kontrolernya sudah
 * menegakkan izin dan mengisi `$idPenggunaBackOffice`.
 */
final class AturStasiunDapurProduk
{
    public function __construct(
        private readonly PenjagaPelakuRuteDapur $penjaga,
        private readonly DaftarStasiunDapur $stasiun,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return string|null Uuid stasiun akhir (null = ikut kategori)
     */
    public function Jalankan(int $idOutlet, string $uuidProduk, ?string $uuidStasiun, ?string $uuidPengguna = null, ?int $idPenggunaBackOffice = null): ?string
    {
        $idPengguna = $idPenggunaBackOffice ?? $this->penjaga->PeriksaPelaku($idOutlet, (string) $uuidPengguna);
        $idStasiun = $this->penjaga->PeriksaStasiun($uuidStasiun);

        return DB::transaction(function () use ($uuidProduk, $idStasiun, $idPengguna): ?string {
            $produk = Produk::query()->where('Uuid', strtoupper($uuidProduk))->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('ProdukTidakDitemukan', 'Produk tidak ditemukan.', 'UuidProduk', 404);

            if ($produk->IdInduk !== null) {
                throw new PelanggaranAturanBisnis('ProdukVarianAnak', 'Atur stasiun dapur pada produk induknya. Semua variannya ikut.', 'UuidProduk');
            }

            if ($produk->Jenis === JenisProduk::Jasa) {
                throw new PelanggaranAturanBisnis('ProdukJasa', 'Jasa tidak dikirim ke dapur.', 'UuidProduk');
            }

            if ($produk->IdStasiunDapur !== $idStasiun) {
                $lama = $produk->IdStasiunDapur;
                $produk->forceFill(['IdStasiunDapur' => $idStasiun])->save();
                $peta = $this->stasiun->AmbilPetaId();
                $this->audit->Catat('dapur.rute-atur', $produk, nilaiLama: ['Stasiun' => $lama === null ? null : ($peta[$lama]['Nama'] ?? null)], nilaiBaru: [
                    'Jenis' => 'Produk',
                    'Nama' => $produk->Nama,
                    'Stasiun' => $idStasiun === null ? null : ($peta[$idStasiun]['Nama'] ?? null),
                ], idPengguna: $idPengguna);
            }

            return $idStasiun === null ? null : ($this->stasiun->AmbilPetaId()[$idStasiun]['Uuid'] ?? null);
        });
    }
}
