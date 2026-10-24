<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Promo\Enum\StatusPemakaianVoucher;
use App\Domain\Promo\Model\Promo;
use App\Domain\Promo\Model\Voucher;
use App\Domain\Promo\Model\VoucherPemakaian;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-17 (v3.46): voucher berkode di checkout toko online. Perkiraan total memakai promo voucher (diperiksa, tidak
 * dipesan); checkout memesan voucher atas Uuid pesanan; kasir menerima voucher di daftar pesanan dan saat menagih
 * pesanan, pesanan voucher berpindah ke penjualan dan terpakai; pesanan ditolak/batal melepasnya; kode salah dibatasi.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    RateLimiter::clear('voucher-toko-online');
});

/** @return array<string, mixed> */
function SiapkanVoucherOnline(TestCase $tes, ?int $maksimalPakai = 1): array
{
    $k = BantuanTokoOnline::Siapkan($tes);
    // Fitur promo dinyalakan lewat helper yang sama dengan gratis ongkir; promo itu sendiri dinonaktifkan.
    BantuanTokoOnline::BuatPromoGratisOngkir($k, kode: 'TIDAK-DIPAKAI')->forceFill(['Status' => 'Diarsipkan'])->save();
    $promo = Promo::query()->create([
        'Kode' => 'VCR-ONLINE',
        'Nama' => 'Potongan Rp 5.000 belanja online',
        'Definisi' => ['WajibVoucher' => true, 'Aksi' => ['Jenis' => 'DiskonTetapPesanan', 'Jumlah' => '5000']],
    ]);
    $voucher = Voucher::query()->create(['IdPromo' => $promo->Id, 'Kode' => 'ONLINE5K', 'MaksimalPakai' => $maksimalPakai]);

    return $k + ['Promo' => $promo, 'Voucher' => $voucher];
}

/** @param array<string, mixed> $k */
function HitungVoucherOnline(TestCase $tes, array $k, ?string $kode): TestResponse
{
    $kiriman = BantuanTokoOnline::Kiriman($k);

    return $tes->postJson("/{$k['Slug']}/keranjang/hitung", [
        'Outlet' => $kiriman['Outlet'], 'JenisPemenuhan' => 'AmbilSendiri', 'Baris' => $kiriman['Baris'], 'KodeVoucher' => $kode,
    ]);
}

it('perkiraan: voucher sah memotong total tanpa dipesan; kode salah = masalah bidang KodeVoucher', function (): void {
    $k = SiapkanVoucherOnline($this);

    $tanpa = HitungVoucherOnline($this, $k, null)->assertOk()->assertJsonPath('Voucher', null)->json();
    $dengan = HitungVoucherOnline($this, $k, 'online5k')->assertOk()
        ->assertJsonPath('Voucher.Kode', 'ONLINE5K')
        ->assertJsonPath('Voucher.NamaPromo', 'Potongan Rp 5.000 belanja online')
        ->assertJsonPath('Diskon', '5000.00')
        ->json();
    expect(Uang::Dari((string) $tanpa['Total'])->Kurangi(Uang::Dari((string) $dengan['Total']))->KeString())->toBe('5000.00');

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(VoucherPemakaian::query()->count())->toBe(0);

    HitungVoucherOnline($this, $k, 'SALAH-123')->assertNotFound()->assertJsonPath('Galat.Kode', 'VoucherTidakDitemukan');
});

it('checkout memesan voucher atas pesanan; kasir menagih dengan voucher → terpakai di penjualan; jatah habis untuk pesanan lain', function (): void {
    $k = SiapkanVoucherOnline($this);
    $kiriman = [...BantuanTokoOnline::Kiriman($k), 'KodeVoucher' => 'ONLINE5K'];
    $this->postJson("/{$k['Slug']}/pesan", $kiriman)->assertCreated();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan = PesananOnline::query()->where('Uuid', $kiriman['Uuid'])->sole();
    expect($pesanan->KodeVoucher)->toBe('ONLINE5K')
        ->and((string) $pesanan->Diskon)->toBe('5000.00')
        ->and(VoucherPemakaian::query()->where('UuidPenjualan', $pesanan->Uuid)->value('Status'))->toBe(StatusPemakaianVoucher::Dipesan);

    // Sekali pakai: pesanan kedua dengan voucher yang sama ditolak utuh (tidak ada pesanan setengah jadi).
    $kedua = [...BantuanTokoOnline::Kiriman($k), 'KodeVoucher' => 'ONLINE5K', 'NoHp' => '081299998888'];
    $this->postJson("/{$k['Slug']}/pesan", $kedua)->assertStatus(409)->assertJsonPath('Galat.Kode', 'VoucherHabis');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PesananOnline::query()->count())->toBe(1);

    // Kasir menerima voucher (dengan definisi promonya) di daftar pesanan.
    $daftar = $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-online')->assertOk()->json('Pesanan');
    expect($daftar[0]['Voucher']['Kode'])->toBe('ONLINE5K')
        ->and($daftar[0]['Voucher']['UuidPromo'])->toBe($k['Promo']->Uuid)
        ->and($daftar[0]['Voucher']['Promo']['Definisi']['WajibVoucher'])->toBeTrue();

    // Kasir menagih dengan Uuid penjualan baru + voucher + UuidPesananOnline: pesanan voucher berpindah & terpakai.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan->forceFill(['Status' => StatusPesananOnline::Siap])->save();
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $uuidJual = (string) Str::ulid();
    $item = BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']],
        'Promo' => [['Promo' => $k['Promo'], 'Baris' => [], 'Pesanan' => '5000.00']],
    ], ['UuidPesananOnline' => $pesanan->Uuid, 'Voucher' => 'ONLINE5K'], $uuidJual);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $uuidJual)->sole();
    $pemakaian = VoucherPemakaian::query()->sole();
    expect($pemakaian->Status)->toBe(StatusPemakaianVoucher::Dipakai)
        ->and($pemakaian->UuidPenjualan)->toBe($uuidJual)
        ->and($pemakaian->IdPenjualan)->toBe($penjualan->Id)
        ->and($k['Voucher']->refresh()->JumlahDipakai)->toBe(1)
        ->and($penjualan->AlasanTinjauan ?? '')->not->toContain('VoucherTidakBerlaku');
});

it('pesanan ditolak melepas voucher sehingga bisa dipakai lagi; kode salah berulang dibatasi', function (): void {
    $k = SiapkanVoucherOnline($this);
    $kiriman = [...BantuanTokoOnline::Kiriman($k), 'KodeVoucher' => 'ONLINE5K'];
    $this->postJson("/{$k['Slug']}/pesan", $kiriman)->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $pesanan = PesananOnline::query()->where('Uuid', $kiriman['Uuid'])->sole();

    $this->withToken($k['Token'])->postJson("/api/pos/v1/pesanan-online/{$pesanan->Uuid}/status", [
        'UuidPengguna' => $k['Kasir']->Uuid, 'Status' => 'Ditolak', 'Alasan' => 'Stok bahan habis',
    ])->assertOk();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(VoucherPemakaian::query()->sole()->Status)->toBe(StatusPemakaianVoucher::Dilepas);
    HitungVoucherOnline($this, $k, 'ONLINE5K')->assertOk()->assertJsonPath('Voucher.Kode', 'ONLINE5K');

    for ($i = 0; $i < 10; $i++) {
        HitungVoucherOnline($this, $k, "TEBAK{$i}")->assertNotFound();
    }
    HitungVoucherOnline($this, $k, 'TEBAK99')->assertStatus(429)->assertJsonPath('Galat.Kode', 'TerlaluBanyakPercobaanVoucher');
    // Tanpa voucher tetap bisa menghitung.
    HitungVoucherOnline($this, $k, null)->assertOk();
});
