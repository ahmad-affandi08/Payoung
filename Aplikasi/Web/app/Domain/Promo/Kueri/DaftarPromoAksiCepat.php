<?php

declare(strict_types=1);

namespace App\Domain\Promo\Kueri;

use App\Domain\Promo\Enum\StatusPromo;
use App\Domain\Promo\Model\Promo;

/**
 * Daftar promo untuk aksi cepat Aplikasi Owner (OWN-07): nama, rentang berlaku, dan apakah promo menyala (`Aktif`) atau
 * dimatikan (`Diarsipkan`). Promo yang sudah lewat masa berlakunya tetap tampil dengan penanda `Berakhir`, supaya pemilik
 * tidak menyalakan promo yang sebenarnya tidak berlaku lagi tanpa sadar.
 */
final class DaftarPromoAksiCepat
{
    /**
     * @return list<array{Uuid: string, Kode: string, Nama: string, Aktif: bool, MulaiPada: string|null, SelesaiPada: string|null, Berakhir: bool}>
     */
    public function Ambil(?string $kata, int $batas = 50): array
    {
        $kata = trim((string) $kata);
        $pola = '%'.addcslashes($kata, '%_\\').'%';
        $sekarang = now();

        return array_values(Promo::query()
            ->when($kata !== '', fn ($kueri) => $kueri->where(fn ($dalam) => $dalam->where('Nama', 'like', $pola)->orWhere('Kode', 'like', $pola)))
            ->orderByRaw('CASE WHEN `Status` = ? THEN 0 ELSE 1 END', [StatusPromo::Aktif->value])
            ->orderByDesc('DiubahPada')
            ->limit(max(1, min(100, $batas)))
            ->get()
            ->map(fn (Promo $p): array => [
                'Uuid' => $p->Uuid,
                'Kode' => $p->Kode,
                'Nama' => $p->Nama,
                'Aktif' => $p->Status === StatusPromo::Aktif,
                'MulaiPada' => $p->MulaiPada?->toIso8601String(),
                'SelesaiPada' => $p->SelesaiPada?->toIso8601String(),
                'Berakhir' => $p->SelesaiPada !== null && $p->SelesaiPada->lessThanOrEqualTo($sekarang),
            ])->all());
    }
}
