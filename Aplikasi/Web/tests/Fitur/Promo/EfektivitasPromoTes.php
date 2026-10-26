<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Promo\Model\Promo;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-16c bagian 4c laporan efektivitas promo: jumlah pakai & potongan (tanpa yang di-void), penjualan barang promo selama
 * promo vs periode yang sama panjang sebelum promo, dan uplift.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-16c bagian 4c efektivitas promo', function (): void {
    it('uplift = (bersih selama promo − bersih sebelum) ÷ sebelum, periode pembanding sama panjang. Promo belum mulai', function (): void {
        // Hari ini (WIB) 2026-10-10; promo mulai 2026-10-08 → periode 3 hari, pembanding 2026-10-05 s.d. 2026-10-07.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 05:00:00', 'UTC'));
        $k = BantuanPenjualan::Siapkan($this, 'Kopi Senja Uplift');
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $promo = Promo::query()->create([
            'Kode' => 'KOPI10',
            'Nama' => 'Diskon 10% kopi susu',
            'MulaiPada' => CarbonImmutable::parse('2026-10-08 00:00:00', 'Asia/Jakarta')->utc(),
            'Definisi' => [
                'Hari' => [], 'JamMulai' => null, 'JamSelesai' => null, 'Outlet' => [], 'Kanal' => [], 'Tier' => [],
                'MinimalSubtotal' => '0.00',
                'Kondisi' => ['Jenis' => 'Produk', 'Uuid' => [$produk->Uuid], 'JumlahMinimal' => '0.0000'],
                'Aksi' => ['Jenis' => 'DiskonPersenItem', 'Persen' => '10'],
                'BatasPerTransaksi' => null,
            ],
        ]);
        $k += ['Produk' => $produk, 'Promo' => $promo];

        // Sebelum promo: 1 × 38.500.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']]]),
        ]))->toBe([['Diterima', null]]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->get("/kelola/promo/{$promo->Uuid}/efektivitas")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Promo/Efektivitas')
            ->where('Efektivitas.Berjalan', false)
            ->where('Efektivitas.Periode.Dari', '2026-10-08'));

        // Selama promo: 2 transaksi × (2 × 38.500 − 10%) = 2 × 69.300.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 05:00:00', 'UTC'));
        $item = fn (): array => BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00']],
            'Promo' => [['Promo' => $promo, 'Baris' => [0 => '7700.00']]],
        ]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item(), $item()]))->toBe([['Diterima', null], ['Diterima', null]]);

        $this->travelTo(CarbonImmutable::parse('2026-10-10 05:00:00', 'UTC'));
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->get("/kelola/promo/{$promo->Uuid}/efektivitas")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Efektivitas.Berjalan', true)
            ->where('Efektivitas.Periode', ['Dari' => '2026-10-08', 'Sampai' => '2026-10-10', 'Hari' => 3])
            ->where('Efektivitas.Pembanding', ['Dari' => '2026-10-05', 'Sampai' => '2026-10-07'])
            ->where('Efektivitas.JumlahPakai', 2)
            ->where('Efektivitas.TotalPotongan', '15400.00')
            ->where('Efektivitas.RataPotongan', '7700.00')
            ->where('Efektivitas.Sekarang.Bersih', '138600.00')
            ->where('Efektivitas.Sekarang.JumlahTransaksi', 2)
            ->where('Efektivitas.Sekarang.Qty', '4.0000')
            ->where('Efektivitas.Sekarang.RataHarian', '46200.00')
            ->where('Efektivitas.Sebelum.Bersih', '38500.00')
            // (138.600 − 38.500) ÷ 38.500 = 260,0%.
            ->where('Efektivitas.UpliftPersen', '260.0'));

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get("/kelola/promo/{$promo->Uuid}/efektivitas")->assertForbidden();
    });
});
