<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Perangkat;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * PRD §17.2.5 & §17.2.5a (v1.96): aplikasi kasir melaporkan profil hardware & hasil Wizard Uji Perangkat ke
 * `Perangkat.ProfilHardware` (dukungan teknis, daftar kompatibilitas). Tersimpan & diaudit hanya bila berubah.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('POST /api/pos/v1/perangkat/profil-hardware (v1.96)', function (): void {
    it('menyimpan profil & hasil uji. Laporan sama tidak diaudit ulang. Tampil ringkas di daftar perangkat', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant('Kopi Senja Solo');
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);
        $profil = [
            'Produsen' => 'SUNMI',
            'Model' => 'V2s',
            'Sistem' => 'Android 11',
            'Adaptor' => 'Sunmi',
            'Printer' => ['Jenis' => 'SdkVendor', 'Nama' => 'Printer bawaan Sunmi V2s', 'Lebar' => '58 mm'],
            'Uji' => ['Cetak' => 'Lolos', 'Potong' => 'Dilewati', 'Laci' => 'Dilewati', 'Pemindai' => 'Lolos'],
            'DiujiPada' => '2026-09-26T03:15:00Z',
        ];

        $this->withToken($token)->postJson('/api/pos/v1/perangkat/profil-hardware', $profil)->assertOk()->assertJsonPath('Tersimpan', true);
        $this->withToken($token)->postJson('/api/pos/v1/perangkat/profil-hardware', $profil)->assertOk();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $tersimpan = Perangkat::query()->findOrFail($perangkat->Id)->ProfilHardware;
        expect($tersimpan)->toMatchArray($profil)
            ->and($tersimpan['DilaporkanPada'] ?? null)->toBeString()
            ->and(LogAudit::query()->where('Peristiwa', 'perangkat.profil-hardware')->count())->toBe(1);

        BantuanPersediaan::MasukSebagai($this, $tenant->Id, PeranTenantBawaan::Pemilik);
        $this->get('/kelola/perangkat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Perangkat.0.PerangkatKeras', 'SUNMI V2s | Printer bawaan Sunmi V2s (58 mm) | uji lolos'));

        // Hasil uji berubah (printer gagal) = disimpan & diaudit lagi.
        $this->withToken($token)->postJson('/api/pos/v1/perangkat/profil-hardware', [...$profil, 'Uji' => ['Cetak' => 'Gagal']])->assertOk();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(LogAudit::query()->where('Peristiwa', 'perangkat.profil-hardware')->count())->toBe(2)
            ->and(Perangkat::query()->findOrFail($perangkat->Id)->ProfilHardware['Uji'] ?? null)->toBe(['Cetak' => 'Gagal']);
    });

    it('validasi: kunci tak dikenal & nilai uji di luar pilihan ditolak, tanpa token 401', function (): void {
        ['Tenant' => $tenant] = BantuanOrganisasi::BuatTenant();
        ['Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $tenant->Id);

        $this->withToken($token)->postJson('/api/pos/v1/perangkat/profil-hardware', ['Uji' => ['Cetak' => 'Mantap']])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/pos/v1/perangkat/profil-hardware', ['Printer' => ['Pin' => '1234']])
            ->assertUnprocessable();
        $this->flushHeaders()->postJson('/api/pos/v1/perangkat/profil-hardware', ['Produsen' => 'SUNMI'])->assertUnauthorized();
    });
});
