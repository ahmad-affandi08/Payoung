<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Aksi\SegarkanStatusNomorPengirimWhatsapp;
use App\Domain\Integrasi\Enum\StatusNomorWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Console\Command;
use Throwable;

/**
 * D-299: memeriksa sambungan nomor pengirim WhatsApp yang sedang terhubung. Nomor yang terputus ditandai dan
 * dimatikan (muncul di Kotak Tindakan pemilik). Hanya mengunjungi tenant yang punya nomor terhubung. Kegagalan satu
 * tenant tidak menghentikan tenant lain.
 */
final class PeriksaSambunganWhatsappPerintah extends Command
{
    protected $signature = 'whatsapp:periksa-sambungan';

    protected $description = 'Memeriksa sambungan nomor pengirim WhatsApp tenant (D-299).';

    public function handle(KetersediaanWhatsapp $ketersediaan, KonteksPengelola $pengelola, KonteksTenant $konteks, SegarkanStatusNomorPengirimWhatsapp $segarkan): int
    {
        if (! $ketersediaan->PlatformAktif()) {
            $this->line('Layanan WhatsApp belum aktif di konsol.');

            return self::SUCCESS;
        }

        $daftar = $pengelola->IdTenantDenganPekerjaan(NomorPengirimWhatsapp::class, static fn ($kueri) => $kueri->where('Status', StatusNomorWhatsapp::Terhubung->value));
        $sebelumnya = $konteks->Ambil();
        $diperiksa = 0;
        $galat = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    foreach (NomorPengirimWhatsapp::query()->where('Status', StatusNomorWhatsapp::Terhubung->value)->get() as $nomor) {
                        $segarkan->Jalankan($nomor);
                        $diperiksa++;
                    }
                } catch (Throwable $e) {
                    $galat++;
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$diperiksa} nomor diperiksa di ".count($daftar)." tenant, {$galat} tenant gagal.");

        return $galat === 0 ? self::SUCCESS : self::FAILURE;
    }
}
