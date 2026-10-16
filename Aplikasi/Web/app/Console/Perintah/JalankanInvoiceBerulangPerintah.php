<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Aksi\JalankanInvoiceBerulang;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use Illuminate\Console\Command;
use Throwable;

/**
 * Putaran harian invoice berulang (F-21): menerbitkan invoice dari templat aktif yang jadwalnya sudah tiba. Idempoten:
 * aman dijalankan ulang kapan saja, siklus yang sudah terbit tidak terbit lagi.
 */
final class JalankanInvoiceBerulangPerintah extends Command
{
    protected $signature = 'invoice:jalankan-berulang {--tenant=* : Id tenant (kosong = semua)}';

    protected $description = 'Menerbitkan invoice dari templat invoice berulang yang jatuh tempo (F-21).';

    public function handle(KeanggotaanPengguna $keanggotaan, KonteksTenant $konteks, TanggalBisnisOutlet $tanggalBisnis, JalankanInvoiceBerulang $jalankan): int
    {
        $diminta = array_values(array_filter(array_map(
            fn (mixed $nilai): int => is_scalar($nilai) ? (int) $nilai : 0,
            (array) $this->option('tenant'),
        ), fn (int $id): bool => $id > 0));
        $semua = $keanggotaan->AmbilSemuaIdTenant();
        $daftar = $diminta === [] ? $semua : array_values(array_intersect(array_unique($diminta), $semua));
        $sebelumnya = $konteks->Ambil();
        $dibuat = 0;
        $galat = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    $hariIni = $tanggalBisnis->Hitung(null);
                    $uuid = InvoiceBerulang::query()
                        ->where('Status', StatusInvoiceBerulang::Aktif->value)
                        ->whereNotNull('BerikutnyaPada')
                        ->whereDate('BerikutnyaPada', '<=', $hariIni->toDateString())
                        ->orderBy('Id')
                        ->pluck('Uuid');

                    foreach ($uuid as $u) {
                        $dibuat += $jalankan->Jalankan($idTenant, (string) $u, $hariIni);
                    }
                } catch (Throwable $e) {
                    $galat++;
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line(count($daftar)." tenant diperiksa, {$dibuat} invoice berulang diterbitkan, {$galat} tenant gagal.");

        return $galat === 0 ? self::SUCCESS : self::FAILURE;
    }
}
