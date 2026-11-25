<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Penjualan\Aksi\CekStatusTagihanQrisPos;
use App\Domain\Penjualan\Aksi\RekonsiliasiTagihanQris;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
use App\Domain\Penjualan\Model\TagihanQris;
use Illuminate\Console\Command;

/**
 * Audit P0 F-02: tiap 10 menit menyelesaikan tagihan QRIS dinamis yang hasil pembuatannya di gerbang tidak pasti.
 * Per tenant dengan `KonteksTenant` diatur sehingga semua kueri tetap lewat scope `MilikTenant`.
 */
final class RekonsiliasiTagihanQrisPerintah extends Command
{
    protected $signature = 'penjualan:rekonsiliasi-qris';

    protected $description = 'Merekonsiliasi tagihan QRIS dinamis berstatus TidakPasti dengan gerbang pembayaran (audit P0 F-02).';

    public function handle(KonteksPengelola $pengelola, KonteksTenant $konteks, RekonsiliasiTagihanQris $rekonsiliasi): int
    {
        $sebelumnya = $konteks->Ambil();
        $berubah = 0;

        try {
            // Hanya tenant yang punya tagihan tidak pasti atau menunggu yang sudah lewat batas (audit kinerja skala besar).
            $batas = now()->subMinutes(CekStatusTagihanQrisPos::MENIT_TENGGANG);

            foreach ($pengelola->IdTenantDenganPekerjaan(TagihanQris::class, fn ($q) => $q->where(fn ($w) => $w->where('Status', StatusTagihanQris::TidakPasti->value)->orWhere(fn ($m) => $m->where('Status', StatusTagihanQris::Menunggu->value)->where('KedaluwarsaPada', '<', $batas))->orWhere(fn ($l) => $l->where('Status', StatusTagihanQris::Lunas->value)->where('Sumber', SumberTagihanQris::Pos->value)->whereNull('UuidPenjualan')->where('PerluTinjauan', false)->whereNull('DitutupPada')->where('LunasPada', '<=', now()->subMinutes(RekonsiliasiTagihanQris::MENIT_TUNGGU_PENJUALAN))))) as $idTenant) {
                $konteks->Atur($idTenant);
                $berubah += $rekonsiliasi->Jalankan();
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line("{$berubah} tagihan QRIS tidak pasti diselesaikan.");

        return self::SUCCESS;
    }
}
