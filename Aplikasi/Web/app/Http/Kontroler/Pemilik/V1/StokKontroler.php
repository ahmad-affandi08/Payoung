<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pemilik\V1;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Laporan\Kueri\LaporanStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Kueri\DaftarSaldoStok;
use App\Domain\Persediaan\Kueri\KartuStok;
use App\Http\Permintaan\Pemilik\V1\KartuStokPermintaan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cek stok di Aplikasi Owner (OWN-06, izin `persediaan.lihat`, dibatasi outlet akses): ringkasan nilai persediaan &
 * jumlah stok kritis, sepuluh stok kritis teratas, saldo per produk × lokasi (cari nama/SKU/barcode, berhalaman), dan
 * riwayat kartu stok satu produk di satu lokasi. Angkanya dari kueri yang sama dengan back-office (`DaftarSaldoStok`,
 * `LaporanStok`, `KartuStok`), jadi selalu sama.
 */
final class StokKontroler extends DasarPemilikKontroler
{
    public const HARI_MAKS_KARTU = 92;

    private const TOP_KRITIS = 10;

    public function Ringkasan(Request $permintaan, DaftarSaldoStok $saldo, LaporanStok $laporan): JsonResponse
    {
        $idOutlet = $this->SaringOutlet($permintaan->query('outlet') === null ? null : (string) $permintaan->string('outlet'), $this->IdOutletBoleh($permintaan));
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarSaldoStok::KOLOM_URUT, 'Nama');
        $hasil = $saldo->AmbilTabel($tabel, $idOutlet);
        $nilai = $laporan->NilaiPersediaan($this->TanggalHariIni($idOutlet), $idOutlet);
        $kritis = $laporan->StokKritis($idOutlet, batas: self::TOP_KRITIS);

        return response()->json([
            'Ringkasan' => [
                'NilaiPersediaan' => $nilai['Total']['Nilai'],
                'JumlahProduk' => $nilai['Total']['JumlahProduk'],
                'JumlahKritis' => $kritis['Jumlah'],
                'JumlahMinus' => $hasil['Ringkasan']['JumlahMinus'],
            ],
            'Kritis' => $kritis['Baris'],
            'Saldo' => [
                'Data' => array_map(fn (array $b): array => [
                    'UuidProduk' => $b['UuidProduk'],
                    'UuidGudang' => $b['UuidGudang'],
                    'NamaProduk' => $b['NamaProduk'],
                    'Sku' => $b['Sku'],
                    'SimbolSatuan' => $b['SimbolSatuan'],
                    'NamaGudang' => $b['NamaGudang'],
                    'NamaOutlet' => $b['NamaOutlet'],
                    'JumlahTersedia' => $b['JumlahTersedia'],
                    'HppRataRata' => $b['HppRataRata'],
                    'NilaiPersediaan' => $b['NilaiPersediaan'],
                ], $hasil['Data']),
                'Meta' => $hasil['Meta'],
            ],
        ]);
    }

    public function Kartu(KartuStokPermintaan $permintaan, KartuStok $kartu, InfoProdukStok $infoProduk, InfoGudang $infoGudang): JsonResponse
    {
        $uuidProduk = strtoupper((string) $permintaan->string('produk'));
        $uuidGudang = strtoupper((string) $permintaan->string('gudang'));
        $produk = $infoProduk->AmbilDariUuid([$uuidProduk])[$uuidProduk] ?? null;
        $gudang = $infoGudang->AmbilDariUuid([$uuidGudang])[$uuidGudang] ?? null;
        $boleh = $this->IdOutletBoleh($permintaan);
        abort_if($produk === null || $gudang === null, 404);
        abort_if($boleh !== null && ($gudang->idOutlet === null || ! in_array($gudang->idOutlet, $boleh, true)), 404);

        $sampai = $permintaan->filled('sampai') ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $permintaan->string('sampai')) : $this->TanggalHariIni([(int) $gudang->idOutlet]);
        $dari = $permintaan->filled('dari') ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $permintaan->string('dari')) : ($sampai instanceof CarbonImmutable ? $sampai->subDays(29) : null);
        abort_unless($dari instanceof CarbonImmutable && $sampai instanceof CarbonImmutable, 422);

        if ($dari->diffInDays($sampai) + 1 > self::HARI_MAKS_KARTU) {
            throw new PelanggaranAturanBisnis('RentangTerlaluPanjang', 'Rentang kartu stok paling panjang '.self::HARI_MAKS_KARTU.' hari. Persempit tanggalnya.', 'sampai');
        }

        $isi = $kartu->Ambil($produk->id, $gudang->id, $dari, $sampai, max(1, (int) $permintaan->integer('halaman', 1)), 50);

        return response()->json([
            'Produk' => ['Uuid' => $produk->uuid, 'Nama' => $produk->nama, 'Sku' => $produk->sku, 'SimbolSatuan' => $produk->simbolSatuan],
            'Gudang' => ['Uuid' => $gudang->uuid, 'Nama' => $gudang->nama, 'NamaOutlet' => $gudang->namaOutlet],
            'Periode' => ['Dari' => $dari->toDateString(), 'Sampai' => $sampai->toDateString()],
            'SaldoAwal' => $isi['SaldoAwal'],
            'SaldoAkhir' => $isi['SaldoAkhir'],
            'Mutasi' => [
                'Data' => array_map(fn (array $m): array => [
                    'TanggalBisnis' => $m['TanggalBisnis'],
                    'LabelJenisMutasi' => $m['LabelJenisMutasi'],
                    'NomorReferensi' => $m['NomorReferensi'],
                    'Masuk' => $m['Masuk'],
                    'Keluar' => $m['Keluar'],
                    'SaldoSetelah' => $m['SaldoSetelah'],
                ], $isi['Mutasi']['Data']),
                'Meta' => $isi['Mutasi']['Meta'],
            ],
        ]);
    }
}
