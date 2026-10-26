<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Kueri;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Model\PembayaranKonsinyasi;
use App\Domain\Pembelian\Model\PenitipProduk;
use App\Domain\Persediaan\Kueri\RingkasanMutasiKonsinyasi;

/**
 * F-05i: hutang konsinyasi per penitip = nilai titipan yang sudah terjual (Σ HPP penjualan bersih produk titipannya,
 * sama dengan kredit Hutang Konsinyasi di J-05.7) − setoran yang dicatat. Dipakai daftar penitip dan pembatas
 * setoran.
 */
final class HutangKonsinyasi
{
    public function __construct(private readonly RingkasanMutasiKonsinyasi $ringkasan) {}

    /**
     * @param  list<int>|null  $idPemasok  null = semua penitip
     * @return array<int, array{Terjual: Uang, Dibayar: Uang, Sisa: Uang, JumlahProduk: int}> kunci = IdPemasok
     */
    public function Ambil(?array $idPemasok = null): array
    {
        $penitip = PenitipProduk::query()
            ->when($idPemasok !== null, fn ($kueri) => $kueri->whereIn('IdPemasok', $idPemasok ?? []))
            ->get(['IdProduk', 'IdPemasok']);
        $ringkasan = $this->ringkasan->Ambil(array_values(array_map('intval', $penitip->pluck('IdProduk')->all())));
        $hasil = [];

        foreach ($idPemasok ?? [] as $id) {
            $hasil[$id] = ['Terjual' => Uang::Nol(), 'Dibayar' => Uang::Nol(), 'Sisa' => Uang::Nol(), 'JumlahProduk' => 0];
        }

        foreach ($penitip as $p) {
            $hasil[$p->IdPemasok] ??= ['Terjual' => Uang::Nol(), 'Dibayar' => Uang::Nol(), 'Sisa' => Uang::Nol(), 'JumlahProduk' => 0];
            $hasil[$p->IdPemasok]['Terjual'] = $hasil[$p->IdPemasok]['Terjual']->Tambah($ringkasan[$p->IdProduk]['NilaiTerjual'] ?? Uang::Nol());
            $hasil[$p->IdPemasok]['JumlahProduk']++;
        }

        $dibayar = PembayaranKonsinyasi::query()
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->when($idPemasok !== null, fn ($kueri) => $kueri->whereIn('IdPemasok', $idPemasok ?? []))
            ->groupBy('IdPemasok')
            ->selectRaw('IdPemasok, SUM(Jumlah) AS Jumlah')
            ->toBase()
            ->get();

        foreach ($dibayar as $d) {
            $id = (int) $d->IdPemasok;
            $hasil[$id] ??= ['Terjual' => Uang::Nol(), 'Dibayar' => Uang::Nol(), 'Sisa' => Uang::Nol(), 'JumlahProduk' => 0];
            $hasil[$id]['Dibayar'] = Uang::Dari((string) $d->Jumlah);
        }

        foreach ($hasil as $id => $h) {
            $hasil[$id]['Sisa'] = $h['Terjual']->Kurangi($h['Dibayar']);
        }

        return $hasil;
    }

    public function AmbilSisa(int $idPemasok): Uang
    {
        return $this->Ambil([$idPemasok])[$idPemasok]['Sisa'];
    }
}
