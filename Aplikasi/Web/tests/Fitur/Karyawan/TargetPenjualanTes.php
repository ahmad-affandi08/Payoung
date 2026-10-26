<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\Komisi;
use App\Domain\Karyawan\Model\TargetPenjualan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Data\DataSaringLaporanPenjualan;
use App\Domain\Penjualan\Kueri\AgregatPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-18 bagian 3 target penjualan (EMP-05): target bulanan per outlet/karyawan (satu per sasaran per periode, simpan
 * ulang = ganti nilai), realisasi outlet = penjualan bersih, realisasi karyawan = Σ dasar baris yang dilayani × porsi
 * dikurangi void, proyeksi bulan berjalan, izin, isolasi tenant.
 */

beforeEach(function (): void {
    // 10 Oktober 2026 pukul 10.00 WIB: hari ke-10 dari 31.
    Carbon::setTestNow(Carbon::parse('2026-10-10 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(fn () => Carbon::setTestNow());

describe('F-18 bagian 3 target penjualan', function (): void {
    it('target outlet & karyawan: realisasi, persen, proyeksi. Void mengurangi realisasi karyawan. Simpan ulang mengganti', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cantik Target');
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $maya = Karyawan::query()->create(['Nama' => 'Maya Senior']);
        $jual = fn (array $staf): array => BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00', 'Staf' => $staf]]]);
        $a = $jual([$maya->Uuid]);
        $b = $jual([$maya->Uuid]);
        $c = $jual([]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$a, $b, $c]))->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $dasar = (string) Komisi::query()->orderBy('Id')->firstOrFail()->Dasar;
        $void = Penjualan::query()->where('Uuid', $b['Uuid'])->sole();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $void)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Komisi::query()->where('IdPenjualan', $void->Id)->sole()->DasarDibatalkan)->toBe($dasar);
        $bersihOutlet = app(AgregatPenjualan::class)->Total(new DataSaringLaporanPenjualan(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), [$k['Outlet']->Id]))->Bersih();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->put('/kelola/karyawan/target', ['Periode' => '2026-10', 'Cakupan' => 'Outlet', 'Sasaran' => $k['Outlet']->Uuid, 'Nilai' => '0'])->assertSessionHasErrors('Nilai');
        $this->put('/kelola/karyawan/target', ['Periode' => '2026-10', 'Cakupan' => 'Karyawan', 'Sasaran' => $k['Outlet']->Uuid, 'Nilai' => '100000'])
            ->assertSessionHasErrors(['Sasaran' => 'Pilih karyawan yang aktif.']);
        $this->put('/kelola/karyawan/target', ['Periode' => '2026-10', 'Cakupan' => 'Outlet', 'Sasaran' => $k['Outlet']->Uuid, 'Nilai' => '1000000'])->assertRedirect('/kelola/karyawan/target?periode=2026-10');
        $this->put('/kelola/karyawan/target', ['Periode' => '2026-10', 'Cakupan' => 'Outlet', 'Sasaran' => $k['Outlet']->Uuid, 'Nilai' => '200000'])->assertRedirect();
        $this->put('/kelola/karyawan/target', ['Periode' => '2026-10', 'Cakupan' => 'Karyawan', 'Sasaran' => $maya->Uuid, 'Nilai' => '100000'])->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TargetPenjualan::query()->count())->toBe(2)
            ->and(TargetPenjualan::query()->where('Cakupan', 'Outlet')->sole()->Nilai)->toBe('200000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'target-penjualan.simpan')->count())->toBe(3);

        $persen = fn (Uang $realisasi, string $target): string => (string) BigDecimal::of($realisasi->KeString())->multipliedBy(100)->dividedBy($target, 1, RoundingMode::Down);
        $realisasiMaya = Uang::Dari($dasar);
        $this->get('/kelola/karyawan/target')->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Karyawan/Target')
            ->where('Periode', '2026-10')
            ->where('Berjalan', true)
            ->where('Target.0.Cakupan', 'Outlet')
            ->where('Target.0.Realisasi', $bersihOutlet->KeString())
            ->where('Target.0.Persen', $persen($bersihOutlet, '200000'))
            ->where('Target.0.Proyeksi', $bersihOutlet->Kali('3.1')->KeString())
            ->where('Target.1.NamaSasaran', 'Maya Senior')
            // Hanya penjualan A (B di-void, C tanpa staf).
            ->where('Target.1.Realisasi', $realisasiMaya->KeString())
            ->where('Target.1.Sisa', Uang::Dari('100000')->Kurangi($realisasiMaya)->KeString())
            ->where('Izin.Kelola', true));
        // Bulan lalu: tanpa target, tanpa proyeksi.
        $this->get('/kelola/karyawan/target?periode=2026-09')->assertInertia(fn (AssertableInertia $h) => $h->where('Berjalan', false)->count('Target', 0));
    });

    it('lihat butuh karyawan.lihat, ubah butuh karyawan.kelola; hapus; tenant lain tidak bisa mengakses', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Kopi Senja Solo');
        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/karyawan/target')->assertForbidden();
        $this->put('/kelola/karyawan/target', [])->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->put('/kelola/karyawan/target', ['Periode' => '2026-11', 'Cakupan' => 'Outlet', 'Sasaran' => $a['Outlet']->Uuid, 'Nilai' => '50000000'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $target = TargetPenjualan::query()->sole();
        $this->get('/kelola/karyawan/target?periode=2026-11')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Target.0.Realisasi', '0.00')
            ->where('Target.0.Persen', '0.0')
            ->where('Target.0.Proyeksi', null));

        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->put('/kelola/karyawan/target', ['Periode' => '2026-11', 'Cakupan' => 'Outlet', 'Sasaran' => $a['Outlet']->Uuid, 'Nilai' => '1000'])
            ->assertSessionHasErrors(['Sasaran' => 'Pilih outlet.']);
        $this->get('/kelola/karyawan/target?periode=2026-11')->assertInertia(fn (AssertableInertia $h) => $h->count('Target', 0));
        $this->delete("/kelola/karyawan/target/{$target->Uuid}")->assertNotFound();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->delete("/kelola/karyawan/target/{$target->Uuid}")->assertRedirect('/kelola/karyawan/target?periode=2026-11');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(TargetPenjualan::query()->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'target-penjualan.hapus')->count())->toBe(1);
    });
});
