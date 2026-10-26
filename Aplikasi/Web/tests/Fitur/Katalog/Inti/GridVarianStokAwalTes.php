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
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokAwal;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * Fashion (§9.4, D-143): grid ukuran × warna. Stok awal banyak varian dicatat sebagai SATU dokumen stok awal yang langsung
 * diposting (J-05.1) lewat endpoint `POST /kelola/produk/{induk}/varian/stok-awal`; satu baris ditolak = tidak ada yang
 * tercatat. Halaman detail induk membawa stok sekarang tiap varian dan lokasi stok yang boleh dipakai pelaku.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Induk kaos dengan 6 varian (S/M/L × Hitam/Putih... tiga ukuran dua warna), harga dasar Rp 75.000.
 *
 * @param  array<string, mixed>  $t
 * @return array{0: Produk, 1: list<Produk>}
 */
function SiapkanIndukKaos(TestCase $tes, array $t): array
{
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $form = BantuanKatalog::IsiFormProduk($t['Pcs'], KelompokPajak::query()->firstOrFail(), [
        'Nama' => 'Kaos Polos Katun Combed 30s',
        'Sku' => 'KAOS',
        'Jenis' => 'IndukVarian',
        'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M', 'L']], ['Nama' => 'Warna', 'Nilai' => ['Hitam', 'Putih']]],
    ]);
    BantuanPersediaan::MasukSebagai($tes, $t['Tenant']->Id)->post('/kelola/produk', $form)->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $induk = Produk::query()->where('Uuid', $form['Uuid'])->sole();
    BantuanPersediaan::MasukSebagai($tes, $t['Tenant']->Id)->post("/kelola/produk/{$induk->Uuid}/varian", [
        'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M', 'L']], ['Nama' => 'Warna', 'Nilai' => ['Hitam', 'Putih']]],
        'JenisAnak' => 'Stok',
        'HargaDasar' => '75000',
    ])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);

    return [$induk, Produk::query()->where('IdInduk', $induk->Id)->orderBy('Id')->get()->all()];
}

it('stok awal grid: satu dokumen diposting, saldo per varian benar, jurnal seimbang. Detail induk membawa stok & lokasi', function (): void {
    $t = BantuanPersediaan::SiapkanTenant('Toko Kaos Laweyan Solo');
    [$induk, $varian] = SiapkanIndukKaos($this, $t);
    expect($varian)->toHaveCount(6);
    $masuk = fn () => BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

    $masuk()->get("/kelola/produk/{$induk->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
        ->where('StokAwalVarian.Lokasi.0.Uuid', $t['Gudang']->Uuid)
        ->where("StokVarian.{$varian[0]->Uuid}", '0'));

    $masuk()->post("/kelola/produk/{$induk->Uuid}/varian/stok-awal", [
        'UuidGudang' => $t['Gudang']->Uuid,
        'Baris' => [
            ['Uuid' => $varian[0]->Uuid, 'Jumlah' => '10', 'HargaBeli' => '40000'],
            ['Uuid' => $varian[1]->Uuid, 'Jumlah' => '4', 'HargaBeli' => '40000'],
            ['Uuid' => $varian[2]->Uuid, 'Jumlah' => '7', 'HargaBeli' => ''],
        ],
    ])->assertSessionHasNoErrors()->assertSessionHas('Kilat');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $dokumen = StokAwal::query()->sole();
    expect($dokumen->Status)->toBe(StatusStokAwal::Diposting)
        ->and($dokumen->JumlahBaris)->toBe(3)
        ->and($dokumen->TotalNilai)->toBe('560000.00')
        ->and(SaldoStok::query()->where('IdProduk', $varian[0]->Id)->value('JumlahTersedia'))->toBe('10.0000')
        ->and(SaldoStok::query()->where('IdProduk', $varian[2]->Id)->value('JumlahTersedia'))->toBe('7.0000')
        ->and(MutasiStok::query()->count())->toBe(3)
        ->and(BantuanStokAwal::PeriksaInvarianTanpaJurnalPenjualan($t['Tenant']->Id))->toBe([])
        ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);

    $masuk()->get("/kelola/produk/{$induk->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
        ->where("StokVarian.{$varian[0]->Uuid}", '10.0000')
        ->where("StokVarian.{$varian[5]->Uuid}", '0'));
});

it('ditolak seluruhnya: varian induk lain, varian ber-batch, stok awal ganda, jumlah desimal untuk satuan bulat, lokasi asing', function (): void {
    $t = BantuanPersediaan::SiapkanTenant('Toko Kaos Pasar Klewer');
    [$induk, $varian] = SiapkanIndukKaos($this, $t);
    $masuk = fn () => BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
    $kirim = fn (array $baris, ?string $gudang = null) => $masuk()->post("/kelola/produk/{$induk->Uuid}/varian/stok-awal", ['UuidGudang' => $gudang ?? $t['Gudang']->Uuid, 'Baris' => $baris]);

    // Produk yang bukan varian induk ini.
    $lain = BantuanKatalog::BuatProduk(['Sku' => 'LAIN-1', 'IdKelompokPajak' => KelompokPajak::query()->firstOrFail()->Id], '1000.00', $t['Pcs']);
    $kirim([['Uuid' => $varian[0]->Uuid, 'Jumlah' => '5'], ['Uuid' => $lain->Uuid, 'Jumlah' => '5']])->assertSessionHasErrors();

    // Lokasi tidak dikenal; jumlah berformat ribuan.
    $kirim([['Uuid' => $varian[0]->Uuid, 'Jumlah' => '5']], (string) Str::ulid())->assertSessionHasErrors('UuidGudang');
    $kirim([['Uuid' => $varian[0]->Uuid, 'Jumlah' => '1.000,5']])->assertSessionHasErrors('Baris.0.Jumlah');

    // Varian ber-batch tidak boleh lewat grid.
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    $varian[1]->forceFill(['Pelacakan' => PelacakanProduk::Batch])->save();
    $kirim([['Uuid' => $varian[0]->Uuid, 'Jumlah' => '5'], ['Uuid' => $varian[1]->Uuid, 'Jumlah' => '5']])->assertSessionHasErrors('Baris.1.Jumlah');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(StokAwal::query()->count())->toBe(0)->and(MutasiStok::query()->count())->toBe(0);

    // Stok awal ganda: varian yang sudah punya stok awal tidak bisa dicatat ulang, dan varian lain di kiriman yang sama
    // juga tidak tercatat.
    $kirim([['Uuid' => $varian[0]->Uuid, 'Jumlah' => '5']])->assertSessionHasNoErrors();
    $kirim([['Uuid' => $varian[0]->Uuid, 'Jumlah' => '9'], ['Uuid' => $varian[2]->Uuid, 'Jumlah' => '9']])->assertSessionHasErrors();
    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(StokAwal::query()->count())->toBe(1)
        ->and(SaldoStok::query()->where('IdProduk', $varian[2]->Id)->count())->toBe(0);
});

it('tanpa izin stok awal: prop StokAwalVarian null dan kiriman ditolak', function (): void {
    $t = BantuanPersediaan::SiapkanTenant('Toko Kaos Tanpa Izin');
    [$induk, $varian] = SiapkanIndukKaos($this, $t);
    $peran = Peran::query()->create(['Kode' => 'PenataKatalog', 'Nama' => 'Penata Katalog', 'Bawaan' => false]);
    foreach ([IzinTenant::ProdukLihat, IzinTenant::ProdukKelola] as $izin) {
        PeranIzin::query()->create(['IdPeran' => $peran->Id, 'KunciIzin' => $izin->value]);
    }
    $anggota = Pengguna::factory()->create();
    TenantPengguna::query()->create(['IdTenant' => $t['Tenant']->Id, 'IdPengguna' => $anggota->Id, 'Pemilik' => false, 'IdPeran' => $peran->Id, 'SemuaOutlet' => true]);

    BantuanOrganisasi::Masuk($this, $anggota, $t['Tenant']->Id)->get("/kelola/produk/{$induk->Uuid}")
        ->assertInertia(fn (AssertableInertia $h) => $h->where('StokAwalVarian', null)->where('StokVarian', []));
    BantuanOrganisasi::Masuk($this, $anggota, $t['Tenant']->Id)->post("/kelola/produk/{$induk->Uuid}/varian/stok-awal", [
        'UuidGudang' => $t['Gudang']->Uuid,
        'Baris' => [['Uuid' => $varian[0]->Uuid, 'Jumlah' => '5']],
    ])->assertSessionHasErrors('Baris');

    BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
    expect(StokAwal::query()->count())->toBe(0);
});
