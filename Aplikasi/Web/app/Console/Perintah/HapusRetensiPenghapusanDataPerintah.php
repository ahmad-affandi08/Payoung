<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Aksi\HapusBerkasPembukuanKedaluwarsa;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use Illuminate\Console\Command;

/**
 * Bulanan menghapus lampiran pembukuan tenant yang akunnya sudah ditutup dan masa retensi pajaknya lewat (UU PDP, P-07).
 * Hanya tenant dengan permintaan penghapusan yang sudah melewati batas yang dikunjungi.
 */
final class HapusRetensiPenghapusanDataPerintah extends Command
{
    protected $signature = 'penghapusan-data:hapus-retensi';

    protected $description = 'Menghapus lampiran pembukuan tenant tertutup yang masa retensinya sudah lewat.';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, HapusBerkasPembukuanKedaluwarsa $hapus): int
    {
        $batas = now()->subYears((int) config('tenant.TahunRetensiPembukuan'));
        $sebelumnya = $konteks->Ambil();
        $jumlah = 0;

        try {
            $daftar = $pengelola->IdTenantDenganPekerjaan(
                PermintaanPenghapusanData::class,
                fn ($q) => $q->where('Status', StatusPermintaanPenghapusanData::Dilaksanakan->value)->whereNull('BerkasPembukuanDihapusPada')->where('DilaksanakanPada', '<=', $batas),
                termasukDitutup: true,
            );

            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);
                $jumlah += $hapus->Jalankan($idTenant);
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$jumlah} tenant tertutup dibersihkan dari lampiran pembukuan.");

        return self::SUCCESS;
    }
}
