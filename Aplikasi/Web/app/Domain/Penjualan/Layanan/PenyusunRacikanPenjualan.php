<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Data\DataKebutuhanStok;
use App\Domain\Katalog\Data\DataProdukPenjualan;
use App\Domain\Katalog\Enum\GolonganObat;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\KomposisiPenjualan;
use App\Domain\Penjualan\Data\DataPenjualanPos;
use App\Domain\Penjualan\Data\DataRacikanSiap;
use Brick\Math\BigDecimal;

/**
 * Apotek bagian 3 (PRD §9.5 "racikan: resep racik sebagai produk `recipe` sementara"): memvalidasi blok
 * `Baris[].Racikan` dan menyusun kebutuhan stoknya. Racikan = resep sementara milik satu baris penjualan:
 *
 * - baris pembawanya produk **Jasa** (jasa racik; harganya harga racikan yang disepakati kasir, termasuk tuslah), tanpa
 *   pilihan & nomor seri;
 * - komponennya obat/bahan **berstok** (Stok, Produksi, Bahan baku), tidak ganda, tanpa nomor seri (obat ber-batch
 *   diambil FEFO seperti bahan resep biasa); jumlah komponen = untuk satu racikan, dikali jumlah baris;
 * - golongan racikan = golongan **terkuat** komponennya, dan racikan tidak pernah dianggap Obat Wajib Apotek (racikan
 *   berasal dari resep dokter), sehingga racikan berisi obat keras wajib resep & apoteker.
 *
 * Racikan yang tidak valid ditolak (`DataTidakValid` dkk.): komposisinya ditentukan apoteker di depan pasien, bukan
 * kondisi offline yang perlu diterima lalu ditinjau.
 */
final class PenyusunRacikanPenjualan
{
    public const MAKS_KOMPONEN = 20;

    private const JENIS_KOMPONEN = [JenisProduk::Stok, JenisProduk::Produksi, JenisProduk::BahanBaku];

    public function __construct(private readonly KomposisiPenjualan $komposisi) {}

    /**
     * @param  array<string, DataProdukPenjualan>  $produk  produk baris (kunci Uuid)
     * @return array<int, DataRacikanSiap> kunci = indeks baris
     *
     * @throws PelanggaranAturanBisnis
     */
    public function Siapkan(DataPenjualanPos $data, array $produk): array
    {
        $uuid = [];

        foreach ($data->baris as $baris) {
            foreach ($baris->racikan->komponen ?? [] as $k) {
                $uuid[] = $k['UuidProduk'];
            }
        }

        if ($uuid === []) {
            return [];
        }

        $komponenProduk = $this->komposisi->AmbilProduk(array_values(array_unique($uuid)));
        $hasil = [];

        foreach ($data->baris as $indeks => $baris) {
            $racikan = $baris->racikan;

            if ($racikan === null) {
                continue;
            }

            $bidang = "Baris.{$indeks}.Racikan";
            $wadah = $produk[$baris->uuidProduk];

            if ($wadah->jenis !== JenisProduk::Jasa) {
                throw new PelanggaranAturanBisnis('RacikanBukanJasa', "Racikan dicatat pada produk jasa racik; {$wadah->nama} berjenis {$wadah->jenis->AmbilLabel()}.", "Baris.{$indeks}.UuidProduk");
            }

            if ($baris->pilihan !== [] || $baris->nomorSeri !== []) {
                throw new PelanggaranAturanBisnis('DataTidakValid', 'Baris racikan tidak bisa membawa pilihan atau nomor seri.', $bidang);
            }

            if ($racikan->nama === '') {
                throw new PelanggaranAturanBisnis('DataTidakValid', 'Nama racikan wajib diisi.', "{$bidang}.Nama");
            }

            $kebutuhan = [];
            $komponen = [];
            $dipakai = [];
            $golongan = null;

            foreach ($racikan->komponen as $i => $k) {
                $bidangKomponen = "{$bidang}.Komponen.{$i}";
                $p = $komponenProduk[$k['UuidProduk']] ?? throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Obat racikan ke-'.($i + 1).' tidak ditemukan.', "{$bidangKomponen}.UuidProduk");

                if (! in_array($p->jenis, self::JENIS_KOMPONEN, true)) {
                    throw new PelanggaranAturanBisnis('KomponenRacikanTidakValid', "{$p->nama} berjenis {$p->jenis->AmbilLabel()} tidak bisa menjadi bahan racikan. Pakai produk berstok.", "{$bidangKomponen}.UuidProduk");
                }

                if ($p->pelacakan === PelacakanProduk::Seri) {
                    throw new PelanggaranAturanBisnis('PelacakanBelumDidukung', "{$p->nama} memakai nomor seri sehingga tidak bisa menjadi bahan racikan.", "{$bidangKomponen}.UuidProduk");
                }

                if (isset($dipakai[$p->id])) {
                    throw new PelanggaranAturanBisnis('KomponenRacikanGanda', "{$p->nama} tercantum dua kali di racikan {$racikan->nama}. Gabungkan jumlahnya.", "{$bidangKomponen}.UuidProduk");
                }

                $dipakai[$p->id] = true;
                $konversi = '1';

                if ($k['UuidProdukSatuan'] !== null) {
                    $konversi = $p->satuan[$k['UuidProdukSatuan']]['KonversiKeDasar']
                        ?? throw new PelanggaranAturanBisnis('SatuanTidakDikenal', "Satuan {$p->nama} tidak ditemukan.", "{$bidangKomponen}.UuidProdukSatuan");
                }

                $dasar = $k['Jumlah']->KeDesimal()->multipliedBy($konversi);
                $kebutuhan[] = [
                    new DataKebutuhanStok($p->id, $p->uuid, $p->nama, $p->jenis, $p->pelacakan, BigDecimal::one(), BigDecimal::one(), "Racikan {$racikan->nama}", $p->dihapus),
                    $dasar,
                ];
                $golongan = self::Terkuat($golongan, $p->golonganObat);
                $komponen[] = [
                    'UuidProduk' => $p->uuid,
                    'NamaProduk' => mb_substr($p->nama, 0, 255),
                    'UuidProdukSatuan' => $k['UuidProdukSatuan'],
                    'Jumlah' => $k['Jumlah']->KeString(),
                    'JumlahDasar' => (string) $dasar->toScale(4),
                    'GolonganObat' => $p->golonganObat?->value,
                ];
            }

            $hasil[$indeks] = new DataRacikanSiap($racikan->nama, $kebutuhan, $golongan, [
                'Nama' => mb_substr($racikan->nama, 0, 100),
                'JumlahKemasan' => $racikan->jumlahKemasan,
                'AturanPakai' => $racikan->aturanPakai === null ? null : mb_substr($racikan->aturanPakai, 0, 100),
                'Komponen' => $komponen,
            ]);
        }

        return $hasil;
    }

    /** Golongan dengan pengawasan lebih ketat (urutan kasus enum: Bebas → Narkotika). */
    private static function Terkuat(?GolonganObat $a, ?GolonganObat $b): ?GolonganObat
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        $urutan = GolonganObat::cases();

        return array_search($b, $urutan, true) > array_search($a, $urutan, true) ? $b : $a;
    }
}
