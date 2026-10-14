<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Rilis\Kueri;

use App\Domain\Tenant\Model\RilisAplikasi;

/** Daftar rilis aplikasi untuk halaman Rilis aplikasi (P-10), terbaru dulu. */
final class DaftarRilis
{
    /**
     * @return list<array<string, mixed>>
     */
    public function Ambil(): array
    {
        return array_values(RilisAplikasi::query()
            ->orderByDesc('Id')
            ->limit(200)
            ->get()
            ->map(fn (RilisAplikasi $r): array => [
                'Uuid' => $r->Uuid,
                'Aplikasi' => $r->Aplikasi->value,
                'LabelAplikasi' => $r->Aplikasi->AmbilLabel(),
                'Platform' => $r->Platform,
                'Kanal' => $r->Kanal->value,
                'Versi' => $r->Versi,
                'Build' => $r->Build,
                'Status' => $r->Status->value,
                'PersenRollout' => $r->PersenRollout,
                'UrlUnduh' => $r->UrlUnduh,
                'Sha256Unduhan' => $r->Sha256Unduhan,
                'CatatanRilis' => $r->CatatanRilis,
                'VersiMinimum' => $r->VersiMinimum,
                'VersiMinimumBerlakuPada' => $r->VersiMinimumBerlakuPada?->toIso8601String(),
                'PerbaikanKeamanan' => $r->PerbaikanKeamanan,
                'DiterbitkanPada' => $r->DiterbitkanPada?->toIso8601String(),
                'DihentikanPada' => $r->DihentikanPada?->toIso8601String(),
                'AlasanDihentikan' => $r->AlasanDihentikan,
            ])
            ->all());
    }
}
