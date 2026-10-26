<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Persediaan\Aksi\BatalkanOrderProduksi;
use App\Domain\Persediaan\Aksi\PostingOrderProduksi;
use App\Domain\Persediaan\Aksi\SimpanOrderProduksi;
use App\Domain\Persediaan\Data\DataOrderProduksi;
use App\Domain\Persediaan\Enum\StatusOrderProduksi;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\OrderProduksi;
use App\Domain\Persediaan\Model\OrderProduksiBahan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05e order produksi (INV-11, J-05.6): bahan keluar ProduksiPakai (HPP berjalan), hasil masuk ProduksiHasil bernilai
 * Σ bahan + overhead; jurnal Dr persediaan hasil / Cr persediaan bahan + Overhead Produksi Dibebankan; bahan standar
 * dari resep × jumlah hasil; pembatalan membalik stok & jurnal selama hasil belum terpakai. Invarian di tiap skenario.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Roti (Produksi) dengan resep 10 pcs = gula 2 kg + minyak 1 pcs; stok awal gula 10 kg @15.000, minyak 100 @38.500. */
function SiapkanProduksi(): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['BahanBaku'], '10', '15000'),
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));
    BantuanKomposisi::SimpanResep($p['Produksi'], [[$p['BahanBaku'], '2'], [$p['Stok'], '1']], '10');

    return [...$t, 'Produk' => $p];
}

/** @param list<array{idProduk: int, jumlah: Kuantitas}>|null $bahan */
function DrafProduksi(Gudang $gudang, Produk $hasil, string $jumlah, ?array $bahan = null, string $overhead = '0', ?string $batch = null, ?string $kedaluwarsa = null): OrderProduksi
{
    return app(SimpanOrderProduksi::class)->Jalankan(new DataOrderProduksi(
        null,
        $gudang->Id,
        CarbonImmutable::parse(B::Kemarin()),
        $hasil->Id,
        Kuantitas::Dari($jumlah),
        Uang::Dari($overhead),
        $batch,
        $kedaluwarsa === null ? null : CarbonImmutable::parse($kedaluwarsa),
        null,
        $bahan,
    ), null);
}

describe('F-05e draf order produksi', function (): void {
    it('bahan kosong diisi dari resep × jumlah hasil (standar = aktual)', function (): void {
        $t = SiapkanProduksi();
        $o = DrafProduksi($t['Gudang'], $t['Produk']['Produksi'], '25');
        $bahan = OrderProduksiBahan::query()->where('IdOrderProduksi', $o->Id)->orderBy('Urutan')->get();

        expect($o->Status)->toBe(StatusOrderProduksi::Draf)
            ->and($o->VersiResep)->toBe(1)
            ->and($bahan->pluck('JumlahStandar')->all())->toBe(['5.0000', '2.5000'])
            ->and($bahan->pluck('Jumlah')->all())->toBe(['5.0000', '2.5000'])
            ->and(MutasiStok::query()->where('JenisReferensi', 'Produksi')->count())->toBe(0);
    });

    it('menolak hasil bukan Produksi, tanpa resep, bahan berpelacakan/tidak berstok/ganda/sama dengan hasil', function (): void {
        $t = SiapkanProduksi();
        $p = $t['Produk'];
        $tanpaResep = BantuanKatalog::BuatProduk(['Nama' => 'Bolu Pandan Produksi', 'Jenis' => JenisProduk::Produksi], '30000.00', $t['Pcs']);
        $b = fn (Produk $x, string $j = '1'): array => ['idProduk' => $x->Id, 'jumlah' => Kuantitas::Dari($j)];

        expect(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Stok'], '1')))->toBe('BukanProdukProduksi')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $tanpaResep, '1')))->toBe('ResepBelumAda')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '1', [$b($p['Batch'])])))->toBe('PelacakanTidakDidukung')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '1', [$b($p['Jasa'])])))->toBe('BahanTidakBerstok')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '1', [$b($p['Stok']), $b($p['Stok'])])))->toBe('BahanGanda')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '1', [$b($p['Produksi'])])))->toBe('BahanSamaDenganHasil')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '0')))->toBe('JumlahTidakValid')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '1.5')))->toBe('JumlahTidakValid')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '1', overhead: '-1')))->toBe('OverheadTidakValid');
    });
});

describe('F-05e posting & jurnal J-05.6', function (): void {
    it('bahan keluar HPP berjalan, hasil masuk Σ bahan + overhead, jurnal seimbang, invarian terjaga', function (): void {
        $t = SiapkanProduksi();
        $p = $t['Produk'];
        $o = DrafProduksi($t['Gudang'], $p['Produksi'], '20', [
            ['idProduk' => $p['BahanBaku']->Id, 'jumlah' => Kuantitas::Dari('4.5')],
            ['idProduk' => $p['Stok']->Id, 'jumlah' => Kuantitas::Dari('2')],
        ], '50000');

        expect(OrderProduksiBahan::query()->where('IdOrderProduksi', $o->Id)->orderBy('Urutan')->pluck('JumlahStandar')->all())->toBe(['4.0000', '2.0000']);

        $o = app(PostingOrderProduksi::class)->Jalankan($o, $t['Pemilik']->Id);
        $jurnal = Jurnal::query()->where('JenisSumber', 'OrderProduksi')->where('IdSumber', $o->Id)->sole();

        expect($o->Status)->toBe(StatusOrderProduksi::Diposting)
            ->and($o->Nomor)->toBe('PR/'.$t['Gudang']->Kode.'/'.CarbonImmutable::parse(B::Kemarin())->format('ym').'/0001')
            ->and($o->TotalNilaiBahan)->toBe('144500.00')
            ->and($o->NilaiHasil)->toBe('194500.00')
            ->and($o->HppSatuanHasil)->toBe('9725.000000')
            ->and(B::Saldo($p['BahanBaku'], $t['Gudang']))->toBe(['5.5000', '82500.00'])
            ->and(B::Saldo($p['Stok'], $t['Gudang']))->toBe(['98.0000', '3773000.00'])
            ->and(B::Saldo($p['Produksi'], $t['Gudang']))->toBe(['20.0000', '194500.00'])
            ->and(MutasiStok::query()->where('JenisReferensi', 'Produksi')->pluck('JenisMutasi')->map->value->sort()->values()->all())->toBe(['ProduksiHasil', 'ProduksiPakai', 'ProduksiPakai'])
            ->and(B::BarisJurnal($jurnal->Id))->toEqualCanonicalizing([
                ['PersediaanBarangDagang', '117500.00', '0.00', $t['Outlet']->Id],
                ['PersediaanBahanBaku', '0.00', '67500.00', $t['Outlet']->Id],
                ['OverheadProduksiDibebankan', '0.00', '50000.00', $t['Outlet']->Id],
            ])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);

        // Idempoten: posting ulang tidak menambah mutasi/jurnal.
        app(PostingOrderProduksi::class)->Jalankan($o, $t['Pemilik']->Id);
        expect(MutasiStok::query()->where('JenisReferensi', 'Produksi')->count())->toBe(3)
            ->and(Jurnal::query()->where('JenisSumber', 'OrderProduksi')->count())->toBe(1);
    });

    it('stok bahan kurang ditolak dan tidak ada yang tercatat', function (): void {
        $t = SiapkanProduksi();
        $o = DrafProduksi($t['Gudang'], $t['Produk']['Produksi'], '100');

        expect(B::KodeGalat(fn () => app(PostingOrderProduksi::class)->Jalankan($o, $t['Pemilik']->Id)))->toBe('StokTidakCukup')
            ->and($o->refresh()->Status)->toBe(StatusOrderProduksi::Draf)
            ->and(MutasiStok::query()->where('JenisReferensi', 'Produksi')->count())->toBe(0);
    });

    it('hasil ber-batch wajib nomor batch & kedaluwarsa, lalu batch baru tercatat', function (): void {
        $t = SiapkanProduksi();
        $p = $t['Produk'];
        $p['Produksi']->forceFill(['Pelacakan' => PelacakanProduk::Batch])->save();

        expect(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '10')))->toBe('NomorBatchWajib')
            ->and(B::KodeGalat(fn () => DrafProduksi($t['Gudang'], $p['Produksi'], '10', batch: 'RT-0927')))->toBe('KedaluwarsaWajib');

        $o = app(PostingOrderProduksi::class)->Jalankan(DrafProduksi($t['Gudang'], $p['Produksi'], '10', batch: 'RT-0927', kedaluwarsa: '2026-10-03'), $t['Pemilik']->Id);

        expect(MutasiStok::query()->where('JenisReferensi', 'Produksi')->where('KunciBaris', 'H')->sole()->IdBatchStok)->not->toBeNull()
            ->and($o->NilaiHasil)->toBe('68500.00')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

describe('F-05e pembatalan', function (): void {
    it('draf dibatalkan tanpa efek stok. Terposting dibalik (stok & jurnal) dengan alasan wajib', function (): void {
        $t = SiapkanProduksi();
        $p = $t['Produk'];
        $draf = DrafProduksi($t['Gudang'], $p['Produksi'], '10');
        expect(app(BatalkanOrderProduksi::class)->Jalankan($draf, null, $t['Pemilik']->Id)->Status)->toBe(StatusOrderProduksi::Dibatalkan);

        $o = app(PostingOrderProduksi::class)->Jalankan(DrafProduksi($t['Gudang'], $p['Produksi'], '10', overhead: '15000'), $t['Pemilik']->Id);
        expect(B::KodeGalat(fn () => app(BatalkanOrderProduksi::class)->Jalankan($o, 'x', $t['Pemilik']->Id)))->toBe('AlasanTidakValid');

        $o = app(BatalkanOrderProduksi::class)->Jalankan($o, 'Salah pilih produk hasil', $t['Pemilik']->Id);
        $pembalik = Jurnal::query()->whereKey($o->IdJurnalPembatalan)->sole();

        expect($o->Status)->toBe(StatusOrderProduksi::Dibatalkan)
            ->and(B::Saldo($p['BahanBaku'], $t['Gudang']))->toBe(['10.0000', '150000.00'])
            ->and(B::Saldo($p['Stok'], $t['Gudang']))->toBe(['100.0000', '3850000.00'])
            ->and(B::Saldo($p['Produksi'], $t['Gudang']))->toBe(['0.0000', '0.00'])
            ->and(B::BarisJurnal($pembalik->Id))->toEqualCanonicalizing([
                ['PersediaanBarangDagang', '0.00', '45000.00', $t['Outlet']->Id],
                ['PersediaanBahanBaku', '30000.00', '0.00', $t['Outlet']->Id],
                ['OverheadProduksiDibebankan', '15000.00', '0.00', $t['Outlet']->Id],
            ])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('hasil yang sudah terjual tidak bisa dibatalkan', function (): void {
        $t = SiapkanProduksi();
        $o = app(PostingOrderProduksi::class)->Jalankan(DrafProduksi($t['Gudang'], $t['Produk']['Produksi'], '10'), $t['Pemilik']->Id);
        BantuanStokAwal::Jual($t['Produk']['Produksi'], $t['Gudang'], '1');

        expect(B::KodeGalat(fn () => app(BatalkanOrderProduksi::class)->Jalankan($o, 'Mau dibatalkan saja', $t['Pemilik']->Id)))->toBe('StokSudahTerpakai')
            ->and($o->refresh()->Status)->toBe(StatusOrderProduksi::Diposting);
    });
});

describe('F-05e HTTP back-office', function (): void {
    it('buat draf, detail, resep JSON, posting lewat rute. Isolasi tenant', function (): void {
        $t = SiapkanProduksi();
        $p = $t['Produk'];
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->getJson('/kelola/persediaan/produksi/resep?produk='.$p['Produksi']->Uuid.'&jumlah=5')
            ->assertOk()
            ->assertJsonPath('VersiResep', 1)
            ->assertJsonPath('Bahan.0.JumlahStandar', '1.0000')
            ->assertJsonPath('Bahan.1.JumlahStandar', '0.5000');

        $this->post('/kelola/persediaan/produksi', [
            'UuidGudang' => $t['Gudang']->Uuid,
            'Tanggal' => B::Kemarin(),
            'UuidProduk' => $p['Produksi']->Uuid,
            'JumlahHasil' => '10',
            'BiayaOverhead' => '10000',
            'Bahan' => [
                ['UuidProduk' => $p['BahanBaku']->Uuid, 'Jumlah' => '2'],
                ['UuidProduk' => $p['Stok']->Uuid, 'Jumlah' => '1'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $o = OrderProduksi::query()->sole();
        $this->get("/kelola/persediaan/produksi/{$o->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Produksi/Detail')
            ->where('Order.Status', 'Draf')
            ->where('Tindakan.Posting', true)
            ->has('Bahan', 2));
        $this->get('/kelola/persediaan/produksi')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Produksi/Daftar')
            ->where('Order.Data.0.NamaProduk', $p['Produksi']->Nama));

        $this->post("/kelola/persediaan/produksi/{$o->Uuid}/posting")->assertRedirect()->assertSessionHasNoErrors();
        expect($o->refresh()->Status)->toBe(StatusOrderProduksi::Diposting)->and($o->NilaiHasil)->toBe('78500.00');

        $lain = BantuanPersediaan::SiapkanTenant('Toko Lain Sentosa');
        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/persediaan/produksi/{$o->Uuid}")->assertNotFound();
    });

    it('kasir tanpa izin persediaan.kelola tidak bisa membuat order produksi', function (): void {
        $t = SiapkanProduksi();
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);

        $this->get('/kelola/persediaan/produksi/buat')->assertForbidden();
    });
});
