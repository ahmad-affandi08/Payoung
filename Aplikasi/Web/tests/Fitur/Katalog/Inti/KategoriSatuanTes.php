<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Aksi\PastikanJalurKategori;
use App\Domain\Katalog\Aksi\PastikanSatuan;
use App\Domain\Katalog\Enum\EntitasKatalog;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\PenghapusanKatalog;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\Satuan;
use App\Domain\Referensi\Model\SatuanStandar;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('F-03 kategori bertingkat', function (): void {
    it('maks. 3 tingkat, tanpa siklus, nama unik di antara saudara tanpa beda huruf besar/kecil, audit', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post('/kelola/kategori', ['Nama' => 'Minuman', 'UuidInduk' => null, 'Urutan' => '1'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $minuman = Kategori::query()->where('Nama', 'Minuman')->sole();
        $masuk()->post('/kelola/kategori', ['Nama' => 'Kopi', 'UuidInduk' => $minuman->Uuid])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $kopi = Kategori::query()->where('Nama', 'Kopi')->sole();
        $masuk()->post('/kelola/kategori', ['Nama' => 'Kopi Susu', 'UuidInduk' => $kopi->Uuid])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $kopiSusu = Kategori::query()->where('Nama', 'Kopi Susu')->sole();

        $masuk()->post('/kelola/kategori', ['Nama' => 'Aren', 'UuidInduk' => $kopiSusu->Uuid])
            ->assertSessionHasErrors(['UuidInduk' => 'Kategori maksimal 3 tingkat, misal Minuman › Kopi › Kopi Susu.']);
        $masuk()->post('/kelola/kategori', ['Nama' => 'KOPI', 'UuidInduk' => $minuman->Uuid])->assertSessionHasErrors(['Nama' => 'Kategori KOPI sudah ada di tingkat ini.']);
        $masuk()->post('/kelola/kategori', ['Nama' => 'Kopi', 'UuidInduk' => null])->assertSessionHasNoErrors();
        $masuk()->put("/kelola/kategori/{$minuman->Uuid}", ['Nama' => 'Minuman', 'UuidInduk' => $kopiSusu->Uuid])->assertSessionHasErrors('UuidInduk');
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $snack = BantuanKatalog::BuatKategori('Snack');
        // Memindah Kopi (tinggi 2) ke bawah Snack → kedalaman 3: boleh; ke bawah Kopi Susu (dirinya sendiri) → siklus.
        $masuk()->put("/kelola/kategori/{$kopi->Uuid}", ['Nama' => 'Kopi', 'UuidInduk' => $snack->Uuid])->assertSessionHasNoErrors();
        $masuk()->put("/kelola/kategori/{$kopi->Uuid}", ['Nama' => 'Kopi', 'UuidInduk' => $kopiSusu->Uuid])
            ->assertSessionHasErrors(['UuidInduk' => 'Kategori tidak bisa dipindah ke bawah dirinya sendiri atau sub-kategorinya.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Kategori::query()->count())->toBe(5)
            ->and($kopi->refresh()->IdInduk)->toBe($snack->Id)
            ->and(LogAudit::query()->where('Peristiwa', 'kategori.buat')->count())->toBe(4)
            ->and(LogAudit::query()->where('Peristiwa', 'kategori.ubah')->count())->toBe(1);
    });

    it('hapus: ditolak bila punya sub-kategori atau produk. Produk terhapus dilepas. Jejak Kategori dicatat', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $induk = BantuanKatalog::BuatKategori('Makanan');
        $anak = BantuanKatalog::BuatKategori('Roti', $induk);
        $produk = BantuanKatalog::BuatProduk(['IdKategori' => $anak->Id], null, $t['Pcs']);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->delete("/kelola/kategori/{$induk->Uuid}")->assertSessionHasErrors(['Umum' => 'Kategori Makanan masih punya sub-kategori. Pindahkan atau hapus sub-kategorinya dulu.']);
        $masuk()->delete("/kelola/kategori/{$anak->Uuid}")->assertSessionHasErrors(['Umum' => 'Kategori Roti masih dipakai 1 produk. Pindahkan produknya ke kategori lain dulu.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $produk->delete();
        $masuk()->delete("/kelola/kategori/{$anak->Uuid}")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Kategori::query()->whereKey($anak->Id)->exists())->toBeFalse()
            ->and(Produk::query()->withTrashed()->whereKey($produk->Id)->sole()->IdKategori)->toBeNull()
            ->and(PenghapusanKatalog::query()->where('Entitas', EntitasKatalog::Kategori->value)->sole()->UuidEntitas)->toBe($anak->Uuid)
            ->and(LogAudit::query()->where('Peristiwa', 'kategori.hapus')->count())->toBe(1);
    });

    it('PastikanJalurKategori (impor): cocok tanpa beda huruf besar/kecil, membuat tingkat yang hilang bila boleh', function (): void {
        BantuanKatalog::SiapkanTenantProduk();
        $minuman = BantuanKatalog::BuatKategori('Minuman');
        $aksi = app(PastikanJalurKategori::class);

        expect($aksi->Jalankan(['minuman', ' Kopi '], false))->toBeNull();
        $kopi = $aksi->Jalankan(['minuman', ' Kopi '], true);
        expect($kopi?->IdInduk)->toBe($minuman->Id)
            ->and($aksi->Jalankan(['MINUMAN', 'kopi'], false)?->Id)->toBe($kopi?->Id)
            ->and($aksi->Jalankan([], true))->toBeNull()
            ->and(fn () => $aksi->Jalankan(['A', 'B', 'C', 'D'], true))->toThrow(PelanggaranAturanBisnis::class, 'maksimal 3 tingkat')
            ->and(Kategori::query()->count())->toBe(2);
    });
});

describe('F-03 satuan tenant', function (): void {
    it('nama unik, desimal terkunci bila menjadi satuan dasar produk, hapus hanya bila tidak dipakai', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $masuk()->post('/kelola/satuan', ['Nama' => 'Lusin', 'Simbol' => 'lsn', 'BolehDesimal' => false])->assertSessionHasNoErrors();
        $masuk()->post('/kelola/satuan', ['Nama' => 'lusin', 'Simbol' => 'ls', 'BolehDesimal' => false])->assertSessionHasErrors(['Nama' => 'Satuan lusin sudah ada.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        BantuanKatalog::BuatProduk([], null, $t['Pcs']);
        $lusin = Satuan::query()->where('Nama', 'Lusin')->sole();
        $masuk()->put("/kelola/satuan/{$t['Pcs']->Uuid}", ['Nama' => 'Pieces', 'Simbol' => 'pcs', 'BolehDesimal' => true])->assertSessionHasErrors('BolehDesimal');
        $masuk()->put("/kelola/satuan/{$t['Pcs']->Uuid}", ['Nama' => 'Buah', 'Simbol' => 'bh', 'BolehDesimal' => false])->assertSessionHasNoErrors();
        $masuk()->delete("/kelola/satuan/{$t['Pcs']->Uuid}")->assertSessionHasErrors(['Umum' => 'Satuan Buah masih dipakai produk, jadi tidak bisa dihapus.']);
        $masuk()->delete("/kelola/satuan/{$lusin->Uuid}")->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Satuan::query()->whereKey($lusin->Id)->exists())->toBeFalse()
            ->and($t['Pcs']->refresh()->Nama)->toBe('Buah')
            ->and(PenghapusanKatalog::query()->where('Entitas', EntitasKatalog::Satuan->value)->sole()->UuidEntitas)->toBe($lusin->Uuid)
            ->and(LogAudit::query()->whereIn('Peristiwa', ['satuan.buat', 'satuan.ubah', 'satuan.hapus'])->count())->toBe(3);
    });

    it('PastikanSatuan (impor): nama/simbol/kode tenant, lalu satuan standar platform, lalu satuan baru bila boleh', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk();
        SatuanStandar::query()->create(['Kode' => 'LTR', 'Nama' => 'Liter', 'Simbol' => 'L', 'BolehDesimal' => true, 'Aktif' => true]);
        $aksi = app(PastikanSatuan::class);

        expect($aksi->Jalankan('PCS', false)?->Id)->toBe($t['Pcs']->Id)
            ->and($aksi->Jalankan('kilogram', false)?->Id)->toBe($t['Kg']->Id)
            ->and($aksi->Jalankan('Kg', false)?->Id)->toBe($t['Kg']->Id);

        $liter = $aksi->Jalankan('ltr', false);
        expect($liter?->KodeStandar)->toBe('LTR')->and($liter?->BolehDesimal)->toBeTrue()
            ->and($aksi->Jalankan('Karung', false))->toBeNull();

        $karung = $aksi->Jalankan('Karung', true);
        expect($karung?->Simbol)->toBe('Karung')->and($karung?->KodeStandar)->toBeNull()
            ->and($aksi->Jalankan('karung', true)?->Id)->toBe($karung?->Id)
            ->and(Satuan::query()->count())->toBe(4);
    });
});
