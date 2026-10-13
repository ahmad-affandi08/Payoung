<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Kueri;

use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\SatuanProdukJual;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;

/**
 * Isian awal formulir perintah kerja (§9.10): ubah perintah kerja yang ada (baris beserta pilihan satuannya), atau
 * buat baru dari kendaraan yang dipilih (`?kendaraan=` dari daftar kendaraan/detail pelanggan).
 */
final class IsianFormPerintahKerja
{
    public function __construct(
        private readonly DetailPerintahKerja $detail,
        private readonly InfoProdukStok $produk,
        private readonly SatuanProdukJual $satuan,
        private readonly IdentitasPelanggan $pelanggan,
        private readonly ZonaWaktuOutlet $zona,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function DariPerintahKerja(PerintahKerja $pk): array
    {
        $d = $this->detail->Ambil($pk, null, true);
        $info = $this->produk->AmbilDariUuid(array_values(array_filter(array_column($d['Baris'], 'UuidProduk'), 'is_string')));
        $satuan = $this->satuan->AmbilUntukProduk(array_values(array_map(fn ($i): int => $i->id, $info)));
        $estimasi = $pk->EstimasiSelesaiPada?->copy()->setTimezone($this->zona->Ambil($pk->IdOutlet));

        return [
            'Uuid' => $d['Uuid'],
            'Nomor' => $d['Nomor'],
            'UuidOutlet' => $d['Outlet']['Uuid'],
            'UuidPelanggan' => $d['Pelanggan']['Uuid'],
            'NamaPelanggan' => $d['Pelanggan']['Nama'],
            'Kendaraan' => $d['Kendaraan'],
            'KmMasuk' => $d['KmMasuk'],
            'Keluhan' => $d['Keluhan'],
            'Diagnosis' => $d['Diagnosis'],
            'EstimasiSelesaiPada' => $estimasi?->format('Y-m-d\TH:i'),
            'Baris' => array_values(array_map(fn (array $b): array => [
                'Jenis' => $b['Jenis'],
                'UuidProduk' => $b['UuidProduk'],
                'UuidProdukSatuan' => $b['UuidProdukSatuan'],
                'NamaProduk' => $b['NamaProduk'],
                'SimbolSatuan' => $b['SimbolSatuan'],
                'Jumlah' => $b['Jumlah'],
                'Diskon' => $b['Diskon'],
                'UuidKaryawan' => $b['Karyawan']['Uuid'] ?? null,
                'Catatan' => $b['Catatan'],
                'NomorSeri' => $b['NomorSeri'],
                'Pelacakan' => ($info[(string) $b['UuidProduk']] ?? null)?->pelacakan->value ?? 'Tidak',
                'StokTersedia' => $b['StokTersedia'],
                'Satuan' => array_map(
                    fn (array $s): array => ['Uuid' => $s['Uuid'], 'Simbol' => $s['Simbol'], 'Konversi' => $s['Konversi']],
                    $satuan[$info[(string) $b['UuidProduk']]->id ?? 0] ?? [],
                ),
            ], $d['Baris'])),
        ];
    }

    /**
     * Isian baru dari kendaraan terpilih (pelanggan pemiliknya ikut terisi); null bila kendaraan tidak dikenal.
     *
     * @return array<string, mixed>|null
     */
    public function DariKendaraan(string $uuidKendaraan): ?array
    {
        $k = Kendaraan::query()->where('Uuid', strtoupper($uuidKendaraan))->where('Aktif', true)->first();

        if ($k === null) {
            return null;
        }

        $p = $this->pelanggan->AmbilRingkas($k->IdPelanggan);

        return [
            'UuidPelanggan' => $p['Uuid'] ?? null,
            'NamaPelanggan' => $p['Nama'] ?? null,
            'Kendaraan' => ['Uuid' => $k->Uuid, 'Jenis' => $k->Jenis->value, 'NomorPolisi' => $k->NomorPolisi, 'Label' => $k->AmbilLabel(), 'KmTerakhir' => $k->KmTerakhir, 'Warna' => $k->Warna],
        ];
    }
}
