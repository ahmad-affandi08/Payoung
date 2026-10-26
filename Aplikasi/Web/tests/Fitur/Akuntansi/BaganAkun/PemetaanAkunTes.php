<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\SaldoNormal;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-13a pemetaan akun (PRD "Rincian F-13a", BR-P03.3)', function (): void {
    it('daftar: satu baris per peran tingkat tenant + override outlet. Status sesuai/tipe salah. Opsi akun aktif', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        $kasSolo = Akun::query()->create(['Kode' => '1-1110', 'Nama' => 'Kas Outlet Solo Baru', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit, 'KasBank' => true]);
        PemetaanAkun::query()->create(['Kunci' => 'KasOutlet', 'IdAkun' => $kasSolo->Id, 'IdOutlet' => $solo->Id]);
        // Pemetaan salah tipe (data lama) ditandai, tidak disembunyikan.
        PemetaanAkun::query()->where('Kunci', 'Hpp')->whereNull('IdOutlet')->update(['IdAkun' => Akun::query()->where('Kode', '6-9000')->value('Id')]);

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan)->get('/kelola/akuntansi/pemetaan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->component('Kelola/Akuntansi/Pemetaan/Daftar')
                ->has('Pemetaan', count(PeranAkun::cases()) + 1)
                ->where('Pemetaan.0.Kunci', 'KasOutlet')
                ->where('Pemetaan.0.UuidOutlet', null)
                ->where('Pemetaan.0.KodeAkun', '1-1100')
                ->where('Pemetaan.0.Status', 'Sesuai')
                ->where('Pemetaan.1.NamaOutlet', 'Cabang Solo Baru')
                ->where('Pemetaan.1.KodeAkun', '1-1110')
                ->where('Pemetaan', fn ($daftar): bool => collect($daftar)->firstWhere('Kunci', 'Hpp')['Status'] === 'TipeSalah')
                ->where('Izin', ['Kelola' => true, 'UbahSemuaOutlet' => true]));
    });

    it('ubah pemetaan tenant & override outlet: tipe/kontra divalidasi, diaudit, berlaku untuk jurnal berikutnya saja', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        $lama = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1, idOutlet: $solo->Id));
        $gudangBaru = Akun::query()->create(['Kode' => '1-1505', 'Nama' => 'Persediaan Gudang Solo', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit]);
        $diskonBaru = Akun::query()->create(['Kode' => '4-1150', 'Nama' => 'Potongan Grosir', 'Jenis' => TipeAkun::Pendapatan, 'SaldoNormal' => SaldoNormal::Debit]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $beban = Akun::query()->where('Kode', '6-9000')->sole();
        $penjualan = Akun::query()->where('Kode', '4-1000')->sole();
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'PersediaanBarangDagang', 'UuidAkun' => $beban->Uuid])->assertSessionHasErrors('UuidAkun');
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'DiskonPenjualan', 'UuidAkun' => $penjualan->Uuid])->assertSessionHasErrors('UuidAkun');
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'PeranAsing', 'UuidAkun' => $penjualan->Uuid])->assertSessionHasErrors('Kunci');
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'DiskonPenjualan', 'UuidAkun' => $diskonBaru->Uuid])->assertRedirect('/kelola/akuntansi/pemetaan');
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'PersediaanBarangDagang', 'UuidOutlet' => $solo->Uuid, 'UuidAkun' => $gudangBaru->Uuid])->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penentu = app(PenentuAkun::class);
        $baru = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 2, idOutlet: $solo->Id));
        $utama = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 3, idOutlet: $t['Outlet']->Id));

        expect($penentu->AmbilIdAkun(PeranAkun::DiskonPenjualan, null))->toBe($diskonBaru->Id)
            ->and($penentu->AmbilIdAkun(PeranAkun::PersediaanBarangDagang, $solo->Id))->toBe($gudangBaru->Id)
            ->and($penentu->AmbilIdAkun(PeranAkun::PersediaanBarangDagang, $t['Outlet']->Id))->toBe(BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang))
            // Jurnal lama tidak berubah; jurnal berikutnya memakai pemetaan baru.
            ->and(JurnalDetail::query()->where('IdJurnal', $lama->idJurnal)->where('Debit', '>', 0)->value('IdAkun'))->toBe(BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang))
            ->and(JurnalDetail::query()->where('IdJurnal', $baru->idJurnal)->where('Debit', '>', 0)->value('IdAkun'))->toBe($gudangBaru->Id)
            ->and(JurnalDetail::query()->where('IdJurnal', $utama->idJurnal)->where('Debit', '>', 0)->value('IdAkun'))->toBe(BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang));

        $audit = LogAudit::query()->where('Peristiwa', 'akun.pemetaan.ubah')->orderBy('Id')->get();
        expect($audit)->toHaveCount(2)
            ->and($audit[0]->NilaiLama)->toMatchArray(['Kunci' => 'DiskonPenjualan', 'KodeAkun' => '4-1100'])
            ->and($audit[0]->NilaiBaru)->toMatchArray(['Kunci' => 'DiskonPenjualan', 'KodeAkun' => '4-1150'])
            ->and($audit[1]->NilaiBaru)->toMatchArray(['Kunci' => 'PersediaanBarangDagang', 'IdOutlet' => $solo->Id, 'KodeAkun' => '1-1505']);

        // Hapus override outlet: kembali ke pemetaan umum.
        $this->delete('/kelola/akuntansi/pemetaan', ['Kunci' => 'PersediaanBarangDagang', 'UuidOutlet' => $solo->Uuid])->assertRedirect();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($penentu->AmbilIdAkun(PeranAkun::PersediaanBarangDagang, $solo->Id))->toBe(BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang))
            ->and(LogAudit::query()->where('Peristiwa', 'akun.pemetaan.hapus')->count())->toBe(1);
    });

    it('peran kas/bank menandai akunnya sebagai akun kas/bank. Akun nonaktif tidak bisa dipetakan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $bri = Akun::query()->create(['Kode' => '1-1210', 'Nama' => 'Bank BRI', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit]);
        $mati = Akun::query()->create(['Kode' => '1-1220', 'Nama' => 'Bank Lama', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit, 'Aktif' => false]);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'Bank', 'UuidAkun' => $mati->Uuid])->assertSessionHasErrors('UuidAkun');
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'Bank', 'UuidAkun' => $bri->Uuid])->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect($bri->fresh()?->KasBank)->toBeTrue();
    });

    it('batas outlet: pengguna per outlet hanya mengatur override outlet aksesnya, tidak bisa mengubah pemetaan umum', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        $gudang = Akun::query()->create(['Kode' => '1-1505', 'Nama' => 'Persediaan Gudang Solo', 'Jenis' => TipeAkun::Aset, 'SaldoNormal' => SaldoNormal::Debit]);
        $akuntan = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Akuntan, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $akuntan->Id, 'IdPeran' => BantuanOrganisasi::Peran($t['Tenant']->Id, PeranTenantBawaan::Akuntan)->Id]);
        BantuanOrganisasi::Masuk($this, $akuntan, $t['Tenant']->Id);

        $this->get('/kelola/akuntansi/pemetaan')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('OpsiOutlet', [['Uuid' => $solo->Uuid, 'Nama' => 'Cabang Solo Baru']])
            ->where('Izin.UbahSemuaOutlet', false));
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'PersediaanBarangDagang', 'UuidAkun' => $gudang->Uuid])->assertForbidden();
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'PersediaanBarangDagang', 'UuidOutlet' => $t['Outlet']->Uuid, 'UuidAkun' => $gudang->Uuid])->assertNotFound();
        $this->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'PersediaanBarangDagang', 'UuidOutlet' => $solo->Uuid, 'UuidAkun' => $gudang->Uuid])->assertRedirect();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(PemetaanAkun::query()->where('Kunci', 'PersediaanBarangDagang')->count())->toBe(2);
    });

    it('izin & isolasi: Kasir 403; Manajer Outlet tidak bisa mengubah. Akun tenant lain ditolak', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
        $bankA = Akun::query()->where('Kode', '1-1200')->sole();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/akuntansi/pemetaan')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)
            ->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'Bank', 'UuidAkun' => $bankA->Uuid])->assertForbidden();

        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan)
            ->put('/kelola/akuntansi/pemetaan', ['Kunci' => 'Bank', 'UuidAkun' => $bankA->Uuid])->assertSessionHasErrors('UuidAkun');

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(Akun::query()->whereKey(BantuanJurnal::IdAkunPeran(PeranAkun::Bank))->value('Kode'))->toBe('1-1200')
            ->and(BantuanJurnal::IdAkunPeran(PeranAkun::Bank))->not->toBe($bankA->Id);
    });
});
