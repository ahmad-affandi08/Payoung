<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Daftar templat invoice berulang untuk `TabelData` (F-21), dibatasi outlet yang boleh diakses pelaku (null = semua).
 * Cari: nama templat. Saring: `Status`. Templat yang gagal terbit pada putaran terakhir ditandai lewat `GalatTerakhir`.
 */
final class DaftarInvoiceBerulang
{
    public const KOLOM_URUT = ['Nama', 'BerikutnyaPada', 'JumlahTerbit', 'DibuatPada'];

    public const KOLOM_SARING = ['Status'];

    public const URUT_BAWAAN = 'BerikutnyaPada';

    public function __construct(
        private readonly IdentitasPelanggan $identitas,
        private readonly PetaUuidOutlet $petaOutlet,
    ) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function Ambil(DataPermintaanTabel $p, ?array $idOutletBoleh): array
    {
        $status = $p->AmbilDaftar('Status', array_map(fn (StatusInvoiceBerulang $s): string => $s->value, StatusInvoiceBerulang::cases()));
        $pola = PenerapKueriTabel::PolaCari($p->cari);
        /** @var Builder<InvoiceBerulang> $kueri */
        $kueri = InvoiceBerulang::query()
            ->when($idOutletBoleh !== null, fn ($q) => $q->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->when($status !== [], fn ($q) => $q->whereIn('Status', $status))
            ->when($p->cari !== '', fn ($q) => $q->where('Nama', 'like', $pola));

        return PenerapKueriTabel::Terapkan($kueri, $p, ['Nama' => 'Nama', 'BerikutnyaPada' => 'BerikutnyaPada', 'JumlahTerbit' => 'JumlahTerbit', 'DibuatPada' => 'DibuatPada'], function (Collection $baris): array {
            $pelanggan = $this->identitas->AmbilNamaBanyak($baris->pluck('IdPelanggan')->all());
            $outlet = $this->petaOutlet->AmbilKode(array_values(array_unique(array_map('intval', $baris->pluck('IdOutlet')->all()))));

            return array_values($baris->map(fn (InvoiceBerulang $t): array => [
                'Uuid' => $t->Uuid,
                'Nama' => $t->Nama,
                'NamaPelanggan' => $pelanggan[$t->IdPelanggan]['Nama'] ?? '',
                'KodeOutlet' => $outlet[$t->IdOutlet] ?? '',
                'Jadwal' => $t->Frekuensi->AmbilLabel($t->Interval),
                'TanggalMulai' => $t->TanggalMulai->format('Y-m-d'),
                'BerikutnyaPada' => $t->BerikutnyaPada?->format('Y-m-d'),
                'JumlahTerbit' => $t->JumlahTerbit,
                'BatasKali' => $t->BatasKali,
                'Status' => $t->Status->value,
                'LabelStatus' => $t->Status->AmbilLabel(),
                'Mode' => $t->Mode->value,
                'LabelMode' => $t->Mode->AmbilLabel(),
                'GalatTerakhir' => $t->GalatTerakhir,
            ])->all());
        });
    }
}
