<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Data\DataBarisHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Layanan\PenghitungPerubahanHarga;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use Illuminate\Support\Facades\DB;

/**
 * Aksi massal harga (F-03, audit kemudahan pakai): naik/turun harga jual produk terpilih dengan persen atau nominal,
 * dengan pembulatan ke kelipatan tertentu. Yang diubah hanya harga dasar dan harga bertingkat (`IdDaftarHarga` null);
 * harga di daftar harga khusus tidak disentuh. Tiap produk diproses lewat `SimpanHargaProduk` sehingga validasi,
 * `RiwayatHarga` (BR-03.3), dan audit `produk.harga.ubah` sama dengan mengubah satu per satu. Semua atau tidak sama
 * sekali (satu transaksi); hasil negatif menolak seluruh proses.
 */
final class UbahHargaProdukMassal
{
    public const MAKS = 200;

    public const MODE = PenghitungPerubahanHarga::MODE;

    public const PEMBULATAN = PenghitungPerubahanHarga::PEMBULATAN;

    public function __construct(private readonly SimpanHargaProduk $simpan) {}

    /**
     * @param  list<string>  $uuid
     * @return array{Produk: int, Harga: int} jumlah produk yang berubah dan jumlah baris harga yang berubah
     *
     * @throws PelanggaranAturanBisnis ModeTidakDikenal, PilihanKosong, TerlaluBanyak, NilaiTidakValid, ProdukTidakDikenal, HargaNegatif
     */
    public function Jalankan(string $mode, string $nilai, int $pembulatan, array $uuid): array
    {
        PenghitungPerubahanHarga::PastikanPilihanSah($mode, $pembulatan);

        $uuid = array_values(array_unique($uuid));

        if ($uuid === []) {
            throw new PelanggaranAturanBisnis('PilihanKosong', 'Pilih minimal satu produk.', 'Uuid');
        }

        if (count($uuid) > self::MAKS) {
            throw new PelanggaranAturanBisnis('TerlaluBanyak', 'Maksimal '.self::MAKS.' produk sekali proses.', 'Uuid');
        }

        $angka = PenghitungPerubahanHarga::AmbilAngka($nilai, str_ends_with($mode, 'Persen'));

        return DB::transaction(function () use ($mode, $angka, $pembulatan, $uuid): array {
            $produk = Produk::query()->whereIn('Uuid', $uuid)->orderBy('Id')->get();

            if ($produk->count() !== count($uuid)) {
                throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Sebagian produk tidak ditemukan. Muat ulang halaman.', 'Uuid');
            }

            $produkBerubah = 0;
            $hargaBerubah = 0;

            foreach ($produk as $p) {
                $baris = ProdukHarga::query()
                    ->where('IdProduk', $p->Id)
                    ->whereNull('IdDaftarHarga')
                    ->orderBy('IdProdukSatuan')
                    ->orderBy('JumlahMinimum')
                    ->get();

                /** @var array<int, list<DataBarisHarga>> $perSatuan */
                $perSatuan = [];
                $berubah = 0;

                foreach ($baris as $harga) {
                    $lama = Uang::Dari($harga->Harga);
                    $baru = PenghitungPerubahanHarga::Hitung($lama, $mode, $angka, $pembulatan);

                    if ($baru->BernilaiNegatif()) {
                        throw new PelanggaranAturanBisnis('HargaNegatif', "Harga {$p->Nama} akan menjadi negatif. Kurangi nilai penurunan.", 'Nilai');
                    }

                    $berubah += $baru->SamaDengan($lama) ? 0 : 1;
                    $perSatuan[$harga->IdProdukSatuan][] = new DataBarisHarga(Kuantitas::Dari($harga->JumlahMinimum), $baru);
                }

                if ($berubah === 0) {
                    continue;
                }

                $this->simpan->Jalankan($p, $perSatuan, SumberPerubahanHarga::Manual);
                $produkBerubah++;
                $hargaBerubah += $berubah;
            }

            return ['Produk' => $produkBerubah, 'Harga' => $hargaBerubah];
        });
    }
}
