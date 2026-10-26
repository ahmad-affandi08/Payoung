<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Kueri\KodeCoretaxProduk;
use App\Domain\Pajak\Enum\KategoriJenisPajak;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Kueri\IdentitasPajakPelanggan;
use App\Domain\Penjualan\Data\DataFakturPajak;
use App\Domain\Penjualan\Data\HasilFakturPajakCoretax;
use App\Domain\Penjualan\Model\FakturPajakPenjualan;
use App\Domain\Penjualan\Model\FakturPenjualan;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Penjualan\Model\SuratJalanDetail;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Menyusun data Faktur Pajak Keluaran untuk impor massal Coretax dari faktur penjualan grosir (PRD v3.12, F-12).
 *
 * **Sumbernya faktur grosir dan, sejak v6.27 (TAX-07), Faktur Pajak per transaksi kasir** (`FakturPajakPenjualan`, atas
 * permintaan pembeli ber-NPWP/NIK). Penjualan kasir lain tidak diekspor: struknya sudah menjadi dokumen tertentu yang
 * kedudukannya sama dengan Faktur Pajak (PMK) bagi pembeli tanpa identitas pajak.
 *
 * **Angka dihitung ulang dengan mesin kalkulasi yang sama dengan dokumen** (`MesinKalkulasi` + snapshot tarif & pengali
 * DPP dari surat jalan) sehingga DPP baris identik dengan DPP dokumen. Coretax menghitung `OtherTaxBase` dan `VAT` per
 * baris dengan pembulatan sendiri, jadi ΣPPN Coretax bisa berbeda beberapa sen dari PPN faktur internal; selisihnya
 * **dilaporkan** (`selisihDpp`/`selisihPpn`), tidak disembunyikan dan tidak diubah di pembukuan.
 *
 * Faktur yang tidak bisa diekspor dengan benar (pembeli tanpa NPWP/NIK, baris non-PPN, pengali DPP selain 11/12,
 * tanpa PPN) **dilewati dan dijelaskan** — lebih baik pengguna melihat daftar pekerjaan rumah daripada Coretax menolak
 * seluruh berkas karena satu baris.
 */
final class PenyusunFakturPajakCoretax
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profilTenant,
        private readonly DaftarKelompokPajak $kelompokPajak,
        private readonly IdentitasPajakPelanggan $identitasPelanggan,
        private readonly KodeCoretaxProduk $kodeProduk,
        private readonly PenghitungBarisFakturPajak $penghitung,
        private readonly PenyusunFakturPajakPenjualan $penyusunPenjualan,
    ) {}

    /**
     * @param  list<int>|null  $idOutlet  null = semua outlet
     */
    public function Susun(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutlet): HasilFakturPajakCoretax
    {
        [$tin, $masalahUmum] = $this->PeriksaPenjual();

        $query = FakturPenjualan::query()
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->whereBetween('Tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->orderBy('Tanggal')->orderBy('Id');

        if ($idOutlet !== null) {
            $query->whereIn('IdOutlet', $idOutlet);
        }

        $daftarFaktur = $query->get();
        $suratJalanPerFaktur = $this->AmbilSuratJalan(array_values(array_map('intval', $daftarFaktur->pluck('Id')->all())));
        $pembeli = $this->identitasPelanggan->AmbilBanyak(array_values(array_unique($daftarFaktur->pluck('IdPelanggan')->all())));
        $semuaDetail = [];

        foreach ($suratJalanPerFaktur as $daftar) {
            foreach ($daftar as $sj) {
                foreach ($sj['Detail'] as $d) {
                    $semuaDetail[] = $d;
                }
            }
        }

        $kode = $this->kodeProduk->AmbilBanyak(array_values(array_unique(array_map(fn (SuratJalanDetail $d): int => $d->IdProduk, $semuaDetail))));
        $pajakKelompok = $this->kelompokPajak->AmbilJenisPajakPerKelompok(array_values(array_unique(array_filter(
            array_map(fn (SuratJalanDetail $d): ?int => $d->IdKelompokPajak, $semuaDetail),
            'is_int',
        ))));

        $hasilFaktur = [];
        $masalahFaktur = [];
        $peringatan = [];

        foreach ($daftarFaktur as $faktur) {
            $masalah = [];
            $susunan = $this->SusunFaktur(
                $faktur,
                $suratJalanPerFaktur[$faktur->Id] ?? [],
                $pembeli[$faktur->IdPelanggan] ?? null,
                $kode,
                $pajakKelompok,
                $masalah,
                $peringatan,
            );

            if ($masalah !== []) {
                $masalahFaktur[$faktur->Nomor] = $masalah;

                continue;
            }

            if ($susunan !== null) {
                $hasilFaktur[] = $susunan;
            }
        }

        $daftarKasir = $this->SusunDariKasir($dari, $sampai, $idOutlet, $hasilFaktur, $masalahFaktur, $peringatan);

        return new HasilFakturPajakCoretax(
            tinPenjual: $tin ?? '',
            faktur: $hasilFaktur,
            masalahUmum: $masalahUmum,
            masalahFaktur: $masalahFaktur,
            peringatan: array_values(array_unique($peringatan)),
            jumlahDiperiksa: $daftarFaktur->count() + $daftarKasir,
        );
    }

    /**
     * Faktur Pajak per transaksi kasir yang aktif pada periode & outlet saringan (TAX-07). Yang lolos ditambahkan ke
     * `$hasilFaktur`, yang tidak ke `$masalahFaktur` (kuncinya nomor penjualan).
     *
     * @param  list<int>|null  $idOutlet
     * @param  list<DataFakturPajak>  $hasilFaktur
     * @param  array<string, list<string>>  $masalahFaktur
     * @param  list<string>  $peringatan
     * @return int jumlah Faktur Pajak kasir yang diperiksa
     */
    private function SusunDariKasir(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutlet, array &$hasilFaktur, array &$masalahFaktur, array &$peringatan): int
    {
        $daftar = FakturPajakPenjualan::query()
            ->with('Penjualan')
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->whereBetween('Tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->when($idOutlet !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutlet ?? []))
            ->orderBy('Tanggal')->orderBy('Id')->get();
        $pembeli = $this->identitasPelanggan->AmbilBanyak(array_values(array_unique($daftar->pluck('IdPelanggan')->all())));

        foreach ($daftar as $fp) {
            $hasil = $this->penyusunPenjualan->Susun($fp->Penjualan, $pembeli[$fp->IdPelanggan] ?? null);
            $peringatan = [...$peringatan, ...$hasil['Peringatan']];

            if ($hasil['Faktur'] === null) {
                $masalahFaktur[$fp->Penjualan->Nomor] = $hasil['Masalah'];

                continue;
            }

            $hasilFaktur[] = $hasil['Faktur'];
        }

        return $daftar->count();
    }

    /**
     * Syarat penjual untuk dokumen pajak Coretax (dipakai juga rekap nota retur, v4.08): PKP dan NPWP sah.
     *
     * @return array{0: string|null, 1: list<string>} [TIN penjual 16 digit, masalah umum]
     */
    public function PeriksaPenjual(): array
    {
        $profil = $this->profilTenant->Ambil($this->konteks->Wajib());
        $masalah = [];
        $tin = self::NormalisasiNpwp($profil['Npwp'] ?? null);

        if (! $profil['Pkp']) {
            $masalah[] = 'Usaha Anda belum ditandai sebagai PKP. Faktur Pajak hanya diterbitkan oleh Pengusaha Kena Pajak.';
        }

        if ($tin === null) {
            $masalah[] = 'NPWP usaha belum diisi atau tidak sah (harus 15 atau 16 digit). Isi di pengaturan usaha.';
        }

        return [$tin, $masalah];
    }

    /**
     * NPWP 15 digit diberi awalan "0" menjadi 16 digit (format NPWP baru); 16 digit dipakai apa adanya; selain itu tidak sah.
     */
    public static function NormalisasiNpwp(?string $npwp): ?string
    {
        $digit = preg_replace('/\D/', '', (string) $npwp) ?? '';

        return match (strlen($digit)) {
            15 => '0'.$digit,
            16 => $digit,
            default => null,
        };
    }

    /**
     * @param  list<int>  $idFaktur
     * @return array<int, list<array{SuratJalan: SuratJalan, Detail: list<SuratJalanDetail>}>>
     */
    private function AmbilSuratJalan(array $idFaktur): array
    {
        if ($idFaktur === []) {
            return [];
        }

        $suratJalan = SuratJalan::query()
            ->whereIn('IdFakturPenjualan', $idFaktur)
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->orderBy('Tanggal')->orderBy('Id')->get();
        $detail = SuratJalanDetail::query()->whereIn('IdSuratJalan', $suratJalan->pluck('Id')->all())->orderBy('IdSuratJalan')->orderBy('Urutan')->get()->groupBy('IdSuratJalan');
        $hasil = [];

        foreach ($suratJalan as $sj) {
            $hasil[(int) $sj->IdFakturPenjualan][] = ['SuratJalan' => $sj, 'Detail' => array_values(($detail->get($sj->Id) ?? collect())->all())];
        }

        return $hasil;
    }

    /**
     * @param  list<array{SuratJalan: SuratJalan, Detail: list<SuratJalanDetail>}>  $suratJalan
     * @param  array{Nama: string, Alamat: string|null, Email: string|null, Npwp: string|null, Nik: string|null, NamaNpwp: string|null, AlamatNpwp: string|null}|null  $pembeli
     * @param  array<int, array{Kode: string|null, Unit: string|null, Jasa: bool}>  $kode
     * @param  array<int, list<array{Kode: string, Kategori: KategoriJenisPajak, Nama: string, DasarPengenaan: mixed, KenaBiayaKirim: bool}>>  $pajakKelompok
     * @param  list<string>  $masalah
     * @param  list<string>  $peringatan
     */
    private function SusunFaktur(
        FakturPenjualan $faktur,
        array $suratJalan,
        ?array $pembeli,
        array $kode,
        array $pajakKelompok,
        array &$masalah,
        array &$peringatan,
    ): ?DataFakturPajak {
        if ($faktur->TarifPpn === null) {
            $masalah[] = 'Faktur ini tidak memuat PPN (tidak ada tarif PPN tersimpan), jadi tidak diekspor sebagai Faktur Pajak.';
        } elseif ($faktur->PengaliDppPembilang !== 11 || $faktur->PengaliDppPenyebut !== 12) {
            $masalah[] = "Faktur ini memakai pengali DPP {$faktur->PengaliDppPembilang}/{$faktur->PengaliDppPenyebut}. Ekspor baru mendukung DPP nilai lain 11/12 (kode transaksi 04). Terbitkan Faktur Pajaknya langsung di Coretax.";
        }

        $identitas = $this->penghitung->SusunIdentitasPembeli($pembeli, $masalah);

        if ($suratJalan === []) {
            $masalah[] = 'Faktur ini tidak memiliki surat jalan aktif.';
        }

        if ($masalah !== []) {
            return null;
        }

        $baris = [];
        $totalDpp = Uang::Nol();
        $totalPpn = Uang::Nol();
        $tarif = BigDecimal::of((string) $faktur->TarifPpn);

        foreach ($suratJalan as $isi) {
            $sj = $isi['SuratJalan'];
            $hasil = $this->penghitung->HitungBaris($sj->IdOutlet, (int) $sj->PengaliDppPembilang, (int) $sj->PengaliDppPenyebut, $isi['Detail'], $pajakKelompok, $tarif);

            foreach ($isi['Detail'] as $indeks => $detail) {
                $baris[] = $this->penghitung->SusunBaris($detail, $hasil[$indeks] ?? null, $tarif, $kode[$detail->IdProduk] ?? null, $masalah, $peringatan);
            }
        }

        if ($masalah !== []) {
            $masalah = array_values(array_unique($masalah));

            return null;
        }

        foreach ($baris as $b) {
            $totalDpp = $totalDpp->Tambah(Uang::Dari($b->dpp));
            $totalPpn = $totalPpn->Tambah(Uang::Dari($b->ppn));
        }

        return new DataFakturPajak(
            nomorFaktur: $faktur->Nomor,
            tanggal: $faktur->Tanggal->format('Y-m-d'),
            namaPembeli: $identitas['Nama'],
            alamatPembeli: $identitas['Alamat'],
            emailPembeli: $identitas['Email'],
            jenisDokumenPembeli: $identitas['JenisDokumen'],
            tinPembeli: $identitas['Tin'],
            nomorDokumenPembeli: $identitas['NomorDokumen'],
            idTkuPembeli: $identitas['IdTku'],
            baris: $baris,
            totalDpp: $totalDpp->KeString(),
            totalPpn: $totalPpn->KeString(),
            selisihDpp: $totalDpp->Kurangi(Uang::Dari($faktur->DasarPengenaanPajak))->KeString(),
            selisihPpn: $totalPpn->Kurangi(Uang::Dari($faktur->Pajak))->KeString(),
        );
    }

    /**
     * Angka tanpa nol di belakang koma (`200.0000` → `200`, `12.500000` → `12.5`), tidak pernah bernotasi ilmiah.
     * Memakai `strippedOfTrailingZeros()` (brick/math 1.x; `stripTrailingZeros()` hanya ada di 0.x dan di sini menjadi Error).
     */
    public static function RingkasAngka(BigDecimal $angka): string
    {
        $ringkas = $angka->strippedOfTrailingZeros();

        return (string) ($ringkas->getScale() < 0 ? $ringkas->toScale(0) : $ringkas);
    }
}
