<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStok;
use App\Domain\Persediaan\Model\PermintaanStokDetail;
use Illuminate\Support\Collection;

/**
 * Daftar permintaan stok untuk `TabelData` (D-105; tipe FE `BarisDaftarPermintaanStok`), bawaan terbaru dulu. Hanya
 * permintaan yang lokasi asal atau tujuannya di outlet yang boleh diakses (null = semua). Cari: nomor, catatan,
 * nama/SKU produk. Saring: Status (pilihan banyak), Gudang (Uuid lokasi asal atau tujuan), Arah (`Masuk` = diminta ke
 * outlet saya, `Keluar` = saya yang meminta). `PerluDikirim` = masih terbuka dan outlet asalnya boleh saya akses.
 */
final class DaftarPermintaanStok
{
    public const KOLOM_URUT = ['Tanggal', 'Nomor'];

    public const KOLOM_SARING = ['Status', 'Gudang', 'Arah'];

    public const URUT_BAWAAN = '-Tanggal';

    public function __construct(private readonly InfoGudang $infoGudang) {}

    /**
     * @param  list<int>|null  $idOutletBoleh
     * @return array{Data: list<array<string, mixed>>, Meta: array{Halaman: int, PerHalaman: int, Total: int, JumlahHalaman: int}}
     */
    public function AmbilTabel(DataPermintaanTabel $permintaan, ?array $idOutletBoleh): array
    {
        $kata = $permintaan->cari;
        $status = $permintaan->AmbilDaftar('Status', array_map(fn (StatusPermintaanStok $s): string => $s->value, StatusPermintaanStok::cases()));
        $arah = $permintaan->saring['Arah'] ?? null;
        $uuidGudang = $permintaan->saring['Gudang'] ?? null;
        $idGudang = $uuidGudang === null ? null : (($this->infoGudang->AmbilDariUuid([$uuidGudang])[$uuidGudang] ?? null)->id ?? 0);
        $pola = PenerapKueriTabel::PolaCari($kata);

        $kueri = PermintaanStok::query()
            ->when($idOutletBoleh !== null, fn ($k) => $k->where(fn ($d) => $d->whereIn('IdOutletAsal', $idOutletBoleh ?? [])->orWhereIn('IdOutletTujuan', $idOutletBoleh ?? [])))
            ->when($arah === 'Masuk' && $idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutletAsal', $idOutletBoleh ?? []))
            ->when($arah === 'Keluar' && $idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutletTujuan', $idOutletBoleh ?? []))
            ->when($status !== [], fn ($k) => $k->whereIn('Status', $status))
            ->when($idGudang !== null, fn ($k) => $k->where(fn ($d) => $d->where('IdGudangAsal', $idGudang)->orWhere('IdGudangTujuan', $idGudang)))
            ->when($kata !== '', fn ($k) => $k->where(fn ($d) => $d
                ->where('Nomor', 'like', $pola)
                ->orWhere('Catatan', 'like', $pola)
                ->orWhereIn('Id', PermintaanStokDetail::query()->select('IdPermintaanStok')->where(fn ($x) => $x->where('NamaProduk', 'like', $pola)->orWhere('Sku', 'like', $pola)))));

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, ['Tanggal' => 'Tanggal', 'Nomor' => 'Nomor'], fn (Collection $dokumen): array => $this->Petakan(array_values($dokumen->all()), $idOutletBoleh));
    }

    /**
     * @param  list<PermintaanStok>  $dokumen
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array<string, mixed>>
     */
    private function Petakan(array $dokumen, ?array $idOutletBoleh): array
    {
        $gudang = $this->infoGudang->AmbilBanyak(array_values(array_unique([...array_map(fn (PermintaanStok $p): int => $p->IdGudangAsal, $dokumen), ...array_map(fn (PermintaanStok $p): int => $p->IdGudangTujuan, $dokumen)])));

        return array_map(fn (PermintaanStok $p): array => [
            'Uuid' => $p->Uuid,
            'Nomor' => $p->Nomor,
            'Tanggal' => $p->Tanggal->format('Y-m-d'),
            'NamaGudangAsal' => $gudang[$p->IdGudangAsal]->nama ?? '',
            'NamaOutletAsal' => $gudang[$p->IdGudangAsal]->namaOutlet ?? null,
            'NamaGudangTujuan' => $gudang[$p->IdGudangTujuan]->nama ?? '',
            'NamaOutletTujuan' => $gudang[$p->IdGudangTujuan]->namaOutlet ?? null,
            'Status' => $p->Status->value,
            'LabelStatus' => $p->Status->AmbilLabel(),
            'JumlahBaris' => $p->JumlahBaris,
            'PerluDikirim' => $p->Status->CekTerbuka() && ($idOutletBoleh === null || in_array($p->IdOutletAsal, $idOutletBoleh, true)),
        ], $dokumen);
    }
}
