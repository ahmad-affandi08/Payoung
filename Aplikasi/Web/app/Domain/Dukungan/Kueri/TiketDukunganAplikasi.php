<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Kueri;

use App\Domain\Dukungan\Model\TiketDukungan;

/**
 * Tiket dukungan untuk aplikasi (P-09): daftar dan percakapan dalam JSON ringkas. Tetap dibatasi tenant aktif lewat
 * MilikTenant; [idPelapor] (Aplikasi Kasir) membatasi lagi ke tiket yang dibuat staf itu sendiri. Catatan internal tim
 * tidak pernah ikut.
 */
final class TiketDukunganAplikasi
{
    public function __construct(private readonly TiketDukunganTenant $tenant) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function Daftar(?int $idPelapor, int $batas = 30): array
    {
        return array_values(TiketDukungan::query()
            ->when($idPelapor !== null, fn ($kueri) => $kueri->where('IdPelapor', $idPelapor))
            ->orderByDesc('DibuatPada')
            ->orderByDesc('Id')
            ->limit($batas)
            ->get()
            ->map(fn (TiketDukungan $tiket): array => $this->Ringkas($tiket))
            ->all());
    }

    public function Cari(string $uuid, ?int $idPelapor): TiketDukungan
    {
        return TiketDukungan::query()
            ->where('Uuid', strtoupper($uuid))
            ->when($idPelapor !== null, fn ($kueri) => $kueri->where('IdPelapor', $idPelapor))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function Ringkas(TiketDukungan $tiket): array
    {
        return [
            ...TiketDukunganTenant::PetakanRingkas($tiket),
            'BatasSlaPada' => $tiket->BatasSlaPada->toIso8601String(),
            'SlaJamKerja' => ! $tiket->SlaJamKalender,
            'ResponsPertamaPada' => $tiket->ResponsPertamaPada?->toIso8601String(),
            'LewatSla' => $tiket->CekLewatSla(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function Detail(TiketDukungan $tiket): array
    {
        return [...$this->tenant->AmbilDetail($tiket), ...$this->Ringkas($tiket)];
    }
}
