<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Model\StokOpname;
use Illuminate\Support\Collection;

/**
 * Daftar stok opname untuk `TabelData` (D-16; tipe FE `BarisDaftarStokOpname`), bawaan terbaru dulu. Hanya lokasi di
 * outlet yang boleh diakses. Cari: nomor, catatan, kategori, rak. Saring: Status, Gudang. Nilai selisih tidak memuat jumlah
 * sistem (aman untuk hitung buta).
 */
final class DaftarStokOpname
{
    public const KOLOM_URUT = ['TanggalSnapshot', 'Nomor'];

    public const KOLOM_SARING = ['Status', 'Gudang'];

    public const URUT_BAWAAN = '-TanggalSnapshot';

    public function __construct(private readonly InfoGudang $infoGudang) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $permintaan, ?array $idOutletBoleh): array
    {
        $kata = $permintaan->cari;
        $status = $permintaan->AmbilDaftar('Status', array_map(fn (StatusStokOpname $s): string => $s->value, StatusStokOpname::cases()));
        $uuidGudang = $permintaan->saring['Gudang'] ?? null;
        $idGudang = $uuidGudang === null ? null : (($this->infoGudang->AmbilDariUuid([$uuidGudang])[$uuidGudang] ?? null)->id ?? 0);
        $pola = PenerapKueriTabel::PolaCari($kata);

        $kueri = StokOpname::query()
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($status !== [], fn ($k) => $k->whereIn('Status', $status))
            ->when($idGudang !== null, fn ($k) => $k->where('IdGudang', $idGudang))
            ->when($kata !== '', fn ($k) => $k->where(fn ($d) => $d->where('Nomor', 'like', $pola)->orWhere('Catatan', 'like', $pola)->orWhere('NamaKategori', 'like', $pola)->orWhere('NamaRak', 'like', $pola)));

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['TanggalSnapshot' => 'TanggalSnapshot', 'Nomor' => 'Nomor'], fn (Collection $dokumen): array => $this->Petakan(array_values($dokumen->all())));
    }

    /**
     * @param  list<StokOpname>  $dokumen
     * @return list<array<string, mixed>>
     */
    private function Petakan(array $dokumen): array
    {
        $gudang = $this->infoGudang->AmbilBanyak(array_values(array_unique(array_map(fn (StokOpname $o): int => $o->IdGudang, $dokumen))));

        return array_map(fn (StokOpname $o): array => [
            'Uuid' => $o->Uuid,
            'Nomor' => $o->Nomor,
            'TanggalSnapshot' => $o->TanggalSnapshot->format('Y-m-d'),
            'NamaGudang' => $gudang[$o->IdGudang]->nama ?? '',
            'NamaOutlet' => $gudang[$o->IdGudang]->namaOutlet ?? null,
            'NamaKategori' => $o->NamaKategori,
            'NamaRak' => $o->NamaRak,
            'HitungButa' => $o->HitungButa,
            'Status' => $o->Status->value,
            'LabelStatus' => $o->Status->AmbilLabel(),
            'JumlahBaris' => $o->JumlahBaris,
            'JumlahDihitung' => $o->JumlahDihitung,
            'TotalNilaiLebih' => $o->TotalNilaiLebih,
            'TotalNilaiKurang' => $o->TotalNilaiKurang,
        ], $dokumen);
    }
}
