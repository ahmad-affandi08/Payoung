<?php

declare(strict_types=1);

use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * D-23: menu tidak disembunyikan per fitur. Props bersama `FiturPaket` memuat fitur di luar paket beserta paket termurah
 * yang memuatnya dan add-on aktif yang membukanya; Pemilik bisa meminta add-on dari dialog (menjadi tiket dukungan
 * karena pembelian add-on mandiri belum tersedia). Fitur yang sudah aktif tidak ditawarkan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
});

/** @return array{Tenant: Tenant, Pemilik: Pengguna} */
function SiapkanTenantStarter(): array
{
    $t = BantuanOrganisasi::BuatTenant('Toko Kelontong Berkah Boyolali');
    Langganan::query()->where('IdTenant', $t['Tenant']->Id)->update(['IdPaket' => Paket::query()->where('Kode', 'STARTER')->value('Id')]);
    Addon::query()->where('Kode', 'SELF_ORDER')->update(['Status' => StatusPaket::Aktif->value]);

    return $t;
}

it('fitur di luar paket Starter ditawarkan paket termurah & add-on. Fitur paket tidak ditawarkan', function (): void {
    $t = SiapkanTenantStarter();

    BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id)->get('/kelola')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('FiturPaket.NamaPaket', 'Starter')
        ->where('FiturPaket.Terkunci', function ($terkunci): bool {
            $t = collect($terkunci)->toArray();

            return $t['promo.mesin']['Nama'] === 'Mesin promo'
                && $t['promo.mesin']['Paket']['Kode'] === 'PRO'
                && $t['stok.transfer']['Paket']['Kode'] === 'BISNIS'
                && $t['kanal.self-order']['Addon']['Kode'] === 'SELF_ORDER'
                && ! array_key_exists('stok.dasar', $t)
                && ! array_key_exists('laporan.lengkap', $t)
                // Add-on yang masih diarsipkan (belum dijual) tidak ditawarkan.
                && $t['laporan.insight']['Addon'] === null;
        }));
});

it('Pemilik meminta add-on → tiket dukungan. Fitur aktif/tanpa add-on ditolak, tanpa izin langganan 403', function (): void {
    $t = SiapkanTenantStarter();
    $masuk = fn () => BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

    $masuk()->post('/kelola/langganan/addon', ['KunciFitur' => 'kanal.self-order'])
        ->assertRedirect()
        ->assertSessionHas('Kilat', 'Permintaan add-on Self-order QR terkirim. Tim kami akan mengaktifkannya dan mengirim tagihan.');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $tiket = TiketDukungan::query()->sole();
    expect($tiket->Judul)->toBe('Permintaan add-on Self-order QR')
        ->and($tiket->Pesan()->value('Isi'))->toContain('Rp 39.000/bulan');

    $masuk()->post('/kelola/langganan/addon', ['KunciFitur' => 'stok.dasar'])->assertSessionHasErrors('Umum');
    $masuk()->post('/kelola/langganan/addon', ['KunciFitur' => 'promo.mesin'])->assertSessionHasErrors('Umum');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(TiketDukungan::query()->count())->toBe(1);

    $kasir = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Kasir);
    BantuanOrganisasi::Masuk($this, $kasir, $t['Tenant']->Id)->post('/kelola/langganan/addon', ['KunciFitur' => 'kanal.self-order'])->assertForbidden();
});
