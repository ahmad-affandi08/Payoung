<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Promo\Enum\StatusPemakaianVoucher;
use App\Domain\Promo\Enum\StatusPromo;
use App\Domain\Promo\Enum\StatusVoucher;
use App\Domain\Promo\Model\Promo;
use App\Domain\Promo\Model\Voucher;
use App\Domain\Promo\Model\VoucherPemakaian;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16c bagian 2 (CRM-06 voucher, PRD "Rincian F-16c"): promo wajib voucher, kode dipesan online di POS (§18.4) lalu
 * dipakai saat `Penjualan.Buat` diterima (idempoten, voucher bermasalah = diterima + tinjauan `VoucherTidakBerlaku`),
 * dilepas saat void; back-office: kode tunggal/massal, nonaktifkan, ekspor CSV, izin, isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant kasir + produk Rp 38.500 + promo wajib voucher potong pesanan Rp 10.000 + voucher `HEMAT10K`.
 *
 * @return array<string, mixed>
 */
function SiapkanVoucher(TestCase $tes, ?int $maksimalPakai = 1, string $namaUsaha = 'Kopi Senja Voucher'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $promo = Promo::query()->create([
        'Kode' => 'VCR-HEMAT',
        'Nama' => 'Voucher hemat Rp 10.000',
        'Definisi' => ['WajibVoucher' => true, 'Aksi' => ['Jenis' => 'DiskonTetapPesanan', 'Jumlah' => '10000']],
    ]);
    $voucher = Voucher::query()->create(['IdPromo' => $promo->Id, 'Kode' => 'HEMAT10K', 'MaksimalPakai' => $maksimalPakai]);

    return $k + ['Produk' => $produk, 'Promo' => $promo, 'Voucher' => $voucher];
}

/**
 * Isian formulir promo potong 10% semua barang.
 *
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsianPromoVoucher(array $timpa): array
{
    return [
        'Nama' => 'Diskon 10% semua barang',
        'Prioritas' => 0,
        'Eksklusif' => false,
        'Hari' => [],
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
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemVoucher(array $k, ?string $uuid = null, bool $pakaiPromo = true, ?string $kode = 'HEMAT10K'): array
{
    return BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']],
        'Promo' => $pakaiPromo ? [['Promo' => $k['Promo'], 'Pesanan' => '10000.00']] : [],
    ], $kode === null ? [] : ['Voucher' => $kode], $uuid);
}

describe('F-16c bagian 2 voucher di POS', function (): void {
    it('pesan voucher: promo ikut dikirim. Sekali pakai tidak bisa dipesan transaksi lain sampai dilepas', function (): void {
        $k = SiapkanVoucher($this);
        $jualA = BantuanKasir::Uuid();
        $jualB = BantuanKasir::Uuid();

        $respons = $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => ' hemat10k ', 'UuidPenjualan' => $jualA])->assertOk();
        expect($respons->json('Voucher.Kode'))->toBe('HEMAT10K')
            ->and($respons->json('Voucher.UuidPromo'))->toBe($k['Promo']->Uuid)
            ->and($respons->json('Voucher.SisaPakai'))->toBe(0)
            ->and($respons->json('Promo.Kode'))->toBe('VCR-HEMAT')
            ->and($respons->json('Promo.Definisi.WajibVoucher'))->toBeTrue();

        // Memesan ulang untuk penjualan yang sama = memperpanjang (idempoten).
        $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => 'HEMAT10K', 'UuidPenjualan' => $jualA])->assertOk();
        $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => 'HEMAT10K', 'UuidPenjualan' => $jualB])
            ->assertStatus(409)->assertJsonPath('Galat.Kode', 'VoucherHabis');

        $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/lepas', ['Kode' => 'HEMAT10K', 'UuidPenjualan' => $jualA])->assertNoContent();
        $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => 'HEMAT10K', 'UuidPenjualan' => $jualB])->assertOk();

        // Pesanan yang kedaluwarsa tidak menahan voucher.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        VoucherPemakaian::query()->where('UuidPenjualan', $jualB)->update(['DipesanSampai' => CarbonImmutable::now()->subMinute()]);
        $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => 'HEMAT10K', 'UuidPenjualan' => $jualA])->assertOk();
    });

    it('voucher tidak dikenal, nonaktif, kedaluwarsa, atau promonya diarsipkan ditolak. Voucher tenant lain tidak dikenal', function (): void {
        $k = SiapkanVoucher($this);
        $lain = SiapkanVoucher($this, namaUsaha: 'Toko Lain Voucher');
        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        Voucher::query()->create(['IdPromo' => $lain['Promo']->Id, 'Kode' => 'MILIKLAIN']);
        $pesan = fn (string $kode) => $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => $kode, 'UuidPenjualan' => BantuanKasir::Uuid()]);

        $pesan('TIDAKADA')->assertNotFound()->assertJsonPath('Galat.Kode', 'VoucherTidakDitemukan');
        $pesan('MILIKLAIN')->assertNotFound();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $k['Voucher']->update(['Status' => StatusVoucher::Nonaktif]);
        $pesan('HEMAT10K')->assertStatus(422)->assertJsonPath('Galat.Kode', 'VoucherNonaktif');

        $k['Voucher']->update(['Status' => StatusVoucher::Aktif, 'KedaluwarsaPada' => CarbonImmutable::now()->subMinute()]);
        $pesan('HEMAT10K')->assertStatus(422)->assertJsonPath('Galat.Kode', 'VoucherKedaluwarsa');

        $k['Voucher']->update(['KedaluwarsaPada' => null]);
        $k['Promo']->update(['Status' => StatusPromo::Diarsipkan]);
        $pesan('HEMAT10K')->assertStatus(422)->assertJsonPath('Galat.Kode', 'PromoTidakBerlaku');
    });

    it('penjualan bervoucher: diterima tanpa tinjauan, voucher terpakai sekali (idempoten), habis untuk transaksi lain. Void melepas', function (): void {
        $k = SiapkanVoucher($this);
        $item = ItemVoucher($k);
        $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => 'HEMAT10K', 'UuidPenjualan' => $item['Uuid']])->assertOk();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        // 2 × 38.500 = 77.000 − voucher 10.000 = 67.000.
        expect((string) $jual->TotalAkhir)->toBe('67000.00')
            ->and($jual->PerluTinjauan)->toBeFalse()
            ->and($k['Voucher']->refresh()->JumlahDipakai)->toBe(1)
            ->and(VoucherPemakaian::query()->sole()->Status)->toBe(StatusPemakaianVoucher::Dipakai)
            ->and(VoucherPemakaian::query()->sole()->IdPenjualan)->toBe($jual->Id);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($k['Voucher']->refresh()->JumlahDipakai)->toBe(1);

        $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => 'HEMAT10K', 'UuidPenjualan' => BantuanKasir::Uuid()])
            ->assertStatus(409)->assertJson(['Galat' => ['Kode' => 'VoucherHabis', 'Pesan' => 'Voucher HEMAT10K sudah habis dipakai.']]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($k['Voucher']->refresh()->JumlahDipakai)->toBe(0)
            ->and(VoucherPemakaian::query()->sole()->Status)->toBe(StatusPemakaianVoucher::Dilepas);
        $this->withToken($k['Token'])->postJson('/api/pos/v1/voucher/pesan', ['Kode' => 'HEMAT10K', 'UuidPenjualan' => BantuanKasir::Uuid()])->assertOk();
    });

    it('voucher tanpa pesanan online atau melewati batas: penjualan tetap diterima + tinjauan. Promo voucher tanpa kode = PromoBerbeda', function (): void {
        $k = SiapkanVoucher($this);
        $k['Voucher']->forceFill(['JumlahDipakai' => 1])->save();
        $item = ItemVoucher($k);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        expect($jual->PerluTinjauan)->toBeTrue()
            ->and($jual->AlasanTinjauan)->toContain('VoucherTidakBerlaku: voucher HEMAT10K tidak dipesan online untuk penjualan ini; voucher HEMAT10K melewati batas pakai (1)')
            ->and($jual->AlasanTinjauan)->not->toContain('PromoBerbeda')
            ->and($k['Voucher']->refresh()->JumlahDipakai)->toBe(2);

        // Aplikasi menerapkan promo voucher tanpa mengirim kodenya: server tidak menerapkan promo itu.
        $tanpaKode = ItemVoucher($k, kode: null);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpaKode]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $tanpaKode['Uuid'])->sole()->AlasanTinjauan)
            ->toContain('PromoBerbeda: perangkat: VCR-HEMAT Rp 10.000; server: tanpa promo');

        // Kode tidak dikenal server.
        $asing = ItemVoucher($k, pakaiPromo: false, kode: 'ASING');
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$asing]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $asing['Uuid'])->sole()->AlasanTinjauan)->toContain('voucher ASING tidak dikenal server');
    });

    it('GET /api/pos/v1/promo: promo wajib voucher hanya untuk aplikasi yang mengenalnya (?voucher=1)', function (): void {
        $k = SiapkanVoucher($this);

        expect($this->withToken($k['Token'])->getJson('/api/pos/v1/promo')->assertOk()->json('Promo'))->toBe([])
            ->and(array_column($this->withToken($k['Token'])->getJson('/api/pos/v1/promo?voucher=1')->assertOk()->json('Promo'), 'Kode'))->toBe(['VCR-HEMAT']);
    });
});

describe('F-16c bagian 2 back-office voucher', function (): void {
    it('promo wajib voucher lewat formulir. Kode tunggal, massal berawalan, nonaktifkan, daftar, ekspor CSV, audit', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kopi Senja Kode');
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post('/kelola/promo', IsianPromoVoucher(['Kode' => 'BIASA']))->assertSessionHasNoErrors();
        $this->post('/kelola/promo', IsianPromoVoucher(['Kode' => 'VCR', 'Nama' => 'Voucher 10% semua barang', 'WajibVoucher' => true]))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $biasa = Promo::query()->where('Kode', 'BIASA')->sole();
        $promo = Promo::query()->where('Kode', 'VCR')->sole();
        expect($promo->Definisi['WajibVoucher'] ?? null)->toBeTrue()
            ->and(array_key_exists('WajibVoucher', $biasa->Definisi))->toBeFalse();

        $this->post("/kelola/promo/{$biasa->Uuid}/voucher", ['Cara' => 'Satu', 'Kode' => 'BIASA01'])->assertSessionHasErrors('Kode');
        $this->post("/kelola/promo/{$promo->Uuid}/voucher", ['Cara' => 'Satu', 'Kode' => 'ab'])->assertSessionHasErrors('Kode');
        $this->post("/kelola/promo/{$promo->Uuid}/voucher", ['Cara' => 'Satu', 'Kode' => 'merdeka-17', 'MaksimalPakai' => 100, 'TanggalKedaluwarsa' => '2026-10-31'])
            ->assertSessionHasNoErrors();
        $this->post("/kelola/promo/{$promo->Uuid}/voucher", ['Cara' => 'Satu', 'Kode' => 'MERDEKA-17'])->assertSessionHasErrors('Kode');
        $this->post("/kelola/promo/{$promo->Uuid}/voucher", ['Cara' => 'Massal', 'Jumlah' => 5001])->assertSessionHasErrors('Jumlah');
        $this->post("/kelola/promo/{$promo->Uuid}/voucher", ['Cara' => 'Massal', 'Jumlah' => 50, 'Awalan' => 'hut', 'MaksimalPakai' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '50 voucher ditambahkan.');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tunggal = Voucher::query()->where('Kode', 'MERDEKA-17')->sole();
        // Asia/Jakarta: kedaluwarsa inklusif 31 Okt → 1 Nov 00:00 WIB = 31 Okt 17:00 UTC.
        expect($tunggal->KedaluwarsaPada?->toIso8601ZuluString())->toBe('2026-10-31T17:00:00Z')
            ->and($tunggal->MaksimalPakai)->toBe(100);
        $massal = Voucher::query()->where('Kode', 'like', 'HUT%')->pluck('Kode')->all();
        expect($massal)->toHaveCount(50)
            ->and(collect($massal)->every(fn (string $kode): bool => preg_match('/^HUT[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{8}$/', $kode) === 1))->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'voucher.tambah')->count())->toBe(2);

        $this->post("/kelola/promo/voucher/{$tunggal->Uuid}/nonaktifkan")->assertSessionHasNoErrors();
        expect($tunggal->refresh()->Status)->toBe(StatusVoucher::Nonaktif);

        $this->get("/kelola/promo/{$promo->Uuid}/voucher")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Promo/Voucher')
            ->where('Promo.Kode', 'VCR')
            ->where('Ringkasan', ['Total' => 51, 'Aktif' => 50, 'Dipakai' => 0]));
        $json = $this->getJson("/kelola/promo/{$promo->Uuid}/voucher?cari=merdeka&saring[Status]=Nonaktif")->assertOk();
        expect(array_column($json->json('Data'), 'Kode'))->toBe(['MERDEKA-17'])
            ->and($json->json('Meta.Total'))->toBe(1);

        $csv = $this->get("/kelola/promo/{$promo->Uuid}/voucher/ekspor")->assertOk()->streamedContent();
        $baris = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv))));
        expect($baris[0])->toBe('Kode,BatasPakai,Dipakai,KedaluwarsaPada,Status')
            ->and($baris)->toHaveCount(52)
            ->and($csv)->toContain('MERDEKA-17,100,0,2026-10-31T17:00:00Z,Nonaktif');
    });

    it('kasir tanpa pelanggan.kelola tidak bisa menambah & mengekspor. Voucher & promo tenant lain 404', function (): void {
        $k = SiapkanVoucher($this);
        $lain = SiapkanVoucher($this, namaUsaha: 'Toko Lain Kode');

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->get("/kelola/promo/{$lain['Promo']->Uuid}/voucher")->assertNotFound();
        $this->post("/kelola/promo/voucher/{$lain['Voucher']->Uuid}/nonaktifkan")->assertNotFound();

        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
        $this->post("/kelola/promo/{$k['Promo']->Uuid}/voucher", ['Cara' => 'Massal', 'Jumlah' => 5])->assertForbidden();
        $this->get("/kelola/promo/{$k['Promo']->Uuid}/voucher/ekspor")->assertForbidden();
    });
});

describe('F-16c aksi massal promo & voucher', function (): void {
    it('promo massal: arsipkan lalu aktifkan kembali, yang sudah berstatus itu dilewati; Uuid asing menolak semuanya', function (): void {
        $k = SiapkanVoucher($this);
        $lain = Promo::query()->create(['Kode' => 'PRM-2', 'Nama' => 'Promo kedua', 'Definisi' => ['Aksi' => ['Jenis' => 'DiskonTetapPesanan', 'Jumlah' => '5000']]]);
        $lain->forceFill(['Status' => StatusPromo::Diarsipkan])->save();
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $uuid = [$k['Promo']->Uuid, $lain->Uuid];

        $this->post('/kelola/promo/massal', ['Aksi' => 'Arsipkan', 'Uuid' => $uuid])
            ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '1 promo diarsipkan. 1 dilewati karena sudah berstatus itu.');
        $this->post('/kelola/promo/massal', ['Aksi' => 'Pulihkan', 'Uuid' => $uuid])
            ->assertSessionHas('Kilat', '2 promo diaktifkan kembali.');
        expect($k['Promo']->refresh()->Status)->toBe(StatusPromo::Aktif)->and($lain->refresh()->Status)->toBe(StatusPromo::Aktif)
            ->and(LogAudit::query()->where('Peristiwa', 'promo.arsipkan')->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'promo.pulihkan')->count())->toBe(2);

        $this->post('/kelola/promo/massal', ['Aksi' => 'Arsipkan', 'Uuid' => [$k['Promo']->Uuid, '01J9ZZZZZZZZZZZZZZZZZZZZZZ']])->assertSessionHasErrors('Uuid');
        $this->post('/kelola/promo/massal', ['Aksi' => 'Hapus', 'Uuid' => $uuid])->assertSessionHasErrors('Aksi');
        expect($k['Promo']->refresh()->Status)->toBe(StatusPromo::Aktif);
    });

    it('voucher massal: nonaktifkan/aktifkan terpilih, nonaktifkan yang kedaluwarsa, voucher promo lain ditolak, kasir ditolak', function (): void {
        $k = SiapkanVoucher($this, null);
        $lamaKedaluwarsa = Voucher::query()->create(['IdPromo' => $k['Promo']->Id, 'Kode' => 'LAMA01', 'KedaluwarsaPada' => now()->subDay()]);
        $masihBerlaku = Voucher::query()->create(['IdPromo' => $k['Promo']->Id, 'Kode' => 'BARU01', 'KedaluwarsaPada' => now()->addDays(10)]);
        $promoLain = Promo::query()->create(['Kode' => 'PRM-3', 'Nama' => 'Promo ketiga', 'Definisi' => ['WajibVoucher' => true, 'Aksi' => ['Jenis' => 'DiskonTetapPesanan', 'Jumlah' => '5000']]]);
        $asing = Voucher::query()->create(['IdPromo' => $promoLain->Id, 'Kode' => 'LAIN01']);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $alamat = "/kelola/promo/{$k['Promo']->Uuid}/voucher";

        $this->post("{$alamat}/massal", ['Aksi' => 'Nonaktifkan', 'Uuid' => [$k['Voucher']->Uuid, $masihBerlaku->Uuid]])
            ->assertSessionHasNoErrors()->assertSessionHas('Kilat', '2 voucher dinonaktifkan.');
        $this->post("{$alamat}/massal", ['Aksi' => 'Aktifkan', 'Uuid' => [$k['Voucher']->Uuid, $lamaKedaluwarsa->Uuid]])
            ->assertSessionHas('Kilat', '1 voucher diaktifkan. 1 dilewati karena sudah berstatus itu.');
        expect($k['Voucher']->refresh()->Status)->toBe(StatusVoucher::Aktif)->and($masihBerlaku->refresh()->Status)->toBe(StatusVoucher::Nonaktif);

        // Hanya voucher aktif yang lewat tanggalnya sendiri; yang tanpa tanggal atau masih berlaku tidak disentuh.
        $this->post("{$alamat}/nonaktifkan-kedaluwarsa")->assertSessionHas('Kilat', '1 voucher kedaluwarsa dinonaktifkan.');
        expect($lamaKedaluwarsa->refresh()->Status)->toBe(StatusVoucher::Nonaktif)->and($k['Voucher']->refresh()->Status)->toBe(StatusVoucher::Aktif);
        $this->post("{$alamat}/nonaktifkan-kedaluwarsa")->assertSessionHas('Kilat', 'Tidak ada voucher aktif yang sudah kedaluwarsa.');

        // Voucher milik promo lain tidak bisa diubah lewat promo ini.
        $this->post("{$alamat}/massal", ['Aksi' => 'Nonaktifkan', 'Uuid' => [$k['Voucher']->Uuid, $asing->Uuid]])->assertSessionHasErrors('Uuid');
        expect($asing->refresh()->Status)->toBe(StatusVoucher::Aktif)->and($k['Voucher']->refresh()->Status)->toBe(StatusVoucher::Aktif);

        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
        $this->post("{$alamat}/massal", ['Aksi' => 'Nonaktifkan', 'Uuid' => [$k['Voucher']->Uuid]])->assertForbidden();
        $this->post('/kelola/promo/massal', ['Aksi' => 'Arsipkan', 'Uuid' => [$k['Promo']->Uuid]])->assertForbidden();
    });
});
