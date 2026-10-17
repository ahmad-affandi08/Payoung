<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Data;

use App\Domain\Organisasi\Model\Outlet;
use Carbon\CarbonInterface;

/**
 * Ringkasan outlet untuk domain lain (misal PanduanAwal, F-01) supaya tidak memakai Model `Outlet` langsung
 * (CLAUDE.md #14). Hanya baca.
 */
final readonly class DataOutletRingkas
{
    public function __construct(
        public int $id,
        public int $idTenant,
        public string $uuid,
        public string $kode,
        public string $nama,
        public ?string $kodeKota,
        public string $zonaWaktu,
        public ?string $templateSektor,
        public ?int $idTemplateSektorVersi,
        public ?CarbonInterface $templateSektorDiterapkanPada,
        public ?string $alamat = null,
        public ?string $telepon = null,
    ) {}

    public static function DariModel(Outlet $outlet): self
    {
        return new self(
            id: $outlet->Id,
            idTenant: $outlet->IdTenant,
            uuid: $outlet->Uuid,
            kode: $outlet->Kode,
            nama: $outlet->Nama,
            kodeKota: $outlet->KodeKota,
            zonaWaktu: $outlet->ZonaWaktu,
            templateSektor: $outlet->TemplateSektor,
            idTemplateSektorVersi: $outlet->IdTemplateSektorVersi,
            templateSektorDiterapkanPada: $outlet->TemplateSektorDiterapkanPada?->toImmutable(),
            alamat: $outlet->Alamat,
            telepon: $outlet->Telepon,
        );
    }
}
