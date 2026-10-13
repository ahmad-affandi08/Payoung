<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Model\FakturPenjualan;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Penjualan\Model\PesananGrosirDetail;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\ReturGrosirDetail;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Penjualan\Model\SuratJalanDetail;

/**
 * Muatan dokumen grosir yang **dicetak** (F-12, §9.7): surat jalan, faktur penjualan, nota kredit retur, dan daftar
 * ambil barang. Terpisah dari `DetailGrosir` karena isinya memang beda kebutuhan: halaman cetak butuh identitas
 * penagihan & alamat (pembeli dan outlet penjual) yang tidak dipakai halaman kerja, dan sebaliknya tidak butuh jurnal,
 * riwayat status, maupun dokumen terkait yang cuma berguna untuk diklik.
 *
 * **Dua dokumen sengaja tanpa harga: surat jalan dan daftar ambil barang.** Keduanya dipegang sopir dan petugas gudang,
 * dan surat jalan ikut dibaca petugas gudang pembeli saat barang diterima. Yang mereka perlukan cuma barang & jumlah;
 * harga jual grosir di tangan orang-orang itu adalah kebocoran margin, bukan kelengkapan. Uangnya ada di faktur, yang
 * dikirim ke bagian pembelian pembeli.
 *
 * Identitas pembeli, outlet, dan lokasi stok diambil lewat kueri publik domain lain (aturan #14), bukan dengan
 * membaca tabelnya dari sini.
 */
final class DokumenCetakGrosir
{
    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly InfoGudang $infoGudang,
    ) {}

    /**
     * Surat jalan: barang & jumlah saja, plus kolom tanda tangan. Status ikut dikirim supaya halamannya bisa menandai
     * surat jalan yang sudah dibatalkan — cetakan ulang yang tampak sah adalah cara termudah barang keluar dua kali.
     *
     * @return array<string, mixed>
     */
    public function SuratJalan(SuratJalan $suratJalan): array
    {
        $pesanan = PesananGrosir::query()->whereKey($suratJalan->IdPesananGrosir)->first(['Id', 'Nomor', 'TanggalKirimDiminta']);

        return [
            'SuratJalan' => [
                ...$this->Kepala($suratJalan->Nomor, $suratJalan->IdPelanggan, $suratJalan->IdOutlet),
                'Tanggal' => $suratJalan->Tanggal->format('Y-m-d'),
                'Status' => $suratJalan->Status->value,
                'LabelStatus' => $suratJalan->Status->AmbilLabel(),
                'AlasanBatal' => $suratJalan->AlasanBatal,
                'NamaGudang' => $this->NamaGudang($suratJalan->IdGudang),
                'NomorPesanan' => $pesanan?->Nomor,
                'NamaPengirim' => $suratJalan->NamaPengirim,
                'NomorKendaraan' => $suratJalan->NomorKendaraan,
                'NamaPenerima' => $suratJalan->NamaPenerima,
                'Catatan' => $suratJalan->Catatan,
            ],
            'Baris' => array_values(SuratJalanDetail::query()->where('IdSuratJalan', $suratJalan->Id)->orderBy('Urutan')->get()->map(
                fn (SuratJalanDetail $d): array => [
                    'Urutan' => $d->Urutan,
                    'NamaProduk' => $d->NamaProduk,
                    'Sku' => $d->Sku,
                    'SimbolSatuan' => $d->SimbolSatuan,
                    'Jumlah' => $d->Jumlah,
                    'Rincian' => DetailGrosir::TeksRincian($d->RincianPelacakan),
                ],
            )->all()),
        ];
    }

    /**
     * Faktur penjualan: barisnya baris surat jalan yang ditautkan (faktur tidak punya tabel baris sendiri, BR-12.4),
     * jadi tiap baris menyebut surat jalan asalnya — pembeli mencocokkan tagihan dengan barang yang dia terima.
     *
     * @return array<string, mixed>
     */
    public function Faktur(FakturPenjualan $faktur): array
    {
        $suratJalan = SuratJalan::query()->where('IdFakturPenjualan', $faktur->Id)->orderBy('Tanggal')->orderBy('Id')->get();
        $nomor = $suratJalan->pluck('Nomor', 'Id');
        $baris = SuratJalanDetail::query()->whereIn('IdSuratJalan', $suratJalan->pluck('Id')->all())->orderBy('IdSuratJalan')->orderBy('Urutan')->get();

        return [
            'Faktur' => [
                ...$this->Kepala($faktur->Nomor, $faktur->IdPelanggan, $faktur->IdOutlet),
                'Tanggal' => $faktur->Tanggal->format('Y-m-d'),
                'JatuhTempo' => $faktur->JatuhTempo->format('Y-m-d'),
                'Status' => $faktur->Status->value,
                'LabelStatus' => $faktur->Status->AmbilLabel(),
                'AlasanBatal' => $faktur->AlasanBatal,
                'TerminHari' => $faktur->TerminHari,
                'PeriodePenyerahan' => $faktur->PeriodePenyerahan,
                'NomorFakturPajak' => $faktur->NomorFakturPajak,
                'TarifPpn' => $faktur->TarifPpn,
                'Subtotal' => $faktur->Subtotal,
                'Diskon' => $faktur->Diskon,
                'DasarPengenaanPajak' => $faktur->DasarPengenaanPajak,
                'Pajak' => $faktur->Pajak,
                'Total' => $faktur->Total,
                'Catatan' => $faktur->Catatan,
            ],
            'SuratJalan' => array_values($suratJalan->map(fn (SuratJalan $s): array => [
                'Nomor' => $s->Nomor,
                'Tanggal' => $s->Tanggal->format('Y-m-d'),
                'Total' => $s->Total,
            ])->all()),
            'Baris' => array_values($baris->map(fn (SuratJalanDetail $d): array => [
                'Kunci' => "{$d->IdSuratJalan}-{$d->Urutan}",
                'NomorSuratJalan' => $nomor->get($d->IdSuratJalan) ?? '',
                'NamaProduk' => $d->NamaProduk,
                'Sku' => $d->Sku,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
                'Subtotal' => $d->Subtotal,
            ])->all()),
        ];
    }

    /**
     * Nota kredit retur (BR-12.7): yang dicetak untuk pembeli adalah pengurangan tagihannya, jadi nomor faktur yang
     * dikurangi ikut disebut. Retur atas penyerahan yang belum difakturkan tetap bisa dicetak sebagai tanda terima
     * barang kembali, dan halamannya yang menjelaskan bedanya.
     *
     * @return array<string, mixed>
     */
    public function Retur(ReturGrosir $retur): array
    {
        $suratJalan = SuratJalan::query()->whereKey($retur->IdSuratJalan)->first(['Id', 'Nomor', 'Tanggal']);
        $faktur = $retur->IdFakturPenjualan === null
            ? null
            : FakturPenjualan::query()->whereKey($retur->IdFakturPenjualan)->first(['Id', 'Nomor']);

        return [
            'Retur' => [
                ...$this->Kepala($retur->Nomor, $retur->IdPelanggan, $retur->IdOutlet),
                'Tanggal' => $retur->Tanggal->format('Y-m-d'),
                'Status' => $retur->Status->value,
                'LabelStatus' => $retur->Status->AmbilLabel(),
                'AlasanBatal' => $retur->AlasanBatal,
                'Alasan' => $retur->Alasan,
                'MengurangiPiutang' => $retur->MengurangiPiutang,
                'NomorSuratJalan' => $suratJalan?->Nomor,
                'TanggalSuratJalan' => $suratJalan?->Tanggal->format('Y-m-d'),
                'NomorFaktur' => $faktur?->Nomor,
                'TarifPpn' => $retur->TarifPpn,
                'Subtotal' => $retur->Subtotal,
                'Diskon' => $retur->Diskon,
                'DasarPengenaanPajak' => $retur->DasarPengenaanPajak,
                'Pajak' => $retur->Pajak,
                'Total' => $retur->Total,
                'Catatan' => $retur->Catatan,
            ],
            'Baris' => array_values(ReturGrosirDetail::query()->where('IdReturGrosir', $retur->Id)->orderBy('Urutan')->get()->map(
                fn (ReturGrosirDetail $d): array => [
                    'Urutan' => $d->Urutan,
                    'NamaProduk' => $d->NamaProduk,
                    'Sku' => $d->Sku,
                    'SimbolSatuan' => $d->SimbolSatuan,
                    'Jumlah' => $d->Jumlah,
                    'LabelKondisi' => $d->Kondisi->AmbilLabel(),
                    'Harga' => $d->Harga,
                    'Diskon' => $d->Diskon,
                    'Subtotal' => $d->Subtotal,
                ],
            )->all()),
        ];
    }

    /**
     * Daftar ambil barang dari satu pesanan grosir: **sisa yang belum dikirim**, bukan jumlah pesanannya. Pesanan yang
     * dikirim bertahap kalau tidak begitu akan membuat petugas mengambil barang yang minggu lalu sudah keluar.
     * Barisnya yang sisanya nol tidak dicetak sama sekali.
     *
     * @return array<string, mixed>
     */
    public function DaftarAmbil(PesananGrosir $pesanan): array
    {
        $baris = PesananGrosirDetail::query()->where('IdPesananGrosir', $pesanan->Id)->orderBy('Urutan')->get()
            ->filter(fn (PesananGrosirDetail $d): bool => $d->AmbilSisaKirim()->Bandingkan(Kuantitas::Nol()) > 0);

        return [
            'Pesanan' => [
                ...$this->Kepala($pesanan->Nomor, $pesanan->IdPelanggan, $pesanan->IdOutlet),
                'Tanggal' => $pesanan->Tanggal->format('Y-m-d'),
                'TanggalKirimDiminta' => $pesanan->TanggalKirimDiminta?->format('Y-m-d'),
                'Status' => $pesanan->Status->value,
                'LabelStatus' => $pesanan->Status->AmbilLabel(),
                'Catatan' => $pesanan->Catatan,
            ],
            'Baris' => array_values($baris->map(fn (PesananGrosirDetail $d): array => [
                'Urutan' => $d->Urutan,
                'NamaProduk' => $d->NamaProduk,
                'Sku' => $d->Sku,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'JumlahTerkirim' => $d->JumlahTerkirim,
                'SisaKirim' => $d->AmbilSisaKirim()->KeString(),
            ])->all()),
        ];
    }

    /**
     * @return array{Nomor: string, Pelanggan: array{Nama: string, Alamat: string|null, NoHp: string}, Outlet: array{Kode: string, Nama: string, Alamat: string|null}}
     */
    private function Kepala(string $nomor, int $idPelanggan, int $idOutlet): array
    {
        return [
            'Nomor' => $nomor,
            'Pelanggan' => $this->identitas->AmbilUntukCetak($idPelanggan) ?? ['Nama' => '', 'Alamat' => null, 'NoHp' => ''],
            'Outlet' => $this->petaOutlet->AmbilIdentitas([$idOutlet])[$idOutlet] ?? ['Kode' => '', 'Nama' => '', 'Alamat' => null],
        ];
    }

    private function NamaGudang(int $idGudang): string
    {
        return $this->infoGudang->AmbilBanyak([$idGudang])[$idGudang]->nama ?? '';
    }
}
