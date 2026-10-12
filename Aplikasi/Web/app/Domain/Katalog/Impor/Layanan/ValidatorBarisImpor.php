<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Impor\Layanan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Impor\Data\DataOpsiImpor;
use App\Domain\Katalog\Impor\Data\DataPresetImpor;
use App\Domain\Katalog\Impor\Enum\BidangImpor;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Pajak\Enum\KategoriPajakProduk;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Referensi\Kueri\SatuanStandarAktif;
use InvalidArgumentException;

/**
 * Validasi satu baris impor (F-03 BR-03.6, fase A): mengurai sel menurut pemetaan & preset, lalu menghasilkan data
 * ternormalisasi dan daftar galat per bidang. Tidak menulis apa pun; rujukan (satuan, kategori, kelompok pajak)
 * hanya dicari, hasilnya disimpan sementara selama satu validasi. Aturan antar-baris (SKU/barcode ganda, produk yang
 * sudah ada, batas SKU) ada di fase B (`PemvalidasiImpor`).
 *
 * Kunci `Data`: Nama, NamaStruk, Sku, Jenis (diisi eksplisit atau null), JenisAkhir, Kategori (jalur), Merek, Satuan,
 * HargaJual, Barcode, IdKelompokPajak, HargaTermasukPajak (Ya|Tidak|Ikut|null), TampilDiPos, TampilOnline,
 * Favorit (Ya|Tidak|null; K-16, bahan baku tetap tidak favorit lewat aturan produk), BolehMinus, Pelacakan, Status, NamaInduk, Varian, SatuanAlternatif [{Nomor, Satuan, Konversi, Harga, Barcode}],
 * Grosir ([{JumlahMinimum, Harga}] atau null bila kolom grosir tidak dipetakan). Null/kosong = tidak diubah.
 */
final class ValidatorBarisImpor
{
    /** @var array<string, bool> */
    private array $satuanAda = [];

    /** @var array<string, bool> */
    private array $kategoriAda = [];

    /** @var array<string, int|null> */
    private array $kelompokPajak = [];

    public function __construct(
        private readonly DaftarKelompokPajak $daftarKelompokPajak,
        private readonly SatuanStandarAktif $satuanStandar,
    ) {}

    /**
     * @param  list<string>  $sel
     * @param  array<string, int|null>  $pemetaan
     * @return array{Data: array<string, mixed>, Galat: list<array{Bidang: string, Pesan: string}>}
     */
    public function Validasi(array $sel, array $pemetaan, DataPresetImpor $preset, DataOpsiImpor $opsi): array
    {
        $galat = [];
        $ambil = function (BidangImpor $bidang) use ($sel, $pemetaan): string {
            $indeks = $pemetaan[$bidang->value] ?? null;

            return $indeks === null ? '' : trim($sel[$indeks] ?? '');
        };
        $urai = function (BidangImpor $bidang, callable $pengurai) use ($ambil, &$galat): mixed {
            try {
                return $pengurai($ambil($bidang));
            } catch (InvalidArgumentException $e) {
                $galat[] = ['Bidang' => $bidang->AmbilJudul(), 'Pesan' => $e->getMessage()];

                return null;
            }
        };

        $nama = preg_replace('/\s+/u', ' ', $ambil(BidangImpor::Nama)) ?? '';

        if ($nama === '') {
            $galat[] = ['Bidang' => BidangImpor::Nama->AmbilJudul(), 'Pesan' => 'Nama produk wajib diisi.'];
        } elseif (mb_strlen($nama) > 150) {
            $galat[] = ['Bidang' => BidangImpor::Nama->AmbilJudul(), 'Pesan' => 'Nama produk maksimal 150 karakter.'];
        }

        $namaStruk = self::TeksMaksimal($ambil(BidangImpor::NamaStruk), 40, BidangImpor::NamaStruk, $galat);
        $merek = self::TeksMaksimal($ambil(BidangImpor::Merek), 60, BidangImpor::Merek, $galat);
        $sku = $urai(BidangImpor::Sku, fn (string $t): ?string => PenguraiNilaiImpor::UraiSku($t));
        $jenis = $urai(BidangImpor::Jenis, fn (string $t): ?JenisProduk => PenguraiNilaiImpor::UraiJenis($t, $preset));

        if ($jenis === null) {
            $lacak = $urai(BidangImpor::LacakStok, fn (string $t): ?bool => PenguraiNilaiImpor::UraiBoolean($t, $preset));
            $jenis = $lacak === null ? null : ($lacak ? JenisProduk::Stok : JenisProduk::NonStok);
        }

        $jenisAkhir = $jenis ?? $opsi->jenisBawaan;
        $kategori = $urai(BidangImpor::Kategori, fn (string $t): array => PenguraiNilaiImpor::UraiJalurKategori($t));

        if (is_array($kategori) && $kategori !== [] && ! $opsi->buatKategoriBaru && ! $this->CekKategoriAda(array_values(array_map('strval', $kategori)))) {
            $galat[] = ['Bidang' => BidangImpor::Kategori->AmbilJudul(), 'Pesan' => 'Kategori '.implode(' > ', $kategori).' belum ada. Buat dulu, atau centang "Buat kategori baru".'];
        }

        $satuan = $ambil(BidangImpor::Satuan);
        $this->PeriksaSatuan($satuan, BidangImpor::Satuan, $opsi, $galat);
        $hargaJual = $urai(BidangImpor::HargaJual, fn (string $t): ?string => PenguraiNilaiImpor::UraiUang($t)?->KeString());
        $barcode = $urai(BidangImpor::Barcode, fn (string $t): array => PenguraiNilaiImpor::UraiBarcode($t)) ?? [];
        $idKelompokPajak = null;
        $teksPajak = $ambil(BidangImpor::KelompokPajak);

        if ($teksPajak !== '') {
            $idKelompokPajak = $this->CariKelompokPajak($teksPajak, $preset);

            if ($idKelompokPajak === null) {
                $galat[] = ['Bidang' => BidangImpor::KelompokPajak->AmbilJudul(), 'Pesan' => "Kelompok pajak \"{$teksPajak}\" tidak dikenali. Pakai nama kelompok pajak di menu Kelompok pajak."];
            }
        }

        $varian = $urai(BidangImpor::Varian, fn (string $t): array => PenguraiNilaiImpor::UraiVarian($t, $preset)) ?? [];
        $namaInduk = null;

        if ($preset->modeVarian === DataPresetImpor::MODE_VARIAN_KOLOM_INDUK) {
            $namaInduk = $ambil(BidangImpor::NamaInduk) === '' ? null : $ambil(BidangImpor::NamaInduk);

            if ($namaInduk !== null && $varian === [] && $ambil(BidangImpor::Varian) === '') {
                $galat[] = ['Bidang' => BidangImpor::Varian->AmbilJudul(), 'Pesan' => 'Isi nilai varian, misal "Ukuran: M", untuk produk varian.'];
            } elseif ($namaInduk === null && $varian !== []) {
                $galat[] = ['Bidang' => BidangImpor::NamaInduk->AmbilJudul(), 'Pesan' => 'Isi nama produk induk untuk baris varian.'];
            }
        } elseif ($preset->modeVarian === DataPresetImpor::MODE_VARIAN_BARIS_PER_VARIAN && $varian !== []) {
            $namaInduk = $nama;
        } else {
            $varian = [];
        }

        if ($namaInduk !== null && mb_strlen($namaInduk) > 150) {
            $galat[] = ['Bidang' => BidangImpor::NamaInduk->AmbilJudul(), 'Pesan' => 'Nama induk maksimal 150 karakter.'];
        }

        if ($namaInduk !== null && ! $jenisAkhir->CekBolehAnakVarian()) {
            $galat[] = ['Bidang' => BidangImpor::Jenis->AmbilJudul(), 'Pesan' => "Jenis {$jenisAkhir->AmbilLabel()} tidak bisa menjadi varian."];
        }

        $satuanAlternatif = $this->ValidasiSatuanAlternatif($ambil, $urai, $satuan, $opsi, $galat);
        $grosir = $this->ValidasiGrosir($pemetaan, $urai, $galat);

        if ($grosir !== null && $grosir !== [] && $hargaJual === null && $ambil(BidangImpor::HargaJual) === '') {
            $galat[] = ['Bidang' => BidangImpor::HargaJual->AmbilJudul(), 'Pesan' => 'Isi harga jual bila mengisi harga grosir.'];
        }

        $semuaBarcode = array_map('mb_strtolower', $barcode);

        foreach ($satuanAlternatif as $alternatif) {
            $semuaBarcode = [...$semuaBarcode, ...array_map('mb_strtolower', $alternatif['Barcode'])];
        }

        if (count(array_unique($semuaBarcode)) !== count($semuaBarcode)) {
            $galat[] = ['Bidang' => BidangImpor::Barcode->AmbilJudul(), 'Pesan' => 'Barcode yang sama ditulis lebih dari sekali di baris ini.'];
        }

        return [
            'Data' => [
                'Nama' => $nama,
                'NamaStruk' => $namaStruk,
                'Sku' => $sku,
                'Jenis' => $jenis?->value,
                'JenisAkhir' => $jenisAkhir->value,
                'Kategori' => is_array($kategori) && $kategori !== [] ? $kategori : null,
                'Merek' => $merek,
                'Satuan' => $satuan === '' ? null : $satuan,
                'HargaJual' => $hargaJual,
                'Barcode' => $barcode,
                'IdKelompokPajak' => $idKelompokPajak,
                'HargaTermasukPajak' => $urai(BidangImpor::HargaTermasukPajak, fn (string $t): ?string => PenguraiNilaiImpor::UraiTigaKeadaan($t, $preset)),
                'TampilDiPos' => $urai(BidangImpor::TampilDiPos, fn (string $t): ?bool => PenguraiNilaiImpor::UraiBoolean($t, $preset)),
                'TampilOnline' => $urai(BidangImpor::TampilOnline, fn (string $t): ?bool => PenguraiNilaiImpor::UraiBoolean($t, $preset)),
                'Favorit' => $urai(BidangImpor::Favorit, fn (string $t): ?bool => PenguraiNilaiImpor::UraiBoolean($t, $preset)),
                'BolehMinus' => $urai(BidangImpor::BolehMinus, fn (string $t): ?bool => PenguraiNilaiImpor::UraiBoolean($t, $preset)),
                'Pelacakan' => $urai(BidangImpor::Pelacakan, fn (string $t): ?string => PenguraiNilaiImpor::UraiPelacakan($t)?->value),
                'Status' => $urai(BidangImpor::Status, fn (string $t): ?string => PenguraiNilaiImpor::UraiStatus($t, $preset)?->value),
                'NamaInduk' => $namaInduk,
                'Varian' => $namaInduk === null ? [] : $varian,
                'SatuanAlternatif' => $satuanAlternatif,
                'Grosir' => $grosir,
            ],
            'Galat' => $galat,
        ];
    }

    /**
     * @param  callable(BidangImpor): string  $ambil
     * @param  callable(BidangImpor, callable): mixed  $urai
     * @param  list<array{Bidang: string, Pesan: string}>  $galat
     * @return list<array{Nomor: int, Satuan: string, Konversi: string, Harga: string|null, Barcode: list<string>}>
     */
    private function ValidasiSatuanAlternatif(callable $ambil, callable $urai, string $satuanDasar, DataOpsiImpor $opsi, array &$galat): array
    {
        $hasil = [];
        $dipakai = [mb_strtolower($satuanDasar === '' ? 'pcs' : $satuanDasar) => true];

        for ($nomor = 1; $nomor <= BidangImpor::JUMLAH_SATUAN_ALTERNATIF; $nomor++) {
            $bidangSatuan = BidangImpor::SatuanAlternatif($nomor);
            $satuan = $ambil($bidangSatuan);
            $konversi = $urai(BidangImpor::KonversiSatuanAlternatif($nomor), fn (string $t): ?string => PenguraiNilaiImpor::UraiKuantitas($t)?->KeString());
            $harga = $urai(BidangImpor::HargaSatuanAlternatif($nomor), fn (string $t): ?string => PenguraiNilaiImpor::UraiUang($t)?->KeString());
            $barcode = $urai(BidangImpor::BarcodeSatuanAlternatif($nomor), fn (string $t): array => PenguraiNilaiImpor::UraiBarcode($t)) ?? [];

            if ($satuan === '') {
                if ($konversi !== null || $harga !== null || $barcode !== []) {
                    $galat[] = ['Bidang' => $bidangSatuan->AmbilJudul(), 'Pesan' => "Isi nama satuan alternatif {$nomor}."];
                }

                continue;
            }

            if (isset($dipakai[mb_strtolower($satuan)])) {
                $galat[] = ['Bidang' => $bidangSatuan->AmbilJudul(), 'Pesan' => "Satuan {$satuan} sudah dipakai di baris ini."];

                continue;
            }

            $dipakai[mb_strtolower($satuan)] = true;
            $this->PeriksaSatuan($satuan, $bidangSatuan, $opsi, $galat);

            if ($konversi === null || $konversi === '0.0000') {
                $galat[] = ['Bidang' => BidangImpor::KonversiSatuanAlternatif($nomor)->AmbilJudul(), 'Pesan' => "Isi satuan {$satuan} wajib lebih dari 0 (dalam satuan dasar)."];

                continue;
            }

            $hasil[] = ['Nomor' => $nomor, 'Satuan' => $satuan, 'Konversi' => $konversi, 'Harga' => $harga, 'Barcode' => $barcode];
        }

        return $hasil;
    }

    /**
     * @param  array<string, int|null>  $pemetaan
     * @param  callable(BidangImpor, callable): mixed  $urai
     * @param  list<array{Bidang: string, Pesan: string}>  $galat
     * @return list<array{JumlahMinimum: string, Harga: string}>|null
     */
    private function ValidasiGrosir(array $pemetaan, callable $urai, array &$galat): ?array
    {
        $dipetakan = false;
        $hasil = [];
        $sebelumnya = '1';

        for ($nomor = 1; $nomor <= BidangImpor::JUMLAH_GROSIR; $nomor++) {
            $dipetakan = $dipetakan || ($pemetaan[BidangImpor::JumlahGrosir($nomor)->value] ?? null) !== null || ($pemetaan[BidangImpor::HargaGrosir($nomor)->value] ?? null) !== null;
            $jumlah = $urai(BidangImpor::JumlahGrosir($nomor), fn (string $t): ?string => PenguraiNilaiImpor::UraiKuantitas($t)?->KeString());
            $harga = $urai(BidangImpor::HargaGrosir($nomor), fn (string $t): ?string => PenguraiNilaiImpor::UraiUang($t)?->KeString());

            if ($jumlah === null && $harga === null) {
                continue;
            }

            if ($jumlah === null || $harga === null) {
                $galat[] = ['Bidang' => BidangImpor::HargaGrosir($nomor)->AmbilJudul(), 'Pesan' => "Isi jumlah minimal dan harga grosir {$nomor} bersamaan."];

                continue;
            }

            if (Kuantitas::Dari($jumlah)->Bandingkan(Kuantitas::Dari($sebelumnya)) <= 0) {
                $galat[] = ['Bidang' => BidangImpor::JumlahGrosir($nomor)->AmbilJudul(), 'Pesan' => 'Jumlah minimal grosir harus lebih dari 1 dan naik berurutan.'];

                continue;
            }

            $sebelumnya = $jumlah;
            $hasil[] = ['JumlahMinimum' => $jumlah, 'Harga' => $harga];
        }

        return $dipetakan ? $hasil : null;
    }

    /**
     * @param  list<array{Bidang: string, Pesan: string}>  $galat
     */
    private function PeriksaSatuan(string $satuan, BidangImpor $bidang, DataOpsiImpor $opsi, array &$galat): void
    {
        if ($satuan === '') {
            return;
        }

        if (mb_strlen($satuan) > 20 && ! $this->CekSatuanAda($satuan)) {
            $galat[] = ['Bidang' => $bidang->AmbilJudul(), 'Pesan' => 'Nama satuan baru maksimal 20 karakter.'];
        } elseif (! $opsi->buatSatuanBaru && ! $this->CekSatuanAda($satuan)) {
            $galat[] = ['Bidang' => $bidang->AmbilJudul(), 'Pesan' => "Satuan {$satuan} belum ada. Buat dulu di menu Satuan, atau centang \"Buat satuan baru\"."];
        }
    }

    /** Sama dengan urutan `PastikanSatuan`: satuan tenant (Nama/Simbol/KodeStandar) lalu satuan standar platform. */
    private function CekSatuanAda(string $teks): bool
    {
        $kecil = mb_strtolower(trim($teks));

        return $this->satuanAda[$kecil] ??= Satuan::query()
            ->where(fn ($kueri) => $kueri->whereRaw('LOWER(Nama) = ?', [$kecil])->orWhereRaw('LOWER(Simbol) = ?', [$kecil])->orWhereRaw('LOWER(KodeStandar) = ?', [$kecil]))
            ->exists() || $this->satuanStandar->AmbilBerdasarkanKode([mb_strtoupper(trim($teks))]) !== [];
    }

    /**
     * @param  list<string>  $jalur
     */
    private function CekKategoriAda(array $jalur): bool
    {
        $kunci = mb_strtolower(implode('>', $jalur));

        if (isset($this->kategoriAda[$kunci])) {
            return $this->kategoriAda[$kunci];
        }

        $idInduk = null;

        foreach ($jalur as $nama) {
            $id = Kategori::query()
                ->when($idInduk === null, fn ($kueri) => $kueri->whereNull('IdInduk'), fn ($kueri) => $kueri->where('IdInduk', $idInduk))
                ->whereRaw('LOWER(Nama) = ?', [mb_strtolower($nama)])
                ->orderBy('Id')
                ->value('Id');

            if (! is_int($id)) {
                return $this->kategoriAda[$kunci] = false;
            }

            $idInduk = $id;
        }

        return $this->kategoriAda[$kunci] = true;
    }

    /** Nama kelompok pajak tenant → kata kategori preset (PPN, PB1, …) → null. */
    private function CariKelompokPajak(string $teks, DataPresetImpor $preset): ?int
    {
        $kecil = mb_strtolower(trim($teks));

        if (array_key_exists($kecil, $this->kelompokPajak)) {
            return $this->kelompokPajak[$kecil];
        }

        $id = $this->daftarKelompokPajak->CariIdBerdasarkanNama($teks);

        if ($id === null) {
            foreach ($preset->nilaiKelompokPajak as $kategori => $kata) {
                $kategoriPajak = KategoriPajakProduk::tryFrom($kategori);

                if ($kategoriPajak !== null && in_array($kecil, $kata, true)) {
                    $id = $this->daftarKelompokPajak->CariIdBerdasarkanKategori($kategoriPajak);
                    break;
                }
            }
        }

        return $this->kelompokPajak[$kecil] = $id;
    }

    /**
     * @param  list<array{Bidang: string, Pesan: string}>  $galat
     */
    private static function TeksMaksimal(string $teks, int $maksimal, BidangImpor $bidang, array &$galat): ?string
    {
        if ($teks === '') {
            return null;
        }

        if (mb_strlen($teks) > $maksimal) {
            $galat[] = ['Bidang' => $bidang->AmbilJudul(), 'Pesan' => "{$bidang->AmbilJudul()} maksimal {$maksimal} karakter."];

            return null;
        }

        return $teks;
    }
}
