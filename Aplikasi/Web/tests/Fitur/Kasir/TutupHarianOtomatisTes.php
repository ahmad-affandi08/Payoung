<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Kasir\Model\TutupHarian;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Penjualan\Model\Penjualan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-23 D bagian 3: tiap pagi hari yang aman ditutup (sudah berakhir, ada shift & semuanya ditutup, tanpa peringatan)
 * ditutup otomatis atas nama Owner lewat aksi tutup harian yang sama (`DitutupOtomatis`). Hari dengan shift terbuka,
 * peringatan, tanpa shift, atau yang masih berjalan dibiarkan untuk ditutup manual. Menjalankan ulang tidak menggandakan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Carbon::setTestNow(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

it('hari aman ditutup otomatis atas nama Owner. Hari tanpa shift & hari berjalan dibiarkan. Ulang tidak menggandakan', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    Shift::query()->update(['Status' => StatusShift::Tertutup->value, 'DitutupPada' => now()]);

    // 17 Oktober pagi: perangkat sudah tersambung lagi setelah 15 Oktober berakhir.
    Carbon::setTestNow(Carbon::parse('2026-10-17 03:00:00', 'UTC'));
    Perangkat::query()->update(['TerakhirAktifPada' => now()]);

    expect(Artisan::call('kasir:tutup-harian-otomatis', ['--tenant' => [$k['Tenant']->Id]]))->toBe(0);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $tutup = TutupHarian::query()->sole();
    expect($tutup->TanggalBisnis->toDateString())->toBe('2026-10-15')
        ->and($tutup->DitutupOtomatis)->toBeTrue()
        ->and($tutup->DitutupOleh)->toBe($k['Pemilik']->Id)
        ->and($tutup->JumlahTransaksi)->toBe(1)
        ->and($tutup->PenjualanBersih)->toBe('77000.00')
        ->and(LogAudit::query()->where('Peristiwa', 'kasir.tutup-harian')->count())->toBe(1);

    Artisan::call('kasir:tutup-harian-otomatis', ['--tenant' => [$k['Tenant']->Id]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(TutupHarian::query()->count())->toBe(1);

    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
    $this->get('/kelola/kasir/tutup-harian')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Hari.0.TanggalBisnis', '2026-10-17')
        ->where('Hari.0.Ditutup', false)
        ->where('Hari.1.TanggalBisnis', '2026-10-16')
        ->where('Hari.1.Ditutup', false)
        ->where('Hari.2.TanggalBisnis', '2026-10-15')
        ->where('Hari.2.Ditutup', true)
        ->where('Hari.2.DitutupOtomatis', true));
});

it('shift masih terbuka atau ada peringatan: tidak ditutup otomatis. Tenant lain tidak tersentuh', function (): void {
    $a = BantuanPenjualan::Siapkan($this, 'Kopi Senja Solo');
    $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    $minyakA = BantuanPenjualan::BuatProdukBerstok($a['Gudang'], $a['Pemilik']->Id);
    BantuanPenjualan::Jual($this, $a, ['Baris' => [['Produk' => $minyakA, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    $minyakB = BantuanPenjualan::BuatProdukBerstok($b['Gudang'], $b['Pemilik']->Id);
    $p = BantuanPenjualan::Jual($this, $b, ['Baris' => [['Produk' => $minyakB, 'Jumlah' => '1', 'Harga' => '38500.00']]]);

    // Tenant B: shift ditutup tetapi penjualan perlu ditinjau → peringatan.
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    Shift::query()->update(['Status' => StatusShift::Tertutup->value, 'DitutupPada' => now()]);
    Penjualan::query()->whereKey($p->Id)->update(['PerluTinjauan' => true, 'AlasanTinjauan' => 'Uji']);

    Carbon::setTestNow(Carbon::parse('2026-10-17 03:00:00', 'UTC'));
    foreach ([$a, $b] as $k) {
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Perangkat::query()->update(['TerakhirAktifPada' => now()]);
    }

    // Tenant A: shift 15 Oktober lupa ditutup.
    expect(Artisan::call('kasir:tutup-harian-otomatis'))->toBe(0);
    foreach ([$a, $b] as $k) {
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TutupHarian::query()->count())->toBe(0);
    }

    // Setelah shift A ditutup, hanya A yang ditutup otomatis; B tetap menunggu tinjauan manual.
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    Shift::query()->update(['Status' => StatusShift::Tertutup->value, 'DitutupPada' => now()]);
    Artisan::call('kasir:tutup-harian-otomatis');
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    expect(TutupHarian::query()->sole()->DitutupOtomatis)->toBeTrue();
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    expect(TutupHarian::query()->count())->toBe(0);
});
