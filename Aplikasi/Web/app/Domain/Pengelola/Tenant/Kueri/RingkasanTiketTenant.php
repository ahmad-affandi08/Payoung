<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;

/**
 * Tiket dukungan satu tenant untuk tab Tiket di tampilan 360° (P-07, P-09): `BATAS` tiket terbaru, jumlah yang masih
 * terbuka, dan berapa yang lewat batas SLA. Dipanggil di dalam `KonteksPengelola::JalankanLintasTenant` untuk tenant
 * yang dibuka (satu baris audit untuk seluruh pembukaan 360°), jadi membaca tenant aktif saat itu. Hanya untuk
 * pemegang `dukungan.tiket.lihat` (diperiksa pemanggil).
 */
final class RingkasanTiketTenant
{
    public const BATAS = 12;

    /**
     * @return array{Data: list<array<string, mixed>>, JumlahTerbuka: int, JumlahLewatSla: int}
     */
    public function Ambil(): array
    {
        $terbuka = array_map(fn (StatusTiketDukungan $s): string => $s->value, StatusTiketDukungan::AmbilTerbuka());

        return [
            'Data' => array_values(TiketDukungan::query()->orderByDesc('Id')->limit(self::BATAS)->get()->map(fn (TiketDukungan $t): array => [
                'Uuid' => $t->Uuid,
                'Nomor' => $t->Nomor,
                'Judul' => $t->Judul,
                'Status' => $t->Status->value,
                'LabelStatus' => $t->Status->AmbilLabel(),
                'Prioritas' => $t->Prioritas->AmbilLabel(),
                'LewatSla' => $t->Status->CekTerbuka() && $t->BatasSlaPada->lessThan(now()),
                'DibuatPada' => $t->DibuatPada->toIso8601String(),
            ])->all()),
            'JumlahTerbuka' => TiketDukungan::query()->whereIn('Status', $terbuka)->count(),
            'JumlahLewatSla' => TiketDukungan::query()->whereIn('Status', $terbuka)->where('BatasSlaPada', '<', now())->count(),
        ];
    }
}
