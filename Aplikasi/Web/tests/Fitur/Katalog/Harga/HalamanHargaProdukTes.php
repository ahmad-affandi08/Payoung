<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Harga\Aksi\SimpanHargaProduk;
use App\Domain\Katalog\Harga\Enum\SumberHarga;
use App\Domain\Katalog\Harga\Enum\SumberPerubahanHarga;
use App\Domain\Katalog\Harga\Kueri\HargaProdukBerlaku;
use App\Domain\Katalog\Harga\Model\DaftarHarga;
use App\Domain\Katalog\Harga\Model\RiwayatHarga;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->t = BantuanKatalog::SiapkanTenantProduk();
    BantuanFitur::Beri($this->t['Tenant']->Id, 'harga.daftar-harga');
    $this->produk = BantuanKatalog::BuatProduk(['Nama' => 'Sabun Mandi Cair Aroma Sereh Wangi 450 ml'], '5000.00', $this->t['Pcs']);
    $this->pcs = BantuanHarga::SatuanDasar($this->produk);
    $this->pak = BantuanHarga::TambahSatuan($this->produk, BantuanKatalog::BuatSatuan('Pak', 'pak'), '10');
    $this->daftar = BantuanHarga::BuatDaftarHarga('Harga Grosir', ['Prioritas' => 10]);
    $this->url = "/kelola/produk/{$this->produk->Uuid}/harga";
});

describe('F-03 halaman harga produk (E.6)', function (): void {
    it('menampilkan satuan dengan harga dasar, daftar harga beserta harga produk, riwayat, label pajak, dan izin', function (): void {
        BantuanHarga::TambahHargaDaftar($this->daftar, $this->pcs, '12', '4000');

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->get($this->url)->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Produk/Harga')
                ->where('Kepala.Uuid', $this->produk->Uuid)
                ->has('Satuan', 2)
                ->where('Satuan.0.UuidProdukSatuan', $this->pcs->Uuid)
                ->where('Satuan.0.HargaDasar', [['JumlahMinimum' => '1.0000', 'Harga' => '5000.00']])
                ->where('Satuan.1.HargaDasar', [])
                ->where('DaftarHarga.0.Uuid', $this->daftar->Uuid)
                ->where('DaftarHarga.0.Ringkasan', 'Semua outlet | Semua kanal | Prioritas 10')
                ->where('DaftarHarga.0.Harga', [['JumlahMinimum' => '12.0000', 'Harga' => '4000.00', 'UuidProdukSatuan' => $this->pcs->Uuid]])
                ->has('Riwayat.Data', 0)
                ->where('LabelHargaTermasukPajak', 'Ikut outlet: harga belum termasuk pajak')
                ->where('Izin.UbahHarga', true));
    });

    it('BR-03.3 menyimpan harga dasar & bertingkat; riwayat mencatat pengguna dari konteks audit dan tampil di halaman', function (): void {
        $pemilik = $this->t['Pemilik'];
        $tes = BantuanOrganisasi::Masuk($this, $pemilik, $this->t['Tenant']->Id);

        $tes->put($this->url, ['Satuan' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '5000'], ['JumlahMinimum' => '12', 'Harga' => '4500']]],
            ['UuidProdukSatuan' => $this->pak->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '45000']]],
        ]])->assertSessionHasNoErrors()->assertRedirect();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '5000.00', '12.0000' => '4500.00'])
            ->and(BantuanHarga::HargaDasar($this->pak))->toBe(['1.0000' => '45000.00'])
            ->and(RiwayatHarga::query()->pluck('DiubahOleh')->unique()->all())->toBe([$pemilik->Id]);

        BantuanOrganisasi::Masuk($this, $pemilik, $this->t['Tenant']->Id)->get($this->url)
            ->assertInertia(fn (AssertableInertia $h) => $h->has('Riwayat.Data', 2)
                ->where('Riwayat.Data.0.NamaPengubah', $pemilik->Nama)
                ->where('Riwayat.Data.0.LabelSumber', 'Diubah manual'));
    });

    it('galat aturan bisnis kembali ke bidang satuan yang sama dengan body (Satuan.{i}.Harga…)', function (): void {
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($this->url, ['Satuan' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '5000']]],
            ['UuidProdukSatuan' => $this->pak->Uuid, 'Harga' => [['JumlahMinimum' => '5', 'Harga' => '42000']]],
        ]])->assertSessionHasErrors(['Satuan.1.Harga']);

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($this->url, ['Satuan' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '5000'], ['JumlahMinimum' => '1.5', 'Harga' => '4900']]],
        ]])->assertSessionHasErrors(['Satuan.0.Harga.1.JumlahMinimum']);

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($this->url, ['Satuan' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '5.000']]],
        ]])->assertSessionHasErrors(['Satuan.0.Harga.0.Harga']);
    });

    it('menyimpan harga produk di daftar harga dari halaman produk; galat baris rata dipetakan ke Harga.{k}', function (): void {
        $urlDaftar = "{$this->url}/daftar-harga/{$this->daftar->Uuid}";
        $tes = BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id);

        $tes->put($urlDaftar, ['Harga' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'JumlahMinimum' => '12', 'Harga' => '4000'],
            ['UuidProdukSatuan' => $this->pak->Uuid, 'JumlahMinimum' => '1', 'Harga' => '40000'],
        ]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(ProdukHarga::query()->where('IdDaftarHarga', $this->daftar->Id)->orderBy('Id')->pluck('Harga', 'IdProdukSatuan')->all())
            ->toBe([$this->pcs->Id => '4000.00', $this->pak->Id => '40000.00'])
            ->and(RiwayatHarga::query()->where('IdDaftarHarga', $this->daftar->Id)->count())->toBe(2);

        // Satuan tanpa baris di body dikeluarkan dari daftar; galat baris ke-2 (pcs kedua) → Harga.2.
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($urlDaftar, ['Harga' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'JumlahMinimum' => '12', 'Harga' => '4000'],
            ['UuidProdukSatuan' => $this->pak->Uuid, 'JumlahMinimum' => '1', 'Harga' => '40000'],
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'JumlahMinimum' => '12.0', 'Harga' => '3900'],
        ]])->assertSessionHasErrors(['Harga.2.JumlahMinimum']);

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($urlDaftar, ['Harga' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'JumlahMinimum' => '12', 'Harga' => '4000'],
        ]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(ProdukHarga::query()->where('IdDaftarHarga', $this->daftar->Id)->pluck('IdProdukSatuan')->all())->toBe([$this->pcs->Id]);
    });

    it('penentuan harga lewat halaman: daftar harga yang dibuat & diisi dari halaman dipakai HargaProdukBerlaku', function (): void {
        $outletUtama = $this->t['Outlet'];
        $tes = BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id);
        $tes->post('/kelola/daftar-harga', [
            'Nama' => 'Harga Ojek Online', 'UuidOutlet' => [$outletUtama->Uuid], 'Kanal' => 'Online', 'TierPelanggan' => '',
            'MulaiPada' => '', 'SelesaiPada' => '', 'Prioritas' => '20',
        ])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        $online = DaftarHarga::query()->where('Nama', 'Harga Ojek Online')->sole();

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put("{$this->url}/daftar-harga/{$online->Uuid}", ['Harga' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'JumlahMinimum' => '1', 'Harga' => '5500'],
        ]])->assertSessionHasNoErrors();
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id)->put($this->url, ['Satuan' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '5000'], ['JumlahMinimum' => '12', 'Harga' => '4500']]],
        ]])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        $berlaku = app(HargaProdukBerlaku::class);
        $waktu = CarbonImmutable::parse('2026-10-01T03:00:00Z');
        $tentukan = fn (string $jumlah, ?KanalPenjualan $kanal) => $berlaku->Tentukan($this->produk, $this->pcs, Kuantitas::Dari($jumlah), $outletUtama->Id, $kanal, null, $waktu);

        expect([$tentukan('1', KanalPenjualan::Online)?->harga->KeString(), $tentukan('1', KanalPenjualan::Online)?->sumber])->toBe(['5500.00', SumberHarga::DaftarHarga])
            ->and($tentukan('1', KanalPenjualan::Online)?->uuidDaftarHarga)->toBe($online->Uuid)
            ->and([$tentukan('12', KanalPenjualan::MakanDiTempat)?->harga->KeString(), $tentukan('12', KanalPenjualan::MakanDiTempat)?->sumber])->toBe(['4500.00', SumberHarga::Bertingkat])
            ->and($tentukan('3', null)?->harga->KeString())->toBe('5000.00')
            ->and($berlaku->Tentukan($this->produk, $this->pak, Kuantitas::Dari('1'), $outletUtama->Id, null, null, $waktu))->toBeNull();
    });

    it('izin: Kasir melihat halaman tetapi 403 saat menyimpan; Manajer Outlet tanpa produk.harga.ubah juga 403', function (): void {
        $body = ['Satuan' => [['UuidProdukSatuan' => $this->pcs->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '1']]]]];

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir)->get($this->url)->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.UbahHarga', false));
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir)->put($this->url, $body)->assertForbidden();
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->put($this->url, $body)->assertForbidden();
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)
            ->put("{$this->url}/daftar-harga/{$this->daftar->Uuid}", ['Harga' => []])->assertForbidden();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '5000.00']);
    });

    it('isolasi tenant: produk dan daftar harga tenant lain = 404', function (): void {
        $lain = BantuanKatalog::SiapkanTenantProduk('Toko Makmur Jaya');
        $masukLain = fn () => BantuanKatalog::MasukSebagai($this, $lain['Tenant']->Id);

        $masukLain()->get($this->url)->assertNotFound();
        $masukLain()->put($this->url, ['Satuan' => []])->assertNotFound();

        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        $produkLain = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit 2 L'], '32000.00', $lain['Pcs']);
        $masukLain()->put("/kelola/produk/{$produkLain->Uuid}/harga/daftar-harga/{$this->daftar->Uuid}", ['Harga' => []])->assertNotFound();
        $masukLain()->put("/kelola/produk/{$produkLain->Uuid}/harga", ['Satuan' => [
            ['UuidProdukSatuan' => $this->pcs->Uuid, 'Harga' => [['JumlahMinimum' => '1', 'Harga' => '1']]],
        ]])->assertSessionHasErrors(['Satuan.0.UuidProdukSatuan']);

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect(BantuanHarga::HargaDasar($this->pcs))->toBe(['1.0000' => '5000.00']);
    });
});

it('BR-03.3 RiwayatHarga.DiubahOleh diambil dari konteks audit (misal job impor) tanpa sesi web', function (): void {
    $pengguna = Pengguna::factory()->create();
    app(PencatatAudit::class)->AturKonteks($pengguna->Id, null, null);

    app(SimpanHargaProduk::class)->Jalankan($this->produk, [$this->pcs->Id => [BantuanHarga::Baris('1', '5100')]], SumberPerubahanHarga::Impor);

    expect(RiwayatHarga::query()->sole()->only(['DiubahOleh', 'HargaBaru']))->toBe(['DiubahOleh' => $pengguna->Id, 'HargaBaru' => '5100.00']);
});
