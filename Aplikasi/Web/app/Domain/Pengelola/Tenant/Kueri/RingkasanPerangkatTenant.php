<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;

/**
 * Perangkat POS satu tenant untuk tab Perangkat di tampilan 360° (P-07): nama, jenis, outlet, platform, versi aplikasi,
 * terakhir aktif, dan outbox tertunda, supaya Dukungan melihat perangkat yang lama offline atau menumpuk antreannya.
 * Token, kunci PIN, dan profil hardware tidak ikut. Dipanggil di dalam `KonteksPengelola::JalankanLintasTenant` untuk
 * tenant yang dibuka (satu baris audit untuk seluruh pembukaan 360°), jadi membaca tenant aktif saat itu.
 */
final class RingkasanPerangkatTenant
{
    /** Perangkat yang tidak aktif lebih dari ini dianggap lama offline. */
    public const HARI_OFFLINE = 7;

    /**
     * @return array{Data: list<array<string, mixed>>, JumlahAktif: int, JumlahOffline: int, TotalOutboxTertunda: int}
     */
    public function Ambil(): array
    {
        $batas = now()->subDays(self::HARI_OFFLINE);
        $perangkat = Perangkat::query()->orderByRaw('DicabutPada IS NULL DESC')->orderBy('Id')->limit(100)->get();
        $outlet = Outlet::query()->whereIn('Id', $perangkat->pluck('IdOutlet')->unique()->all())->pluck('Nama', 'Id');
        $data = array_values($perangkat->map(function (Perangkat $p) use ($outlet, $batas): array {
            $terakhir = $p->TerakhirAktifPada ?? $p->DiaktifkanPada;

            return [
                'Uuid' => $p->Uuid,
                'Nama' => $p->Nama,
                'Kode' => $p->Kode,
                'Jenis' => $p->Jenis->AmbilLabel(),
                'Outlet' => $outlet[$p->IdOutlet] ?? '',
                'Platform' => $p->Platform?->value,
                'VersiAplikasi' => $p->VersiAplikasi,
                'DiaktifkanPada' => $p->DiaktifkanPada?->toIso8601String(),
                'TerakhirAktifPada' => $p->TerakhirAktifPada?->toIso8601String(),
                'OutboxTertunda' => $p->JumlahOutboxTertunda,
                'Dicabut' => $p->DicabutPada !== null,
                'LamaOffline' => $p->DicabutPada === null && $terakhir !== null && $terakhir->lessThan($batas),
            ];
        })->all());

        return [
            'Data' => $data,
            'JumlahAktif' => count(array_filter($data, fn (array $d): bool => ! $d['Dicabut'])),
            'JumlahOffline' => count(array_filter($data, fn (array $d): bool => $d['LamaOffline'])),
            'TotalOutboxTertunda' => array_sum(array_map(fn (array $d): int => $d['Dicabut'] ? 0 : (int) $d['OutboxTertunda'], $data)),
        ];
    }
}
