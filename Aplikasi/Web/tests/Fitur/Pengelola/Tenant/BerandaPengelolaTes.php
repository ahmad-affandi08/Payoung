<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\PenandaTenant;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Langganan;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Pengelola\BantuanTenantPengelola;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Beranda konsol: ringkasan per bidang yang hanya diisi bila pelaku berizin melihatnya, tenant berpenanda
 * Uji/Demo/Internal tidak dihitung sebagai pelanggan, dan butir "Perlu perhatian" hanya dari bagian yang boleh dilihat.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('Super Admin melihat seluruh ringkasan; tenant uji tidak dihitung; langganan dipilah menurut status', function (): void {
    $kopi = BantuanTenantPengelola::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
    $toko = BantuanTenantPengelola::BuatTenant('Toko Bangunan Sinar Jaya', 'budi@sinarjaya.co.id');
    $demo = BantuanTenantPengelola::BuatTenant('Salon Demo', 'demo@salon.id');
    Langganan::query()->where('IdTenant', $toko->Id)->sole()->update(['Status' => StatusLangganan::Aktif]);
    $demo->update(['Penanda' => PenandaTenant::Demo]);
    BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

    $this->get(BantuanPengelola::Url('/'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Pengelola/Beranda')
        ->where('Ringkasan.Tenant.Total', 2)
        ->where('Ringkasan.Tenant.Baru7Hari', 2)
        ->where('Ringkasan.Tenant.Trial', 1)
        ->where('Ringkasan.Tenant.Berbayar', 1)
        ->where('Ringkasan.Tenant.Tertunggak', 0)
        ->where('Ringkasan.Tagihan.JatuhTempo', 0)
        ->where('Ringkasan.Dukungan.Terbuka', 0)
        ->has('Ringkasan.Operasional.PenjadwalSehat')
        ->has('Ringkasan.Perhatian'));
    expect($kopi->Id)->not->toBe($toko->Id);
});

it('bagian di luar izin peran tidak dikirim sama sekali', function (): void {
    BantuanTenantPengelola::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
    BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::KontenLegal);

    $this->get(BantuanPengelola::Url('/'))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Ringkasan.Tenant', null)
        ->where('Ringkasan.Tagihan', null)
        ->where('Ringkasan.Dukungan', null)
        ->where('Ringkasan.Operasional', null)
        ->where('Ringkasan.Perhatian', []));
});

it('butir perhatian muncul dari data nyata dan menautkan ke halaman yang tepat', function (): void {
    $tenant = BantuanTenantPengelola::BuatTenant('Kopi Nusantara', 'rina@kopinusantara.id');
    BantuanTenantPengelola::Masuk($this, PeranPengelolaBawaan::SuperAdmin);

    $respons = $this->get(BantuanPengelola::Url('/'))->assertOk();
    $perhatian = $respons->inertiaProps('Ringkasan.Perhatian');

    // Tanpa denyut penjadwal, kondisi sistem dinilai bermasalah dan jadi butir pertama.
    expect($perhatian[0]['Judul'])->toBe('Kondisi sistem bermasalah')->and($perhatian[0]['Tautan'])->toBe('/operasional')->and($perhatian[0]['Tingkat'])->toBe('bahaya');
    expect($tenant->Id)->toBeInt();
});
