<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Layanan\PengukurPenyimpanan;
use App\Domain\Tenant\Model\PemakaianPenyimpanan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Console\Command;

/**
 * Mengukur ulang pemakaian penyimpanan berkas tenant (audit E2). Malam hari hanya tenant yang sudah punya baris
 * `PemakaianPenyimpanan` (pernah mengunggah berkas yang dibatasi paket) yang diukur; `--semua` mengukur setiap tenant,
 * dijalankan sekali setelah rilis ini supaya berkas lama ikut terhitung.
 */
final class UkurPenyimpananTenantPerintah extends Command
{
    protected $signature = 'penyimpanan:ukur {--semua : Ukur semua tenant, bukan hanya yang sudah punya catatan} {--halaman=5000 : Jumlah tenant per halaman baca (untuk uji)}';

    protected $description = 'Mengukur ulang pemakaian penyimpanan berkas per tenant untuk batas paket.';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, PengukurPenyimpanan $pengukur): int
    {
        $sebelumnya = $konteks->Ambil();
        $diukur = 0;
        $ukuranHalaman = max(1, (int) $this->option('halaman'));

        try {
            if ($this->option('semua')) {
                $daftar = array_values(array_map('intval', Tenant::query()->where('Status', '<>', StatusTenant::Ditutup->value)->orderBy('Id')->pluck('Id')->all()));
                $diukur = $this->UkurSemua($daftar, $konteks, $pengukur);
            } else {
                // Baris `PemakaianPenyimpanan` tidak pernah hilang setelah diukur, jadi hasil yang dipotong batas halaman
                // akan selalu berisi tenant yang sama dan tenant di ujung urutan tidak pernah diukur ulang. Halaman
                // dibaca berantai (id berikutnya) sampai habis.
                $terakhir = 0;

                do {
                    $halaman = $pengelola->IdTenantDenganPekerjaan(PemakaianPenyimpanan::class, fn ($q) => $q, $ukuranHalaman, setelahIdTenant: $terakhir);
                    $diukur += $this->UkurSemua($halaman, $konteks, $pengukur);
                    $terakhir = $halaman === [] ? $terakhir : max($halaman);
                } while (count($halaman) >= $ukuranHalaman);
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$diukur} tenant diukur penyimpanannya.");

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $daftar
     */
    private function UkurSemua(array $daftar, KonteksTenant $konteks, PengukurPenyimpanan $pengukur): int
    {
        foreach ($daftar as $idTenant) {
            $konteks->Atur($idTenant);
            $pengukur->Segarkan($idTenant);
        }

        return count($daftar);
    }
}
