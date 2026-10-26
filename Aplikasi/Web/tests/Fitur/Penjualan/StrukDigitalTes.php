<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('POS-11 struk digital /s/{kodeStruk}', function (): void {
    it('tanpa login menampilkan isi struk (tanpa HPP). Kode dari awalan data-awal + Uuid. Void ditandai', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
        $awalan = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->json('Struk.AwalanStrukDigital');
        $kode = KodeStrukDigital::Buat($k['Tenant']->Id, $p->Uuid);
        expect($awalan.$p->Uuid)->toBe(url('/s/'.$kode));

        $this->get('/s/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Publik/StrukDigital')
            ->where('Struk.Nomor', $p->Nomor)
            ->where('Struk.NamaUsaha', $k['Outlet']->Nama)
            ->where('Struk.TotalAkhir', '77000.00')
            ->where('Struk.Dibatalkan', false)
            ->has('Struk.Baris', 1)
            ->where('Struk.Baris.0.NamaProduk', 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter')
            ->missing('Struk.Baris.0.TotalHpp')
            ->missing('Struk.TotalHpp'));

        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]);
        $this->get('/s/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.Dibatalkan', true));
    });

    it('F-17 bagian 3: ongkir dan diskon ongkir ikut di struk (Total = barang + ongkir − diskon ongkir)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['BiayaKirim' => '20000.00', 'DiskonKirim' => '5000.00', 'Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);

        $this->get('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $p->Uuid))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Struk.Subtotal', '38500.00')
            ->where('Struk.BiayaKirim', '20000.00')
            ->where('Struk.DiskonKirim', '5000.00')
            ->where('Struk.TotalAkhir', '53500.00'));
    });

    it('tidak dikenal, tenant lain, dan struk digital dimatikan = halaman belum tersedia (404)', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Kopi Senja Solo');
        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $minyak = BantuanPenjualan::BuatProdukBerstok($a['Gudang'], $a['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $a, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);

        $this->get('/s/'.KodeStrukDigital::Buat($a['Tenant']->Id, (string) Str::ulid()))->assertNotFound()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Publik/StrukDigital')->where('Struk', null));
        // Uuid penjualan A dengan kode tenant B: tidak terbaca (scope tenant).
        $this->get('/s/'.KodeStrukDigital::Buat($b['Tenant']->Id, $p->Uuid))->assertNotFound();
        $this->get('/s/zzzzzzzzzzzz.'.$p->Uuid)->assertNotFound();
        $this->get('/s/bukan-kode')->assertNotFound();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->put('/kelola/kasir/struk', [
            'TampilkanLogo' => true, 'TampilkanAlamat' => true, 'TampilkanTelepon' => true, 'TampilkanNpwp' => true,
            'TampilkanKasir' => true, 'TampilkanPelanggan' => true, 'TampilkanHemat' => true, 'TampilkanStrukDigital' => false,
            'NamaDicetak' => null, 'TeksKepala' => [], 'CatatanKaki' => null, 'TeksPenutup' => null,
        ])->assertRedirect('/kelola/kasir/struk');
        $this->get('/s/'.KodeStrukDigital::Buat($a['Tenant']->Id, $p->Uuid))->assertNotFound();
        expect($this->withToken($a['Token'])->getJson('/api/pos/v1/data-awal')->json('Struk.AwalanStrukDigital'))->toBeNull();
    });
});
