<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Aksi\SiapkanKategoriBiayaBawaan;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('D-106 kategori biaya bawaan', function (): void {
    it('menambah kategori keluar umum dengan akun beban per jenis biaya, aditif dan idempoten', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $sebelum = KategoriKas::query()->count();

        $baru = app(SiapkanKategoriBiayaBawaan::class)->Jalankan();

        expect($baru)->toBe(count(SiapkanKategoriBiayaBawaan::KATEGORI))
            ->and(KategoriKas::query()->count())->toBe($sebelum + $baru)
            ->and(KategoriKas::query()->where('Jenis', JenisKategoriKas::Keluar->value)->where('Nama', 'Listrik')->exists())->toBeTrue()
            ->and(KategoriKas::query()->where('Nama', 'Beli es batu & galon')->exists())->toBeTrue();

        $listrik = KategoriKas::query()->where('Nama', 'Listrik')->sole();
        $air = KategoriKas::query()->where('Nama', 'Air')->sole();
        $akun = Akun::query()->whereKey($listrik->IdAkun)->sole();
        expect($akun->Kode)->toBe('6-2100')
            ->and($air->IdAkun)->toBe($listrik->IdAkun)
            ->and($akun->Jenis->value)->toBe('Beban')
            ->and(Akun::query()->where('Kode', '6-2100')->count())->toBe(1);

        // Kedua: tidak ada yang ditambahkan, akun tidak ganda.
        $akunSebelum = Akun::query()->count();
        expect(app(SiapkanKategoriBiayaBawaan::class)->Jalankan())->toBe(0)
            ->and(Akun::query()->count())->toBe($akunSebelum)
            ->and(KategoriKas::query()->count())->toBe($sebelum + $baru);
    });

    it('tidak menimpa kategori buatan pengguna dan melewati kode akun yang bentrok', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $beban = Akun::query()->where('Kode', '6-9000')->sole();
        $kustom = KategoriKas::query()->create(['Nama' => 'listrik', 'Jenis' => JenisKategoriKas::Keluar, 'IdAkun' => $beban->Id, 'Aktif' => false]);
        // Kode 6-2300 dipakai akun nonaktif: kategori "Sewa tempat" dilewati, bukan gagal.
        Akun::query()->create(['Kode' => '6-2300', 'Nama' => 'Sewa lama', 'Jenis' => 'Beban', 'SaldoNormal' => 'Debit', 'Sistem' => false, 'Aktif' => false, 'KasBank' => false]);

        $baru = app(SiapkanKategoriBiayaBawaan::class)->Jalankan();

        expect($baru)->toBe(count(SiapkanKategoriBiayaBawaan::KATEGORI) - 2)
            ->and(KategoriKas::query()->where('Nama', 'Sewa tempat')->exists())->toBeFalse()
            ->and(KategoriKas::query()->where('Nama', 'Listrik')->pluck('Id')->all())->toBe([$kustom->Id])
            ->and($kustom->fresh()?->IdAkun)->toBe($beban->Id)
            ->and($kustom->fresh()?->Aktif)->toBeFalse();
    });

    it('tombol di halaman Kategori kas: izin akuntansi.kelola, tercatat di audit, pesan jelas', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $this->post('/kelola/kasir/kategori-kas/biaya-umum')->assertRedirect('/kelola/kasir/kategori-kas')->assertSessionHas('Kilat');
        $this->post('/kelola/kasir/kategori-kas/biaya-umum')->assertRedirect('/kelola/kasir/kategori-kas');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(KategoriKas::query()->where('Nama', 'Transport & bensin')->exists())->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'kas.kategori.simpan')->count())->toBe(count(SiapkanKategoriBiayaBawaan::KATEGORI))
            ->and(LogAudit::query()->where('Peristiwa', 'akun.tambah')->count())->toBeGreaterThan(0);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::ManajerOutlet);
        $this->post('/kelola/kasir/kategori-kas/biaya-umum')->assertForbidden();
    });

    it('perintah kasir:siapkan-kategori-biaya menyiapkan semua tenant lama dan aman diulang', function (): void {
        $a = BantuanKasir::Siapkan($this, 'Kopi Senja Solo');
        $b = BantuanKasir::Siapkan($this, 'Warung Bakso Pak Kumis');

        $this->artisan('kasir:siapkan-kategori-biaya')->assertSuccessful();
        $this->artisan('kasir:siapkan-kategori-biaya')->expectsOutputToContain('0 kategori ditambahkan')->assertSuccessful();

        foreach ([$a, $b] as $t) {
            BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
            expect(KategoriKas::query()->where('Nama', 'Listrik')->count())->toBe(1);
        }
    });
});
