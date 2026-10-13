<?php

declare(strict_types=1);

namespace App\Domain\Bengkel\Kueri;

use App\Domain\Bengkel\Enum\JenisBarisPerintahKerja;
use App\Domain\Bengkel\Enum\StatusPerintahKerja;
use App\Domain\Bengkel\Layanan\TautanPersetujuanServis;
use App\Domain\Bengkel\Model\Kendaraan;
use App\Domain\Bengkel\Model\PerintahKerja;
use App\Domain\Bengkel\Model\PerintahKerjaDetail;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Karyawan\Kueri\JadwalStafReservasi;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\SatuanProdukJual;
use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Penjualan\Kueri\RingkasanPenjualanDokumen;
use App\Domain\Persediaan\Kueri\StokTersediaGudang;

/**
 * Isi satu perintah kerja (§9.10) untuk halaman detail, cetak, halaman persetujuan publik, dan aplikasi kasir. Semua
 * data domain lain (pelanggan, outlet, produk, mekanik, stok, penjualan) dibaca lewat kueri publik domain itu.
 *
 * Stok tersedia sparepart = saldo lokasi Toko outlet perintah kerja (sumber stok penjualan kasir), hanya informasi:
 * perintah kerja **tidak mencadangkan** stok; stok berkurang saat ditagih.
 */
final class DetailPerintahKerja
{
    public function __construct(
        private readonly IdentitasPelanggan $pelanggan,
        private readonly PetaUuidOutlet $outlet,
        private readonly JadwalStafReservasi $staf,
        private readonly InfoProdukStok $produk,
        private readonly OutletPenjualan $outletPenjualan,
        private readonly StokTersediaGudang $stok,
        private readonly RingkasanPenjualanDokumen $penjualan,
        private readonly SatuanProdukJual $satuan,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(PerintahKerja $pk, ?string $slugTenant = null, bool $denganStok = false): array
    {
        $detail = PerintahKerjaDetail::query()->where('IdPerintahKerja', $pk->Id)->orderBy('Urutan')->get();
        $kendaraan = Kendaraan::query()->whereKey($pk->IdKendaraan)->first();
        $pelanggan = $this->pelanggan->AmbilUntukCetak($pk->IdPelanggan);
        $uuidPelanggan = $this->pelanggan->AmbilRingkas($pk->IdPelanggan)['Uuid'] ?? null;
        $outlet = $this->outlet->AmbilIdentitas([$pk->IdOutlet])[$pk->IdOutlet] ?? null;
        $uuidOutlet = $this->outlet->Ambil([$pk->IdOutlet])[$pk->IdOutlet] ?? null;
        $mekanik = $this->staf->AmbilRingkas(array_values(array_filter($detail->pluck('IdKaryawan')->all(), 'is_int')));
        $produk = $this->produk->AmbilBanyak(array_values($detail->pluck('IdProduk')->all()), true);
        $satuan = [];

        foreach ($this->satuan->AmbilUntukProduk(array_values(array_unique($detail->pluck('IdProduk')->all()))) as $daftar) {
            foreach ($daftar as $s) {
                $satuan[$s['Id']] = $s['Uuid'];
            }
        }

        $stok = [];

        if ($denganStok) {
            $idGudang = $this->outletPenjualan->AmbilIdGudangToko($pk->IdOutlet);
            $idSparepart = array_values($detail->filter(fn (PerintahKerjaDetail $d): bool => $d->Jenis === JenisBarisPerintahKerja::Sparepart)->pluck('IdProduk')->all());
            $stok = $idGudang === null ? [] : $this->stok->AmbilProduk($idGudang, $idSparepart);
        }

        $penjualan = $pk->IdPenjualan === null ? null : ($this->penjualan->AmbilBanyak([$pk->IdPenjualan])[$pk->IdPenjualan] ?? null);
        $tautan = $slugTenant !== null && $pk->TokenPersetujuan !== null && $pk->CekTautanBerlaku()
            ? TautanPersetujuanServis::Buat($slugTenant, $pk->TokenPersetujuan)
            : null;

        return [
            'Uuid' => $pk->Uuid,
            'Nomor' => $pk->Nomor,
            'Status' => $pk->Status->value,
            'LabelStatus' => $pk->Status->AmbilLabel(),
            'DibuatPada' => $pk->DibuatPada?->toIso8601ZuluString(),
            'KmMasuk' => $pk->KmMasuk,
            'Keluhan' => $pk->Keluhan,
            'Diagnosis' => $pk->Diagnosis,
            'EstimasiSelesaiPada' => $pk->EstimasiSelesaiPada?->toIso8601ZuluString(),
            'CatatanQc' => $pk->CatatanQc,
            'AlasanBatal' => $pk->AlasanBatal,
            'Subtotal' => (string) $pk->Subtotal,
            'Diskon' => (string) $pk->Diskon,
            'Pajak' => (string) $pk->Pajak,
            'Total' => (string) $pk->Total,
            'TotalDisetujui' => (string) $pk->TotalDisetujui,
            'Outlet' => [
                'Uuid' => $uuidOutlet,
                'Nama' => $outlet['Nama'] ?? '',
                'Kode' => $outlet['Kode'] ?? '',
                'Alamat' => $outlet['Alamat'] ?? null,
            ],
            'Pelanggan' => [
                'Uuid' => $uuidPelanggan,
                'Nama' => $pelanggan['Nama'] ?? '-',
                'NoHp' => isset($pelanggan['NoHp']) ? NomorHp::Format($pelanggan['NoHp']) : null,
                'Alamat' => $pelanggan['Alamat'] ?? null,
            ],
            'Kendaraan' => $kendaraan === null ? null : [
                'Uuid' => $kendaraan->Uuid,
                'Jenis' => $kendaraan->Jenis->value,
                'NomorPolisi' => $kendaraan->NomorPolisi,
                'Label' => $kendaraan->AmbilLabel(),
                'Warna' => $kendaraan->Warna,
                'KmTerakhir' => $kendaraan->KmTerakhir,
            ],
            'Persetujuan' => [
                'Tautan' => $tautan,
                'KedaluwarsaPada' => $pk->TokenPersetujuanKedaluwarsaPada?->toIso8601ZuluString(),
                'DikirimPada' => $pk->PersetujuanDikirimPada?->toIso8601ZuluString(),
                'DiputuskanPada' => $pk->DiputuskanPada?->toIso8601ZuluString(),
                'DiputuskanLewat' => $pk->DiputuskanLewat?->value,
                'CatatanPelanggan' => $pk->CatatanPelanggan,
            ],
            'Penjualan' => $penjualan,
            'DitagihPada' => $pk->DitagihPada?->toIso8601ZuluString(),
            'ServisBerikutnyaPada' => $pk->ServisBerikutnyaPada?->toDateString(),
            'ServisBerikutnyaKm' => $pk->ServisBerikutnyaKm,
            'PengingatServisTerkirimPada' => $pk->PengingatServisTerkirimPada?->toIso8601ZuluString(),
            'Baris' => array_values($detail->map(fn (PerintahKerjaDetail $d): array => [
                'Uuid' => $d->Uuid,
                'Urutan' => $d->Urutan,
                'Jenis' => $d->Jenis->value,
                'UuidProduk' => isset($produk[$d->IdProduk]) ? $produk[$d->IdProduk]->uuid : null,
                'UuidProdukSatuan' => $satuan[$d->IdProdukSatuan] ?? null,
                'NamaProduk' => $d->NamaProduk,
                'Sku' => $d->Sku,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => (string) $d->Jumlah,
                'HargaSatuan' => (string) $d->HargaSatuan,
                'Diskon' => (string) $d->Diskon,
                'Subtotal' => (string) $d->Subtotal,
                'Karyawan' => $d->IdKaryawan === null || ! isset($mekanik[$d->IdKaryawan]) ? null : $mekanik[$d->IdKaryawan],
                'Catatan' => $d->Catatan,
                'NomorSeri' => $d->NomorSeri ?? [],
                'Disetujui' => $d->Disetujui,
                'StokTersedia' => $denganStok && $d->Jenis === JenisBarisPerintahKerja::Sparepart ? ($stok[$d->IdProduk] ?? '0.0000') : null,
            ])->all()),
        ];
    }

    /**
     * Riwayat status (siapa & kapan) untuk halaman detail.
     *
     * @return list<array{Dari: string|null, Ke: string, Label: string, Pada: string|null, Alasan: string|null}>
     */
    public function AmbilRiwayat(PerintahKerja $pk): array
    {
        return array_values(RiwayatStatusDokumen::query()
            ->where('JenisDokumen', PerintahKerja::JENIS_DOKUMEN)
            ->where('IdDokumen', $pk->Id)
            ->orderBy('Id')
            ->get()
            ->map(fn (RiwayatStatusDokumen $r): array => [
                'Dari' => $r->StatusDari,
                'Ke' => $r->StatusKe,
                'Label' => StatusPerintahKerja::tryFrom($r->StatusKe)?->AmbilLabel() ?? $r->StatusKe,
                'Pada' => $r->DiubahPada?->toIso8601ZuluString(),
                'Alasan' => $r->Alasan,
            ])->all());
    }
}
