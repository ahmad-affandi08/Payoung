<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Persediaan\Data\DataBarisDokumenStok;
use App\Domain\Persediaan\Data\DataTransferStok;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Layanan\PemeriksaBarisStok;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PenjagaVersiDokumen;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\PermintaanStok;
use App\Domain\Persediaan\Model\TransferStok;
use App\Domain\Persediaan\Model\TransferStokDetail;
use Illuminate\Support\Facades\DB;

/**
 * Membuat atau mengubah draf transfer stok (F-05b). Buat idempoten per Uuid klien. Ubah hanya untuk Draf
 * (`StatusTidakSesuai`) dengan versi optimistis (`DokumenBerubah`). Lokasi asal ≠ tujuan, keduanya aktif dan bukan
 * lokasi dalam perjalanan; tanggal tidak di masa depan; baris diperiksa `PemeriksaBarisStok` terhadap lokasi asal
 * (batch/nomor seri asal wajib ada di sana). Draf belum mengubah stok. Audit `transfer-stok.buat`/`transfer-stok.ubah`.
 * D-105: draf baru boleh menjawab permintaan stok terbuka (`uuidPermintaanStok`): lokasi tujuannya sama dengan lokasi
 * yang meminta dan outlet asalnya sama dengan outlet yang diminta; baru terhitung terpenuhi saat transfer dikirim.
 */
final class SimpanTransferStok
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaBarisStok $pemeriksaBaris,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataTransferStok $data, ?TransferStok $ada): TransferStok
    {
        if ($ada === null && $data->uuid !== null) {
            $lama = TransferStok::query()->where('Uuid', $data->uuid)->first();

            if ($lama !== null) {
                return $lama;
            }
        }

        return DB::transaction(function () use ($data, $ada): TransferStok {
            $oleh = $this->audit->AmbilIdPengguna();

            if ($ada !== null) {
                $transfer = TransferStok::query()->whereKey($ada->Id)->lockForUpdate()->firstOrFail();

                if ($transfer->Status !== StatusTransferStok::Draf) {
                    throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Transfer berstatus {$transfer->Status->AmbilLabel()} tidak bisa diubah. Hanya draf yang bisa diubah.");
                }

                PenjagaVersiDokumen::Pastikan($data->versiDiubahPada, $transfer->DiubahPada);
            } else {
                $transfer = new TransferStok;

                if ($data->uuid !== null) {
                    $transfer->Uuid = $data->uuid;
                }
            }

            $asal = $this->pemeriksaLokasi->AmbilLokasi($data->idGudangAsal, 'UuidGudangAsal');
            $tujuan = $this->pemeriksaLokasi->AmbilLokasi($data->idGudangTujuan, 'UuidGudangTujuan');

            if ($asal->id === $tujuan->id) {
                throw new PelanggaranAturanBisnis('LokasiSama', 'Lokasi tujuan harus berbeda dari lokasi asal.', 'UuidGudangTujuan');
            }

            $this->pemeriksaLokasi->PastikanBukanMasaDepan($data->tanggal, $asal->idOutlet);
            $produk = $this->pemeriksaBaris->Periksa($data->baris, $asal->id, true);
            $idPermintaan = $ada === null ? $this->PeriksaPermintaan($data->uuidPermintaanStok, $asal->idOutlet, $tujuan->id) : $transfer->IdPermintaanStok;
            $lama = $ada === null ? null : ['JumlahBaris' => $transfer->JumlahBaris, 'IdGudangAsal' => $transfer->IdGudangAsal, 'IdGudangTujuan' => $transfer->IdGudangTujuan];
            $catatan = $data->catatan === null ? null : trim($data->catatan);

            $transfer->fill([
                'IdPermintaanStok' => $idPermintaan,
                'IdGudangAsal' => $asal->id,
                'IdOutletAsal' => $asal->idOutlet,
                'IdGudangTujuan' => $tujuan->id,
                'IdOutletTujuan' => $tujuan->idOutlet,
                'Tanggal' => $data->tanggal->format('Y-m-d'),
                'Catatan' => $catatan === '' ? null : $catatan,
                'JumlahBaris' => count($data->baris),
                'DiubahOleh' => $oleh,
            ]);

            if ($ada === null) {
                $transfer->DibuatOleh = $oleh;
            }

            $transfer->DiubahPada = now();
            $transfer->save();

            if ($ada !== null) {
                TransferStokDetail::query()->where('IdTransferStok', $transfer->Id)->get()->each(fn (TransferStokDetail $d) => $d->delete());
            }

            $this->SimpanBaris($transfer, $data->baris, $produk);
            $ringkas = ['IdGudangAsal' => $asal->id, 'NamaGudangAsal' => $asal->nama, 'IdGudangTujuan' => $tujuan->id, 'NamaGudangTujuan' => $tujuan->nama, 'JumlahBaris' => $transfer->JumlahBaris];

            if ($ada === null) {
                $this->riwayat->Catat(TransferStok::JENIS_DOKUMEN, $transfer->Id, null, StatusTransferStok::Draf->value, $oleh);
                $this->audit->Catat('transfer-stok.buat', $transfer, null, $ringkas);
            } else {
                $this->audit->Catat('transfer-stok.ubah', $transfer, $lama, $ringkas);
            }

            return $transfer;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    /** D-105: permintaan harus terbuka, meminta ke outlet asal ini, dan untuk lokasi tujuan yang sama. */
    private function PeriksaPermintaan(?string $uuid, ?int $idOutletAsal, int $idGudangTujuan): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $permintaan = PermintaanStok::query()->where('Uuid', strtoupper($uuid))->first()
            ?? throw new PelanggaranAturanBisnis('PermintaanTidakDikenal', 'Permintaan stok tidak ditemukan.', 'UuidPermintaanStok');

        if (! $permintaan->Status->CekTerbuka()) {
            throw new PelanggaranAturanBisnis('PermintaanSudahBerakhir', "Permintaan {$permintaan->Nomor} berstatus {$permintaan->Status->AmbilLabel()} dan tidak menerima transfer lagi.", 'UuidPermintaanStok');
        }

        if ($permintaan->IdOutletAsal !== $idOutletAsal || $permintaan->IdGudangTujuan !== $idGudangTujuan) {
            throw new PelanggaranAturanBisnis('PermintaanTidakCocok', "Transfer ini harus dikirim dari outlet yang diminta dan ke lokasi yang meminta di permintaan {$permintaan->Nomor}.", 'UuidPermintaanStok');
        }

        return $permintaan->Id;
    }

    /**
     * @param  list<DataBarisDokumenStok>  $baris
     * @param  array<int, DataInfoProdukStok>  $produk
     */
    private function SimpanBaris(TransferStok $transfer, array $baris, array $produk): void
    {
        $idTenant = $this->konteks->Wajib();
        $batch = BatchStok::query()->whereIn('Id', array_values(array_filter(array_map(fn (DataBarisDokumenStok $b): ?int => $b->idBatchStok, $baris))))->get()->keyBy('Id');
        $seri = NomorSeri::query()->whereIn('Id', array_values(array_filter(array_map(fn (DataBarisDokumenStok $b): ?int => $b->idNomorSeri, $baris))))->get()->keyBy('Id');
        $waktu = now();
        $isi = [];

        foreach ($baris as $i => $b) {
            $info = $produk[$b->idProduk];
            $batchAsal = $b->idBatchStok === null ? null : $batch->get($b->idBatchStok);
            $isi[] = [
                'IdTenant' => $idTenant,
                'IdTransferStok' => $transfer->Id,
                'Urutan' => $i + 1,
                'IdProduk' => $b->idProduk,
                'NamaProduk' => mb_substr($info->nama, 0, 150),
                'Sku' => $info->sku,
                'JumlahDikirim' => $b->jumlah->KeString(),
                'IdBatchStok' => $batchAsal?->Id,
                'NomorBatch' => $batchAsal?->NomorBatch,
                'TanggalKedaluwarsa' => $batchAsal?->TanggalKedaluwarsa?->format('Y-m-d'),
                'IdNomorSeri' => $b->idNomorSeri,
                'NomorSeri' => $b->idNomorSeri === null ? null : $seri->get($b->idNomorSeri)?->Nomor,
                'DibuatPada' => $waktu,
                'DiubahPada' => $waktu,
            ];
        }

        TransferStokDetail::query()->insert($isi);
    }
}
