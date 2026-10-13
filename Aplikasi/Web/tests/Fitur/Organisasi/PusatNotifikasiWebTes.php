<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\ProdukGudang;
use App\Domain\Organisasi\Aksi\BuatNotifikasiOperasional;
use App\Domain\Organisasi\Aksi\BuatNotifikasiPengguna;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\NotifikasiDibisukan;
use App\Domain\Organisasi\Model\NotifikasiPengguna;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F31 (PLT-08) pusat notifikasi web: notifikasi operasional kini juga dibuat untuk anggota yang hanya memakai web
 * (tanpa Aplikasi Owner), lonceng + halaman /kelola/notifikasi membacanya, tanda sudah dibaca (satu/semua, hanya milik
 * sendiri), tautan halaman tujuan (hanya di bawah /kelola), dan jenis yang bisa dibisukan (kecuali Persetujuan).
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
    Queue::fake();
});

/** @return array<string, mixed> tenant dengan satu produk yang stoknya di bawah minimum (butir Kotak Tindakan "Stok menipis"). */
function SiapkanStokMenipis(object $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    ProdukGudang::query()->create(['IdProduk' => $minyak->Id, 'IdGudang' => $k['Gudang']->Id, 'StokMinimum' => '1000']);

    return $k;
}

it('notifikasi operasional juga dibuat untuk anggota tanpa perangkat Owner; lonceng dan halaman web membacanya dengan tautan', function (): void {
    $k = SiapkanStokMenipis($this);
    $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);

    app(BuatNotifikasiOperasional::class)->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-07', 'Asia/Jakarta'));

    $notifikasi = NotifikasiPengguna::query()->where('IdPengguna', $k['Pemilik']->Id)->where('Jenis', 'StokKritis')->sole();
    expect($notifikasi->Data)->toMatchArray(['Href' => '/kelola/laporan/stok?tab=kritis'])
        ->and(NotifikasiPengguna::query()->where('IdPengguna', $kasir->Id)->where('Jenis', 'StokKritis')->exists())->toBeFalse();

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $this->get('/kelola/notifikasi')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Notifikasi')
            ->where('NotifikasiBelumDibaca', fn ($n): bool => $n >= 1)
            ->where('BelumDibaca', fn ($n): bool => $n >= 1)
            ->where('Notifikasi', fn ($daftar): bool => collect($daftar)->contains(fn (array $n): bool => $n['Uuid'] === $notifikasi->Uuid
                && $n['Tautan'] === '/kelola/laporan/stok?tab=kritis'
                && $n['LabelJenis'] === 'Stok menipis'
                && $n['DibacaPada'] === null))
            ->where('Saring.Tampil', 'semua'));
});

it('tandai dibaca: satu atau semua, hanya milik sendiri; filter belum dibaca; lencana ikut turun', function (): void {
    $k = SiapkanStokMenipis($this);
    $lain = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Admin);
    $buat = app(BuatNotifikasiPengguna::class);
    $satu = $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::PerangkatOffline, 'uji:1', 'Perangkat POS tidak aktif', '2 perangkat tidak terhubung.');
    $dua = $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::PiutangJatuhTempo, 'uji:2', 'Piutang lewat jatuh tempo', '3 piutang.');
    $milikLain = $buat->Jalankan($lain->Id, JenisNotifikasiPengguna::PerangkatOffline, 'uji:3', 'Perangkat POS tidak aktif', 'Milik admin.');

    $masuk = BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $masuk->post('/kelola/notifikasi/dibaca', ['Uuid' => [$satu?->Uuid, $milikLain?->Uuid]])->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    expect($satu?->fresh()?->DibacaPada)->not->toBeNull()
        ->and($dua?->fresh()?->DibacaPada)->toBeNull()
        ->and($milikLain?->fresh()?->DibacaPada)->toBeNull();

    $masuk->get('/kelola/notifikasi?tampil=belum-dibaca')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tampil', 'belum-dibaca')
        ->where('BelumDibaca', 1)
        ->where('Notifikasi', fn ($daftar): bool => collect($daftar)->pluck('Uuid')->all() === [$dua?->Uuid]));

    $masuk->post('/kelola/notifikasi/dibaca', ['Semua' => true])->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($dua?->fresh()?->DibacaPada)->not->toBeNull()
        ->and($milikLain?->fresh()?->DibacaPada)->toBeNull();
    $masuk->get('/kelola/notifikasi')->assertInertia(fn (AssertableInertia $h) => $h->where('BelumDibaca', 0)->where('NotifikasiBelumDibaca', 0));

    $masuk->post('/kelola/notifikasi/dibaca', ['Uuid' => ['bukan-ulid']])->assertSessionHasErrors('Uuid.0');
});

it('tautan: Href di bawah /kelola dipakai; Href ke luar diabaikan; notifikasi lama memakai tautan bawaan jenisnya; Persetujuan tanpa tautan web', function (): void {
    $k = SiapkanStokMenipis($this);
    $buat = app(BuatNotifikasiPengguna::class);
    $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::StokKritis, 'uji:a', 'Stok menipis', 'A', ['Href' => '/kelola/laporan/stok?tab=kritis']);
    $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::PerangkatOffline, 'uji:b', 'Perangkat', 'B', ['Href' => 'https://jahat.example/kelola/perangkat']);
    $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::PiutangJatuhTempo, 'uji:c', 'Piutang', 'C', ['Tautan' => 'notifikasi']);
    $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::Persetujuan, 'uji:d', 'Persetujuan', 'D', ['Tautan' => 'persetujuan']);
    $buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::SelisihKas, 'uji:e', 'Shift', 'E', ['Href' => '//jahat.example/kelola']);

    BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id)->get('/kelola/notifikasi')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Notifikasi', function ($daftar): bool {
            $tautan = collect($daftar)->pluck('Tautan', 'Isi');

            return $tautan['A'] === '/kelola/laporan/stok?tab=kritis'
                && $tautan['B'] === '/kelola/perangkat'
                && $tautan['C'] === '/kelola/piutang'
                && $tautan['D'] === null
                && $tautan['E'] === '/kelola/kasir/shift?saring[PerluTinjauan]=Ya';
        }));
});

it('bisukan jenis: notifikasi baru jenis itu tidak dibuat (Persetujuan tetap); pengaturan menggantikan, tidak menumpuk', function (): void {
    $k = SiapkanStokMenipis($this);
    $masuk = BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
    $buat = app(BuatNotifikasiPengguna::class);

    $masuk->put('/kelola/notifikasi/pengaturan', ['Dibisukan' => ['StokKritis', 'PerangkatOffline']])->assertRedirect()->assertSessionHas('Kilat', 'Pengaturan notifikasi disimpan.');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(NotifikasiDibisukan::query()->where('IdPengguna', $k['Pemilik']->Id)->orderBy('Jenis')->get()->map(fn ($b) => $b->Jenis->value)->all())->toBe(['PerangkatOffline', 'StokKritis'])
        ->and($buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::StokKritis, 'uji:1', 'Stok menipis', 'x'))->toBeNull()
        ->and($buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::PiutangJatuhTempo, 'uji:2', 'Piutang', 'x'))->not->toBeNull()
        ->and($buat->Jalankan($k['Pemilik']->Id, JenisNotifikasiPengguna::Persetujuan, 'uji:3', 'Persetujuan', 'x'))->not->toBeNull();

    // Operasional ikut menghormati bisuan: stok menipis tidak dibuat, jenis lain tetap.
    $dibuat = app(BuatNotifikasiOperasional::class)->Jalankan($k['Tenant']->Id, CarbonImmutable::parse('2026-10-07', 'Asia/Jakarta'));
    expect(NotifikasiPengguna::query()->where('Jenis', 'StokKritis')->count())->toBe(0)
        ->and($dibuat)->toBeGreaterThanOrEqual(0);

    $masuk->get('/kelola/notifikasi')->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Dibisukan', fn ($daftar): bool => collect($daftar)->sort()->values()->all() === ['PerangkatOffline', 'StokKritis'])
        ->where('Jenis', fn ($jenis): bool => count($jenis) === 6 && collect($jenis)->firstWhere('Nilai', 'Persetujuan')['BisaDibisukan'] === false));

    $masuk->put('/kelola/notifikasi/pengaturan', ['Dibisukan' => ['PiutangJatuhTempo']])->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(NotifikasiDibisukan::query()->where('IdPengguna', $k['Pemilik']->Id)->pluck('Jenis')->map(fn ($j) => $j->value)->all())->toBe(['PiutangJatuhTempo']);

    $masuk->put('/kelola/notifikasi/pengaturan', ['Dibisukan' => []])->assertRedirect();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(NotifikasiDibisukan::query()->count())->toBe(0);
});

it('pengaturan ditolak: Persetujuan, jenis tidak dikenal, atau tanpa daftar', function (): void {
    $k = SiapkanStokMenipis($this);
    $masuk = BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

    $masuk->put('/kelola/notifikasi/pengaturan', ['Dibisukan' => ['Persetujuan']])->assertSessionHasErrors('Umum');
    $masuk->put('/kelola/notifikasi/pengaturan', ['Dibisukan' => ['Bukan Jenis']])->assertSessionHasErrors('Dibisukan.0');
    $masuk->put('/kelola/notifikasi/pengaturan', [])->assertSessionHasErrors('Dibisukan');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(NotifikasiDibisukan::query()->count())->toBe(0);
});

it('isolasi tenant: pengguna tenant lain tidak melihat dan tidak bisa menandai notifikasi tenant ini', function (): void {
    $a = SiapkanStokMenipis($this);
    $notifikasi = app(BuatNotifikasiPengguna::class)->Jalankan($a['Pemilik']->Id, JenisNotifikasiPengguna::PerangkatOffline, 'uji:a', 'Perangkat POS tidak aktif', 'Milik tenant A.');

    $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
    $masukB = BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id);
    $masukB->get('/kelola/notifikasi')->assertInertia(fn (AssertableInertia $h) => $h->where('Notifikasi', [])->where('BelumDibaca', 0));
    $masukB->post('/kelola/notifikasi/dibaca', ['Uuid' => [$notifikasi?->Uuid]])->assertRedirect();
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    expect($notifikasi?->fresh()?->DibacaPada)->toBeNull();
});
