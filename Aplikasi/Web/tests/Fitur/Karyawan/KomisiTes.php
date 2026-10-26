<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Enum\CakupanKomisi;
use App\Domain\Karyawan\Enum\JenisKomisi;
use App\Domain\Karyawan\Model\AturanKomisi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\Komisi;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use Brick\Math\RoundingMode;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-18 bagian 2 (PRD "Rincian F-18 bagian 2"): aturan komisi (produk > kategori > semua; level staf yang cocok
 * diutamakan), komisi dicatat saat `Penjualan.Buat` membawa `Baris.*.Staf` (dibagi rata, idempoten), staf tidak dikenal
 * = tinjauan, void membatalkan penuh, retur memotong proporsional, laporan per karyawan, data awal POS, dan izin.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant kasir + produk berstok + dua karyawan (Senior & Junior) + aturan 10% semua produk & 20% produk ini untuk Senior.
 *
 * @return array<string, mixed>
 */
function SiapkanKomisi(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Salon Cantik Komisi');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $senior = Karyawan::query()->create(['Nama' => 'Maya Senior', 'LevelStaf' => 'Senior']);
    $junior = Karyawan::query()->create(['Nama' => 'Dewi Junior', 'LevelStaf' => 'Junior']);
    AturanKomisi::query()->create(['Nama' => 'Umum 10%', 'Cakupan' => CakupanKomisi::Semua, 'Jenis' => JenisKomisi::Persen, 'Nilai' => '10']);
    AturanKomisi::query()->create(['Nama' => 'Senior produk 20%', 'Cakupan' => CakupanKomisi::Produk, 'UuidProduk' => $produk->Uuid, 'LevelStaf' => 'Senior', 'Jenis' => JenisKomisi::Persen, 'Nilai' => '20']);

    return $k + ['Produk' => $produk, 'Senior' => $senior, 'Junior' => $junior];
}

function DasarKomisi(PenjualanDetail $d): Uang
{
    return Uang::Dari((string) $d->Bruto)->Kurangi(Uang::Dari((string) $d->JumlahDiskon))->Kurangi(Uang::Dari((string) $d->JumlahDiskonPesanan))
        ->Kurangi(Uang::Dari((string) $d->JumlahPajak)->Kurangi(Uang::Dari((string) $d->PajakEksklusif)));
}

describe('F-18 komisi penjualan', function (): void {
    it('dibagi rata per staf dengan aturan masing-masing; idempoten; staf tidak dikenal = tinjauan', function (): void {
        $k = SiapkanKomisi($this);
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00', 'Staf' => [$k['Senior']->Uuid, $k['Junior']->Uuid]]]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $detail = PenjualanDetail::query()->sole();
        $dasar = DasarKomisi($detail);
        $senior = Komisi::query()->where('IdKaryawan', $k['Senior']->Id)->sole();
        $junior = Komisi::query()->where('IdKaryawan', $k['Junior']->Id)->sole();

        expect(Komisi::query()->count())->toBe(2)
            ->and((string) $senior->Porsi)->toBe('0.5000')
            ->and((string) $senior->Jumlah)->toBe($dasar->Kali('0.1')->KeString())
            ->and((string) $junior->Jumlah)->toBe($dasar->Kali('0.05')->KeString())
            ->and(Penjualan::query()->sole()->PerluTinjauan)->toBeFalse();

        $asing = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00', 'Staf' => [BantuanKasir::Uuid()]]]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$asing]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $asing['Uuid'])->sole()->AlasanTinjauan)->toContain('StafTidakDikenal')
            ->and(Komisi::query()->count())->toBe(2);
    });

    it('aturan sama untuk semua staf: sisa pembulatan ke staf terakhir sehingga Σ bagian = komisi penuh', function (): void {
        $k = SiapkanKomisi($this);
        $ketiga = Karyawan::query()->create(['Nama' => 'Rani Junior', 'LevelStaf' => 'Junior']);
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00', 'Staf' => [$k['Junior']->Uuid, $ketiga->Uuid]]]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $penuh = DasarKomisi(PenjualanDetail::query()->sole())->Kali('0.1');
        $jumlah = array_reduce(Komisi::query()->pluck('Jumlah')->all(), fn (Uang $t, $j): Uang => $t->Tambah(Uang::Dari((string) $j)), Uang::Nol());
        expect($jumlah->KeString())->toBe($penuh->KeString());
    });

    it('void membatalkan penuh. Retur separuh memotong separuh. Laporan menghitung bersih', function (): void {
        $k = SiapkanKomisi($this);
        $jual = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00', 'Staf' => [$k['Senior']->Uuid]]]]);
        $void = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00', 'Staf' => [$k['Junior']->Uuid]]]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $void)]))->toBe([['Diterima', null]]);
        $detail = PenjualanDetail::query()->where('IdPenjualan', $jual->Id)->sole();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $jual, [['Detail' => $detail, 'Jumlah' => '1']])]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $komisiJual = Komisi::query()->where('IdPenjualan', $jual->Id)->sole();
        $komisiVoid = Komisi::query()->where('IdPenjualan', $void->Id)->sole();
        $separuh = Uang::Dari((string) $komisiJual->Jumlah)->Kali('0.5', RoundingMode::HalfUp);
        expect((string) $komisiVoid->JumlahDibatalkan)->toBe((string) $komisiVoid->Jumlah)
            ->and((string) $komisiJual->JumlahDibatalkan)->toBe($separuh->KeString());

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->getJson('/kelola/karyawan/komisi/laporan?cari=')->assertOk()
            ->assertJsonPath('Meta.Total', 2)
            ->assertJsonPath('Data.0.Nama', 'Maya Senior')
            ->assertJsonPath('Data.0.Bersih', Uang::Dari((string) $komisiJual->Jumlah)->Kurangi($separuh)->KeString())
            ->assertJsonPath('Data.1.Bersih', '0.00')
            ->assertJsonPath('Ringkasan.Bersih', Uang::Dari((string) $komisiJual->Jumlah)->Kurangi($separuh)->KeString());
    });
});

describe('F-18 aturan komisi back-office', function (): void {
    it('tambah, validasi, ubah, arsip; izin; data awal POS memuat karyawan aktif', function (): void {
        $k = SiapkanKomisi($this);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post('/kelola/karyawan/komisi', ['Nama' => 'Terlalu besar', 'Cakupan' => 'Semua', 'Jenis' => 'Persen', 'Nilai' => '150'])->assertSessionHasErrors('Nilai');
        $this->post('/kelola/karyawan/komisi', ['Nama' => 'Tanpa produk', 'Cakupan' => 'Produk', 'Jenis' => 'Tetap', 'Nilai' => '5000'])->assertSessionHasErrors('UuidProduk');
        $this->post('/kelola/karyawan/komisi', ['Nama' => 'Cuci blow Rp 5.000', 'Cakupan' => 'Produk', 'UuidProduk' => $k['Produk']->Uuid, 'Jenis' => 'Tetap', 'Nilai' => '5000'])->assertSessionHasNoErrors()->assertRedirect('/kelola/karyawan/komisi');
        $aturan = AturanKomisi::query()->where('Nama', 'Cuci blow Rp 5.000')->sole();
        $this->put("/kelola/karyawan/komisi/{$aturan->Uuid}", ['Nama' => 'Cuci blow Rp 6.000', 'Cakupan' => 'Produk', 'UuidProduk' => $k['Produk']->Uuid, 'Jenis' => 'Tetap', 'Nilai' => '6000'])->assertSessionHasNoErrors();
        $this->post("/kelola/karyawan/komisi/{$aturan->Uuid}/arsipkan")->assertSessionHasNoErrors();
        expect($aturan->refresh()->Nama)->toBe('Cuci blow Rp 6.000')
            ->and($aturan->Status->value)->toBe('Diarsipkan')
            ->and(LogAudit::query()->where('Peristiwa', 'like', 'aturan-komisi.%')->count())->toBe(3);

        $this->get('/kelola/karyawan/komisi')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Karyawan/Komisi')->has('Aturan', 3)->where('Izin.Kelola', true));
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()
            ->assertJsonPath('Karyawan.0.Nama', 'Dewi Junior')
            ->assertJsonCount(2, 'Karyawan');

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/karyawan/komisi/laporan')->assertOk();
        $this->post('/kelola/karyawan/komisi', ['Nama' => 'Tidak boleh', 'Cakupan' => 'Semua', 'Jenis' => 'Persen', 'Nilai' => '5'])->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/karyawan/komisi')->assertForbidden();
    });

    it('halaman tambah aturan (halaman penuh): opsi kategori, tanpa karyawan.kelola 403', function (): void {
        $k = SiapkanKomisi($this);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get('/kelola/karyawan/komisi/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Karyawan/BuatAturanKomisi')
            ->has('OpsiKategori')
            ->missing('Aturan'));

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/karyawan/komisi/buat')->assertForbidden();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/karyawan/komisi/buat')->assertForbidden();
    });
});
