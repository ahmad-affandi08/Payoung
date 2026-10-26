<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Kueri\KodeCoretaxProduk;
use App\Domain\Pajak\Enum\KategoriJenisPajak;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Penjualan\Data\DataFakturPajak;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\PenjualanPajak;
use Brick\Math\BigDecimal;

/**
 * Menyusun satu Faktur Pajak Keluaran Coretax dari satu penjualan kasir (TAX-07, PRD v6.27, D-147). Dipakai dua kali:
 * saat faktur diterbitkan (supaya penjualan yang tidak bisa diekspor ditolak sejak awal, lengkap dengan alasannya) dan
 * saat ekspor (keadaan penjualan bisa berubah sesudahnya, mis. di-void).
 *
 * **Angka tidak dihitung ulang** seperti pada faktur grosir: penjualan kasir sudah menyimpan snapshot per baris
 * (`Bruto`, diskon baris dan pesanan, pajak inklusif/eksklusif), jadi DPP baris = Bruto − diskon − PPN yang ada di
 * dalam harga. Itu angka yang persis sama dengan yang dibukukan. Selisih pembulatan terhadap `PenjualanPajak` (DPP nilai
 * lain dan PPN dokumen) dilaporkan, tidak disembunyikan dan tidak mengubah pembukuan.
 *
 * Yang **tidak** bisa dijadikan Faktur Pajak PPN dan ditolak dengan alasan tertulis: penjualan yang tidak Lunas
 * (void/diretur), tanpa PPN, bercampur pajak lain (PB1/PBJT), berpengali DPP selain 11/12, berbaris non-PPN, atau
 * memuat biaya layanan/ongkir pada barisnya (dasar pajaknya bukan lagi harga barang), dan pembeli tanpa NPWP/NIK sah.
 */
final class PenyusunFakturPajakPenjualan
{
    public function __construct(
        private readonly DaftarKelompokPajak $kelompokPajak,
        private readonly KodeCoretaxProduk $kodeProduk,
        private readonly PenghitungBarisFakturPajak $penghitung,
    ) {}

    /**
     * @param  array{Nama: string, Alamat: string|null, Email: string|null, Npwp: string|null, Nik: string|null, NamaNpwp: string|null, AlamatNpwp: string|null}|null  $pembeli
     * @return array{Faktur: DataFakturPajak|null, Masalah: list<string>, Peringatan: list<string>}
     */
    public function Susun(Penjualan $penjualan, ?array $pembeli): array
    {
        $masalah = [];
        $peringatan = [];

        if ($penjualan->Status !== StatusPenjualan::Lunas) {
            $masalah[] = 'Penjualan ini sudah dibatalkan atau diretur. Faktur Pajak hanya untuk penjualan yang masih berlaku. Batalkan Faktur Pajaknya.';
        }

        $identitas = $this->penghitung->SusunIdentitasPembeli($pembeli, $masalah);
        $detail = array_values(PenjualanDetail::query()->where('IdPenjualan', $penjualan->Id)->orderBy('Urutan')->get()->all());
        $pajakDokumen = PenjualanPajak::query()->where('IdPenjualan', $penjualan->Id)->orderBy('Id')->get();
        $kategori = $this->kelompokPajak->AmbilKategoriJenisPajak(array_values(array_unique(array_merge(
            $pajakDokumen->pluck('KodeJenisPajak')->all(),
            ...array_map(fn (PenjualanDetail $d): array => array_column($d->SnapshotPajak ?? [], 'Kode'), $detail),
        ))));
        $kodePpn = array_keys(array_filter($kategori, fn (KategoriJenisPajak $k): bool => $k === KategoriJenisPajak::Ppn));
        $ppn = $pajakDokumen->first(fn (PenjualanPajak $p): bool => in_array($p->KodeJenisPajak, $kodePpn, true));

        if ($ppn === null) {
            $masalah[] = 'Penjualan ini tidak memuat PPN, jadi tidak bisa dijadikan Faktur Pajak.';
        } elseif ($ppn->PengaliDppPembilang !== 11 || $ppn->PengaliDppPenyebut !== 12) {
            $masalah[] = "Penjualan ini memakai pengali DPP {$ppn->PengaliDppPembilang}/{$ppn->PengaliDppPenyebut}; baru mendukung DPP nilai lain 11/12 (kode transaksi 04). Terbitkan Faktur Pajaknya langsung di Coretax.";
        }

        if ($pajakDokumen->contains(fn (PenjualanPajak $p): bool => ! in_array($p->KodeJenisPajak, $kodePpn, true))) {
            $masalah[] = 'Penjualan ini juga memuat pajak selain PPN (mis. PB1/PBJT). Faktur Pajak PPN tidak dapat memuatnya. Terbitkan langsung di Coretax.';
        }

        if ($detail === []) {
            $masalah[] = 'Penjualan ini tidak memiliki baris.';
        }

        if ($masalah !== [] || $ppn === null) {
            return ['Faktur' => null, 'Masalah' => array_values(array_unique($masalah)), 'Peringatan' => $peringatan];
        }

        $kode = $this->kodeProduk->AmbilBanyak(array_values(array_unique(array_map(fn (PenjualanDetail $d): int => $d->IdProduk, $detail))));
        $tarif = BigDecimal::of($ppn->Tarif);
        $baris = [];
        $totalDpp = Uang::Nol();
        $totalDppNilaiLain = Uang::Nol();
        $totalPpn = Uang::Nol();

        foreach ($detail as $d) {
            $kodeBaris = array_column($d->SnapshotPajak ?? [], 'Kode');

            if (array_diff($kodeBaris, $kodePpn) !== []) {
                $masalah[] = "Baris \"{$d->NamaProduk}\" memuat pajak selain PPN. Faktur Pajak PPN tidak dapat memuatnya.";
            }

            if (! Uang::Dari($d->BiayaLayanan)->BernilaiNol() || ! Uang::Dari($d->BiayaKirim)->BernilaiNol()) {
                $masalah[] = "Baris \"{$d->NamaProduk}\" memuat biaya layanan atau ongkir. Dasar pajaknya tidak lagi sama dengan harga barang, jadi Faktur Pajaknya diterbitkan langsung di Coretax.";
            }

            $kenaPpn = array_intersect($kodeBaris, $kodePpn) !== [];
            $inklusif = Uang::Dari($d->JumlahPajak)->Kurangi(Uang::Dari($d->PajakEksklusif));
            $diskon = Uang::Dari($d->JumlahDiskon)->Tambah(Uang::Dari($d->JumlahDiskonPesanan));
            $hitung = ! $kenaPpn ? null : [
                'Bruto' => Uang::Dari($d->Bruto),
                'Diskon' => $diskon,
                'Dpp' => Uang::Dari($d->Bruto)->Kurangi($diskon)->Kurangi($inklusif),
            ];
            $isi = $this->penghitung->SusunBaris($d, $hitung, $tarif, $kode[$d->IdProduk] ?? null, $masalah, $peringatan);
            $baris[] = $isi;
            $totalDpp = $totalDpp->Tambah(Uang::Dari($isi->dpp));
            $totalDppNilaiLain = $totalDppNilaiLain->Tambah(Uang::Dari($isi->dppNilaiLain));
            $totalPpn = $totalPpn->Tambah(Uang::Dari($isi->ppn));
        }

        if ($masalah !== []) {
            return ['Faktur' => null, 'Masalah' => array_values(array_unique($masalah)), 'Peringatan' => array_values(array_unique($peringatan))];
        }

        return [
            'Faktur' => new DataFakturPajak(
                nomorFaktur: $penjualan->Nomor,
                tanggal: $penjualan->TanggalBisnis->format('Y-m-d'),
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
                selisihDpp: $totalDppNilaiLain->Kurangi(Uang::Dari($ppn->Dpp))->KeString(),
                selisihPpn: $totalPpn->Kurangi(Uang::Dari($ppn->Jumlah))->KeString(),
            ),
            'Masalah' => [],
            'Peringatan' => array_values(array_unique($peringatan)),
        ];
    }
}
