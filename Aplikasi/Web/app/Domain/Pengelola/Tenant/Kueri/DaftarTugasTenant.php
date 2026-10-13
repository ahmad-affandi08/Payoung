<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pengelola\Tenant\Enum\JenisTugasTenant;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Model\SkorKesehatanTenant;
use App\Domain\Pengelola\Tenant\Model\TugasTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tugas otomatis tim dari skor kesehatan (P-07 PGL-09) untuk `TabelData` mode server: saring status dan jenis, cari nama
 * atau slug tenant; tugas terbuka tampil di depan. Juga ringkasan sebaran kategori pada tanggal hitung terbaru.
 */
final class DaftarTugasTenant
{
    public const KOLOM_URUT = ['DibuatPada'];

    public const KOLOM_SARING = ['Status', 'Jenis'];

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p): array
    {
        $status = $p->AmbilDaftar('Status', [TugasTenant::TERBUKA, TugasTenant::SELESAI]);
        $jenis = $p->AmbilDaftar('Jenis', array_map(fn (JenisTugasTenant $j): string => $j->value, JenisTugasTenant::cases()));
        $pola = PenerapKueriTabel::PolaCari($p->cari);
        $kueri = TugasTenant::query()
            ->when($status !== [], fn (Builder $k) => $k->whereIn('Status', $status))
            ->when($jenis !== [], fn (Builder $k) => $k->whereIn('Jenis', $jenis))
            ->when($p->cari !== '', fn (Builder $k) => $k->whereIn('IdTenant', Tenant::query()->select('Id')->where(fn ($t) => $t->where('Nama', 'like', $pola)->orWhere('Slug', 'like', $pola))))
            ->orderByRaw("Status = 'Terbuka' DESC");

        return PenerapKueriTabel::Terapkan($kueri, $p, ['DibuatPada' => 'Id'], fn (Collection $baris): array => $this->Petakan($baris));
    }

    /**
     * Jumlah tenant per kategori pada tanggal hitung terbaru.
     *
     * @return array{Tanggal: string|null, Sehat: int, PerluPerhatian: int, Berisiko: int, TugasTerbuka: int}
     */
    public function AmbilSebaran(): array
    {
        $tanggal = SkorKesehatanTenant::query()->max('Tanggal');
        $hitung = $tanggal === null ? collect() : SkorKesehatanTenant::query()->where('Tanggal', $tanggal)->selectRaw('Kategori, COUNT(*) AS Jumlah')->groupBy('Kategori')->pluck('Jumlah', 'Kategori');

        return [
            'Tanggal' => is_string($tanggal) ? substr($tanggal, 0, 10) : null,
            'Sehat' => (int) ($hitung[KategoriKesehatan::Sehat->value] ?? 0),
            'PerluPerhatian' => (int) ($hitung[KategoriKesehatan::PerluPerhatian->value] ?? 0),
            'Berisiko' => (int) ($hitung[KategoriKesehatan::Berisiko->value] ?? 0),
            'TugasTerbuka' => TugasTenant::query()->where('Status', TugasTenant::TERBUKA)->count(),
        ];
    }

    /**
     * @param  Collection<int, TugasTenant>  $baris
     * @return list<array<string, mixed>>
     */
    private function Petakan(Collection $baris): array
    {
        $idTenant = $baris->pluck('IdTenant')->unique()->all();
        $tenant = Tenant::query()->whereIn('Id', $idTenant)->get(['Id', 'Uuid', 'Nama', 'Slug'])->keyBy('Id');
        $skor = [];

        foreach (SkorKesehatanTenant::query()->whereIn('IdTenant', $idTenant)->orderByDesc('Tanggal')->get() as $s) {
            $skor[$s->IdTenant] ??= $s;
        }

        return array_values($baris->map(fn (TugasTenant $t): array => [
            'Uuid' => $t->Uuid,
            'UuidTenant' => $tenant->get($t->IdTenant)?->Uuid,
            'NamaTenant' => $tenant->get($t->IdTenant)->Nama ?? '',
            'Slug' => $tenant->get($t->IdTenant)->Slug ?? '',
            'Jenis' => $t->Jenis->value,
            'LabelJenis' => $t->Jenis->AmbilLabel(),
            'Ringkasan' => $t->Ringkasan,
            'Status' => $t->Status,
            'DibuatPada' => $t->DibuatPada?->toIso8601String() ?? '',
            'SelesaiPada' => $t->SelesaiPada?->toIso8601String(),
            'CaraSelesai' => $t->CaraSelesai,
            'CatatanSelesai' => $t->CatatanSelesai,
            'Skor' => isset($skor[$t->IdTenant]) ? $skor[$t->IdTenant]->Skor : null,
            'Kategori' => isset($skor[$t->IdTenant]) ? $skor[$t->IdTenant]->Kategori->value : null,
            'LabelKategori' => isset($skor[$t->IdTenant]) ? $skor[$t->IdTenant]->Kategori->AmbilLabel() : null,
        ])->all());
    }
}
