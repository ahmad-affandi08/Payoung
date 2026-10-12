<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Impor\Kueri;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Data\DataSaringProduk;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\StatusProduk;
use App\Domain\Katalog\Impor\Enum\BidangImpor;
use App\Domain\Katalog\Impor\Layanan\PenguraiNilaiImpor;
use App\Domain\Katalog\Layanan\PohonKategoriTenant;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukBarcode;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Baris ekspor produk (F-03, DesainF03 C.6 langkah 8): saringan sama dengan daftar produk (kata, kategori termasuk
 * turunan, jenis, status), urut Id, per 500 produk (`chunkById`). Anak varian ditulis tepat di bawah induknya.
 * Kolom = `BidangImpor::AmbilDiekspor()` (judul preset Umum), sehingga ekspor → ubah → impor kembali utuh: SKU
 * mencocokkan produk, harga uang/jumlah ditulis dengan titik desimal yang dibaca ulang sebagai desimal. Maks. 3
 * satuan alternatif & 3 tingkat grosir; daftar harga tidak diekspor (H.11).
 */
final class DataEksporProduk
{
    public const UKURAN_POTONGAN = 500;

    public function __construct(private readonly DaftarKelompokPajak $kelompokPajak) {}

    /**
     * @return list<string>
     */
    public static function AmbilJudul(): array
    {
        return array_map(fn (BidangImpor $bidang): string => $bidang->AmbilJudul(), BidangImpor::AmbilDiekspor());
    }

    /**
     * @return Generator<list<string>>
     */
    public function AmbilBaris(DataSaringProduk $saring): Generator
    {
        $jalurKategori = self::SusunJalurKategori();
        $namaSatuan = Satuan::query()->pluck('Nama', 'Id')->all();
        $namaPajak = array_column($this->kelompokPajak->AmbilOpsi(), 'Nama', 'Id');
        $potongan = [];

        $kueri = $this->Saring(Produk::query()->whereNull('IdInduk'), $saring);

        foreach ($kueri->lazyById(self::UKURAN_POTONGAN, 'Id') as $produk) {
            $potongan[] = $produk;

            if (count($potongan) >= self::UKURAN_POTONGAN) {
                yield from $this->TulisPotongan($potongan, $saring, $jalurKategori, $namaSatuan, $namaPajak);
                $potongan = [];
            }
        }

        if ($potongan !== []) {
            yield from $this->TulisPotongan($potongan, $saring, $jalurKategori, $namaSatuan, $namaPajak);
        }
    }

    /**
     * @param  Builder<Produk>  $kueri
     * @return Builder<Produk>
     */
    private function Saring(Builder $kueri, DataSaringProduk $saring): Builder
    {
        $kata = trim($saring->kata);
        $pola = '%'.addcslashes($kata, '%_\\').'%';

        return $kueri
            ->when($kata !== '', fn ($k) => $k->where(fn ($dalam) => $dalam
                ->where('Nama', 'like', $pola)
                ->orWhere('Sku', 'like', $pola)
                ->orWhereIn('Id', ProdukBarcode::query()->where('Barcode', $kata)->select('IdProduk'))
                ->orWhereIn('Id', Produk::query()->whereIn('Id', ProdukBarcode::query()->where('Barcode', $kata)->select('IdProduk'))->whereNotNull('IdInduk')->select('IdInduk'))))
            ->when($saring->idKategori !== null, fn ($k) => $k->whereIn('IdKategori', PohonKategoriTenant::Muat()->AmbilIdDenganTurunan((int) $saring->idKategori)))
            ->when($saring->jenis !== null, fn ($k) => $k->where('Jenis', $saring->jenis?->value))
            ->when($saring->status === StatusProduk::Aktif, fn ($k) => $k->whereNull('DiarsipkanPada'))
            ->when($saring->status === StatusProduk::Diarsipkan, fn ($k) => $k->whereNotNull('DiarsipkanPada'));
    }

    /**
     * @param  list<Produk>  $induk
     * @param  array<int, string>  $jalurKategori
     * @param  array<int, string>  $namaSatuan
     * @param  array<int, string>  $namaPajak
     * @return Generator<list<string>>
     */
    private function TulisPotongan(array $induk, DataSaringProduk $saring, array $jalurKategori, array $namaSatuan, array $namaPajak): Generator
    {
        $idInduk = array_map(fn (Produk $p): int => $p->Id, $induk);
        $anak = Produk::query()->whereIn('IdInduk', $idInduk)
            ->when($saring->status === StatusProduk::Aktif, fn ($k) => $k->whereNull('DiarsipkanPada'))
            ->when($saring->status === StatusProduk::Diarsipkan, fn ($k) => $k->whereNotNull('DiarsipkanPada'))
            ->orderBy('Id')->get()->groupBy('IdInduk');
        $semuaId = [...$idInduk, ...$anak->flatten()->map(fn (Produk $p): int => $p->Id)->all()];
        $satuan = ProdukSatuan::query()->whereIn('IdProduk', $semuaId)->orderBy('Id')->get()->groupBy('IdProduk');
        $barcode = ProdukBarcode::query()->whereIn('IdProduk', $semuaId)->orderBy('Id')->get()->groupBy('IdProdukSatuan');
        $harga = ProdukHarga::query()->whereIn('IdProduk', $semuaId)->whereNull('IdDaftarHarga')->orderBy('JumlahMinimum')->get()->groupBy('IdProdukSatuan');
        foreach ($induk as $produk) {
            yield $this->TulisProduk($produk, null, $jalurKategori, $namaSatuan, $namaPajak, $satuan, $barcode, $harga);

            foreach ($anak->get($produk->Id, collect()) as $varian) {
                yield $this->TulisProduk($varian, $produk->Nama, $jalurKategori, $namaSatuan, $namaPajak, $satuan, $barcode, $harga);
            }
        }
    }

    /**
     * @param  array<int, string>  $jalurKategori
     * @param  array<int, string>  $namaSatuan
     * @param  array<int, string>  $namaPajak
     * @param  Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, ProdukSatuan>>  $satuan
     * @param  Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, ProdukBarcode>>  $barcode
     * @param  Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, ProdukHarga>>  $harga
     * @return list<string>
     */
    private function TulisProduk(Produk $produk, ?string $namaInduk, array $jalurKategori, array $namaSatuan, array $namaPajak, Collection $satuan, Collection $barcode, Collection $harga): array
    {
        $satuanProduk = $satuan->get($produk->Id, collect());
        $dasar = $satuanProduk->first(fn (ProdukSatuan $s): bool => $s->IdSatuan === $produk->IdSatuanDasar);
        $alternatif = $satuanProduk->filter(fn (ProdukSatuan $s): bool => $s->IdSatuan !== $produk->IdSatuanDasar)->values();
        $hargaDasar = $dasar === null ? collect() : $harga->get($dasar->Id, collect());
        $barcodeDari = fn (?ProdukSatuan $s): string => $s === null ? '' : implode(', ', $barcode->get($s->Id, collect())->pluck('Barcode')->all());
        $hargaSatu = function (?ProdukSatuan $s) use ($harga): string {
            $baris = $s === null ? null : $harga->get($s->Id, collect())->first(fn (ProdukHarga $h): bool => self::CekJumlahSatu($h->JumlahMinimum));

            return $baris === null ? '' : PenguraiNilaiImpor::FormatUangEkspor($baris->Harga);
        };
        $grosir = $hargaDasar->reject(fn (ProdukHarga $h): bool => self::CekJumlahSatu($h->JumlahMinimum))->values();

        $nilai = [
            BidangImpor::Nama->value => $produk->Nama,
            BidangImpor::NamaStruk->value => $produk->NamaStruk ?? '',
            BidangImpor::Sku->value => $produk->Sku ?? '',
            BidangImpor::Jenis->value => $produk->Jenis->AmbilLabel(),
            BidangImpor::LacakStok->value => '',
            BidangImpor::Kategori->value => $produk->IdKategori === null ? '' : ($jalurKategori[$produk->IdKategori] ?? ''),
            BidangImpor::Merek->value => $produk->Merek ?? '',
            BidangImpor::Satuan->value => $namaSatuan[$produk->IdSatuanDasar] ?? '',
            BidangImpor::HargaJual->value => $produk->Jenis === JenisProduk::IndukVarian ? '' : $hargaSatu($dasar),
            BidangImpor::Barcode->value => $barcodeDari($dasar),
            BidangImpor::KelompokPajak->value => $produk->IdKelompokPajak === null ? '' : ($namaPajak[$produk->IdKelompokPajak] ?? ''),
            BidangImpor::HargaTermasukPajak->value => match ($produk->HargaTermasukPajak) {
                true => 'Ya',
                false => 'Tidak',
                null => 'Ikut outlet',
            },
            BidangImpor::TampilDiPos->value => $produk->TampilDiPos ? 'Ya' : 'Tidak',
            BidangImpor::TampilOnline->value => $produk->TampilOnline ? 'Ya' : 'Tidak',
            BidangImpor::Favorit->value => $produk->Favorit ? 'Ya' : 'Tidak',
            BidangImpor::BolehMinus->value => match ($produk->BolehMinus) {
                true => 'Ya',
                false => 'Tidak',
                null => '',
            },
            BidangImpor::Pelacakan->value => $produk->Pelacakan->value,
            BidangImpor::NamaInduk->value => $namaInduk ?? '',
            BidangImpor::Varian->value => $namaInduk === null ? '' : implode('; ', array_map(
                fn (mixed $a): string => is_array($a) ? ((string) ($a['Nama'] ?? '')).': '.((string) (is_string($a['Nilai'] ?? null) ? $a['Nilai'] : '')) : '',
                $produk->AtributVarian ?? [],
            )),
            BidangImpor::Status->value => $produk->AmbilStatus()->value,
        ];

        for ($nomor = 1; $nomor <= BidangImpor::JUMLAH_SATUAN_ALTERNATIF; $nomor++) {
            $s = $alternatif->get($nomor - 1);
            $nilai[BidangImpor::SatuanAlternatif($nomor)->value] = $s === null ? '' : ($namaSatuan[$s->IdSatuan] ?? '');
            $nilai[BidangImpor::KonversiSatuanAlternatif($nomor)->value] = $s === null ? '' : PenguraiNilaiImpor::FormatKuantitasEkspor($s->KonversiKeDasar);
            $nilai[BidangImpor::HargaSatuanAlternatif($nomor)->value] = $hargaSatu($s);
            $nilai[BidangImpor::BarcodeSatuanAlternatif($nomor)->value] = $barcodeDari($s);
        }

        for ($nomor = 1; $nomor <= BidangImpor::JUMLAH_GROSIR; $nomor++) {
            $h = $grosir->get($nomor - 1);
            $nilai[BidangImpor::JumlahGrosir($nomor)->value] = $h === null ? '' : PenguraiNilaiImpor::FormatKuantitasEkspor($h->JumlahMinimum);
            $nilai[BidangImpor::HargaGrosir($nomor)->value] = $h === null ? '' : PenguraiNilaiImpor::FormatUangEkspor($h->Harga);
        }

        return array_map(fn (BidangImpor $bidang): string => $nilai[$bidang->value] ?? '', BidangImpor::AmbilDiekspor());
    }

    private static function CekJumlahSatu(string $jumlah): bool
    {
        return Kuantitas::Dari($jumlah)->SamaDengan(Kuantitas::Dari(1));
    }

    /**
     * Jalur kategori "Minuman > Kopi" per Id.
     *
     * @return array<int, string>
     */
    private static function SusunJalurKategori(): array
    {
        $kategori = Kategori::query()->get(['Id', 'IdInduk', 'Nama'])->keyBy('Id');
        $hasil = [];

        foreach ($kategori as $id => $satu) {
            $jalur = [$satu->Nama];
            $induk = $satu->IdInduk;
            $batas = 10;

            while ($induk !== null && $batas-- > 0) {
                $atas = $kategori->get($induk);

                if ($atas === null) {
                    break;
                }

                $jalur[] = $atas->Nama;
                $induk = $atas->IdInduk;
            }

            $hasil[(int) $id] = implode(' > ', array_reverse($jalur));
        }

        return $hasil;
    }
}
