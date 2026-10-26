<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusOrderProduksi;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PenyusunJurnalProduksi;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\LapisanFifo;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\OrderProduksi;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * F-05e: membatalkan order produksi. Draf → Dibatalkan tanpa efek stok. Diposting → Dibatalkan dengan mutasi pembalik
 * (hasil keluar & bahan kembali masuk pada nilai asal) dan jurnal pembalik (`KunciSumber` Pembatalan), di tanggal
 * bisnis hari ini; ditolak `StokSudahTerpakai` bila hasil produksi sudah terjual/berpindah (saldo, batch, atau
 * lapisan FIFO-nya tidak utuh lagi). Alasan wajib 5–255 karakter untuk order tercatat. Audit
 * `order-produksi.batalkan`.
 */
final class BatalkanOrderProduksi
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturan,
        private readonly MutasiDokumen $mutasiDokumen,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalProduksi $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(OrderProduksi $order, ?string $alasan, int $idPengguna): OrderProduksi
    {
        $alasan = trim((string) $alasan);

        return DB::transaction(function () use ($order, $alasan, $idPengguna): OrderProduksi {
            $pengaturan = $this->pengaturan->AmbilDenganKunciBaca();
            $order = OrderProduksi::query()->whereKey($order->Id)->lockForUpdate()->firstOrFail();
            $asal = $order->Status;

            if ($asal === StatusOrderProduksi::Dibatalkan) {
                return $order;
            }

            if ($asal === StatusOrderProduksi::Diposting) {
                $panjang = mb_strlen($alasan);

                if ($panjang < 5 || $panjang > 255) {
                    throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
                }

                $order->IdJurnalPembatalan = $this->Balikkan($order, $pengaturan->metodeHpp, $alasan, $idPengguna);
            }

            $order->UbahStatus(StatusOrderProduksi::Dibatalkan);
            $order->fill([
                'AlasanBatal' => $alasan === '' ? null : mb_substr($alasan, 0, 255),
                'DibatalkanOleh' => $idPengguna,
                'DibatalkanPada' => now(),
                'DiubahOleh' => $idPengguna,
            ]);
            $order->save();

            $this->riwayat->Catat(OrderProduksi::JENIS_DOKUMEN, $order->Id, $asal->value, StatusOrderProduksi::Dibatalkan->value, $idPengguna, $alasan === '' ? null : $alasan);
            $this->audit->Catat('order-produksi.batalkan', $order, ['Status' => $asal->value], [
                'Status' => StatusOrderProduksi::Dibatalkan->value,
                'Nomor' => $order->Nomor,
                'Alasan' => $alasan === '' ? null : $alasan,
                'IdJurnalPembatalan' => $order->IdJurnalPembatalan,
            ], idPengguna: $idPengguna);

            return $order;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    private function Balikkan(OrderProduksi $order, MetodeHpp $metode, string $alasan, int $idPengguna): ?int
    {
        $mutasi = $this->mutasiDokumen->Ambil(JenisReferensiMutasi::Produksi, $order->Id);
        $asli = array_values(array_filter($mutasi, fn (MutasiStok $m): bool => ! str_starts_with($m->KunciBaris, 'X/')));
        $hasil = array_values(array_filter($asli, fn (MutasiStok $m): bool => $m->KunciBaris === 'H'))[0] ?? null;

        if ($hasil === null) {
            throw new PelanggaranAturanBisnis('MutasiTidakDitemukan', 'Mutasi stok order produksi ini tidak ditemukan. Hubungi dukungan.');
        }

        $gudang = $this->infoGudang->AmbilBanyak([$order->IdGudang])[$order->IdGudang];
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (MutasiStok $m): int => $m->IdProduk, $asli))), denganTerhapus: true);
        $this->PastikanHasilUtuh($hasil, $produk[$hasil->IdProduk]->nama ?? $order->NamaProduk, $gudang->nama, $metode);
        $tanggal = $this->tanggalBisnis->Hitung($order->IdOutlet);

        $balik = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::Produksi,
            $order->Id,
            $order->Uuid,
            (string) $order->Nomor,
            $tanggal,
            $idPengguna,
            null,
            array_map(fn (MutasiStok $m): DataBarisMutasi => new DataBarisMutasi(
                kunciBaris: 'X/'.$m->KunciBaris,
                idProduk: $m->IdProduk,
                idGudang: $m->IdGudang,
                jenisMutasi: $m->JenisMutasi,
                jumlah: Kuantitas::Dari($m->Jumlah)->Negasi(),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: AritmetikaHpp::AmbilMutlak(Uang::Dari($m->TotalHpp)->Kurangi(Uang::Dari($m->SelisihHpp))),
                hppSatuan: BigDecimal::of($m->HppSatuan),
                idReferensiDetail: $m->IdReferensiDetail,
                idBatchStok: $m->IdBatchStok,
                idMutasiAsal: $m->Id,
            ), $asli),
        ));

        $hasilBalik = $balik->baris['X/H'];
        $bahanBalik = array_values(array_filter($balik->baris, fn ($b): bool => $b->kunciBaris !== 'X/H'));
        $baris = $this->penyusunJurnal->Susun($hasilBalik, $bahanBalik, $produk, Uang::Nol()->Kurangi(Uang::Dari($order->BiayaOverhead)), $gudang->idOutlet);

        if ($baris === []) {
            return null;
        }

        return $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::OrderProduksi,
            idSumber: $order->Id,
            uuidSumber: $order->Uuid,
            nomorSumber: (string) $order->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Pembatalan produksi {$order->Nomor}: {$alasan}", 0, 255),
            baris: $baris,
            idPengguna: $idPengguna,
            kunciSumber: 'Pembatalan',
            idJurnalDibalik: $order->IdJurnal,
        ))->idJurnal;
    }

    /** Hasil produksi harus masih utuh di lokasi (saldo, batch, lapisan FIFO) agar pembalikan tidak merusak HPP. */
    private function PastikanHasilUtuh(MutasiStok $hasil, string $nama, string $namaGudang, MetodeHpp $metode): void
    {
        $jumlah = Kuantitas::Dari($hasil->Jumlah);
        $saldo = $this->pengunciSaldo->Kunci([[$hasil->IdProduk, $hasil->IdGudang]]);
        $tersedia = Kuantitas::Dari($saldo[SaldoStok::BuatKunciPasangan($hasil->IdProduk, $hasil->IdGudang)]->JumlahTersedia ?? '0');
        $utuh = $tersedia->Bandingkan($jumlah) >= 0;

        if ($utuh && $hasil->IdBatchStok !== null) {
            $batch = BatchStok::query()->whereKey($hasil->IdBatchStok)->lockForUpdate()->first();
            $utuh = $batch !== null && Kuantitas::Dari($batch->JumlahSisa)->Bandingkan($jumlah) >= 0;
        }

        if ($utuh && $metode === MetodeHpp::Fifo) {
            $lapisan = LapisanFifo::query()->where('IdMutasiSumber', $hasil->Id)->lockForUpdate()->first();
            $utuh = $lapisan === null || Kuantitas::Dari($lapisan->JumlahSisa)->SamaDengan(Kuantitas::Dari($lapisan->JumlahAwal));
        }

        if (! $utuh) {
            throw new PelanggaranAturanBisnis(
                'StokSudahTerpakai',
                "Hasil produksi {$nama} di {$namaGudang} sudah terpakai atau terjual (tersedia ".str_replace('.', ',', (string) $tersedia->KeDesimal()->strippedOfTrailingZeros()).'). Koreksi lewat penyesuaian stok.',
            );
        }
    }
}
