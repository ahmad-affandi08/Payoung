<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Meja;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Tenant\Model\Tenant;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * POS-03 denah meja: letak meja (titik tengah pada kanvas relatif 0–1000) disimpan dari back-office lewat seret-lepas,
 * dibawa API POS ke kasir; null = belum ditata. Semua atau tidak sama sekali, izin outlet.kelola, isolasi tenant & outlet,
 * audit, dan tidak menyentuh pesanan terbuka.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function OutletDenah(Tenant $tenant): Outlet
{
    BantuanOrganisasi::AturKonteks($tenant->Id);

    return Outlet::query()->orderBy('Id')->firstOrFail();
}

describe('POS-03 denah meja', function (): void {
    it('simpan letak meja: tampil di detail outlet dan API POS. Null = belum ditata. Audit sekali per simpan', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $dua = Meja::query()->create(['IdOutlet' => $k['Outlet']->Id, 'Nama' => '8', 'Kapasitas' => 2]);
        $alamat = "/kelola/outlet/{$k['Outlet']->Uuid}/denah-meja";
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->put($alamat, ['Meja' => [
            ['Uuid' => $k['Meja']->Uuid, 'PosisiX' => 120, 'PosisiY' => 340],
            ['Uuid' => $dua->Uuid, 'PosisiX' => null, 'PosisiY' => null],
        ]])->assertSessionHasNoErrors()->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($k['Meja']->refresh()->PosisiX)->toBe(120)->and($k['Meja']->PosisiY)->toBe(340)
            ->and($dua->refresh()->PosisiX)->toBeNull();

        $meja = collect($this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->assertOk()->json('Meja'));
        expect($meja->firstWhere('Uuid', $k['Meja']->Uuid))->toMatchArray(['PosisiX' => 120, 'PosisiY' => 340])
            ->and($meja->firstWhere('Uuid', $dua->Uuid))->toMatchArray(['PosisiX' => null, 'PosisiY' => null]);

        // Meja yang sudah null tetap null (tidak dihitung berubah); simpan ulang tanpa perubahan tidak menambah audit.
        $this->put($alamat, ['Meja' => [['Uuid' => $k['Meja']->Uuid, 'PosisiX' => 120, 'PosisiY' => 340]]])->assertSessionHasNoErrors();
        expect(LogAudit::query()->where('Peristiwa', 'denah-meja.simpan')->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'denah-meja.simpan')->sole()->NilaiBaru)->toMatchArray(['JumlahMejaBerubah' => 1]);
    });

    it('tolak posisi di luar denah, setengah terisi, meja ganda, atau meja bukan milik outlet — semuanya batal', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant('Kedai Denah Lengkap');
        $outlet = OutletDenah($tenant);
        $satu = Meja::query()->create(['IdOutlet' => $outlet->Id, 'Nama' => '1']);
        $dua = Meja::query()->create(['IdOutlet' => $outlet->Id, 'Nama' => '2']);
        $alamat = "/kelola/outlet/{$outlet->Uuid}/denah-meja";
        BantuanOrganisasi::Masuk($this, $pemilik, $tenant->Id);

        $this->put($alamat, ['Meja' => [['Uuid' => $satu->Uuid, 'PosisiX' => 1001, 'PosisiY' => 5]]])->assertSessionHasErrors('Meja.0.PosisiX');
        $this->put($alamat, ['Meja' => [['Uuid' => $satu->Uuid, 'PosisiX' => 10, 'PosisiY' => null]]])->assertSessionHasErrors('Meja');
        $this->put($alamat, ['Meja' => [
            ['Uuid' => $satu->Uuid, 'PosisiX' => 10, 'PosisiY' => 10],
            ['Uuid' => $satu->Uuid, 'PosisiX' => 20, 'PosisiY' => 20],
        ]])->assertSessionHasErrors('Meja');
        $this->put($alamat, ['Meja' => [
            ['Uuid' => $satu->Uuid, 'PosisiX' => 10, 'PosisiY' => 10],
            ['Uuid' => '01J00000000000000000000000', 'PosisiX' => 20, 'PosisiY' => 20],
        ]])->assertSessionHasErrors('Meja');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($satu->refresh()->PosisiX)->toBeNull()->and($dua->refresh()->PosisiX)->toBeNull();
    });

    it('izin outlet.kelola dan isolasi tenant & outlet', function (): void {
        ['Tenant' => $a] = BantuanOrganisasi::BuatTenant('Kedai Denah A');
        $outletA = OutletDenah($a);
        $meja = Meja::query()->create(['IdOutlet' => $outletA->Id, 'Nama' => '1']);
        $isi = ['Meja' => [['Uuid' => $meja->Uuid, 'PosisiX' => 50, 'PosisiY' => 50]]];

        $kasir = BantuanOrganisasi::TambahAnggota($a->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $a->Id);
        $this->put("/kelola/outlet/{$outletA->Uuid}/denah-meja", $isi)->assertForbidden();

        ['Tenant' => $b, 'Pemilik' => $pemilikB] = BantuanOrganisasi::BuatTenant('Kedai Denah B');
        BantuanOrganisasi::Masuk($this, $pemilikB, $b->Id);
        $this->put("/kelola/outlet/{$outletA->Uuid}/denah-meja", $isi)->assertNotFound();
        $outletB = OutletDenah($b);
        $this->put("/kelola/outlet/{$outletB->Uuid}/denah-meja", $isi)->assertSessionHasErrors('Meja');

        BantuanOrganisasi::AturKonteks($a->Id);
        expect($meja->refresh()->PosisiX)->toBeNull();
    });
});
