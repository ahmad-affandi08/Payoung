<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Organisasi\Aksi\SiapkanGudangDalamPerjalanan;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Data\DataBarisDokumenStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PemeriksaBarisStok;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PencatatJurnalPersediaan;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PenomorDokumenPersediaan;
use App\Domain\Persediaan\Layanan\PenyusunJurnalPersediaan;
use App\Domain\Persediaan\Model\TransferStok;
use App\Domain\Persediaan\Model\TransferStokDetail;
use App\Domain\Persediaan\Tugas\BeritahuTransferStokMasukTugas;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mengirim transfer stok (F-05b, J-05.2): per baris mutasi `TransferKeluar` dari lokasi asal (dinilai HPP berjalan
 * asal) lalu `TransferMasuk` ke lokasi "Dalam perjalanan" outlet asal (`SiapkanGudangDalamPerjalanan`) sebesar nilai
 * yang keluar, batch/nomor seri ikut pindah. Jurnal Dr Persediaan Dalam Perjalanan / Cr Persediaan (kunci `Kirim`).
 * Semua dalam satu transaksi; idempoten (dokumen yang sudah dikirim dikembalikan apa adanya).
 *
 * Urutan kunci: L1 Tenant (S) → L2 dokumen → L3 SaldoStok semua pasangan (asal & dalam perjalanan) urut → nomor TF
 * (L7) → buku stok (reentran) → jurnal. Stok asal tidak cukup = `StokTidakCukup` (BR-05.2).
 */
final class KirimTransferStok
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturan,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly PemeriksaBarisStok $pemeriksaBaris,
        private readonly SiapkanGudangDalamPerjalanan $siapkanTransit,
        private readonly InfoGudang $infoGudang,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly PenomorDokumenPersediaan $penomor,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalPersediaan $penyusunJurnal,
        private readonly PencatatJurnalPersediaan $pencatatJurnal,
        private readonly CatatPemenuhanPermintaanStok $pemenuhanPermintaan,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(TransferStok $transfer, int $idPengguna): TransferStok
    {
        return DB::transaction(fn (): TransferStok => $this->Kirim($transfer->Id, $idPengguna), max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    private function Kirim(int $idTransfer, int $idPengguna): TransferStok
    {
        $this->pengaturan->AmbilDenganKunciBaca();
        $transfer = TransferStok::query()->whereKey($idTransfer)->lockForUpdate()->firstOrFail();

        if ($transfer->Status !== StatusTransferStok::Draf) {
            if ($transfer->Status === StatusTransferStok::Dibatalkan) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', 'Transfer yang dibatalkan tidak bisa dikirim.');
            }

            return $transfer;
        }

        $detail = $transfer->Detail()->orderBy('Urutan')->get();
        $asal = $this->pemeriksaLokasi->AmbilLokasi($transfer->IdGudangAsal, 'UuidGudangAsal');
        $tujuan = $this->pemeriksaLokasi->AmbilLokasi($transfer->IdGudangTujuan, 'UuidGudangTujuan');
        $tanggal = CarbonImmutable::parse($transfer->Tanggal->format('Y-m-d'));
        $this->pemeriksaLokasi->PastikanBukanMasaDepan($tanggal, $asal->idOutlet);
        $produk = $this->pemeriksaBaris->Periksa(self::KeDataBaris($detail), $asal->id, true);
        $transit = $this->siapkanTransit->Jalankan($asal->idOutlet);

        $pasangan = [];

        foreach ($detail as $d) {
            $pasangan[] = [$d->IdProduk, $asal->id];
            $pasangan[] = [$d->IdProduk, $transit->id];
        }

        $this->pengunciSaldo->Kunci($pasangan);
        $nomor = $this->penomor->AmbilTransfer($tanggal, $asal->kode, $tujuan->kode);

        $keluar = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::TransferStok,
            $transfer->Id,
            $transfer->Uuid,
            $nomor,
            $tanggal,
            $idPengguna,
            null,
            array_values($detail->map(fn (TransferStokDetail $d): DataBarisMutasi => new DataBarisMutasi(
                kunciBaris: 'K/'.$d->Id,
                idProduk: $d->IdProduk,
                idGudang: $asal->id,
                jenisMutasi: JenisMutasi::TransferKeluar,
                jumlah: Kuantitas::Dari($d->JumlahDikirim)->Negasi(),
                modeNilai: ModeNilaiMutasi::Berjalan,
                idReferensiDetail: $d->Id,
                idBatchStok: $d->IdBatchStok,
                idNomorSeri: $d->IdNomorSeri,
            ))->all()),
        ));

        $masuk = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::TransferStok,
            $transfer->Id,
            $transfer->Uuid,
            $nomor,
            $tanggal,
            $idPengguna,
            null,
            array_values($detail->map(function (TransferStokDetail $d) use ($keluar, $transit, $produk): DataBarisMutasi {
                $hasil = $keluar->baris['K/'.$d->Id];
                $pelacakan = $produk[$d->IdProduk]->pelacakan;

                return new DataBarisMutasi(
                    kunciBaris: 'KT/'.$d->Id,
                    idProduk: $d->IdProduk,
                    idGudang: $transit->id,
                    jenisMutasi: JenisMutasi::TransferMasuk,
                    jumlah: Kuantitas::Dari($d->JumlahDikirim),
                    modeNilai: ModeNilaiMutasi::Ditentukan,
                    nilai: AritmetikaHpp::AmbilMutlak($hasil->totalHpp),
                    hppSatuan: $hasil->hppSatuan,
                    idReferensiDetail: $d->Id,
                    batchMasuk: $pelacakan === PelacakanProduk::Batch ? new DataBatchMasuk((string) $d->NomorBatch, $d->TanggalKedaluwarsa === null ? null : CarbonImmutable::parse($d->TanggalKedaluwarsa->format('Y-m-d'))) : null,
                    nomorSeriMasuk: $pelacakan === PelacakanProduk::Seri ? $d->NomorSeri : null,
                );
            })->all()),
        ));

        $total = Uang::Nol();

        foreach ($detail as $d) {
            $nilai = AritmetikaHpp::AmbilMutlak($keluar->baris['K/'.$d->Id]->totalHpp);
            $total = $total->Tambah($nilai);
            $d->NilaiKirim = $nilai->KeString();
            $d->IdBatchStokTransit = $masuk->baris['KT/'.$d->Id]->idBatchStok;
            $d->save();
        }

        $gudang = $this->infoGudang->AmbilBanyak([$asal->id, $transit->id]);
        $barisJurnal = [];

        foreach ([...array_values($keluar->baris), ...array_values($masuk->baris)] as $hasil) {
            $barisJurnal[] = [$hasil, PeranAkun::SelisihHpp];
        }

        $jurnal = $this->pencatatJurnal->Posting(
            JenisSumberJurnal::TransferStok,
            $transfer->Id,
            $transfer->Uuid,
            $nomor,
            $tanggal,
            "Transfer {$nomor} dikirim dari {$asal->nama} ke {$tujuan->nama}",
            $this->penyusunJurnal->Susun($barisJurnal, $produk, $gudang, $asal->idOutlet),
            $idPengguna,
            'Kirim',
        );

        $transfer->UbahStatus(StatusTransferStok::Dikirim);
        $transfer->fill([
            'Nomor' => $nomor,
            'IdGudangTransit' => $transit->id,
            'TotalNilaiKirim' => $total->KeString(),
            'DikirimOleh' => $idPengguna,
            'DikirimPada' => now(),
            'DiubahOleh' => $idPengguna,
        ]);
        $transfer->save();

        $this->riwayat->Catat(TransferStok::JENIS_DOKUMEN, $transfer->Id, StatusTransferStok::Draf->value, StatusTransferStok::Dikirim->value, $idPengguna);
        $this->audit->Catat('transfer-stok.kirim', $transfer, ['Status' => StatusTransferStok::Draf->value], [
            'Status' => StatusTransferStok::Dikirim->value,
            'Nomor' => $nomor,
            'TotalNilaiKirim' => $transfer->TotalNilaiKirim,
            'NomorJurnal' => $jurnal?->nomor,
        ], idPengguna: $idPengguna);

        $this->pemenuhanPermintaan->Jalankan($transfer, $idPengguna);
        // Kabar non-kritis (aturan #10): antrean, setelah commit.
        BeritahuTransferStokMasukTugas::dispatch($transfer->IdTenant, $transfer->Id)->afterCommit();

        return $transfer;
    }

    /**
     * @param  Collection<int, TransferStokDetail>  $detail
     * @return list<DataBarisDokumenStok>
     */
    private static function KeDataBaris(Collection $detail): array
    {
        return array_values($detail->map(fn (TransferStokDetail $d): DataBarisDokumenStok => new DataBarisDokumenStok(
            idProduk: $d->IdProduk,
            jumlah: Kuantitas::Dari($d->JumlahDikirim),
            idBatchStok: $d->IdBatchStok,
            idNomorSeri: $d->IdNomorSeri,
        ))->all());
    }
}
