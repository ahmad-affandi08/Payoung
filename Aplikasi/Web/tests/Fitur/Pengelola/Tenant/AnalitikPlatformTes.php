<?php

declare(strict_types=1);

use App\Domain\Organisasi\Model\Outlet;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Pengelola\Tenant\Aksi\PerbaruiAnalitikTenant;
use App\Domain\Pengelola\Tenant\Kueri\LaporanAnalitikPlatform;
use App\Domain\Pengelola\Tenant\Model\AktivitasTenantBulanan;
use App\Domain\Pengelola\Tenant\Model\AnalitikTenant;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Aksi\BuatTagihanLangganan;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanTagihan;

/*
 * P-07 PGL-24 (v5.99): analitik platform. `PerbaruiAnalitikTenant` mengumpulkan tonggak funnel, fitur yang dipakai, dan
 * aktivitas bulanan lintas tenant; `LaporanAnalitikPlatform` menyajikan funnel, adopsi fitur, dan retensi kohort sebagai
 * angka agregat untuk Analis, Keuangan, serta Mitra & Penjualan.
 */

beforeEach(function (): void {
    // 20 Oktober 2026 pukul 10.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanTagihan::SiapkanPrasyarat();
    Mail::fake();
});

function BuatTenantAnalitik(string $nama, string $didaftarkan, ?string $sektor = null): Tenant
{
    ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant($nama);
    Tenant::query()->whereKey($tenant->Id)->update(['DibuatPada' => CarbonImmutable::parse($didaftarkan, 'UTC')]);

    if ($sektor !== null) {
        Outlet::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->update(['TemplateSektor' => $sektor]);
    }

    return $tenant;
}

/**
 * @param  list<string>  $fitur
 */
function CatatAnalitikUji(Tenant $tenant, ?string $sektor, ?string $panduan = null, ?string $transaksi = null, ?string $bayar = null, array $fitur = []): void
{
    AnalitikTenant::query()->updateOrCreate(['IdTenant' => $tenant->Id], [
        'KodeSektor' => $sektor,
        'PanduanSelesaiPada' => $panduan,
        'TransaksiPertamaPada' => $transaksi,
        'BayarPertamaPada' => $bayar,
        'FiturDipakai' => $fitur,
    ]);
}

function AktivitasUji(Tenant $tenant, string $bulan, int $transaksi = 5, int $hari = 3): void
{
    AktivitasTenantBulanan::query()->create(['IdTenant' => $tenant->Id, 'Bulan' => $bulan, 'JumlahTransaksi' => $transaksi, 'HariAktif' => $hari]);
}

describe('funnel pendaftaran', function (): void {
    it('menghitung tiap tahap, persen terhadap pendaftar dan tahap sebelumnya, serta rata-rata hari sejak daftar', function (): void {
        $a = BuatTenantAnalitik('Kedai A', '2026-09-01 03:00:00', 'FNB-CAF');
        $b = BuatTenantAnalitik('Kedai B', '2026-09-01 03:00:00', 'FNB-CAF');
        $c = BuatTenantAnalitik('Toko C', '2026-09-01 03:00:00', 'RTL-GEN');
        BuatTenantAnalitik('Toko D', '2026-09-01 03:00:00', 'RTL-GEN');
        CatatAnalitikUji($a, 'FNB-CAF', '2026-09-02 03:00:00', '2026-09-03 03:00:00', '2026-09-11 03:00:00');
        CatatAnalitikUji($b, 'FNB-CAF', '2026-09-02 03:00:00', '2026-09-05 03:00:00');
        CatatAnalitikUji($c, 'RTL-GEN', null, '2026-09-03 03:00:00');

        $hasil = app(LaporanAnalitikPlatform::class)->Funnel(null, null, null);
        $tahap = collect($hasil['Tahap'])->keyBy('Kunci');

        expect($tahap['Daftar']['Jumlah'])->toBe(4)
            ->and($tahap['Panduan']['Jumlah'])->toBe(2)
            ->and($tahap['Panduan']['PersenDaftar'])->toBe('50.0')
            ->and($tahap['Panduan']['RataRataHari'])->toBe('1.0')
            ->and($tahap['Transaksi']['Jumlah'])->toBe(3)
            ->and($tahap['Transaksi']['PersenDaftar'])->toBe('75.0')
            ->and($tahap['Transaksi']['PersenSebelumnya'])->toBe('150.0')
            ->and($tahap['Transaksi']['RataRataHari'])->toBe('2.7')
            ->and($tahap['Bayar']['Jumlah'])->toBe(1)
            ->and($tahap['Bayar']['PersenSebelumnya'])->toBe('33.3')
            ->and($tahap['Bayar']['RataRataHari'])->toBe('10.0');

        $sektor = collect($hasil['PerSektor'])->keyBy('Kunci');
        expect($sektor['FNB-CAF']['Daftar'])->toBe(2)
            ->and($sektor['FNB-CAF']['Bayar'])->toBe(1)
            ->and($sektor['FNB-CAF']['PersenBayar'])->toBe('50.0')
            ->and($sektor['RTL-GEN']['Transaksi'])->toBe(1);
    });

    it('tenant Uji/Demo/Internal tidak dihitung. Filter periode daftar dan sektor bekerja', function (): void {
        $lama = BuatTenantAnalitik('Toko Lama', '2026-01-10 03:00:00', 'RTL-GEN');
        $baru = BuatTenantAnalitik('Toko Baru', '2026-10-10 03:00:00', 'FNB-CAF');
        $uji = BuatTenantAnalitik('Toko Uji', '2026-10-10 03:00:00', 'FNB-CAF');
        Tenant::query()->whereKey($uji->Id)->update(['Penanda' => PenandaTenant::Uji->value]);
        CatatAnalitikUji($lama, 'RTL-GEN', null, '2026-01-12 03:00:00');
        CatatAnalitikUji($baru, 'FNB-CAF', null, '2026-10-11 03:00:00');
        CatatAnalitikUji($uji, 'FNB-CAF', null, '2026-10-11 03:00:00');
        $kueri = app(LaporanAnalitikPlatform::class);

        expect($kueri->Funnel(null, null, null)['Tahap'][0]['Jumlah'])->toBe(2)
            ->and($kueri->Funnel(CarbonImmutable::parse('2026-10-01', 'Asia/Jakarta'), CarbonImmutable::parse('2026-10-31', 'Asia/Jakarta'), null)['Tahap'][0]['Jumlah'])->toBe(1)
            ->and($kueri->Funnel(null, null, 'RTL-GEN')['Tahap'][0]['Jumlah'])->toBe(1)
            ->and($kueri->Funnel(null, null, 'RTL-GEN')['Tahap'][2]['Jumlah'])->toBe(1);
    });

    it('tidak ada tenant: semua tahap nol tanpa pembagian dengan nol', function (): void {
        $hasil = app(LaporanAnalitikPlatform::class)->Funnel(CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2020-01-31'), null);

        expect(collect($hasil['Tahap'])->pluck('Jumlah')->all())->toBe([0, 0, 0, 0])
            ->and($hasil['Tahap'][1]['PersenDaftar'])->toBe('0.0')
            ->and($hasil['Tahap'][1]['RataRataHari'])->toBeNull()
            ->and($hasil['PerSektor'])->toBe([]);
    });
});

describe('adopsi fitur', function (): void {
    it('hanya menghitung tenant yang menjual. Persen per sektor, baris Semua sektor di depan', function (): void {
        $a = BuatTenantAnalitik('Kedai A', '2026-09-01 03:00:00');
        $b = BuatTenantAnalitik('Kedai B', '2026-09-01 03:00:00');
        $c = BuatTenantAnalitik('Toko C', '2026-09-01 03:00:00');
        $d = BuatTenantAnalitik('Toko D', '2026-09-01 03:00:00');
        CatatAnalitikUji($a, 'FNB-CAF', fitur: ['Penjualan', 'Opname', 'Promo']);
        CatatAnalitikUji($b, 'FNB-CAF', fitur: ['Penjualan', 'Promo']);
        CatatAnalitikUji($c, 'RTL-GEN', fitur: ['Penjualan', 'Karyawan']);
        // Tidak menjual 30 hari terakhir: tidak masuk penyebut.
        CatatAnalitikUji($d, 'RTL-GEN', fitur: ['Opname', 'Karyawan']);

        $hasil = app(LaporanAnalitikPlatform::class)->AdopsiFitur();
        $baris = collect($hasil['Baris'])->keyBy('Kunci');

        expect($hasil['Baris'][0]['Kunci'])->toBe('*')
            ->and($baris['*']['TenantAktif'])->toBe(3)
            ->and($baris['*']['Fitur']['Promo'])->toBe(['Jumlah' => 2, 'Persen' => '66.7'])
            ->and($baris['FNB-CAF']['TenantAktif'])->toBe(2)
            ->and($baris['FNB-CAF']['Fitur']['Opname'])->toBe(['Jumlah' => 1, 'Persen' => '50.0'])
            ->and($baris['FNB-CAF']['Fitur']['Promo']['Persen'])->toBe('100.0')
            ->and($baris['RTL-GEN']['TenantAktif'])->toBe(1)
            ->and($baris['RTL-GEN']['Fitur']['Karyawan']['Persen'])->toBe('100.0')
            ->and($baris['RTL-GEN']['Fitur']['Pelanggan']['Jumlah'])->toBe(0)
            ->and(array_column($hasil['Fitur'], 'Kunci'))->toBe(['Opname', 'PesananPembelian', 'Pelanggan', 'Promo', 'Karyawan']);
    });

    it('tanpa tenant yang menjual: daftar kosong', function (): void {
        expect(app(LaporanAnalitikPlatform::class)->AdopsiFitur()['Baris'])->toBe([]);
    });
});

describe('retensi kohort', function (): void {
    it('kohort per bulan daftar (WIB): M+0 dan M+n dari bulan ber-transaksi, bulan mendatang null', function (): void {
        // 31 Agustus 2026 pukul 20.00 UTC = 1 September 03.00 WIB → masuk kohort September.
        $a = BuatTenantAnalitik('Kedai A', '2026-08-31 20:00:00');
        $b = BuatTenantAnalitik('Kedai B', '2026-09-15 03:00:00');
        $c = BuatTenantAnalitik('Toko C', '2026-10-05 03:00:00');
        AktivitasUji($a, '2026-09-01');
        AktivitasUji($a, '2026-10-01');
        AktivitasUji($b, '2026-09-01');
        AktivitasUji($c, '2026-10-01');
        // Bulan tanpa transaksi (void semua) tidak dihitung aktif.
        AktivitasUji($b, '2026-10-01', transaksi: 0, hari: 0);

        $hasil = app(LaporanAnalitikPlatform::class)->RetensiKohort(null);
        $baris = collect($hasil['Baris'])->keyBy('Kunci');

        expect($hasil['Bulan'])->toHaveCount(12)
            ->and($hasil['Bulan'][0])->toBe('M+0')
            ->and($hasil['Baris'])->toHaveCount(12)
            ->and($baris['2026-09']['Ukuran'])->toBe(2)
            ->and($baris['2026-09']['Label'])->toBe('September 2026')
            ->and($baris['2026-09']['Retensi'][0])->toBe(['Persen' => '100.0', 'Aktif' => 2])
            ->and($baris['2026-09']['Retensi'][1])->toBe(['Persen' => '50.0', 'Aktif' => 1])
            ->and($baris['2026-09']['Retensi'][2])->toBeNull()
            ->and($baris['2026-10']['Ukuran'])->toBe(1)
            ->and($baris['2026-10']['Retensi'][0]['Persen'])->toBe('100.0')
            ->and($baris['2026-10']['Retensi'][1])->toBeNull()
            ->and($baris['2026-08']['Ukuran'])->toBe(0)
            ->and($baris['2026-08']['Retensi'][0]['Persen'])->toBe('0.0');
    });

    it('filter sektor membatasi kohort', function (): void {
        $a = BuatTenantAnalitik('Kedai A', '2026-10-05 03:00:00');
        $b = BuatTenantAnalitik('Toko B', '2026-10-05 03:00:00');
        CatatAnalitikUji($a, 'FNB-CAF');
        CatatAnalitikUji($b, 'RTL-GEN');
        AktivitasUji($a, '2026-10-01');

        $hasil = collect(app(LaporanAnalitikPlatform::class)->RetensiKohort('FNB-CAF')['Baris'])->keyBy('Kunci');

        expect($hasil['2026-10']['Ukuran'])->toBe(1)
            ->and($hasil['2026-10']['Retensi'][0]['Persen'])->toBe('100.0');
    });
});

describe('PerbaruiAnalitikTenant', function (): void {
    it('mengisi sektor, panduan, transaksi pertama, bayar pertama, fitur, dan aktivitas bulanan. Tonggak yang tercapai tidak berubah', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Analitik Solo');
        Outlet::query()->withoutGlobalScopes()->where('IdTenant', $k['Tenant']->Id)->update(['TemplateSektor' => 'FNB-CAF']);
        ProgresPanduanAwal::query()->withoutGlobalScopes()->updateOrCreate(['IdTenant' => $k['Tenant']->Id], ['SelesaiPada' => '2026-10-02 03:00:00']);
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '50');
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
        BantuanTagihan::AturTenant($k['Tenant']);
        $tagihan = app(BuatTagihanLangganan::class)->Jalankan($k['Pemilik']->Id, 'PRO', SiklusTagihan::Bulanan);
        TagihanLangganan::query()->whereKey($tagihan->Id)->update(['DibayarPada' => '2026-10-04 03:00:00', 'Status' => 'Lunas']);

        $hasil = app(PerbaruiAnalitikTenant::class)->Jalankan();
        $analitik = AnalitikTenant::query()->where('IdTenant', $k['Tenant']->Id)->sole();
        $bulanIni = AktivitasTenantBulanan::query()->where('IdTenant', $k['Tenant']->Id)->where('Bulan', '2026-10-01')->sole();

        expect($hasil['Tenant'])->toBeGreaterThanOrEqual(1)
            ->and($hasil['Bulan'])->toBe(2)
            ->and($analitik->KodeSektor)->toBe('FNB-CAF')
            ->and($analitik->PanduanSelesaiPada?->toDateTimeString())->toBe('2026-10-02 03:00:00')
            ->and($analitik->TransaksiPertamaPada)->not->toBeNull()
            ->and($analitik->BayarPertamaPada?->toDateTimeString())->toBe('2026-10-04 03:00:00')
            ->and($analitik->FiturDipakai)->toContain('Penjualan')
            ->and($bulanIni->JumlahTransaksi)->toBe(1)
            ->and($bulanIni->HariAktif)->toBe(1);
        expect(LogAuditPengelola::query()->where('Aksi', 'tenant.data.akses')->where('Alasan', 'like', 'PGL-24%')->count())->toBe(1);

        // Tonggak tidak mundur walau tagihan kemudian dikembalikan; hitungan ulang tidak menggandakan baris.
        TagihanLangganan::query()->whereKey($tagihan->Id)->update(['DibayarPada' => null, 'Status' => 'Dikembalikan']);
        app(PerbaruiAnalitikTenant::class)->Jalankan();

        expect(AnalitikTenant::query()->where('IdTenant', $k['Tenant']->Id)->count())->toBe(1)
            ->and(AnalitikTenant::query()->where('IdTenant', $k['Tenant']->Id)->sole()->BayarPertamaPada?->toDateTimeString())->toBe('2026-10-04 03:00:00')
            ->and(AktivitasTenantBulanan::query()->where('IdTenant', $k['Tenant']->Id)->where('Bulan', '2026-10-01')->count())->toBe(1);
    });

    it('tenant ber-penanda tidak diproses. Tagihan bernilai nol bukan tonggak bayar', function (): void {
        ['Tenant' => $uji] = BantuanOrganisasi::BuatTenant('Tenant Uji Analitik');
        Tenant::query()->whereKey($uji->Id)->update(['Penanda' => PenandaTenant::Uji->value]);
        ['Tenant' => $normal] = BantuanOrganisasi::BuatTenant('Tenant Normal Analitik');

        app(PerbaruiAnalitikTenant::class)->Jalankan();

        expect(AnalitikTenant::query()->where('IdTenant', $uji->Id)->exists())->toBeFalse()
            ->and(AnalitikTenant::query()->where('IdTenant', $normal->Id)->sole()->BayarPertamaPada)->toBeNull();
    });

    it('argumen sejak mengisi ulang riwayat bulanan dan perintah analitik:perbarui melaporkan jumlahnya', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Riwayat Analitik');
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '50');
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']]]);

        $hasil = app(PerbaruiAnalitikTenant::class)->Jalankan(CarbonImmutable::parse('2026-07-15', 'UTC'));

        expect($hasil['Bulan'])->toBe(4)
            ->and(Artisan::call('analitik:perbarui', ['--sejak' => '2026-09']))->toBe(0)
            ->and(Artisan::output())->toContain('tenant');
        expect(Artisan::call('analitik:perbarui', ['--sejak' => 'bukan-bulan']))->not->toBe(0);
    });
});

describe('halaman /analitik', function (): void {
    it('Analis, Keuangan, dan Mitra & Penjualan boleh melihat; Teknis dan Dukungan ditolak', function (): void {
        foreach ([PeranPengelolaBawaan::Analis, PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::MitraPenjualan] as $peran) {
            $this->flushSession();
            BantuanTenantPengelola::Masuk($this, $peran);
            $this->get(BantuanPengelola::Url('/analitik'))->assertOk();
        }

        foreach ([PeranPengelolaBawaan::Teknis, PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::KontenLegal] as $peran) {
            $this->flushSession();
            BantuanTenantPengelola::Masuk($this, $peran);
            $this->get(BantuanPengelola::Url('/analitik'))->assertForbidden();
        }
    });

    it('props memuat funnel, adopsi, retensi, dan pilihan sektor tanpa nama atau data pribadi tenant', function (): void {
        $a = BuatTenantAnalitik('Rahasia Kedai Pribadi', '2026-10-05 03:00:00', 'FNB-CAF');
        CatatAnalitikUji($a, 'FNB-CAF', null, '2026-10-06 03:00:00', null, ['Penjualan', 'Promo']);
        AktivitasUji($a, '2026-10-01');

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);
        $respons = $this->get(BantuanPengelola::Url('/analitik?sektor=FNB-CAF&dari=2026-10-01&sampai=2026-10-31'));
        $respons->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Analitik/Tampil')
            ->where('Saring.Sektor', 'FNB-CAF')
            ->where('Saring.Dari', '2026-10-01')
            ->where('PilihanSektor.0.Nilai', 'FNB-CAF')
            ->where('Funnel.Tahap.0.Jumlah', 1)
            ->where('Funnel.Tahap.2.Jumlah', 1)
            ->where('Adopsi.Baris.0.Kunci', '*')
            ->where('Adopsi.Baris.1.Fitur.Promo.Persen', '100.0')
            ->where('Retensi.Baris.11.Kunci', '2026-10')
            ->where('Retensi.Baris.11.Retensi.0.Persen', '100.0')
            ->where('HariFitur', 30));

        expect((string) $respons->getContent())->not->toContain('Rahasia Kedai Pribadi');
    });

    it('sektor tak dikenal dan tanggal rusak diabaikan, bukan masalah', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);

        $this->get(BantuanPengelola::Url('/analitik?sektor=%27%3B--&dari=bukan&sampai=2026-13-45'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Sektor', '')->where('Saring.Dari', '')->where('Saring.Sampai', ''));
    });

    it('rentang tanggal terbalik ditukar', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Analis);

        $this->get(BantuanPengelola::Url('/analitik?dari=2026-10-31&sampai=2026-10-01'))
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Saring.Dari', '2026-10-01')->where('Saring.Sampai', '2026-10-31'));
    });
});
