<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Enum\JenisMutasiPoin;
use App\Domain\Pelanggan\Model\MutasiPoin;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PengaturanLoyalti;
use App\Domain\Pelanggan\Model\TierPelanggan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16b bagian 1 (PRD "Rincian F-16b"): tier pelanggan, pengaturan loyalti, perolehan poin di transaksi penjualan
 * (pengali tier, idempoten), pembalikan void & retur proporsional, kedaluwarsa FIFO & evaluasi tier harian, penyesuaian
 * manual, fitur paket `pelanggan.loyalti`, izin, dan data tier/poin di API POS.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant kasir + pelanggan Ani + produk Rp 38.500 (stok 10) + loyalti aktif (Rp 10.000 = 1 poin, 12 bulan).
 *
 * @return array<string, mixed>
 */
function SiapkanLoyalti(TestCase $tes, bool $aktif = true): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Toko Kelontong Berkah Loyal');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $ani = Pelanggan::query()->create(['Nama' => 'Ani Rahmawati', 'NoHp' => '6281234567890']);

    if ($aktif) {
        PengaturanLoyalti::query()->create(['Aktif' => true]);
    }

    return $k + ['Produk' => $produk, 'Ani' => $ani];
}

/**
 * @param  array<string, mixed>  $k
 */
function JualKe(TestCase $tes, array $k, Pelanggan $pelanggan, string $jumlah = '2'): Penjualan
{
    $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => $jumlah, 'Harga' => '38500.00']]], ['UuidPelanggan' => $pelanggan->Uuid]);
    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return Penjualan::query()->where('Uuid', $item['Uuid'])->firstOrFail();
}

function SaldoPoin(Pelanggan $p): int
{
    return (int) MutasiPoin::query()->where('IdPelanggan', $p->Id)->sum('Poin');
}

describe('F-16b perolehan & pembalikan poin', function (): void {
    it('poin = ⌊total ÷ belanja per poin × pengali tier⌋ di transaksi penjualan. Kirim ulang tidak menggandakan. Berlaku 12 bulan', function (): void {
        $k = SiapkanLoyalti($this);
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']]], ['UuidPelanggan' => $k['Ani']->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

        // 2 × 38.500 = 77.000 → 7 poin.
        $perolehan = MutasiPoin::query()->where('IdSumber', $jual->Id)->sole();
        expect($perolehan->Jenis)->toBe(JenisMutasiPoin::Perolehan)
            ->and($perolehan->Poin)->toBe(7)
            ->and($perolehan->Sisa)->toBe(7)
            ->and($perolehan->KedaluwarsaPada?->toDateString())->toBe($jual->TanggalBisnis->addMonthsNoOverflow(12)->toDateString());

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(MutasiPoin::query()->count())->toBe(1);

        $gold = TierPelanggan::query()->create(['Kode' => 'GOLD', 'Nama' => 'Gold', 'MinimalBelanja' => '0', 'PengaliPoin' => '1.50']);
        $k['Ani']->forceFill(['IdTier' => $gold->Id])->save();
        JualKe($this, $k, $k['Ani']);
        // 77.000 × 1,5 ÷ 10.000 = 11,55 → 11.
        expect(SaldoPoin($k['Ani']))->toBe(18);

        // Penjualan tanpa pelanggan tidak memberi poin.
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Produk'], 'Harga' => '38500.00']]]);
        expect(MutasiPoin::query()->count())->toBe(2);
    });

    it('loyalti nonaktif atau paket tanpa pelanggan.loyalti = tanpa poin', function (): void {
        $k = SiapkanLoyalti($this, aktif: false);
        JualKe($this, $k, $k['Ani']);
        expect(MutasiPoin::query()->count())->toBe(0);

        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Toko Starter Sejahtera', 'STARTER');
        BantuanOrganisasi::AturKonteks($tenant->Id);
        PengaturanLoyalti::query()->create(['Aktif' => true]);
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);
        $this->get('/kelola/pelanggan/loyalti')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pelanggan/PengaturanLoyalti')
            ->where('FiturAktif', false)
            ->where('Pengaturan.Aktif', true));
    });

    it('void membalik poin. Retur membalik proporsional terhadap refund kumulatif', function (): void {
        $k = SiapkanLoyalti($this);
        $jual = JualKe($this, $k, $k['Ani']);
        expect(SaldoPoin($k['Ani']))->toBe(7);

        $void = BantuanPenjualan::ItemVoid($k, $jual);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$void]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoPoin($k['Ani']))->toBe(0)
            ->and(MutasiPoin::query()->where('Jenis', JenisMutasiPoin::PembalikanVoid->value)->sole()->Poin)->toBe(-7);

        // Retur 1 dari 2 (refund 38.500 dari 77.000): poin bersih ⌊7 × 38.500 ÷ 77.000⌋ = 3 → balik 4; lalu sisanya → balik 3.
        $jual2 = JualKe($this, $k, $k['Ani']);
        $detail = PenjualanDetail::query()->where('IdPenjualan', $jual2->Id)->sole();
        $retur1 = BantuanPenjualan::ItemRetur($k, $jual2, [['Detail' => $detail, 'Jumlah' => '1']]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur1]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoPoin($k['Ani']))->toBe(3);

        $retur2 = BantuanPenjualan::ItemRetur($k, $jual2, [['Detail' => $detail, 'Jumlah' => '1']]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur2]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoPoin($k['Ani']))->toBe(0)
            ->and(MutasiPoin::query()->where('Jenis', JenisMutasiPoin::PembalikanRetur->value)->pluck('Poin')->all())->toBe([-4, -3]);
    });
});

describe('F-16b proses malam', function (): void {
    it('kedaluwarsa FIFO (idempoten) lalu evaluasi tier dari belanja. Tier dikunci & tier diarsipkan tidak dipakai', function (): void {
        $k = SiapkanLoyalti($this);
        $budi = Pelanggan::query()->create(['Nama' => 'Budi', 'NoHp' => '6281311112222']);
        $reseller = Pelanggan::query()->create(['Nama' => 'Toko Reseller', 'NoHp' => '6281399990000']);
        $silver = TierPelanggan::query()->create(['Kode' => 'SILVER', 'Nama' => 'Silver', 'MinimalBelanja' => '50000']);
        $gold = TierPelanggan::query()->create(['Kode' => 'GOLD', 'Nama' => 'Gold', 'MinimalBelanja' => '100000']);
        TierPelanggan::query()->create(['Kode' => 'LAMA', 'Nama' => 'Lama', 'MinimalBelanja' => '1000', 'Status' => 'Diarsipkan']);
        $reseller->forceFill(['IdTier' => $gold->Id, 'TierTetap' => true])->save();

        $jual1 = JualKe($this, $k, $k['Ani']);
        JualKe($this, $k, $k['Ani']);
        JualKe($this, $k, $budi, '1');
        // Lot pertama Ani kedaluwarsa kemarin.
        MutasiPoin::query()->where('IdSumber', $jual1->Id)->update(['KedaluwarsaPada' => CarbonImmutable::now()->subDays(2)->toDateString()]);

        Artisan::call('pelanggan:proses-loyalti', ['--tenant' => [$k['Tenant']->Id]]);
        Artisan::call('pelanggan:proses-loyalti', ['--tenant' => [$k['Tenant']->Id]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(SaldoPoin($k['Ani']))->toBe(7)
            ->and(MutasiPoin::query()->where('Jenis', JenisMutasiPoin::Kedaluwarsa->value)->sole()->Poin)->toBe(-7)
            ->and(MutasiPoin::query()->where('IdSumber', $jual1->Id)->where('Jenis', 'Perolehan')->value('Sisa'))->toBe(0);
        // Ani 154.000 → Gold; Budi 38.500 → tanpa tier (LAMA diarsipkan); reseller tetap Gold (dikunci).
        expect($k['Ani']->refresh()->IdTier)->toBe($gold->Id)
            ->and($budi->refresh()->IdTier)->toBeNull()
            ->and($reseller->refresh()->IdTier)->toBe($gold->Id);
        expect(LogAudit::query()->where('Peristiwa', 'pelanggan.tier-otomatis')->count())->toBe(1);
        unset($silver);
    });
});

describe('F-16b back-office', function (): void {
    it('tier: tambah, kode unik & terkunci, pengali tervalidasi, arsip. Atur tier pelanggan. Penyesuaian poin tidak boleh minus', function (): void {
        $k = SiapkanLoyalti($this);
        BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id);

        $this->get('/kelola/pelanggan/tier/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pelanggan/BuatTier')
            ->where('FiturAktif', true));
        // Tambah dari halaman penuh /tier/buat: kembali ke daftar tier.
        $this->post('/kelola/pelanggan/tier', ['Kode' => 'gold', 'Nama' => 'Gold', 'MinimalBelanja' => '5000000', 'PengaliPoin' => '1.5', 'Urutan' => 2])
            ->assertSessionHasNoErrors()->assertRedirect('/kelola/pelanggan/tier');
        $this->post('/kelola/pelanggan/tier', ['Kode' => 'GOLD', 'Nama' => 'Emas', 'MinimalBelanja' => '1', 'PengaliPoin' => '1'])->assertSessionHasErrors('Kode');
        $this->post('/kelola/pelanggan/tier', ['Kode' => 'X', 'Nama' => 'X', 'MinimalBelanja' => '1', 'PengaliPoin' => '11'])->assertSessionHasErrors('PengaliPoin');
        $this->post('/kelola/pelanggan/tier', ['Kode' => 'X', 'Nama' => 'X', 'MinimalBelanja' => '', 'PengaliPoin' => '1'])
            ->assertSessionHasErrors(['MinimalBelanja' => 'Isi minimal belanja. Isi 0 agar tier berlaku untuk semua pelanggan.']);
        $gold = TierPelanggan::query()->where('Kode', 'GOLD')->sole();
        expect($gold->PengaliPoin)->toBe('1.50');

        $this->put("/kelola/pelanggan/tier/{$gold->Uuid}", ['Kode' => 'LAIN', 'Nama' => 'Gold Plus', 'MinimalBelanja' => '6000000', 'PengaliPoin' => '2'])->assertSessionHasNoErrors();
        expect($gold->refresh()->Kode)->toBe('GOLD')->and($gold->Nama)->toBe('Gold Plus');

        $this->get('/kelola/pelanggan/tier')->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pelanggan/Tier')
            ->where('Tier.0.Kode', 'GOLD')
            ->where('FiturAktif', true));

        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/tier", ['UuidTier' => $gold->Uuid, 'TierTetap' => true])->assertSessionHasNoErrors();
        expect($k['Ani']->refresh()->IdTier)->toBe($gold->Id)->and($k['Ani']->TierTetap)->toBeTrue();

        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/poin", ['Poin' => -5, 'Alasan' => 'Salah input'])->assertSessionHasErrors('Poin');
        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/poin", ['Poin' => 50, 'Alasan' => 'ok'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/poin", ['Poin' => 50, 'Alasan' => 'Kompensasi keluhan'])->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/poin", ['Poin' => -20, 'Alasan' => 'Koreksi kompensasi'])->assertSessionHasNoErrors();
        expect(SaldoPoin($k['Ani']))->toBe(30);
        $this->get("/kelola/pelanggan/{$k['Ani']->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Pelanggan.SaldoPoin', 30)
            ->where('Pelanggan.Tier', ['Kode' => 'GOLD', 'Nama' => 'Gold Plus'])
            ->has('RiwayatPoin', 2)
            ->where('LoyaltiBerlaku', true));

        $this->post("/kelola/pelanggan/tier/{$gold->Uuid}/arsipkan")->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/tier", ['UuidTier' => $gold->Uuid, 'TierTetap' => false])->assertSessionHasErrors('UuidTier');

        $this->put('/kelola/pelanggan/loyalti', ['Aktif' => true, 'BelanjaPerPoin' => '50', 'MasaBerlakuBulan' => 12, 'BulanEvaluasiTier' => 12])->assertSessionHasErrors('BelanjaPerPoin');
        $this->put('/kelola/pelanggan/loyalti', ['Aktif' => true, 'BelanjaPerPoin' => '5000', 'MasaBerlakuBulan' => 6, 'BulanEvaluasiTier' => 3])->assertSessionHasNoErrors();
        expect(PengaturanLoyalti::query()->sole()->BelanjaPerPoin)->toBe('5000.00');

        expect(LogAudit::query()->whereIn('Peristiwa', ['tier-pelanggan.tambah', 'tier-pelanggan.ubah', 'tier-pelanggan.arsipkan', 'pelanggan.tier', 'pelanggan.poin-sesuaikan', 'loyalti.pengaturan'])->count())->toBe(7);
    });

    it('izin: supervisor melihat tier & pengaturan tetapi tidak bisa mengubah. Tenant lain 404', function (): void {
        $k = SiapkanLoyalti($this);
        $gold = TierPelanggan::query()->create(['Kode' => 'GOLD', 'Nama' => 'Gold']);

        BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/pelanggan/tier')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.Kelola', false));
        $this->get('/kelola/pelanggan/loyalti')->assertOk();
        $this->get('/kelola/pelanggan/tier/buat')->assertForbidden();
        $this->post('/kelola/pelanggan/tier', ['Kode' => 'X', 'Nama' => 'X', 'MinimalBelanja' => '0', 'PengaliPoin' => '1'])->assertForbidden();
        $this->put('/kelola/pelanggan/loyalti', ['Aktif' => false, 'BelanjaPerPoin' => '10000', 'MasaBerlakuBulan' => 12, 'BulanEvaluasiTier' => 12])->assertForbidden();
        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/poin", ['Poin' => 5, 'Alasan' => 'Coba coba'])->assertForbidden();

        $b = BantuanKatalog::SiapkanTenantProduk('Toko B');
        BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
        $this->put("/kelola/pelanggan/tier/{$gold->Uuid}", ['Nama' => 'Curian', 'MinimalBelanja' => '0', 'PengaliPoin' => '1'])->assertNotFound();
        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/poin", ['Poin' => 5, 'Alasan' => 'Coba coba'])->assertNotFound();
    });

    it('API POS cari pelanggan membawa kode tier & saldo poin', function (): void {
        $k = SiapkanLoyalti($this);
        $gold = TierPelanggan::query()->create(['Kode' => 'GOLD', 'Nama' => 'Gold']);
        $k['Ani']->forceFill(['IdTier' => $gold->Id])->save();
        JualKe($this, $k, $k['Ani']);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/pelanggan?kata=ani')->assertOk()->assertJsonPath('Pelanggan.0', [
            'Uuid' => $k['Ani']->Uuid,
            'Nama' => 'Ani Rahmawati',
            'NoHp' => '0812****7890',
            'KodeTier' => 'GOLD',
            'NamaTier' => 'Gold',
            'SaldoPoin' => 7,
            // F-12: data kredit ikut dikirim (tambahan kompatibel mundur).
            'LimitKredit' => null,
            'SisaPiutang' => '0.00',
            'HariLewatJatuhTempo' => 0,
            // F-16c bagian 3: data promo pelanggan (tambahan kompatibel mundur).
            'HariLahir' => null,
            'JumlahTransaksi' => 1,
            'PemakaianPromo' => [],
        ]);
    });
});
