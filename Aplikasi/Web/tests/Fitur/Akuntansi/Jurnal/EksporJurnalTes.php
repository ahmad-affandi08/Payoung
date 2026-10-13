<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * FIN-12 (F28): ekspor jurnal per baris untuk software akuntansi lain. Sama dengan daftar jurnal: cari & saringan
 * tabel ikut, pelaku berbatas outlet hanya jurnal yang semua barisnya di outlet aksesnya, tenant lain tidak terlihat.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('mengekspor satu baris per baris jurnal dengan akun, outlet, debit, kredit; cari dan saringan tanggal ikut', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1, idPengguna: $t['Pemilik']->Id, baris: [
        DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('10600000.00'), $t['Outlet']->Id, 'Minyak goreng 2 L, dus'),
        DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('10600000.00'), $t['Outlet']->Id),
    ]));
    $masuk = BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Akuntan);

    $csv = $masuk->get('/kelola/akuntansi/jurnal/ekspor?format=csv')->assertOk()->streamedContent();
    $baris = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv, "\xEF\xBB\xBF")))));

    expect($baris[0])->toBe('Tanggal,"Nomor jurnal",Sumber,"Nomor sumber",Keterangan,"Kode akun","Nama akun",Outlet,Memo,Debit,Kredit')
        ->and($baris)->toHaveCount(3)
        ->and($csv)->toContain('"Minyak goreng 2 L, dus"')
        ->and($csv)->toContain('10600000.00,0.00')
        ->and($csv)->toContain('0.00,10600000.00')
        ->and($csv)->toContain($t['Outlet']->Nama);

    // Cari yang tidak cocok → hanya judul kolom; tanggal di luar rentang → kosong.
    $kosong = $masuk->get('/kelola/akuntansi/jurnal/ekspor?format=csv&cari=tidak-ada-nomor-ini')->assertOk()->streamedContent();
    expect(array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($kosong, "\xEF\xBB\xBF"))))))->toHaveCount(1);
    $diLuar = $masuk->get('/kelola/akuntansi/jurnal/ekspor?format=csv&saring[Tanggal]=2000-01-01..2000-01-31')->assertOk()->streamedContent();
    expect(array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($diLuar, "\xEF\xBB\xBF"))))))->toHaveCount(1);
});

it('pelaku berbatas outlet hanya mengekspor jurnal yang semua barisnya di outlet aksesnya; kasir ditolak', function (): void {
    $t = BantuanPersediaan::SiapkanTenant();
    $solo = BantuanJurnal::BuatOutlet();
    BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1, idPengguna: $t['Pemilik']->Id, baris: [
        DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('1000000.00'), $t['Outlet']->Id),
        DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('1000000.00'), $t['Outlet']->Id),
    ]));
    BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 2, idPengguna: $t['Pemilik']->Id, baris: [
        DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('2000000.00'), $solo->Id),
        DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('2000000.00'), $solo->Id),
    ]));
    $akuntan = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Akuntan, semuaOutlet: false);
    OutletPengguna::query()->create(['IdOutlet' => $solo->Id, 'IdPengguna' => $akuntan->Id, 'IdPeran' => BantuanOrganisasi::Peran($t['Tenant']->Id, PeranTenantBawaan::Akuntan)->Id]);
    BantuanOrganisasi::Masuk($this, $akuntan, $t['Tenant']->Id);

    $csv = $this->get('/kelola/akuntansi/jurnal/ekspor?format=csv')->assertOk()->streamedContent();
    expect($csv)->toContain('2000000.00')->not->toContain('1000000.00');

    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/akuntansi/jurnal/ekspor?format=csv')->assertForbidden();
});

it('tenant lain tidak ikut: ekspor tenant B kosong walau tenant A punya jurnal', function (): void {
    $a = BantuanPersediaan::SiapkanTenant('Toko Sembako Berkah Jaya');
    BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1, idPengguna: $a['Pemilik']->Id, baris: [
        DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('5000000.00'), $a['Outlet']->Id),
        DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('5000000.00'), $a['Outlet']->Id),
    ]));
    $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
    $csv = BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Akuntan)->get('/kelola/akuntansi/jurnal/ekspor?format=csv')->assertOk()->streamedContent();

    expect($csv)->not->toContain('5000000.00');
});
