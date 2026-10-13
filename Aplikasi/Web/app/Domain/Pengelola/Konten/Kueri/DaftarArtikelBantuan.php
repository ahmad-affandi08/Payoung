<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Konten\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Situs\Enum\StatusArtikel;
use App\Domain\Situs\Model\ArtikelBantuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * PLT-12: daftar artikel Pusat Bantuan di konsol (`TabelData`): cari judul/slug/kategori, saring status & kategori,
 * urut terbaru diubah.
 */
final class DaftarArtikelBantuan
{
    public const KOLOM_URUT = ['DiubahPada', 'DiterbitkanPada', 'Judul', 'Urutan'];

    public const KOLOM_SARING = ['Status', 'Kategori'];

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $permintaan): array
    {
        $kueri = ArtikelBantuan::query();
        $status = $permintaan->AmbilDaftar('Status', array_map(fn (StatusArtikel $s): string => $s->value, StatusArtikel::cases()));

        if ($status !== []) {
            $kueri->whereIn('Status', $status);
        }

        if (($permintaan->saring['Kategori'] ?? '') !== '') {
            $kueri->where('Kategori', $permintaan->saring['Kategori']);
        }

        if ($permintaan->cari !== '') {
            $kata = PenerapKueriTabel::PolaCari($permintaan->cari);
            $kueri->where(fn (Builder $b) => $b->where('Judul', 'like', $kata)->orWhere('Slug', 'like', $kata)->orWhere('Kategori', 'like', $kata));
        }

        if ($permintaan->urut === []) {
            $kueri->orderByDesc('DiubahPada')->orderByDesc('Id');
        }

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['DiubahPada' => 'DiubahPada', 'DiterbitkanPada' => 'DiterbitkanPada', 'Judul' => 'Judul', 'Urutan' => 'Urutan'], fn (Collection $baris): array => array_map(
            fn (ArtikelBantuan $a): array => $this->PetakanRingkas($a),
            array_values($baris->all()),
        ));
    }

    /**
     * @return list<string>
     */
    public function AmbilKategori(): array
    {
        return array_values(array_filter(ArtikelBantuan::query()->whereNotNull('Kategori')->distinct()->orderBy('Kategori')->pluck('Kategori')->all(), 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    public function PetakanRingkas(ArtikelBantuan $a): array
    {
        return [
            'Uuid' => $a->Uuid,
            'Slug' => $a->Slug,
            'Judul' => $a->Judul,
            'Kategori' => $a->Kategori,
            'Urutan' => $a->Urutan,
            'JumlahKonteks' => count($a->Konteks),
            'Status' => $a->Status->value,
            'LabelStatus' => $a->Status->AmbilLabel(),
            'Jalur' => '/pusat-bantuan/'.$a->Slug,
            'DiterbitkanPada' => $a->DiterbitkanPada?->toIso8601String(),
            'DiubahPada' => $a->DiubahPada?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function PetakanLengkap(ArtikelBantuan $a): array
    {
        return [
            ...$this->PetakanRingkas($a),
            'Ringkasan' => $a->Ringkasan,
            'Isi' => $a->Isi,
            'UrlVideo' => $a->UrlVideo,
            'Konteks' => $a->Konteks,
        ];
    }
}
