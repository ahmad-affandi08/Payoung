<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Data\DataBarisStokAwal;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Pemeriksaan isi dokumen stok awal (DesainF05a C.6.1), dipakai `SimpanStokAwal` dan diulang `PostingStokAwal`:
 *
 * - lokasi stok dikenal dan aktif (`GudangTidakDikenal` / `GudangDiarsipkan`);
 * - 1 ≤ baris ≤ `persediaan.StokAwal.MaksimalBaris` (`BarisKosong` / `BarisTerlaluBanyak`);
 * - tanggal ≤ tanggal bisnis hari ini di outlet lokasi (`TanggalDiMasaDepan`);
 * - per baris: produk dikenal, berstok, bukan Konsinyasi (H-1), belum diarsipkan; jumlah > 0 dalam satuan dasar
 *   (bulat bila satuan tidak desimal, H-2); HPP ≥ 0 maksimal 6 desimal; batch/seri (`PemvalidasiPelacakan`); tanpa
 *   pasangan (produk, batch) ganda.
 *
 * Galat baris dikumpulkan semuanya di `detail.Baris: [{Urutan, Bidang, Kode, Pesan}]`; kode & pesan galat utama =
 * galat baris pertama, bidangnya `Baris.{indeks}.{Bidang}` (galat validasi form Inertia).
 */
final class PemeriksaStokAwal
{
    /** Batas digit bulat kolom: Jumlah DECIMAL(18,4), HppSatuan DECIMAL(19,6), Nilai DECIMAL(18,2). */
    private const DIGIT_JUMLAH = 14;

    private const DIGIT_HPP = 13;

    private const DIGIT_NILAI = 16;

    public function __construct(
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PemvalidasiPelacakan $pelacakan,
    ) {}

    /**
     * @param  list<DataBarisStokAwal>  $baris
     * @return array{Gudang: DataInfoGudang, Produk: array<int, DataInfoProdukStok>}
     *
     * @throws PelanggaranAturanBisnis
     */
    public function Periksa(int $idGudang, CarbonImmutable $tanggal, array $baris): array
    {
        $gudang = $this->infoGudang->AmbilBanyak([$idGudang])[$idGudang]
            ?? throw new PelanggaranAturanBisnis('GudangTidakDikenal', 'Lokasi stok tidak ditemukan.', 'UuidGudang');

        if (! $gudang->aktif) {
            throw new PelanggaranAturanBisnis('GudangDiarsipkan', "Lokasi stok {$gudang->nama} sudah diarsipkan. Pilih lokasi stok yang aktif.", 'UuidGudang');
        }

        $maksimal = (int) config('persediaan.StokAwal.MaksimalBaris', 2000);

        if ($baris === []) {
            throw new PelanggaranAturanBisnis('BarisKosong', 'Tambahkan minimal satu produk.', 'Baris');
        }

        if (count($baris) > $maksimal) {
            throw new PelanggaranAturanBisnis('BarisTerlaluBanyak', "Satu dokumen stok awal maksimal {$maksimal} baris. Pecah menjadi beberapa dokumen.", 'Baris');
        }

        $hariIni = $this->tanggalBisnis->Hitung($gudang->idOutlet);

        if ($tanggal->format('Y-m-d') > $hariIni->format('Y-m-d')) {
            throw new PelanggaranAturanBisnis('TanggalDiMasaDepan', 'Tanggal stok awal tidak boleh melewati hari ini ('.$hariIni->format('d/m/Y').').', 'Tanggal');
        }

        $produk = $this->infoProduk->AmbilBanyak(array_values(array_map(fn (DataBarisStokAwal $b): int => $b->idProduk, $baris)));
        $galat = [];
        $terlihat = [];

        foreach ($baris as $indeks => $satu) {
            $info = $produk[$satu->idProduk] ?? null;

            foreach ($this->PeriksaBaris($info, $satu) as [$bidang, $kode, $pesan]) {
                $galat[] = ['Indeks' => $indeks, 'Urutan' => $indeks + 1, 'Bidang' => $bidang, 'Kode' => $kode, 'Pesan' => $pesan];
            }

            $kunci = $satu->idProduk.'|'.mb_strtolower(trim((string) $satu->nomorBatch));

            if (isset($terlihat[$kunci])) {
                $nama = $info === null ? 'Produk ini' : $info->nama;
                $galat[] = ['Indeks' => $indeks, 'Urutan' => $indeks + 1, 'Bidang' => 'UuidProduk', 'Kode' => 'BarisGanda',
                    'Pesan' => "{$nama} sudah ada di baris {$terlihat[$kunci]}".($satu->nomorBatch === null ? '' : " dengan batch {$satu->nomorBatch}").'. Gabungkan jumlahnya di satu baris.'];
            } else {
                $terlihat[$kunci] = $indeks + 1;
            }
        }

        if ($galat === []) {
            self::PastikanTotalDalamBatas($baris);
        }

        if ($galat !== []) {
            $pertama = $galat[0];

            throw new PelanggaranAturanBisnis(
                $pertama['Kode'],
                "Baris {$pertama['Urutan']}: {$pertama['Pesan']}".(count($galat) > 1 ? ' (dan '.(count($galat) - 1).' masalah lain)' : ''),
                "Baris.{$pertama['Indeks']}.{$pertama['Bidang']}",
                detail: ['Baris' => array_map(fn (array $g): array => ['Urutan' => $g['Urutan'], 'Bidang' => $g['Bidang'], 'Kode' => $g['Kode'], 'Pesan' => $g['Pesan']], $galat)],
            );
        }

        return ['Gudang' => $gudang, 'Produk' => $produk];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [Bidang, Kode, Pesan]
     */
    private function PeriksaBaris(?DataInfoProdukStok $info, DataBarisStokAwal $baris): array
    {
        if ($info === null || $info->dihapus) {
            return [['UuidProduk', 'ProdukTidakDikenal', 'Produk tidak ditemukan.']];
        }

        if ($info->jenis === JenisProduk::Konsinyasi) {
            return [['UuidProduk', 'ProdukKonsinyasi', "{$info->nama} adalah barang konsinyasi (titipan). Stok titipan dicatat lewat konsinyasi, bukan stok awal."]];
        }

        if (! $info->jenis->CekPunyaStok()) {
            return [['UuidProduk', 'ProdukTanpaStok', "{$info->nama} berjenis {$info->jenis->AmbilLabel()} yang tidak punya stok."]];
        }

        if ($info->diarsipkan) {
            return [['UuidProduk', 'ProdukDiarsipkan', "{$info->nama} sudah diarsipkan. Pulihkan produknya dulu bila masih dijual."]];
        }

        $galat = [];
        $jumlah = $baris->jumlah->KeDesimal();

        if (! $jumlah->isPositive()) {
            $galat[] = ['Jumlah', 'JumlahTidakValid', 'Stok harus lebih dari 0.'];
        } elseif (! $info->bolehDesimal && $jumlah->strippedOfTrailingZeros()->getScale() > 0) {
            $galat[] = ['Jumlah', 'JumlahTidakValid', "Stok {$info->nama} harus bilangan bulat ({$info->simbolSatuan})."];
        } elseif (self::HitungDigitBulat($jumlah) > self::DIGIT_JUMLAH) {
            $galat[] = ['Jumlah', 'JumlahTidakValid', 'Stok terlalu besar.'];
        }

        $hpp = $baris->hppSatuan;

        if ($hpp->isNegative()) {
            $galat[] = ['HppSatuan', 'HppTidakValid', 'Harga modal tidak boleh negatif.'];
        } elseif ($hpp->strippedOfTrailingZeros()->getScale() > 6) {
            $galat[] = ['HppSatuan', 'HppTidakValid', 'Harga modal maksimal 6 angka di belakang koma.'];
        } elseif (self::HitungDigitBulat($hpp) > self::DIGIT_HPP) {
            $galat[] = ['HppSatuan', 'HppTidakValid', 'Harga modal terlalu besar.'];
        } elseif ($galat === [] && self::HitungDigitBulat(AritmetikaHpp::KeDesimal(AritmetikaHpp::Nilai($baris->jumlah, $hpp))) > self::DIGIT_NILAI) {
            $galat[] = ['HppSatuan', 'HppTidakValid', 'Nilai baris (stok × harga modal) terlalu besar.'];
        }

        foreach ($this->pelacakan->PeriksaBaris($info, $baris) as $g) {
            $galat[] = [$g['Bidang'], 'PelacakanTidakValid', $g['Pesan']];
        }

        return $galat;
    }

    /**
     * TotalNilai dokumen (dan total jurnal J-05.1) = Σ Nilai baris harus muat di DECIMAL(18,2), walau tiap baris
     * sudah dalam batas.
     *
     * @param  list<DataBarisStokAwal>  $baris
     */
    private static function PastikanTotalDalamBatas(array $baris): void
    {
        $total = BigDecimal::zero();

        foreach ($baris as $satu) {
            $total = $total->plus(AritmetikaHpp::KeDesimal(AritmetikaHpp::Nilai($satu->jumlah, $satu->hppSatuan)));
        }

        if (self::HitungDigitBulat($total) > self::DIGIT_NILAI) {
            throw new PelanggaranAturanBisnis('HppTidakValid', 'Total nilai stok awal terlalu besar untuk satu dokumen. Pecah menjadi beberapa dokumen.', 'Baris');
        }
    }

    private static function HitungDigitBulat(BigDecimal $nilai): int
    {
        return strlen((string) $nilai->abs()->toScale(0, RoundingMode::Down));
    }
}
