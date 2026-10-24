<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-16a (v3.36) impor & ekspor pelanggan: impor dua langkah (periksa tanpa menyimpan → terapkan), judul kolom
 * bersinonim, nomor HP terdaftar dilewati (tidak ditimpa), nomor dobel di berkas, baris bermasalah dilaporkan,
 * audit tanpa data pribadi; ekspor mengikuti saringan & bisa diimpor ulang; izin `pelanggan.kelola`.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function BerkasPelangganCsv(string $isi, string $nama = 'pelanggan.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nama, $isi);
}

it('impor: periksa tidak menyimpan; terapkan membuat yang baru, melewati yang terdaftar & bermasalah; ulang = 0 baru', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Kue Bu Sri Lestari');
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    Pelanggan::query()->create(['Nama' => 'Ani Lama (dirawat kasir)', 'NoHp' => '6281234567890', 'Catatan' => 'Jangan ditimpa']);

    $csv = implode("\n", [
        'Nama Pelanggan;No HP;E-mail;Tanggal Lahir;Tag;Catatan;Boleh Promo;Poin',
        'Siti Rahmawati Kusumawardhani;0812-1111-2222;siti@contoh.id;17/08/1990;"Member; Arisan";Suka kopi;Ya;999',
        'Ani Berkas Lama;+62 812 3456 7890;;;;Ditimpa?;;',
        'Siti Dobel;081211112222;;;;;;',
        'HP Salah;0812;;;;;;',
        'Tanggal Salah;0813-5555-6666;;31/02/1990;;;;',
        ';0813-7777-8888;;;;;;',
        'Budi Santoso;6285711223344;BUDI@CONTOH.ID;1985-01-30;;;Tidak;',
    ]);

    $this->post('/kelola/pelanggan/impor', ['Berkas' => BerkasPelangganCsv($csv)])->assertRedirect('/kelola/pelanggan/impor');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Pelanggan::query()->count())->toBe(1);
    $this->get('/kelola/pelanggan/impor')->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Pelanggan/Impor')
        ->where('Hasil.Terapkan', false)
        ->where('Hasil.JumlahBaris', 7)
        ->where('Hasil.Baru', 2)
        ->where('Hasil.SudahAda', 1)
        ->where('Hasil.Bermasalah', 4)
        ->where('Hasil.Masalah.0.Baris', 4)
        ->where('Hasil.Masalah.1.Pesan', 'Nomor HP tidak valid.')
        ->where('Hasil.Masalah.3.Pesan', 'Nama kosong.'));

    $this->post('/kelola/pelanggan/impor', ['Berkas' => BerkasPelangganCsv($csv), 'Terapkan' => '1'])->assertRedirect()->assertSessionHas('Kilat', '2 pelanggan baru diimpor.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $siti = Pelanggan::query()->where('NoHp', '6281211112222')->sole();
    expect(Pelanggan::query()->count())->toBe(3)
        ->and($siti->Nama)->toBe('Siti Rahmawati Kusumawardhani')
        ->and($siti->Email)->toBe('siti@contoh.id')
        ->and($siti->TanggalLahir?->toDateString())->toBe('1990-08-17')
        ->and($siti->Tag)->toBe(['Member', 'Arisan'])
        ->and($siti->Catatan)->toBe('Suka kopi')
        ->and($siti->SetujuPemasaran)->toBeTrue()
        ->and($siti->Status)->toBe(StatusPelanggan::Aktif)
        ->and(Pelanggan::query()->where('NoHp', '6285711223344')->sole()->Email)->toBe('budi@contoh.id')
        ->and(Pelanggan::query()->where('NoHp', '6281234567890')->sole()->Catatan)->toBe('Jangan ditimpa');
    $audit = LogAudit::query()->where('Peristiwa', 'pelanggan.impor')->sole();
    expect($audit->NilaiBaru)->toMatchArray(['Baru' => 2, 'SudahAda' => 1, 'Bermasalah' => 4])
        ->and(json_encode($audit->NilaiBaru))->not->toContain('0812');

    $this->post('/kelola/pelanggan/impor', ['Berkas' => BerkasPelangganCsv($csv), 'Terapkan' => '1'])->assertSessionHas('Kilat', '0 pelanggan baru diimpor.');
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Pelanggan::query()->count())->toBe(3);
});

it('impor ditolak: kolom wajib tidak ada, bukan berkas tabel', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Kue Bu Sri');
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

    $this->post('/kelola/pelanggan/impor', ['Berkas' => BerkasPelangganCsv("Nama,Email\nSiti,siti@contoh.id")])->assertSessionHasErrors('Berkas');
    $this->post('/kelola/pelanggan/impor', ['Berkas' => BerkasPelangganCsv("Nama,NoHp\nSiti,0812", 'pelanggan.pdf')])->assertSessionHasErrors('Berkas');
    $this->post('/kelola/pelanggan/impor', [])->assertSessionHasErrors('Berkas');
});

it('ekspor: ikut filter, kolom bisa diimpor ulang (semua sudah ada), tercatat di audit; izin pelanggan.kelola', function (): void {
    $t = BantuanKatalog::SiapkanTenantProduk('Toko Kue Bu Sri');
    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    Pelanggan::query()->create(['Nama' => 'Ani Wijaya', 'NoHp' => '6281234567890', 'Tag' => ['Member'], 'TanggalLahir' => '1990-05-17']);
    Pelanggan::query()->create(['Nama' => '=HYPERLINK("x")', 'NoHp' => '6281311112222']);
    Pelanggan::query()->create(['Nama' => 'Citra Arsip', 'NoHp' => '6281399998888', 'Status' => StatusPelanggan::Diarsipkan]);

    $isi = $this->get('/kelola/pelanggan/ekspor?format=csv&saring[Status]=Aktif')->assertOk()->streamedContent();
    $baris = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($isi, "\xEF\xBB\xBF")))));
    expect($baris)->toHaveCount(3)
        ->and($baris[0])->toStartWith('Nama,NoHp,Email,TanggalLahir,Alamat,Tag,Catatan,SetujuPemasaran,Tier,Poin,SaldoDeposit,Status')
        ->and($isi)->toContain('Ani Wijaya')->toContain('1990-05-17')->toContain("'=HYPERLINK")
        ->and($isi)->not->toContain('Citra Arsip');
    expect(LogAudit::query()->where('Peristiwa', 'pelanggan.ekspor')->count())->toBe(1);

    $this->post('/kelola/pelanggan/impor', ['Berkas' => BerkasPelangganCsv($isi), 'Terapkan' => '1'])->assertSessionHas('Kilat', '0 pelanggan baru diimpor.');
    $this->get('/kelola/pelanggan/impor')->assertInertia(fn (AssertableInertia $h) => $h->where('Hasil.SudahAda', 2)->where('Hasil.Bermasalah', 0));

    BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Supervisor);
    $this->get('/kelola/pelanggan/ekspor')->assertForbidden();
    $this->get('/kelola/pelanggan/impor')->assertForbidden();
    $this->post('/kelola/pelanggan/impor', ['Berkas' => BerkasPelangganCsv("Nama,NoHp\nSiti,081211112222")])->assertForbidden();
});
