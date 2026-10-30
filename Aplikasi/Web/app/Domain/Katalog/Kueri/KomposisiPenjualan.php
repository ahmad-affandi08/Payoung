<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Data\DataKebutuhanStok;
use App\Domain\Katalog\Data\DataPilihanPenjualan;
use App\Domain\Katalog\Data\DataProdukPenjualan;
use App\Domain\Katalog\Data\DataResepProduksi;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Katalog\PaketProduk\Model\PaketProdukDetail;
use App\Domain\Katalog\Pilihan\Model\Pilihan;
use App\Domain\Katalog\Resep\Kueri\HppResep;
use App\Domain\Katalog\Resep\Model\Resep;
use App\Domain\Katalog\Resep\Model\ResepDetail;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;

/**
 * API baca publik Katalog untuk penerimaan penjualan POS (F-07b, CLAUDE.md #14): produk & satuan per Uuid (termasuk
 * yang sudah dihapus), kebutuhan stok per produk (dirinya sendiri untuk Stok/Produksi, bahan resep versi terbaru
 * untuk Resep, komponen untuk Paket secara rekursif), dan pilihan beserta bahannya. Mengembalikan DTO, bukan Model.
 */
final class KomposisiPenjualan
{
    /** Paket → komponen → bahan resep; lebih dalam dari ini dianggap data rusak dan dihentikan. */
    private const KEDALAMAN_MAKSIMAL = 4;

    /**
     * @param  list<string>  $uuid
     * @return array<string, DataProdukPenjualan> kunci = Uuid
     */
    public function AmbilProduk(array $uuid): array
    {
        if ($uuid === []) {
            return [];
        }

        $produk = Produk::query()->withTrashed()->whereIn('Uuid', array_values(array_unique($uuid)))->get();
        $satuan = ProdukSatuan::query()->whereIn('IdProduk', $produk->pluck('Id')->all())->get()->groupBy('IdProduk');
        $kategori = Kategori::query()->whereKey($produk->pluck('IdKategori')->filter()->unique()->values()->all())->pluck('Uuid', 'Id');
        $hasil = [];

        foreach ($produk as $p) {
            $daftarSatuan = [];

            foreach ($satuan->get($p->Id, []) as $s) {
                $daftarSatuan[$s->Uuid] = ['IdSatuan' => $s->IdSatuan, 'KonversiKeDasar' => (string) $s->KonversiKeDasar];
            }

            $hasil[$p->Uuid] = new DataProdukPenjualan(
                $p->Id,
                $p->Uuid,
                $p->Nama,
                $p->Jenis,
                $p->Pelacakan,
                $p->IdSatuanDasar,
                $daftarSatuan,
                $p->DihapusPada !== null,
                $p->IdKelompokPajak,
                $p->HargaTermasukPajak,
                $p->IdKategori === null ? null : ($kategori[$p->IdKategori] ?? null),
                $p->MasaGaransiBulan,
                $p->GolonganObat,
                $p->ObatWajibApotek,
            );
        }

        return $hasil;
    }

    /**
     * Kebutuhan stok per 1 satuan dasar tiap produk. Jasa, NonStok, dan produk tanpa resep/komponen = daftar kosong.
     *
     * @param  list<int>  $idProduk
     * @return array<int, list<DataKebutuhanStok>> kunci = IdProduk
     */
    public function AmbilKebutuhanStok(array $idProduk): array
    {
        $hasil = [];
        $unik = array_values(array_unique($idProduk));
        // Produk akar dimuat sekali untuk seluruh keranjang (dulu satu kueri per produk, di setiap penjualan sinkron).
        $dimuat = $unik === [] ? new Collection : Produk::query()->withTrashed()->whereKey($unik)->get()->keyBy('Id');

        foreach ($unik as $id) {
            $akar = $dimuat->get($id);
            $hasil[$id] = $akar === null ? [] : $this->Uraikan($id, BigDecimal::one(), BigDecimal::one(), '', 0, $akar);
        }

        return $hasil;
    }

    /**
     * F-05e: resep versi terbaru produk jenis Produksi beserta kebutuhan bahan berstok per 1 satuan dasar hasil; null
     * bila bukan produk Produksi atau belum punya resep.
     */
    public function AmbilResepProduksi(int $idProduk): ?DataResepProduksi
    {
        $produk = Produk::query()->whereKey($idProduk)->first();

        if ($produk === null || $produk->Jenis !== JenisProduk::Produksi) {
            return null;
        }

        $resep = Resep::query()->where('IdProduk', $produk->Id)->orderByDesc('Versi')->first();

        if ($resep === null) {
            return null;
        }

        $bahan = [];

        foreach (ResepDetail::query()->where('IdResep', $resep->Id)->orderBy('Urutan')->get() as $baris) {
            array_push($bahan, ...$this->Uraikan(
                $baris->IdProdukBahan,
                HppResep::HitungJumlahKotor($baris->JumlahDasar, $baris->PersenSusut),
                BigDecimal::of($resep->JumlahHasil),
                'Resep '.$produk->Nama.' v'.$resep->Versi,
                1,
            ));
        }

        return new DataResepProduksi($resep->Id, $resep->Versi, BigDecimal::of($resep->JumlahHasil), $bahan);
    }

    /**
     * Pilihan per Uuid (pilihan yang sudah dihapus tidak muncul).
     *
     * @param  list<string>  $uuid
     * @return array<string, DataPilihanPenjualan> kunci = Uuid
     */
    public function AmbilPilihan(array $uuid): array
    {
        if ($uuid === []) {
            return [];
        }

        $pilihan = Pilihan::query()->whereIn('Uuid', array_values(array_unique($uuid)))->get();
        $bahan = Produk::query()->withTrashed()->whereIn('Id', $pilihan->pluck('IdProduk')->filter()->unique()->all())->get()->keyBy('Id');
        $hasil = [];

        foreach ($pilihan as $p) {
            $produkBahan = $p->IdProduk === null ? null : $bahan->get($p->IdProduk);
            $hasil[$p->Uuid] = new DataPilihanPenjualan($p->Uuid, $p->Nama, $produkBahan === null || $p->Jumlah === null ? null : new DataKebutuhanStok(
                $produkBahan->Id,
                $produkBahan->Uuid,
                $produkBahan->Nama,
                $produkBahan->Jenis,
                $produkBahan->Pelacakan,
                BigDecimal::of($p->Jumlah),
                BigDecimal::one(),
                "Pilihan: {$p->Nama}",
                $produkBahan->DihapusPada !== null,
            ));
        }

        return $hasil;
    }

    /**
     * Simbol satuan per Id (tampilan baris penjualan back-office).
     *
     * @param  list<int>  $idSatuan
     * @return array<int, string>
     */
    public function AmbilSimbolSatuan(array $idSatuan): array
    {
        $hasil = [];

        foreach (Satuan::query()->whereIn('Id', array_values(array_unique($idSatuan)))->get(['Id', 'Simbol']) as $satuan) {
            $hasil[$satuan->Id] = $satuan->Simbol;
        }

        return $hasil;
    }

    /**
     * @return list<DataKebutuhanStok>
     */
    private function Uraikan(int $idProduk, BigDecimal $pembilang, BigDecimal $penyebut, string $jalur, int $kedalaman, ?Produk $dimuat = null): array
    {
        $produk = $dimuat ?? Produk::query()->withTrashed()->whereKey($idProduk)->first();

        if ($produk === null || $kedalaman > self::KEDALAMAN_MAKSIMAL) {
            return [];
        }

        if ($produk->Jenis->CekPunyaStok()) {
            return [new DataKebutuhanStok($produk->Id, $produk->Uuid, $produk->Nama, $produk->Jenis, $produk->Pelacakan, $pembilang, $penyebut, $jalur === '' ? 'Produk' : $jalur, $produk->DihapusPada !== null)];
        }

        return match ($produk->Jenis) {
            JenisProduk::Resep => $this->UraikanResep($produk, $pembilang, $penyebut, $jalur),
            JenisProduk::Paket => $this->UraikanPaket($produk, $pembilang, $penyebut, $jalur, $kedalaman),
            default => [],
        };
    }

    /**
     * Bahan resep versi terbaru: jumlah kotor (termasuk susut, sama dengan `HppResep`) ÷ JumlahHasil.
     *
     * @return list<DataKebutuhanStok>
     */
    private function UraikanResep(Produk $produk, BigDecimal $pembilang, BigDecimal $penyebut, string $jalur): array
    {
        $resep = Resep::query()->where('IdProduk', $produk->Id)->orderByDesc('Versi')->first();

        if ($resep === null) {
            return [];
        }

        $detail = ResepDetail::query()->where('IdResep', $resep->Id)->orderBy('Urutan')->get();
        $bahan = Produk::query()->withTrashed()->whereIn('Id', $detail->pluck('IdProdukBahan')->all())->get()->keyBy('Id');
        $hasil = [];

        foreach ($detail as $baris) {
            $p = $bahan->get($baris->IdProdukBahan);

            if ($p === null) {
                continue;
            }

            $hasil[] = new DataKebutuhanStok(
                $p->Id,
                $p->Uuid,
                $p->Nama,
                $p->Jenis,
                $p->Pelacakan,
                $pembilang->multipliedBy(HppResep::HitungJumlahKotor($baris->JumlahDasar, $baris->PersenSusut)),
                $penyebut->multipliedBy(BigDecimal::of($resep->JumlahHasil)),
                trim($jalur.' Resep '.$produk->Nama.' v'.$resep->Versi),
                $p->DihapusPada !== null,
                $produk->LokasiProduksi,
            );
        }

        return $hasil;
    }

    /**
     * @return list<DataKebutuhanStok>
     */
    private function UraikanPaket(Produk $produk, BigDecimal $pembilang, BigDecimal $penyebut, string $jalur, int $kedalaman): array
    {
        $hasil = [];

        foreach (PaketProdukDetail::query()->where('IdProdukPaket', $produk->Id)->orderBy('Urutan')->get() as $komponen) {
            $hasil = [...$hasil, ...$this->Uraikan(
                $komponen->IdProdukKomponen,
                $pembilang->multipliedBy(BigDecimal::of($komponen->Jumlah)),
                $penyebut,
                trim($jalur.' Paket '.$produk->Nama.':'),
                $kedalaman + 1,
            )];
        }

        return $hasil;
    }
}
