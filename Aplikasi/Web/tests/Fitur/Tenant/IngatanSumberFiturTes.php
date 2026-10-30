<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Kueri\SumberFiturTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit race/N+1: fitur & batas paket tenant diperiksa belasan kali per halaman, jadi `SumberFiturTenant` mengingat
 * hasilnya selama satu permintaan. Ingatan harus (1) menghemat kueri, (2) segar bila data pembentuknya berubah lewat
 * model, dan (3) tidak dipakai oleh pembacaan berkunci (penegakan batas paket di dalam transaksi).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('mengingat hasil Ambil: pemanggilan kedua tanpa kueri dan hasilnya objek yang sama', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    $sumber = app(SumberFiturTenant::class);
    $sumber->Lupakan();

    DB::enableQueryLog();
    $pertama = $sumber->Ambil($t['Tenant']->Id);
    $kueriPertama = count(DB::getQueryLog());
    $kedua = $sumber->Ambil($t['Tenant']->Id);

    expect($kueriPertama)->toBeGreaterThan(0)
        ->and(count(DB::getQueryLog()))->toBe($kueriPertama)
        ->and($kedua)->toBe($pertama)
        ->and(app(SumberFiturTenant::class))->toBe($sumber);
});

it('mengosongkan ingatan saat override fitur berubah lewat model', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    $sumber = app(SumberFiturTenant::class);
    $sumber->Lupakan();
    $sebelum = $sumber->Ambil($t['Tenant']->Id);
    expect($sebelum->overrideFitur)->not->toContain(PemeriksaFiturTenant::KUNCI_TOKO_ONLINE);

    $override = OverrideTenant::query()->create([
        'IdTenant' => $t['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_TOKO_ONLINE,
        'BerakhirPada' => now()->addDays(30),
        'Alasan' => 'Uji ingatan fitur',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    expect($sumber->Ambil($t['Tenant']->Id)->overrideFitur)->toContain(PemeriksaFiturTenant::KUNCI_TOKO_ONLINE)
        ->and(app(PemeriksaFiturTenant::class)->CekAktif($t['Tenant']->Id, PemeriksaFiturTenant::KUNCI_TOKO_ONLINE))->toBeTrue();

    $override->delete();

    expect($sumber->Ambil($t['Tenant']->Id)->overrideFitur)->not->toContain(PemeriksaFiturTenant::KUNCI_TOKO_ONLINE);
});

it('mengosongkan ingatan saat transaksi dibatalkan, dan pembacaan berkunci tidak memakai ingatan', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    $sumber = app(SumberFiturTenant::class);
    $sumber->Lupakan();
    $diingat = $sumber->Ambil($t['Tenant']->Id);

    $berkunci = DB::transaction(fn () => $sumber->Ambil($t['Tenant']->Id, kunci: true));

    expect($berkunci)->not->toBe($diingat)
        ->and($berkunci)->toEqual($diingat);

    DB::beginTransaction();
    OverrideTenant::query()->create([
        'IdTenant' => $t['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_TOKO_ONLINE,
        'BerakhirPada' => now()->addDays(30),
        'Alasan' => 'Akan dibatalkan',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    expect($sumber->Ambil($t['Tenant']->Id)->overrideFitur)->toContain(PemeriksaFiturTenant::KUNCI_TOKO_ONLINE);
    DB::rollBack();

    expect($sumber->Ambil($t['Tenant']->Id)->overrideFitur)->not->toContain(PemeriksaFiturTenant::KUNCI_TOKO_ONLINE);
});

it('ingatan dipisah per outlet dan dibatasi ukurannya agar penyapu semua tenant tidak menimbun memori', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    $sumber = app(SumberFiturTenant::class);
    $sumber->Lupakan();

    $tanpaOutlet = $sumber->Ambil($t['Tenant']->Id);
    $denganOutlet = $sumber->Ambil($t['Tenant']->Id, idOutlet: $t['Outlet']->Id);

    expect($sumber->Ambil($t['Tenant']->Id))->toBe($tanpaOutlet)
        ->and($sumber->Ambil($t['Tenant']->Id, idOutlet: $t['Outlet']->Id))->toBe($denganOutlet);

    // Tenant lain yang tidak ada = tanpa langganan, tetap terjawab (semua batas nol) dan tidak merusak ingatan.
    for ($i = 1; $i <= 80; $i++) {
        $sumber->Ambil(9_000_000 + $i);
    }

    expect($sumber->Ambil($t['Tenant']->Id)->fiturPaket)->toBe($tanpaOutlet->fiturPaket);
});
