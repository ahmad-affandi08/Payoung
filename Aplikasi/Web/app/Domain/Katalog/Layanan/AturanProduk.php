<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Data\DataAtributVarian;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kontrak\PemeriksaRiwayatStok;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\Satuan;

/**
 * Aturan produk bersama `SimpanProduk`, `PerbaruiProdukSebagian`, dan Aksi varian (F-03 C.1, C.2, C.5): jenis,
 * pelacakan, kelompok pajak, SKU, barcode, dan definisi atribut varian. Semua pemeriksaan di dalam transaksi Aksi.
 */
final class AturanProduk
{
    public const POLA_SKU = '/^[A-Za-z0-9][A-Za-z0-9._\-\/]{0,63}$/';

    public const POLA_BARCODE = '/^[A-Za-z0-9\-\.]{3,64}$/';

    public function __construct(
        private readonly PenilaiPemakaianProduk $penilai,
        private readonly PembuatSku $pembuatSku,
        private readonly PemeriksaRiwayatStok $riwayatStok,
    ) {}

    /** C.1: jenis tidak pernah berubah ke/dari IndukVarian; selain itu hanya bila produk belum dipakai. */
    public function PastikanJenisBolehDiubah(Produk $produk, JenisProduk $baru): void
    {
        if ($produk->Jenis === $baru) {
            return;
        }

        if ($produk->Jenis === JenisProduk::IndukVarian || $baru === JenisProduk::IndukVarian) {
            throw new PelanggaranAturanBisnis('JenisTidakBolehDiubah', 'Jenis induk varian tidak bisa diubah, dan produk lain tidak bisa diubah menjadi induk varian. Buat produk induk varian baru.', 'Jenis');
        }

        $alasan = $this->penilai->AmbilAlasan($produk);

        if ($alasan !== null) {
            throw new PelanggaranAturanBisnis('JenisTidakBolehDiubah', "Jenis produk tidak bisa diubah karena produk {$alasan}.", 'Jenis');
        }
    }

    /**
     * F-05a (DesainF05a C.4): `Pelacakan` (Tidak/Batch/Seri) terkunci begitu produk punya riwayat stok, karena batch,
     * nomor seri, dan saldo yang sudah tercatat mengikuti pelacakan lama.
     */
    public function PastikanPelacakanBolehDiubah(Produk $produk, PelacakanProduk $baru): void
    {
        if ($produk->Pelacakan === $baru || ! $this->riwayatStok->CekPunyaRiwayatStok($produk->Id)) {
            return;
        }

        throw new PelanggaranAturanBisnis(
            'PelacakanTerkunci',
            "Pelacakan tidak bisa diubah dari {$produk->Pelacakan->AmbilLabel()} karena produk sudah punya riwayat stok. Buat produk baru bila cara pelacakannya perlu berbeda.",
            'Pelacakan',
        );
    }

    /** Anak varian hanya boleh berjenis `CekBolehAnakVarian()`. */
    public function PastikanJenisAnak(JenisProduk $jenis, string $bidang = 'Jenis'): void
    {
        if (! $jenis->CekBolehAnakVarian()) {
            throw new PelanggaranAturanBisnis('JenisTidakMendukung', "Varian tidak bisa berjenis {$jenis->AmbilLabel()}.", $bidang);
        }
    }

    public function PastikanKategori(?int $idKategori): void
    {
        if ($idKategori !== null && ! Kategori::query()->whereKey($idKategori)->exists()) {
            throw new PelanggaranAturanBisnis('KategoriTidakDikenal', 'Kategori tidak ditemukan. Muat ulang halaman lalu pilih lagi.', 'UuidKategori');
        }
    }

    public function AmbilSatuan(int $idSatuan, string $bidang = 'UuidSatuanDasar'): Satuan
    {
        return Satuan::query()->whereKey($idSatuan)->first()
            ?? throw new PelanggaranAturanBisnis('SatuanDasarWajib', 'Satuan tidak ditemukan. Muat ulang halaman lalu pilih lagi.', $bidang);
    }

    /**
     * C.1: `Pelacakan` ≠ Tidak hanya untuk jenis yang boleh; Seri butuh satuan dasar tanpa desimal dan memaksa
     * `BolehMinus = false`.
     *
     * @return array{0: PelacakanProduk, 1: bool|null}
     */
    public function TentukanPelacakan(JenisProduk $jenis, PelacakanProduk $pelacakan, Satuan $satuanDasar, ?bool $bolehMinus): array
    {
        if ($pelacakan === PelacakanProduk::Tidak) {
            return [$pelacakan, $bolehMinus];
        }

        if (! $jenis->CekBolehPelacakan()) {
            throw new PelanggaranAturanBisnis('JenisTidakMendukung', "Produk {$jenis->AmbilLabel()} tidak memakai pelacakan batch atau nomor seri.", 'Pelacakan');
        }

        if ($pelacakan === PelacakanProduk::Seri) {
            if ($satuanDasar->BolehDesimal) {
                throw new PelanggaranAturanBisnis('JenisTidakMendukung', 'Nomor seri butuh satuan dasar tanpa desimal, misal pcs atau unit.', 'Pelacakan');
            }

            return [$pelacakan, false];
        }

        return [$pelacakan, $bolehMinus];
    }

    /** C.2: kelompok pajak wajib untuk produk yang bisa dijual dan induk varian. */
    public function PastikanKelompokPajak(JenisProduk $jenis, ?int $idKelompokPajak): void
    {
        if ($idKelompokPajak === null && ($jenis->CekBisaDijual() || $jenis === JenisProduk::IndukVarian)) {
            throw new PelanggaranAturanBisnis('KelompokPajakWajib', 'Pilih kelompok pajak agar pajak di struk benar.', 'UuidKelompokPajak');
        }
    }

    /** BahanBaku tidak pernah tampil di POS (C.1). */
    public static function TentukanTampilDiPos(JenisProduk $jenis, bool $tampilDiPos): bool
    {
        return $jenis === JenisProduk::BahanBaku ? false : $tampilDiPos;
    }

    /** K-16: BahanBaku tidak pernah tampil di POS, jadi tidak pernah favorit. Induk varian boleh (induklah yang tampil di kasir). */
    public static function TentukanFavorit(JenisProduk $jenis, bool $favorit): bool
    {
        return $jenis === JenisProduk::BahanBaku ? false : $favorit;
    }

    /** BR-03.1: SKU di-trim; kosong = otomatis; pola & keunikan per tenant (tanpa beda huruf besar/kecil). */
    public function TentukanSku(?string $sku, ?int $idProduk): string
    {
        $sku = trim((string) $sku);

        if ($sku === '') {
            return $this->pembuatSku->Buat();
        }

        if (preg_match(self::POLA_SKU, $sku) !== 1) {
            throw new PelanggaranAturanBisnis('BR-03.1', 'SKU 1–64 karakter: huruf, angka, titik, garis bawah, tanda hubung, atau garis miring, diawali huruf/angka.', 'Sku');
        }

        if (PembuatSku::CekSkuTerpakai($sku, $idProduk)) {
            throw new PelanggaranAturanBisnis('BR-03.1', "SKU {$sku} sudah dipakai produk lain. Pakai SKU lain atau kosongkan agar dibuat otomatis.", 'Sku');
        }

        return $sku;
    }

    public static function NormalisasiBarcode(string $barcode, string $bidang): string
    {
        $barcode = trim($barcode);

        if (preg_match(self::POLA_BARCODE, $barcode) !== 1) {
            throw new PelanggaranAturanBisnis('BR-03.1', 'Barcode 3–64 karakter: huruf, angka, titik, atau tanda hubung.', $bidang);
        }

        return $barcode;
    }

    /**
     * Definisi atribut induk varian: maks. `katalog.Varian.MaksimalAtribut` atribut dan `MaksimalNilai` nilai, nama
     * dan nilai unik tanpa beda huruf besar/kecil. Bila induk sudah punya anak: set atribut tetap, dan nilai yang
     * dipakai anak tidak boleh dihapus (`AtributVarianDipakai`).
     *
     * @param  list<DataAtributVarian>  $atribut
     * @return list<array{Nama: string, Nilai: list<string>}>
     */
    public function NormalisasiDefinisiVarian(array $atribut, ?Produk $induk): array
    {
        $maksimalAtribut = (int) config('katalog.Varian.MaksimalAtribut', 3);
        $maksimalNilai = (int) config('katalog.Varian.MaksimalNilai', 20);

        if (count($atribut) > $maksimalAtribut) {
            throw new PelanggaranAturanBisnis('VarianTerlaluBanyak', "Maksimal {$maksimalAtribut} atribut varian.", 'AtributVarian');
        }

        $hasil = [];
        $namaAda = [];

        foreach ($atribut as $i => $satu) {
            $nama = trim($satu->nama);

            if ($nama === '' || mb_strlen($nama) > 30 || isset($namaAda[mb_strtolower($nama)])) {
                throw new PelanggaranAturanBisnis('AtributVarianTidakValid', 'Nama atribut wajib diisi (maks. 30 karakter) dan tidak boleh sama.', "AtributVarian.{$i}.Nama");
            }

            $namaAda[mb_strtolower($nama)] = true;
            $nilai = [];
            $nilaiAda = [];

            foreach ($satu->nilai as $isi) {
                $isi = trim($isi);

                if ($isi === '' || isset($nilaiAda[mb_strtolower($isi)])) {
                    continue;
                }

                if (mb_strlen($isi) > 40) {
                    throw new PelanggaranAturanBisnis('AtributVarianTidakValid', 'Nilai atribut maksimal 40 karakter.', "AtributVarian.{$i}.Nilai");
                }

                $nilaiAda[mb_strtolower($isi)] = true;
                $nilai[] = $isi;
            }

            if ($nilai === [] || count($nilai) > $maksimalNilai) {
                throw new PelanggaranAturanBisnis('VarianTerlaluBanyak', "Isi 1–{$maksimalNilai} nilai untuk atribut {$nama}.", "AtributVarian.{$i}.Nilai");
            }

            $hasil[] = ['Nama' => $nama, 'Nilai' => $nilai];
        }

        if ($induk !== null) {
            $this->PastikanNilaiDipakaiTetapAda($induk, $hasil);
        }

        return $hasil;
    }

    /**
     * @param  list<array{Nama: string, Nilai: list<string>}>  $definisi
     */
    private function PastikanNilaiDipakaiTetapAda(Produk $induk, array $definisi): void
    {
        $anak = Produk::query()->where('IdInduk', $induk->Id)->get(['Id', 'Nama', 'AtributVarian']);

        if ($anak->isEmpty()) {
            return;
        }

        $peta = [];

        foreach ($definisi as $atribut) {
            $peta[mb_strtolower($atribut['Nama'])] = array_map('mb_strtolower', $atribut['Nilai']);
        }

        $namaLama = array_map(fn (array $atribut): string => mb_strtolower((string) ($atribut['Nama'] ?? '')), $induk->AtributVarian ?? []);
        $namaBaru = array_keys($peta);
        sort($namaLama);
        sort($namaBaru);

        if ($namaLama !== $namaBaru) {
            throw new PelanggaranAturanBisnis('AtributVarianDipakai', 'Atribut varian tidak bisa ditambah, dihapus, atau diganti namanya setelah varian dibuat. Tambah nilai baru saja.', 'AtributVarian');
        }

        $indeksNama = array_flip(array_map(fn (array $atribut): string => mb_strtolower($atribut['Nama']), $definisi));

        foreach ($anak as $satu) {
            foreach ($satu->AtributVarian ?? [] as $atribut) {
                $nama = mb_strtolower((string) ($atribut['Nama'] ?? ''));
                $nilai = (string) ($atribut['Nilai'] ?? '');

                if (! in_array(mb_strtolower($nilai), $peta[$nama] ?? [], true)) {
                    throw new PelanggaranAturanBisnis('AtributVarianDipakai', "Nilai {$nilai} dipakai varian {$satu->Nama}. Arsipkan atau hapus varian itu dulu.", 'AtributVarian.'.($indeksNama[$nama] ?? 0).'.Nilai');
                }
            }
        }
    }
}
