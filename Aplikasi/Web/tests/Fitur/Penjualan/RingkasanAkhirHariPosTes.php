<?php

declare(strict_types=1);

use App\Domain\Penjualan\Model\PenjualanDetail;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-24: `GET /api/pos/v1/ringkasan-harian` ringkasan akhir hari outlet untuk kasir dari dokumen langsung: transaksi,
 * void, retur, bersih, uang per metode, per kasir; tanpa HPP/laba; tanggal masa depan ditolak; tenant lain kosong.
 */

beforeEach(function (): void {
    // Tanggal bisnis outlet = WIB; CI berjalan di UTC sehingga di malam hari UTC "hari ini" sudah berganti. Waktu dibekukan.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:30', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('ringkasan hari ini: dua penjualan, satu void, satu retur. Per metode & per kasir, tanpa HPP', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $jual = fn () => BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '77000.00']]]);
    $p1 = $jual();
    $jual();
    $divoid = $jual();
    $d = PenjualanDetail::query()->where('IdPenjualan', $p1->Id)->sole();
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
        BantuanPenjualan::ItemVoid($k, $divoid),
        BantuanPenjualan::ItemRetur($k, $p1, [['Detail' => $d, 'Jumlah' => '1']]),
    ]))->toBe([['Diterima', null], ['Diterima', null]]);

    $hasil = $this->withToken($k['Token'])->getJson('/api/pos/v1/ringkasan-harian')->assertOk()
        ->assertJsonPath('JumlahTransaksi', 2)
        ->assertJsonPath('JumlahVoid', 1)
        ->assertJsonPath('JumlahRetur', 1)
        ->assertJsonPath('Kotor', '154000.00')
        ->assertJsonPath('Retur', '38500.00')
        ->assertJsonPath('Bersih', '115500.00')
        ->assertJsonPath('PerKasir.0.Nama', $k['Kasir']->Nama)
        ->assertJsonPath('PerKasir.0.JumlahTransaksi', 2)
        ->assertJsonPath('PerMetodeBayar.0.Jenis', 'Tunai')
        ->assertJsonPath('PerMetodeBayar.0.Jumlah', '115500.00')
        ->json();
    expect($hasil)->not->toHaveKey('Hpp');

    $this->withToken($k['Token'])->getJson('/api/pos/v1/ringkasan-harian?tanggal='.now()->addDays(3)->toDateString())->assertUnprocessable();
    $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain Makmur');
    $this->withToken($lain['Token'])->getJson('/api/pos/v1/ringkasan-harian')->assertOk()
        ->assertJsonPath('JumlahTransaksi', 0)->assertJsonPath('Bersih', '0.00')->assertJsonPath('PerKasir', []);
});
