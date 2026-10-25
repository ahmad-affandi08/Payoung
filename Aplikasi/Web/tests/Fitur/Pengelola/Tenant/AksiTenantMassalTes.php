<?php

declare(strict_types=1);

use App\Domain\Pengelola\Tenant\Aksi\AksiTenantMassal;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/**
 * @param  list<Tenant>  $tenant
 * @param  array<string, mixed>  $badan
 */
function KirimMassalUji(TestCase $tes, string $metode, string $jalur, array $tenant, array $badan): TestResponse
{
    return $tes->call($metode, BantuanPengelola::Url("/tenant/massal/{$jalur}"), ['Uuid' => array_map(fn (Tenant $t): string => $t->Uuid, $tenant), ...$badan]);
}

function StatusUji(Tenant $tenant): StatusLangganan
{
    return Langganan::query()->where('IdTenant', $tenant->Id)->sole()->Status;
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('D-208 aksi massal di daftar tenant', function (): void {
    it('perpanjang uji coba: tiap tenant diperpanjang lewat aksi satuan dan punya baris audit sendiri', function (): void {
        $a = BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        $b = BantuanTenantPengelola::BuatTenant('Toko B', 'b@contoh.id');
        $akhirA = Langganan::query()->where('IdTenant', $a->Id)->sole()->TrialBerakhirPada;
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        KirimMassalUji($this, 'PUT', 'trial', [$a, $b], ['Hari' => 5, 'Alasan' => 'Owner menunggu printer struk tiba.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat');

        expect(Langganan::query()->where('IdTenant', $a->Id)->sole()->TrialBerakhirPada->equalTo($akhirA->copy()->addDays(5)))->toBeTrue()
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.trial.perpanjang')->pluck('IdTenant')->sort()->values()->all())
            ->toBe(collect([$a->Id, $b->Id])->sort()->values()->all());
    });

    it('tenant yang tidak memenuhi syarat dilewati dan alasannya dirangkum tanpa membatalkan yang lain', function (): void {
        $trial = BantuanTenantPengelola::BuatTenant('Toko Trial', 'trial@contoh.id');
        $aktif = BantuanTenantPengelola::BuatTenant('Toko Aktif', 'aktif@contoh.id');
        Langganan::query()->where('IdTenant', $aktif->Id)->update(['Status' => StatusLangganan::Aktif]);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $respons = KirimMassalUji($this, 'PUT', 'trial', [$trial, $aktif], ['Hari' => 3, 'Alasan' => 'Menunggu perangkat kasir tiba.']);

        $respons->assertSessionHasNoErrors();
        expect(session('Kilat'))->toContain('1 tenant trialnya diperpanjang')->toContain('1 dilewati')
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.trial.perpanjang')->count())->toBe(1)
            ->and(StatusUji($aktif))->toBe(StatusLangganan::Aktif);
    });

    it('tidak ada yang berubah menghasilkan galat Umum, bukan pesan berhasil', function (): void {
        $aktif = BantuanTenantPengelola::BuatTenant('Toko Aktif', 'aktif@contoh.id');
        Langganan::query()->where('IdTenant', $aktif->Id)->update(['Status' => StatusLangganan::Aktif]);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        KirimMassalUji($this, 'PUT', 'trial', [$aktif], ['Hari' => 3, 'Alasan' => 'Menunggu perangkat kasir tiba.'])
            ->assertSessionHasErrors('Umum');
    });

    it('tangguhkan lalu aktifkan kembali sekaligus untuk banyak tenant (Super Admin)', function (): void {
        $a = BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        $b = BantuanTenantPengelola::BuatTenant('Toko B', 'b@contoh.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

        KirimMassalUji($this, 'PUT', 'tangguhkan', [$a, $b], ['Kategori' => 'Penyalahgunaan', 'Catatan' => 'Pola pendaftaran massal dari satu perangkat.'])
            ->assertSessionHasNoErrors();
        expect(StatusUji($a))->toBe(StatusLangganan::Ditangguhkan)->and(StatusUji($b))->toBe(StatusLangganan::Ditangguhkan);

        KirimMassalUji($this, 'PUT', 'aktifkan', [$a, $b], ['Alasan' => 'Investigasi selesai, tidak terbukti.'])
            ->assertSessionHasNoErrors();
        expect(StatusUji($a))->not->toBe(StatusLangganan::Ditangguhkan)->and(StatusUji($b))->not->toBe(StatusLangganan::Ditangguhkan);
    });

    it('catatan internal ditulis di setiap tenant terpilih', function (): void {
        $a = BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        $b = BantuanTenantPengelola::BuatTenant('Toko B', 'b@contoh.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        KirimMassalUji($this, 'POST', 'catatan', [$a, $b], ['Isi' => 'Ikut program promo Oktober.'])->assertSessionHasNoErrors();

        expect(LogAuditPengelola::query()->where('Aksi', 'tenant.catatan.tulis')->count())->toBe(2);
    });

    it('pilihan kosong dan lebih dari batas ditolak validasi', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $this->put(BantuanPengelola::Url('/tenant/massal/trial'), ['Uuid' => [], 'Hari' => 3, 'Alasan' => 'Menunggu perangkat kasir tiba.'])
            ->assertSessionHasErrors('Uuid');
        $this->put(BantuanPengelola::Url('/tenant/massal/trial'), [
            'Uuid' => array_fill(0, AksiTenantMassal::MAKS + 1, str_repeat('A', 26)),
            'Hari' => 3,
            'Alasan' => 'Menunggu perangkat kasir tiba.',
        ])->assertSessionHasErrors('Uuid');
    });

    it('§19.3: izin per aksi ditegakkan seperti aksi satuan', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant('Toko A', 'a@contoh.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);

        KirimMassalUji($this, 'PUT', 'trial', [$tenant], ['Hari' => 3, 'Alasan' => 'Menunggu perangkat kasir tiba.'])->assertForbidden();
        KirimMassalUji($this, 'PUT', 'tangguhkan', [$tenant], ['Kategori' => 'Penipuan', 'Catatan' => 'Laporan chargeback berulang.'])->assertForbidden();
        expect(StatusUji($tenant))->toBe(StatusLangganan::Trial);
    });
});
