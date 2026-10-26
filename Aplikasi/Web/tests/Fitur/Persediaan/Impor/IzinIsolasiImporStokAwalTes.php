<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Model\ImporStokAwal;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanImporStokAwal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/**
 * @return list<list<string|int|null>>
 */
function BuatBarisImporSederhana(): array
{
    return [BantuanImporStokAwal::JUDUL, ['MGS-2L', 'Minyak Goreng Sawit 2 Liter', 'pcs', '', '24', '38.500']];
}

describe('F-05a impor stok awal: izin persediaan.kelola & isolasi tenant/outlet', function (): void {
    it('Kasir (tanpa persediaan.kelola) 403 di semua rute impor; Staf Gudang boleh', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $pemilik = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($pemilik, BantuanImporStokAwal::BuatCsv(BuatBarisImporSederhana()), $t['Gudang']->Uuid);
        $p = "/kelola/persediaan/stok-awal/impor/{$impor->Uuid}";
        $kasir = fn () => BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);

        $kasir()->get('/kelola/persediaan/stok-awal/impor')->assertForbidden();
        $kasir()->get('/kelola/persediaan/stok-awal/impor/templat?format=xlsx')->assertForbidden();
        $kasir()->post('/kelola/persediaan/stok-awal/impor', ['Berkas' => BantuanImporStokAwal::BuatCsv(BuatBarisImporSederhana())])->assertForbidden();
        $kasir()->get($p)->assertForbidden();
        $kasir()->get("{$p}/status")->assertForbidden();
        $kasir()->put("{$p}/pemetaan", ['Pemetaan' => $impor->Pemetaan, 'Tanggal' => now()->toDateString()])->assertForbidden();
        $kasir()->post("{$p}/terapkan")->assertForbidden();
        $kasir()->post("{$p}/lanjutkan")->assertForbidden();
        $kasir()->post("{$p}/batalkan")->assertForbidden();
        $kasir()->get("{$p}/laporan?jenis=semua&format=csv")->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang)->get($p)->assertOk();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporStokAwal::MenungguPemetaan);
    });

    it('isolasi tenant: impor tenant lain = 404 di semua rute dan tidak tampil di riwayat', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        $masukA = BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($masukA, BantuanImporStokAwal::BuatCsv(BuatBarisImporSederhana()), $a['Gudang']->Uuid);

        $b = BantuanPersediaan::SiapkanTenant('Apotek Sehat Sentosa');
        $masukB = BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $p = "/kelola/persediaan/stok-awal/impor/{$impor->Uuid}";

        $masukB->get($p)->assertNotFound();
        $masukB->get("{$p}/status")->assertNotFound();
        $masukB->put("{$p}/pemetaan", ['Pemetaan' => $impor->Pemetaan, 'Tanggal' => now()->subDay()->toDateString()])->assertNotFound();
        $masukB->post("{$p}/terapkan")->assertNotFound();
        $masukB->post("{$p}/lanjutkan")->assertNotFound();
        $masukB->post("{$p}/batalkan")->assertNotFound();
        $masukB->get("{$p}/laporan?jenis=semua&format=csv")->assertNotFound();
        $masukB->get('/kelola/persediaan/stok-awal/impor')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Riwayat.Data', 0));

        // Pemetaan dengan lokasi bawaan tenant lain juga 404.
        $masukA = BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id);
        BantuanImporStokAwal::Petakan($masukA, $impor, $b['Gudang']->Uuid)->assertNotFound();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporStokAwal::MenungguPemetaan);
    });

    it('pengguna berakses per outlet hanya melihat impornya sendiri. Lokasi di luar aksesnya = 404', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $pemilik = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $imporPemilik = BantuanImporStokAwal::Unggah($pemilik, BantuanImporStokAwal::BuatCsv(BuatBarisImporSederhana()), $t['Gudang']->Uuid);

        $terbatas = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::StafGudang, false);
        $terbatas->get("/kelola/persediaan/stok-awal/impor/{$imporPemilik->Uuid}")->assertNotFound();
        $terbatas->post('/kelola/persediaan/stok-awal/impor', ['Berkas' => BantuanImporStokAwal::BuatCsv(BuatBarisImporSederhana()), 'UuidGudangBawaan' => $t['Gudang']->Uuid])->assertNotFound();

        $imporSendiri = BantuanImporStokAwal::Unggah($terbatas, BantuanImporStokAwal::BuatCsv([...BuatBarisImporSederhana(), ['GULA-1', '', '', '', '5', '14000']]));
        $terbatas->get('/kelola/persediaan/stok-awal/impor')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Riwayat.Data', 1)
            ->where('Riwayat.Data.0.Uuid', $imporSendiri->Uuid)
            ->has('OpsiGudang', 0));
        $terbatas->get("/kelola/persediaan/stok-awal/impor/{$imporSendiri->Uuid}")->assertOk();
    });

    it('batalkan dari MenungguPemetaan: audit stok-awal.impor.batalkan. Tidak bisa dibatalkan dua kali atau diterapkan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
        $impor = BantuanImporStokAwal::Unggah($masuk, BantuanImporStokAwal::BuatCsv(BuatBarisImporSederhana()), $t['Gudang']->Uuid);
        $p = "/kelola/persediaan/stok-awal/impor/{$impor->Uuid}";

        $masuk->post("{$p}/batalkan")->assertSessionHasNoErrors();
        $masuk->post("{$p}/batalkan")->assertSessionHasErrors('Impor');
        $masuk->post("{$p}/terapkan")->assertSessionHasErrors('Impor');
        $masuk->post("{$p}/lanjutkan")->assertSessionHasErrors('Impor');
        BantuanImporStokAwal::Petakan($masuk, $impor, $t['Gudang']->Uuid)->assertSessionHasErrors('Pemetaan');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($impor->refresh()->Status)->toBe(StatusImporStokAwal::Dibatalkan)
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.impor.batalkan')->sole()->NilaiBaru)->toBe(['Status' => 'Dibatalkan'])
            ->and(ImporStokAwal::query()->count())->toBe(1);
    });
});
