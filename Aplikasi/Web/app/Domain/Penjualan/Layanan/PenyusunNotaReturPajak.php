<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Kueri\KodeCoretaxProduk;
use App\Domain\Pajak\Enum\KategoriJenisPajak;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Pelanggan\Kueri\IdentitasPajakPelanggan;
use App\Domain\Penjualan\Data\DataNotaReturPajak;
use App\Domain\Penjualan\Data\HasilNotaReturPajak;
use App\Domain\Penjualan\Model\FakturPenjualan;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\ReturGrosirDetail;
use App\Domain\Penjualan\Model\SuratJalan;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Rekap nota retur pajak dari retur grosir (PRD v4.08, F-12 §9.7, BR-12.7; UU PPN Pasal 5A, PMK 81/2024).
 *
 * Barang yang dikembalikan atas penyerahan yang sudah dibuatkan Faktur Pajak mengurangi PPN Keluaran lewat **nota
 * retur** yang merujuk Faktur Pajak asal (NSFP). Bila pembelinya PKP, pembeli yang membuat retur di Coretax (Retur
 * Pajak Masukan) dan penjual mengonfirmasinya; bila bukan PKP, penjual mencatatnya sendiri di Coretax (Retur Pajak
 * Keluaran). Payoung tidak tahu status PKP pembeli, jadi rekap ini menyiapkan angka yang sama untuk kedua jalur:
 * nomor Faktur Pajak asal, barang, jumlah, DPP, DPP nilai lain 11/12, dan PPN per baris — dihitung dengan cara yang
 * sama dengan baris Faktur Pajak (`PenghitungBarisFakturPajak`) dari snapshot harga & tarif retur.
 *
 * **Bukan berkas impor Coretax.** Format impor retur resmi belum dipastikan, jadi ekspornya CSV untuk diisikan /
 * dicocokkan di Coretax; XML impor menunggu validasi (Panduan/KeputusanMenunggu.md K35).
 *
 * Retur yang tidak bisa direkap dijelaskan (`masalahRetur`): surat jalan belum difakturkan, faktur belum punya nomor
 * Faktur Pajak, pengali DPP selain 11/12, pembeli tanpa NPWP/NIK. Retur tanpa PPN tidak relevan dan tidak diperiksa.
 */
final class PenyusunNotaReturPajak
{
    public function __construct(
        private readonly PenyusunFakturPajakCoretax $penyusunFaktur,
        private readonly PenghitungBarisFakturPajak $penghitung,
        private readonly DaftarKelompokPajak $kelompokPajak,
        private readonly IdentitasPajakPelanggan $identitasPelanggan,
        private readonly KodeCoretaxProduk $kodeProduk,
    ) {}

    /**
     * @param  list<int>|null  $idOutlet  null = semua outlet
     */
    public function Susun(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $idOutlet): HasilNotaReturPajak
    {
        [, $masalahUmum] = $this->penyusunFaktur->PeriksaPenjual();

        $query = ReturGrosir::query()
            ->with('Detail')
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->whereNotNull('TarifPpn')
            ->whereBetween('Tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->orderBy('Tanggal')->orderBy('Id');

        if ($idOutlet !== null) {
            $query->whereIn('IdOutlet', $idOutlet);
        }

        $daftar = $query->get();
        $suratJalan = SuratJalan::query()->whereIn('Id', $daftar->pluck('IdSuratJalan')->unique()->values()->all())->get()->keyBy('Id');
        $faktur = FakturPenjualan::query()
            ->whereIn('Id', $suratJalan->pluck('IdFakturPenjualan')->filter()->unique()->values()->all())
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->get()->keyBy('Id');
        $pembeli = $this->identitasPelanggan->AmbilBanyak(array_values(array_unique($daftar->pluck('IdPelanggan')->all())));
        $semuaDetail = $daftar->flatMap(fn (ReturGrosir $r) => $r->Detail->all());
        $kode = $this->kodeProduk->AmbilBanyak(array_values(array_unique($semuaDetail->map(fn (ReturGrosirDetail $d): int => $d->IdProduk)->all())));
        $pajakKelompok = $this->kelompokPajak->AmbilJenisPajakPerKelompok(array_values(array_unique(array_filter(
            $semuaDetail->map(fn (ReturGrosirDetail $d): ?int => $d->IdKelompokPajak)->all(),
            'is_int',
        ))));

        $hasil = [];
        $masalahRetur = [];
        $peringatan = [];

        foreach ($daftar as $retur) {
            $masalah = [];
            $sj = $suratJalan->get($retur->IdSuratJalan);
            $fakturAsal = $sj?->IdFakturPenjualan === null ? null : $faktur->get($sj->IdFakturPenjualan);

            if ($fakturAsal === null) {
                $masalah[] = 'Surat jalan '.($sj->Nomor ?? '').' belum difakturkan. Nota retur pajak dibuat setelah Faktur Pajak penyerahannya terbit.';
            } elseif ($fakturAsal->NomorFakturPajak === null || trim($fakturAsal->NomorFakturPajak) === '') {
                $masalah[] = "Faktur {$fakturAsal->Nomor} belum punya nomor Faktur Pajak. Isi nomornya dari Coretax di halaman faktur.";
            }

            if ($retur->PengaliDppPembilang !== 11 || $retur->PengaliDppPenyebut !== 12) {
                $masalah[] = "Retur ini memakai pengali DPP {$retur->PengaliDppPembilang}/{$retur->PengaliDppPenyebut}. Rekap baru mendukung DPP nilai lain 11/12.";
            }

            $identitas = $this->penghitung->SusunIdentitasPembeli($pembeli[$retur->IdPelanggan] ?? null, $masalah);

            if ($masalah === [] && $fakturAsal !== null) {
                $nota = $this->SusunNota($retur, $fakturAsal, $identitas, $kode, $pajakKelompok, $masalah, $peringatan);

                if ($nota !== null && $masalah === []) {
                    $hasil[] = $nota;

                    continue;
                }
            }

            $masalahRetur[$retur->Nomor] = array_values(array_unique($masalah));
        }

        return new HasilNotaReturPajak(
            retur: $hasil,
            masalahUmum: $masalahUmum,
            masalahRetur: $masalahRetur,
            peringatan: array_values(array_unique($peringatan)),
            jumlahDiperiksa: $daftar->count(),
        );
    }

    /**
     * @param  array{Nama: string, Alamat: string, Email: string|null, JenisDokumen: string, Tin: string, NomorDokumen: string, IdTku: string}  $identitas
     * @param  array<int, array{Kode: string|null, Unit: string|null, Jasa: bool}>  $kode
     * @param  array<int, list<array{Kode: string, Kategori: KategoriJenisPajak, Nama: string, DasarPengenaan: mixed, KenaBiayaKirim: bool}>>  $pajakKelompok
     * @param  list<string>  $masalah
     * @param  list<string>  $peringatan
     */
    private function SusunNota(
        ReturGrosir $retur,
        FakturPenjualan $faktur,
        array $identitas,
        array $kode,
        array $pajakKelompok,
        array &$masalah,
        array &$peringatan,
    ): ?DataNotaReturPajak {
        $tarif = BigDecimal::of((string) $retur->TarifPpn);
        $detail = array_values($retur->Detail->sortBy('Urutan')->all());
        $hitung = $this->penghitung->HitungBaris($retur->IdOutlet, (int) $retur->PengaliDppPembilang, (int) $retur->PengaliDppPenyebut, $detail, $pajakKelompok, $tarif);
        $baris = [];
        $totalDpp = Uang::Nol();
        $totalPpn = Uang::Nol();

        foreach ($detail as $indeks => $d) {
            $b = $this->penghitung->SusunBaris($d, $hitung[$indeks] ?? null, $tarif, $kode[$d->IdProduk] ?? null, $masalah, $peringatan);
            $baris[] = $b;
            $totalDpp = $totalDpp->Tambah(Uang::Dari($b->dpp));
            $totalPpn = $totalPpn->Tambah(Uang::Dari($b->ppn));
        }

        if ($masalah !== []) {
            return null;
        }

        return new DataNotaReturPajak(
            nomorRetur: $retur->Nomor,
            tanggalRetur: $retur->Tanggal->format('Y-m-d'),
            nomorFaktur: $faktur->Nomor,
            tanggalFaktur: $faktur->Tanggal->format('Y-m-d'),
            nomorFakturPajak: (string) $faktur->NomorFakturPajak,
            namaPembeli: $identitas['Nama'],
            jenisDokumenPembeli: $identitas['JenisDokumen'],
            tinPembeli: $identitas['Tin'],
            nomorDokumenPembeli: $identitas['NomorDokumen'],
            alasan: $retur->Alasan,
            baris: $baris,
            totalDpp: $totalDpp->KeString(),
            totalPpn: $totalPpn->KeString(),
            selisihDpp: $totalDpp->Kurangi(Uang::Dari($retur->DasarPengenaanPajak))->KeString(),
            selisihPpn: $totalPpn->Kurangi(Uang::Dari($retur->Pajak))->KeString(),
        );
    }
}
