<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\Peran;
use App\Domain\Organisasi\Model\PeranIzin;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pajak\Model\KelompokPajak;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokAwal;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit kemudahan pakai #11: "Stok sekarang" + "Harga beli" di formulir produk baru menjadi stok awal satu baris yang
 * langsung diposting (J-05.1) di transaksi yang sama dengan produknya. Satu lokasi stok terpilih otomatis di halaman.
 * Ditolak tanpa menyimpan produk: produk ber-batch/seri, lokasi di luar akses, atau pelaku tanpa izin stok awal.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $t
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsiFormProdukBerstok(array $t, array $timpa = []): array
{
    // `BantuanPersediaan::SiapkanTenant` sudah membuat kelompok pajak bawaan katalog.
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    return BantuanKatalog::IsiFormProduk($t['Pcs'], KelompokPajak::query()->firstOrFail(), array_replace([
        'Nama' => 'Sabun Cuci Piring Jeruk Nipis 780 ml',
        'StokAwal' => ['Jumlah' => '24', 'HargaBeli' => '12500', 'UuidGudang' => $t['Gudang']->Uuid],
    ], $timpa));
}

it('produk baru + stok sekarang: stok awal Diposting, saldo stok 24, jurnal seimbang, tanpa stok = tanpa dokumen', function (): void {
    $t = BantuanPersediaan::SiapkanTenant('Toko Kelontong Berkah Sragen');
    $masuk = fn () => BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->get('/kelola/produk/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('StokAwal.Lokasi.0.Uuid', $t['Gudang']->Uuid));

    $form = IsiFormProdukBerstok($t);
    $masuk()->post('/kelola/produk', $form)->assertSessionHasNoErrors()->assertRedirect("/kelola/produk/{$form['Uuid']}")
        ->assertSessionHas('Kilat', "Produk Sabun Cuci Piring Jeruk Nipis 780 ml disimpan dengan stok 24 di {$t['Gudang']->Nama}.");

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $produk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
    $dokumen = StokAwal::query()->sole();
    expect($dokumen->Status)->toBe(StatusStokAwal::Diposting)
        ->and($dokumen->TotalNilai)->toBe('300000.00')
        ->and(SaldoStok::query()->where('IdProduk', $produk->Id)->where('IdGudang', $t['Gudang']->Id)->value('JumlahTersedia'))->toBe('24.0000')
        ->and(BantuanStokAwal::PeriksaInvarianTanpaJurnalPenjualan($t['Tenant']->Id))->toBe([]);

    // Kosong = produk biasa; harga beli kosong = Rp 0.
    $masuk()->post('/kelola/produk', IsiFormProdukBerstok($t, ['Nama' => 'Teh Celup Melati Isi 25', 'StokAwal' => null]))->assertSessionHasNoErrors();
    $masuk()->post('/kelola/produk', IsiFormProdukBerstok($t, ['Nama' => 'Gula Pasir Curah 1 kg', 'StokAwal' => ['Jumlah' => '3', 'HargaBeli' => '', 'UuidGudang' => $t['Gudang']->Uuid]]))->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(StokAwal::query()->count())->toBe(2)
        ->and(StokAwal::query()->orderByDesc('Id')->firstOrFail()->TotalNilai)->toBe('0.00');
});

it('ditolak tanpa menyimpan produk: ber-batch, jumlah berformat ribuan, lokasi tidak dikenal, tanpa izin stok awal', function (): void {
    $t = BantuanPersediaan::SiapkanTenant('Toko Kelontong Tolak Sragen');
    $masuk = fn () => BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->post('/kelola/produk', IsiFormProdukBerstok($t, ['Pelacakan' => PelacakanProduk::Batch->value]))->assertSessionHasErrors('StokAwal.Jumlah');
    $masuk()->post('/kelola/produk', IsiFormProdukBerstok($t, ['StokAwal' => ['Jumlah' => '1.000,5', 'UuidGudang' => $t['Gudang']->Uuid]]))->assertSessionHasErrors('StokAwal.Jumlah');
    $masuk()->post('/kelola/produk', IsiFormProdukBerstok($t, ['StokAwal' => ['Jumlah' => '5', 'UuidGudang' => (string) Str::ulid()]]))->assertSessionHasErrors('StokAwal.UuidGudang');

    // Peran kustom: boleh kelola produk, tidak boleh stok awal → isian disembunyikan dan kiriman ditolak.
    $peran = Peran::query()->create(['Kode' => 'PenataKatalog', 'Nama' => 'Penata Katalog', 'Bawaan' => false]);
    foreach ([IzinTenant::ProdukLihat, IzinTenant::ProdukKelola] as $izin) {
        PeranIzin::query()->create(['IdPeran' => $peran->Id, 'KunciIzin' => $izin->value]);
    }
    $anggota = Pengguna::factory()->create();
    TenantPengguna::query()->create(['IdTenant' => $t['Tenant']->Id, 'IdPengguna' => $anggota->Id, 'Pemilik' => false, 'IdPeran' => $peran->Id, 'SemuaOutlet' => true]);
    BantuanOrganisasi::Masuk($this, $anggota, $t['Tenant']->Id)->get('/kelola/produk/buat')
        ->assertInertia(fn (AssertableInertia $h) => $h->where('StokAwal', null));
    BantuanOrganisasi::Masuk($this, $anggota, $t['Tenant']->Id)->post('/kelola/produk', IsiFormProdukBerstok($t))->assertSessionHasErrors('StokAwal.Jumlah');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(Produk::query()->count())->toBe(0)
        ->and(StokAwal::query()->count())->toBe(0);
});
