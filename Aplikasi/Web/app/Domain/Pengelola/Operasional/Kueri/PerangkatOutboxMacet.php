<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Perangkat POS yang outbox-nya tidak kosong lebih lama dari `operasional.BatasOutboxMacetJam` (P-11, PGL-20): transaksi
 * sudah terjadi di kasir tetapi belum sampai ke server. Jumlah tenant untuk alert memakai `IdTenantDenganPekerjaan`
 * (tanpa baris audit, dipanggil tiap menit); daftar rinci memuat nama tenant, outlet, dan perangkat sehingga dibaca lewat
 * `JalankanLintasTenant` (diaudit) saat dasbor dibuka.
 */
final class PerangkatOutboxMacet
{
    public function __construct(private readonly KonteksPengelola $konteks) {}

    /** Jumlah tenant yang punya perangkat dengan outbox macet. */
    public function HitungTenant(): int
    {
        return count($this->konteks->IdTenantDenganPekerjaan(Perangkat::class, fn (Builder $kueri) => $this->Saring($kueri)));
    }

    /**
     * @return list<array{Uuid: string, NamaTenant: string, NamaOutlet: string, NamaPerangkat: string, JumlahOutbox: int, TertundaSejak: string|null, TerakhirAktifPada: string|null, VersiAplikasi: string|null}>
     */
    public function AmbilDaftar(int $batas = 50): array
    {
        return $this->konteks->JalankanLintasTenant('P-11: perangkat dengan outbox macet', function (KonteksPengelola $k) use ($batas): array {
            $baris = $this->Saring($k->KueriLintas(Perangkat::class))
                ->orderBy('OutboxTertundaSejak')
                ->limit($batas)
                ->get();
            // Nama tenant & outlet dibaca langsung: relasi Eloquent outlet masih terkena scope tenant.
            $namaTenant = $baris->isEmpty()
                ? collect()
                : DB::table('Tenant')->whereIn('Id', $baris->pluck('IdTenant')->unique()->all())->pluck('Nama', 'Id');
            $namaOutlet = $baris->isEmpty()
                ? collect()
                : DB::table('Outlet')->whereIn('Id', $baris->pluck('IdOutlet')->unique()->all())->pluck('Nama', 'Id');

            return array_values($baris->map(fn (Perangkat $p): array => [
                'Uuid' => $p->Uuid,
                'NamaTenant' => (string) ($namaTenant[$p->IdTenant] ?? '-'),
                'NamaOutlet' => (string) ($namaOutlet[$p->IdOutlet] ?? '-'),
                'NamaPerangkat' => $p->Nama,
                'JumlahOutbox' => $p->JumlahOutboxTertunda,
                'TertundaSejak' => $p->OutboxTertundaSejak?->toIso8601String(),
                'TerakhirAktifPada' => $p->TerakhirAktifPada?->toIso8601String(),
                'VersiAplikasi' => $p->VersiAplikasi,
            ])->all());
        });
    }

    /**
     * @param  Builder<Perangkat>  $kueri
     * @return Builder<Perangkat>
     */
    private function Saring(Builder $kueri): Builder
    {
        return $kueri
            ->whereNull('DicabutPada')
            ->whereNotNull('DiaktifkanPada')
            ->where('JumlahOutboxTertunda', '>', 0)
            ->where('OutboxTertundaSejak', '<=', now()->subHours((int) config('operasional.BatasOutboxMacetJam')));
    }
}
