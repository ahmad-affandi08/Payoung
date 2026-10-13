<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;
use Illuminate\Database\Eloquent\Builder;

/**
 * Antrean persetujuan PO untuk Aplikasi Owner (OWN-04): PO berstatus Menunggu Persetujuan di outlet akses pengguna,
 * terlama diajukan dulu. `BisaDisetujui` = pengguna bukan pembuat PO (aturan pemisahan tugas `SetujuiPesananPembelian`).
 */
final class PesananMenungguPersetujuan
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly DaftarAnggota $anggota,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array<string, mixed>>
     */
    public function Daftar(?array $idOutletBoleh, int $idPengguna): array
    {
        $daftar = $this->Kueri($idOutletBoleh)->with('Pemasok:Id,Nama')->withCount('Detail')->orderBy('DiajukanPada')->orderBy('Id')->limit(100)->get();
        $pengaju = $this->anggota->AmbilNamaPengguna($this->konteks->Wajib(), array_values(array_unique(array_filter($daftar->pluck('DiajukanOleh')->all(), 'is_int'))));

        return array_values($daftar->map(fn (PesananPembelian $po): array => [
            'Uuid' => $po->Uuid,
            'Nomor' => $po->Nomor,
            'NamaPemasok' => $po->Pemasok->Nama,
            'Tanggal' => $po->Tanggal->toDateString(),
            'JumlahBaris' => (int) $po->getAttribute('detail_count'),
            'Total' => $po->Total,
            'Catatan' => $po->Catatan,
            'DiajukanOleh' => $po->DiajukanOleh === null ? null : ($pengaju[$po->DiajukanOleh] ?? null),
            'DiajukanPada' => $po->DiajukanPada?->utc()->toIso8601ZuluString(),
            'BisaDisetujui' => $po->DibuatOleh !== $idPengguna,
        ])->all());
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     */
    public function Cari(string $uuid, ?array $idOutletBoleh): ?PesananPembelian
    {
        return PesananPembelian::query()
            ->where('Uuid', $uuid)
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->first();
    }

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return Builder<PesananPembelian>
     */
    private function Kueri(?array $idOutletBoleh): Builder
    {
        return PesananPembelian::query()
            ->where('Status', StatusPesananPembelian::MenungguPersetujuan->value)
            ->when($idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []));
    }
}
