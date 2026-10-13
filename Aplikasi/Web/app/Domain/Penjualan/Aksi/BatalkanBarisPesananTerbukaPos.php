<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pemenuhan\Aksi\BatalkanBarisTiketDapur;
use App\Domain\Penjualan\Data\DataPesananTerbukaPos;
use App\Domain\Penjualan\Enum\StatusBarisPesanan;
use App\Domain\Penjualan\Layanan\PencatatTerbuangVoidItem;
use App\Domain\Penjualan\Layanan\PenjagaPesananTerbuka;
use App\Domain\Penjualan\Model\PesananTerbukaDetail;
use Illuminate\Support\Facades\DB;

/**
 * Item outbox `PesananTerbuka.BatalkanBaris` (F-07 mode meja fase 1, BR-07.5): membatalkan baris pesanan terbuka
 * (tidak dihapus). Baris yang sudah dikirim ke dapur = **void item**: alasan wajib, butuh izin `penjualan.void` pada
 * pelaku atau penyetuju, dicatat di log audit `pesanan-terbuka.void-item` (dasar laporan void), dan baris tiket dapurnya
 * ditandai dibatalkan. Semua baris sudah batal → `Duplikat`. Opsi "waste" (v5.78): `CatatTerbuang` mencatat void item sebagai
 * bahan terbuang (stok bahan sudah terpakai karena item sudah dibuat), lihat `PencatatTerbuangVoidItem`.
 */
final class BatalkanBarisPesananTerbukaPos
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenjagaPesananTerbuka $penjaga,
        private readonly BatalkanBarisTiketDapur $batalkanTiket,
        private readonly PencatatAudit $audit,
        private readonly PencatatTerbuangVoidItem $terbuang,
    ) {}

    public function Jalankan(DataPesananTerbukaPos $data): StatusItemSinkron
    {
        $this->penjaga->PastikanWaktuWajar($data->waktu, 'DibatalkanPada');

        return DB::transaction(function () use ($data): StatusItemSinkron {
            $idTenant = $this->konteks->Wajib();
            $pesanan = $this->penjaga->CariUntukDiubah($data->uuidPesanan, $data->idOutlet);
            $baris = PesananTerbukaDetail::query()->where('IdPesananTerbuka', $pesanan->Id)->whereIn('Uuid', $data->uuidBaris)->lockForUpdate()->get();

            if ($baris->count() !== count(array_unique($data->uuidBaris))) {
                throw new PelanggaranAturanBisnis('BarisTidakDitemukan', 'Sebagian item yang dibatalkan tidak ada di pesanan ini.', 'UuidBaris', 404);
            }

            $aktif = $baris->filter(fn (PesananTerbukaDetail $b): bool => $b->Status === StatusBarisPesanan::Aktif);

            if ($aktif->isEmpty()) {
                return StatusItemSinkron::Duplikat;
            }

            $this->penjaga->PastikanTerbuka($pesanan);
            $pelaku = $this->penjaga->CariPelaku($idTenant, $data->uuidPengguna, $data->idOutlet);
            $terkirim = $aktif->filter(fn (PesananTerbukaDetail $b): bool => $b->DikirimKeDapurPada !== null);
            $penyetuju = null;

            if ($terkirim->isNotEmpty()) {
                if ($data->alasan === null || mb_strlen(trim($data->alasan)) < 3) {
                    throw new PelanggaranAturanBisnis('AlasanWajib', 'Tulis alasan pembatalan item yang sudah dikirim ke dapur.', 'Alasan');
                }

                $penyetuju = $this->penjaga->CariPenyetujuVoid($idTenant, $pelaku, $data->uuidPenyetuju, $data->idOutlet);
            }

            foreach ($aktif as $b) {
                $b->update([
                    'Status' => StatusBarisPesanan::Dibatalkan,
                    'DibatalkanPada' => $data->waktu,
                    'AlasanBatal' => $data->alasan,
                    'IdPembatal' => $pelaku->id,
                    'IdPenyetujuBatal' => $b->DikirimKeDapurPada !== null ? $penyetuju?->id : null,
                ]);
            }

            if ($terkirim->isNotEmpty()) {
                $this->batalkanTiket->Jalankan(array_values($terkirim->pluck('Uuid')->all()));
                $dilewati = [];
                $dicatat = [];

                if ($data->catatTerbuang) {
                    foreach ($terkirim as $b) {
                        $catatan = $this->terbuang->Catat($b, $data->idOutlet, $data->idPerangkat, $pelaku->id, $data->waktu, $data->alasan);
                        $catatan === null ? $dicatat[] = $b->NamaProduk : $dilewati[] = ['Nama' => $b->NamaProduk, 'Alasan' => $catatan];
                    }
                }

                $this->audit->Catat('pesanan-terbuka.void-item', $pesanan, nilaiBaru: [
                    'Nomor' => $pesanan->Nomor,
                    'Item' => $terkirim->map(fn (PesananTerbukaDetail $b): array => ['Nama' => $b->NamaProduk, 'Jumlah' => (string) $b->Jumlah])->values()->all(),
                    'Alasan' => $data->alasan,
                    'IdPenyetuju' => $penyetuju?->id,
                    'CatatTerbuang' => $data->catatTerbuang,
                    'TerbuangDicatat' => $dicatat,
                    'TerbuangDilewati' => $dilewati,
                ], idPengguna: $pelaku->id);
            }

            $pesanan->touch();

            return StatusItemSinkron::Diterima;
        });
    }
}
