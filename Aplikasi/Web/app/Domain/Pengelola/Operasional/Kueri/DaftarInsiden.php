<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Operasional\Kueri;

use App\Domain\Pengelola\Operasional\Aksi\KelolaInsiden;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\KomponenLayanan;
use App\Domain\Tenant\Enum\StatusInsiden;
use App\Domain\Tenant\Enum\TingkatInsiden;
use App\Domain\Tenant\Model\InsidenPlatform;
use App\Domain\Tenant\Model\PembaruanInsiden;

/** Insiden layanan untuk halaman konsol (P-11 PGL-21): 200 terbaru beserta kronologinya, dan pilihan isian. */
final class DaftarInsiden
{
    /**
     * @return array{Insiden: list<array<string, mixed>>, OpsiTingkat: list<array{Nilai: string, Label: string}>, OpsiStatus: list<array{Nilai: string, Label: string}>, OpsiKomponen: list<array{Nilai: string, Label: string}>, AmbangWajibUmumPersen: int}
     */
    public function Ambil(): array
    {
        $daftar = InsidenPlatform::query()->orderByRaw("Status = 'Selesai'")->orderByDesc('MulaiPada')->limit(200)->get();
        $pembaruan = PembaruanInsiden::query()->whereIn('IdInsiden', $daftar->pluck('Id')->all())->orderByDesc('Id')->get()->groupBy('IdInsiden');
        $nama = PenggunaPengelola::query()->whereIn('Id', $pembaruan->flatten()->pluck('DibuatOleh')->merge($daftar->pluck('DibuatOleh'))->filter()->unique()->all())->pluck('Nama', 'Id');

        return [
            'Insiden' => array_values($daftar->map(fn (InsidenPlatform $i): array => [
                'Uuid' => $i->Uuid,
                'Judul' => $i->Judul,
                'Tingkat' => $i->Tingkat->value,
                'Status' => $i->Status->value,
                'LabelStatus' => $i->Status->AmbilLabel(),
                'Dampak' => $i->Dampak,
                'Komponen' => $i->Komponen,
                'LabelKomponen' => array_values(array_map(fn (string $k): string => KomponenLayanan::tryFrom($k)?->AmbilLabel() ?? $k, $i->Komponen)),
                'PersenTenantTerdampak' => $i->PersenTenantTerdampak,
                'MulaiPada' => $i->MulaiPada->toIso8601ZuluString(),
                'DiumumkanPada' => $i->DiumumkanPada?->toIso8601ZuluString(),
                'SelesaiPada' => $i->SelesaiPada?->toIso8601ZuluString(),
                'PostMortem' => $i->PostMortem,
                'PostMortemDiterbitkanPada' => $i->PostMortemDiterbitkanPada?->toIso8601ZuluString(),
                'DibuatOleh' => $i->DibuatOleh === null ? null : ($nama[$i->DibuatOleh] ?? null),
                'Pembaruan' => array_values(($pembaruan->get($i->Id) ?? collect())->map(fn (PembaruanInsiden $p): array => [
                    'Status' => $p->Status->value,
                    'LabelStatus' => $p->Status->AmbilLabel(),
                    'Isi' => $p->Isi,
                    'Waktu' => $p->DibuatPada?->toIso8601ZuluString(),
                    'Oleh' => $p->DibuatOleh === null ? null : ($nama[$p->DibuatOleh] ?? null),
                ])->all()),
            ])->all()),
            'OpsiTingkat' => array_map(fn (TingkatInsiden $t): array => ['Nilai' => $t->value, 'Label' => $t->AmbilLabel()], TingkatInsiden::cases()),
            'OpsiStatus' => array_map(fn (StatusInsiden $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusInsiden::cases()),
            'OpsiKomponen' => array_map(fn (KomponenLayanan $k): array => ['Nilai' => $k->value, 'Label' => $k->AmbilLabel()], KomponenLayanan::cases()),
            'AmbangWajibUmumPersen' => KelolaInsiden::AMBANG_WAJIB_UMUM_PERSEN,
        ];
    }
}
