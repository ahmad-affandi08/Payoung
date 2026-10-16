<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Kueri;

use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Outlet;

/**
 * Data halaman Pengaturan › Ganti jenis usaha: outlet aktif beserta jenis usahanya sekarang, dan jenis usaha
 * (template terbit) yang bisa dipilih.
 */
final class PilihanGantiSektor
{
    public function __construct(private readonly TemplateTerbit $templateTerbit) {}

    /**
     * @return array{Outlet: list<array{Uuid: string, Nama: string, KodeTemplate: string|null, NamaTemplate: string|null}>, Template: list<array{Kode: string, Nama: string, Keterangan: string|null}>}
     */
    public function Ambil(): array
    {
        $template = $this->templateTerbit->AmbilSemua();
        $namaPerKode = array_column($template, 'Nama', 'Kode');

        return [
            'Outlet' => array_values(Outlet::query()
                ->where('Status', StatusOrganisasi::Aktif->value)
                ->orderBy('Id')
                ->get(['Uuid', 'Nama', 'TemplateSektor'])
                ->map(fn (Outlet $outlet): array => [
                    'Uuid' => $outlet->Uuid,
                    'Nama' => $outlet->Nama,
                    'KodeTemplate' => $outlet->TemplateSektor,
                    'NamaTemplate' => $outlet->TemplateSektor === null ? null : ($namaPerKode[$outlet->TemplateSektor] ?? $outlet->TemplateSektor),
                ])->all()),
            'Template' => array_map(fn (array $baris): array => [
                'Kode' => $baris['Kode'],
                'Nama' => $baris['Nama'],
                'Keterangan' => $baris['Keterangan'],
            ], $template),
        ];
    }
}
