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
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PencatatJurnalPersediaan;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PenyusunJurnalPersediaan;
use App\Domain\Persediaan\Model\TransferStok;
use App\Domain\Persediaan\Model\TransferStokDetail;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Illuminate\Support\Facades\DB;

/**
 * Menutup transfer yang belum diterima seluruhnya (F-05b): selisih kirim vs terima wajib beralasan dan dicatat
 * sebagai susut dari lokasi dalam perjalanan (mutasi `Susut` sebesar sisa nilai kirim; J-05.4 Dr Susut & Barang
 * Rusak / Cr Persediaan Dalam Perjalanan, kunci `Tutup`), lalu status Diterima. Tanggal = tanggal bisnis outlet tujuan.
 */
final class TutupTransferStok
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturan,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalPersediaan $penyusunJurnal,
        private readonly PencatatJurnalPersediaan $pencatatJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(TransferStok $transfer, string $alasan, int $idPengguna): TransferStok
    {
        return DB::transaction(function () use ($transfer, $alasan, $idPengguna): TransferStok {
            $this->pengaturan->AmbilDenganKunciBaca();
            $transfer = TransferStok::query()->whereKey($transfer->Id)->lockForUpdate()->firstOrFail();

            if (! $transfer->Status->CekDalamPerjalanan()) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Transfer berstatus {$transfer->Status->AmbilLabel()} tidak bisa ditutup.");
            }

            $alasan = trim($alasan);

            if (mb_strlen($alasan) < 5) {
                throw new PelanggaranAturanBisnis('AlasanWajib', 'Tulis alasan selisih kirim dan terima (minimal 5 karakter).', 'Alasan');
            }

            $idTransit = (int) $transfer->IdGudangTransit;
            $tanggal = $this->pemeriksaLokasi->HariIni($transfer->IdOutletTujuan);
            $detail = $transfer->Detail()->orderBy('Urutan')->get()->filter(fn (TransferStokDetail $d): bool => ! AritmetikaHpp::CekNol($d->AmbilSisa()))->values();
            $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);
            $this->pengunciSaldo->Kunci(array_values($detail->map(fn (TransferStokDetail $d): array => [$d->IdProduk, $idTransit])->all()));
            $totalSusut = Uang::Nol();
            $jurnal = null;

            if ($detail->isNotEmpty()) {
                $hasil = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
                    JenisReferensiMutasi::TransferStok,
                    $transfer->Id,
                    $transfer->Uuid,
                    $transfer->Nomor,
                    $tanggal,
                    $idPengguna,
                    null,
                    array_values($detail->map(fn (TransferStokDetail $d): DataBarisMutasi => new DataBarisMutasi(
                        kunciBaris: 'S/'.$d->Id,
                        idProduk: $d->IdProduk,
                        idGudang: $idTransit,
                        jenisMutasi: JenisMutasi::Susut,
                        jumlah: $d->AmbilSisa()->Negasi(),
                        modeNilai: ModeNilaiMutasi::Ditentukan,
                        nilai: $d->AmbilSisaNilai(),
                        idReferensiDetail: $d->Id,
                        idBatchStok: $d->IdBatchStokTransit,
                        idNomorSeri: $d->IdNomorSeri,
                    ))->all()),
                ));

                foreach ($detail as $d) {
                    $nilai = AritmetikaHpp::AmbilMutlak($hasil->baris['S/'.$d->Id]->totalHpp);
                    $d->JumlahSusut = $d->AmbilSisa()->Tambah(Kuantitas::Dari($d->JumlahSusut))->KeString();
                    $d->NilaiSusut = Uang::Dari($d->NilaiSusut)->Tambah($nilai)->KeString();
                    $d->save();
                    $totalSusut = $totalSusut->Tambah($nilai);
                }

                $jurnal = $this->pencatatJurnal->Posting(
                    JenisSumberJurnal::TransferStok,
                    $transfer->Id,
                    $transfer->Uuid,
                    (string) $transfer->Nomor,
                    $tanggal,
                    "Selisih transfer {$transfer->Nomor}: {$alasan}",
                    $this->penyusunJurnal->Susun(
                        array_map(fn ($h): array => [$h, PeranAkun::SusutPersediaan], array_values($hasil->baris)),
                        $produk,
                        $this->infoGudang->AmbilBanyak([$idTransit]),
                        $transfer->IdOutletAsal,
                    ),
                    $idPengguna,
                    'Tutup',
                );
            }

            $statusAwal = $transfer->Status;
            $transfer->UbahStatus(StatusTransferStok::Diterima);
            $transfer->fill([
                'AlasanSelisih' => mb_substr($alasan, 0, 255),
                'TotalNilaiSusut' => Uang::Dari($transfer->TotalNilaiSusut)->Tambah($totalSusut)->KeString(),
                'DitutupOleh' => $idPengguna,
                'DitutupPada' => now(),
                'DiubahOleh' => $idPengguna,
            ]);
            $transfer->save();

            $this->riwayat->Catat(TransferStok::JENIS_DOKUMEN, $transfer->Id, $statusAwal->value, StatusTransferStok::Diterima->value, $idPengguna, mb_substr($alasan, 0, 255));
            $this->audit->Catat('transfer-stok.tutup', $transfer, ['Status' => $statusAwal->value], [
                'Status' => StatusTransferStok::Diterima->value,
                'AlasanSelisih' => $transfer->AlasanSelisih,
                'TotalNilaiSusut' => $transfer->TotalNilaiSusut,
                'NomorJurnal' => $jurnal?->nomor,
            ], idPengguna: $idPengguna);

            return $transfer;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
