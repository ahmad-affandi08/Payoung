<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Kasir\Aksi\SiapkanKategoriBiayaBawaan;
use App\Domain\Kasir\Aksi\SimpanKategoriKas;
use App\Domain\Kasir\Data\DataKategoriKas;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Outlet;
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

        // Sepuluh kategori biaya + kategori uang laci: belanja stok (keluar) dan kembalian (masuk), D-256 dan D-257.
        expect($baru)->toBe(count(SiapkanKategoriBiayaBawaan::KATEGORI) + 2)
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

    it('D-256: kategori belanja stok dari uang laci menunjuk akun penampung (aset), bukan beban', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        app(SiapkanKategoriBiayaBawaan::class)->Jalankan();

        $laci = KategoriKas::query()->where('Nama', SiapkanKategoriBiayaBawaan::KATEGORI_BELANJA_LACI)->sole();
        $akun = Akun::query()->whereKey($laci->IdAkun)->sole();
        expect($laci->Jenis)->toBe(JenisKategoriKas::Keluar)
            ->and($akun->Kode)->toBe('1-1490')
            ->and($akun->Jenis->value)->toBe('Aset')
            ->and(app(PenentuAkun::class)->AmbilIdAkun(PeranAkun::KliringBelanjaLaci, null))->toBe($akun->Id);
    });

    it('D-256: kategori kas tidak boleh menunjuk akun persediaan atau akun kendali lain, penampung laci boleh', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penentu = app(PenentuAkun::class);
        $persediaan = Akun::query()->whereKey($penentu->AmbilIdAkun(PeranAkun::PersediaanBarangDagang, null))->sole();
        $simpan = app(SimpanKategoriKas::class);

        expect(fn () => $simpan->Jalankan(new DataKategoriKas('Beli stok langsung', JenisKategoriKas::Keluar, $persediaan->Uuid, 0)))
            ->toThrow(PelanggaranAturanBisnis::class, 'akun kendali');
        expect(KategoriKas::query()->where('Nama', 'Beli stok langsung')->exists())->toBeFalse();

        $penampung = Akun::query()->whereKey($penentu->AmbilIdAkun(PeranAkun::KliringBelanjaLaci, null))->sole();
        $kategori = $simpan->Jalankan(new DataKategoriKas('Uang laci ke pasar', JenisKategoriKas::Keluar, $penampung->Uuid, 0));
        expect($kategori->IdAkun)->toBe($penampung->Id);
    });

    it('D-257: usaha FnB juga mendapat "Belanja bahan dari uang laci", kembalian masuk ke penampung yang sama', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        Outlet::query()->update(['TemplateSektor' => 'FNB-CAF']);

        app(SiapkanKategoriBiayaBawaan::class)->Jalankan();

        $stok = KategoriKas::query()->where('Nama', SiapkanKategoriBiayaBawaan::KATEGORI_BELANJA_LACI)->sole();
        $bahan = KategoriKas::query()->where('Nama', SiapkanKategoriBiayaBawaan::KATEGORI_BELANJA_BAHAN_LACI)->sole();
        $kembalian = KategoriKas::query()->where('Nama', SiapkanKategoriBiayaBawaan::KATEGORI_KEMBALIAN_LACI)->sole();
        expect($bahan->Jenis)->toBe(JenisKategoriKas::Keluar)
            ->and($kembalian->Jenis)->toBe(JenisKategoriKas::Masuk)
            ->and($bahan->IdAkun)->toBe($stok->IdAkun)
            ->and($kembalian->IdAkun)->toBe($stok->IdAkun);
    });

    it('tidak menimpa kategori buatan pengguna dan melewati kode akun yang bentrok', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $beban = Akun::query()->where('Kode', '6-9000')->sole();
        $kustom = KategoriKas::query()->create(['Nama' => 'listrik', 'Jenis' => JenisKategoriKas::Keluar, 'IdAkun' => $beban->Id, 'Aktif' => false]);
        // Kode 6-2300 dipakai akun nonaktif: kategori "Sewa tempat" dilewati, bukan gagal.
        Akun::query()->create(['Kode' => '6-2300', 'Nama' => 'Sewa lama', 'Jenis' => 'Beban', 'SaldoNormal' => 'Debit', 'Sistem' => false, 'Aktif' => false, 'KasBank' => false]);

        $baru = app(SiapkanKategoriBiayaBawaan::class)->Jalankan();

        expect($baru)->toBe(count(SiapkanKategoriBiayaBawaan::KATEGORI))
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
            ->and(LogAudit::query()->where('Peristiwa', 'kas.kategori.simpan')->count())->toBe(count(SiapkanKategoriBiayaBawaan::KATEGORI) + 2)
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
