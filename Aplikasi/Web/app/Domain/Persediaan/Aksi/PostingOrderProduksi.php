<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenyediaAkunPeran;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusOrderProduksi;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PencatatJurnalPersediaan;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PenomorDokumenPersediaan;
use App\Domain\Persediaan\Layanan\PenyusunBahanProduksi;
use App\Domain\Persediaan\Layanan\PenyusunJurnalProduksi;
use App\Domain\Persediaan\Model\OrderProduksi;
use App\Domain\Persediaan\Model\OrderProduksiBahan;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * F-05e: memposting order produksi dalam satu transaksi (aturan #10): bahan keluar `ProduksiPakai` dinilai HPP berjalan,
 * hasil masuk `ProduksiHasil` bernilai Σ nilai bahan + overhead (HPP hasil = nilai ÷ jumlah), lalu jurnal J-05.6.
 * Isi diperiksa ulang (produk, bahan, lokasi, tanggal). Stok bahan kurang ditolak sesuai BR-05.2 (`StokTidakCukup`).
 * Akun overhead dibuat bila tenant belum punya (`PenyediaAkunPeran`). Idempoten: order yang sudah dicatat
 * dikembalikan apa adanya. Audit `order-produksi.posting`.
 */
final class PostingOrderProduksi
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturan,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly PenyusunBahanProduksi $penyusunBahan,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly InfoProdukStok $infoProduk,
        private readonly PenomorDokumenPersediaan $penomor,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalProduksi $penyusunJurnal,
        private readonly PencatatJurnalPersediaan $pencatatJurnal,
        private readonly PenyediaAkunPeran $penyediaAkun,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(OrderProduksi $order, int $idPengguna): OrderProduksi
    {
        return DB::transaction(function () use ($order, $idPengguna): OrderProduksi {
            $this->pengaturan->AmbilDenganKunciBaca();
            $order = OrderProduksi::query()->whereKey($order->Id)->lockForUpdate()->firstOrFail();

            if ($order->Status === StatusOrderProduksi::Diposting) {
                return $order;
            }

            if ($order->Status !== StatusOrderProduksi::Draf) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Order produksi berstatus {$order->Status->AmbilLabel()} tidak bisa dicatat.");
            }

            $gudang = $this->pemeriksaLokasi->AmbilLokasi($order->IdGudang);
            $tanggal = CarbonImmutable::parse($order->Tanggal->format('Y-m-d'));
            $this->pemeriksaLokasi->PastikanBukanMasaDepan($tanggal, $gudang->idOutlet);
            $bahan = $order->Bahan()->orderBy('Urutan')->get();
            $jumlahHasil = Kuantitas::Dari($order->JumlahHasil);
            $this->penyusunBahan->Susun($order->IdProduk, $jumlahHasil, array_values($bahan->map(fn (OrderProduksiBahan $b): array => ['idProduk' => $b->IdProduk, 'jumlah' => Kuantitas::Dari($b->Jumlah)])->all()));

            $idProduk = [$order->IdProduk, ...$bahan->pluck('IdProduk')->all()];
            $this->pengunciSaldo->Kunci(array_values(array_map(fn (int $id): array => [$id, $gudang->id], $idProduk)));
            $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($idProduk)));
            $overhead = Uang::Dari($order->BiayaOverhead);

            if (! $overhead->BernilaiNol()) {
                $this->penyediaAkun->Pastikan(PeranAkun::OverheadProduksiDibebankan, '5-1300', 'Overhead Produksi Dibebankan');
            }

            $nomor = $this->penomor->AmbilProduksi($tanggal, $gudang->kode);
            $hasilBahan = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
                JenisReferensiMutasi::Produksi,
                $order->Id,
                $order->Uuid,
                $nomor,
                $tanggal,
                $idPengguna,
                null,
                array_values($bahan->map(fn (OrderProduksiBahan $b): DataBarisMutasi => new DataBarisMutasi(
                    kunciBaris: 'B/'.$b->Id,
                    idProduk: $b->IdProduk,
                    idGudang: $gudang->id,
                    jenisMutasi: JenisMutasi::ProduksiPakai,
                    jumlah: Kuantitas::Dari($b->Jumlah)->Negasi(),
                    modeNilai: ModeNilaiMutasi::Berjalan,
                    idReferensiDetail: $b->Id,
                ))->all()),
            ));

            $totalBahan = Uang::Nol();

            foreach ($bahan as $b) {
                $nilai = AritmetikaHpp::AmbilMutlak($hasilBahan->baris['B/'.$b->Id]->totalHpp);
                $b->Nilai = $nilai->KeString();
                $b->save();
                $totalBahan = $totalBahan->Tambah($nilai);
            }

            $nilaiHasil = $totalBahan->Tambah($overhead);
            $hppHasil = AritmetikaHpp::Hpp($nilaiHasil, $jumlahHasil);
            $hasilProduk = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
                JenisReferensiMutasi::Produksi,
                $order->Id,
                $order->Uuid,
                $nomor,
                $tanggal,
                $idPengguna,
                null,
                [new DataBarisMutasi(
                    kunciBaris: 'H',
                    idProduk: $order->IdProduk,
                    idGudang: $gudang->id,
                    jenisMutasi: JenisMutasi::ProduksiHasil,
                    jumlah: $jumlahHasil,
                    modeNilai: ModeNilaiMutasi::Ditentukan,
                    nilai: $nilaiHasil,
                    hppSatuan: $hppHasil,
                    batchMasuk: $produk[$order->IdProduk]->pelacakan === PelacakanProduk::Batch
                        ? new DataBatchMasuk((string) $order->NomorBatch, $order->TanggalKedaluwarsa === null ? null : CarbonImmutable::parse($order->TanggalKedaluwarsa->format('Y-m-d')))
                        : null,
                )],
            ));

            $jurnal = $this->pencatatJurnal->Posting(
                JenisSumberJurnal::OrderProduksi,
                $order->Id,
                $order->Uuid,
                $nomor,
                $tanggal,
                "Produksi {$nomor}: {$order->NamaProduk} di {$gudang->nama}",
                $this->penyusunJurnal->Susun($hasilProduk->baris['H'], array_values($hasilBahan->baris), $produk, $overhead, $gudang->idOutlet),
                $idPengguna,
            );

            $order->UbahStatus(StatusOrderProduksi::Diposting);
            $order->fill([
                'Nomor' => $nomor,
                'TotalNilaiBahan' => $totalBahan->KeString(),
                'NilaiHasil' => $nilaiHasil->KeString(),
                'HppSatuanHasil' => (string) $hppHasil,
                'IdJurnal' => $jurnal?->idJurnal,
                'DipostingOleh' => $idPengguna,
                'DipostingPada' => now(),
                'DiubahOleh' => $idPengguna,
            ]);
            $order->save();

            $this->riwayat->Catat(OrderProduksi::JENIS_DOKUMEN, $order->Id, StatusOrderProduksi::Draf->value, StatusOrderProduksi::Diposting->value, $idPengguna);
            $this->audit->Catat('order-produksi.posting', $order, ['Status' => StatusOrderProduksi::Draf->value], [
                'Status' => StatusOrderProduksi::Diposting->value,
                'Nomor' => $nomor,
                'JumlahHasil' => $order->JumlahHasil,
                'TotalNilaiBahan' => $order->TotalNilaiBahan,
                'BiayaOverhead' => $order->BiayaOverhead,
                'NilaiHasil' => $order->NilaiHasil,
                'NomorJurnal' => $jurnal?->nomor,
            ], idPengguna: $idPengguna);

            return $order;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
