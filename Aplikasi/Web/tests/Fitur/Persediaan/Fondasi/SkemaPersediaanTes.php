<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Dokumen\Model\NomorUrutDokumen;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Katalog\Kontrak\PemeriksaPemakaianProduk;
use App\Domain\Katalog\Kontrak\PemeriksaRiwayatStok;
use App\Domain\Katalog\Kontrak\PenyediaHppBahan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Kueri\HppBahanDariSaldo;
use App\Domain\Persediaan\Kueri\PemakaianProdukDiPersediaan;
use App\Domain\Persediaan\Kueri\RiwayatStokProduk;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\StokAwalDetail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Tabel F-05a (DesainF05a B.1). */
const TABEL_F05A = [
    'NomorUrutDokumen', 'RiwayatStatusDokumen', 'KunciPeriode', 'Jurnal', 'JurnalDetail', 'BatchStok', 'NomorSeri',
    'MutasiStok', 'SaldoStok', 'LapisanFifo', 'ImporStokAwal', 'ImporStokAwalBaris', 'StokAwal', 'StokAwalDetail',
];

/**
 * Satu baris MutasiStok mentah (prasyarat test skema; mesin buku stok = Tim A).
 *
 * @param  array<string, mixed>  $timpa
 */
function BuatMutasiMentahSkema(int $idProduk, int $idGudang, string $jumlah, string $totalHpp, string $kunciBaris, array $timpa = []): MutasiStok
{
    return MutasiStok::query()->create(array_replace([
        'IdProduk' => $idProduk,
        'IdGudang' => $idGudang,
        'JenisMutasi' => JenisMutasi::StokAwal,
        'Jumlah' => $jumlah,
        'HppSatuan' => '1234.568000',
        'TotalHpp' => $totalHpp,
        'SelisihHpp' => '0.00',
        'SaldoSetelah' => $jumlah,
        'NilaiSetelah' => $totalHpp,
        'HppRataRataSetelah' => '1234.568000',
        'JenisReferensi' => JenisReferensiMutasi::StokAwal,
        'IdReferensi' => 1,
        'KunciBaris' => $kunciBaris,
        'TanggalBisnis' => '2026-09-24',
    ], $timpa));
}

describe('F-05a skema persediaan (DesainF05a B.1–B.3)', function (): void {
    it('tipe kolom: uang decimal(18,2), HPP per unit decimal(19,6), jumlah decimal(18,4), tanpa float/double', function (): void {
        $harapan = [
            'MutasiStok' => ['Jumlah' => 'decimal(18,4)', 'HppSatuan' => 'decimal(19,6)', 'TotalHpp' => 'decimal(18,2)', 'SelisihHpp' => 'decimal(18,2)',
                'SaldoSetelah' => 'decimal(18,4)', 'NilaiSetelah' => 'decimal(18,2)', 'HppRataRataSetelah' => 'decimal(19,6)'],
            'SaldoStok' => ['JumlahTersedia' => 'decimal(18,4)', 'JumlahDipesan' => 'decimal(18,4)', 'NilaiPersediaan' => 'decimal(18,2)', 'HppRataRata' => 'decimal(19,6)'],
            'LapisanFifo' => ['JumlahAwal' => 'decimal(18,4)', 'JumlahSisa' => 'decimal(18,4)', 'HppSatuan' => 'decimal(19,6)', 'NilaiAwal' => 'decimal(18,2)', 'NilaiSisa' => 'decimal(18,2)'],
            'BatchStok' => ['JumlahSisa' => 'decimal(18,4)', 'HppSatuan' => 'decimal(19,6)'],
            'StokAwal' => ['TotalNilai' => 'decimal(18,2)'],
            'StokAwalDetail' => ['Jumlah' => 'decimal(18,4)', 'HppSatuan' => 'decimal(19,6)', 'Nilai' => 'decimal(18,2)'],
            'Jurnal' => ['TotalDebit' => 'decimal(18,2)', 'TotalKredit' => 'decimal(18,2)'],
            'JurnalDetail' => ['Debit' => 'decimal(18,2)', 'Kredit' => 'decimal(18,2)'],
        ];

        foreach ($harapan as $tabel => $kolom) {
            foreach ($kolom as $nama => $tipe) {
                $aktual = DB::selectOne('SELECT COLUMN_TYPE AS Tipe FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$tabel, $nama]);
                expect($aktual?->Tipe)->toBe($tipe, "{$tabel}.{$nama}");
            }
        }

        $melayang = DB::select("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('float', 'double', 'real') AND TABLE_NAME IN (".implode(',', array_fill(0, count(TABEL_F05A), '?')).')', TABEL_F05A);
        expect($melayang)->toBe([]);
    });

    it('setiap tabel tenant ber-IdTenant ber-FK. Semua indeks sekunder diawali IdTenant. Nama constraint eksplisit ≤ 64', function (): void {
        $daftar = implode(',', array_fill(0, count(TABEL_F05A), '?'));

        foreach (TABEL_F05A as $tabel) {
            $fk = DB::selectOne("SELECT CONSTRAINT_NAME AS Nama FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'IdTenant' AND REFERENCED_TABLE_NAME = 'Tenant'", [$tabel]);
            expect($fk?->Nama)->toBe("Fk{$tabel}IdTenant");
        }

        $indeks = DB::select("SELECT TABLE_NAME AS Tabel, INDEX_NAME AS Nama, COLUMN_NAME AS Kolom FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND SEQ_IN_INDEX = 1 AND INDEX_NAME <> 'PRIMARY' AND (INDEX_NAME LIKE 'Idx%' OR INDEX_NAME LIKE 'Uniq%') AND TABLE_NAME IN ({$daftar})", TABEL_F05A);
        expect($indeks)->not->toBe([]);

        foreach ($indeks as $baris) {
            // Pengecualian: indeks unik Uuid publik (macro UuidPublik) berlaku global.
            if ($baris->Nama === "Uniq{$baris->Tabel}Uuid") {
                continue;
            }

            expect($baris->Kolom)->toBe('IdTenant', "{$baris->Tabel}.{$baris->Nama}");
        }

        // Hanya kunci asing & unik yang kita namai sendiri. MariaDB menambah CHECK `json_valid` otomatis dengan nama kolom
        // (kolom JSON = LONGTEXT di sana); MySQL tidak punya CHECK semacam itu dan skema ini tidak mendefinisikan CHECK sendiri.
        $nama = DB::select("SELECT CONSTRAINT_NAME AS Nama FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE IN ('FOREIGN KEY', 'UNIQUE') AND TABLE_NAME IN ({$daftar})", TABEL_F05A);

        foreach ($nama as $baris) {
            expect(strlen($baris->Nama))->toBeLessThanOrEqual(64)
                ->and($baris->Nama)->toMatch('/^(Fk|Uniq|Idx)[A-Z]/');
        }
    });

    it('indeks kunci desain ada: idempotensi mutasi, kartu stok per pasangan, saldo unik, nomor urut dengan kolom tersimpan', function (): void {
        $indeks = collect(DB::select('SELECT INDEX_NAME AS Nama FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() GROUP BY INDEX_NAME'))->pluck('Nama')->all();

        expect($indeks)->toContain(
            'UniqMutasiStokIdTenantJenisReferensiIdReferensiKunciBaris',
            'IdxMutasiStokIdTenantIdProdukIdGudangId',
            'UniqSaldoStokIdTenantIdProdukIdGudang',
            'IdxSaldoStokIdTenantDiubahPada',
            'UniqNomorUrutDokumenIdTenantJenisPeriodeKunci',
            'UniqLapisanFifoIdTenantIdMutasiSumber',
            'UniqStokAwalDetailIdTenantIdStokAwalIdProdukKunciBatch',
            'UniqBatchStokIdTenantIdProdukIdGudangNomorBatch',
            'UniqNomorSeriIdTenantIdProdukNomor',
        );
    });
});

describe('F-05a model persediaan (DesainF05a B.3)', function (): void {
    it('BR-05.1: MutasiStok append-only (ubah/hapus lewat model melempar) dan idempoten per (JenisReferensi, IdReferensi, KunciBaris)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['Stok'];
        $mutasi = BuatMutasiMentahSkema($produk->Id, $t['Gudang']->Id, '10.0000', '12345.68', 'P/1');

        expect($mutasi->refresh()->Jumlah)->toBe('10.0000')
            ->and($mutasi->TotalHpp)->toBe('12345.68')
            ->and($mutasi->JenisMutasi)->toBe(JenisMutasi::StokAwal)
            ->and($mutasi->IdTenant)->toBe($t['Tenant']->Id);

        expect(fn () => $mutasi->update(['Jumlah' => '11.0000']))->toThrow(LogicException::class, MutasiStok::PESAN_TIDAK_BISA_DIUBAH)
            ->and(fn () => $mutasi->delete())->toThrow(LogicException::class, MutasiStok::PESAN_TIDAK_BISA_DIUBAH)
            ->and(fn () => BuatMutasiMentahSkema($produk->Id, $t['Gudang']->Id, '1.0000', '1234.57', 'P/1'))->toThrow(UniqueConstraintViolationException::class);
    });

    it('isolasi tenant: data persediaan tenant A tidak terlihat dari tenant B (MilikTenant)', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Kelontong Makmur Sentosa');
        $produkA = BantuanPersediaan::BuatProdukSemuaJenis($a['Pcs'], $a['Kg'])['Stok'];
        BuatMutasiMentahSkema($produkA->Id, $a['Gudang']->Id, '5.0000', '5000.00', 'P/1');
        SaldoStok::query()->create(['IdProduk' => $produkA->Id, 'IdGudang' => $a['Gudang']->Id, 'JumlahTersedia' => '5.0000', 'NilaiPersediaan' => '5000.00']);
        StokAwal::query()->create(['IdGudang' => $a['Gudang']->Id, 'Tanggal' => '2026-09-24', 'Status' => StatusStokAwal::Draf]);

        BantuanPersediaan::SiapkanTenant('Warung Bu Tini Jaya');

        expect(MutasiStok::query()->count())->toBe(0)
            ->and(SaldoStok::query()->count())->toBe(0)
            ->and(StokAwal::query()->count())->toBe(0);

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(MutasiStok::query()->count())->toBe(1)
            ->and(StokAwal::query()->count())->toBe(1);
    });

    it('StokAwalDetail: satu baris per (produk, batch) per dokumen, termasuk produk tanpa batch (KunciBatch tersimpan)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $stokAwal = StokAwal::query()->create(['IdGudang' => $t['Gudang']->Id, 'IdOutlet' => $t['Outlet']->Id, 'Tanggal' => '2026-09-24']);
        $baris = fn (int $urutan, int $idProduk, ?string $batch): StokAwalDetail => StokAwalDetail::query()->create([
            'IdStokAwal' => $stokAwal->Id, 'Urutan' => $urutan, 'IdProduk' => $idProduk, 'NamaProduk' => 'Produk uji', 'Jumlah' => '1.0000',
            'HppSatuan' => '1000.000000', 'Nilai' => '1000.00', 'NomorBatch' => $batch,
        ]);

        $baris(1, $produk['Stok']->Id, null);
        $baris(2, $produk['Batch']->Id, 'B-2026-09-A');
        $baris(3, $produk['Batch']->Id, 'B-2026-09-B');

        expect($stokAwal->refresh()->Status)->toBe(StatusStokAwal::Draf)
            ->and($stokAwal->Detail()->count())->toBe(3)
            ->and(StokAwalDetail::query()->where('IdProduk', $produk['Stok']->Id)->value('KunciBatch'))->toBe('')
            ->and(fn () => $baris(4, $produk['Stok']->Id, null))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => $baris(5, $produk['Batch']->Id, 'B-2026-09-A'))->toThrow(UniqueConstraintViolationException::class);
    });

    it('StokAwal::UbahStatus hanya mengikuti BisaBerubahKe', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $stokAwal = StokAwal::query()->create(['IdGudang' => $t['Gudang']->Id, 'Tanggal' => '2026-09-24']);

        $stokAwal->UbahStatus(StatusStokAwal::Diposting);
        expect($stokAwal->Status)->toBe(StatusStokAwal::Diposting)
            ->and(fn () => $stokAwal->UbahStatus(StatusStokAwal::Draf))->toThrow(LogicException::class);
    });
});

describe('F-05a layanan dokumen bersama (DesainF05a C.1)', function (): void {
    it('PenomorDokumen: berurutan tanpa celah per jenis & periode, terpisah per outlet/perangkat, batal ikut transaksi', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $penomor = app(PenomorDokumen::class);

        expect($penomor->AmbilBerikutnya(JenisDokumenBernomor::StokAwal, '2026-09'))->toBe(1)
            ->and($penomor->AmbilBerikutnya(JenisDokumenBernomor::StokAwal, '2026-09'))->toBe(2)
            ->and($penomor->AmbilBerikutnya(JenisDokumenBernomor::StokAwal, '2026-10'))->toBe(1)
            ->and($penomor->AmbilBerikutnya(JenisDokumenBernomor::Jurnal, '2026-09'))->toBe(1)
            ->and($penomor->AmbilBerikutnya(JenisDokumenBernomor::StokAwal, '2026-09', $t['Outlet']->Id))->toBe(1)
            ->and($penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::StokAwal, '2026-09'))->toBe('SA/2026/09/0003');

        try {
            DB::transaction(function () use ($penomor): void {
                $penomor->AmbilBerikutnya(JenisDokumenBernomor::StokAwal, '2026-09');

                throw new RuntimeException('posting gagal');
            });
        } catch (RuntimeException) {
            // Nomor yang diambil ikut dibatalkan.
        }

        expect($penomor->AmbilBerikutnya(JenisDokumenBernomor::StokAwal, '2026-09'))->toBe(4)
            ->and(NomorUrutDokumen::query()->count())->toBe(4)
            ->and(fn () => $penomor->AmbilBerikutnya(JenisDokumenBernomor::StokAwal, '2026-13'))->toThrow(InvalidArgumentException::class);

        BantuanPersediaan::SiapkanTenant('Toko Lain Sejahtera');
        expect(app(PenomorDokumen::class)->AmbilBerikutnya(JenisDokumenBernomor::StokAwal, '2026-09'))->toBe(1);
    });

    it('PencatatRiwayatStatus menulis riwayat append-only milik tenant', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $stokAwal = StokAwal::query()->create(['IdGudang' => $t['Gudang']->Id, 'Tanggal' => '2026-09-24']);

        app(PencatatRiwayatStatus::class)->Catat(StokAwal::JENIS_DOKUMEN, $stokAwal->Id, 'Diposting', 'Dibatalkan', $t['Pemilik']->Id, 'Salah input jumlah minyak goreng');
        $riwayat = RiwayatStatusDokumen::query()->sole();

        expect($riwayat->IdTenant)->toBe($t['Tenant']->Id)
            ->and($riwayat->StatusDari)->toBe('Diposting')
            ->and($riwayat->StatusKe)->toBe('Dibatalkan')
            ->and($riwayat->DiubahOleh)->toBe($t['Pemilik']->Id)
            ->and($riwayat->DiubahPada)->not->toBeNull()
            ->and(fn () => $riwayat->update(['Alasan' => 'ubah']))->toThrow(LogicException::class)
            ->and(fn () => $riwayat->delete())->toThrow(LogicException::class);
    });
});

describe('F-05a izin, rute, dan ikatan provider (DesainF05a B.5, D, G)', function (): void {
    it('izin persediaan.stok-awal.posting: Pemilik, Admin, Manajer Outlet, Akuntan; Staf Gudang hanya draf', function (): void {
        $punya = fn (PeranTenantBawaan $peran): bool => in_array(IzinTenant::PersediaanStokAwalPosting, $peran->AmbilIzin(), true);

        expect(IzinTenant::PersediaanStokAwalPosting->value)->toBe('persediaan.stok-awal.posting')
            ->and(IzinTenant::PersediaanStokAwalPosting->AmbilKelompok())->toBe('Persediaan & pembelian')
            ->and($punya(PeranTenantBawaan::Pemilik))->toBeTrue()
            ->and($punya(PeranTenantBawaan::Admin))->toBeTrue()
            ->and($punya(PeranTenantBawaan::ManajerOutlet))->toBeTrue()
            ->and($punya(PeranTenantBawaan::Akuntan))->toBeTrue()
            ->and($punya(PeranTenantBawaan::StafGudang))->toBeFalse()
            ->and(in_array(IzinTenant::PersediaanKelola, PeranTenantBawaan::StafGudang->AmbilIzin(), true))->toBeTrue()
            ->and($punya(PeranTenantBawaan::Kasir))->toBeFalse();
    });

    it('semua rute F-05a terdaftar dengan nama desain', function (): void {
        $nama = [
            'kelola.persediaan.stok-awal.daftar', 'kelola.persediaan.stok-awal.buat', 'kelola.persediaan.stok-awal.simpan',
            'kelola.persediaan.stok-awal.detail', 'kelola.persediaan.stok-awal.ubah', 'kelola.persediaan.stok-awal.perbarui',
            'kelola.persediaan.stok-awal.buang', 'kelola.persediaan.stok-awal.posting', 'kelola.persediaan.stok-awal.batalkan',
            'kelola.persediaan.stok-awal.status', 'kelola.persediaan.produk.cari', 'kelola.persediaan.saldo',
            'kelola.persediaan.kartu-stok', 'kelola.persediaan.pengaturan', 'kelola.persediaan.pengaturan.simpan',
            'kelola.persediaan.stok-awal.impor.daftar', 'kelola.persediaan.stok-awal.impor.templat', 'kelola.persediaan.stok-awal.impor.unggah',
            'kelola.persediaan.stok-awal.impor.detail', 'kelola.persediaan.stok-awal.impor.status', 'kelola.persediaan.stok-awal.impor.pemetaan.simpan',
            'kelola.persediaan.stok-awal.impor.terapkan', 'kelola.persediaan.stok-awal.impor.lanjutkan', 'kelola.persediaan.stok-awal.impor.batalkan',
            'kelola.persediaan.stok-awal.impor.laporan', 'kelola.akuntansi.jurnal.daftar', 'kelola.akuntansi.jurnal.detail',
        ];

        foreach ($nama as $rute) {
            expect(Route::has($rute))->toBeTrue($rute);
        }

        expect(route('kelola.persediaan.stok-awal.impor.daftar', absolute: false))->toBe('/kelola/persediaan/stok-awal/impor')
            ->and(Route::getRoutes()->getByName('kelola.persediaan.stok-awal.posting')?->gatherMiddleware())
            ->toContain('App\Http\Perantara\WajibIzinTenant:persediaan.stok-awal.posting');
    });

    it('provider: PemeriksaRiwayatStok, PenyediaHppBahan, dan pemeriksa pemakaian produk diikat ke Persediaan (Tim F)', function (): void {
        expect(app(PemeriksaRiwayatStok::class))->toBeInstanceOf(RiwayatStokProduk::class)
            ->and(app(PenyediaHppBahan::class))->toBeInstanceOf(HppBahanDariSaldo::class);

        $kelas = array_map(fn (object $pemeriksa): string => $pemeriksa::class, iterator_to_array(app()->tagged(PemeriksaPemakaianProduk::TAG), false));
        expect($kelas)->toContain(PemakaianProdukDiPersediaan::class);
    });
});

describe('F-05a pemeriksa invarian (DesainF05a F, aturan #17)', function (): void {
    it('tenant tanpa mutasi = konsisten. Saldo rusak, rantai putus, dan Q=0 bernilai terdeteksi', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $idTenant = $t['Tenant']->Id;
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['Stok'];

        expect(PemeriksaInvarian::PeriksaSemua($idTenant, fifo: true))->toBe([]);

        BuatMutasiMentahSkema($produk->Id, $t['Gudang']->Id, '10.0000', '12345.68', 'P/1');
        BuatMutasiMentahSkema($produk->Id, $t['Gudang']->Id, '-3.0000', '-3703.70', 'P/2', ['SaldoSetelah' => '7.0000', 'NilaiSetelah' => '8641.98']);
        SaldoStok::query()->create(['IdProduk' => $produk->Id, 'IdGudang' => $t['Gudang']->Id, 'JumlahTersedia' => '7.0000', 'NilaiPersediaan' => '8641.98']);

        expect(PemeriksaInvarian::PeriksaSaldoStok($idTenant))->toBe([])
            ->and(PemeriksaInvarian::PeriksaRantaiMutasi($idTenant))->toBe([]);

        // Rusak: saldo cache tidak sama dengan Σ ledger.
        DB::table('SaldoStok')->where('IdTenant', $idTenant)->update(['JumlahTersedia' => '0.0000']);
        expect(PemeriksaInvarian::PeriksaSaldoStok($idTenant))->toHaveCount(1)
            ->and(PemeriksaInvarian::PeriksaNilaiNolSaatJumlahNol($idTenant))->toHaveCount(1);

        // Rusak: rantai SaldoSetelah putus.
        BuatMutasiMentahSkema($produk->Id, $t['Gudang']->Id, '1.0000', '1234.57', 'P/3', ['SaldoSetelah' => '1.0000', 'NilaiSetelah' => '1234.57']);
        expect(PemeriksaInvarian::PeriksaRantaiMutasi($idTenant))->toHaveCount(1);

        // Tenant lain tidak ikut diperiksa.
        $lain = BantuanPersediaan::SiapkanTenant('Toko Bangunan Sumber Rejeki');
        expect(PemeriksaInvarian::PeriksaSemua($lain['Tenant']->Id, fifo: true))->toBe([]);
    });

    it('jurnal tidak seimbang dan saldo akun persediaan ≠ nilai stok terdeteksi (SQL mentah)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $idTenant = $t['Tenant']->Id;
        $idAkun = (int) DB::table('PemetaanAkun')->where('IdTenant', $idTenant)->where('Kunci', 'PersediaanBarangDagang')->value('IdAkun');
        $idEkuitas = (int) DB::table('PemetaanAkun')->where('IdTenant', $idTenant)->where('Kunci', 'EkuitasSaldoAwal')->value('IdAkun');
        $idJurnal = DB::table('Jurnal')->insertGetId([
            'Uuid' => '01K5ZQ8X6T2N3M4P5Q6R7S8T9V', 'IdTenant' => $idTenant, 'Nomor' => 'JU/2026/09/000001', 'Tanggal' => '2026-09-24',
            'Periode' => '2026-09', 'JenisSumber' => 'StokAwal', 'IdSumber' => 1, 'Keterangan' => 'Stok awal SA/2026/09/0001',
            'TotalDebit' => '12345.68', 'TotalKredit' => '12345.68',
        ]);
        $baris = fn (int $urutan, int $akun, string $debit, string $kredit) => DB::table('JurnalDetail')->insert([
            'IdTenant' => $idTenant, 'IdJurnal' => $idJurnal, 'Urutan' => $urutan, 'IdAkun' => $akun, 'Debit' => $debit, 'Kredit' => $kredit, 'Tanggal' => '2026-09-24',
        ]);
        $baris(1, $idAkun, '12345.68', '0.00');
        $baris(2, $idEkuitas, '0.00', '12345.67');

        expect(PemeriksaInvarian::PeriksaJurnalSeimbang($idTenant))->toHaveCount(2)
            ->and(PemeriksaInvarian::PeriksaAkunPersediaan($idTenant))->toHaveCount(1);
    });
});
