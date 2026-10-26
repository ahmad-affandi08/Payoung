<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Penjualan\Enum\ModeResolusiPromo;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Promo\Enum\StatusPromo;
use App\Domain\Promo\Kueri\PemakaianPromo;
use App\Domain\Promo\Model\PengaturanPromo;
use App\Domain\Promo\Model\Promo;
use App\Domain\Promo\Model\PromoPemakaian;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16c bagian 1 (PRD "Rincian F-16c"): master promo back-office (validasi per aksi, arsip, mode resolusi, izin,
 * isolasi tenant), promo aktif untuk POS, dan penerimaan `Penjualan.Buat` berpromo: validasi ulang dengan mesin promo
 * server (beda = diterima + tinjauan `PromoBerbeda`), pemakaian & kuota di transaksi yang sama, idempoten.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array<string, mixed>
 */
function IsianPromo(array $timpa = []): array
{
    return [
        'Kode' => 'KOPI10',
        'Nama' => 'Diskon 10% kopi susu',
        'Prioritas' => 5,
        'Eksklusif' => false,
        'TanggalMulai' => null,
        'TanggalSelesai' => null,
        'Kuota' => null,
        'Hari' => [],
        'JamMulai' => null,
        'JamSelesai' => null,
        'Outlet' => [],
        'Kanal' => [],
        'Tier' => [],
        'MinimalSubtotal' => '0',
        'JenisKondisi' => 'Semua',
        'UuidKondisi' => [],
        'JumlahMinimal' => '0',
        'JenisAksi' => 'DiskonPersenItem',
        'Persen' => '10',
        ...$timpa,
    ];
}

/**
 * Tenant kasir + produk Rp 38.500 + promo 10% untuk produk itu (dibuat langsung di basis data).
 *
 * @return array<string, mixed>
 */
function SiapkanPromo(TestCase $tes, ?int $kuota = null): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kopi Senja Promo');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $promo = Promo::query()->create([
        'Kode' => 'KOPI10',
        'Nama' => 'Diskon 10% kopi susu',
        'Prioritas' => 5,
        'Kuota' => $kuota,
        'Definisi' => [
            'Hari' => [], 'JamMulai' => null, 'JamSelesai' => null, 'Outlet' => [], 'Kanal' => [], 'Tier' => [],
            'MinimalSubtotal' => '0.00',
            'Kondisi' => ['Jenis' => 'Produk', 'Uuid' => [$produk->Uuid], 'JumlahMinimal' => '0.0000'],
            'Aksi' => ['Jenis' => 'DiskonPersenItem', 'Persen' => '10'],
            'BatasPerTransaksi' => null,
        ],
    ]);

    return $k + ['Produk' => $produk, 'Promo' => $promo];
}

/**
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemPromo(array $k, string $diskon = '7700.00'): array
{
    return BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']],
        'Promo' => [['Promo' => $k['Promo'], 'Baris' => [0 => $diskon]]],
    ]);
}

describe('F-16c promo di penjualan POS', function (): void {
    it('promo perangkat sama dengan server: diterima tanpa tinjauan, pemakaian & kuota tercatat, idempoten', function (): void {
        $k = SiapkanPromo($this, kuota: 5);
        $item = ItemPromo($k);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

        // 2 × 38.500 = 77.000 − 10% = 69.300.
        expect((string) $jual->TotalDiskon)->toBe('7700.00')
            ->and((string) $jual->TotalAkhir)->toBe('69300.00')
            ->and($jual->PerluTinjauan)->toBeFalse();
        $pakai = PromoPemakaian::query()->sole();
        expect($pakai->IdPromo)->toBe($k['Promo']->Id)
            ->and((string) $pakai->JumlahDiskon)->toBe('7700.00')
            ->and($k['Promo']->refresh()->KuotaTerpakai)->toBe(1);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PromoPemakaian::query()->count())->toBe(1)
            ->and($k['Promo']->refresh()->KuotaTerpakai)->toBe(1);
    });

    it('v1.90 void mengembalikan jatah promo: kuota berkurang, pemakaian ditandai dibatalkan, ringkasan tidak menghitungnya', function (): void {
        $k = SiapkanPromo($this, kuota: 1);
        $item = ItemPromo($k);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        expect($k['Promo']->refresh()->KuotaTerpakai)->toBe(1);

        $void = BantuanPenjualan::ItemVoid($k, $jual);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$void]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($k['Promo']->refresh()->KuotaTerpakai)->toBe(0)
            ->and(PromoPemakaian::query()->sole()->DibatalkanPada)->not->toBeNull()
            ->and(app(PemakaianPromo::class)->AmbilRingkasan([$k['Promo']->Id]))->toBe([]);

        // Kirim ulang void tidak mengurangi kuota dua kali; kuota 1 bisa dipakai lagi tanpa tinjauan.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$void]))->toBe([['Duplikat', null]]);
        $lagi = ItemPromo($k);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lagi]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $lagi['Uuid'])->sole()->PerluTinjauan)->toBeFalse()
            ->and($k['Promo']->refresh()->KuotaTerpakai)->toBe(1);
    });

    it('promo diarsipkan atau kuota habis selagi offline: penjualan tetap diterima + tinjauan PromoBerbeda', function (): void {
        $k = SiapkanPromo($this, kuota: 1);
        $k['Promo']->forceFill(['KuotaTerpakai' => 1])->save();
        $item = ItemPromo($k);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        expect($jual->PerluTinjauan)->toBeTrue()
            ->and($jual->AlasanTinjauan)->toContain('PromoBerbeda: perangkat: KOPI10 Rp 7.700; server: tanpa promo')
            ->and($jual->AlasanTinjauan)->toContain('kuota promo KOPI10 (1) sudah habis')
            ->and((string) $jual->TotalAkhir)->toBe('69300.00')
            ->and($k['Promo']->refresh()->KuotaTerpakai)->toBe(2);

        // Server menemukan promo yang tidak dipakai perangkat (promo baru belum terunduh).
        $k['Promo']->forceFill(['Kuota' => null])->save();
        $tanpa = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpa]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $tanpa['Uuid'])->sole()->AlasanTinjauan)->toContain('perangkat: tanpa promo; server: KOPI10 Rp 3.850');
    });

    it('promo yang merujuk baris tidak ada atau hitungan tidak cocok ditolak', function (): void {
        $k = SiapkanPromo($this);
        $salah = ItemPromo($k);
        $salah['Data']['Promo'][0]['DiskonBaris'][0]['UuidBaris'] = BantuanKasir::Uuid();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$salah]))->toBe([['Ditolak', 'DataTidakValid']]);

        $beda = ItemPromo($k);
        $beda['Data']['Promo'][0]['DiskonBaris'][0]['Jumlah'] = '9000.00';
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$beda]))->toBe([['Ditolak', 'HitunganTidakCocok']]);
    });

    it('GET /api/pos/v1/promo: promo aktif belum berakhir + mode. Arsip & berakhir tidak ikut. Tenant tanpa fitur kosong', function (): void {
        $k = SiapkanPromo($this);
        Promo::query()->create(['Kode' => 'LAMA', 'Nama' => 'Promo lalu', 'SelesaiPada' => CarbonImmutable::now()->subDay(), 'Definisi' => ['Aksi' => ['Jenis' => 'DiskonTetapPesanan', 'Jumlah' => '1000']]]);
        Promo::query()->create(['Kode' => 'ARSIP', 'Nama' => 'Promo arsip', 'Status' => StatusPromo::Diarsipkan, 'Definisi' => ['Aksi' => ['Jenis' => 'DiskonTetapPesanan', 'Jumlah' => '1000']]]);
        PengaturanPromo::query()->create(['ModeResolusi' => ModeResolusiPromo::PrioritasKetat]);

        $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/promo')->assertOk();
        expect($respons->json('ModeResolusi'))->toBe('PrioritasKetat')
            ->and(array_column($respons->json('Promo'), 'Kode'))->toBe(['KOPI10'])
            ->and($respons->json('Promo.0.Definisi.Aksi'))->toBe(['Jenis' => 'DiskonPersenItem', 'Persen' => '10'])
            ->and($respons->json('Promo.0.KuotaTersisa'))->toBeNull();
    });
});

describe('F-16c back-office promo', function (): void {
    it('tambah promo bundel lewat formulir: definisi kanonik, tanggal zona tenant, audit. Validasi per aksi', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kopi Senja Formulir');
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post('/kelola/promo', IsianPromo(['Kode' => 'kopi 10']))->assertSessionHasErrors('Kode');
        $this->post('/kelola/promo', IsianPromo(['Persen' => '150']))->assertSessionHasErrors('Persen');
        $this->post('/kelola/promo', IsianPromo(['JenisAksi' => 'BundelHargaTetap', 'Harga' => '30000', 'JumlahMinimal' => '1']))->assertSessionHasErrors('JumlahMinimal');
        $this->post('/kelola/promo', IsianPromo(['JenisKondisi' => 'Produk']))->assertSessionHasErrors('UuidKondisi');
        $this->post('/kelola/promo', IsianPromo(['JamMulai' => '25:00']))->assertSessionHasErrors('JamMulai');
        $this->post('/kelola/promo', IsianPromo(['TanggalMulai' => '2026-10-10', 'TanggalSelesai' => '2026-10-01']))->assertSessionHasErrors('SelesaiPada');

        $this->post('/kelola/promo', IsianPromo([
            'Kode' => 'happy-hour',
            'Nama' => 'Happy hour 2 kopi Rp 30.000',
            'JenisAksi' => 'BundelHargaTetap',
            'Harga' => '30000',
            'JumlahMinimal' => '2',
            'BatasPerTransaksi' => 3,
            'Hari' => [5, 1, 2],
            'JamMulai' => '14:00',
            'JamSelesai' => '17:00',
            'Kanal' => ['MakanDiTempat'],
            'TanggalMulai' => '2026-10-01',
            'TanggalSelesai' => '2026-12-31',
        ]))->assertRedirect('/kelola/promo')->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $promo = Promo::query()->sole();
        expect($promo->Kode)->toBe('HAPPY-HOUR')
            // MySQL JSON menyimpan kunci terurut ulang: bandingkan isi, bukan urutan kunci.
            ->and($promo->Definisi)->toEqual([
                'Hari' => [1, 2, 5],
                'JamMulai' => '14:00',
                'JamSelesai' => '17:00',
                'Outlet' => [],
                'Kanal' => ['MakanDiTempat'],
                'Tier' => [],
                'MinimalSubtotal' => '0.00',
                'Kondisi' => ['Jenis' => 'Semua', 'Uuid' => [], 'JumlahMinimal' => '2.0000'],
                'Aksi' => ['Jenis' => 'BundelHargaTetap', 'Harga' => '30000.00'],
                'BatasPerTransaksi' => 3,
            ])
            // Asia/Jakarta: 1 Okt 00:00 WIB = 30 Sep 17:00 UTC; selesai inklusif 31 Des → 1 Jan 00:00 WIB.
            ->and($promo->MulaiPada?->toIso8601ZuluString())->toBe('2026-09-30T17:00:00Z')
            ->and($promo->SelesaiPada?->toIso8601ZuluString())->toBe('2026-12-31T17:00:00Z')
            ->and(LogAudit::query()->where('Peristiwa', 'promo.tambah')->count())->toBe(1);

        $this->get("/kelola/promo/{$promo->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Promo/Formulir')
            ->where('Promo.TanggalMulai', '2026-10-01')
            ->where('Promo.TanggalSelesai', '2026-12-31'));
        $this->post('/kelola/promo', IsianPromo(['Kode' => 'HAPPY-HOUR']))->assertSessionHasErrors('Kode');

        $this->post("/kelola/promo/{$promo->Uuid}/arsipkan")->assertSessionHasNoErrors();
        $this->put('/kelola/promo/pengaturan', ['ModeResolusi' => 'PrioritasKetat'])->assertSessionHasNoErrors();
        $this->get('/kelola/promo')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Promo/Daftar')
            ->where('ModeResolusi', 'PrioritasKetat')
            ->where('Promo.0.Status', 'Diarsipkan')
            ->where('Promo.0.LabelAksi', 'Bundel harga tetap'));
    });

    it('kasir tanpa pelanggan.kelola tidak bisa mengubah. Promo tenant lain 404', function (): void {
        $k = SiapkanPromo($this);
        $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain Promo');
        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        $promoLain = Promo::query()->create(['Kode' => 'LAIN', 'Nama' => 'Promo toko lain', 'Definisi' => ['Aksi' => ['Jenis' => 'DiskonTetapPesanan', 'Jumlah' => '1000']]]);

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->get("/kelola/promo/{$promoLain->Uuid}/ubah")->assertNotFound();

        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
        $this->post('/kelola/promo', IsianPromo(['Kode' => 'KASIR']))->assertForbidden();
    });
});
