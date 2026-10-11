<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Organisasi\Model\PeranIzin;
use App\Domain\Organisasi\Model\TenantPengguna;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

describe('F-01 langkah 6: perangkat kasir (memakai aktivasi F-02b)', function (): void {
    it('menambah perangkat Kasir di outlet wizard lalu menampilkan kode aktivasi + QR di halaman wizard', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Outlet' => $outlet] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/perangkat', ['Nama' => 'Kasir Depan Dekat Pintu Masuk'])
            ->assertRedirect('/kelola/panduan-awal/perangkat')
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('Kilat');
        $kode = session('KodeAktivasiBaru');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $perangkat = Perangkat::query()->sole();
        expect($perangkat->Jenis)->toBe(JenisPerangkat::Kasir)
            ->and($perangkat->IdOutlet)->toBe($outlet->Id)
            ->and($kode['KodePerangkat'])->toBe("{$outlet->Kode}-K01")
            ->and($kode['Kode'])->toMatch('/^[2-9A-HJ-NP-Z]{8}$/');

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->withSession(['KodeAktivasiBaru' => $kode])->get('/kelola/panduan-awal/perangkat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/PanduanAwal/Perangkat')
                ->where('Outlet.Uuid', $outlet->Uuid)
                ->where('Outlet.BatasPerangkat', ['Batas' => 5, 'Terpakai' => 1])
                ->where('Perangkat.0', ['Uuid' => $perangkat->Uuid, 'Kode' => "{$outlet->Kode}-K01", 'Nama' => 'Kasir Depan Dekat Pintu Masuk', 'LabelJenis' => JenisPerangkat::Kasir->AmbilLabel(), 'Status' => 'BelumDiaktifkan'])
                ->where('KodeAktivasiBaru.Kode', $kode['Kode'])
                ->where('KodeAktivasiBaru.QrSvg', fn (string $svg) => str_contains($svg, '<svg'))
                ->where('BolehKelolaPerangkat', true));

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post("/kelola/panduan-awal/perangkat/{$perangkat->Uuid}/kode-aktivasi")
            ->assertRedirect('/kelola/panduan-awal/perangkat')
            ->assertSessionHas('KodeAktivasiBaru.UuidPerangkat', $perangkat->Uuid);
    });

    it('audit kemudahan pakai #2: PIN kasir pemilik ditanyakan di langkah ini dan tersimpan tanpa keluar dari panduan', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/perangkat')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PinSayaDiatur', false));

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->from('/kelola/panduan-awal/perangkat')
            ->put('/kelola/keamanan/pin', ['Pin' => '583920', 'KonfirmasiPin' => '583920'])
            ->assertRedirect('/kelola/panduan-awal/perangkat')
            ->assertSessionHasNoErrors();

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/perangkat')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('PinSayaDiatur', true));
        expect(TenantPengguna::query()->where('IdTenant', $tenant->Id)->where('IdPengguna', $pemilik->Id)->value('HashPin'))->not->toBeNull();
    });

    it('BR-02.1: batas perangkat per outlet paket tetap berlaku (GRATIS = 1)', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant(kodePaket: 'GRATIS');

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/perangkat', ['Nama' => 'Kasir 1'])->assertSessionHasNoErrors();
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->post('/kelola/panduan-awal/perangkat', ['Nama' => 'Kasir 2'])->assertSessionHasErrors('Umum');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Perangkat::query()->count())->toBe(1);
    });

    it('peran kustom dengan panduan-awal.kelola tanpa perangkat.kelola: halaman terbuka tanpa tombol, tambah perangkat 403', function (): void {
        ['Tenant' => $tenant] = BantuanPanduanAwal::BuatTenant();
        $peran = Peran::query()->create(['Kode' => 'PenyiapToko', 'Nama' => 'Penyiap Toko', 'Bawaan' => false]);
        PeranIzin::query()->create(['IdPeran' => $peran->Id, 'KunciIzin' => IzinTenant::PanduanAwalKelola->value]);
        $anggota = Pengguna::factory()->create();
        TenantPengguna::query()->create(['IdTenant' => $tenant->Id, 'IdPengguna' => $anggota->Id, 'Pemilik' => false, 'IdPeran' => $peran->Id, 'SemuaOutlet' => true]);

        BantuanPanduanAwal::Masuk($this, $anggota, $tenant)->get('/kelola/panduan-awal/perangkat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->where('BolehKelolaPerangkat', false));
        BantuanPanduanAwal::Masuk($this, $anggota, $tenant)->post('/kelola/panduan-awal/perangkat', ['Nama' => 'Kasir Depan'])
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Kelola/TanpaIzin'));

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Perangkat::query()->count())->toBe(0);
    });
});
