<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Kueri;

use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Model\ProdukRak;
use App\Domain\Persediaan\Model\RakGudang;
use App\Domain\Persediaan\Model\StokOpname;

/**
 * Rak gudang untuk halaman Rak (F-05c INV-07) dan pilihan opname per rak. Hanya rak di lokasi stok pada outlet yang boleh
 * diakses pelaku. Jumlah produk dan nomor opname yang sedang berjalan dihitung dalam satu kueri per rak-set, bukan per rak.
 */
final class DaftarRakGudang
{
    public function __construct(private readonly InfoGudang $infoGudang) {}

    /**
     * Semua rak (termasuk nonaktif) di lokasi yang boleh diakses, dipersempit ke satu lokasi bila `uuidGudang` diisi.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, UuidGudang: string, NamaGudang: string, NamaOutlet: string|null, Kode: string, Nama: string, Urutan: int, Aktif: bool, JumlahProduk: int, OpnameBerjalan: string|null}>
     */
    public function AmbilDaftar(?array $idOutletBoleh, ?string $uuidGudang = null): array
    {
        $gudang = [];

        foreach ($this->infoGudang->AmbilBoleh($idOutletBoleh, false) as $g) {
            if ($uuidGudang === null || $g->uuid === $uuidGudang) {
                $gudang[$g->id] = $g;
            }
        }

        if ($gudang === []) {
            return [];
        }

        $rak = RakGudang::query()->whereIn('IdGudang', array_keys($gudang))->orderBy('IdGudang')->orderBy('Urutan')->orderBy('Kode')->get();
        $idRak = $rak->pluck('Id')->all();
        $jumlah = $idRak === [] ? collect() : ProdukRak::query()->whereIn('IdRak', $idRak)->selectRaw('IdRak, COUNT(*) AS Jumlah')->groupBy('IdRak')->toBase()->pluck('Jumlah', 'IdRak');
        $opname = $idRak === [] ? collect() : StokOpname::query()
            ->whereIn('IdRak', $idRak)
            ->whereIn('Status', [StatusStokOpname::Berlangsung->value, StatusStokOpname::Ditinjau->value])
            ->pluck('Nomor', 'IdRak');

        return array_values($rak->map(fn (RakGudang $r): array => [
            'Uuid' => $r->Uuid,
            'UuidGudang' => $gudang[$r->IdGudang]->uuid,
            'NamaGudang' => $gudang[$r->IdGudang]->nama,
            'NamaOutlet' => $gudang[$r->IdGudang]->namaOutlet,
            'Kode' => $r->Kode,
            'Nama' => $r->Nama,
            'Urutan' => $r->Urutan,
            'Aktif' => $r->Aktif,
            'JumlahProduk' => (int) ($jumlah[$r->Id] ?? 0),
            'OpnameBerjalan' => isset($opname[$r->Id]) ? (string) $opname[$r->Id] : null,
        ])->all());
    }

    /**
     * Rak aktif yang berisi sedikitnya satu produk, untuk pilihan di formulir mulai opname.
     *
     * @param  list<int>|null  $idOutletBoleh
     * @return list<array{Uuid: string, UuidGudang: string, Kode: string, Nama: string, JumlahProduk: int}>
     */
    public function AmbilOpsiAktif(?array $idOutletBoleh): array
    {
        $hasil = [];

        foreach ($this->AmbilDaftar($idOutletBoleh) as $r) {
            if ($r['Aktif'] && $r['JumlahProduk'] > 0) {
                $hasil[] = ['Uuid' => $r['Uuid'], 'UuidGudang' => $r['UuidGudang'], 'Kode' => $r['Kode'], 'Nama' => $r['Nama'], 'JumlahProduk' => $r['JumlahProduk']];
            }
        }

        return $hasil;
    }
}
