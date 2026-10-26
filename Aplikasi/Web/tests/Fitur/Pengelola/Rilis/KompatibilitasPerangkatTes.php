<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\StatusKompatibilitas;
use App\Domain\Tenant\Model\KompatibilitasPerangkat;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * v1.98 Hardware Compatibility List (PRD §17.2.5a): disusun otomatis dari `Perangkat.ProfilHardware` (Wizard Uji
 * Perangkat) lintas tenant tanpa nama tenant; tim menandai Tersertifikasi/Terbatas; halaman publik tanpa login.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/**
 * @param  array<string, string>  $uji
 */
function LaporkanProfilUji(TestCase $tes, string $token, string $produsen, string $model, ?array $printer, array $uji): void
{
    $tes->flushHeaders()->withToken($token)->postJson('/api/pos/v1/perangkat/profil-hardware', [
        'Produsen' => $produsen,
        'Model' => $model,
        'Sistem' => 'Android 11',
        'Adaptor' => 'Generik',
        'Printer' => $printer,
        'Uji' => $uji,
        'DiujiPada' => '2026-09-26T03:15:00Z',
    ])->assertOk();
}

describe('v1.98 daftar kompatibilitas perangkat (HCL)', function (): void {
    it('agregat lintas tenant: Kompatibel/Terbatas otomatis, printer LAN dilewati. Tanda tim mengalahkan otomatis. Publik tanpa nama tenant', function (): void {
        ['Tenant' => $a] = BantuanOrganisasi::BuatTenant('Kopi Senja Solo');
        ['Tenant' => $b] = BantuanOrganisasi::BuatTenant('Toko Berkah Klaten');
        ['Token' => $tokenA1] = BantuanPerangkat::BuatDanAktifkan($this, $a->Id);
        ['Token' => $tokenA2] = BantuanPerangkat::BuatDanAktifkan($this, $a->Id, nama: 'Kasir Belakang');
        ['Token' => $tokenB] = BantuanPerangkat::BuatDanAktifkan($this, $b->Id);
        $sunmi = ['Jenis' => 'SdkVendor', 'Nama' => 'Printer bawaan Sunmi V2s', 'Lebar' => '58 mm'];
        $lolos = ['Cetak' => 'Lolos', 'Potong' => 'Dilewati', 'Laci' => 'Dilewati', 'Pemindai' => 'Lolos'];

        LaporkanProfilUji($this, $tokenA1, 'SUNMI', 'V2s', $sunmi, $lolos);
        LaporkanProfilUji($this, $tokenB, 'SUNMI', 'V2s', $sunmi, $lolos);
        // Printer Bluetooth murah: cetak gagal di satu-satunya perangkat → Terbatas. Model perangkat ikut gagal.
        LaporkanProfilUji($this, $tokenA2, 'Samsung', 'SM-T295', ['Jenis' => 'BluetoothKlasik', 'Nama' => 'RPP02N', 'Lebar' => '58 mm'], [
            'Cetak' => 'Gagal', 'Potong' => 'Dilewati', 'Laci' => 'Dilewati', 'Pemindai' => 'Lolos',
        ]);

        $this->artisan('pengelola:segarkan-kompatibilitas')->assertSuccessful();

        $sunmiV2s = KompatibilitasPerangkat::query()->where('Jenis', 'Perangkat')->where('Nama', 'SUNMI V2s')->sole();
        $printerSunmi = KompatibilitasPerangkat::query()->where('Jenis', 'Printer')->where('Nama', 'Printer bawaan Sunmi V2s')->sole();
        $rpp = KompatibilitasPerangkat::query()->where('Jenis', 'Printer')->where('Nama', 'RPP02N')->sole();
        expect($sunmiV2s->JumlahPerangkat)->toBe(2)
            ->and($sunmiV2s->JumlahTenant)->toBe(2)
            ->and($sunmiV2s->StatusOtomatis)->toBe(StatusKompatibilitas::Kompatibel)
            ->and($printerSunmi->Sambungan)->toBe('SdkVendor')
            ->and($printerSunmi->JumlahLolos)->toBe(2)
            ->and($rpp->StatusOtomatis)->toBe(StatusKompatibilitas::Terbatas)
            ->and(KompatibilitasPerangkat::query()->where('Nama', 'Samsung SM-T295')->sole()->StatusOtomatis)->toBe(StatusKompatibilitas::Terbatas)
            ->and(LogAuditPengelola::query()->where('Aksi', 'tenant.data.akses')->where('Alasan', 'like', 'HCL:%')->exists())->toBeTrue();

        // Tim menandai printer RPP02N Tersertifikasi setelah uji lab (catatan wajib).
        $this->actingAs(BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Teknis), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->put(BantuanPengelola::Url("/kompatibilitas-perangkat/{$rpp->Uuid}"), ['Status' => 'Tersertifikasi', 'Catatan' => ''])
            ->assertSessionHasErrors('Catatan');
        $this->put(BantuanPengelola::Url("/kompatibilitas-perangkat/{$rpp->Uuid}"), ['Status' => 'Kompatibel', 'Catatan' => 'x'])
            ->assertSessionHasErrors('Status');
        $this->put(BantuanPengelola::Url("/kompatibilitas-perangkat/{$rpp->Uuid}"), [
            'Status' => 'Tersertifikasi', 'Catatan' => 'Lolos uji lab dengan firmware 2.1. Perbarui firmware lama.',
        ])->assertSessionHasNoErrors();
        expect($rpp->refresh()->AmbilStatus())->toBe(StatusKompatibilitas::Tersertifikasi)
            ->and(LogAuditPengelola::query()->where('Aksi', 'kompatibilitas.tandai')->count())->toBe(1);

        $this->get(BantuanPengelola::Url('/kompatibilitas-perangkat'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Pengelola/Rilis/KompatibilitasPerangkat')
            ->has('Baris', 4));

        // Segarkan ulang tidak menghapus tanda tim.
        $this->post(BantuanPengelola::Url('/kompatibilitas-perangkat/segarkan'))->assertRedirect();
        expect($rpp->refresh()->StatusManual)->toBe(StatusKompatibilitas::Tersertifikasi);

        // Publik: tanpa login, tanpa nama tenant; urut Tersertifikasi → Kompatibel → Terbatas per jenis.
        auth('pengelola')->logout();
        // Setelah permintaan ke domain pengelola, alamat relatif ikut host itu: pakai domain utama eksplisit.
        $publik = $this->get('http://localhost/kompatibilitas-perangkat')->assertOk();
        $publik->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Situs/KompatibilitasPerangkat')
            ->has('Baris', 4)
            ->where('Baris.2.Nama', 'RPP02N')
            ->where('Baris.2.Status', 'Tersertifikasi')
            ->where('Baris.3.Nama', 'Printer bawaan Sunmi V2s'));
        expect($publik->getContent())->not->toContain('Kopi Senja Solo')->not->toContain('Toko Berkah Klaten');
    });

    it('perangkat yang tidak lagi melapor menjadi Belum diuji dan hilang dari publik. Izin: Dukungan tidak bisa melihat', function (): void {
        ['Tenant' => $a] = BantuanOrganisasi::BuatTenant('Kopi Senja Solo');
        ['Perangkat' => $perangkat, 'Token' => $token] = BantuanPerangkat::BuatDanAktifkan($this, $a->Id);
        LaporkanProfilUji($this, $token, 'iMin', 'D4-503', null, ['Cetak' => 'Lolos', 'Potong' => 'Lolos', 'Laci' => 'Lolos', 'Pemindai' => 'Lolos']);
        $this->artisan('pengelola:segarkan-kompatibilitas')->assertSuccessful();
        expect($this->get('/kompatibilitas-perangkat')->assertOk()->viewData('page')['props']['Baris'])->toHaveCount(1);

        BantuanOrganisasi::AturKonteks($a->Id);
        $perangkat->refresh()->forceFill(['DicabutPada' => now()])->save();
        $this->artisan('pengelola:segarkan-kompatibilitas')->assertSuccessful();
        $baris = KompatibilitasPerangkat::query()->sole();
        expect($baris->JumlahPerangkat)->toBe(0)
            ->and($baris->StatusOtomatis)->toBe(StatusKompatibilitas::BelumDiuji)
            ->and($this->get('/kompatibilitas-perangkat')->viewData('page')['props']['Baris'])->toHaveCount(0);

        $this->actingAs(BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan), 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
        $this->get(BantuanPengelola::Url('/kompatibilitas-perangkat'))->assertForbidden();
    });
});
