<?php

declare(strict_types=1);

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Aksi\BuatTagihanLangganan;
use App\Domain\Tenant\Enum\SiklusTagihan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Dukungan\BantuanDukungan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;

/*
 * P-07 (v5.97): tampilan 360° tenant punya tab Tagihan, Tiket, dan Perangkat. Tagihan dan tiket hanya dikirim ke peran
 * yang boleh melihatnya (§19.3); perangkat untuk semua peran yang membuka tenant, tanpa token atau kunci PIN.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant('owner.aktivitas@contoh.id', '081299001234', 'Kedai Aktivitas Solo');
    BantuanTagihan::AturTenant($this->tenant);
    $this->tagihan = app(BuatTagihanLangganan::class)->Jalankan($this->pemilik->Id, 'PRO', SiklusTagihan::Bulanan);
    $this->tiket = BantuanDukungan::BuatTiket($this->tenant, $this->pemilik, 'Printer struk tidak mencetak setelah pembaruan');
    app(KonteksTenant::class)->Kosongkan();
});

describe('tab di tampilan 360°', function (): void {
    it('Dukungan melihat tiket dan perangkat. Tagihan hanya untuk peran yang boleh (tab disembunyikan, bukan kosong)', function (): void {
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        BantuanPerangkat::BuatDanAktifkan($this, $this->tenant->Id, null, 'Kasir Depan');
        app(KonteksTenant::class)->Kosongkan();

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);
        $halaman = $this->get(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}"))->assertOk();
        $halaman->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Tenant/Tampil')
            ->where('Tenant.Tiket.JumlahTerbuka', 1)
            ->where('Tenant.Tiket.Data.0.Nomor', $this->tiket->Nomor)
            ->where('Tenant.Tiket.Data.0.Judul', 'Printer struk tidak mencetak setelah pembaruan')
            ->where('Tenant.Tiket.Data.0.LabelStatus', 'Baru')
            ->where('Tenant.Tiket.Data.0.LewatSla', false)
            ->where('Tenant.Perangkat.JumlahAktif', 1)
            ->where('Tenant.Perangkat.Data.0.Nama', 'Kasir Depan')
            ->where('Tenant.Perangkat.Data.0.Platform', 'Android')
            ->where('Tenant.Perangkat.Data.0.Dicabut', false));

        $this->flushSession();
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Keuangan);
        $this->get(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}"))->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tenant.Tagihan.JumlahTerbuka', 1)
            ->where('Tenant.Tagihan.Data.0.Nomor', $this->tagihan->Nomor)
            ->where('Tenant.Tagihan.Data.0.LabelStatus', 'Belum dibayar')
            ->where('Tenant.Tagihan.Data.0.LabelJenis', 'Aktivasi langganan')
            ->where('Tenant.Tagihan.TotalTerbuka', $this->tagihan->Total)
            ->where('Tenant.Tiket', null));
    });

    it('perangkat tidak membocorkan token, kunci PIN, atau profil hardware. Perangkat dicabut dan lama offline ditandai', function (): void {
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        ['Perangkat' => $aktif] = BantuanPerangkat::BuatDanAktifkan($this, $this->tenant->Id, null, 'Kasir Depan');
        ['Perangkat' => $lama] = BantuanPerangkat::BuatDanAktifkan($this, $this->tenant->Id, null, 'Kasir Gudang Lama');
        ['Perangkat' => $dicabut] = BantuanPerangkat::BuatDanAktifkan($this, $this->tenant->Id, null, 'Kasir Rusak');
        Perangkat::query()->whereKey($aktif->Id)->update(['TerakhirAktifPada' => now()->subHour(), 'JumlahOutboxTertunda' => 3, 'VersiAplikasi' => '1.0.0']);
        Perangkat::query()->whereKey($lama->Id)->update(['TerakhirAktifPada' => now()->subDays(10), 'JumlahOutboxTertunda' => 40]);
        Perangkat::query()->whereKey($dicabut->Id)->update(['DicabutPada' => now()->subDay(), 'JumlahOutboxTertunda' => 9]);
        app(KonteksTenant::class)->Kosongkan();

        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);
        $respons = $this->get(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}"));
        $respons->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tenant.Perangkat.JumlahAktif', 2)
            ->where('Tenant.Perangkat.JumlahOffline', 1)
            // Outbox perangkat yang dicabut tidak dihitung.
            ->where('Tenant.Perangkat.TotalOutboxTertunda', 43)
            ->has('Tenant.Perangkat.Data', 3));

        $perangkat = collect($respons->inertiaProps('Tenant.Perangkat.Data'))->keyBy('Nama');
        expect($perangkat['Kasir Depan']['OutboxTertunda'])->toBe(3)
            ->and($perangkat['Kasir Depan']['LamaOffline'])->toBeFalse()
            ->and($perangkat['Kasir Depan']['VersiAplikasi'])->toBe('1.0.0')
            ->and($perangkat['Kasir Gudang Lama']['LamaOffline'])->toBeTrue()
            ->and($perangkat['Kasir Rusak']['Dicabut'])->toBeTrue()
            ->and($perangkat['Kasir Rusak']['LamaOffline'])->toBeFalse();

        $mentah = (string) json_encode($perangkat->all());
        expect($mentah)->not->toContain('HashToken')->not->toContain('KunciPinOffline')->not->toContain('ProfilHardware')->not->toContain('TokenPush');
    });

    it('tiket lewat batas SLA ditandai dan dihitung. Tab membaca tenant yang dibuka saja', function (): void {
        BantuanOrganisasi::AturKonteks($this->tenant->Id);
        $lain = BantuanDukungan::BuatTenant('Toko Lain Sukoharjo', 'lain.aktivitas@contoh.id');
        BantuanDukungan::BuatTiket($lain['Tenant'], $lain['Pengguna'], 'Tiket tenant lain tidak boleh tampil');
        app(KonteksTenant::class)->Kosongkan();

        // Maju melewati batas SLA tiket pertama (jam SLA kecil: 3 hari sudah pasti lewat).
        $this->travelTo(CarbonImmutable::parse('2026-10-25 03:00:00', 'UTC'));
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::Dukungan);
        $this->get(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}"))->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tenant.Tiket.JumlahTerbuka', 1)
            ->where('Tenant.Tiket.JumlahLewatSla', 1)
            ->where('Tenant.Tiket.Data.0.LewatSla', true)
            ->has('Tenant.Tiket.Data', 1));
    });

    it('seluruh pembukaan 360° (termasuk tab tiket dan perangkat) hanya satu baris audit akses data tenant', function (): void {
        BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);
        $sebelum = LogAuditPengelola::query()->where('Aksi', KonteksPengelola::AKSI_AUDIT)->where('IdTenant', $this->tenant->Id)->count();
        $this->get(BantuanPengelola::Url("/tenant/{$this->tenant->Uuid}"))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Tenant.Tiket.Data', 1)
            ->has('Tenant.Tagihan.Data', 1));

        expect(LogAuditPengelola::query()->where('Aksi', KonteksPengelola::AKSI_AUDIT)->where('IdTenant', $this->tenant->Id)->count())->toBe($sebelum + 1);
    });
});
