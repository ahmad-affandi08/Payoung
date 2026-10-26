<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusOrganisasi;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Referensi\Enum\ZonaWaktu;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/**
 * @param  array<string, mixed>  $ubah
 * @return array<string, mixed>
 */
function IsianOutletUji(int $idTenant, array $ubah = []): array
{
    BantuanOrganisasi::AturKonteks($idTenant);

    return [
        'Nama' => 'Kopi Nusantara Cabang Laweyan Solo Barat Dekat Stasiun Purwosari',
        'Kode' => 'SLO1',
        'Merek' => Merek::query()->value('Uuid'),
        'Alamat' => 'Jl. Slamet Riyadi No. 427, Laweyan',
        'KodeKota' => '33.72',
        'ZonaWaktu' => 'WIB',
        'JamTutupBuku' => '04:00',
        'Pkp' => true,
        'Nitku' => '0012345678901234000001',
        'PungutPbjt' => true,
        ...$ubah,
    ];
}

/**
 * @return array{Tenant: Tenant, Pemilik: Pengguna, Tes: TestCase}
 */
function MasukPemilikOutlet(TestCase $tes): array
{
    ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanOrganisasi::BuatTenant();
    BantuanFitur::Beri($tenant->Id, 'stok.multi-gudang');

    return ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Tes' => BantuanOrganisasi::Masuk($tes, $pemilik, $tenant->Id)];
}

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanOrganisasi::BuatKota();
    BantuanOrganisasi::BuatKota('73.71', 'Kota Makassar', ZonaWaktu::Wita);
    Mail::fake();
});

describe('Outlet (F-02 langkah 1)', function (): void {
    it('menambah outlet: zona waktu dari kota, profil pajak tersimpan, lokasi stok Toko dibuat (BR-02.4), tercatat di log audit', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik, 'Tes' => $tes] = MasukPemilikOutlet($this);

        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id, ['Kode' => 'mks1', 'KodeKota' => '73.71', 'ZonaWaktu' => 'WIB']))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $outlet = Outlet::query()->where('Kode', 'MKS1')->sole();
        expect($outlet->ZonaWaktu)->toBe('Asia/Makassar')
            ->and($outlet->ProfilPajak)->toBe(['Pkp' => true, 'Nitku' => '0012345678901234000001', 'PungutPbjt' => true])
            ->and($outlet->Status)->toBe(StatusOrganisasi::Aktif);
        $gudang = Gudang::query()->where('IdOutlet', $outlet->Id)->sole();
        expect($gudang->Jenis)->toBe(JenisGudang::Toko)->and($gudang->Kode)->toBe('MKS1');

        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'outlet.buat', 'IdObjek' => $outlet->Id, 'IdPengguna' => $pemilik->Id]);
        $this->assertDatabaseHas('LogAudit', ['IdTenant' => $tenant->Id, 'Peristiwa' => 'gudang.buat', 'IdObjek' => $gudang->Id]);
    });

    it('tanpa kota, zona waktu dipilih manual (WIT → Asia/Jayapura)', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);

        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id, ['KodeKota' => null, 'ZonaWaktu' => 'WIT', 'Pkp' => false]))
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $outlet = Outlet::query()->where('Kode', 'SLO1')->sole();
        expect($outlet->ZonaWaktu)->toBe('Asia/Jayapura')->and($outlet->ProfilPajak)->toBe(['Pkp' => false, 'Nitku' => null, 'PungutPbjt' => true]);
    });

    it('D-177: telepon outlet tersimpan rapi, kunci Telepon kosong mengosongkan, tanpa kunci tidak mengubah, format salah ditolak', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);

        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id, ['Telepon' => '  0271-123456 ']))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $outlet = Outlet::query()->where('Kode', 'SLO1')->sole();
        expect($outlet->Telepon)->toBe('0271-123456');

        // Klien lama / panduan awal tanpa kunci Telepon: nilai lama dipertahankan.
        $isian = IsianOutletUji($tenant->Id, ['Alamat' => 'Jl. Baru No. 1']);
        $tes->put("/kelola/outlet/{$outlet->Uuid}", $isian)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($outlet->refresh()->Telepon)->toBe('0271-123456')->and($outlet->Alamat)->toBe('Jl. Baru No. 1');

        $tes->get("/kelola/outlet/{$outlet->Uuid}")->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->where('Outlet.Telepon', '0271-123456'));

        $tes->put("/kelola/outlet/{$outlet->Uuid}", [...$isian, 'Telepon' => 'telp 0812 abc'])->assertSessionHasErrors('Telepon');
        $tes->put("/kelola/outlet/{$outlet->Uuid}", [...$isian, 'Telepon' => '12'])->assertSessionHasErrors('Telepon');

        // Kunci ada tetapi kosong: dikosongkan.
        $tes->put("/kelola/outlet/{$outlet->Uuid}", [...$isian, 'Telepon' => ''])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect($outlet->refresh()->Telepon)->toBeNull();
    });

    it('D-177: pratinjau pengaturan struk memakai alamat & telepon outlet yang sedang diatur', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();
        $utama->update(['Alamat' => 'Jl. Solo Purwodadi KM 32', 'Telepon' => '08213883816']);

        $tes->get('/kelola/kasir/struk')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Kasir/Struk')
            ->where('Profil.NamaOutlet', $utama->Nama)
            ->where('Profil.AlamatOutlet', 'Jl. Solo Purwodadi KM 32')
            ->where('Profil.TeleponOutlet', '08213883816')
            ->where('Profil.UuidOutletProfil', $utama->Uuid));
    });

    it('memvalidasi isian: kode 3–5 karakter, kota harus dari referensi, jam tutup buku JJ:MM, NITKU 22 angka', function (array $ubah, string $bidang): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);

        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id, $ubah))->assertSessionHasErrors($bidang);
    })->with([
        'kode terlalu pendek' => [['Kode' => 'AB'], 'Kode'],
        'kode terlalu panjang' => [['Kode' => 'SOLOBARU'], 'Kode'],
        'kode diawali angka' => [['Kode' => '1SLO'], 'Kode'],
        'kota tak dikenal' => [['KodeKota' => '99.99'], 'KodeKota'],
        'jam salah' => [['JamTutupBuku' => '25:00'], 'JamTutupBuku'],
        'nitku salah' => [['Nitku' => '12345'], 'Nitku'],
    ]);

    it('BR-02.2: kode unik per tenant tanpa membedakan huruf, termasuk outlet arsip. Tenant lain boleh memakai kode sama', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id))->assertSessionHasNoErrors();

        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id, ['Kode' => 'slo1', 'Nama' => 'Cabang Lain']))->assertSessionHasErrors('Kode');
        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id, ['Kode' => Outlet::query()->orderBy('Id')->value('Kode')]))->assertSessionHasErrors('Kode');

        ['Tenant' => $lain, 'Pemilik' => $pemilikLain] = BantuanOrganisasi::BuatTenant('Toko Lain');
        BantuanOrganisasi::Masuk($this, $pemilikLain, $lain->Id)->post('/kelola/outlet', IsianOutletUji($lain->Id))->assertSessionHasNoErrors();
    });

    it('BR-02.2: kode tidak bisa diubah setelah outlet bertransaksi (KodeDikunciPada), kolom lain tetap bisa', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();

        $tes->put("/kelola/outlet/{$utama->Uuid}", IsianOutletUji($tenant->Id, ['Kode' => 'PUSAT', 'Nama' => 'Outlet Pusat']))->assertSessionHasNoErrors();
        expect($utama->refresh()->Kode)->toBe('PUSAT');
        $this->assertDatabaseHas('LogAudit', ['Peristiwa' => 'outlet.ubah', 'IdObjek' => $utama->Id]);

        $utama->forceFill(['KodeDikunciPada' => now()])->save();
        $tes->put("/kelola/outlet/{$utama->Uuid}", IsianOutletUji($tenant->Id, ['Kode' => 'BARU', 'Nama' => 'Outlet Pusat']))->assertSessionHasErrors('Kode');
        $tes->put("/kelola/outlet/{$utama->Uuid}", IsianOutletUji($tenant->Id, ['Kode' => 'PUSAT', 'Nama' => 'Outlet Pusat Solo']))->assertSessionHasNoErrors();
        expect($utama->refresh()->only(['Kode', 'Nama']))->toBe(['Kode' => 'PUSAT', 'Nama' => 'Outlet Pusat Solo']);
    });

    it('regresi F-01: mengubah outlet tidak menghapus kunci ProfilPajak dari panduan awal (service charge, harga termasuk pajak)', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();
        $utama->forceFill(['ProfilPajak' => ['Pkp' => false, 'Nitku' => null, 'PungutPbjt' => true, 'BiayaLayanan' => ['Aktif' => true, 'Persen' => '5.00'], 'HargaTermasukPajak' => true]])->save();

        $tes->put("/kelola/outlet/{$utama->Uuid}", IsianOutletUji($tenant->Id, ['Kode' => 'KNS', 'Pkp' => true, 'PungutPbjt' => true]))->assertSessionHasNoErrors();

        $profil = $utama->refresh()->ProfilPajak;
        expect($profil['Pkp'])->toBeTrue()
            ->and($profil['Nitku'])->toBe('0012345678901234000001')
            ->and($profil['BiayaLayanan'])->toEqual(['Aktif' => true, 'Persen' => '5.00'])
            ->and($profil['HargaTermasukPajak'])->toBeTrue();
    });

    it('arsip: outlet terakhir tidak bisa diarsipkan. Outlet arsip tetap ada dan bisa dipulihkan', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();

        $tes->post("/kelola/outlet/{$utama->Uuid}/arsipkan")->assertSessionHasErrors('Umum');

        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id))->assertSessionHasNoErrors();
        $tes->post("/kelola/outlet/{$utama->Uuid}/arsipkan")->assertSessionHasNoErrors();
        expect($utama->refresh()->Status)->toBe(StatusOrganisasi::Diarsipkan)->and($utama->DiarsipkanPada)->not->toBeNull();

        $tes->post("/kelola/outlet/{$utama->Uuid}/pulihkan")->assertSessionHasNoErrors();
        expect($utama->refresh()->Status)->toBe(StatusOrganisasi::Aktif);
        $this->assertDatabaseHas('LogAudit', ['Peristiwa' => 'outlet.arsipkan', 'IdObjek' => $utama->Id]);
        $this->assertDatabaseHas('LogAudit', ['Peristiwa' => 'outlet.pulihkan', 'IdObjek' => $utama->Id]);
    });

    it('daftar & detail menampilkan outlet, merek, kota, dan batas paket', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);

        $tes->get('/kelola/outlet')->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Kelola/Outlet/Daftar')
            ->has('Outlet', 1)
            ->where('Outlet.0.Kode', 'KNS')
            ->where('Outlet.0.ZonaWaktu', 'WIB')
            ->has('Merek', 1)
            ->has('Kota', 2)
            ->where('BatasOutlet', ['Batas' => 3, 'Terpakai' => 1]));

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();
        $tes->get("/kelola/outlet/{$utama->Uuid}")->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Kelola/Outlet/Detail')
            ->where('Outlet.Kode', 'KNS')
            ->where('Outlet.KodeTerkunci', false)
            ->has('Gudang', 1)
            ->has('JenisGudang', count(JenisGudang::cases())));
    });

    it('halaman tambah outlet (halaman penuh): opsi merek, kota, batas paket, tanpa outlet.kelola 403. Simpan ke detail', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);

        $tes->get('/kelola/outlet/buat')->assertOk()->assertInertia(fn (AssertableInertia $halaman) => $halaman
            ->component('Kelola/Outlet/Buat')
            ->has('Merek', 1)
            ->has('Merek.0', fn (AssertableInertia $merek) => $merek->has('Nilai')->has('Label'))
            ->has('Kota', 2)
            ->where('BatasOutlet', ['Batas' => 3, 'Terpakai' => 1]));

        $respons = $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $respons->assertRedirect('/kelola/outlet/'.Outlet::query()->where('Kode', 'SLO1')->sole()->Uuid);

        $manajer = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::ManajerOutlet);
        BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id)->get('/kelola/outlet/buat')->assertForbidden();
    });

    it('anggota yang hanya ditugaskan ke satu outlet tidak melihat dan tidak bisa membuka outlet lain', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->where('Kode', 'KNS')->sole();
        $solo = Outlet::query()->where('Kode', 'SLO1')->sole();
        $manajer = BantuanOrganisasi::TambahAnggota($tenant->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $manajer->Id, 'IdPeran' => BantuanOrganisasi::Peran($tenant->Id, PeranTenantBawaan::ManajerOutlet)->Id]);

        BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id)->get('/kelola/outlet')
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->has('Outlet', 1)->where('Outlet.0.Kode', 'SLO1'));
        BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id)->get("/kelola/outlet/{$utama->Uuid}")->assertNotFound();
        BantuanOrganisasi::Masuk($this, $manajer, $tenant->Id)->get("/kelola/outlet/{$solo->Uuid}")->assertOk();
    });
});

describe('Lokasi stok (F-02 langkah 2, BR-02.4)', function (): void {
    it('menambah Dapur & Gudang Belakang. Kode gudang unik per tenant', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();

        $tes->post("/kelola/outlet/{$utama->Uuid}/gudang", ['Nama' => 'Dapur', 'Kode' => 'kns-dpr', 'Jenis' => 'Dapur'])->assertSessionHasNoErrors();
        $tes->post("/kelola/outlet/{$utama->Uuid}/gudang", ['Nama' => 'Gudang Belakang', 'Kode' => 'KNS-GB', 'Jenis' => 'Gudang'])->assertSessionHasNoErrors();
        $tes->post("/kelola/outlet/{$utama->Uuid}/gudang", ['Nama' => 'Dapur 2', 'Kode' => 'KNS-DPR', 'Jenis' => 'Dapur'])->assertSessionHasErrors('Kode');
        $tes->post("/kelola/outlet/{$utama->Uuid}/gudang", ['Nama' => 'Aneh', 'Kode' => 'KNS-X', 'Jenis' => 'Kulkas'])->assertSessionHasErrors('Jenis');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Gudang::query()->where('IdOutlet', $utama->Id)->pluck('Kode')->sort()->values()->all())->toBe(['KNS', 'KNS-DPR', 'KNS-GB']);
    });

    it('lokasi stok jual terakhir tidak bisa diarsipkan atau diubah menjadi Rusak. Setelah ada lokasi lain boleh', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->sole();
        $toko = Gudang::query()->sole();

        $tes->post("/kelola/outlet/{$utama->Uuid}/gudang/{$toko->Uuid}/arsipkan")->assertSessionHasErrors('Umum');
        $tes->put("/kelola/outlet/{$utama->Uuid}/gudang/{$toko->Uuid}", ['Nama' => 'Rusak', 'Kode' => 'KNS', 'Jenis' => 'Rusak'])->assertSessionHasErrors('Jenis');

        $tes->post("/kelola/outlet/{$utama->Uuid}/gudang", ['Nama' => 'Gudang Belakang', 'Kode' => 'KNS-GB', 'Jenis' => 'Gudang'])->assertSessionHasNoErrors();
        $tes->post("/kelola/outlet/{$utama->Uuid}/gudang/{$toko->Uuid}/arsipkan")->assertSessionHasNoErrors();
        expect($toko->refresh()->Status)->toBe(StatusOrganisasi::Diarsipkan);
        $tes->post("/kelola/outlet/{$utama->Uuid}/gudang/{$toko->Uuid}/pulihkan")->assertSessionHasNoErrors();
        expect($toko->refresh()->Status)->toBe(StatusOrganisasi::Aktif);
    });

    it('gudang milik outlet lain di tenant yang sama tidak bisa diubah lewat URL outlet ini (404)', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        $tes->post('/kelola/outlet', IsianOutletUji($tenant->Id))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $utama = Outlet::query()->where('Kode', 'KNS')->sole();
        $gudangSolo = Gudang::query()->where('Kode', 'SLO1')->sole();

        $tes->put("/kelola/outlet/{$utama->Uuid}/gudang/{$gudangSolo->Uuid}", ['Nama' => 'Curian', 'Kode' => 'SLO1', 'Jenis' => 'Toko'])->assertNotFound();
    });
});

describe('Merek', function (): void {
    it('menambah, mengganti nama, dan menghapus merek yang belum dipakai. Merek dipakai atau merek terakhir tidak bisa dihapus', function (): void {
        ['Tenant' => $tenant, 'Tes' => $tes] = MasukPemilikOutlet($this);
        BantuanOrganisasi::AturKonteks($tenant->Id);
        $bawaan = Merek::query()->sole();

        $tes->delete("/kelola/merek/{$bawaan->Uuid}")->assertSessionHasErrors('Umum');
        $tes->post('/kelola/merek', ['Nama' => 'Roti Nusantara'])->assertSessionHasNoErrors();
        $tes->post('/kelola/merek', ['Nama' => 'Roti Nusantara'])->assertSessionHasErrors('Nama');

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $roti = Merek::query()->where('Nama', 'Roti Nusantara')->sole();
        $tes->put("/kelola/merek/{$roti->Uuid}", ['Nama' => 'Roti & Kue Nusantara'])->assertSessionHasNoErrors();
        $tes->delete("/kelola/merek/{$bawaan->Uuid}")->assertSessionHasErrors('Umum');
        $tes->delete("/kelola/merek/{$roti->Uuid}")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        expect(Merek::query()->pluck('Nama')->all())->toBe([$bawaan->Nama]);
        $this->assertDatabaseHas('LogAudit', ['Peristiwa' => 'merek.hapus', 'IdObjek' => $roti->Id]);
    });
});
