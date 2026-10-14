<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Kueri;

use App\Domain\Pengelola\Konten\Aksi\SimpanDrafTemplatKomunikasi;
use App\Domain\Tenant\Enum\BahasaKomunikasi;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use App\Domain\Tenant\Enum\StatusTemplateKomunikasi;
use App\Domain\Tenant\Model\TemplateKomunikasi;

/**
 * PGL-07: tampilan templat komunikasi di konsol. Daftar = satu kelompok per peristiwa dengan sel peristiwa × kanal ×
 * bahasa (sumber teks yang sedang dipakai, versi terbit, ada draf, status BSP); editor = variabel, teks bawaan, versi
 * terbit, draf, dan riwayat satu kombinasi.
 */
final class DaftarTemplatKomunikasi
{
    /**
     * @return list<array{Kode: string, Label: string, Keterangan: string, Sel: list<array<string, mixed>>}>
     */
    public function AmbilDaftar(): array
    {
        $semua = TemplateKomunikasi::query()->orderByDesc('Versi')->get()->groupBy(fn (TemplateKomunikasi $t): string => $t->KodePeristiwa.'|'.$t->Kanal->value.'|'.$t->Bahasa->value);
        $hasil = [];

        foreach (PeristiwaKomunikasi::cases() as $peristiwa) {
            $sel = [];

            foreach ($peristiwa->AmbilKanal() as $kanal) {
                foreach (BahasaKomunikasi::cases() as $bahasa) {
                    $versi = $semua->get($peristiwa->value.'|'.$kanal->value.'|'.$bahasa->value, collect());
                    $terbit = $versi->first(fn (TemplateKomunikasi $t): bool => $t->Status === StatusTemplateKomunikasi::Terbit);
                    $draf = $versi->first(fn (TemplateKomunikasi $t): bool => $t->Status === StatusTemplateKomunikasi::Draf);

                    $sel[] = [
                        'Kanal' => $kanal->value,
                        'KanalLabel' => $kanal->AmbilLabel(),
                        'Bahasa' => $bahasa->value,
                        'BahasaLabel' => $bahasa->AmbilLabel(),
                        'Sumber' => $terbit !== null ? 'Templat' : 'Bawaan',
                        'VersiTerbit' => $terbit?->Versi,
                        'AdaDraf' => $draf !== null,
                        'StatusBsp' => $terbit?->StatusBsp?->value,
                    ];
                }
            }

            $hasil[] = ['Kode' => $peristiwa->value, 'Label' => $peristiwa->AmbilLabel(), 'Keterangan' => $peristiwa->AmbilKeterangan(), 'Sel' => $sel];
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    public function AmbilEditor(PeristiwaKomunikasi $peristiwa, KanalKomunikasi $kanal, BahasaKomunikasi $bahasa): array
    {
        $versi = TemplateKomunikasi::query()
            ->where('KodePeristiwa', $peristiwa->value)->where('Kanal', $kanal->value)->where('Bahasa', $bahasa->value)
            ->orderByDesc('Versi')->get();
        $terbit = $versi->first(fn (TemplateKomunikasi $t): bool => $t->Status === StatusTemplateKomunikasi::Terbit);
        $draf = $versi->first(fn (TemplateKomunikasi $t): bool => $t->Status === StatusTemplateKomunikasi::Draf);
        $wajib = $peristiwa->AmbilVariabelWajib($kanal);

        return [
            'Peristiwa' => ['Kode' => $peristiwa->value, 'Label' => $peristiwa->AmbilLabel(), 'Keterangan' => $peristiwa->AmbilKeterangan()],
            'Kanal' => ['Nilai' => $kanal->value, 'Label' => $kanal->AmbilLabel()],
            'Bahasa' => ['Nilai' => $bahasa->value, 'Label' => $bahasa->AmbilLabel()],
            'Variabel' => array_map(
                fn (array $v): array => [...$v, 'Wajib' => in_array($v['Nama'], $wajib, true)],
                $peristiwa->AmbilVariabel(),
            ),
            'Bawaan' => $peristiwa->AmbilBawaan($kanal, $bahasa),
            'Terbit' => $terbit === null ? null : $this->Petakan($terbit),
            'Draf' => $draf === null ? null : $this->Petakan($draf),
            'Riwayat' => $versi->filter(fn (TemplateKomunikasi $t): bool => $t->Status === StatusTemplateKomunikasi::Terbit)->map(fn (TemplateKomunikasi $t): array => $this->Petakan($t))->values()->all(),
            'Batas' => [
                'Subjek' => SimpanDrafTemplatKomunikasi::BATAS_SUBJEK,
                'Judul' => $kanal === KanalKomunikasi::Notifikasi ? SimpanDrafTemplatKomunikasi::BATAS_JUDUL_NOTIFIKASI : SimpanDrafTemplatKomunikasi::BATAS_JUDUL,
                'Isi' => match ($kanal) {
                    KanalKomunikasi::Email => SimpanDrafTemplatKomunikasi::BATAS_ISI_EMAIL,
                    KanalKomunikasi::WhatsApp => SimpanDrafTemplatKomunikasi::BATAS_ISI_WHATSAPP,
                    KanalKomunikasi::Notifikasi => SimpanDrafTemplatKomunikasi::BATAS_ISI_NOTIFIKASI,
                },
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function Petakan(TemplateKomunikasi $t): array
    {
        return [
            'Uuid' => $t->Uuid,
            'Versi' => $t->Versi,
            'Status' => $t->Status->value,
            'Subjek' => $t->Subjek,
            'Judul' => $t->Judul,
            'Isi' => $t->Isi,
            'StatusBsp' => $t->StatusBsp?->value,
            'CatatanBsp' => $t->CatatanBsp,
            'DiterbitkanPada' => $t->DiterbitkanPada?->toIso8601String(),
            'DiubahPada' => $t->DiubahPada?->toIso8601String(),
        ];
    }
}
