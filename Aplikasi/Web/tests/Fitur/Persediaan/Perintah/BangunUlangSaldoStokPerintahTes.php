<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Layanan\PembangunUlangSaldoStok;
use App\Domain\Persediaan\Layanan\PemeriksaKonsistensiStok;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant ber-ledger konsisten (ditulis langsung lewat bantuan, tanpa mesin buku stok): minyak goreng 10 @ 1234.5678
 * lalu keluar 3, gula pasir (kg) 25.5 kg, susu UHT batch 12 + keluar 2, satu rice cooker bernomor seri.
 *
 * @return array{Tenant: Tenant, Gudang: Gudang, Produk: array<string, Produk>, Batch: BatchStok}
 */
function SiapkanLedgerBangunUlang(string $namaUsaha = 'Toko Sembako Berkah Jaya', MetodeHpp $metode = MetodeHpp::RataRata): array
{
    $t = BantuanPersediaan::SiapkanTenant($namaUsaha, $metode);
    $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $gudang = $t['Gudang']->Id;

    $minyakMasuk = BantuanPersediaan::TulisMutasiLangsung($produk['Stok']->Id, $gudang, '10', '12345.68');
    BantuanPersediaan::TulisMutasiLangsung($produk['Stok']->Id, $gudang, '-3', '-3703.70');
    $gula = BantuanPersediaan::TulisMutasiLangsung($produk['BahanBaku']->Id, $gudang, '25.5', '382500.00');

    $batch = BantuanPersediaan::BuatBatchLangsung($produk['Batch']->Id, $gudang, 'UHT-2026-09-A');
    $susuMasuk = BantuanPersediaan::TulisMutasiLangsung($produk['Batch']->Id, $gudang, '12', '198000.00', ['IdBatchStok' => $batch->Id]);
    BantuanPersediaan::TulisMutasiLangsung($produk['Batch']->Id, $gudang, '-2', '-33000.00', ['IdBatchStok' => $batch->Id]);

    $seri = BantuanPersediaan::BuatNomorSeriLangsung($produk['Seri']->Id, 'RC18-2026-000123', StatusNomorSeri::Tersedia, $gudang);
    $rice = BantuanPersediaan::TulisMutasiLangsung($produk['Seri']->Id, $gudang, '1', '512500.00', ['IdNomorSeri' => $seri->Id]);

    if ($metode === MetodeHpp::Fifo) {
        BantuanPersediaan::BuatLapisanFifoLangsung($minyakMasuk, '7.0000', '8641.98');
        BantuanPersediaan::BuatLapisanFifoLangsung($gula);
        BantuanPersediaan::BuatLapisanFifoLangsung($susuMasuk, '10.0000', '165000.00');
        BantuanPersediaan::BuatLapisanFifoLangsung($rice);
    }

    return ['Tenant' => $t['Tenant'], 'Gudang' => $t['Gudang'], 'Produk' => $produk, 'Batch' => $batch->refresh()];
}

/** Invarian yang dijaga bangun ulang (tanpa jurnal: ledger ditulis langsung). */
function PeriksaInvarianStokBangunUlang(int $idTenant, bool $fifo = false): array
{
    return [
        ...PemeriksaInvarian::PeriksaSaldoStok($idTenant),
        ...PemeriksaInvarian::PeriksaRantaiMutasi($idTenant),
        ...PemeriksaInvarian::PeriksaNilaiNolSaatJumlahNol($idTenant),
        ...($fifo ? PemeriksaInvarian::PeriksaLapisanFifo($idTenant) : []),
        ...PemeriksaInvarian::PeriksaBatch($idTenant),
        ...PemeriksaInvarian::PeriksaNomorSeri($idTenant),
    ];
}

describe('persediaan:bangun-ulang-saldo --periksa (DesainF05a C.9, aturan #9)', function (): void {
    it('BR-05.1: ledger konsisten (MA & FIFO) → tanpa perbedaan, kode keluar 0', function (MetodeHpp $metode): void {
        $l = SiapkanLedgerBangunUlang(metode: $metode);

        expect(PeriksaInvarianStokBangunUlang($l['Tenant']->Id, $metode === MetodeHpp::Fifo))->toBe([])
            ->and(app(PemeriksaKonsistensiStok::class)->Periksa())->toBe([]);

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])
            ->expectsOutputToContain('1 tenant diperiksa, semua konsisten.')
            ->assertSuccessful();
    })->with([MetodeHpp::RataRata, MetodeHpp::Fifo]);

    it('BR-05.1: saldo rusak terdeteksi, kode keluar 1, dan --periksa tidak mengubah apa pun', function (): void {
        $l = SiapkanLedgerBangunUlang();
        $minyak = $l['Produk']['Stok']->Id;
        SaldoStok::query()->where('IdProduk', $minyak)->update(['JumlahTersedia' => '9.0000', 'NilaiPersediaan' => '8000.00']);

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])
            ->expectsOutputToContain("Saldo stok produk {$minyak} di lokasi {$l['Gudang']->Id}: jumlah 9.0000 / nilai 8000.00")
            ->expectsOutputToContain('1 tenant diperiksa, 1 tenant berbeda.')
            ->assertExitCode(1);

        BantuanOrganisasi::AturKonteks($l['Tenant']->Id);
        expect(SaldoStok::query()->where('IdProduk', $minyak)->value('JumlahTersedia'))->toBe('9.0000')
            ->and(LogAudit::query()->where('Peristiwa', 'persediaan.saldo.bangun-ulang')->count())->toBe(0);
    });

    it('melaporkan rantai SaldoSetelah/NilaiSetelah yang putus, Q=0 bernilai, batch & seri yang tidak sesuai', function (): void {
        $l = SiapkanLedgerBangunUlang();
        $id = $l['Tenant']->Id;
        $p = $l['Produk'];
        // Korupsi disimulasikan langsung di SQL (model MutasiStok append-only).
        DB::table('MutasiStok')->where('IdTenant', $id)->where('IdProduk', $p['Stok']->Id)->where('Jumlah', '<', 0)->update(['SaldoSetelah' => '6.0000']);
        DB::table('BatchStok')->where('Id', $l['Batch']->Id)->update(['JumlahSisa' => '12.0000']);
        DB::table('NomorSeri')->where('IdTenant', $id)->update(['Status' => StatusNomorSeri::Terjual->value, 'IdGudang' => null]);
        DB::table('SaldoStok')->where('IdTenant', $id)->where('IdProduk', $p['BahanBaku']->Id)->update(['JumlahTersedia' => '0.0000']);

        $perbedaan = app(PemeriksaKonsistensiStok::class)->Periksa();

        expect(implode("\n", $perbedaan))
            ->toContain('saldo setelah 6.0000 / nilai setelah 8641.98, seharusnya 7.0000 / 8641.98')
            ->toContain("Saldo stok produk {$p['BahanBaku']->Id} di lokasi {$l['Gudang']->Id}: jumlah 0.0000 dengan nilai 382500.00")
            ->toContain('Batch UHT-2026-09-A')
            ->toContain('sisa 12.0000, seharusnya 10.0000')
            ->toContain('Nomor seri RC18-2026-000123')
            ->toContain('status Terjual');
    });

    it('FIFO: Σ sisa lapisan terbuka ≠ saldo dan penanda Habis salah dilaporkan (hanya tenant FIFO)', function (): void {
        $l = SiapkanLedgerBangunUlang(metode: MetodeHpp::Fifo);
        DB::table('LapisanFifo')->where('IdTenant', $l['Tenant']->Id)->where('IdProduk', $l['Produk']['Stok']->Id)->update(['NilaiSisa' => '8600.00']);
        DB::table('LapisanFifo')->where('IdTenant', $l['Tenant']->Id)->where('IdProduk', $l['Produk']['BahanBaku']->Id)->update(['Habis' => true]);

        $perbedaan = implode("\n", app(PemeriksaKonsistensiStok::class)->Periksa());

        expect($perbedaan)->toContain("Lapisan FIFO produk {$l['Produk']['Stok']->Id} di lokasi {$l['Gudang']->Id}: sisa 7.0000 / 8600.00, saldo 7.0000 / 8641.98")
            ->toContain('dengan penanda habis 1 tidak sesuai');

        BantuanPersediaan::AturMetodeHpp($l['Tenant'], MetodeHpp::RataRata);
        expect(implode("\n", app(PemeriksaKonsistensiStok::class)->Periksa()))->not->toContain('Lapisan FIFO');
    });

    it('uraian per pemeriksaan dibatasi supaya laporan malam tidak membanjir', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['Stok'];

        foreach (range(1, PemeriksaKonsistensiStok::BATAS_PER_PEMERIKSAAN + 2) as $i) {
            BantuanPersediaan::TulisMutasiLangsung($produk->Id, $t['Gudang']->Id, '1', '38500.00');
        }

        DB::table('MutasiStok')->where('IdTenant', $t['Tenant']->Id)->update(['SaldoSetelah' => '999.0000']);
        $perbedaan = app(PemeriksaKonsistensiStok::class)->Periksa();

        expect(array_filter($perbedaan, fn (string $u): bool => str_starts_with($u, 'Mutasi stok ')))->toHaveCount(PemeriksaKonsistensiStok::BATAS_PER_PEMERIKSAAN)
            ->and($perbedaan)->toContain('Masih ada perbedaan lain sejenis (hanya '.PemeriksaKonsistensiStok::BATAS_PER_PEMERIKSAAN.' pertama yang ditampilkan).')
            ->and($perbedaan)->toHaveCount(PemeriksaKonsistensiStok::BATAS_PER_PEMERIKSAAN + 1);
    });
});

describe('persediaan:bangun-ulang-saldo (bangun ulang, DesainF05a C.9)', function (): void {
    it('BR-05.1: memperbaiki jumlah, nilai, HPP rata-rata, mutasi terakhir. Membuat baris yang hilang. Menolkan baris yatim. Memperbaiki batch', function (): void {
        $l = SiapkanLedgerBangunUlang();
        $id = $l['Tenant']->Id;
        $p = $l['Produk'];
        $gudang = $l['Gudang']->Id;
        $harapanMinyak = SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->firstOrFail()->only(['JumlahTersedia', 'NilaiPersediaan', 'HppRataRata', 'IdMutasiStokTerakhir']);

        SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->update(['JumlahTersedia' => '9.0000', 'NilaiPersediaan' => '8000.00', 'HppRataRata' => '1.000000', 'IdMutasiStokTerakhir' => null]);
        SaldoStok::query()->where('IdProduk', $p['BahanBaku']->Id)->delete();
        SaldoStok::query()->create(['IdProduk' => $p['Produksi']->Id, 'IdGudang' => $gudang, 'JumlahTersedia' => '4.0000', 'NilaiPersediaan' => '100000.00', 'HppRataRata' => '25000.000000']);
        BatchStok::query()->whereKey($l['Batch']->Id)->update(['JumlahSisa' => '3.0000']);

        expect(PeriksaInvarianStokBangunUlang($id))->not->toBe([]);

        $this->artisan('persediaan:bangun-ulang-saldo')
            ->expectsOutputToContain("Tenant {$id}: 4 saldo stok diperbaiki.")
            ->expectsOutputToContain('1 tenant diproses, 4 saldo stok diperbaiki.')
            ->assertSuccessful();

        BantuanOrganisasi::AturKonteks($id);
        expect(PeriksaInvarianStokBangunUlang($id))->toBe([])
            ->and(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->firstOrFail()->only(['JumlahTersedia', 'NilaiPersediaan', 'HppRataRata', 'IdMutasiStokTerakhir']))->toBe($harapanMinyak)
            ->and(SaldoStok::query()->where('IdProduk', $p['BahanBaku']->Id)->firstOrFail()->only(['JumlahTersedia', 'NilaiPersediaan', 'HppRataRata']))
            ->toBe(['JumlahTersedia' => '25.5000', 'NilaiPersediaan' => '382500.00', 'HppRataRata' => '15000.000000'])
            ->and(SaldoStok::query()->where('IdProduk', $p['Produksi']->Id)->firstOrFail()->only(['JumlahTersedia', 'NilaiPersediaan', 'HppRataRata', 'IdMutasiStokTerakhir']))
            ->toBe(['JumlahTersedia' => '0.0000', 'NilaiPersediaan' => '0.00', 'HppRataRata' => null, 'IdMutasiStokTerakhir' => null])
            ->and(BatchStok::query()->whereKey($l['Batch']->Id)->value('JumlahSisa'))->toBe('10.0000');

        $audit = LogAudit::query()->where('Peristiwa', 'persediaan.saldo.bangun-ulang')->sole();
        expect($audit->IdTenant)->toBe($id)
            ->and($audit->IdPengguna)->toBeNull()
            ->and($audit->NilaiBaru['JumlahDiperbaiki'] ?? null)->toBe(4)
            ->and($audit->NilaiBaru['JumlahPasangan'] ?? null)->toBe(5)
            ->and(collect($audit->NilaiBaru['Rincian'] ?? [])->firstWhere('IdProduk', $p['BahanBaku']->Id)['BarisDibuat'] ?? null)->toBeTrue();

        // Idempoten: jalankan lagi = tidak ada yang diperbaiki, tidak ada audit baru.
        expect(app(PembangunUlangSaldoStok::class)->Jalankan())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'persediaan.saldo.bangun-ulang')->count())->toBe(1);

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])->assertSuccessful();
    });

    it('BR-05.1: stok minus & HPP tidak diketahui dibangun ulang apa adanya dari ledger', function (): void {
        $t = BantuanPersediaan::SiapkanTenant('Kedai Kopi Senja Minus', stokBolehMinus: true);
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['Stok'];
        BantuanPersediaan::TulisMutasiLangsung($produk->Id, $t['Gudang']->Id, '-4', '0.00', ['HppRataRataSetelah' => null]);
        BantuanPersediaan::TulisMutasiLangsung($produk->Id, $t['Gudang']->Id, '10', '6600.00', ['HppRataRataSetelah' => '1100.000000']);
        SaldoStok::query()->update(['JumlahTersedia' => '0.0000', 'NilaiPersediaan' => '0.00', 'HppRataRata' => null]);

        expect(app(PembangunUlangSaldoStok::class)->Jalankan())->toBe(1)
            ->and(SaldoStok::query()->sole()->only(['JumlahTersedia', 'NilaiPersediaan', 'HppRataRata']))
            ->toBe(['JumlahTersedia' => '6.0000', 'NilaiPersediaan' => '6600.00', 'HppRataRata' => '1100.000000'])
            ->and(PeriksaInvarianStokBangunUlang($t['Tenant']->Id))->toBe([]);
    });

    it('perbedaan yang tidak bisa diperbaiki (rantai mutasi) tetap dilaporkan dan kode keluar 1', function (): void {
        $l = SiapkanLedgerBangunUlang();
        DB::table('MutasiStok')->where('IdTenant', $l['Tenant']->Id)->where('IdProduk', $l['Produk']['Stok']->Id)->where('Jumlah', '<', 0)->update(['NilaiSetelah' => '1.00']);

        $this->artisan('persediaan:bangun-ulang-saldo')
            ->expectsOutputToContain("Tenant {$l['Tenant']->Id}: 1 perbedaan yang tidak bisa diperbaiki otomatis.")
            ->assertExitCode(1);
    });

    it('urutan kunci: Tenant (S) → SaldoStok FOR UPDATE → Σ mutasi (S) → BatchStok FOR UPDATE, per pasangan', function (): void {
        $l = SiapkanLedgerBangunUlang();
        BatchStok::query()->whereKey($l['Batch']->Id)->update(['JumlahSisa' => '0.0000']);
        $pernyataan = [];
        DB::listen(function ($kueri) use (&$pernyataan): void {
            $sql = strtolower($kueri->sql);

            if (str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode') || str_contains($sql, 'for share')) {
                $pernyataan[] = $sql;
            }
        });

        app(PembangunUlangSaldoStok::class)->Jalankan();

        $urutan = array_map(fn (string $sql): string => match (true) {
            str_contains($sql, 'from `tenant`') => 'Tenant',
            str_contains($sql, 'from `saldostok`') => 'SaldoStok',
            str_contains($sql, 'from `mutasistok`') => 'MutasiStok',
            str_contains($sql, 'from `batchstok`') => 'BatchStok',
            default => $sql,
        }, $pernyataan);
        $perPasangan = ['Tenant', 'SaldoStok', 'MutasiStok', 'BatchStok'];

        // Batch produk ber-batch: satu kunci tambahan Σ mutasi batch setelah BatchStok.
        expect($urutan)->toBe([...$perPasangan, ...$perPasangan, ...$perPasangan, 'MutasiStok', ...$perPasangan]);
    });
});

describe('persediaan:bangun-ulang-saldo lintas tenant (aturan #11)', function (): void {
    it('isolasi tenant: --tenant hanya memproses tenant itu. Tiap tenant lewat KonteksTenant sendiri. Audit per tenant', function (): void {
        $a = SiapkanLedgerBangunUlang('Toko Kelontong Makmur Sentosa');
        $b = SiapkanLedgerBangunUlang('Warung Bu Tini Jaya');
        foreach ([$a, $b] as $l) {
            DB::table('SaldoStok')->where('IdTenant', $l['Tenant']->Id)->where('IdProduk', $l['Produk']['Stok']->Id)->update(['JumlahTersedia' => '1.0000']);
        }

        $this->artisan('persediaan:bangun-ulang-saldo', ['--tenant' => [(string) $a['Tenant']->Id]])
            ->expectsOutputToContain('1 tenant diproses, 1 saldo stok diperbaiki.')
            ->assertSuccessful();

        expect(PeriksaInvarianStokBangunUlang($a['Tenant']->Id))->toBe([])
            ->and(PeriksaInvarianStokBangunUlang($b['Tenant']->Id))->not->toBe([])
            ->and(DB::table('LogAudit')->where('Peristiwa', 'persediaan.saldo.bangun-ulang')->pluck('IdTenant')->all())->toBe([$a['Tenant']->Id]);

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])
            ->expectsOutputToContain('2 tenant diperiksa, 1 tenant berbeda.')
            ->assertExitCode(1);

        $this->artisan('persediaan:bangun-ulang-saldo')->expectsOutputToContain('2 tenant diproses, 1 saldo stok diperbaiki.')->assertSuccessful();

        expect(PeriksaInvarianStokBangunUlang($b['Tenant']->Id))->toBe([])
            ->and(DB::table('LogAudit')->where('Peristiwa', 'persediaan.saldo.bangun-ulang')->orderBy('Id')->pluck('IdTenant')->all())->toBe([$a['Tenant']->Id, $b['Tenant']->Id]);
    });

    it('--tenant tidak valid ditolak. Tenant tak dikenal diperingatkan. Konteks tenant pemanggil dipulihkan', function (): void {
        $l = SiapkanLedgerBangunUlang();

        $this->artisan('persediaan:bangun-ulang-saldo', ['--tenant' => ['abc']])->expectsOutputToContain('Id tenant tidak valid: abc')->assertExitCode(1);
        $this->artisan('persediaan:bangun-ulang-saldo', ['--tenant' => ['999999', (string) $l['Tenant']->Id], '--periksa' => true])
            ->expectsOutputToContain('Tenant 999999 tidak ditemukan.')
            ->expectsOutputToContain('1 tenant diperiksa, semua konsisten.')
            ->assertSuccessful();

        expect(SaldoStok::query()->count())->toBe(4);
    });

    it('dijadwalkan tiap malam dalam mode --periksa', function (): void {
        $acara = collect(app(Schedule::class)->events())
            ->first(fn (Event $e): bool => str_contains((string) $e->command, 'persediaan:bangun-ulang-saldo'));

        expect($acara)->not->toBeNull()
            ->and((string) $acara?->command)->toContain('--periksa')
            ->and($acara?->expression)->toBe('30 2 * * *')
            ->and($acara?->timezone)->toBe('Asia/Jakarta');
    });
});
