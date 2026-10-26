<?php

declare(strict_types=1);

use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Persediaan\BantuanBuku;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Urutan kunci global (DesainF05a C.2): L1 Tenant (S) → idempotensi MutasiStok → L3 SaldoStok urut (IdProduk,
 * IdGudang) → L6 LapisanFifo. Uji konkurensi multi-proses tidak bisa di bawah RefreshDatabase (H-6), jadi urutan
 * pernyataan penguncian ditangkap lewat DB::listen dan dibandingkan secara deterministik.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return list<QueryExecuted>
 */
function TangkapKueriKunciBuku(Closure $aksi): array
{
    $kueri = [];
    DB::listen(function (QueryExecuted $q) use (&$kueri): void {
        $kueri[] = $q;
    });
    $aksi();

    return $kueri;
}

describe('F-05a buku stok: urutan kunci (DesainF05a C.2)', function (): void {
    // Audit F-07 (v2.32): pemeriksaan idempotensi MutasiStok dibaca TANPA kunci (kunci celah = deadlock di test dua
    // koneksi); SaldoStok yang sudah ada dikunci FOR UPDATE lebih dulu, upsert hanya untuk pasangan baru.
    it('SaldoStok dikunci urut (IdProduk, IdGudang) walau baris dokumen tidak urut. Urutan L1 → L3 (kunci, upsert pasangan baru) → L6. Idempotensi tanpa kunci', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(metodeHpp: MetodeHpp::Fifo);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $g1 = $t['Gudang']->Id;
        $g2 = BantuanPersediaan::BuatGudang($t['Outlet'])->Id;
        $produk = [$p['Produksi']->Id, $p['Stok']->Id, $p['BahanBaku']->Id];
        rsort($produk);

        $kueri = TangkapKueriKunciBuku(fn () => BantuanBuku::Catat([
            BantuanBuku::BuatBaris('A', $produk[0], $g2, '1', '1000.00'),
            BantuanBuku::BuatBaris('B', $produk[2], $g2, '1', '1000.00'),
            BantuanBuku::BuatBaris('C', $produk[1], $g1, '1', '1000.00'),
            BantuanBuku::BuatBaris('D', $produk[2], $g1, '1', '1000.00'),
            BantuanBuku::BuatBaris('E', $produk[0], $g1, '1', '1000.00'),
        ], JenisReferensiMutasi::StokAwal));

        $urutan = [];
        $upsertSaldo = null;
        $kunciSaldo = null;

        foreach ($kueri as $i => $q) {
            $sql = strtolower($q->sql);

            if (str_contains($sql, 'from `tenant`') && (str_contains($sql, 'lock in share mode') || str_contains($sql, 'for share'))) {
                $urutan['Tenant'] ??= $i;
            } elseif (str_contains($sql, 'from `mutasistok`') && str_contains($sql, 'for update')) {
                $urutan['MutasiStok'] ??= $i;
            } elseif (str_starts_with($sql, 'insert into `saldostok`')) {
                $urutan['SaldoStokUpsert'] ??= $i;
                $upsertSaldo ??= $q;
            } elseif (str_contains($sql, 'from `saldostok`') && str_contains($sql, 'for update')) {
                $urutan['SaldoStok'] ??= $i;
                $kunciSaldo ??= $q;
            } elseif (str_contains($sql, 'from `lapisanfifo`') && str_contains($sql, 'for update')) {
                $urutan['LapisanFifo'] ??= $i;
            }
        }

        expect(array_keys($urutan))->toBe(['Tenant', 'SaldoStok', 'SaldoStokUpsert', 'LapisanFifo'])
            ->and($upsertSaldo)->not->toBeNull()
            ->and($kunciSaldo)->not->toBeNull();

        // Sisipan upsert: baris-baris VALUES dalam urutan pasangan naik (kolom dibaca dari daftar kolom SQL).
        preg_match('/^insert into `SaldoStok` \(([^)]*)\)/i', (string) $upsertSaldo?->sql, $cocok);
        $kolom = array_map(fn (string $k): string => trim($k, ' `'), explode(',', $cocok[1] ?? ''));
        $posisiProduk = (int) array_search('IdProduk', $kolom, true);
        $posisiGudang = (int) array_search('IdGudang', $kolom, true);
        $ikatan = array_values($upsertSaldo->bindings ?? []);
        $pasangan = [];

        for ($i = 0; $i < count($ikatan); $i += count($kolom)) {
            $pasangan[] = [(int) $ikatan[$i + $posisiProduk], (int) $ikatan[$i + $posisiGudang]];
        }

        $harapan = [[$produk[2], $g1], [$produk[2], $g2], [$produk[1], $g1], [$produk[0], $g1], [$produk[0], $g2]];
        usort($harapan, fn (array $a, array $b): int => $a <=> $b);

        expect($pasangan)->toBe($harapan)
            ->and(strtolower((string) $kunciSaldo?->sql))->toContain('order by `idproduk` asc, `idgudang` asc');
    });

    it('PengunciSaldoStok reentran: membuat baris nol untuk pasangan baru, mengembalikan baris yang sama bila dipanggil ulang', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $g = $t['Gudang']->Id;
        BantuanBuku::CatatMasuk($p['Stok']->Id, $g, '5', '192500.00');
        $pengunci = app(PengunciSaldoStok::class);

        [$pertama, $kedua] = DB::transaction(fn (): array => [
            $pengunci->Kunci([[$p['Stok']->Id, $g], [$p['Produksi']->Id, $g], [$p['Stok']->Id, $g]]),
            $pengunci->Kunci([[$p['Produksi']->Id, $g], [$p['Stok']->Id, $g]]),
        ]);

        $kunciStok = SaldoStok::BuatKunciPasangan($p['Stok']->Id, $g);
        $kunciProduksi = SaldoStok::BuatKunciPasangan($p['Produksi']->Id, $g);

        expect($pertama)->toHaveCount(2)
            ->and($pertama[$kunciStok]->JumlahTersedia)->toBe('5.0000')
            ->and($pertama[$kunciStok]->NilaiPersediaan)->toBe('192500.00')
            ->and($pertama[$kunciProduksi]->JumlahTersedia)->toBe('0.0000')
            ->and($pertama[$kunciProduksi]->HppRataRata)->toBeNull()
            ->and($kedua[$kunciProduksi]->Id)->toBe($pertama[$kunciProduksi]->Id)
            ->and(SaldoStok::query()->count())->toBe(2)
            ->and(BantuanBuku::PeriksaInvarianBuku($t['Tenant']->Id))->toBe([]);
    });
});
