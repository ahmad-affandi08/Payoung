<?php

declare(strict_types=1);

use App\Domain\Organisasi\Aksi\BuatNotifikasiPengguna;
use App\Domain\Organisasi\Aksi\UbahPengaturanNotifikasi;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Kueri\PenerimaNotifikasi;
use App\Domain\Organisasi\Model\NotifikasiOutletDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use App\Domain\Organisasi\Model\NotifikasiPushDimatikan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Tugas\KirimNotifikasiPushTugas;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * PLT-08: preferensi notifikasi per kanal (push ke Aplikasi Owner dimatikan per jenis, notifikasi web tetap ada) dan per
 * outlet (kabar yang membawa outlet tidak dibuat untuk outlet yang dibisukan), melengkapi bisukan per jenis (F31).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Queue::fake();
});

it('push dimatikan per jenis: notifikasi tetap dibuat untuk web tetapi tidak dikirim ke FCM. Jenis lain tetap dikirim', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    app(UbahPengaturanNotifikasi::class)->Jalankan($k['Pemilik']->Id, [], ['StokKritis']);
    $buat = app(BuatNotifikasiPengguna::class);

    $stok = $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::StokKritis, 'uji:stok', 'Stok menipis', 'x');
    $kas = $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::SelisihKas, 'uji:kas', 'Selisih kas', 'x');

    expect($stok)->not->toBeNull()->and($kas)->not->toBeNull()
        ->and(NotifikasiPengguna::query()->count())->toBe(2);
    Queue::assertPushed(KirimNotifikasiPushTugas::class, 1);
    Queue::assertPushed(KirimNotifikasiPushTugas::class, fn (KirimNotifikasiPushTugas $t): bool => $t->idNotifikasi === $kas->Id);
});

it('persetujuan tidak bisa dimatikan pushnya. Jenis tidak dikenal ditolak', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $ubah = app(UbahPengaturanNotifikasi::class);

    expect(fn () => $ubah->Jalankan($k['Pemilik']->Id, [], ['Persetujuan']))->toThrow(Exception::class)
        ->and(fn () => $ubah->Jalankan($k['Pemilik']->Id, [], ['Ngawur']))->toThrow(Exception::class)
        ->and(NotifikasiPushDimatikan::query()->count())->toBe(0);
});

it('outlet dibisukan: penerima kabar outlet itu tidak lagi menyertakan pengguna. Outlet lain tetap. Di luar akses ditolak', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $cabang = BantuanJurnal::BuatOutlet('KLT', 'Cabang Klaten');
    $penerima = app(PenerimaNotifikasi::class);
    expect($penerima->AmbilIdPengguna($k['Tenant']->Id, IzinTenant::LaporanPenjualanLihat, $k['Outlet']->Id))->toContain($k['Pemilik']->Id);

    app(UbahPengaturanNotifikasi::class)->Jalankan($k['Pemilik']->Id, [], null, [$cabang->Uuid]);

    expect($penerima->AmbilIdPengguna($k['Tenant']->Id, IzinTenant::LaporanPenjualanLihat, $cabang->Id))->not->toContain($k['Pemilik']->Id)
        ->and($penerima->AmbilIdPengguna($k['Tenant']->Id, IzinTenant::LaporanPenjualanLihat, $k['Outlet']->Id))->toContain($k['Pemilik']->Id)
        ->and(NotifikasiOutletDibisukan::query()->count())->toBe(1);

    // Mengosongkan daftar mengaktifkan kembali.
    app(UbahPengaturanNotifikasi::class)->Jalankan($k['Pemilik']->Id, [], null, []);
    expect(NotifikasiOutletDibisukan::query()->count())->toBe(0);

    // Supervisor hanya berakses ke outlet utama: mem-bisukan outlet lain ditolak.
    $supervisor = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor, semuaOutlet: false);
    OutletPengguna::query()->create(['IdPengguna' => $supervisor->Id, 'IdOutlet' => $k['Outlet']->Id, 'IdPeran' => BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::Supervisor)->Id]);
    expect(fn () => app(UbahPengaturanNotifikasi::class)->Jalankan($supervisor->Id, [], null, [$cabang->Uuid]))->toThrow(Exception::class);
});

it('halaman dan simpan lewat web: props memuat preferensi dan outlet, PUT menyimpan push + outlet', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $cabang = BantuanJurnal::BuatOutlet('KLT', 'Cabang Klaten');
    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $this->put('/kelola/notifikasi/pengaturan', ['Dibisukan' => ['PerangkatOffline'], 'TanpaPush' => ['StokKritis'], 'OutletDibisukan' => [$cabang->Uuid]])
        ->assertSessionHasNoErrors();

    $this->get('/kelola/notifikasi')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Dibisukan', ['PerangkatOffline'])
        ->where('TanpaPush', ['StokKritis'])
        ->where('OutletDibisukan', [$cabang->Uuid])
        ->has('Outlet', 2));

    // Tanpa kunci baru, pengaturan lama (kirim Dibisukan saja) tidak menghapus preferensi push/outlet.
    $this->put('/kelola/notifikasi/pengaturan', ['Dibisukan' => []])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(NotifikasiPushDimatikan::query()->count())->toBe(1)->and(NotifikasiOutletDibisukan::query()->count())->toBe(1);

    $this->put('/kelola/notifikasi/pengaturan', ['Dibisukan' => [], 'TanpaPush' => ['Persetujuan']])->assertSessionHasErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(NotifikasiPushDimatikan::query()->count())->toBe(1);
});
