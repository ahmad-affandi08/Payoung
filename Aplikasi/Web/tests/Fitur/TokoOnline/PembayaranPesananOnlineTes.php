<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Peristiwa\PeristiwaIntegrasi;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
use App\Domain\Penjualan\Layanan\PenyediaTindakanPenjualan;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use App\Domain\Promo\Model\PromoPemakaian;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-17 toko online bagian 2: pelanggan membayar QRIS dari web sebelum barangnya diserahkan. Uangnya dibukukan sebagai
 * kewajiban (J-17.1 Uang Muka Pelanggan), dipakai kasir lewat metode Uang muka saat menagih, dikembalikan lewat J-17.2
 * bila pesanannya tidak jadi, dan dipulihkan bila penjualannya di-void.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

function PalsukanGerbangOnline(string &$status): void
{
    Http::fake(function (PermintaanHttp $r) use (&$status) {
        if (BantuanGerbangTenant::CekPermintaanBuat($r)) {
            return BantuanGerbangTenant::ResponsBuat($r, 'trx-online');
        }

        return BantuanGerbangTenant::ResponsStatus($status);
    });
}

/**
 * Toko online + gerbang QRIS aktif + sakelar QRIS hidup.
 *
 * @return array<string, mixed>
 */
function SiapkanBayarOnline(TestCase $tes): array
{
    $k = BantuanTokoOnline::Siapkan($tes);
    $gerbang = BantuanGerbangTenant::Aktifkan($k['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PengaturanTokoOnline::query()->sole()->forceFill(['QrisAktif' => true])->save();
    BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis');

    return $k + ['TokenWebhook' => $gerbang->TokenWebhook];
}

/**
 * @param  array<string, mixed>  $k
 * @return array{0: PesananOnline, 1: array<string, mixed>}
 */
function PesanBayarQris(TestCase $tes, array $k, string $pemenuhan = 'AmbilSendiri'): array
{
    $kiriman = [...BantuanTokoOnline::Kiriman($k, $pemenuhan), 'MetodePembayaran' => 'QrisOnline'];
    $dibuat = $tes->postJson('/'.$k['Slug'].'/pesan', $kiriman)->assertCreated()
        ->assertJsonPath('Status', StatusPesananOnline::MenungguPembayaran->value);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return [PesananOnline::query()->where('Uuid', $kiriman['Uuid'])->sole(), $dibuat->json()];
}

/**
 * @param  array<string, mixed>  $k
 */
function WebhookBayarOnline(TestCase $tes, array $k, string $nomor, string $jumlah, string $status = 'SUCCESS'): TestResponse
{
    return BantuanGerbangTenant::KirimWebhook($tes, $k['TokenWebhook'], $nomor, $jumlah, $status);
}

function SaldoPeranOnline(int $idJurnal, PeranAkun $peran, int $idOutlet): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun($peran, $idOutlet);
    $saldo = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Uang::Dari($b->Debit))->Kurangi(Uang::Dari($b->Kredit));
    }

    return $saldo->KeString();
}

it('checkout QRIS menunggu pembayaran, QR-nya idempoten per pesanan, dan tidak tampil bila sakelarnya mati', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan] = PesanBayarQris($this, $k);

    $pertama = $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated()
        ->assertJsonPath('Jumlah', '60000.00')->assertJsonPath('Status', StatusTagihanQris::Menunggu->value);
    // DOKU Checkout memberi halaman bayar (bukan muatan QRIS): pembeli diarahkan ke URL itu, bukan digambarkan QR.
    expect($pertama->json('Qr'))->toBeNull()
        ->and($pertama->json('UrlBayar'))->toStartWith('https://sandbox.doku.com/checkout/link/');

    // Halaman bayar dimuat ulang: tagihan yang sama, gerbang tidak dipanggil dua kali.
    $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertOk()
        ->assertJsonPath('Jumlah', '60000.00');
    expect(count(Http::recorded(fn (PermintaanHttp $r): bool => BantuanGerbangTenant::CekPermintaanBuat($r))))->toBe(1);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(TagihanQris::query()->count())->toBe(1)
        ->and(TagihanQris::query()->sole()->Sumber)->toBe(SumberTagihanQris::TokoOnline)
        ->and(TagihanQris::query()->sole()->IdPerangkat)->toBeNull();

    PengaturanTokoOnline::query()->sole()->forceFill(['QrisAktif' => false])->save();
    $this->postJson('/'.$k['Slug'].'/pesan', [...BantuanTokoOnline::Kiriman($k), 'MetodePembayaran' => 'QrisOnline'])
        ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'MetodePembayaranTidakAktif');
});

it('pembayaran masuk membukukan uang muka (J-17.1) dan memindahkan pesanan ke menunggu konfirmasi, sekali saja', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan] = PesanBayarQris($this, $k);
    $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $tagihan = TagihanQris::query()->sole();

    WebhookBayarOnline($this, $k, $tagihan->NomorPesanan, '60000.00')->assertOk();
    // Notifikasi gerbang terkirim dua kali: jurnalnya tetap satu.
    WebhookBayarOnline($this, $k, $tagihan->NomorPesanan, '60000.00')->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan->refresh();
    expect($pesanan->Status)->toBe(StatusPesananOnline::MenungguKonfirmasi)
        ->and($pesanan->DibayarPada)->not->toBeNull()
        ->and($pesanan->JumlahDibayar)->toBe('60000.00')
        ->and($pesanan->AmbilSisaUangMuka()->KeString())->toBe('60000.00')
        ->and($pesanan->IdJurnal)->not->toBeNull()
        ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::PesananOnline->value)->count())->toBe(1);

    $jurnal = Jurnal::query()->whereKey($pesanan->IdJurnal)->sole();
    expect($jurnal->TotalDebit)->toBe($jurnal->TotalKredit)
        ->and(SaldoPeranOnline($jurnal->Id, PeranAkun::UangMukaPelanggan, $pesanan->IdOutlet))->toBe('-60000.00')
        ->and(SaldoPeranOnline($jurnal->Id, PeranAkun::PiutangPencairan, $pesanan->IdOutlet))->toBe('60000.00');
});

it('kasir menagih pesanan berbayar dengan metode Uang muka. Void mengembalikan uang mukanya', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan] = PesanBayarQris($this, $k);
    $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    WebhookBayarOnline($this, $k, TagihanQris::query()->sole()->NomorPesanan, '60000.00')->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $uangMuka = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::UangMuka, 'Uang muka (DP)');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $pesanan->refresh()->forceFill(['Status' => StatusPesananOnline::Siap])->save();

    $item = BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '60000.00']],
        'Pembayaran' => [['Metode' => $uangMuka, 'Jumlah' => '60000.00']],
    ], ['UuidPesananOnline' => $pesanan->Uuid]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan->refresh();
    expect($pesanan->Status)->toBe(StatusPesananOnline::Selesai)
        ->and($pesanan->UangMukaTerpakai)->toBe('60000.00')
        ->and($pesanan->AmbilSisaUangMuka()->KeString())->toBe('0.00')
        ->and($pesanan->IdPenjualan)->not->toBeNull();

    $penjualan = Penjualan::query()->whereKey($pesanan->IdPenjualan)->sole();
    $void = BantuanPenjualan::ItemVoid($k, $penjualan);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$void]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan->refresh();
    expect($pesanan->AmbilSisaUangMuka()->KeString())->toBe('60000.00')
        ->and($pesanan->IdPenjualan)->toBeNull()
        ->and($pesanan->Status)->toBe(StatusPesananOnline::Siap);
});

it('F-17 bagian 3: kasir menagih pesanan kirim beserta ongkirnya, ongkir masuk Pendapatan Pengiriman dan pesanan kirim belum Selesai', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan] = PesanBayarQris($this, $k, 'Kirim');
    expect($pesanan->Ongkir)->toBe('12000.00');
    $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    WebhookBayarOnline($this, $k, TagihanQris::query()->sole()->NomorPesanan, $pesanan->Total)->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $uangMuka = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::UangMuka, 'Uang muka (DP)');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $pesanan->refresh()->forceFill(['Status' => StatusPesananOnline::Siap])->save();

    $item = BantuanPenjualan::Item($k, [
        'BiayaKirim' => '12000.00',
        'Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '60000.00']],
        'Pembayaran' => [['Metode' => $uangMuka, 'Jumlah' => '72000.00']],
    ], ['UuidPesananOnline' => $pesanan->Uuid]);
    expect($item['Data']['Ringkasan']['TotalAkhir'])->toBe('72000.00');
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    $o = $k['Outlet']->Id;

    expect($penjualan->BiayaKirim)->toBe('12000.00')
        ->and($penjualan->DiskonKirim)->toBe('0.00')
        ->and($penjualan->TotalAkhir)->toBe('72000.00')
        // Yang diuji di sini ongkirnya, jadi yang dipastikan adalah tidak ada OngkirBerbeda — bukan tinjauan kosong,
        // supaya test ini tidak ikut gagal bila kelak fixture-nya memicu alasan tinjauan lain.
        ->and($penjualan->AlasanTinjauan ?? '')->not->toContain('OngkirBerbeda')
        ->and(SaldoPeranOnline((int) $penjualan->IdJurnal, PeranAkun::PendapatanPengiriman, $o))->toBe('-12000.00')
        // Pesanan kirim baru Selesai setelah kurirnya menyerahkan barang, bukan saat ditagih.
        ->and($pesanan->refresh()->Status)->toBe(StatusPesananOnline::Siap)
        ->and($pesanan->IdPenjualan)->toBe($penjualan->Id);
});

it('F-17 bagian 3: perangkat versi lama menagih pesanan kirim tanpa ongkir = diterima + tinjauan OngkirBerbeda, bukan ditolak', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan] = PesanBayarQris($this, $k, 'Kirim');
    $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    WebhookBayarOnline($this, $k, TagihanQris::query()->sole()->NomorPesanan, $pesanan->Total)->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $uangMuka = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::UangMuka, 'Uang muka (DP)');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $pesanan->refresh()->forceFill(['Status' => StatusPesananOnline::Siap])->save();

    $item = BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '60000.00']],
        'Pembayaran' => [['Metode' => $uangMuka, 'Jumlah' => '60000.00']],
    ], ['UuidPesananOnline' => $pesanan->Uuid]);
    expect(array_key_exists('BiayaKirim', $item['Data']))->toBeFalse();
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

    expect($penjualan->BiayaKirim)->toBe('0.00')
        ->and($penjualan->PerluTinjauan)->toBeTrue()
        ->and($penjualan->AlasanTinjauan)->toContain('OngkirBerbeda')
        ->and($penjualan->AlasanTinjauan)->toContain('12.000');
});

/**
 * Pesanan kirim berbayar QRIS dengan promo gratis ongkir aktif, sudah dibayar dan siap ditagih kasir.
 *
 * @param  array<string, mixed>  $k
 * @return array{0: PesananOnline, 1: mixed, 2: mixed}
 */
function SiapkanPesananGratisOngkir(TestCase $tes, array $k): array
{
    $promo = BantuanTokoOnline::BuatPromoGratisOngkir($k);
    [$pesanan] = PesanBayarQris($tes, $k, 'Kirim');
    $tes->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    WebhookBayarOnline($tes, $k, TagihanQris::query()->sole()->NomorPesanan, $pesanan->Total)->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $uangMuka = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::UangMuka, 'Uang muka (DP)');
    $pesanan->refresh()->forceFill(['Status' => StatusPesananOnline::Siap])->save();

    return [$pesanan, $uangMuka, $promo];
}

it('F-16c gratis ongkir: pesanan bergratis ongkir ditagih dengan BiayaKirim + DiskonKirim. Ongkir kotor ke Pendapatan Pengiriman, potongan ke Diskon Penjualan, pemakaian promo tercatat', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan, $uangMuka, $promo] = SiapkanPesananGratisOngkir($this, $k);
    // Pembeli membayar di muka Rp 60.000 saja: ongkir Rp 12.000 sudah digratiskan di checkout.
    expect($pesanan->Ongkir)->toBe('12000.00')->and($pesanan->DiskonOngkir)->toBe('12000.00')->and($pesanan->Total)->toBe('60000.00');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);

    $item = BantuanPenjualan::Item($k, [
        'BiayaKirim' => '12000.00',
        'DiskonKirim' => '12000.00',
        'Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '60000.00']],
        'Pembayaran' => [['Metode' => $uangMuka, 'Jumlah' => '60000.00']],
    ], ['UuidPesananOnline' => $pesanan->Uuid]);
    expect($item['Data']['Ringkasan']['TotalAkhir'])->toBe('60000.00');
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    $o = $k['Outlet']->Id;
    $pakai = PromoPemakaian::query()->where('IdPenjualan', $penjualan->Id)->get();

    expect($penjualan->BiayaKirim)->toBe('12000.00')
        ->and($penjualan->DiskonKirim)->toBe('12000.00')
        ->and($penjualan->TotalAkhir)->toBe('60000.00')
        ->and($penjualan->AlasanTinjauan ?? '')->not->toContain('OngkirBerbeda')
        ->and($penjualan->AlasanTinjauan ?? '')->not->toContain('PromoBerbeda')
        // Ongkir kotor Rp 12.000 sebagai pendapatan, potongannya sebagai Diskon Penjualan: biaya promo terbaca di laporan.
        ->and(SaldoPeranOnline((int) $penjualan->IdJurnal, PeranAkun::PendapatanPengiriman, $o))->toBe('-12000.00')
        ->and(SaldoPeranOnline((int) $penjualan->IdJurnal, PeranAkun::DiskonPenjualan, $o))->toBe('12000.00')
        ->and($pakai)->toHaveCount(1)
        ->and($pakai->first()->IdPromo)->toBe($promo->Id)
        ->and($pakai->first()->JumlahDiskon)->toBe('12000.00')
        ->and($promo->refresh()->KuotaTerpakai)->toBe(1);
});

it('F-16c gratis ongkir: kasir yang menagih ongkir penuh padahal pesanan bergratis ongkir = diterima + tinjauan OngkirBerbeda dan PromoBerbeda', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan, $uangMuka] = SiapkanPesananGratisOngkir($this, $k);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);

    // Aplikasi yang tidak mengenal promo gratis ongkir: ongkir kotor ikut, potongannya tidak.
    $item = BantuanPenjualan::Item($k, [
        'BiayaKirim' => '12000.00',
        'Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '60000.00']],
        'Pembayaran' => [
            ['Metode' => $uangMuka, 'Jumlah' => '60000.00'],
            ['Metode' => $k['Tunai'], 'Jumlah' => '12000.00'],
        ],
    ], ['UuidPesananOnline' => $pesanan->Uuid]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

    expect($penjualan->PerluTinjauan)->toBeTrue()
        ->and($penjualan->AlasanTinjauan)->toContain('OngkirBerbeda')
        ->and($penjualan->AlasanTinjauan)->toContain('PromoBerbeda')
        ->and($penjualan->AlasanTinjauan)->toContain('diskon ongkir perangkat Rp 0. Server Rp 12.000')
        // Tanpa potongan, tidak ada yang dicatat sebagai pemakaian promo.
        ->and(PromoPemakaian::query()->where('IdPenjualan', $penjualan->Id)->count())->toBe(0);
});

it('pesanan berbayar yang ditolak muncul di Kotak Tindakan dan pengembaliannya dibukukan sekali (J-17.2)', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan] = PesanBayarQris($this, $k);
    $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    WebhookBayarOnline($this, $k, TagihanQris::query()->sole()->NomorPesanan, '60000.00')->assertOk();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->post("/kelola/toko-online/pesanan/{$pesanan->Uuid}/status", ['Status' => 'Ditolak', 'Alasan' => 'Stok bahan habis'])
        ->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $konteksTindakan = new DataKonteksTindakan($k['Tenant']->Id, $k['Pemilik']->Id, true, [], null, CarbonImmutable::now('Asia/Jakarta')->startOfDay());
    $butir = app(PenyediaTindakanPenjualan::class)->Kumpulkan($konteksTindakan);
    $refund = null;
    foreach ($butir as $b) {
        if ($b->kunci === 'pesanan-online.uang-muka-belum-kembali') {
            $refund = $b;
        }
    }
    expect($refund)->not->toBeNull()->and($refund->jumlah)->toBe(1);

    $akun = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
    $this->post("/kelola/toko-online/pesanan/{$pesanan->Uuid}/kembalikan-uang", ['UuidAkun' => $akun->Uuid, 'Alasan' => 'Ditransfer ulang ke pelanggan'])
        ->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan->refresh();
    expect($pesanan->DikembalikanPada)->not->toBeNull()
        ->and($pesanan->IdJurnalRefund)->not->toBeNull()
        ->and($pesanan->AmbilSisaUangMuka()->KeString())->toBe('0.00');
    $jurnal = Jurnal::query()->whereKey($pesanan->IdJurnalRefund)->sole();
    expect($jurnal->TotalDebit)->toBe($jurnal->TotalKredit)
        ->and(SaldoPeranOnline($jurnal->Id, PeranAkun::UangMukaPelanggan, $pesanan->IdOutlet))->toBe('60000.00');

    $this->post("/kelola/toko-online/pesanan/{$pesanan->Uuid}/kembalikan-uang", ['UuidAkun' => $akun->Uuid, 'Alasan' => 'Ditransfer ulang ke pelanggan'])
        ->assertSessionHasErrors();
});

it('E8: pesanan berbayar yang belum ditagih di kasir lebih dari 12 jam muncul di Kotak Tindakan, yang baru dibayar atau sudah dibatalkan tidak', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan] = PesanBayarQris($this, $k);
    $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    WebhookBayarOnline($this, $k, TagihanQris::query()->sole()->NomorPesanan, '60000.00')->assertOk();
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->post("/kelola/toko-online/pesanan/{$pesanan->Uuid}/status", ['Status' => 'Dikonfirmasi'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    $konteks = new DataKonteksTindakan($k['Tenant']->Id, $k['Pemilik']->Id, true, [], null, CarbonImmutable::now('Asia/Jakarta')->startOfDay());
    $cari = fn () => collect(app(PenyediaTindakanPenjualan::class)->Kumpulkan($konteks))->firstWhere('kunci', 'pesanan-online.berbayar-belum-ditagih');

    // Baru dibayar: kasir masih punya waktu menagih.
    expect($cari())->toBeNull();

    $pesanan->refresh()->forceFill(['DibayarPada' => now()->subHours(13)])->save();
    $butir = $cari();
    expect($butir)->not->toBeNull()->and($butir->jumlah)->toBe(1)->and($butir->rincian[0]->judul)->toBe($pesanan->Nomor);

    // Pesanan yang akhirnya dibatalkan punya butir pengembalian uangnya sendiri.
    $this->post("/kelola/toko-online/pesanan/{$pesanan->Uuid}/status", ['Status' => 'Dibatalkan', 'Alasan' => 'Stok bahan habis'])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($cari())->toBeNull();
});

it('pesanan yang tidak dibayar hangus sesuai batas QRIS, yang sudah dibayar tidak pernah hangus', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$belum] = PesanBayarQris($this, $k);
    [$dibayar] = PesanBayarQris($this, $k);
    $this->postJson("/{$k['Slug']}/pesanan/{$dibayar->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    WebhookBayarOnline($this, $k, TagihanQris::query()->where('IdPesananOnline', $dibayar->Id)->sole()->NomorPesanan, '60000.00')->assertOk();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PesananOnline::query()->update(['DibuatPada' => now()->subMinutes(40)]);
    $this->artisan('pesanan-online:kedaluwarsa')->assertSuccessful();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PesananOnline::query()->whereKey($belum->Id)->value('Status'))->toBe(StatusPesananOnline::Kedaluwarsa)
        ->and(PesananOnline::query()->whereKey($dibayar->Id)->value('Status'))->toBe(StatusPesananOnline::MenungguKonfirmasi);
});

it('pesanan hangus dihitung dari QR terakhir: QR yang baru dibuka pembeli menahan pesanan, QR yang sudah lewat tenggang tidak', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$qrBaru] = PesanBayarQris($this, $k);
    [$qrBasi] = PesanBayarQris($this, $k);
    $this->postJson("/{$k['Slug']}/pesanan/{$qrBaru->KodeAkses}/bayar")->assertCreated();
    $this->postJson("/{$k['Slug']}/pesanan/{$qrBasi->KodeAkses}/bayar")->assertCreated();

    // Kedua pesanan dibuat 40 menit lalu; QR pertama dibuat baru saja, QR kedua sudah kedaluwarsa 30 menit lalu.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PesananOnline::query()->update(['DibuatPada' => now()->subMinutes(40)]);
    TagihanQris::query()->where('IdPesananOnline', $qrBasi->Id)->update(['KedaluwarsaPada' => now()->subMinutes(30)]);
    $this->artisan('pesanan-online:kedaluwarsa')->assertSuccessful();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PesananOnline::query()->whereKey($qrBaru->Id)->value('Status'))->toBe(StatusPesananOnline::MenungguPembayaran)
        ->and(PesananOnline::query()->whereKey($qrBasi->Id)->value('Status'))->toBe(StatusPesananOnline::Kedaluwarsa);
});

it('gerbang dimatikan setelah QRIS menyala: etalase tidak menawarkan QRIS dan checkout QRIS ditolak sebelum pesanan menggantung', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    $this->getJson('/'.$k['Slug'])->assertOk();
    $this->get('/'.$k['Slug'])->assertInertia(fn (AssertableInertia $h) => $h->where('Pembayaran.QrisOnline', true));

    BantuanGerbangTenant::Nonaktifkan($k['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PengaturanTokoOnline::query()->sole()->QrisAktif)->toBeTrue();

    $this->get('/'.$k['Slug'])->assertInertia(fn (AssertableInertia $h) => $h->where('Pembayaran.QrisOnline', false));
    $this->postJson('/'.$k['Slug'].'/pesan', [...BantuanTokoOnline::Kiriman($k), 'MetodePembayaran' => 'QrisOnline'])
        ->assertUnprocessable()->assertJsonPath('Galat.Kode', 'MetodePembayaranTidakAktif');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PesananOnline::query()->count())->toBe(0);
});

it('sakelar QRIS tidak bisa dinyalakan tanpa gerbang pembayaran aktif', function (): void {
    $k = BantuanTokoOnline::Siapkan($this);
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->put('/kelola/toko-online/pengaturan', [
        'Outlet' => $k['Outlet']->Uuid, 'Aktif' => true, 'TokoOnlineAktif' => true,
        'AmbilSendiriAktif' => true, 'KirimAktif' => true, 'BayarSaatAmbilAktif' => true, 'CodAktif' => true,
        'QrisAktif' => true, 'MinimalPesanan' => '10000.00', 'MenitKedaluwarsa' => 120, 'PesanTutup' => null,
    ])->assertSessionHasErrors('QrisAktif');
});

it('X7: uang muka pesanan online yang masuk memicu pembayaran.diterima sekali, walau notifikasi gerbang berulang', function (): void {
    $status = 'PENDING';
    PalsukanGerbangOnline($status);
    $k = SiapkanBayarOnline($this);
    [$pesanan] = PesanBayarQris($this, $k);
    $this->postJson("/{$k['Slug']}/pesanan/{$pesanan->KodeAkses}/bayar")->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $tagihan = TagihanQris::query()->sole();
    Event::fake([PeristiwaIntegrasi::class]);

    WebhookBayarOnline($this, $k, $tagihan->NomorPesanan, '60000.00')->assertOk();
    WebhookBayarOnline($this, $k, $tagihan->NomorPesanan, '60000.00')->assertOk();

    Event::assertDispatchedTimes(PeristiwaIntegrasi::class, 1);
    Event::assertDispatched(PeristiwaIntegrasi::class, fn (PeristiwaIntegrasi $p): bool => $p->jenis === 'pembayaran.diterima'
        && $p->idTenant === $k['Tenant']->Id
        && $p->data['Sumber'] === 'PesananOnline'
        && $p->data['Uuid'] === $pesanan->Uuid
        && $p->data['Jumlah'] === '60000.00'
        && $p->data['Metode'] === 'QRIS Otomatis'
        && $p->data['Alokasi'] === []);
});
