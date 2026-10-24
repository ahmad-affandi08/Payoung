<?php

declare(strict_types=1);

use App\Domain\Integrasi\Enum\StatusPendaftaranMerchant;
use App\Domain\Integrasi\Model\PendaftaranMerchantPembayaran;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Konsol pengelola: pendaftaran merchant pembayaran (DOKU Partner) per tenant. Pengelola melihat status & ID tetapi tidak
 * pernah foto KTP (sudah dihapus), NIK, nomor rekening utuh, atau shared key. Segarkan status memakai kredensial Partner
 * platform (izin integrasi.kelola); mengisi penampung merchantId/terminalId butuh 2FA baru dan hanya mencatat nilai.
 */

const ID_PARTNER_KONSOL = 'BRN-PARTNER-0001';

const KUNCI_PARTNER_KONSOL = 'SK-partner-konsol-rahasia-5566';

const NIK_KONSOL = '3372010101900001';

const REKENING_KONSOL = '1234567890123';

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    config()->set('integrasi.PendaftaranMerchant', [
        'Penyedia' => 'DokuPartner',
        'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => ID_PARTNER_KONSOL],
        'Kredensial' => ['KunciRahasia' => KUNCI_PARTNER_KONSOL],
    ]);
});

function UrlMerchantKonsol(object $tenant, string $bagian): string
{
    return BantuanPengelola::Url("/tenant/{$tenant->Uuid}/pendaftaran-merchant/{$bagian}");
}

function BuatPendaftaranKonsol(object $tenant, StatusPendaftaranMerchant $status = StatusPendaftaranMerchant::Ditinjau): PendaftaranMerchantPembayaran
{
    BantuanOrganisasi::AturKonteks($tenant->Id);

    return PendaftaranMerchantPembayaran::query()->create([
        'Status' => $status,
        'NamaPemilik' => 'Budi Santoso',
        'Nik' => NIK_KONSOL,
        'Email' => 'budi@kelontongberkah.id',
        'NomorHp' => '6281234567890',
        'NamaUsaha' => 'Toko Kelontong Berkah Solo',
        'AlamatUsaha' => 'Jl. Slamet Riyadi No. 120, Surakarta',
        'NamaPemilikRekening' => 'Budi Santoso',
        'NomorRekening' => REKENING_KONSOL,
        'IdBisnisDoku' => 'BSN-0001-AAAA',
        'IdBrandDoku' => 'BRN-0099-BBBB',
        'KunciBersama' => 'SHARED-KEY-RAHASIA-777',
        'StatusDoku' => 'UPDATING',
        'DikirimPada' => now()->subHour(),
        'TokenCallback' => 'x1-'.str_repeat('a', 40),
    ]);
}

function PendaftaranKonsol(object $tenant): ?PendaftaranMerchantPembayaran
{
    BantuanOrganisasi::AturKonteks($tenant->Id);

    return PendaftaranMerchantPembayaran::query()->first();
}

function JawabanKonsol(string $bisnis = 'ACTIVE', string $brand = 'ACTIVE'): array
{
    return ['business' => ['id' => 'BSN-0001-AAAA', 'status' => $bisnis, 'brands' => [['id' => 'BRN-0099-BBBB', 'status' => $brand, 'shared_key' => 'SHARED-KEY-RAHASIA-777']]]];
}

describe('detail tenant di konsol', function (): void {
    it('menampilkan status dan ID; NIK/rekening tersamar; tanpa foto, shared key, atau token callback di halaman', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant('Toko Kelontong Berkah Solo', 'owner.berkah@contoh.id');
        BuatPendaftaranKonsol($tenant);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $halaman = $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))->assertOk();
        $halaman->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tenant.PendaftaranMerchant.PartnerAktif', true)
            ->where('Tenant.PendaftaranMerchant.Pendaftaran.Status', 'Ditinjau')
            ->where('Tenant.PendaftaranMerchant.Pendaftaran.IdBisnisDoku', 'BSN-0001-AAAA')
            ->where('Tenant.PendaftaranMerchant.Pendaftaran.IdBrandDoku', 'BRN-0099-BBBB')
            ->where('Tenant.PendaftaranMerchant.Pendaftaran.NikTersamar', '••••0001')
            ->where('Tenant.PendaftaranMerchant.Pendaftaran.RekeningTersamar', '••••0123')
            ->where('Tenant.PendaftaranMerchant.Pendaftaran.BisaDisegarkan', true)
            ->where('Tenant.PendaftaranMerchant.Pendaftaran.IdPedagangQris', null)
            ->missing('Tenant.PendaftaranMerchant.Pendaftaran.Nik')->missing('Tenant.PendaftaranMerchant.Pendaftaran.KunciBersama'));
        $isi = $halaman->getContent();

        foreach ([NIK_KONSOL, REKENING_KONSOL, 'SHARED-KEY-RAHASIA-777', KUNCI_PARTNER_KONSOL, str_repeat('a', 40)] as $rahasia) {
            expect($isi)->not->toContain($rahasia);
        }
    });

    it('tenant tanpa pendaftaran: Pendaftaran null; isolasi: pendaftaran tenant lain tidak bocor', function (): void {
        $tokoA = BantuanTenantPengelola::BuatTenant('Toko A Solo');
        $tokoB = BantuanTenantPengelola::BuatTenant('Toko B Klaten');
        BuatPendaftaranKonsol($tokoA);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->get(BantuanPengelola::Url("/tenant/{$tokoB->Uuid}"))
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Tenant.PendaftaranMerchant.Pendaftaran', null));
        expect(PendaftaranKonsol($tokoB))->toBeNull();
    });

    it('Partner belum diisi: PartnerAktif false', function (): void {
        config()->set('integrasi.PendaftaranMerchant', []);
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))
            ->assertInertia(fn (AssertableInertia $h) => $h->where('Tenant.PendaftaranMerchant.PartnerAktif', false));
    });
});

describe('segarkan status dari konsol', function (): void {
    it('Get Business Data → Aktif; tercatat di audit pengelola tanpa rahasia; shared key tersimpan terenkripsi', function (): void {
        Http::fake(['api-uat.doku.com/*' => Http::response(JawabanKonsol(), 200)]);
        $tenant = BantuanTenantPengelola::BuatTenant();
        BuatPendaftaranKonsol($tenant);
        $pelaku = BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->post(UrlMerchantKonsol($tenant, 'segarkan'))->assertSessionHasNoErrors()->assertSessionHas('Kilat');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains($r->url(), '/adv-core-api/partner/v1.0/business/BRN-0099-BBBB?') && $r->hasHeader('Client-Id', ID_PARTNER_KONSOL));
        expect(PendaftaranKonsol($tenant)->Status)->toBe(StatusPendaftaranMerchant::Aktif);
        $log = LogAuditPengelola::query()->where('Aksi', 'tenant.merchant.segarkan')->sole();
        expect($log->IdTenant)->toBe($tenant->Id)->and($log->IdPenggunaPengelola)->toBe($pelaku->Id)
            ->and($log->NilaiLama)->toBe(['Status' => 'Ditinjau'])
            ->and($log->NilaiBaru)->toBe(['Status' => 'Aktif', 'StatusDoku' => 'ACTIVE'])
            ->and(json_encode($log))->not->toContain(NIK_KONSOL)->not->toContain(KUNCI_PARTNER_KONSOL)->not->toContain('SHARED-KEY');
    });

    it('masalah DOKU: pesan jelas tanpa secret, status tersimpan tidak berubah', function (): void {
        Http::fake(['api-uat.doku.com/*' => Http::response(['message' => ['Layanan down '.KUNCI_PARTNER_KONSOL]], 503)]);
        $tenant = BantuanTenantPengelola::BuatTenant();
        BuatPendaftaranKonsol($tenant);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->post(UrlMerchantKonsol($tenant, 'segarkan'))->assertSessionHasErrors('Umum');

        expect(PendaftaranKonsol($tenant)->Status)->toBe(StatusPendaftaranMerchant::Ditinjau);
        expect(session('errors')->first('Umum'))->toContain('HTTP 503')->not->toContain(KUNCI_PARTNER_KONSOL);
    });

    it('belum terdaftar di DOKU atau Partner belum diisi: ditolak tanpa memanggil DOKU', function (): void {
        Http::fake();
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->post(UrlMerchantKonsol($tenant, 'segarkan'))->assertSessionHasErrors('Umum');

        $belum = BuatPendaftaranKonsol($tenant, StatusPendaftaranMerchant::Draf);
        $belum->update(['IdBisnisDoku' => null, 'IdBrandDoku' => null]);
        $this->post(UrlMerchantKonsol($tenant, 'segarkan'))->assertSessionHasErrors('Umum');

        $belum->update(['IdBisnisDoku' => 'BSN-1']);
        config()->set('integrasi.PendaftaranMerchant', []);
        $this->post(UrlMerchantKonsol($tenant, 'segarkan'))->assertSessionHasErrors('Umum');

        Http::assertNothingSent();
    });

    it('izin integrasi.kelola: Dukungan, Keuangan, Analis ditolak 403 tanpa memanggil DOKU', function (PeranPengelolaBawaan $peran): void {
        Http::fake();
        $tenant = BantuanTenantPengelola::BuatTenant();
        BuatPendaftaranKonsol($tenant);
        BantuanTenantPengelola::Masuk($this, $peran);

        $this->post(UrlMerchantKonsol($tenant, 'segarkan'))->assertForbidden();

        Http::assertNothingSent();
    })->with([PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::Keuangan, PeranPengelolaBawaan::Analis]);
});

describe('penampung merchantId/terminalId QRIS (diisi manual)', function (): void {
    it('Teknis dengan 2FA baru mengisi dan mengosongkan; tercatat di audit; hanya penampung', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BuatPendaftaranKonsol($tenant);
        $pelaku = BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->put(UrlMerchantKonsol($tenant, 'penampung-qris'), ['IdPedagangQris' => 'MCH-0001-ABC', 'IdTerminalQris' => 'TRM.01'])
            ->assertSessionHasNoErrors()->assertSessionHas('Kilat');

        $p = PendaftaranKonsol($tenant);
        expect($p->IdPedagangQris)->toBe('MCH-0001-ABC')->and($p->IdTerminalQris)->toBe('TRM.01')->and($p->Status)->toBe(StatusPendaftaranMerchant::Ditinjau);
        $log = LogAuditPengelola::query()->where('Aksi', 'tenant.merchant.penampung-qris')->sole();
        expect($log->IdPenggunaPengelola)->toBe($pelaku->Id)->and($log->NilaiLama)->toBe(['IdPedagangQris' => null, 'IdTerminalQris' => null])
            ->and($log->NilaiBaru)->toBe(['IdPedagangQris' => 'MCH-0001-ABC', 'IdTerminalQris' => 'TRM.01']);

        $this->put(UrlMerchantKonsol($tenant, 'penampung-qris'), ['IdPedagangQris' => '', 'IdTerminalQris' => ''])->assertSessionHasNoErrors();
        expect(PendaftaranKonsol($tenant)->IdPedagangQris)->toBeNull();
    });

    it('nilai dengan karakter aneh ditolak', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BuatPendaftaranKonsol($tenant);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->put(UrlMerchantKonsol($tenant, 'penampung-qris'), ['IdPedagangQris' => 'MCH <script>', 'IdTerminalQris' => str_repeat('a', 101)])
            ->assertSessionHasErrors(['IdPedagangQris', 'IdTerminalQris']);

        expect(PendaftaranKonsol($tenant)->IdPedagangQris)->toBeNull();
    });

    it('tenant tanpa pendaftaran: ditolak dengan pesan jelas', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->put(UrlMerchantKonsol($tenant, 'penampung-qris'), ['IdPedagangQris' => 'MCH-1', 'IdTerminalQris' => 'TRM-1'])->assertSessionHasErrors('Umum');
    });

    it('izin: peran tanpa integrasi.kelola ditolak 403; tanpa 2FA baru ditolak dan nilai tidak berubah', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BuatPendaftaranKonsol($tenant);

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);
        $this->put(UrlMerchantKonsol($tenant, 'penampung-qris'), ['IdPedagangQris' => 'MCH-1'])->assertForbidden();

        $anggota = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis);
        $this->flushSession();
        $this->actingAs($anggota, 'pengelola')->withSession(BantuanPengelola::SesiPerangkatTepercaya());
        $this->put(UrlMerchantKonsol($tenant, 'penampung-qris'), ['IdPedagangQris' => 'MCH-1']);

        expect(PendaftaranKonsol($tenant)->IdPedagangQris)->toBeNull();
    });
});
