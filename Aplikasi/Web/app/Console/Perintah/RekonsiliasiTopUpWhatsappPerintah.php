<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Aksi\SegarkanTopUpSaldoWhatsapp;
use App\Domain\Integrasi\Enum\StatusTopUpSaldoWhatsapp;
use App\Domain\Integrasi\Model\TopUpSaldoWhatsapp;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Console\Command;
use Throwable;

/**
 * D-300: jalur cadangan webhook untuk isi saldo WhatsApp. Top-up yang masih Menunggu ditanyakan statusnya ke gerbang,
 * dan yang lewat 24 jam tanpa lunas ditutup. Hanya mengunjungi tenant yang punya top-up menunggu. Kegagalan satu
 * tenant tidak menghentikan tenant lain.
 */
final class RekonsiliasiTopUpWhatsappPerintah extends Command
{
    protected $signature = 'whatsapp:rekonsiliasi-topup';

    protected $description = 'Menanyakan status isi saldo WhatsApp yang belum mendapat notifikasi (D-300).';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, SegarkanTopUpSaldoWhatsapp $segarkan): int
    {
        $daftar = $pengelola->IdTenantDenganPekerjaan(TopUpSaldoWhatsapp::class, static fn ($kueri) => $kueri->where('Status', StatusTopUpSaldoWhatsapp::Menunggu->value));
        $sebelumnya = $konteks->Ambil();
        $berubah = 0;
        $galat = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    foreach (TopUpSaldoWhatsapp::query()->where('Status', StatusTopUpSaldoWhatsapp::Menunggu->value)->orderBy('Id')->limit(50)->get() as $topUp) {
                        $berubah += $segarkan->Jalankan($topUp) ? 1 : 0;
                    }
                } catch (Throwable $e) {
                    $galat++;
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$berubah} isi saldo berubah status di ".count($daftar)." tenant, {$galat} tenant gagal.");

        return $galat === 0 ? self::SUCCESS : self::FAILURE;
    }
}
