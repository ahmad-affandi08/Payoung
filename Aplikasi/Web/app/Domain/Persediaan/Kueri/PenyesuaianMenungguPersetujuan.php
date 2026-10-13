<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Model\PenyesuaianStok;
use Illuminate\Database\Eloquent\Builder;

/**
 * Antrean persetujuan penyesuaian stok untuk Aplikasi Owner (OWN-04): dokumen Menunggu Persetujuan di outlet akses
 * pengguna, terlama diajukan dulu. `BisaDisetujui` = pengguna bukan pembuat dan bukan pengaju (`SetujuiPenyesuaianStok`).
 */
final class PenyesuaianMenungguPersetujuan
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly DaftarAnggota $anggota,
        private readonly InfoGudang $gudang,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array<string, mixed>>
     */
    public function Daftar(?array $idOutletBoleh, int $idPengguna): array
    {
        $daftar = PenyesuaianStok::query()
            ->where('Status', StatusPenyesuaianStok::MenungguPersetujuan->value)
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderBy('DiajukanPada')->orderBy('Id')->limit(100)->get();
        $pengaju = $this->anggota->AmbilNamaPengguna($this->konteks->Wajib(), array_values(array_unique(array_filter($daftar->pluck('DiajukanOleh')->all(), 'is_int'))));
        $gudang = $this->gudang->AmbilBanyak(array_values(array_unique($daftar->pluck('IdGudang')->all())));

        return array_values($daftar->map(fn (PenyesuaianStok $p): array => [
            'Uuid' => $p->Uuid,
            'Nomor' => $p->Nomor,
            'NamaGudang' => $gudang[$p->IdGudang]->nama ?? '',
            'NamaOutlet' => $gudang[$p->IdGudang]->namaOutlet ?? '',
            'Tanggal' => $p->Tanggal->toDateString(),
            'Alasan' => $p->KodeAlasan->AmbilLabel(),
            'Keterangan' => $p->Keterangan,
            'JumlahBaris' => $p->JumlahBaris,
            'NilaiPerkiraan' => $p->NilaiPerkiraan,
            'DiajukanOleh' => $p->DiajukanOleh === null ? null : ($pengaju[$p->DiajukanOleh] ?? null),
            'DiajukanPada' => $p->DiajukanPada?->utc()->toIso8601ZuluString(),
            'BisaDisetujui' => $p->DibuatOleh !== $idPengguna && $p->DiajukanOleh !== $idPengguna,
        ])->all());
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     */
    public function Cari(string $uuid, ?array $idOutletBoleh): ?PenyesuaianStok
    {
        return PenyesuaianStok::query()
            ->where('Uuid', $uuid)
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->first();
    }
}
