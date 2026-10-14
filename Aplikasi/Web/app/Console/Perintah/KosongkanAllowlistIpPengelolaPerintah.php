<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Pengelola\TimInternal\Layanan\AllowlistIpPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Tenant\Model\PengaturanPlatform;
use Illuminate\Console\Command;

/**
 * Jalan pemulihan PGL-01 (D-146): mematikan pembatasan IP konsol dari server bila seluruh tim terkunci (misal IP kantor
 * berganti). Hanya bisa dijalankan orang yang punya akses server; tercatat di audit pengelola tanpa pelaku.
 */
final class KosongkanAllowlistIpPengelolaPerintah extends Command
{
    protected $signature = 'pengelola:kosongkan-allowlist-ip';

    protected $description = 'Mematikan pembatasan alamat IP konsol Platform Pengelola (pemulihan bila terkunci).';

    public function handle(AllowlistIpPengelola $allowlist, PencatatAuditPengelola $audit): int
    {
        $lama = $allowlist->Ambil();

        if ($lama === []) {
            $this->info('Pembatasan IP memang sedang mati.');

            return self::SUCCESS;
        }

        PengaturanPlatform::query()->where('Kunci', AllowlistIpPengelola::KUNCI)->delete();
        $audit->Catat('tim.batasan-ip.kosongkan-server', null, nilaiLama: ['Daftar' => $lama], nilaiBaru: ['Daftar' => []], alasan: 'Dimatikan dari server (perintah pemulihan).');
        $this->info('Pembatasan IP dimatikan. Daftar lama: '.implode(', ', $lama));

        return self::SUCCESS;
    }
}
