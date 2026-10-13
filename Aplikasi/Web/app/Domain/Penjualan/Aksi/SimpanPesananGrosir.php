<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Harga\Kueri\HargaProdukBerlaku;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\TierPelanggan;
use App\Domain\Penjualan\Data\DataPesananGrosir;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Layanan\PenghitungGrosir;
use App\Domain\Penjualan\Layanan\PenomorGrosir;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Penjualan\Model\PesananGrosirDetail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Simpan draf SO grosir (F-12, §9.7): buat baru atau ganti isi draf yang sudah ada.
 *
 * - **Harga datang dari server**, bukan dari klien: `HargaProdukBerlaku` (price engine yang sama dengan kasir, termasuk
 *   daftar harga bertingkat per jumlah & tier pelanggan, X8). Harga lalu di-**snapshot** di baris, sehingga harga yang
 *   sudah disepakati dengan pembeli tidak bergeser sendiri bila daftar harga berubah sebelum SO dikonfirmasi.
 * - **Cakupan bagian 1** (§25 no. 27d): hanya produk berjenis Stok tanpa pelacakan batch/seri. Resep, paket, jasa,
 *   dan produksi ditolak dengan galat yang menyebut alasannya, bukan diam-diam dihitung salah.
 * - Angka dokumen dihitung `PenghitungGrosir` (mesin kalkulasi F-07a yang sama dengan kasir).
 * - Hanya draf yang boleh diubah; setelah dikonfirmasi barisnya dikunci `JagaDokumenTerposting`.
 */
final class SimpanPesananGrosir
{
    public function __construct(
        private readonly HargaProdukBerlaku $harga,
        private readonly PenghitungGrosir $penghitung,
        private readonly PenomorGrosir $penomor,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataPesananGrosir $data, int $idPengguna, ?string $uuidPesanan = null): PesananGrosir
    {
        if ($data->baris === []) {
            throw new PelanggaranAturanBisnis('PesananTanpaBaris', 'Pesanan grosir harus punya minimal satu barang.', 'Baris');
        }

        return DB::transaction(function () use ($data, $idPengguna, $uuidPesanan): PesananGrosir {
            $pelanggan = Pelanggan::query()->where('Uuid', $data->uuidPelanggan)->first()
                ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan tidak ditemukan.', 'UuidPelanggan');
            $outlet = Outlet::query()->whereKey($data->idOutlet)->first()
                ?? throw new PelanggaranAturanBisnis('OutletTidakDikenal', 'Outlet tidak ditemukan.', 'IdOutlet');

            $pesanan = $uuidPesanan === null
                ? null
                : (PesananGrosir::query()->where('Uuid', $uuidPesanan)->lockForUpdate()->first()
                    ?? throw new PelanggaranAturanBisnis('PesananTidakDitemukan', 'Pesanan grosir tidak ditemukan.'));

            if ($pesanan !== null && ! $pesanan->Status->CekBolehDiubah()) {
                throw new PelanggaranAturanBisnis(
                    'PesananTidakBisaDiubah',
                    "Pesanan {$pesanan->Nomor} sudah {$pesanan->Status->AmbilLabel()} dan tidak bisa diubah. Buat pesanan baru atau batalkan yang ini.",
                );
            }

            $tier = $pelanggan->IdTier === null ? null : TierPelanggan::query()->whereKey($pelanggan->IdTier)->value('Kode');
            $baris = $this->SusunBaris($data, is_string($tier) ? $tier : null);
            $hasil = $this->penghitung->Hitung($outlet->Id, $outlet->KodeKota, array_map(
                fn (array $b): array => [
                    'Jumlah' => $b['Jumlah'],
                    'HargaSatuan' => $b['HargaSatuan'],
                    'Diskon' => $b['Diskon'],
                    'IdKelompokPajak' => $b['IdKelompokPajak'],
                    'HargaTermasukPajak' => $b['HargaTermasukPajak'],
                ],
                $baris,
            ));

            $nilai = [
                'IdPelanggan' => $pelanggan->Id,
                'IdOutlet' => $outlet->Id,
                'Tanggal' => $data->tanggal->toDateString(),
                'TanggalKirimDiminta' => $data->tanggalKirimDiminta?->toDateString(),
                'Catatan' => $data->catatan,
                'Subtotal' => $hasil->subtotal->KeString(),
                'Diskon' => $hasil->diskon->KeString(),
                'DasarPengenaanPajak' => $hasil->dasarPengenaanPajak->KeString(),
                'Pajak' => $hasil->pajak->KeString(),
                'Total' => $hasil->total->KeString(),
                'DiubahOleh' => $idPengguna,
            ];

            if ($pesanan === null) {
                $pesanan = PesananGrosir::query()->create([
                    ...$nilai,
                    ...($data->uuid === null ? [] : ['Uuid' => $data->uuid]),
                    'Nomor' => $this->penomor->AmbilNomorOutlet(JenisDokumenBernomor::PesananGrosir, $data->tanggal, $outlet->Id),
                    'Status' => StatusPesananGrosir::Draf,
                    'Sumber' => $data->sumber,
                    'IdSalesman' => $data->idSalesman,
                    'IdPerangkat' => $data->idPerangkat,
                    'DibuatOleh' => $idPengguna,
                ]);
            } else {
                $pesanan->update($nilai);
                // Draf: baris lama diganti seluruhnya, bukan ditambal, supaya urutan & snapshot harga konsisten.
                PesananGrosirDetail::query()->where('IdPesananGrosir', $pesanan->Id)->delete();
            }

            foreach ($baris as $urutan => $satuBaris) {
                PesananGrosirDetail::query()->create([
                    'IdPesananGrosir' => $pesanan->Id,
                    'Urutan' => $urutan + 1,
                    'IdProduk' => $satuBaris['IdProduk'],
                    'NamaProduk' => $satuBaris['NamaProduk'],
                    'Sku' => $satuBaris['Sku'],
                    'IdProdukSatuan' => $satuBaris['IdProdukSatuan'],
                    'SimbolSatuan' => $satuBaris['SimbolSatuan'],
                    'Konversi' => $satuBaris['Konversi'],
                    'Jumlah' => $satuBaris['Jumlah']->KeString(),
                    'Harga' => $satuBaris['HargaSatuan']->KeString(),
                    'Diskon' => $satuBaris['Diskon']->KeString(),
                    // Kotor; diskonnya kolom tersendiri, supaya Σ baris = Subtotal dokumen dan Σ diskon = Diskon dokumen.
                    'Subtotal' => $satuBaris['HargaSatuan']->Kali($satuBaris['Jumlah']->KeString())->KeString(),
                    'HargaTermasukPajak' => $satuBaris['HargaTermasukPajak'],
                    'IdKelompokPajak' => $satuBaris['IdKelompokPajak'],
                ]);
            }

            $this->audit->Catat($uuidPesanan === null ? 'grosir.pesanan-buat' : 'grosir.pesanan-ubah', $pesanan, nilaiBaru: [
                'Nomor' => $pesanan->Nomor,
                'IdPelanggan' => $pelanggan->Id,
                'JumlahBaris' => count($baris),
                'Total' => $pesanan->Total,
                'Sumber' => $pesanan->Sumber->value,
            ]);

            return $pesanan->load('Detail');
        });
    }

    /**
     * @return list<array{IdProduk: int, NamaProduk: string, Sku: string|null, IdProdukSatuan: int, SimbolSatuan: string, Konversi: string, Jumlah: Kuantitas, HargaSatuan: Uang, Diskon: Uang, IdKelompokPajak: int|null, HargaTermasukPajak: bool|null}>
     */
    private function SusunBaris(DataPesananGrosir $data, ?string $tier): array
    {
        $hasil = [];

        foreach ($data->baris as $baris) {
            $satuan = ($baris->uuidProdukSatuan === null ? $this->AmbilSatuanDasar($baris->uuidProduk) : ProdukSatuan::query()->with('Produk', 'SatuanUnit')->where('Uuid', $baris->uuidProdukSatuan)->first())
                ?? throw new PelanggaranAturanBisnis('SatuanTidakDikenal', 'Satuan produk tidak ditemukan.', 'Baris');
            $produk = $satuan->Produk;

            if ($produk->Uuid !== $baris->uuidProduk) {
                throw new PelanggaranAturanBisnis('SatuanBukanMilikProduk', 'Satuan yang dipilih bukan milik produk itu.', 'Baris');
            }

            $this->PastikanProdukDidukung($produk);

            $harga = $this->harga->Tentukan($produk, $satuan, $baris->jumlah, $data->idOutlet, null, $tier, CarbonImmutable::now())
                ?? throw new PelanggaranAturanBisnis(
                    'HargaBelumDiatur',
                    "Produk {$produk->Nama} belum punya harga jual untuk satuan itu. Atur harganya dulu di katalog.",
                    'Baris',
                );

            if ($baris->diskon->Bandingkan($harga->harga->Kali($baris->jumlah->KeString())) > 0) {
                throw new PelanggaranAturanBisnis('DiskonMelebihiBaris', "Diskon baris {$produk->Nama} melebihi nilai barisnya.", 'Baris');
            }

            $hasil[] = [
                'IdProduk' => $produk->Id,
                'NamaProduk' => $produk->Nama,
                'Sku' => $produk->Sku,
                'IdProdukSatuan' => $satuan->Id,
                'SimbolSatuan' => $satuan->SatuanUnit->Simbol,
                'Konversi' => $satuan->KonversiKeDasar,
                'Jumlah' => $baris->jumlah,
                'HargaSatuan' => $harga->harga,
                'Diskon' => $baris->diskon,
                'IdKelompokPajak' => $produk->IdKelompokPajak,
                'HargaTermasukPajak' => $produk->HargaTermasukPajak,
            ];
        }

        return $hasil;
    }

    /** Satuan dasar produk (baris tanpa satuan dari aplikasi salesman); null bila produknya tidak dikenal. */
    private function AmbilSatuanDasar(string $uuidProduk): ?ProdukSatuan
    {
        $produk = Produk::query()->where('Uuid', $uuidProduk)->first();

        return $produk === null ? null : ProdukSatuan::query()->with('Produk', 'SatuanUnit')
            ->where('IdProduk', $produk->Id)
            ->where('IdSatuan', $produk->IdSatuanDasar)
            ->first();
    }

    /**
     * Cakupan grosir (§25 no. 27d): produk berstok biasa, ber-batch (FEFO saat surat jalan), atau bernomor seri (nomor
     * dicatat saat surat jalan). Penolakannya sengaja eksplisit per sebab: menghitung resep atau paket di sini
     * berarti HPP dan pemotongan stoknya salah saat surat jalan diposting, dan itu jenis kesalahan yang baru terlihat
     * di laporan keuangan.
     */
    private function PastikanProdukDidukung(Produk $produk): void
    {
        if (! $produk->Aktif) {
            throw new PelanggaranAturanBisnis('ProdukTidakAktif', "Produk {$produk->Nama} sudah tidak aktif.", 'Baris');
        }

        if ($produk->Jenis !== JenisProduk::Stok) {
            throw new PelanggaranAturanBisnis(
                'JenisProdukBelumDidukung',
                "Grosir mendukung produk berstok (biasa, ber-batch, atau bernomor seri); {$produk->Nama} berjenis {$produk->Jenis->value}.",
                'Baris',
            );
        }
    }
}
