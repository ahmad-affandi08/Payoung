<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Integrasi\ApiPublik\Enum\StatusKirimanWebhook;
use App\Domain\Integrasi\ApiPublik\Model\KirimanWebhook;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tenant dengan kiriman webhook keluar yang gagal (percobaan habis) dalam [jam] terakhir (PGL-20 lanjutan). Menyebut nama
 * tenant, jadi dibaca lewat `JalankanLintasTenant` (diaudit) saat dasbor dibuka; yang dihitung hanya jumlah kiriman gagal,
 * tanpa muatan, alamat tujuan, atau kode respons penerima.
 */
final class WebhookGagalPerTenant
{
    public function __construct(private readonly KonteksPengelola $konteks) {}

    /**
     * @return list<array{NamaTenant: string, JumlahGagal: int, TerakhirPada: string|null}>
     */
    public function Ambil(int $jam = 24, int $batas = 10): array
    {
        return $this->konteks->JalankanLintasTenant('P-11: webhook keluar gagal per tenant', function (KonteksPengelola $k) use ($jam, $batas): array {
            $baris = $k->KueriLintas(KirimanWebhook::class)
                ->where('Status', StatusKirimanWebhook::Gagal->value)
                ->where('DiubahPada', '>=', now()->subHours($jam))
                ->selectRaw('IdTenant, COUNT(*) AS JumlahGagal, MAX(DiubahPada) AS TerakhirPada')
                ->groupBy('IdTenant')
                ->orderByDesc('JumlahGagal')
                ->limit(max(1, $batas))
                ->toBase()
                ->get();
            $nama = $baris->isEmpty() ? collect() : DB::table('Tenant')->whereIn('Id', $baris->pluck('IdTenant')->all())->pluck('Nama', 'Id');

            return array_values($baris->map(fn (object $b): array => [
                'NamaTenant' => (string) ($nama[$b->IdTenant] ?? '-'),
                'JumlahGagal' => (int) $b->JumlahGagal,
                'TerakhirPada' => $b->TerakhirPada === null ? null : Carbon::parse((string) $b->TerakhirPada)->toIso8601String(),
            ])->all());
        });
    }
}
