<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Aksi;

use App\Domain\Bersama\Data\HasilPembersihanTemplate;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;

/**
 * Ganti jenis usaha: kategori sisa template sektor lama dibuang lewat `HapusKategori` hanya bila belum dipakai
 * (tanpa sub-kategori dan tanpa produk). Kategori yang masih berisi produk dipertahankan. Hanya kategori tingkat atas
 * yang namanya cocok (tanpa beda huruf besar/kecil) yang disentuh.
 */
final class BersihkanKategoriTemplate
{
    public function __construct(private readonly HapusKategori $hapus) {}

    /**
     * @param  list<string>  $nama  nama kategori sisa template lama yang tidak ada di template baru
     */
    public function Jalankan(array $nama, bool $terapkan): HasilPembersihanTemplate
    {
        $incaran = array_map(fn (string $satu): string => mb_strtolower(trim($satu)), $nama);

        if ($incaran === []) {
            return new HasilPembersihanTemplate;
        }

        $dihapus = [];
        $dipertahankan = [];

        foreach (Kategori::query()->whereNull('IdInduk')->orderBy('Urutan')->get() as $kategori) {
            if (! in_array(mb_strtolower(trim($kategori->Nama)), $incaran, true)) {
                continue;
            }

            $jumlahProduk = Produk::query()->where('IdKategori', $kategori->Id)->count();
            $adaAnak = Kategori::query()->where('IdInduk', $kategori->Id)->exists();

            if ($jumlahProduk > 0 || $adaAnak) {
                $dipertahankan[] = [
                    'Nama' => $kategori->Nama,
                    'Alasan' => $jumlahProduk > 0 ? "Kategori ini masih dipakai {$jumlahProduk} produk." : 'Kategori ini punya sub-kategori.',
                ];

                continue;
            }

            if ($terapkan) {
                try {
                    $this->hapus->Jalankan($kategori);
                } catch (PelanggaranAturanBisnis $galat) {
                    $dipertahankan[] = ['Nama' => $kategori->Nama, 'Alasan' => $galat->getMessage()];

                    continue;
                }
            }

            $dihapus[] = $kategori->Nama;
        }

        return new HasilPembersihanTemplate($dihapus, $dipertahankan);
    }
}
