<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Data\DataBarisPesananPembelian;
use App\Domain\Pembelian\Data\DataPesananPembelian;
use App\Domain\Pembelian\Kueri\KebutuhanBeliUlang;
use App\Domain\Tenant\Data\DataPengaturanPembelian;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * D-23 D: siapkan draf pesanan pembelian untuk barang di bawah stok minimum (atau, F24, sesuai perkiraan pemakaian), satu draf per (lokasi stok, pemasok),
 * lewat `SimpanPesananPembelian` (nomor, pajak, audit sama seperti draf manual; `DibuatOtomatis` = true). Draf tidak
 * pernah diajukan otomatis. Jumlah sudah dikurangi PO terbuka, jadi menjalankan ulang tidak menggandakan pesanan.
 */
final class BuatDrafPoOtomatis
{
    public const CATATAN = 'Draf otomatis dari stok di bawah minimum. Periksa jumlah & harga sebelum diajukan.';

    public const CATATAN_PERKIRAAN = 'Draf otomatis dari perkiraan pemakaian %d hari terakhir untuk kebutuhan %d hari ke depan. Periksa jumlah & harga sebelum diajukan.';

    public function __construct(
        private readonly KebutuhanBeliUlang $kebutuhan,
        private readonly SimpanPesananPembelian $simpan,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{JumlahPo: int, JumlahBaris: int, TanpaPemasok: list<string>, UuidPo: list<string>}
     */
    public function Jalankan(int $idPengguna, CarbonImmutable $tanggal, ?array $idOutletBoleh = null, string $sumber = DataPengaturanPembelian::SUMBER_DRAF_MINIMUM, int $hariCakupan = DataPengaturanPembelian::HARI_CAKUPAN_DRAF_BAWAAN): array
    {
        return DB::transaction(function () use ($idPengguna, $tanggal, $idOutletBoleh, $sumber, $hariCakupan): array {
            $hasil = $this->kebutuhan->Ambil($idOutletBoleh, $sumber, $hariCakupan, $tanggal);
            $catatan = $sumber === DataPengaturanPembelian::SUMBER_DRAF_PERKIRAAN ? sprintf(self::CATATAN_PERKIRAAN, KebutuhanBeliUlang::HARI_DASAR_PERKIRAAN, $hariCakupan) : self::CATATAN;
            $uuid = [];
            $jumlahBaris = 0;

            foreach ($hasil['Kelompok'] as $k) {
                $po = $this->simpan->Jalankan(new DataPesananPembelian(
                    uuidPemasok: $k['UuidPemasok'],
                    idGudang: $k['IdGudang'],
                    tanggal: $tanggal,
                    perkiraanTiba: null,
                    terminHari: null,
                    ongkir: Uang::Nol(),
                    catatan: $catatan,
                    baris: array_map(fn (array $b): DataBarisPesananPembelian => new DataBarisPesananPembelian(
                        $b['UuidProduk'],
                        $b['UuidProdukSatuan'],
                        Kuantitas::Dari($b['Jumlah']),
                        Uang::Dari($b['Harga']),
                        Uang::Nol(),
                    ), $k['Baris']),
                    idPengguna: $idPengguna,
                    dibuatOtomatis: true,
                ));
                $uuid[] = $po->Uuid;
                $jumlahBaris += count($k['Baris']);
            }

            return ['JumlahPo' => count($uuid), 'JumlahBaris' => $jumlahBaris, 'TanpaPemasok' => $hasil['TanpaPemasok'], 'UuidPo' => $uuid];
        });
    }
}
