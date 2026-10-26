<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Data\DataBarisHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Aksi cepat Aplikasi Owner (OWN-07): menetapkan harga dasar satu produk di satuan jual utamanya. Hanya baris
 * `JumlahMinimum = 1` di satuan jual utama (default jual, atau satuan pertama) yang diganti; harga bertingkat dan harga di
 * daftar harga khusus tidak disentuh. Lewat `SimpanHargaProduk`, jadi validasi, `RiwayatHarga` (sumber `AplikasiOwner`),
 * dan audit `produk.harga.ubah` sama dengan back-office. Produk induk varian ditolak (harga ada di tiap varian).
 */
final class TetapkanHargaDasarProduk
{
    public function __construct(private readonly SimpanHargaProduk $simpan) {}

    /**
     * @return array{HargaLama: string, HargaBaru: string, Berubah: bool}
     *
     * @throws PelanggaranAturanBisnis ProdukIndukVarian, HargaTidakValid, HargaDasarTidakAda
     */
    public function Jalankan(Produk $produk, Uang $harga): array
    {
        if ($produk->Jenis === JenisProduk::IndukVarian) {
            throw new PelanggaranAturanBisnis('ProdukIndukVarian', 'Ubah harga per varian, bukan produk induknya.', 'Harga');
        }

        if ($harga->BernilaiNegatif() || $harga->BernilaiNol()) {
            throw new PelanggaranAturanBisnis('HargaTidakValid', 'Harga harus lebih dari Rp 0.', 'Harga');
        }

        return DB::transaction(function () use ($produk, $harga): array {
            $satuan = ProdukSatuan::query()->where('IdProduk', $produk->Id)->orderByDesc('DefaultJual')->orderBy('Id')->first();
            $baris = $satuan === null ? collect() : ProdukHarga::query()
                ->where('IdProdukSatuan', $satuan->Id)
                ->whereNull('IdDaftarHarga')
                ->orderBy('JumlahMinimum')
                ->get();
            $dasar = $baris->first(fn (ProdukHarga $h): bool => BigDecimal::of($h->JumlahMinimum)->isEqualTo(1));

            if ($satuan === null || $dasar === null) {
                throw new PelanggaranAturanBisnis('HargaDasarTidakAda', 'Produk ini belum punya harga jual. Atur harganya di dashboard dulu.', 'Harga');
            }

            $lama = Uang::Dari($dasar->Harga);
            $daftar = array_values($baris->map(fn (ProdukHarga $h): DataBarisHarga => new DataBarisHarga(
                Kuantitas::Dari($h->JumlahMinimum),
                $h->Id === $dasar->Id ? $harga : Uang::Dari($h->Harga),
            ))->all());
            $hasil = $this->simpan->Jalankan($produk, [$satuan->Id => $daftar], SumberPerubahanHarga::AplikasiOwner);

            return ['HargaLama' => $lama->KeString(), 'HargaBaru' => $harga->KeString(), 'Berubah' => $hasil->CekAdaPerubahan()];
        });
    }
}
