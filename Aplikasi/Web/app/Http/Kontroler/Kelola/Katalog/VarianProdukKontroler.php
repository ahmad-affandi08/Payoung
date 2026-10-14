<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Katalog;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Aksi\GenerasikanVarian;
use App\Domain\Katalog\Aksi\SimpanVarianMassal;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Aksi\CatatStokAwalBanyakProduk;
use App\Domain\Persediaan\Data\DataBarisStokAwal;
use App\Http\Permintaan\Kelola\Katalog\CatatStokAwalVarianPermintaan;
use App\Http\Permintaan\Kelola\Katalog\GenerasikanVarianPermintaan;
use App\Http\Permintaan\Kelola\Katalog\SimpanVarianMassalPermintaan;
use Illuminate\Http\RedirectResponse;

/**
 * Varian produk F-03: generasi kombinasi atribut induk varian. Harga dasar anak butuh izin `produk.harga.ubah`.
 */
final class VarianProdukKontroler extends DasarKatalogKontroler
{
    public function Generasikan(string $produk, GenerasikanVarianPermintaan $permintaan, GenerasikanVarian $generasikan): RedirectResponse
    {
        $hasil = $generasikan->Jalankan($this->CariProduk($produk), $permintaan->AmbilData($this->CekIzin(IzinTenant::ProdukHargaUbah)));
        $dibuat = count($hasil->dibuat);
        $dilewati = count($hasil->dilewati);
        $pesan = $dibuat === 0 ? 'Tidak ada varian baru.' : "{$dibuat} varian dibuat.";

        return back()->with('Kilat', $dilewati === 0 ? $pesan : "{$pesan} {$dilewati} dilewati karena sudah ada.");
    }

    /** Audit kemudahan pakai #18: simpan harga dasar & barcode banyak varian dari tabel varian. */
    public function SimpanMassal(string $produk, SimpanVarianMassalPermintaan $permintaan, SimpanVarianMassal $simpan): RedirectResponse
    {
        $baris = $permintaan->AmbilBaris();
        $diubah = $simpan->Jalankan($this->CariProduk($produk), $baris, $this->CekIzin(IzinTenant::ProdukHargaUbah));

        return back()->with('Kilat', $diubah === 0 ? 'Varian disimpan.' : "Harga {$diubah} varian disimpan.");
    }

    /**
     * Grid ukuran × warna (§9.4, D-143): stok awal banyak varian sekaligus, satu dokumen stok awal yang langsung
     * diposting. Hanya varian induk ini yang berjenis berstok tanpa pelacakan batch/seri; selain itu ditolak dengan
     * pesan yang menyebut variannya. Izin: kelola & posting stok awal, lokasi dalam outlet akses pelaku.
     */
    public function CatatStokAwal(string $produk, CatatStokAwalVarianPermintaan $permintaan, CatatStokAwalBanyakProduk $catat): RedirectResponse
    {
        if ($this->AmbilOpsiStokAwal() === null) {
            throw new PelanggaranAturanBisnis('TanpaIzin', 'Anda belum punya izin mencatat stok awal. Minta Pemilik untuk memberi izinnya.', 'Baris');
        }

        $induk = $this->CariProduk($produk);
        $gudang = app(InfoGudang::class)->AmbilDariUuid([$permintaan->AmbilUuidGudang()])[$permintaan->AmbilUuidGudang()] ?? null;
        $boleh = $this->IdOutletBoleh();

        if ($gudang === null || ($boleh !== null && ($gudang->idOutlet === null || ! in_array($gudang->idOutlet, $boleh, true)))) {
            throw new PelanggaranAturanBisnis('LokasiStokTidakDitemukan', 'Pilih lokasi stok.', 'UuidGudang');
        }

        $masukan = $permintaan->AmbilBaris();
        $anak = Produk::query()->where('IdInduk', $induk->Id)->whereIn('Uuid', array_column($masukan, 'Uuid'))->get()->keyBy('Uuid');
        $baris = [];

        foreach ($masukan as $indeks => $b) {
            $varian = $anak->get($b['Uuid']);

            if (! $varian instanceof Produk) {
                throw new PelanggaranAturanBisnis('VarianTidakDikenal', 'Ada varian yang bukan milik produk ini.', "Baris.{$indeks}.Uuid");
            }

            if (! $varian->Jenis->CekPunyaStok() || $varian->Jenis === JenisProduk::Konsinyasi || $varian->Pelacakan !== PelacakanProduk::Tidak) {
                throw new PelanggaranAturanBisnis('StokAwalTidakBerlaku', "{$varian->Nama}: stok awal hanya untuk barang stok tanpa batch atau nomor seri. Catat lewat halaman Stok awal.", "Baris.{$indeks}.Jumlah");
            }

            $baris[] = new DataBarisStokAwal($varian->Id, $b['Jumlah'], $b['HargaBeli'], null, null, []);
        }

        $dokumen = $catat->Jalankan($gudang, $baris, "Stok awal varian {$induk->Nama} dari grid ukuran × warna", $this->Pelaku()->Id);

        return back()->with('Kilat', 'Stok awal '.count($baris)." varian dicatat di {$gudang->nama} (dokumen {$dokumen->Nomor}).");
    }
}
