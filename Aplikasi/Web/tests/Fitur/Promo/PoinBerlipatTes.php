<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\MutasiPoin;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PengaturanLoyalti;
use App\Domain\Pelanggan\Model\TierPelanggan;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Promo\Model\Promo;
use App\Domain\Promo\Model\PromoPemakaian;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16c bagian 4: promo poin berlipat. Poin dihitung server, jadi promonya ditentukan server (tidak dibandingkan dengan
 * perangkat): perolehan = ⌊total ÷ belanja per poin × pengali tier × pengali promo⌋; pengali terbesar yang berlaku,
 * tidak bertumpuk; pemakaian (kuota & batas per pelanggan) dicatat hanya bila poin diperoleh.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $syarat
 */
function BuatPromoPoin(string $kode, string $pengali, array $syarat = [], ?int $kuota = null): Promo
{
    return Promo::query()->create([
        'Kode' => $kode,
        'Nama' => "Poin {$kode}",
        'Prioritas' => 0,
        'Kuota' => $kuota,
        'Definisi' => [
            'Hari' => [], 'JamMulai' => null, 'JamSelesai' => null, 'Outlet' => [], 'Kanal' => [], 'Tier' => [],
            'MinimalSubtotal' => '0.00',
            'Kondisi' => ['Jenis' => 'Semua', 'Uuid' => [], 'JumlahMinimal' => '0.0000'],
            'Aksi' => ['Jenis' => 'PoinBerlipat', 'Pengali' => $pengali],
            'BatasPerTransaksi' => null,
            ...$syarat,
        ],
    ]);
}

/**
 * Tenant kasir + pelanggan Ani + produk Rp 38.500 + loyalti aktif (Rp 10.000 = 1 poin).
 *
 * @return array<string, mixed>
 */
function SiapkanPoinBerlipat(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kopi Senja Poin Berlipat');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    PengaturanLoyalti::query()->create(['Aktif' => true]);

    return $k + ['Produk' => $produk, 'Ani' => Pelanggan::query()->create(['Nama' => 'Ani Rahmawati', 'NoHp' => '6281234567890'])];
}

/**
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemPoinBerlipat(array $k, ?Pelanggan $pelanggan): array
{
    return BantuanPenjualan::Item(
        $k,
        ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']]],
        $pelanggan === null ? [] : ['UuidPelanggan' => $pelanggan->Uuid],
    );
}

function PoinPenjualan(string $uuid): int
{
    $jual = Penjualan::query()->where('Uuid', $uuid)->sole();

    return (int) MutasiPoin::query()->where('IdSumber', $jual->Id)->sum('Poin');
}

describe('F-16c bagian 4 poin berlipat', function (): void {
    it('pengali promo × pengali tier. Pengali terbesar menang (tidak bertumpuk), tanpa tinjauan. Pemakaian tercatat Rp 0', function (): void {
        $k = SiapkanPoinBerlipat($this);
        $dua = BuatPromoPoin('POIN2X', '2');
        BuatPromoPoin('POIN3X-BESAR', '3', ['MinimalSubtotal' => '200000.00']);
        $item = ItemPoinBerlipat($k, $k['Ani']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // 77.000 ÷ 10.000 × 2 = 15,4 → 15 (tanpa promo 7).
        expect(PoinPenjualan($item['Uuid']))->toBe(15)
            ->and(Penjualan::query()->where('Uuid', $item['Uuid'])->sole()->PerluTinjauan)->toBeFalse()
            ->and(PromoPemakaian::query()->where('IdPromo', $dua->Id)->sole()->JumlahDiskon)->toBe('0.00')
            ->and($dua->fresh()?->KuotaTerpakai)->toBe(1);

        // Struk digital menampilkan poin pasti dari server (termasuk pengali promo).
        $this->get('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $item['Uuid']))->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.PoinDiperoleh', 15));
        $tanpa = ItemPoinBerlipat($k, null);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpa]);
        $this->get('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $tanpa['Uuid']))->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.PoinDiperoleh', null));
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        // Tier Gold ×1,5 dan promo ×1,5 lagi (promo 2× tetap berlaku, pengali terbesar = 2).
        $gold = TierPelanggan::query()->create(['Kode' => 'GOLD', 'Nama' => 'Gold', 'MinimalBelanja' => '0', 'PengaliPoin' => '1.50']);
        $k['Ani']->forceFill(['IdTier' => $gold->Id])->save();
        BuatPromoPoin('POIN15X', '1.5');
        $kedua = ItemPoinBerlipat($k, $k['Ani']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$kedua]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // 77.000 × 1,5 × 2 ÷ 10.000 = 23,1 → 23.
        expect(PoinPenjualan($kedua['Uuid']))->toBe(23);
    });

    it('tanpa pelanggan tidak ada poin dan pemakaian tidak dicatat. Kuota habis tetap diterima + tinjauan', function (): void {
        $k = SiapkanPoinBerlipat($this);
        $promo = BuatPromoPoin('POIN2X-TERBATAS', '2', kuota: 1);

        $tanpa = ItemPoinBerlipat($k, null);
        $satu = ItemPoinBerlipat($k, $k['Ani']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpa, $satu]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PromoPemakaian::query()->where('IdPromo', $promo->Id)->count())->toBe(1)
            ->and(PoinPenjualan($satu['Uuid']))->toBe(15);

        // Kuota sudah terpakai: server tidak lagi menerapkan promo (kuota tersisa 0) → poin biasa.
        $dua = ItemPoinBerlipat($k, $k['Ani']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$dua]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PoinPenjualan($dua['Uuid']))->toBe(7);
    });

    it('formulir: pengali lebih dari 1 sampai 10 dengan satu desimal disimpan ke Definisi.Aksi', function (): void {
        $k = SiapkanPoinBerlipat($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $isian = [
            'Kode' => 'POIN15', 'Nama' => 'Poin 1,5 kali akhir pekan', 'Prioritas' => 0, 'Eksklusif' => false,
            'TanggalMulai' => null, 'TanggalSelesai' => null, 'Kuota' => null, 'Hari' => [6, 7], 'JamMulai' => null, 'JamSelesai' => null,
            'Outlet' => [], 'Kanal' => [], 'Tier' => [], 'MinimalSubtotal' => '0', 'JenisKondisi' => 'Semua', 'UuidKondisi' => [],
            'JumlahMinimal' => '0', 'JenisAksi' => 'PoinBerlipat', 'Pengali' => '1.5',
        ];

        foreach (['1', '10.5', '1.25', null] as $salah) {
            $this->post('/kelola/promo', [...$isian, 'Pengali' => $salah])->assertSessionHasErrors('Pengali');
        }

        $this->post('/kelola/promo', $isian)->assertRedirect('/kelola/promo');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Promo::query()->where('Kode', 'POIN15')->sole()->Definisi['Aksi'])->toBe(['Jenis' => 'PoinBerlipat', 'Pengali' => '1.5']);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/promo?voucher=1&lanjutan=1')->assertOk()
            ->assertJsonPath('Promo.0.Definisi.Aksi.Jenis', 'PoinBerlipat');
    });
});
