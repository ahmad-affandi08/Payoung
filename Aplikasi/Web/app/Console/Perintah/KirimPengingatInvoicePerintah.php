<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Aksi\KirimPengingatInvoiceOtomatis;
use Illuminate\Console\Command;
use Throwable;

/**
 * Putaran harian pengingat invoice otomatis (F-21): mengantrekan pesan WhatsApp berisi tautan invoice untuk invoice yang
 * akan jatuh tempo atau sudah lewat, bagi tenant yang mengaktifkan pengingat piutang otomatis.
 */
final class KirimPengingatInvoicePerintah extends Command
{
    protected $signature = 'invoice:kirim-pengingat {--tenant=* : Id tenant (kosong = semua)}';

    protected $description = 'Mengantrekan pengingat invoice jatuh tempo ke pelanggan lewat WhatsApp (F-21).';

    public function handle(KeanggotaanPengguna $keanggotaan, KonteksTenant $konteks, TanggalBisnisOutlet $tanggalBisnis, KirimPengingatInvoiceOtomatis $kirim): int
    {
        $diminta = array_values(array_filter(array_map(
            fn (mixed $nilai): int => is_scalar($nilai) ? (int) $nilai : 0,
            (array) $this->option('tenant'),
        ), fn (int $id): bool => $id > 0));
        $semua = $keanggotaan->AmbilSemuaIdTenant();
        $daftar = $diminta === [] ? $semua : array_values(array_intersect(array_unique($diminta), $semua));
        $sebelumnya = $konteks->Ambil();
        $antre = 0;
        $galat = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    $antre += $kirim->Jalankan($idTenant, $tanggalBisnis->Hitung(null));
                } catch (Throwable $e) {
                    $galat++;
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line(count($daftar)." tenant diperiksa, {$antre} pengingat invoice diantrekan, {$galat} tenant gagal.");

        return $galat === 0 ? self::SUCCESS : self::FAILURE;
    }
}
