<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Model\Pemasok;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * PLT-10 (F31) impor pemasok: dua langkah (periksa tanpa menyimpan → terapkan), judul kolom bersinonim, kode terdaftar
 * dilewati (tidak ditimpa, termasuk pemasok yang sudah dihapus), kode otomatis PMS-0001 bila berkas tanpa kode, baris
 * bermasalah dilaporkan, audit tanpa data kontak/rekening, izin `pembelian.kelola`, isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function BerkasPemasokCsv(string $isi, string $nama = 'pemasok.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nama, $isi);
}

it('impor: periksa tidak menyimpan; terapkan membuat yang baru, melewati yang terdaftar & bermasalah; ulang = 0 baru', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Sembako Berkah Jaya');
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    Pemasok::query()->create(['Kode' => 'SPM', 'Nama' => 'CV Sumber Pangan Lama (dirawat)', 'Catatan' => 'Jangan ditimpa']);

    $csv = implode("\n", [
        'Kode Pemasok;Nama Pemasok;Kontak;No HP;E-mail;NPWP;PKP;Termin;Bank;No Rekening;Atas Nama',
        'ind-01;PT Indofood Distribusi Nusantara;Dewi Lestari;0271-555-0123;sales@indofood.co.id;01.234.567.8-901.000;Ya;30 hari;BCA;1234567890;PT Indofood Distribusi',
        'spm;CV Sumber Pangan Berkas Baru;;;;;;;;;',
        'IND-01;Kode Dobel;;;;;;;;;',
        'bad kode;Kode Salah;;;;;;;;;',
        'TERMIN;Termin Salah;;;;;;400;;;',
        'HPSALAH;HP Salah;;abc;;;;;;;',
        'MAIL;Surel Salah;;;bukan-surel;;;;;;',
        'TNI;Toko Tani Makmur;Joko;081211112222;;;Tidak;Tunai;;;',
        'NONAME;;;;;;;;;;',
    ]);

    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv($csv)])->assertRedirect('/kelola/pembelian/pemasok/impor');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Pemasok::query()->count())->toBe(1);
    $this->get('/kelola/pembelian/pemasok/impor')->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Pembelian/Pemasok/Impor')
        ->where('Hasil.Terapkan', false)
        ->where('Hasil.JumlahBaris', 9)
        ->where('Hasil.Baru', 2)
        ->where('Hasil.SudahAda', 1)
        ->where('Hasil.Bermasalah', 6)
        ->where('Hasil.Masalah', fn ($masalah): bool => collect($masalah)->pluck('Pesan')->all() === [
            'Kode sama dengan baris sebelumnya di berkas ini; baris ini dilewati.',
            'Kode hanya huruf, angka, titik, garis bawah, atau tanda hubung (maksimal 30).',
            'Termin harus angka 0 sampai 365 hari atau "Tunai".',
            'Nomor HP hanya angka, spasi, +, (, ), atau tanda hubung.',
            'Email tidak valid.',
            'Nama kosong.',
        ]));

    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv($csv), 'Terapkan' => '1'])->assertRedirect()->assertSessionHas('Kilat', '2 pemasok baru diimpor.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $indofood = Pemasok::query()->where('Kode', 'IND-01')->sole();
    expect(Pemasok::query()->count())->toBe(3)
        ->and($indofood->Nama)->toBe('PT Indofood Distribusi Nusantara')
        ->and($indofood->NamaKontak)->toBe('Dewi Lestari')
        ->and($indofood->Email)->toBe('sales@indofood.co.id')
        ->and($indofood->Pkp)->toBeTrue()
        ->and($indofood->TerminHari)->toBe(30)
        ->and($indofood->NomorRekening)->toBe('1234567890')
        ->and($indofood->Aktif)->toBeTrue()
        ->and(Pemasok::query()->where('Kode', 'TNI')->sole()->TerminHari)->toBe(0)
        ->and(Pemasok::query()->where('Kode', 'SPM')->sole()->Catatan)->toBe('Jangan ditimpa')
        ->and(Pemasok::query()->where('Kode', 'SPM')->sole()->Nama)->toBe('CV Sumber Pangan Lama (dirawat)');
    $audit = LogAudit::query()->where('Peristiwa', 'pemasok.impor')->sole();
    expect($audit->NilaiBaru)->toMatchArray(['Baru' => 2, 'SudahAda' => 1, 'Bermasalah' => 6])
        ->and(json_encode($audit->NilaiBaru))->not->toContain('1234567890')->not->toContain('0271');

    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv($csv), 'Terapkan' => '1'])->assertSessionHas('Kilat', '0 pemasok baru diimpor.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Pemasok::query()->count())->toBe(3);
});

it('tanpa kolom Kode: kode otomatis berurutan setelah nomor tertinggi (termasuk yang sudah dihapus); nama yang sama dilewati', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Sembako Berkah Jaya');
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    Pemasok::query()->create(['Kode' => 'PMS-0007', 'Nama' => 'Pemasok Lama Dihapus'])->delete();
    Pemasok::query()->create(['Kode' => 'ABC', 'Nama' => 'Toko Beras Pak Haji']);

    $csv = "Nama,Telepon\nToko Beras Pak Haji,0811\ntoko beras pak haji,0822\nCV Minyak Goreng Sejahtera,0833\nUD Gula Manis Jaya,0844";
    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv($csv), 'Terapkan' => '1'])->assertSessionHas('Kilat', '2 pemasok baru diimpor.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    expect(Pemasok::query()->orderBy('Id')->pluck('Nama', 'Kode')->all())->toBe([
        'ABC' => 'Toko Beras Pak Haji',
        'PMS-0008' => 'CV Minyak Goreng Sejahtera',
        'PMS-0009' => 'UD Gula Manis Jaya',
    ]);
    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv($csv), 'Terapkan' => '1'])->assertSessionHas('Kilat', '0 pemasok baru diimpor.');
});

it('impor ditolak: kolom Nama tidak ada, bukan berkas tabel, tanpa berkas; kode pemasok yang dihapus tidak dipakai ulang', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Sembako Berkah Jaya');
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    Pemasok::query()->create(['Kode' => 'DEL', 'Nama' => 'Dihapus'])->delete();

    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv("Kode,Email\nX1,a@b.id")])->assertSessionHasErrors('Berkas');
    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv("Nama\nBudi", 'pemasok.pdf')])->assertSessionHasErrors('Berkas');
    $this->post('/kelola/pembelian/pemasok/impor', [])->assertSessionHasErrors('Berkas');

    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv("Kode,Nama\nDEL,Pemasok Baru Kode Lama"), 'Terapkan' => '1'])->assertSessionHas('Kilat', '0 pemasok baru diimpor.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Pemasok::query()->count())->toBe(0);
});

it('izin pembelian.kelola dan isolasi tenant: kasir ditolak, pemasok tenant lain tidak dianggap terdaftar', function (): void {
    $a = BantuanKatalog::SiapkanTenantProduk('Toko Sembako Berkah Jaya');
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    Pemasok::query()->create(['Kode' => 'SPM', 'Nama' => 'Pemasok Tenant A']);

    $b = BantuanKatalog::SiapkanTenantProduk('Warung Bakso Pak Kumis');
    BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv("Kode,Nama\nSPM,Pemasok Tenant B"), 'Terapkan' => '1'])->assertSessionHas('Kilat', '1 pemasok baru diimpor.');
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
    expect(Pemasok::query()->sole()->Nama)->toBe('Pemasok Tenant B');

    BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Kasir);
    $this->get('/kelola/pembelian/pemasok/impor')->assertForbidden();
    $this->get('/kelola/pembelian/pemasok/impor/templat')->assertForbidden();
    $this->post('/kelola/pembelian/pemasok/impor', ['Berkas' => BerkasPemasokCsv("Nama\nBudi")])->assertForbidden();
});

it('templat bisa diunduh sebagai Excel', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Sembako Berkah Jaya');
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $this->get('/kelola/pembelian/pemasok/impor/templat')->assertOk()->assertHeader('content-disposition');
});
