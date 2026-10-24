<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pajak\Aksi\TambahkanKelompokPajakTemplate;
use App\Domain\Pajak\Data\DataKelompokPajakTemplate;
use App\Domain\Pajak\Enum\KategoriPajakProduk;
use App\Domain\Pajak\Model\KelompokPajak;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanHarga::SiapkanJenisPajak();
    $this->t = BantuanKatalog::SiapkanTenantProduk();
});

describe('F-03 halaman kelompok pajak (E.8, §12.2)', function (): void {
    it('menampilkan kelompok dengan kategori, detail pajak, jumlah produk, jenis pajak, dan pilihan', function (): void {
        $kelompok = $this->t['KelompokPajak'];
        BantuanKatalog::BuatProduk(['Nama' => 'Sabun Mandi Cair 450 ml', 'IdKelompokPajak' => $kelompok->Id], '5000.00', $this->t['Pcs']);
        BantuanKatalog::BuatProduk(['Nama' => 'Sampo Anti Ketombe 170 ml', 'IdKelompokPajak' => $kelompok->Id], '25000.00', $this->t['Pcs']);

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/kelompok-pajak')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/KelompokPajak/Daftar')
                ->where('KelompokPajak.0.Uuid', $kelompok->Uuid)
                ->where('KelompokPajak.0.JumlahProduk', 2)
                ->where('KelompokPajak.0.LabelKategori', 'Belum dikategorikan')
                ->where('KelompokPajak.0.Pajak', [])
                ->has('JenisPajak', 3)
                ->has('Kategori', count(KategoriPajakProduk::cases()))
                ->where('DasarPengenaan.0', ['Nilai' => 'Subtotal', 'Label' => 'Subtotal'])
                ->where('Izin.KelolaPajak', false));
    });

    it('halaman tambah (halaman penuh): opsi jenis pajak, kategori, dasar pengenaan; tanpa daftar kelompok; Kasir & Manajer 403', function (): void {
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Akuntan)->get('/kelola/kelompok-pajak/buat')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/KelompokPajak/Buat')
                ->has('JenisPajak', 3)
                ->has('Kategori', count(KategoriPajakProduk::cases()))
                ->where('DasarPengenaan.0', ['Nilai' => 'Subtotal', 'Label' => 'Subtotal'])
                ->missing('KelompokPajak'));

        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir)->get('/kelola/kelompok-pajak/buat')->assertForbidden();
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->get('/kelola/kelompok-pajak/buat')->assertForbidden();
    });

    it('Akuntan membuat dan mengubah kelompok pajak; masalah konsistensi kategori di bidang Pajak', function (): void {
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Akuntan);

        $masuk()->from('/kelola/kelompok-pajak/buat')->post('/kelola/kelompok-pajak', ['Nama' => 'Makan & minum', 'Kategori' => 'KenaPbjt', 'Pajak' => [
            ['KodeJenisPajak' => 'PbjtMakananMinuman', 'DasarPengenaan' => 'SubtotalPlusLayanan'],
        ]])->assertSessionHasNoErrors()->assertRedirect('/kelola/kelompok-pajak');
        $masuk()->post('/kelola/kelompok-pajak', ['Nama' => 'Salah', 'Kategori' => 'KenaPpn', 'Pajak' => []])->assertSessionHasErrors(['Pajak']);
        $masuk()->post('/kelola/kelompok-pajak', ['Nama' => 'Tanpa kategori', 'Kategori' => 'Bebas', 'Pajak' => []])->assertSessionHasErrors(['Kategori']);

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        $kelompok = KelompokPajak::query()->where('Nama', 'Makan & minum')->sole();
        expect($kelompok->Kategori)->toBe(KategoriPajakProduk::KenaPbjt);

        $masuk()->put("/kelola/kelompok-pajak/{$kelompok->Uuid}", ['Nama' => 'Makan & minum dine-in', 'Kategori' => 'KenaPbjt', 'Pajak' => [
            ['KodeJenisPajak' => 'PbjtMakananMinuman', 'DasarPengenaan' => 'Subtotal'],
        ]])->assertSessionHasNoErrors();
        expect($kelompok->refresh()->Nama)->toBe('Makan & minum dine-in');
    });

    it('izin & isolasi: Kasir/Manajer 403 saat menyimpan; kelompok tenant lain 404', function (): void {
        $body = ['Nama' => 'Non-pajak', 'Kategori' => 'NonPajak', 'Pajak' => []];
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::Kasir)->post('/kelola/kelompok-pajak', $body)->assertForbidden();
        BantuanKatalog::MasukSebagai($this, $this->t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)
            ->put("/kelola/kelompok-pajak/{$this->t['KelompokPajak']->Uuid}", $body)->assertForbidden();

        $lain = BantuanKatalog::SiapkanTenantProduk('Toko Makmur Jaya');
        BantuanKatalog::MasukSebagai($this, $lain['Tenant']->Id)->put("/kelola/kelompok-pajak/{$this->t['KelompokPajak']->Uuid}", $body)->assertNotFound();

        BantuanOrganisasi::AturKonteks($this->t['Tenant']->Id);
        expect($this->t['KelompokPajak']->refresh()->Nama)->toBe('Barang kena PPN');
    });
});

it('F-01 template sektor: kelompok pajak baru langsung berkategori sesuai detailnya', function (): void {
    app(TambahkanKelompokPajakTemplate::class)->Jalankan([
        new DataKelompokPajakTemplate('Makan & minum', [['KodeJenisPajak' => 'PbjtMakananMinuman', 'DasarPengenaan' => 'SubtotalPlusLayanan', 'Urutan' => 1]]),
        new DataKelompokPajakTemplate('Barang retail', [['KodeJenisPajak' => 'Ppn', 'DasarPengenaan' => 'Subtotal', 'Urutan' => 1]]),
        new DataKelompokPajakTemplate('Tanpa pajak', []),
        new DataKelompokPajakTemplate('Hiburan', [['KodeJenisPajak' => 'PbjtJasaHiburan', 'DasarPengenaan' => 'Subtotal', 'Urutan' => 1]]),
    ]);

    expect(KelompokPajak::query()->whereIn('Nama', ['Makan & minum', 'Barang retail', 'Tanpa pajak', 'Hiburan'])->orderBy('Id')->get()->map(fn (KelompokPajak $k) => $k->Kategori)->all())
        ->toBe([KategoriPajakProduk::KenaPbjt, KategoriPajakProduk::KenaPpn, KategoriPajakProduk::NonPajak, KategoriPajakProduk::Lainnya]);

    // Kelompok lama tanpa kategori ikut terisi saat template diterapkan ulang.
    $lama = KelompokPajak::query()->where('Nama', 'Barang kena PPN')->sole();
    app(TambahkanKelompokPajakTemplate::class)->Jalankan([
        new DataKelompokPajakTemplate('Barang kena PPN', [['KodeJenisPajak' => 'Ppn', 'DasarPengenaan' => 'Subtotal', 'Urutan' => 1]]),
    ]);
    expect($lama->refresh()->Kategori)->toBe(KategoriPajakProduk::KenaPpn);
});
