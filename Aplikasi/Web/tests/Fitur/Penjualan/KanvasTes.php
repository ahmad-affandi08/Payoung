<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Aksi\TerimaTransferStok;
use App\Domain\Persediaan\Data\DataTerimaTransfer;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Model\Tenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Modul Salesman bagian 3 — kanvas (§9.7): kendaraan salesman = outlet bertanda `Kanvas` yang lokasi stok Toko-nya
 * adalah bak kendaraan. Muat/bongkar = transfer stok F-05b, jual tunai/tempo = penjualan POS F-07 (BR-12.x untuk tempo),
 * setoran = tutup shift F-11. Yang diuji: pintasan "Tambah kendaraan kanvas" (SimpanOutlet: BR-02.1 batas paket,
 * BR-02.4 lokasi Toko, audit), sakelar kanvas di ubah outlet, rekap harian Grosir › Kanvas dari buku stok (BR-05.1:
 * Sisa = Σ MutasiStok, SaldoStok = Σ MutasiStok), isolasi tenant, dan izin.
 */

beforeEach(function (): void {
    // 12.00 WIB: jauh dari jam tutup buku 04:00, jadi tanggal bisnis semua dokumen uji sama.
    Carbon::setTestNow('2026-10-02 05:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array<string, mixed>
 */
function IsianOutletKanvasUji(Outlet $outlet, array $ubah = []): array
{
    BantuanOrganisasi::AturKonteks($outlet->IdTenant);

    return [
        'Nama' => $outlet->Nama,
        'Kode' => $outlet->Kode,
        'Merek' => Merek::query()->whereKey($outlet->IdMerek)->value('Uuid'),
        'ZonaWaktu' => 'WIB',
        'JamTutupBuku' => '04:00',
        ...$ubah,
    ];
}

describe('Modul Salesman bagian 3: kendaraan kanvas', function (): void {
    it('pintasan Tambah kendaraan kanvas: outlet Kanvas "Kanvas {plat}" kode KNV1/KNV2 + lokasi stok Toko, tercatat di audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Distributor Sembako Sukoharjo');
        BantuanFitur::Beri($tenant->Id, 'piutang.grosir');
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk()->post('/kelola/grosir/kanvas', ['NomorKendaraan' => '  ad   1234 xy '])->assertSessionHasNoErrors()->assertRedirect();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $kanvas = Outlet::query()->where('Kanvas', true)->sole();
        $utama = Outlet::query()->where('Kanvas', false)->orderBy('Id')->firstOrFail();
        $gudang = Gudang::query()->where('IdOutlet', $kanvas->Id)->sole();

        expect($kanvas->Nama)->toBe('Kanvas AD 1234 XY')
            ->and($kanvas->NomorKendaraan)->toBe('AD 1234 XY')
            ->and($kanvas->Kode)->toBe('KNV1')
            ->and($kanvas->IdMerek)->toBe($utama->IdMerek)
            ->and($kanvas->ZonaWaktu)->toBe($utama->ZonaWaktu)
            ->and($gudang->Jenis)->toBe(JenisGudang::Toko)
            ->and($gudang->Nama)->toBe('Toko Kanvas AD 1234 XY')
            ->and(LogAudit::query()->where('Peristiwa', 'outlet.buat')->where('IdObjek', $kanvas->Id)->value('NilaiBaru'))
            ->toMatchArray(['Kanvas' => true, 'NomorKendaraan' => 'AD 1234 XY']);

        $masuk()->post('/kelola/grosir/kanvas', ['NomorKendaraan' => 'AD 5678 ZZ'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Outlet::query()->where('Kanvas', true)->orderBy('Id')->pluck('Kode')->all())->toBe(['KNV1', 'KNV2']);

        $masuk()->post('/kelola/grosir/kanvas', ['NomorKendaraan' => ''])->assertSessionHasErrors('NomorKendaraan');
        $masuk()->post('/kelola/grosir/kanvas', ['NomorKendaraan' => 'AD<1234>'])->assertSessionHasErrors('NomorKendaraan');

        $masuk()->get('/kelola/grosir/kanvas')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Grosir/Kanvas/Daftar')
            ->has('Kanvas', 2)
            ->where('Kanvas.0.Nama', 'Kanvas AD 1234 XY')
            ->where('Kanvas.0.NomorKendaraan', 'AD 1234 XY')
            ->where('UuidTerpilih', $kanvas->Uuid)
            ->where('Tanggal', '2026-10-02')
            ->where('IzinKanvas.TambahKendaraan', true)
            ->where('Rekap.Produk', []));
    });

    it('BR-02.1: kendaraan kanvas memakai kuota outlet paket. Paket penuh ditolak tanpa outlet/lokasi stok baru', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kanvas Gratisan', kodePaket: 'GRATIS');
        BantuanFitur::Beri($tenant->Id, 'piutang.grosir');

        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id)
            ->post('/kelola/grosir/kanvas', ['NomorKendaraan' => 'AD 1234 XY'])
            ->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Outlet::query()->count())->toBe(1)
            ->and(Outlet::query()->where('Kanvas', true)->count())->toBe(0)
            ->and(Gudang::query()->count())->toBe(1);
    });

    it('ubah outlet: sakelar kanvas + nomor kendaraan tersimpan & teraudit. Klien tanpa kunci Kanvas tidak mengubahnya. Dimatikan = plat dikosongkan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Toko Grosir Kanvas Klaten');
        BantuanFitur::Beri($tenant->Id, 'piutang.grosir');
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $outlet = Outlet::query()->firstOrFail();
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $masuk()->put("/kelola/outlet/{$outlet->Uuid}", IsianOutletKanvasUji($outlet, ['Kanvas' => true, 'NomorKendaraan' => 'ad 1234  xy']))
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($outlet->refresh()->Kanvas)->toBeTrue()
            ->and($outlet->NomorKendaraan)->toBe('AD 1234 XY');
        $audit = LogAudit::query()->where('Peristiwa', 'outlet.ubah')->where('IdObjek', $outlet->Id)->latest('Id')->firstOrFail();
        expect($audit->NilaiLama)->toMatchArray(['Kanvas' => false, 'NomorKendaraan' => null])
            ->and($audit->NilaiBaru)->toMatchArray(['Kanvas' => true, 'NomorKendaraan' => 'AD 1234 XY']);

        $masuk()->get("/kelola/outlet/{$outlet->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Outlet.Kanvas', true)->where('Outlet.NomorKendaraan', 'AD 1234 XY'));

        // Klien lama (tanpa kunci Kanvas) tidak mematikan tanda kanvas.
        $masuk()->put("/kelola/outlet/{$outlet->Uuid}", IsianOutletKanvasUji($outlet, ['Alamat' => 'Jl. Pemuda 5, Klaten']))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($outlet->refresh()->Kanvas)->toBeTrue()->and($outlet->NomorKendaraan)->toBe('AD 1234 XY');

        $masuk()->put("/kelola/outlet/{$outlet->Uuid}", IsianOutletKanvasUji($outlet, ['Kanvas' => false, 'NomorKendaraan' => 'AD 1234 XY']))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($outlet->refresh()->Kanvas)->toBeFalse()->and($outlet->NomorKendaraan)->toBeNull();

        $masuk()->put("/kelola/outlet/{$outlet->Uuid}", IsianOutletKanvasUji($outlet, ['Kanvas' => true, 'NomorKendaraan' => str_repeat('A', 21)]))
            ->assertSessionHasErrors('NomorKendaraan');
    });
});

describe('Modul Salesman bagian 3: rekap harian kanvas', function (): void {
    it('satu hari penuh: muat 100, jual 30 tunai + 20 tempo, satu void, retur 2, bongkar 40 → rekap barang, uang & setoran benar; Sisa = Σ mutasi = SaldoStok', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Distributor Mi Instan Sragen');
        BantuanFitur::Beri($k['Tenant']->Id, 'piutang.grosir');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hari = '2026-10-02';
        $kemarin = '2026-10-01';

        // Outlet perangkat kasir dijadikan kendaraan kanvas lewat formulir ubah outlet.
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
            ->put("/kelola/outlet/{$k['Outlet']->Uuid}", IsianOutletKanvasUji($k['Outlet'], ['Kanvas' => true, 'NomorKendaraan' => 'AD 8001 KN']))
            ->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        /** @var Gudang $bak */
        $bak = $k['Gudang'];

        // Gudang pusat = outlet lain berlokasi stok jenis Gudang; stok awal 200 dus kemarin.
        $pusat = BantuanPersediaan::BuatGudang(BantuanDokumenPersediaan::BuatOutlet('PST', 'Gudang Pusat Sragen'), 'Gudang Pusat Sragen');
        $mi = BantuanKatalog::BuatProduk(['Nama' => 'Mi Instan Goreng Spesial Rasa Ayam Bawang Karton 40 bungkus'], '10000.00');
        BantuanStokAwal::BuatDanPosting($pusat, [BantuanStokAwal::Baris($mi, '200', '8000')], $k['Pemilik']->Id, $kemarin);

        // Muat pagi: transfer pusat → kendaraan 100, diterima hari ini.
        $muat = app(KirimTransferStok::class)->Jalankan(BantuanDokumenPersediaan::DrafTransfer($pusat, $bak, [BantuanDokumenPersediaan::Baris($mi, '100')], $hari), $k['Pemilik']->Id);
        app(TerimaTransferStok::class)->Jalankan($muat, [new DataTerimaTransfer(1, Kuantitas::Dari('100'))], CarbonImmutable::parse($hari), $k['Pemilik']->Id);

        // Jual dari HP salesman (perangkat kasir outlet kanvas): 30 tunai, 20 tempo, 5 tunai yang lalu divoid.
        $toko = Pelanggan::query()->create(['Nama' => 'Toko Kelontong Sumber Rejeki', 'NoHp' => '6281355550011', 'LimitKredit' => '5000000', 'TerminHari' => 14]);
        $tunai = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $mi, 'Jumlah' => '30', 'Harga' => '10000.00']]]);
        $temp = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $mi, 'Jumlah' => '20', 'Harga' => '10000.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '200000.00']]], ['UuidPelanggan' => $toko->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$temp]))->toBe([['Diterima', null]]);
        $batal = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $mi, 'Jumlah' => '5', 'Harga' => '10000.00']]]);
        $detailTunai = PenjualanDetail::query()->where('IdPenjualan', $tunai->Id)->sole();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemVoid($k, $batal),
            BantuanPenjualan::ItemRetur($k, $tunai, [['Detail' => $detailTunai, 'Jumlah' => '2', 'Kondisi' => 'LayakJual']]),
        ]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        // Bongkar sore: sisa 40 kembali ke pusat.
        $bongkar = app(KirimTransferStok::class)->Jalankan(BantuanDokumenPersediaan::DrafTransfer($bak, $pusat, [BantuanDokumenPersediaan::Baris($mi, '40')], $hari), $k['Pemilik']->Id);
        app(TerimaTransferStok::class)->Jalankan($bongkar, [new DataTerimaTransfer(1, Kuantitas::Dari('40'))], CarbonImmutable::parse($hari), $k['Pemilik']->Id);

        // Setoran: kas awal 500.000 + tunai 300.000 − refund retur 20.000 = 780.000; disetor 775.000 (kurang 5.000).
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [[
            'Jenis' => 'Shift.Tutup',
            'Uuid' => BantuanKasir::Uuid(),
            'Data' => [
                'UuidShift' => $k['UuidShift'],
                'UuidPengguna' => $k['Kasir']->Uuid,
                'DitutupPada' => now()->subMinute()->utc()->toIso8601ZuluString(),
                'KasAktual' => '775000.00',
                'PecahanKasAkhir' => null,
                'NonTunai' => [],
                'Alasan' => null,
                'UuidPenyetuju' => null,
                'Ringkasan' => ['KasSeharusnya' => '780000.00', 'Selisih' => '-5000.00'],
            ],
        ]]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $shift = Shift::query()->where('Uuid', $k['UuidShift'])->sole();

        $respons = BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)
            ->get('/kelola/grosir/kanvas?outlet='.$k['Outlet']->Uuid.'&tanggal='.$hari)
            ->assertOk();
        $props = $respons->viewData('page')['props'];

        expect($props['UuidTerpilih'])->toBe($k['Outlet']->Uuid)
            ->and($props['Kanvas'])->toHaveCount(1)
            ->and($props['Kanvas'][0]['NomorKendaraan'])->toBe('AD 8001 KN')
            ->and($props['Rekap']['Produk'])->toBe([[
                'UuidProduk' => $mi->Uuid,
                'NamaProduk' => 'Mi Instan Goreng Spesial Rasa Ayam Bawang Karton 40 bungkus',
                'Sku' => $mi->Sku,
                'Satuan' => 'pcs',
                'Awal' => '0.0000',
                'Muat' => '100.0000',
                'Terjual' => '50.0000',
                'Retur' => '2.0000',
                'Bongkar' => '40.0000',
                'Lain' => '0.0000',
                'Sisa' => '12.0000',
            ]])
            ->and($props['Rekap']['Uang'])->toBe([
                'PenjualanTunai' => '300000.00',
                'PenjualanTempo' => '200000.00',
                'PenjualanLain' => '0.00',
                'RefundTunai' => '20000.00',
                'NilaiRetur' => '20000.00',
                'Bersih' => '480000.00',
                'JumlahTransaksi' => 2,
                'JumlahVoid' => 1,
                'JumlahRetur' => 1,
            ])
            ->and($props['Rekap']['Setoran'])->toBe([
                'JumlahShiftTertutup' => 1,
                'JumlahShiftBelumDitutup' => 0,
                'KasAwal' => '500000.00',
                'KasSeharusnya' => '780000.00',
                'KasAktual' => '775000.00',
                'Selisih' => '-5000.00',
            ])
            ->and($shift->KasSeharusnya)->toBe('780000.00');

        // D-95: transfer muat & bongkar hari itu ikut ke halaman (arah dilihat dari bak kendaraan), tanpa nilai barang.
        expect(array_column($props['Transfer'], 'Arah'))->toBe(['Muat', 'Bongkar'])
            ->and(array_unique(array_column($props['Transfer'], 'NamaLawan')))->toBe(['Gudang Pusat Sragen'])
            ->and($props['Transfer'][0])->not->toHaveKey('NilaiKirim')
            ->and($props['Transfer'][0]['BisaCetak'])->toBeTrue();

        // BR-05.1: Sisa rekap = Σ MutasiStok lokasi kendaraan = SaldoStok; seluruh buku stok & jurnal tenant konsisten.
        $sigma = BigDecimal::of((string) MutasiStok::query()->where('IdGudang', $bak->Id)->where('IdProduk', $mi->Id)->sum('Jumlah'))->toScale(4);
        expect((string) $sigma)->toBe('12.0000')
            ->and(SaldoStok::query()->where('IdGudang', $bak->Id)->where('IdProduk', $mi->Id)->value('JumlahTersedia'))->toBe('12.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        // Tanggal lain diturunkan dari buku stok: kemarin kendaraan kosong; besok mulai dengan sisa hari ini.
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $masuk()->get('/kelola/grosir/kanvas?outlet='.$k['Outlet']->Uuid.'&tanggal='.$kemarin)->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tanggal', $kemarin)->where('Transfer', [])->where('Rekap.Produk', [])->where('Rekap.Uang.JumlahTransaksi', 0)->where('Rekap.Setoran.JumlahShiftTertutup', 0));
        $masuk()->get('/kelola/grosir/kanvas?outlet='.$k['Outlet']->Uuid.'&tanggal=2026-10-03')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Rekap.Produk.0.Awal', '12.0000')->where('Rekap.Produk.0.Terjual', '0.0000')->where('Rekap.Produk.0.Sisa', '12.0000'));
        // Tanggal tidak valid = hari bisnis kendaraan.
        $masuk()->get('/kelola/grosir/kanvas?tanggal=2026-02-31')->assertInertia(fn (AssertableInertia $h) => $h->where('Tanggal', $hari));
    });

    it('isolasi tenant: kendaraan & rekap tenant lain tidak terlihat walau Uuid-nya dikirim', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Kanvas Tenant A Boyolali');
        BantuanOrganisasi::Masuk($this, $a['Pemilik'], $a['Tenant']->Id)
            ->put("/kelola/outlet/{$a['Outlet']->Uuid}", IsianOutletKanvasUji($a['Outlet'], ['Kanvas' => true, 'NomorKendaraan' => 'AD 1 A']))
            ->assertSessionHasNoErrors();

        ['Tenant' => $b, 'Pemilik' => $pemilikB] = BantuanOrganisasi::BuatTenant('Kanvas Tenant B Wonogiri');
        BantuanFitur::Beri($b->Id, 'piutang.grosir');
        BantuanOrganisasi::Masuk($this, $pemilikB, $b->Id)
            ->get('/kelola/grosir/kanvas?outlet='.$a['Outlet']->Uuid)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Kanvas', [])->where('UuidTerpilih', null)->where('Rekap', null));

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(Outlet::query()->where('Kanvas', true)->count())->toBe(1);
        BantuanOrganisasi::AturKonteks($b->Id);
        expect(Outlet::query()->where('Kanvas', true)->count())->toBe(0)
            ->and(Tenant::query()->count())->toBeGreaterThanOrEqual(2);
    });

    it('izin: Kasir ditolak (grosir.kelola); Supervisor boleh melihat rekap tetapi tidak menambah kendaraan (outlet.kelola)', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Kanvas Izin Karanganyar');
        BantuanFitur::Beri($tenant->Id, 'piutang.grosir');

        BantuanKatalog::MasukSebagai($this, $tenant->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/grosir/kanvas')->assertForbidden();
        $this->post('/kelola/grosir/kanvas', ['NomorKendaraan' => 'AD 1 A'])->assertForbidden();

        BantuanKatalog::MasukSebagai($this, $tenant->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/grosir/kanvas')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('IzinKanvas.TambahKendaraan', false));
        $this->post('/kelola/grosir/kanvas', ['NomorKendaraan' => 'AD 1 A'])->assertForbidden();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Outlet::query()->where('Kanvas', true)->count())->toBe(0);
    });
});
