<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Data\DataPerubahanProduk;
use App\Domain\Katalog\Model\Produk;
use Illuminate\Support\Facades\DB;

/**
 * Audit kemudahan pakai #19 (F-03): aksi massal produk terpilih di daftar produk — arsipkan, pulihkan, pindah
 * kategori, tampil/sembunyikan di kasir, tandai/lepas favorit (K-16). Setiap produk diproses lewat Aksi satuannya (`ArsipkanProduk`,
 * `PulihkanProduk`, `PerbaruiProdukSebagian`) sehingga aturan, audit per produk, dan pemberitahuan perubahan sama
 * persis dengan aksi satu per satu. Semua atau tidak sama sekali (satu transaksi). Maksimal [MAKS] produk.
 */
final class UbahProdukMassal
{
    public const MAKS = 200;

    public const AKSI = ['Arsipkan', 'Pulihkan', 'Kategori', 'TampilDiPos', 'SembunyikanDariPos', 'Favorit', 'HapusFavorit'];

    public function __construct(
        private readonly ArsipkanProduk $arsipkan,
        private readonly PulihkanProduk $pulihkan,
        private readonly PerbaruiProdukSebagian $perbarui,
    ) {}

    /**
     * @param  list<string>  $uuid
     *
     * @throws PelanggaranAturanBisnis AksiTidakDikenal, PilihanKosong, TerlaluBanyak, ProdukTidakDikenal, aturan produk
     */
    public function Jalankan(string $aksi, array $uuid, ?int $idKategori = null): int
    {
        if (! in_array($aksi, self::AKSI, true)) {
            throw new PelanggaranAturanBisnis('AksiTidakDikenal', 'Aksi massal tidak dikenal.', 'Aksi');
        }

        $uuid = array_values(array_unique($uuid));

        if ($uuid === []) {
            throw new PelanggaranAturanBisnis('PilihanKosong', 'Pilih minimal satu produk.', 'Uuid');
        }

        if (count($uuid) > self::MAKS) {
            throw new PelanggaranAturanBisnis('TerlaluBanyak', 'Maksimal '.self::MAKS.' produk sekali proses.', 'Uuid');
        }

        if ($aksi === 'Kategori' && $idKategori === null) {
            throw new PelanggaranAturanBisnis('KategoriWajib', 'Pilih kategori tujuan.', 'UuidKategori');
        }

        return DB::transaction(function () use ($aksi, $uuid, $idKategori): int {
            $produk = Produk::query()->whereIn('Uuid', $uuid)->orderBy('Id')->get();

            if ($produk->count() !== count($uuid)) {
                throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Sebagian produk tidak ditemukan. Muat ulang halaman.', 'Uuid');
            }

            foreach ($produk as $p) {
                match ($aksi) {
                    'Arsipkan' => $this->arsipkan->Jalankan($p),
                    'Pulihkan' => $this->pulihkan->Jalankan($p),
                    'Kategori' => $this->perbarui->Jalankan($p, new DataPerubahanProduk(idKategori: $idKategori)),
                    'TampilDiPos' => $this->perbarui->Jalankan($p, new DataPerubahanProduk(tampilDiPos: true)),
                    'Favorit' => $this->perbarui->Jalankan($p, new DataPerubahanProduk(favorit: true)),
                    'HapusFavorit' => $this->perbarui->Jalankan($p, new DataPerubahanProduk(favorit: false)),
                    default => $this->perbarui->Jalankan($p, new DataPerubahanProduk(tampilDiPos: false)),
                };
            }

            return $produk->count();
        });
    }
}
