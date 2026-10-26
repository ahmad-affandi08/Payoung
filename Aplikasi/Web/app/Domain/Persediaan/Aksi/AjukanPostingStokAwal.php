<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\StokAwalDetail;
use App\Domain\Persediaan\Tugas\PostingStokAwalTugas;
use Illuminate\Support\Facades\DB;

/**
 * Pintu masuk HTTP posting stok awal (DesainF05a C.6.3). Jumlah baris mutasi (baris seri dihitung satu per nomor)
 * ≤ `persediaan.StokAwal.BatasPostingLangsung` → `PostingStokAwal` langsung (hasil Diposting). Lebih dari itu:
 * Draf → Memproses di satu transaksi (riwayat + audit `stok-awal.posting-diajukan`), lalu `PostingStokAwalTugas`
 * dikirim setelah commit (hasil Memproses; halaman memantau status). Kirim ganda aman: dokumen Memproses/Diposting
 * dikembalikan statusnya tanpa tugas kedua.
 */
final class AjukanPostingStokAwal
{
    public function __construct(
        private readonly PostingStokAwal $posting,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(StokAwal $stokAwal, int $idPengguna): StatusStokAwal
    {
        if (self::HitungBarisMutasi($stokAwal) <= (int) config('persediaan.StokAwal.BatasPostingLangsung', 300)) {
            return $this->posting->Jalankan($stokAwal, $idPengguna)->Status;
        }

        return DB::transaction(function () use ($stokAwal, $idPengguna): StatusStokAwal {
            $dokumen = StokAwal::query()->whereKey($stokAwal->Id)->lockForUpdate()->firstOrFail();

            if ($dokumen->Status === StatusStokAwal::Diposting || $dokumen->Status === StatusStokAwal::Memproses) {
                return $dokumen->Status;
            }

            if ($dokumen->Status !== StatusStokAwal::Draf) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Stok awal berstatus {$dokumen->Status->AmbilLabel()} tidak bisa dicatat.");
            }

            $dokumen->UbahStatus(StatusStokAwal::Memproses);
            $dokumen->PesanGalat = null;
            $dokumen->DiubahOleh = $idPengguna;
            $dokumen->save();

            $this->riwayat->Catat(StokAwal::JENIS_DOKUMEN, $dokumen->Id, StatusStokAwal::Draf->value, StatusStokAwal::Memproses->value, $idPengguna);
            $this->audit->Catat('stok-awal.posting-diajukan', $dokumen, ['Status' => StatusStokAwal::Draf->value], [
                'Status' => StatusStokAwal::Memproses->value,
                'JumlahBaris' => $dokumen->JumlahBaris,
            ], idPengguna: $idPengguna);

            PostingStokAwalTugas::dispatch($dokumen->IdTenant, $idPengguna, $dokumen->Id)->afterCommit();

            return StatusStokAwal::Memproses;
        });
    }

    private static function HitungBarisMutasi(StokAwal $stokAwal): int
    {
        $jumlah = 0;

        foreach (StokAwalDetail::query()->where('IdStokAwal', $stokAwal->Id)->pluck('DaftarNomorSeri') as $seri) {
            $jumlah += is_array($seri) && $seri !== [] ? count($seri) : 1;
        }

        return $jumlah;
    }
}
