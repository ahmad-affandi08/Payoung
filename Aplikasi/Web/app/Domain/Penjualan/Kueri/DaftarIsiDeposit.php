<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\StatusIsiDeposit;
use App\Domain\Penjualan\Model\IsiDeposit;
use Illuminate\Support\Collection;

/**
 * Daftar isi deposit pelanggan dari POS (F-16d bagian 1) untuk `TabelData` back-office: cari nomor, saring status &
 * perlu tinjauan, urut tanggal/jumlah. Nama pelanggan lewat `IdentitasPelanggan` (API publik domain Pelanggan).
 */
final class DaftarIsiDeposit
{
    public const KOLOM_URUT = ['DiterimaPada', 'Jumlah', 'Nomor'];

    public const KOLOM_SARING = ['Status', 'PerluTinjauan'];

    public const URUT_BAWAAN = '-DiterimaPada';

    public function __construct(private readonly IdentitasPelanggan $identitas) {}

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array<string, mixed>}
     */
    public function AmbilTabel(DataPermintaanTabel $permintaan): array
    {
        $status = $permintaan->AmbilDaftar('Status', array_map(fn (StatusIsiDeposit $s): string => $s->value, StatusIsiDeposit::cases()));
        $tinjauan = $permintaan->AmbilDaftar('PerluTinjauan', ['Ya', 'Tidak']);

        $kueri = IsiDeposit::query()
            ->when($status !== [], fn ($k) => $k->whereIn('Status', $status))
            ->when(count($tinjauan) === 1, fn ($k) => $k->where('PerluTinjauan', $tinjauan[0] === 'Ya'))
            ->when($permintaan->cari !== '', fn ($k) => $k->where('Nomor', 'like', PenerapKueriTabel::PolaCari($permintaan->cari)));

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['DiterimaPada' => 'DiterimaPada', 'Jumlah' => 'Jumlah', 'Nomor' => 'Nomor'], function (Collection $baris): array {
            /** @var Collection<int, IsiDeposit> $baris */
            $nama = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());

            return array_values($baris->map(fn (IsiDeposit $i): array => [
                'Uuid' => $i->Uuid,
                'Nomor' => $i->Nomor,
                'Pelanggan' => $i->IdPelanggan === null ? null : ($nama[$i->IdPelanggan] ?? null),
                'NamaMetode' => $i->NamaMetode,
                'Jumlah' => $i->Jumlah,
                'Status' => $i->Status->value,
                'LabelStatus' => $i->Status->AmbilLabel(),
                'PerluTinjauan' => $i->PerluTinjauan,
                'AlasanTinjauan' => $i->AlasanTinjauan,
                'AlasanBatal' => $i->AlasanBatal,
                'TanggalBisnis' => $i->TanggalBisnis->toDateString(),
                'DiterimaPada' => $i->DiterimaPada->toIso8601ZuluString(),
            ])->all());
        });
    }
}
