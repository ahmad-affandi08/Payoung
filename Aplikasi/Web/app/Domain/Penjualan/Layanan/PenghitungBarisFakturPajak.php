<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\ProfilPajakOutlet;
use App\Domain\Pajak\Enum\KategoriJenisPajak;
use App\Domain\Penjualan\Data\DataBarisFakturPajak;
use App\Domain\Penjualan\Kalkulasi\DataBarisKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataPajakKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataPotongan;
use App\Domain\Penjualan\Kalkulasi\MesinKalkulasi;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\ReturGrosirDetail;
use App\Domain\Penjualan\Model\SuratJalanDetail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Perhitungan baris Faktur Pajak Coretax yang dipakai bersama ekspor Faktur Pajak Keluaran (PRD v3.12) dan rekap nota
 * retur pajak (PRD v4.08): identitas pembeli (NPWP/NIK → TIN, dokumen, IDTKU) dan baris barang/jasa yang dihitung
 * ulang dengan mesin kalkulasi dokumen dari snapshot surat jalan atau retur grosir, sehingga angka faktur dan angka
 * returnya tidak bisa dihitung dengan cara berbeda.
 */
final class PenghitungBarisFakturPajak
{
    public const KODE_BARANG_BAWAAN = '000000';

    public const KODE_SATUAN_BAWAAN = 'UM.0021';

    public const SUFIKS_TKU = '000000';

    private const TIN_NIK = '0000000000000000';

    public function __construct(
        private readonly ProfilPajakOutlet $profilOutlet,
        private readonly MesinKalkulasi $mesin = new MesinKalkulasi,
    ) {}

    /**
     * @param  array{Nama: string, Alamat: string|null, Email: string|null, Npwp: string|null, Nik: string|null, NamaNpwp: string|null, AlamatNpwp: string|null}|null  $pembeli
     * @param  list<string>  $masalah
     * @return array{Nama: string, Alamat: string, Email: string|null, JenisDokumen: string, Tin: string, NomorDokumen: string, IdTku: string}
     */
    public function SusunIdentitasPembeli(?array $pembeli, array &$masalah): array
    {
        $kosong = ['Nama' => '', 'Alamat' => '', 'Email' => null, 'JenisDokumen' => 'TIN', 'Tin' => '', 'NomorDokumen' => '', 'IdTku' => ''];

        if ($pembeli === null) {
            $masalah[] = 'Pelanggan faktur ini tidak ditemukan.';

            return $kosong;
        }

        $nama = trim((string) ($pembeli['NamaNpwp'] ?? '')) !== '' ? (string) $pembeli['NamaNpwp'] : $pembeli['Nama'];
        $alamat = trim((string) ($pembeli['AlamatNpwp'] ?? '')) !== '' ? (string) $pembeli['AlamatNpwp'] : (string) ($pembeli['Alamat'] ?? '');
        $tin = PenyusunFakturPajakCoretax::NormalisasiNpwp($pembeli['Npwp']);
        $nik = preg_replace('/\D/', '', (string) $pembeli['Nik']) ?? '';

        if ($tin !== null) {
            return ['Nama' => $nama, 'Alamat' => $alamat, 'Email' => $pembeli['Email'], 'JenisDokumen' => 'TIN', 'Tin' => $tin, 'NomorDokumen' => '', 'IdTku' => $tin.self::SUFIKS_TKU];
        }

        if (strlen($nik) === 16) {
            return ['Nama' => $nama, 'Alamat' => $alamat, 'Email' => $pembeli['Email'], 'JenisDokumen' => 'National ID', 'Tin' => self::TIN_NIK, 'NomorDokumen' => $nik, 'IdTku' => self::SUFIKS_TKU];
        }

        $masalah[] = "Pelanggan {$pembeli['Nama']} belum punya NPWP atau NIK yang sah. Lengkapi di data pelanggan (Identitas pajak).";

        return $kosong;
    }

    /**
     * Hasil mesin kalkulasi per baris surat jalan/retur (indeks sama dengan `$detail`); null untuk baris yang bukan objek PPN.
     *
     * @param  list<SuratJalanDetail|ReturGrosirDetail>  $detail
     * @param  array<int, list<array{Kode: string, Kategori: KategoriJenisPajak, Nama: string, DasarPengenaan: mixed, KenaBiayaKirim: bool}>>  $pajakKelompok
     * @return array<int, array{Bruto: Uang, Diskon: Uang, Dpp: Uang}|null>
     */
    public function HitungBaris(int $idOutlet, int $pengaliDppPembilang, int $pengaliDppPenyebut, array $detail, array $pajakKelompok, BigDecimal $tarif): array
    {
        $hargaTermasukPajak = ($this->profilOutlet->Ambil($idOutlet)?->hargaTermasukPajak) === true;
        $adaPpn = [];
        $baris = [];

        foreach ($detail as $indeks => $d) {
            $kenaPpn = $d->IdKelompokPajak !== null && self::MemuatPpn($pajakKelompok[$d->IdKelompokPajak] ?? []);
            $adaPpn[$indeks] = $kenaPpn;
            $baris[] = new DataBarisKalkulasi(
                Kuantitas::Dari($d->Jumlah),
                Uang::Dari($d->Harga),
                null,
                $d->HargaTermasukPajak,
                $kenaPpn ? ['PPN'] : [],
                $d->AmbilDiskon()->BernilaiNol() ? [] : [DataPotongan::BuatNominal($d->AmbilDiskon())],
            );
        }

        $hasil = $this->mesin->Hitung(new DataKalkulasi(
            hargaTermasukPajak: $hargaTermasukPajak,
            baris: $baris,
            pajak: [new DataPajakKalkulasi('PPN', $tarif, pengaliDppPembilang: $pengaliDppPembilang, pengaliDppPenyebut: $pengaliDppPenyebut)],
        ));
        $keluar = [];

        foreach ($hasil->baris as $indeks => $b) {
            $keluar[$indeks] = ! $adaPpn[$indeks] ? null : [
                'Bruto' => $b->bruto,
                'Diskon' => $b->diskon,
                'Dpp' => $b->bruto->Kurangi($b->diskon)->Kurangi($b->pajak->Kurangi($b->pajakEksklusif)),
            ];
        }

        return $keluar;
    }

    /**
     * @param  list<array{Kode: string, Kategori: KategoriJenisPajak, Nama: string, DasarPengenaan: mixed, KenaBiayaKirim: bool}>  $jenis
     */
    public static function MemuatPpn(array $jenis): bool
    {
        foreach ($jenis as $j) {
            if ($j['Kategori'] === KategoriJenisPajak::Ppn) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{Bruto: Uang, Diskon: Uang, Dpp: Uang}|null  $hitung
     * @param  array{Kode: string|null, Unit: string|null, Jasa: bool}|null  $kode
     * @param  list<string>  $masalah
     * @param  list<string>  $peringatan
     */
    public function SusunBaris(SuratJalanDetail|ReturGrosirDetail|PenjualanDetail $d, ?array $hitung, BigDecimal $tarif, ?array $kode, array &$masalah, array &$peringatan): DataBarisFakturPajak
    {
        if ($hitung === null) {
            $masalah[] = "Baris \"{$d->NamaProduk}\" bukan objek PPN (kelompok pajaknya tidak memuat PPN). Coretax tidak menerima baris tanpa PPN dalam satu faktur; pisahkan penyerahannya.";

            return new DataBarisFakturPajak('A', self::KODE_BARANG_BAWAAN, $d->NamaProduk, self::KODE_SATUAN_BAWAAN, '0', '0', '0', '0', '0', '0', '0');
        }

        $jumlah = BigDecimal::of($d->Jumlah);
        $dpp = $hitung['Dpp']->KeString();
        $dppBersih = BigDecimal::of($dpp);
        $bruto = BigDecimal::of($hitung['Bruto']->KeString());
        $diskon = BigDecimal::of($hitung['Diskon']->KeString());
        $netto = $bruto->minus($diskon);
        // Harga inklusif: bruto & diskon memuat PPN, sedangkan Coretax memakai harga dan diskon tanpa PPN. Diskon
        // diskalakan sama dengan dasar pajaknya (DPP ÷ netto) supaya rasio diskon terhadap harga tetap.
        $diskonTanpaPajak = $netto->isZero() ? $diskon : $diskon->multipliedBy($dppBersih)->dividedBy($netto, 12, RoundingMode::HalfUp);
        $hargaKotor = $dppBersih->plus($diskonTanpaPajak);
        $harga = $jumlah->isZero() ? BigDecimal::zero() : $hargaKotor->dividedBy($jumlah, 2, RoundingMode::Up);
        $totalDiskon = $harga->multipliedBy($jumlah)->minus($dppBersih)->toScale(2, RoundingMode::HalfUp);

        if ($totalDiskon->isNegative()) {
            $totalDiskon = BigDecimal::zero()->toScale(2);
        }

        $dppNilaiLain = $dppBersih->multipliedBy(11)->dividedBy(12, 2, RoundingMode::HalfUp);
        $ppn = $dppNilaiLain->multipliedBy($tarif)->dividedBy(100, 2, RoundingMode::HalfUp);
        $kodeBarang = $kode['Kode'] ?? null;
        $satuan = $kode['Unit'] ?? null;

        if ($kodeBarang === null) {
            $peringatan[] = 'Ada produk tanpa Kode Barang/Jasa Coretax: dipakai kode umum 000000. Isi di formulir produk (tab Pajak) supaya klasifikasinya benar.';
        }

        if ($satuan === null) {
            $peringatan[] = 'Ada produk tanpa Kode Satuan Coretax: dipakai UM.0021 (Unit). Isi di formulir produk (tab Pajak) bila satuannya lain.';
        }

        return new DataBarisFakturPajak(
            opsi: ($kode['Jasa'] ?? false) ? 'B' : 'A',
            kode: $kodeBarang ?? self::KODE_BARANG_BAWAAN,
            nama: $d->NamaProduk,
            satuan: $satuan ?? self::KODE_SATUAN_BAWAAN,
            harga: $harga->toScale(2)->__toString(),
            jumlah: PenyusunFakturPajakCoretax::RingkasAngka($jumlah),
            totalDiskon: $totalDiskon->__toString(),
            dpp: $dpp,
            dppNilaiLain: $dppNilaiLain->__toString(),
            tarifPpn: PenyusunFakturPajakCoretax::RingkasAngka($tarif),
            ppn: $ppn->__toString(),
        );
    }
}
