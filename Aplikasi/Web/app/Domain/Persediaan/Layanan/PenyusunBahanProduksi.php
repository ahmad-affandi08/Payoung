<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Data\DataResepProduksi;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\KomposisiPenjualan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * F-05e: memeriksa produk hasil & menyusun baris bahan order produksi. Hasil wajib produk jenis Produksi yang aktif,
 * bukan bernomor seri. Kebutuhan standar = resep versi terbaru × jumlah hasil (bahan Resep/Paket diuraikan, susut
 * ikut). Bahan harus produk berstok tanpa pelacakan batch/seri (pemilihan batch bahan menyusul FEFO F-05g), tidak sama
 * dengan hasil, dan tidak ganda; paling banyak 100 baris.
 */
final class PenyusunBahanProduksi
{
    public const MAKS_BAHAN = 100;

    public function __construct(
        private readonly InfoProdukStok $infoProduk,
        private readonly KomposisiPenjualan $komposisi,
    ) {}

    /**
     * @param  list<array{idProduk: int, jumlah: Kuantitas}>|null  $bahanAktual
     * @return array{hasil: DataInfoProdukStok, resep: DataResepProduksi|null, bahan: list<array{info: DataInfoProdukStok, standar: Kuantitas, jumlah: Kuantitas}>}
     */
    public function Susun(int $idProduk, Kuantitas $jumlahHasil, ?array $bahanAktual): array
    {
        $hasil = $this->infoProduk->AmbilBanyak([$idProduk])[$idProduk] ?? null;

        if ($hasil === null || $hasil->dihapus) {
            throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Produk hasil tidak ditemukan.', 'UuidProduk');
        }

        if ($hasil->jenis !== JenisProduk::Produksi) {
            throw new PelanggaranAturanBisnis('BukanProdukProduksi', "{$hasil->nama} bukan produk jenis Produksi. Ubah jenisnya di data produk agar bisa diproduksi.", 'UuidProduk');
        }

        if ($hasil->diarsipkan) {
            throw new PelanggaranAturanBisnis('ProdukDiarsipkan', "{$hasil->nama} sudah diarsipkan.", 'UuidProduk');
        }

        if ($hasil->pelacakan === PelacakanProduk::Seri) {
            throw new PelanggaranAturanBisnis('PelacakanTidakDidukung', 'Produk bernomor seri tidak bisa dibuat lewat order produksi.', 'UuidProduk');
        }

        if (! $jumlahHasil->KeDesimal()->isPositive()) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah hasil harus lebih dari 0.', 'JumlahHasil');
        }

        if (! $hasil->bolehDesimal && ! $jumlahHasil->KeDesimal()->isEqualTo($jumlahHasil->KeDesimal()->toScale(0, RoundingMode::Down))) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', "Jumlah hasil {$hasil->nama} harus bilangan bulat.", 'JumlahHasil');
        }

        $resep = $this->komposisi->AmbilResepProduksi($idProduk);
        $standar = [];

        foreach ($resep === null ? [] : $resep->bahan as $b) {
            $kebutuhan = $b->pembilang->multipliedBy($jumlahHasil->KeDesimal())->dividedBy($b->penyebut, 4, RoundingMode::HalfUp);
            $standar[$b->idProduk] = ($standar[$b->idProduk] ?? BigDecimal::zero())->plus($kebutuhan);
        }

        if ($bahanAktual === null) {
            if ($resep === null || $standar === []) {
                throw new PelanggaranAturanBisnis('ResepBelumAda', "{$hasil->nama} belum punya resep. Isi resepnya dulu, atau tulis bahan yang dipakai.", 'Bahan');
            }

            $bahanAktual = array_values(array_map(fn (int $id, BigDecimal $j): array => ['idProduk' => $id, 'jumlah' => Kuantitas::Dari($j)], array_keys($standar), $standar));
        }

        if ($bahanAktual === []) {
            throw new PelanggaranAturanBisnis('BahanKosong', 'Isi minimal satu bahan.', 'Bahan');
        }

        if (count($bahanAktual) > self::MAKS_BAHAN) {
            throw new PelanggaranAturanBisnis('BahanTerlaluBanyak', 'Paling banyak '.self::MAKS_BAHAN.' baris bahan.', 'Bahan');
        }

        $info = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (array $b): int => $b['idProduk'], $bahanAktual))));
        $hasilBaris = [];
        $sudah = [];

        foreach ($bahanAktual as $i => $b) {
            $bidang = "Bahan.{$i}.UuidProduk";
            $p = $info[$b['idProduk']] ?? null;

            if ($p === null || $p->dihapus) {
                throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Produk bahan tidak ditemukan.', $bidang);
            }

            if ($p->id === $hasil->id) {
                throw new PelanggaranAturanBisnis('BahanSamaDenganHasil', "{$p->nama} tidak bisa menjadi bahan untuk dirinya sendiri.", $bidang);
            }

            if (isset($sudah[$p->id])) {
                throw new PelanggaranAturanBisnis('BahanGanda', "{$p->nama} ditulis lebih dari sekali. Gabungkan jumlahnya.", $bidang);
            }

            if (! $p->jenis->CekPunyaStok() || $p->jenis === JenisProduk::Konsinyasi) {
                throw new PelanggaranAturanBisnis('BahanTidakBerstok', "{$p->nama} tidak punya stok sehingga tidak bisa menjadi bahan.", $bidang);
            }

            if ($p->pelacakan !== PelacakanProduk::Tidak) {
                throw new PelanggaranAturanBisnis('PelacakanTidakDidukung', "{$p->nama} memakai batch/nomor seri. Bahan berpelacakan belum bisa dipakai di order produksi.", $bidang);
            }

            if (! $b['jumlah']->KeDesimal()->isPositive()) {
                throw new PelanggaranAturanBisnis('JumlahTidakValid', "Jumlah {$p->nama} harus lebih dari 0.", "Bahan.{$i}.Jumlah");
            }

            $sudah[$p->id] = true;
            $hasilBaris[] = ['info' => $p, 'standar' => Kuantitas::Dari($standar[$p->id] ?? '0'), 'jumlah' => $b['jumlah']];
        }

        return ['hasil' => $hasil, 'resep' => $resep, 'bahan' => $hasilBaris];
    }
}
