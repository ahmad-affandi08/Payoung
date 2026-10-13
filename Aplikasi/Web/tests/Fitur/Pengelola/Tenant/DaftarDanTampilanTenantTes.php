<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Langganan;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('P-07 daftar tenant', function (): void {
    it('mencari nama, slug, atau email Owner dan menyaring status langganan & penanda', function (): void {
        $kopi = BantuanTenantPengelola::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
        $toko = BantuanTenantPengelola::BuatTenant('Toko Bangunan Sinar Jaya Abadi Sentosa', 'budi@sinarjaya.co.id');
        $salon = BantuanTenantPengelola::BuatTenant('Salon Cantik', 'sari@saloncantik.id');
        Langganan::query()->where('IdTenant', $toko->Id)->sole()->update(['Status' => StatusLangganan::Aktif]);
        $salon->update(['Penanda' => PenandaTenant::Demo]);
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $cari = fn (array $kueri) => $this->get(BantuanPengelola::Url('/tenant?'.http_build_query($kueri)))->assertOk();

        $cari([])->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Pengelola/Tenant/Daftar')
            ->where('Tenant.Meta.Total', 3)
            ->where('Tenant.Data.0.Nama', 'Salon Cantik')
            ->where('Tenant.Data.2.EmailPemilik', 'rina@kopinusantara.id')
            ->where('Tenant.Data.2.KodePaket', 'PRO')
            ->where('Tenant.Data.2.StatusLangganan', 'Trial'));

        $cari(['cari' => 'sinarjaya.co'])->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Tenant.Meta.Total', 1)
            ->where('Tenant.Data.0.Uuid', $toko->Uuid));
        $cari(['cari' => $kopi->Slug])->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Tenant.Meta.Total', 1)
            ->where('Tenant.Data.0.Uuid', $kopi->Uuid));
        $cari(['saring' => ['Status' => 'Aktif']])->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Tenant.Meta.Total', 1)
            ->where('Tenant.Data.0.Uuid', $toko->Uuid));
        $cari(['saring' => ['Penanda' => 'Demo']])->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Tenant.Meta.Total', 1)
            ->where('Tenant.Data.0.Penanda', 'Demo'));
        $cari(['saring' => ['Penanda' => 'Tanpa']])->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Tenant.Meta.Total', 2));
        $cari(['saring' => ['Penanda' => 'Tanpa,Demo']])->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Tenant.Meta.Total', 3));
        // Karakter wildcard LIKE diperlakukan sebagai teks biasa.
        $cari(['cari' => '%'])->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Tenant.Meta.Total', 0));
    });

    it('menyaring menurut paket langganan dan menyediakan pilihannya', function (): void {
        $kopi = BantuanTenantPengelola::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
        BantuanTenantPengelola::BuatTenant('Toko Bangunan Sinar Jaya', 'budi@sinarjaya.co.id');
        $kodePaket = (string) Langganan::query()->where('IdTenant', $kopi->Id)->sole()->Paket->Kode;
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $this->get(BantuanPengelola::Url('/tenant?'.http_build_query(['saring' => ['Paket' => $kodePaket]])))->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Tenant.Meta.Total', 2)
                ->where('PilihanPaket.0.Nilai', fn ($nilai) => is_string($nilai) && $nilai !== ''));
        $this->get(BantuanPengelola::Url('/tenant?'.http_build_query(['saring' => ['Paket' => 'TIDAK-ADA']])))->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('Tenant.Meta.Total', 2));
    });

    it('berhalaman 25 baris per halaman (TabelData D-16)', function (): void {
        foreach (range(1, 26) as $nomor) {
            BantuanTenantPengelola::BuatTenant("Warung Makan Sederhana {$nomor}");
        }
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::MitraPenjualan);

        $this->get(BantuanPengelola::Url('/tenant?halaman=2'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->where('Tenant.Meta.Total', 26)
                ->where('Tenant.Meta.Halaman', 2)
                ->where('Tenant.Meta.JumlahHalaman', 2)
                ->count('Tenant.Data', 1));
    });

    it('§19.3: Konten & Legal dan Analis tidak boleh membuka menu tenant', function (PeranPengelolaBawaan $peran): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        BantuanTenantPengelola::Masuk($this, $peran);

        $this->get(BantuanPengelola::Url('/tenant'))->assertForbidden();
        $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))->assertForbidden();
    })->with([PeranPengelolaBawaan::KontenLegal, PeranPengelolaBawaan::Analis]);

    it('menolak tamu yang belum masuk', function (): void {
        $this->get(BantuanPengelola::Url('/tenant'))->assertRedirect();
    });
});

describe('P-07 tampilan 360° tenant', function (): void {
    it('menampilkan profil, langganan, pemakaian, organisasi, Owner, dan persetujuan legal', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Pengelola/Tenant/Tampil')
                ->where('Tenant.Profil.Nama', 'Kopi Nusantara')
                ->where('Tenant.Langganan.Status', 'Trial')
                ->where('Tenant.Langganan.KodePaket', 'PRO')
                ->where('Tenant.Langganan.SisaPerpanjanganTrial', 2)
                ->where('Tenant.Organisasi.Outlet.0.Nama', 'Kopi Nusantara')
                ->where('Tenant.Organisasi.JumlahGudang', 1)
                ->where('Tenant.Pemakaian.0.Label', 'Outlet')
                ->where('Tenant.Pemakaian.0.Pakai', 1)
                ->where('Tenant.Anggota.0.Email', 'rina@kopinusantara.id')
                ->where('Tenant.Anggota.0.Pemilik', true)
                ->missing('Tenant.Anggota.0.KataSandi')
                ->count('Tenant.PersetujuanLegal', 3) // S&K, Kebijakan Privasi, PPD (BR-P06.2 sejak v1.26)
                ->where('Tenant.Riwayat', [])
                ->where('Aturan.MaksHariTrial', 14));
    });

    it('CLAUDE.md #11: data usaha tenant dibaca lewat KonteksPengelola dan setiap pembukaan tercatat audit', function (): void {
        $tenant = BantuanTenantPengelola::BuatTenant();
        $anggota = BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Teknis);

        $this->get(BantuanPengelola::Url("/tenant/{$tenant->Uuid}"))->assertOk();

        $log = LogAuditPengelola::query()->where('Aksi', KonteksPengelola::AKSI_AUDIT)->sole();
        expect($log->IdTenant)->toBe($tenant->Id)
            ->and($log->IdPenggunaPengelola)->toBe($anggota->Id)
            ->and($log->Alasan)->not->toBeEmpty()
            // Konteks tenant dipulihkan: tidak ada tenant aktif yang bocor ke sisa request.
            ->and(app(KonteksTenant::class)->Ambil())->toBeNull();
    });

    it('isolasi tenant: tampilan tenant A tidak memuat outlet tenant B', function (): void {
        $a = BantuanTenantPengelola::BuatTenant('Kopi Nusantara');
        $b = BantuanTenantPengelola::BuatTenant('Bakso Pak Kumis');
        app(KonteksTenant::class)->Atur($b->Id);
        Outlet::query()->create(['IdMerek' => Outlet::query()->sole()->IdMerek, 'Kode' => 'OUT-002', 'Nama' => 'Cabang Depok']);
        app(KonteksTenant::class)->Kosongkan();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $this->get(BantuanPengelola::Url("/tenant/{$a->Uuid}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->count('Tenant.Organisasi.Outlet', 1)
                ->where('Tenant.Organisasi.Outlet.0.Nama', 'Kopi Nusantara'));
        $this->get(BantuanPengelola::Url("/tenant/{$b->Uuid}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->count('Tenant.Organisasi.Outlet', 2));
    });

    it('tenant yang tidak ada menghasilkan 404', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);

        $this->get(BantuanPengelola::Url('/tenant/01JTIDAKADA0000000000000000'))->assertNotFound();
    });
});
