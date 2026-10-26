<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Kasir\Model\TutupHarian;
use App\Domain\Laporan\Model\RingkasanPenjualanHarian;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Penjualan\Model\Penjualan;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // 15 Oktober 2026 pukul 10.00 WIB (jam tutup buku outlet 04.00): tanggal bisnis outlet = 15 Oktober.
    Carbon::setTestNow(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

describe('F-15 tutup harian per outlet', function (): void {
    it('shift belum ditutup ditolak. Setelah shift ditutup hari ditutup, ringkasan dihitung ulang & dicuplik, audit. Tidak bisa dua kali atau tanggal depan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $uuidOutlet = $k['Outlet']->Uuid;

        $this->get('/kelola/kasir/tutup-harian')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/TutupHarian')
            ->has('Hari', 14)
            ->where('Hari.0.TanggalBisnis', '2026-10-15')
            ->where('Hari.0.Berjalan', true)
            ->where('Hari.0.Ditutup', false)
            ->where('Hari.0.ShiftBelumDitutup', 1)
            ->where('Hari.0.Peringatan', [])
            ->where('Hari.13.TanggalBisnis', '2026-10-02')
            ->where('Izin.Kelola', true));

        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $uuidOutlet, 'TanggalBisnis' => '2026-10-15'])
            ->assertSessionHasErrors(['TanggalBisnis' => 'Masih ada 1 shift tanggal 15 Oktober 2026 yang belum ditutup. Tutup shift di aplikasi kasir dulu.']);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Shift::query()->update(['Status' => StatusShift::Tertutup->value, 'DitutupPada' => now()]);
        RingkasanPenjualanHarian::query()->delete();

        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $uuidOutlet, 'TanggalBisnis' => '2026-10-15'])
            ->assertRedirect('/kelola/kasir/tutup-harian');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tutup = TutupHarian::query()->sole();
        $ringkasan = RingkasanPenjualanHarian::query()->sole();
        expect($tutup->TanggalBisnis->toDateString())->toBe('2026-10-15')
            ->and($tutup->IdOutlet)->toBe($k['Outlet']->Id)
            ->and($tutup->JumlahTransaksi)->toBe(1)
            ->and($tutup->PenjualanBersih)->toBe('77000.00')
            ->and($ringkasan->Bersih)->toBe('77000.00')
            ->and($tutup->Peringatan)->toBeNull()
            ->and(LogAudit::query()->where('Peristiwa', 'kasir.tutup-harian')->count())->toBe(1);

        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $uuidOutlet, 'TanggalBisnis' => '2026-10-15'])
            ->assertSessionHasErrors(['TanggalBisnis' => 'Tanggal 15 Oktober 2026 di outlet ini sudah ditutup.']);
        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $uuidOutlet, 'TanggalBisnis' => '2026-10-16'])
            ->assertSessionHasErrors(['TanggalBisnis' => 'Tanggal 16 Oktober 2026 belum berjalan di outlet ini.']);

        $this->get('/kelola/kasir/tutup-harian')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Hari.0.Ditutup', true)
            ->where('Hari.0.JumlahTransaksi', 1)
            ->where('Hari.0.PenjualanBersih', '77000.00')
            ->where('Hari.0.DitutupOleh', auth()->user()?->Nama));
    });

    it('perangkat belum sinkron sejak hari berakhir & penjualan perlu tinjauan = peringatan. Wajib dikonfirmasi lalu disimpan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Shift::query()->update(['Status' => StatusShift::Tertutup->value, 'DitutupPada' => now()]);
        Penjualan::query()->whereKey($p->Id)->update(['PerluTinjauan' => true, 'AlasanTinjauan' => 'Uji']);

        // Dua hari kemudian: perangkat terakhir aktif 15 Oktober, belum tersambung sejak 15 Oktober berakhir.
        Carbon::setTestNow(Carbon::parse('2026-10-17 03:00:00', 'UTC'));
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->get('/kelola/kasir/tutup-harian')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Hari.2.TanggalBisnis', '2026-10-15')
            ->where('Hari.2.ShiftBelumDitutup', 0)
            ->where('Hari.2.Peringatan.0.Kode', 'PerangkatBelumSinkron')
            ->where('Hari.2.Peringatan.1.Kode', 'PenjualanPerluTinjauan')
            ->where('Hari.2.Peringatan.1.Pesan', '1 penjualan ditandai perlu ditinjau.'));

        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $k['Outlet']->Uuid, 'TanggalBisnis' => '2026-10-15'])
            ->assertSessionHasErrors('AbaikanPeringatan');
        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $k['Outlet']->Uuid, 'TanggalBisnis' => '2026-10-15', 'AbaikanPeringatan' => true])
            ->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(array_column(TutupHarian::query()->sole()->Peringatan ?? [], 'Kode'))->toBe(['PerangkatBelumSinkron', 'PenjualanPerluTinjauan']);

        // Perangkat yang tersambung setelah hari berakhir tidak lagi memicu peringatan (16 Oktober hanya perlu 0 shift).
        Perangkat::query()->update(['TerakhirAktifPada' => now()]);
        $this->get('/kelola/kasir/tutup-harian')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Hari.1.TanggalBisnis', '2026-10-16')
            ->where('Hari.1.Peringatan', []));
        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $k['Outlet']->Uuid, 'TanggalBisnis' => '2026-10-16'])->assertRedirect();
    });

    it('izin: Kasir tidak bisa melihat/menutup. Outlet tenant lain = 404. Tutup harian tenant lain tidak terlihat', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Kopi Senja Solo');
        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        foreach ([$a, $b] as $k) {
            BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
            Shift::query()->update(['Status' => StatusShift::Tertutup->value, 'DitutupPada' => now()]);
        }

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/kasir/tutup-harian')->assertForbidden();
        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $a['Outlet']->Uuid, 'TanggalBisnis' => '2026-10-15'])->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $b['Outlet']->Uuid, 'TanggalBisnis' => '2026-10-15'])->assertNotFound();
        $this->post('/kelola/kasir/tutup-harian', ['Outlet' => $a['Outlet']->Uuid, 'TanggalBisnis' => '2026-10-15'])->assertRedirect();

        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->get('/kelola/kasir/tutup-harian')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Hari.0.Outlet', $b['Outlet']->Uuid)
            ->where('Hari.0.Ditutup', false));
        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(TutupHarian::query()->count())->toBe(0);
    });
});
