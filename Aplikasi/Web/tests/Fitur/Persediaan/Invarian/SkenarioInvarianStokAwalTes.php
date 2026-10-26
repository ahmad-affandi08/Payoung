<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Persediaan\Aksi\BatalkanStokAwal;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Aksi\PostingStokAwal;
use App\Domain\Persediaan\Aksi\SimpanStokAwal;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBarisStokAwal;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\DataStokAwal;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\LapisanFifo;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokAwal;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Baris stok awal: [produk, jumlah, hpp satuan, nomor batch, kedaluwarsa, nomor seri].
 *
 * @param  list<array{0: Produk, 1: string, 2: string, 3?: string|null, 4?: string|null, 5?: list<string>}>  $baris
 */
function SimpanDanPostingStokAwalInvarian(Gudang $gudang, array $baris, int $idPengguna, string $catatan = 'Stok awal hasil hitung fisik akhir bulan'): StokAwal
{
    $tanggal = app(TanggalBisnisOutlet::class)->Hitung($gudang->IdOutlet);
    $data = new DataStokAwal(
        uuid: null,
        idGudang: $gudang->Id,
        tanggal: $tanggal,
        catatan: $catatan,
        baris: array_map(fn (array $b): DataBarisStokAwal => new DataBarisStokAwal(
            idProduk: $b[0]->Id,
            jumlah: Kuantitas::Dari($b[1]),
            hppSatuan: BigDecimal::of($b[2]),
            nomorBatch: $b[3] ?? null,
            tanggalKedaluwarsa: isset($b[4]) ? CarbonImmutable::parse($b[4]) : null,
            nomorSeri: $b[5] ?? [],
        ), $baris),
    );

    $draf = app(SimpanStokAwal::class)->Jalankan($data, null);

    return app(PostingStokAwal::class)->Jalankan($draf, $idPengguna)->refresh();
}

/** Keluar lewat buku stok (penjualan F-07 masa depan; tanpa jurnal, jadi hanya invarian stok yang diperiksa). */
function JualLewatBukuInvarian(Produk $produk, Gudang $gudang, string $jumlah, int $idReferensi): void
{
    app(CatatMutasiStok::class)->Jalankan(new DataDokumenMutasi(
        jenisReferensi: JenisReferensiMutasi::Penjualan,
        idReferensi: $idReferensi,
        uuidReferensi: null,
        nomorReferensi: "PJ/UJI/{$idReferensi}",
        tanggalBisnis: app(TanggalBisnisOutlet::class)->Hitung($gudang->IdOutlet),
        idPengguna: null,
        idPerangkat: null,
        baris: [new DataBarisMutasi(
            kunciBaris: 'J/1',
            idProduk: $produk->Id,
            idGudang: $gudang->Id,
            jenisMutasi: JenisMutasi::Penjualan,
            jumlah: Kuantitas::Dari($jumlah)->Negasi(),
            modeNilai: ModeNilaiMutasi::Berjalan,
        )],
    ));
}

/** Invarian stok tanpa pemeriksaan akun persediaan (untuk mutasi yang jurnalnya milik flow lain). */
function PeriksaInvarianStokSaja(int $idTenant, bool $fifo): array
{
    return [
        ...PemeriksaInvarian::PeriksaSaldoStok($idTenant),
        ...PemeriksaInvarian::PeriksaRantaiMutasi($idTenant),
        ...PemeriksaInvarian::PeriksaNilaiNolSaatJumlahNol($idTenant),
        ...($fifo ? PemeriksaInvarian::PeriksaLapisanFifo($idTenant) : []),
        ...PemeriksaInvarian::PeriksaBatch($idTenant),
        ...PemeriksaInvarian::PeriksaNomorSeri($idTenant),
        ...PemeriksaInvarian::PeriksaJurnalSeimbang($idTenant),
    ];
}

describe('F-05a invarian end-to-end: stok awal → batal → posting lagi (aturan #9, #17)', function (): void {
    it('BR-05.1 J-05.1: MA & FIFO, produk biasa/bahan baku/produksi/batch/seri. Setiap langkah Σ debit = Σ kredit dan SaldoStok = Σ MutasiStok', function (MetodeHpp $metode): void {
        $fifo = $metode === MetodeHpp::Fifo;
        $t = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya', $metode);
        $id = $t['Tenant']->Id;
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $idPengguna = $t['Pemilik']->Id;

        // 1. Posting: nilai baris = Nilai(jumlah, hpp) dibulatkan 2 desimal; seri dialokasikan 512500.33, .33, .34.
        $pertama = SimpanDanPostingStokAwalInvarian($t['Gudang'], [
            [$p['Stok'], '10', '1234.5678'],
            [$p['BahanBaku'], '25.5', '15250.123456'],
            [$p['Produksi'], '40', '8333.333333'],
            [$p['Batch'], '24', '17850', 'UHT-2609-A', '2027-03-31'],
            [$p['Seri'], '3', '512500.333333', null, null, ['RC18-2026-000121', 'RC18-2026-000122', 'RC18-2026-000123']],
        ], $idPengguna);

        expect($pertama->Status)->toBe(StatusStokAwal::Diposting)
            ->and($pertama->TotalNilai)->toBe('2700458.16')
            ->and(PemeriksaInvarian::PeriksaSemua($id, $fifo))->toBe([])
            ->and(Jurnal::query()->count())->toBe(1)
            ->and(Jurnal::query()->sole()->TotalDebit)->toBe('2700458.16')
            ->and(SaldoStok::query()->sum('NilaiPersediaan'))->toEqual('2700458.16')
            ->and(MutasiStok::query()->where('IdProduk', $p['Seri']->Id)->orderBy('Id')->pluck('TotalHpp')->all())->toBe(['512500.33', '512500.33', '512500.34'])
            ->and(NomorSeri::query()->where('Status', StatusNomorSeri::Tersedia)->count())->toBe(3)
            ->and(BatchStok::query()->sole()->JumlahSisa)->toBe('24.0000')
            ->and(LapisanFifo::query()->where('Habis', false)->count())->toBe($fifo ? 7 : 0);

        // Bahan baku ke PersediaanBahanBaku, sisanya ke PersediaanBarangDagang; kredit EkuitasSaldoAwal.
        $perPeran = HitungDebitKreditPerPeran($pertama->IdJurnal);
        expect($perPeran)->toBe([
            'EkuitasSaldoAwal' => ['0.00', '2700458.16'],
            'PersediaanBahanBaku' => ['388878.15', '0.00'],
            'PersediaanBarangDagang' => ['2311580.01', '0.00'],
        ]);

        // 2. Batal: stok & nilai kembali nol, jurnal pembalik tercermin persis.
        $batal = app(BatalkanStokAwal::class)->Jalankan($pertama, 'Salah hitung stok gudang depan', $idPengguna)->refresh();

        expect($batal->Status)->toBe(StatusStokAwal::Dibatalkan)
            ->and(PemeriksaInvarian::PeriksaSemua($id, $fifo))->toBe([])
            ->and(SaldoStok::query()->where(fn ($q) => $q->where('JumlahTersedia', '<>', 0)->orWhere('NilaiPersediaan', '<>', 0))->count())->toBe(0)
            ->and(Jurnal::query()->whereKey($batal->IdJurnalPembatalan)->value('IdJurnalDibalik'))->toBe($pertama->IdJurnal)
            ->and(HitungDebitKreditPerPeran($batal->IdJurnalPembatalan))->toBe([
                'EkuitasSaldoAwal' => ['2700458.16', '0.00'],
                'PersediaanBahanBaku' => ['0.00', '388878.15'],
                'PersediaanBarangDagang' => ['0.00', '2311580.01'],
            ])
            ->and(NomorSeri::query()->where('Status', StatusNomorSeri::Tersedia)->count())->toBe(0)
            ->and(BatchStok::query()->sole()->JumlahSisa)->toBe('0.0000')
            ->and(LapisanFifo::query()->where('Habis', false)->count())->toBe(0);

        // 3. Posting lagi (jumlah berbeda, nomor seri yang sama diaktifkan kembali).
        $kedua = SimpanDanPostingStokAwalInvarian($t['Gudang'], [
            [$p['Stok'], '12', '1250'],
            [$p['BahanBaku'], '20.25', '15000'],
            [$p['Batch'], '18', '17850', 'UHT-2609-A', '2027-03-31'],
            [$p['Seri'], '2', '500000', null, null, ['RC18-2026-000121', 'RC18-2026-000123']],
        ], $idPengguna, 'Stok awal ulang setelah hitung ulang');

        expect($kedua->Status)->toBe(StatusStokAwal::Diposting)
            ->and($kedua->TotalNilai)->toBe('1640050.00')
            ->and(PemeriksaInvarian::PeriksaSemua($id, $fifo))->toBe([])
            ->and(Jurnal::query()->count())->toBe(3)
            ->and(StokAwal::query()->where('Status', StatusStokAwal::Diposting)->count())->toBe(1)
            ->and(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->sole()->only(['JumlahTersedia', 'NilaiPersediaan']))
            ->toBe(['JumlahTersedia' => '12.0000', 'NilaiPersediaan' => '15000.00'])
            ->and(NomorSeri::query()->where('Status', StatusNomorSeri::Tersedia)->orderBy('Nomor')->pluck('Nomor')->all())->toBe(['RC18-2026-000121', 'RC18-2026-000123']);

        // 4. Stok terpakai (penjualan lewat buku stok): pembatalan ditolak dan tidak meninggalkan jejak apa pun.
        JualLewatBukuInvarian($p['Stok'], $t['Gudang'], '5', 9001);
        $jumlahMutasi = MutasiStok::query()->count();

        $galat = null;

        try {
            app(BatalkanStokAwal::class)->Jalankan($kedua->refresh(), 'Coba batal setelah terjual', $idPengguna);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e->kode;
        }

        expect($galat)->toBeIn($fifo ? ['StokSudahTerpakai', 'LapisanSudahTerpakai'] : ['StokSudahTerpakai'])
            ->and(MutasiStok::query()->count())->toBe($jumlahMutasi)
            ->and(Jurnal::query()->count())->toBe(3)
            ->and($kedua->refresh()->Status)->toBe(StatusStokAwal::Diposting)
            ->and(PeriksaInvarianStokSaja($id, $fifo))->toBe([]);

        // Pemeriksa aplikasi (perintah malam) sepakat dengan pemeriksa invarian independen atas data mesin buku stok.
        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])->assertSuccessful();
    })->with([MetodeHpp::RataRata, MetodeHpp::Fifo]);

    it('BR-04.3 H-16: stok awal setelah stok minus (terjual sebelum stok diisi) → selisih ke SelisihHpp, jurnal tetap seimbang', function (MetodeHpp $metode): void {
        $t = BantuanPersediaan::SiapkanTenant('Kedai Kopi Senja Utama', $metode, stokBolehMinus: true);
        $id = $t['Tenant']->Id;
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);

        JualLewatBukuInvarian($p['Stok'], $t['Gudang'], '4', 9101);
        $stokAwal = SimpanDanPostingStokAwalInvarian($t['Gudang'], [[$p['Stok'], '10', '1100']], $t['Pemilik']->Id);

        expect(SaldoStok::query()->sole()->only(['JumlahTersedia', 'NilaiPersediaan']))->toBe(['JumlahTersedia' => '6.0000', 'NilaiPersediaan' => '6600.00'])
            ->and(PemeriksaInvarian::PeriksaSemua($id, $metode === MetodeHpp::Fifo))->toBe([])
            ->and(HitungDebitKreditPerPeran($stokAwal->IdJurnal))->toBe([
                'EkuitasSaldoAwal' => ['0.00', '11000.00'],
                'PersediaanBarangDagang' => ['6600.00', '0.00'],
                'SelisihHpp' => ['4400.00', '0.00'],
            ]);

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])->assertSuccessful();
    })->with([MetodeHpp::RataRata, MetodeHpp::Fifo]);
});

describe('F-05a invarian jurnal multi-outlet (J-05.1 per IdOutlet)', function (): void {
    it('BR-05.1: stok awal di tiga lokasi dua outlet → baris jurnal ber-IdOutlet lokasinya, saldo akun persediaan per outlet = Σ nilai stok outlet itu', function (): void {
        $t = BantuanPersediaan::SiapkanTenant('Toko Bangunan Sinar Abadi');
        $id = $t['Tenant']->Id;
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $belakang = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Belakang Toko');
        $cabang = Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => 'SOLO', 'Nama' => 'Cabang Solo Baru']);
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Gudang Cabang Solo Baru');
        $idPengguna = $t['Pemilik']->Id;

        $depan = SimpanDanPostingStokAwalInvarian($t['Gudang'], [[$p['Stok'], '48', '36250'], [$p['Seri'], '1', '675000', null, null, ['RC18-UTM-0001']]], $idPengguna);
        $gudangBelakang = SimpanDanPostingStokAwalInvarian($belakang, [[$p['Stok'], '120', '36000'], [$p['BahanBaku'], '250.75', '14500.5']], $idPengguna);
        $solo = SimpanDanPostingStokAwalInvarian($gudangCabang, [[$p['Stok'], '60', '36500'], [$p['Batch'], '36', '17900', 'UHT-SOLO-01', '2027-02-28']], $idPengguna);

        expect(PemeriksaInvarian::PeriksaSemua($id))->toBe([]);

        foreach ([[$depan, $t['Outlet']->Id], [$gudangBelakang, $t['Outlet']->Id], [$solo, $cabang->Id]] as [$dokumen, $idOutlet]) {
            expect(JurnalDetail::query()->where('IdJurnal', $dokumen->IdJurnal)->pluck('IdOutlet')->unique()->values()->all())->toBe([$idOutlet])
                ->and(Jurnal::query()->whereKey($dokumen->IdJurnal)->value('TotalDebit'))->toBe($dokumen->TotalNilai);
        }

        // Σ (debit − kredit) akun persediaan per outlet = Σ NilaiPersediaan lokasi di outlet itu.
        foreach ([$t['Outlet']->Id => [$t['Gudang']->Id, $belakang->Id], $cabang->Id => [$gudangCabang->Id]] as $idOutlet => $idGudang) {
            $akun = DB::table('JurnalDetail')->where('IdTenant', $id)->where('IdOutlet', $idOutlet)
                ->whereIn('IdAkun', DB::table('PemetaanAkun')->where('IdTenant', $id)->whereIn('Kunci', ['PersediaanBarangDagang', 'PersediaanBahanBaku'])->select('IdAkun'))
                ->selectRaw('COALESCE(SUM(Debit - Kredit), 0) AS Saldo')->value('Saldo');
            $stok = DB::table('SaldoStok')->where('IdTenant', $id)->whereIn('IdGudang', $idGudang)->selectRaw('COALESCE(SUM(NilaiPersediaan), 0) AS Nilai')->value('Nilai');

            expect(BigDecimal::of((string) $akun)->isEqualTo(BigDecimal::of((string) $stok)))->toBeTrue("outlet {$idOutlet}: akun {$akun} ≠ stok {$stok}");
        }

        // Batalkan satu dokumen cabang: outlet lain tidak tersentuh, invarian tetap.
        app(BatalkanStokAwal::class)->Jalankan($solo, 'Salah lokasi, seharusnya gudang pusat', $idPengguna);

        expect(PemeriksaInvarian::PeriksaSemua($id))->toBe([])
            ->and(SaldoStok::query()->where('IdGudang', $gudangCabang->Id)->sum('NilaiPersediaan'))->toEqual(0)
            ->and(SaldoStok::query()->where('IdGudang', $belakang->Id)->where('IdProduk', $p['Stok']->Id)->value('JumlahTersedia'))->toBe('120.0000');

        $this->artisan('persediaan:bangun-ulang-saldo', ['--periksa' => true])->assertSuccessful();
    });
});

/**
 * Debit & kredit satu jurnal per kunci peran akun (via PemetaanAkun tenant), SQL mentah.
 *
 * @return array<string, array{string, string}>
 */
function HitungDebitKreditPerPeran(?int $idJurnal): array
{
    $baris = DB::select(
        'SELECT p.Kunci, SUM(d.Debit) AS Debit, SUM(d.Kredit) AS Kredit
           FROM JurnalDetail d
           JOIN (SELECT IdAkun, MIN(Kunci) AS Kunci FROM PemetaanAkun
                  WHERE IdTenant = (SELECT IdTenant FROM Jurnal WHERE Id = ?)
                    AND Kunci IN (\'PersediaanBarangDagang\', \'PersediaanBahanBaku\', \'EkuitasSaldoAwal\', \'SelisihHpp\')
                  GROUP BY IdAkun) p ON p.IdAkun = d.IdAkun
          WHERE d.IdJurnal = ?
          GROUP BY p.Kunci ORDER BY p.Kunci',
        [$idJurnal, $idJurnal],
    );

    $hasil = [];

    foreach ($baris as $b) {
        $hasil[(string) $b->Kunci] = [(string) $b->Debit, (string) $b->Kredit];
    }

    return $hasil;
}
