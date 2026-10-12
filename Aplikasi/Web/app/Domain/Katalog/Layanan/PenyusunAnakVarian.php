<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Harga\Data\DataBarisHarga;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;

/**
 * Anak varian (F-03 `GenerasikanVarian`, `TambahVarianAnak`): kunci varian, nama, SKU `{SkuInduk}-{NN}`, salinan
 * kolom induk, satuan dasar, dan harga dasar lewat Tim 2 (sumber `Varian`). Juga meneruskan kolom bersama induk ke
 * anak saat induk diubah. Dipanggil di dalam transaksi Aksi (Tenant & batas paket sudah dikunci pemanggil).
 */
final class PenyusunAnakVarian
{
    private const PANJANG_SKU_MAKSIMAL = 64;

    public function __construct(
        private readonly AturanProduk $aturan,
        private readonly PenghubungHargaProduk $harga,
    ) {}

    /**
     * `lower(trim(Nama))=lower(trim(Nilai))` digabung `|`, urutan atribut induk.
     *
     * @param  list<array{Nama: string, Nilai: string}>  $atribut
     */
    public static function BuatKunci(array $atribut): string
    {
        return implode('|', array_map(fn (array $a): string => mb_strtolower(trim($a['Nama'])).'='.mb_strtolower(trim($a['Nilai'])), $atribut));
    }

    /**
     * Nama anak "{Induk} {Nilai1} / {Nilai2}", maks. 150 karakter.
     *
     * @param  list<array{Nama: string, Nilai: string}>  $atribut
     */
    public static function BuatNama(Produk $induk, array $atribut): string
    {
        return mb_substr($induk->Nama.' '.implode(' / ', array_column($atribut, 'Nilai')), 0, 150);
    }

    /**
     * @param  list<array{Nama: string, Nilai: string}>  $atribut  urut sesuai definisi induk
     */
    public function Buat(Produk $induk, array $atribut, ?string $sku, JenisProduk $jenis, ?Uang $hargaDasar, string $sumberHarga): Produk
    {
        $this->aturan->PastikanJenisAnak($jenis, 'JenisAnak');
        $sku = trim((string) $sku) === '' ? $this->BuatSkuAnak($induk) : $this->aturan->TentukanSku($sku, null);
        $diarsipkan = $induk->DiarsipkanPada !== null;

        $anak = Produk::query()->create([
            'Sku' => $sku,
            'Nama' => self::BuatNama($induk, $atribut),
            'Jenis' => $jenis,
            'IdInduk' => $induk->Id,
            'AtributVarian' => $atribut,
            'KunciVarian' => self::BuatKunci($atribut),
            'IdKategori' => $induk->IdKategori,
            'Merek' => $induk->Merek,
            'IdSatuanDasar' => $induk->IdSatuanDasar,
            'Pelacakan' => PelacakanProduk::Tidak,
            'IdKelompokPajak' => $induk->IdKelompokPajak,
            'HargaTermasukPajak' => $induk->HargaTermasukPajak,
            'BolehMinus' => $induk->BolehMinus,
            'TampilDiPos' => AturanProduk::TentukanTampilDiPos($jenis, $induk->TampilDiPos),
            'TampilOnline' => $induk->TampilOnline,
            'Favorit' => AturanProduk::TentukanFavorit($jenis, $induk->Favorit),
            'Aktif' => ! $diarsipkan,
            'DiarsipkanPada' => $induk->DiarsipkanPada,
        ]);
        $satuan = ProdukSatuan::query()->create([
            'IdProduk' => $anak->Id,
            'IdSatuan' => $induk->IdSatuanDasar,
            'KonversiKeDasar' => '1',
            'DefaultJual' => true,
            'DefaultBeli' => true,
        ]);

        if ($hargaDasar !== null) {
            $this->harga->SimpanHargaDasar($anak, [$satuan->Id => [new DataBarisHarga(Kuantitas::Dari(1), $hargaDasar)]], $sumberHarga, [$satuan->Id => 'HargaDasar']);
        }

        return $anak;
    }

    /**
     * Mengurutkan atribut anak sesuai definisi induk. Induk tanpa anak boleh menerima atribut baru (definisi
     * diperluas); nilai baru pada atribut yang ada selalu boleh (maks. `katalog.Varian.MaksimalNilai`).
     *
     * @param  list<array{Nama: string, Nilai: string}>  $atribut
     * @return array{0: list<array{Nama: string, Nilai: string}>, 1: list<array{Nama: string, Nilai: list<string>}>}
     */
    public function SelaraskanDefinisi(Produk $induk, array $atribut): array
    {
        $definisi = array_values(array_map(fn (array $a): array => ['Nama' => (string) $a['Nama'], 'Nilai' => array_values(array_map('strval', (array) $a['Nilai']))], $induk->AtributVarian ?? []));
        $punyaAnak = Produk::query()->withTrashed()->where('IdInduk', $induk->Id)->exists();
        $masukan = [];

        foreach ($atribut as $satu) {
            $nama = trim($satu['Nama']);
            $nilai = trim($satu['Nilai']);

            if ($nama === '' || $nilai === '' || isset($masukan[mb_strtolower($nama)])) {
                throw new PelanggaranAturanBisnis('AtributVarianTidakValid', 'Setiap atribut varian butuh nama dan nilai, tanpa nama ganda.', 'AtributVarian');
            }

            $masukan[mb_strtolower($nama)] = ['Nama' => $nama, 'Nilai' => $nilai];
        }

        foreach ($masukan as $kunci => $satu) {
            if (! in_array($kunci, array_map(fn (array $a): string => mb_strtolower($a['Nama']), $definisi), true)) {
                if ($punyaAnak) {
                    throw new PelanggaranAturanBisnis('AtributVarianDipakai', "Atribut {$satu['Nama']} belum ada di induk. Atribut baru tidak bisa ditambahkan setelah varian dibuat.", 'AtributVarian');
                }

                $definisi[] = ['Nama' => $satu['Nama'], 'Nilai' => []];
            }
        }

        $urut = [];

        foreach ($definisi as $i => $atributInduk) {
            $satu = $masukan[mb_strtolower($atributInduk['Nama'])] ?? null;

            if ($satu === null) {
                throw new PelanggaranAturanBisnis('AtributVarianTidakValid', "Nilai atribut {$atributInduk['Nama']} wajib diisi.", 'AtributVarian');
            }

            if (! in_array(mb_strtolower($satu['Nilai']), array_map('mb_strtolower', $atributInduk['Nilai']), true)) {
                $definisi[$i]['Nilai'][] = $satu['Nilai'];
            }

            $urut[] = ['Nama' => $atributInduk['Nama'], 'Nilai' => $satu['Nilai']];
        }

        if (count($definisi) > (int) config('katalog.Varian.MaksimalAtribut', 3)) {
            throw new PelanggaranAturanBisnis('VarianTerlaluBanyak', 'Maksimal '.config('katalog.Varian.MaksimalAtribut', 3).' atribut varian.', 'AtributVarian');
        }

        foreach ($definisi as $atributInduk) {
            if (count($atributInduk['Nilai']) > (int) config('katalog.Varian.MaksimalNilai', 20)) {
                throw new PelanggaranAturanBisnis('VarianTerlaluBanyak', "Atribut {$atributInduk['Nama']} maksimal ".config('katalog.Varian.MaksimalNilai', 20).' nilai.', 'AtributVarian');
            }
        }

        return [$urut, $definisi];
    }

    /** IndukVarian: kolom bersama diteruskan ke semua anak yang belum dihapus. */
    public static function TeruskanDariInduk(Produk $induk): void
    {
        Produk::query()->where('IdInduk', $induk->Id)->update([
            'IdKategori' => $induk->IdKategori,
            'Merek' => $induk->Merek,
            'IdKelompokPajak' => $induk->IdKelompokPajak,
            'HargaTermasukPajak' => $induk->HargaTermasukPajak,
            'TampilDiPos' => $induk->TampilDiPos,
            'TampilOnline' => $induk->TampilOnline,
            'Favorit' => $induk->Favorit,
        ]);
        app(PemberitahuProdukDiubah::class)->TandaiBanyak(Produk::query()->where('IdInduk', $induk->Id)->get(['Id', 'Uuid', 'IdTenant']));
    }

    /** `{SkuInduk}-{NN}`: nomor bebas berikutnya per induk (minimal 2 digit). */
    private function BuatSkuAnak(Produk $induk): string
    {
        $awalan = (string) $induk->Sku;
        $terbesar = 0;

        foreach (Produk::query()->withTrashed()->where('IdInduk', $induk->Id)->whereNotNull('Sku')->pluck('Sku') as $sku) {
            if (preg_match('/^'.preg_quote($awalan, '/').'-(\d+)$/', (string) $sku, $cocok) === 1) {
                $terbesar = max($terbesar, (int) $cocok[1]);
            }
        }

        do {
            $akhiran = '-'.str_pad((string) ++$terbesar, 2, '0', STR_PAD_LEFT);
            // SKU maksimal 64 karakter (kolom & POLA_SKU): awalan induk dipotong bila perlu.
            $sku = rtrim(substr($awalan, 0, self::PANJANG_SKU_MAKSIMAL - strlen($akhiran)), '-').$akhiran;
        } while (PembuatSku::CekSkuTerpakai($sku));

        return $sku;
    }
}
