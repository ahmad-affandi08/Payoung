<?php

declare(strict_types=1);

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pengelola\Katalog\Aksi\TerapkanKatalogBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Kueri\HargaPaketBerlaku;
use App\Domain\Tenant\Kueri\PaketPublik;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-86: `katalog:terapkan` menyelaraskan harga paket (promo peluncuran + harga normal terjadwal), add-on, dan fitur
 * server production dengan berkas data rilis dalam satu perintah. Langsung terbit, berlaku untuk tenant lama pada
 * tagihan berikutnya, idempoten, tidak merusak. Tanggal uji dikunci supaya tidak bergantung pada hari berjalannya tes.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    // Server production sebelum rilis ini: harga terbit lama (79.000 / 199.000), tanpa versi baru.
    foreach (['STARTER' => ['79000', '758400'], 'PRO' => ['199000', '1910400']] as $kode => [$bulanan, $tahunan]) {
        HargaPaket::query()->create([
            'IdPaket' => Paket::query()->where('Kode', $kode)->value('Id'),
            'HargaBulanan' => $bulanan,
            'HargaTahunan' => $tahunan,
            'BerlakuMulai' => '2026-01-01',
            'Status' => StatusDataMaster::Terbit,
        ]);
    }
});

afterEach(fn () => Carbon::setTestNow());

/** Harga yang dipakai untuk tagihan pada $tanggal bagi langganan yang dimulai pada $langgananMulai. */
function HargaTagihan(string $kode, string $tanggal, string $langgananMulai): string
{
    $paket = Paket::query()->where('Kode', $kode)->sole();
    $harga = app(HargaPaketBerlaku::class)->Cari($paket->Id, Carbon::parse($tanggal), Carbon::parse($langgananMulai));

    return ($harga ?? throw new RuntimeException('Tidak ada harga berlaku.'))->HargaBulanan;
}

/** @return Collection<int, HargaPaket> */
function VersiTerbit(string $kode)
{
    return HargaPaket::query()
        ->where('IdPaket', Paket::query()->where('Kode', $kode)->value('Id'))
        ->where('Status', StatusDataMaster::Terbit->value)
        ->orderBy('BerlakuMulai')
        ->get();
}

describe('katalog:terapkan', function (): void {
    it('--kering hanya menampilkan rencana dan tidak mengubah apa pun', function (): void {
        $jumlahHarga = HargaPaket::query()->count();
        Addon::query()->where('Kode', 'OUTLET_TAMBAHAN')->update(['HargaBulanan' => '99000', 'Status' => StatusPaket::Diarsipkan->value]);

        $this->artisan('katalog:terapkan', ['--kering' => true])
            ->expectsOutputToContain('[kering] harga promo paket STARTER: 79000.00 → 59000.00 per bulan (566400.00 per tahun), berlaku 2026-10-12 sampai 2027-01-31')
            ->expectsOutputToContain('[kering] harga normal paket STARTER: 79000.00 → 99000.00 per bulan (950400.00 per tahun), berlaku 2027-02-01 seterusnya')
            ->expectsOutputToContain('[kering] add-on OUTLET_TAMBAHAN (ubah): harga 99000.00 → 59000.00, diaktifkan')
            ->assertSuccessful();

        expect(HargaPaket::query()->count())->toBe($jumlahHarga)
            ->and(Addon::query()->where('Kode', 'OUTLET_TAMBAHAN')->sole()->HargaBulanan)->toBe('99000.00');
    });

    it('menerbitkan harga promo hari ini + harga normal terjadwal. Tenant lama ikut promo dan terkunci setelahnya', function (): void {
        $this->artisan('katalog:terapkan')->assertSuccessful();

        $versi = VersiTerbit('STARTER');
        expect($versi)->toHaveCount(3)
            // Versi lama diakhiri sehari sebelum promo mulai.
            ->and($versi[0]->BerlakuSampai?->toDateString())->toBe('2026-10-11')
            ->and($versi[1]->HargaBulanan)->toBe('59000.00')
            ->and($versi[1]->HargaTahunan)->toBe('566400.00')
            ->and($versi[1]->BerlakuMulai->toDateString())->toBe('2026-10-12')
            ->and($versi[1]->BerlakuSampai?->toDateString())->toBe('2027-01-31')
            ->and($versi[1]->TerapkanKePelangganLama)->toBeTrue()
            ->and($versi[2]->HargaBulanan)->toBe('99000.00')
            ->and($versi[2]->BerlakuMulai->toDateString())->toBe('2027-02-01')
            ->and($versi[2]->BerlakuSampai)->toBeNull()
            ->and($versi[2]->TerapkanKePelangganLama)->toBeFalse();

        // Tenant lama (mulai Februari) dan tenant baru (hari ini) membayar harga promo pada tagihan berikutnya.
        expect(HargaTagihan('STARTER', '2026-10-12', '2026-02-01'))->toBe('59000.00')
            ->and(HargaTagihan('STARTER', '2026-10-12', '2026-10-12'))->toBe('59000.00')
            ->and(HargaTagihan('PRO', '2026-10-12', '2026-02-01'))->toBe('149000.00')
            // Setelah promo berakhir: yang mulai sebelumnya (semua pendaftar promo + tenant lama) tetap di harga promo,
            // pendaftar baru membayar harga normal.
            ->and(HargaTagihan('STARTER', '2027-03-01', '2026-02-01'))->toBe('59000.00')
            ->and(HargaTagihan('STARTER', '2027-03-01', '2026-12-31'))->toBe('59000.00')
            ->and(HargaTagihan('STARTER', '2027-03-01', '2027-02-15'))->toBe('99000.00')
            ->and(HargaTagihan('BISNIS', '2027-03-01', '2027-02-15'))->toBe('449000.00');

        // Gratis (Rp0, tanpa promo), Starter, Pro, dan Bisnis (masing-masing promo + normal); Enterprise negosiasi.
        $log = LogAuditPengelola::query()->where('Aksi', 'katalog.harga.terapkan-rilis')->get();
        expect($log)->toHaveCount(7)->and($log->first()->IdPenggunaPengelola)->toBeNull();
    });

    it('situs pemasaran menampilkan promo hanya karena harga normal benar-benar terjadwal. Setelah promo harga normal tampil', function (): void {
        $this->artisan('katalog:terapkan')->assertSuccessful();

        $paket = collect(app(PaketPublik::class)->Ambil())->keyBy('Kode');
        expect($paket['STARTER']['HargaBulanan'])->toBe('59000.00')
            ->and($paket['STARTER']['Promo'])->toBe([
                'HargaBulananNormal' => '99000.00',
                'HargaTahunanNormal' => '950400.00',
                'BerlakuSampai' => '2027-01-31',
                'PersenDiskon' => 40,
                'HargaTerkunci' => true,
            ])
            ->and($paket['PRO']['Promo']['PersenDiskon'])->toBe(40)
            ->and($paket['BISNIS']['Promo']['PersenDiskon'])->toBe(33)
            ->and($paket['GRATIS']['Promo'])->toBeNull();

        Carbon::setTestNow(Carbon::parse('2027-02-02 09:00:00', 'Asia/Jakarta'));
        $sesudah = collect(app(PaketPublik::class)->Ambil())->keyBy('Kode');
        expect($sesudah['STARTER']['HargaBulanan'])->toBe('99000.00')->and($sesudah['STARTER']['Promo'])->toBeNull();
    });

    it('--tanpa-pelanggan-lama menjaga langganan lama di harga lamanya selama promo', function (): void {
        $this->artisan('katalog:terapkan', ['--tanpa-pelanggan-lama' => true])->assertSuccessful();

        expect(HargaTagihan('STARTER', '2026-10-12', '2026-02-01'))->toBe('79000.00')
            ->and(HargaTagihan('STARTER', '2026-10-12', '2026-10-12'))->toBe('59000.00');
    });

    it('menyelaraskan add-on: harga, fitur, aktif. Menambah yang belum ada. Tidak menyentuh yang sudah sesuai', function (): void {
        Addon::query()->where('Kode', 'OUTLET_TAMBAHAN')->update(['HargaBulanan' => '99000', 'Status' => StatusPaket::Diarsipkan->value]);
        Addon::query()->where('Kode', 'INSIGHT')->update(['HargaBulanan' => '59000']);
        Addon::query()->where('Kode', 'SELF_ORDER')->delete();

        $this->artisan('katalog:terapkan')->assertSuccessful();

        $outlet = Addon::query()->where('Kode', 'OUTLET_TAMBAHAN')->sole();
        $baru = Addon::query()->where('Kode', 'SELF_ORDER')->sole();

        expect($outlet->HargaBulanan)->toBe('59000.00')
            ->and($outlet->Status)->toBe(StatusPaket::Aktif)
            ->and(Addon::query()->where('Kode', 'INSIGHT')->sole()->HargaBulanan)->toBe('39000.00')
            ->and($baru->Status)->toBe(StatusPaket::Aktif)
            ->and($baru->HargaBulanan)->toBe('39000.00')
            ->and($baru->KunciFitur)->toBe('kanal.self-order');
    });

    it('--tanpa-aktifkan-addon membiarkan add-on yang diarsipkan Keuangan tetap diarsipkan (harga tetap diselaraskan)', function (): void {
        Addon::query()->where('Kode', 'TOKO_ONLINE')->update(['HargaBulanan' => '99000', 'Status' => StatusPaket::Diarsipkan->value]);

        $this->artisan('katalog:terapkan', ['--tanpa-aktifkan-addon' => true])->assertSuccessful();

        $toko = Addon::query()->where('Kode', 'TOKO_ONLINE')->sole();
        expect($toko->Status)->toBe(StatusPaket::Diarsipkan)->and($toko->HargaBulanan)->toBe('59000.00');
    });

    it('idempoten: dijalankan ulang tidak membuat versi harga atau perubahan lagi', function (): void {
        $this->artisan('katalog:terapkan')->assertSuccessful();
        $jumlah = HargaPaket::query()->count();

        $this->artisan('katalog:terapkan')
            ->expectsOutputToContain('Mengubah 0 versi harga paket, 0 add-on, 0 fitur katalog, dan 0 fitur paket.')
            ->assertSuccessful();

        expect(HargaPaket::query()->count())->toBe($jumlah);
    });

    it('paket dengan harga negosiasi (Enterprise) dan paket yang tidak ada di server dilewati', function (): void {
        $berkas = tempnam(sys_get_temp_dir(), 'paket');
        file_put_contents($berkas, json_encode(['Paket' => [
            ['Kode' => 'TIDAK_ADA', 'Nama' => 'Tidak ada', 'HargaNegosiasi' => false, 'Harga' => ['HargaBulanan' => '1000', 'HargaTahunan' => '9600'], 'Fitur' => []],
            ['Kode' => 'ENTERPRISE', 'Nama' => 'Enterprise', 'HargaNegosiasi' => true, 'Harga' => ['HargaBulanan' => '5000000', 'HargaTahunan' => '1'], 'Fitur' => []],
        ]]));

        $hasil = app(TerapkanKatalogBawaan::class)->Jalankan(pathPaket: $berkas);
        unlink($berkas);

        expect($hasil['Harga'])->toBe([])
            ->and(HargaPaket::query()->where('IdPaket', Paket::query()->where('Kode', 'ENTERPRISE')->value('Id'))->where('Status', StatusDataMaster::Terbit->value)->exists())->toBeFalse();
    });

    it('versi terakhir yang berlaku hari ini atau nanti membuat promo mulai sehari sesudahnya', function (): void {
        $starter = Paket::query()->where('Kode', 'STARTER')->sole();
        HargaPaket::query()->where('IdPaket', $starter->Id)->where('Status', StatusDataMaster::Terbit->value)->update(['BerlakuMulai' => '2026-10-12']);

        $this->artisan('katalog:terapkan')->assertSuccessful();

        $versi = VersiTerbit('STARTER');
        expect($versi)->toHaveCount(3)
            ->and($versi[1]->HargaBulanan)->toBe('59000.00')
            ->and($versi[1]->BerlakuMulai->toDateString())->toBe('2026-10-13')
            ->and($versi[2]->HargaBulanan)->toBe('99000.00');
    });

    it('promo yang sudah lewat tidak dipasang: hanya harga normal yang diterbitkan', function (): void {
        Carbon::setTestNow(Carbon::parse('2027-02-10 09:00:00', 'Asia/Jakarta'));

        $this->artisan('katalog:terapkan')
            ->expectsOutputToContain('harga normal paket STARTER: 79000.00 → 99000.00')
            ->doesntExpectOutputToContain('harga promo')
            ->assertSuccessful();

        $versi = VersiTerbit('STARTER');
        expect($versi)->toHaveCount(2)->and($versi[1]->HargaBulanan)->toBe('99000.00')->and($versi[1]->TerapkanKePelangganLama)->toBeTrue();
    });

    it('menyusul versi normal bila hanya promo yang sudah ada (jalankan ulang setelah sebagian)', function (): void {
        $this->artisan('katalog:terapkan')->assertSuccessful();
        $starter = Paket::query()->where('Kode', 'STARTER')->sole();
        HargaPaket::query()->where('IdPaket', $starter->Id)->where('BerlakuMulai', '2027-02-01')->forceDelete();

        $this->artisan('katalog:terapkan')->expectsOutputToContain('harga normal paket STARTER')->assertSuccessful();

        expect(VersiTerbit('STARTER'))->toHaveCount(3);
    });
});
