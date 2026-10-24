<?php

declare(strict_types=1);

use App\Domain\Pengelola\Tenant\Aksi\HitungSkorKesehatanTenant;
use App\Domain\Pengelola\Tenant\Enum\JenisTugasTenant;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Model\SkorKesehatanTenant;
use App\Domain\Pengelola\Tenant\Model\TugasTenant;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * P-07 PGL-09 (v5.96): skor kesehatan tenant dihitung harian lintas tenant, memicu tugas otomatis (tenant baru belum
 * bertransaksi 48 jam; tenant lama berisiko), dan tampil di daftar tenant, tampilan 360°, serta halaman tugas.
 */

beforeEach(function (): void {
    // 20 Oktober 2026 pukul 10.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

function HitungSkorUji(): array
{
    return app(HitungSkorKesehatanTenant::class)->Jalankan();
}

function UmurkanTenant(Tenant $tenant, string $waktu): void
{
    Tenant::query()->whereKey($tenant->Id)->update(['DibuatPada' => CarbonImmutable::parse($waktu, 'UTC')]);
}

function MasukPengelolaKesehatan(TestCase $tes, PeranPengelolaBawaan $peran = PeranPengelolaBawaan::Dukungan): void
{
    $tes->actingAs(BantuanPengelola::BuatAnggota($peran), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

describe('menghitung skor', function (): void {
    it('tenant baru yang sudah bertransaksi dinilai Sehat tanpa tugas; hitungan ulang di hari yang sama tidak menggandakan baris', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Sehat Solo');
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '50');
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00']]]);

        $hasil = HitungSkorUji();
        $ulang = HitungSkorUji();

        $skor = SkorKesehatanTenant::query()->where('IdTenant', $k['Tenant']->Id)->sole();
        expect($hasil['Dinilai'])->toBeGreaterThanOrEqual(1)
            ->and($ulang['TugasDibuka'])->toBe(0)
            ->and($skor->Kategori)->toBe(KategoriKesehatan::Sehat)
            // Hari aktif 40 (target menyesuaikan umur), tren 15, perangkat 10, tiket 5, tagihan 10; tanpa fitur lain.
            ->and($skor->Skor)->toBe(80)
            ->and($skor->Tanggal->toDateString())->toBe('2026-10-20')
            ->and($skor->Faktor['HariAktif']['Nilai'])->toBe(1)
            ->and(TugasTenant::query()->where('IdTenant', $k['Tenant']->Id)->count())->toBe(0)
            ->and(SkorKesehatanTenant::query()->where('IdTenant', $k['Tenant']->Id)->count())->toBe(1);
        expect(LogAuditPengelola::query()->where('Aksi', 'tenant.data.akses')->where('Alasan', 'like', 'PGL-09%')->count())->toBe(2);
    });

    it('tenant ditangguhkan, berstatus Uji/Demo/Internal, atau ditutup tidak dinilai', function (): void {
        ['Tenant' => $uji] = BantuanOrganisasi::BuatTenant('Tenant Uji Internal');
        ['Tenant' => $tangguh] = BantuanOrganisasi::BuatTenant('Tenant Ditangguhkan');
        ['Tenant' => $normal] = BantuanOrganisasi::BuatTenant('Tenant Normal');
        Tenant::query()->whereKey($uji->Id)->update(['Penanda' => PenandaTenant::Uji->value]);
        Langganan::query()->where('IdTenant', $tangguh->Id)->update(['Status' => 'Ditangguhkan']);

        HitungSkorUji();

        expect(SkorKesehatanTenant::query()->where('IdTenant', $uji->Id)->exists())->toBeFalse()
            ->and(SkorKesehatanTenant::query()->where('IdTenant', $tangguh->Id)->exists())->toBeFalse()
            ->and(SkorKesehatanTenant::query()->where('IdTenant', $normal->Id)->exists())->toBeTrue();
    });

    it('skor lebih dari 90 hari dihapus; skor kemarin dipertahankan sebagai riwayat', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Tenant Riwayat');
        SkorKesehatanTenant::query()->create(['IdTenant' => $tenant->Id, 'Tanggal' => '2026-07-01', 'Skor' => 50, 'Kategori' => KategoriKesehatan::PerluPerhatian, 'Faktor' => []]);
        SkorKesehatanTenant::query()->create(['IdTenant' => $tenant->Id, 'Tanggal' => '2026-10-19', 'Skor' => 60, 'Kategori' => KategoriKesehatan::PerluPerhatian, 'Faktor' => []]);

        HitungSkorUji();

        expect(SkorKesehatanTenant::query()->where('IdTenant', $tenant->Id)->orderBy('Tanggal')->pluck('Tanggal')->map(fn ($t) => $t->toDateString())->all())->toBe(['2026-10-19', '2026-10-20']);
    });

    it('perintah terjadwal tenant:hitung-skor-kesehatan menjalankan hitungan dan melaporkan jumlahnya', function (): void {
        BantuanOrganisasi::BuatTenant('Tenant Perintah');

        expect(Artisan::call('tenant:hitung-skor-kesehatan'))->toBe(0)
            ->and(Artisan::output())->toContain('tenant dinilai');
    });
});

describe('tugas otomatis', function (): void {
    it('tenant 3 hari belum bertransaksi → tugas onboarding; ditutup otomatis begitu transaksi pertama masuk', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Macet Onboarding');
        UmurkanTenant($k['Tenant'], '2026-10-17 03:00:00');
        HitungSkorUji();

        $tugas = TugasTenant::query()->where('IdTenant', $k['Tenant']->Id)->sole();
        expect($tugas->Jenis)->toBe(JenisTugasTenant::OnboardingMacet)
            ->and($tugas->Status)->toBe(TugasTenant::TERBUKA)
            ->and($tugas->Ringkasan)->toContain('Belum bertransaksi 3 hari');

        // Hitungan ulang tidak menggandakan tugas terbuka.
        HitungSkorUji();
        expect(TugasTenant::query()->where('IdTenant', $k['Tenant']->Id)->count())->toBe(1);

        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '20');
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $hasil = HitungSkorUji();

        $tugas->refresh();
        expect($hasil['TugasDitutup'])->toBe(1)
            ->and($tugas->Status)->toBe(TugasTenant::SELESAI)
            ->and($tugas->CaraSelesai)->toBe('Otomatis')
            ->and($tugas->KunciTerbuka)->toBeNull();
    });

    it('tenant yang baru 1 hari mendaftar belum masuk daftar hubungi onboarding', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Tenant Baru Sehari');
        UmurkanTenant($tenant, '2026-10-19 03:00:00');

        HitungSkorUji();

        expect(TugasTenant::query()->where('IdTenant', $tenant->Id)->count())->toBe(0);
    });

    it('tenant lama tanpa aktivitas dan tertunggak → skor Berisiko dan tugas Berisiko; ditandai dihubungi tidak dibuka lagi selama 14 hari', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Kedai Berisiko Klaten');
        UmurkanTenant($tenant, '2026-08-01 03:00:00');
        Langganan::query()->where('IdTenant', $tenant->Id)->update(['Status' => 'Tertunggak']);

        HitungSkorUji();

        $skor = SkorKesehatanTenant::query()->where('IdTenant', $tenant->Id)->sole();
        expect($skor->Kategori)->toBe(KategoriKesehatan::Berisiko)
            ->and($skor->Skor)->toBe(15)
            ->and(TugasTenant::query()->where('IdTenant', $tenant->Id)->pluck('Jenis')->map(fn ($j) => $j->value)->sort()->values()->all())->toBe(['Berisiko', 'OnboardingMacet']);

        // Tim menghubungi: tugas Berisiko ditutup manual dan tidak dibuka lagi pada hitungan berikutnya.
        MasukPengelolaKesehatan($this);
        $berisiko = TugasTenant::query()->where('IdTenant', $tenant->Id)->where('Jenis', JenisTugasTenant::Berisiko->value)->sole();
        $this->post(BantuanPengelola::Url("/tugas-tenant/{$berisiko->Uuid}/selesai"), ['Catatan' => 'abc'])->assertSessionHasErrors('Catatan');
        $this->post(BantuanPengelola::Url("/tugas-tenant/{$berisiko->Uuid}/selesai"), ['Catatan' => 'Dihubungi lewat WhatsApp, janji bayar Jumat.'])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/tugas-tenant/{$berisiko->Uuid}/selesai"), ['Catatan' => 'Sekali lagi tidak boleh.'])->assertSessionHasErrors(['Umum' => 'Tugas ini sudah selesai.']);

        $berisiko->refresh();
        expect($berisiko->CaraSelesai)->toBe('Manual')
            ->and($berisiko->CatatanSelesai)->toBe('Dihubungi lewat WhatsApp, janji bayar Jumat.')
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.tugas.selesai')->where('IdTenant', $tenant->Id)->count())->toBe(1);

        $this->travelTo(CarbonImmutable::parse('2026-10-21 03:00:00', 'UTC'));
        HitungSkorUji();
        expect(TugasTenant::query()->where('IdTenant', $tenant->Id)->where('Jenis', JenisTugasTenant::Berisiko->value)->where('Status', TugasTenant::TERBUKA)->count())->toBe(0);

        // Lewat masa jeda 14 hari dan kondisinya masih berisiko: tugas baru dibuka.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
        HitungSkorUji();
        expect(TugasTenant::query()->where('IdTenant', $tenant->Id)->where('Jenis', JenisTugasTenant::Berisiko->value)->where('Status', TugasTenant::TERBUKA)->count())->toBe(1);
    });

    it('tugas terbuka ditutup otomatis saat tenant ditangguhkan atau diberi penanda Uji', function (): void {
        ['Tenant' => $a] = BantuanOrganisasi::BuatTenant('Tenant Tutup A');
        ['Tenant' => $b] = BantuanOrganisasi::BuatTenant('Tenant Tutup B');
        UmurkanTenant($a, '2026-10-10 03:00:00');
        UmurkanTenant($b, '2026-10-10 03:00:00');
        HitungSkorUji();
        expect(TugasTenant::query()->where('Status', TugasTenant::TERBUKA)->whereIn('IdTenant', [$a->Id, $b->Id])->count())->toBe(2);

        Langganan::query()->where('IdTenant', $a->Id)->update(['Status' => 'Ditangguhkan']);
        Tenant::query()->whereKey($b->Id)->update(['Penanda' => PenandaTenant::Demo->value]);
        $hasil = HitungSkorUji();

        expect($hasil['TugasDitutup'])->toBe(2)
            ->and(TugasTenant::query()->whereIn('IdTenant', [$a->Id, $b->Id])->where('Status', TugasTenant::TERBUKA)->count())->toBe(0);
    });
});

describe('halaman konsol', function (): void {
    it('daftar tenant menampilkan kategori terbaru dan bisa difilter; tampilan 360° memuat rincian faktor dan tugas terbuka', function (): void {
        ['Tenant' => $berisiko] = BantuanOrganisasi::BuatTenant('Kedai Berisiko Tampil');
        ['Tenant' => $baru] = BantuanOrganisasi::BuatTenant('Kedai Baru Tampil');
        UmurkanTenant($berisiko, '2026-08-01 03:00:00');
        Langganan::query()->where('IdTenant', $berisiko->Id)->update(['Status' => 'Tertunggak']);
        HitungSkorUji();
        MasukPengelolaKesehatan($this);

        $daftar = collect($this->getJson(BantuanPengelola::Url('/tenant'))->assertOk()->json('Data'))->keyBy('Nama');
        expect($daftar['Kedai Berisiko Tampil']['Kesehatan'])->toBe(['Skor' => 15, 'Kategori' => 'Berisiko', 'LabelKategori' => 'Berisiko'])
            ->and($daftar['Kedai Baru Tampil']['Kesehatan']['Kategori'])->not->toBe('Berisiko');

        $tersaring = $this->getJson(BantuanPengelola::Url('/tenant?saring[Kesehatan]=Berisiko'))->assertOk()->json('Data');
        expect(array_column($tersaring, 'Nama'))->toBe(['Kedai Berisiko Tampil']);

        $this->get(BantuanPengelola::Url("/tenant/{$berisiko->Uuid}"))->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tenant.Kesehatan.Terbaru.Skor', 15)
            ->where('Tenant.Kesehatan.Terbaru.Kategori', 'Berisiko')
            ->has('Tenant.Kesehatan.Terbaru.Faktor', 6)
            ->where('Tenant.Kesehatan.Terbaru.Faktor.0.Kunci', 'HariAktif')
            ->has('Tenant.Kesehatan.Tugas', 2));
        $this->get(BantuanPengelola::Url("/tenant/{$baru->Uuid}"))->assertInertia(fn (AssertableInertia $h) => $h->has('Tenant.Kesehatan.Terbaru'));
    });

    it('halaman tugas: sebaran kategori, daftar terbuka lebih dulu, filter jenis; izin menangani hanya untuk yang boleh menulis catatan', function (): void {
        ['Tenant' => $a] = BantuanOrganisasi::BuatTenant('Kedai Tugas A');
        UmurkanTenant($a, '2026-10-10 03:00:00');
        HitungSkorUji();
        $tugas = TugasTenant::query()->where('IdTenant', $a->Id)->sole();
        TugasTenant::query()->create(['IdTenant' => $a->Id, 'Jenis' => JenisTugasTenant::Berisiko, 'Status' => TugasTenant::SELESAI, 'KunciTerbuka' => null, 'Ringkasan' => 'Lama', 'CaraSelesai' => 'Otomatis', 'SelesaiPada' => now()]);

        MasukPengelolaKesehatan($this);
        $this->get(BantuanPengelola::Url('/tugas-tenant'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Tenant/Tugas')
            ->where('Sebaran.Tanggal', '2026-10-20')
            ->where('Sebaran.TugasTerbuka', 1)
            ->where('Tugas.Data.0.Uuid', $tugas->Uuid)
            ->where('Tugas.Data.0.Status', 'Terbuka')
            ->where('Tugas.Data.1.Status', 'Selesai')
            ->has('PilihanJenis', 2));
        $selesai = $this->getJson(BantuanPengelola::Url('/tugas-tenant?saring[Status]=Selesai'))->json('Data');
        expect($selesai)->toHaveCount(1)->and($selesai[0]['Ringkasan'])->toBe('Lama');

        // Analis tidak membuka menu tenant (§19.3): tidak boleh melihat maupun menangani.
        MasukPengelolaKesehatan($this, PeranPengelolaBawaan::Analis);
        $this->get(BantuanPengelola::Url('/tugas-tenant'))->assertForbidden();
        $this->post(BantuanPengelola::Url("/tugas-tenant/{$tugas->Uuid}/selesai"), ['Catatan' => 'Dihubungi tadi pagi.'])->assertForbidden();
    });
});
