<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Model\PenerimaLaporanWhatsapp;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Laporan\Aksi\KirimPenjualanPerJam;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Console\Command;
use Throwable;

/**
 * D-299 tahap 3: penjualan per jam ke penerima laporan WhatsApp. Hanya mengunjungi tenant yang punya penerima
 * (`IdTenantDenganPekerjaan`) dan hanya bila layanan WhatsApp aktif di konsol. Kegagalan satu tenant tidak
 * menghentikan tenant lain.
 */
final class KirimPenjualanPerJamPerintah extends Command
{
    protected $signature = 'whatsapp:kirim-penjualan-per-jam';

    protected $description = 'Mengirim penjualan per jam ke penerima laporan WhatsApp outlet (D-299).';

    public function handle(KetersediaanWhatsapp $ketersediaan, KonteksPengelola $pengelola, KonteksTenant $konteks, KirimPenjualanPerJam $kirim): int
    {
        if (! $ketersediaan->PlatformAktif()) {
            $this->line('Layanan WhatsApp belum aktif di konsol.');

            return self::SUCCESS;
        }

        $daftar = $pengelola->IdTenantDenganPekerjaan(PenerimaLaporanWhatsapp::class, static fn ($kueri) => $kueri);
        $sebelumnya = $konteks->Ambil();
        $terkirim = 0;
        $galat = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    if ($ketersediaan->TersediaUntuk($idTenant)) {
                        $terkirim += $kirim->Jalankan($idTenant);
                    }
                } catch (Throwable $e) {
                    $galat++;
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line(count($daftar)." tenant diperiksa, {$terkirim} laporan terkirim, {$galat} tenant gagal.");

        return $galat === 0 ? self::SUCCESS : self::FAILURE;
    }
}
