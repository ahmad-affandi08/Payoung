<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Karyawan\Enum\JenisCuti;
use App\Domain\Karyawan\Enum\StatusCuti;
use App\Domain\Karyawan\Model\CutiKaryawan;
use App\Domain\Karyawan\Model\Karyawan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar cuti/izin karyawan (F-18, `TabelData` mode server): saring status, jenis, karyawan, tanggal (irisan dengan
 * rentang); cari nama karyawan.
 */
final class DaftarCutiKaryawan
{
    public const KOLOM_URUT = ['TanggalMulai', 'TanggalSelesai'];

    public const KOLOM_SARING = ['Status', 'Jenis', 'Karyawan', 'Tanggal'];

    public const URUT_BAWAAN = '-TanggalMulai';

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p): array
    {
        $tanggal = $p->AmbilRentangTanggal('Tanggal');
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusCuti $s): string => $s->value, StatusCuti::cases()));
        $jenis = $p->AmbilDaftar('Jenis', array_map(fn (JenisCuti $j): string => $j->value, JenisCuti::cases()));
        $uuidKaryawan = $p->AmbilDaftar('Karyawan');
        $kueri = CutiKaryawan::query()
            ->when($status !== [], fn (Builder $k) => $k->whereIn('Status', $status))
            ->when($jenis !== [], fn (Builder $k) => $k->whereIn('Jenis', $jenis))
            ->when($uuidKaryawan !== [], fn (Builder $k) => $k->whereIn('IdKaryawan', Karyawan::query()->whereIn('Uuid', $uuidKaryawan)->select('Id')))
            ->when($tanggal['Dari'] !== null, fn (Builder $k) => $k->where('TanggalSelesai', '>=', $tanggal['Dari']))
            ->when($tanggal['Sampai'] !== null, fn (Builder $k) => $k->where('TanggalMulai', '<=', $tanggal['Sampai']))
            ->when($p->cari !== '', fn (Builder $k) => $k->whereIn('IdKaryawan', Karyawan::query()->where('Nama', 'like', PenerapKueriTabel::PolaCari($p->cari))->select('Id')));

        return PenerapKueriTabel::Terapkan($kueri, $p, ['TanggalMulai' => 'TanggalMulai', 'TanggalSelesai' => 'TanggalSelesai'], fn (Collection $baris): array => $this->Petakan($baris));
    }

    /**
     * @param  Collection<int, CutiKaryawan>  $baris
     * @return list<array<string, mixed>>
     */
    private function Petakan(Collection $baris): array
    {
        $karyawan = Karyawan::query()->whereKey($baris->pluck('IdKaryawan')->unique()->all())->get(['Id', 'Uuid', 'Nama'])->keyBy('Id');

        return array_values($baris->map(fn (CutiKaryawan $c): array => [
            'Uuid' => $c->Uuid,
            'Karyawan' => $karyawan->get($c->IdKaryawan)->Nama ?? '',
            'UuidKaryawan' => $karyawan->get($c->IdKaryawan)?->Uuid,
            'Jenis' => $c->Jenis->value,
            'TanggalMulai' => $c->TanggalMulai->toDateString(),
            'TanggalSelesai' => $c->TanggalSelesai->toDateString(),
            'Berbayar' => $c->Berbayar,
            'Alasan' => $c->Alasan,
            'Status' => $c->Status->value,
            'LabelStatus' => $c->Status->AmbilLabel(),
            'AlasanBatal' => $c->AlasanBatal,
        ])->all());
    }
}
