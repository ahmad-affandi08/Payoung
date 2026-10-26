<?php

declare(strict_types=1);

use App\Domain\Katalog\Model\Produk;
use App\Domain\Penjualan\Aksi\KedaluwarsakanPesananOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusReservasiStok;
use App\Domain\Persediaan\Model\ReservasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanTokoOnline;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-17 (v3.48): cadangan stok pesanan toko online. Checkout mencadangkan stok lokasi Toko; pesanan lain hanya bisa
 * memesan sisanya (stok − cadangan aktif), galat `StokTidakCukup` muncul sejak hitung keranjang. Cadangan dilepas saat
 * pesanan ditolak/batal/kedaluwarsa dan ditutup (Dipakai) saat kasir menagihnya; `SaldoStok` tidak pernah disentuh
 * cadangan. Toko yang mengizinkan stok minus tetap menerima pesanan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** @return array<string, mixed> */
function SiapkanStokOnline(TestCase $tes, string $stok = '3'): array
{
    $k = BantuanTokoOnline::Siapkan($tes);
    $roti = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Roti Tawar Gandum Utuh', $stok, '9000', '15000.00');
    $roti->forceFill(['TampilOnline' => true])->save();

    return $k + ['Roti' => $roti];
}

/**
 * @param  array<string, mixed>  $k
 * @return array<string, mixed>
 */
function KirimanRoti(array $k, int $jumlah, string $noHp = '081234567890'): array
{
    /** @var Produk $roti */
    $roti = $k['Roti'];

    return [
        ...BantuanTokoOnline::Kiriman($k),
        'NoHp' => $noHp,
        'Baris' => [['Uuid' => (string) Str::ulid(), 'UuidProduk' => $roti->Uuid, 'Jumlah' => $jumlah, 'Pilihan' => [], 'HargaSatuan' => '1.00']],
    ];
}

/** @param array<string, mixed> $kiriman */
function HitungRoti(TestCase $tes, string $slug, array $kiriman): TestResponse
{
    return $tes->postJson("/{$slug}/keranjang/hitung", ['Outlet' => $kiriman['Outlet'], 'JenisPemenuhan' => 'AmbilSendiri', 'Baris' => $kiriman['Baris']]);
}

it('checkout mencadangkan stok. Pesanan lain hanya mendapat sisanya. Ditolak & kedaluwarsa melepas; SaldoStok tidak berubah', function (): void {
    $k = SiapkanStokOnline($this);
    $pertama = KirimanRoti($k, 2);
    $this->postJson("/{$k['Slug']}/pesan", $pertama)->assertCreated();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $cadangan = ReservasiStok::query()->sole();
    expect($cadangan->Status)->toBe(StatusReservasiStok::Aktif)
        ->and($cadangan->Jumlah)->toBe('2.0000')
        ->and($cadangan->UuidSumber)->toBe($pertama['Uuid'])
        ->and($cadangan->IdGudang)->toBe($k['Gudang']->Id)
        ->and(SaldoStok::query()->where('IdProduk', $k['Roti']->Id)->value('JumlahTersedia'))->toBe('3.0000');

    // Ulang kiriman yang sama (idempoten) tidak mencadangkan dua kali.
    $this->postJson("/{$k['Slug']}/pesan", $pertama)->assertOk();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(ReservasiStok::query()->count())->toBe(1);

    // Sisa 1: dua roti ditolak sejak hitung dan saat checkout; satu roti masih bisa.
    HitungRoti($this, $k['Slug'], KirimanRoti($k, 2))->assertUnprocessable()
        ->assertJsonPath('Galat.Kode', 'StokTidakCukup')
        ->assertJsonPath('Galat.Pesan', 'Stok belum cukup untuk Roti Tawar Gandum Utuh. Kurangi jumlahnya atau pilih menu lain.');
    $this->postJson("/{$k['Slug']}/pesan", KirimanRoti($k, 2, '081211112222'))->assertUnprocessable()->assertJsonPath('Galat.Kode', 'StokTidakCukup');
    $kedua = KirimanRoti($k, 1, '081233334444');
    $this->postJson("/{$k['Slug']}/pesan", $kedua)->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(PesananOnline::query()->count())->toBe(2, 'Checkout yang gagal tidak meninggalkan pesanan.');

    // Ditolak kasir → cadangan pesanan pertama dilepas, dua roti bisa dipesan lagi.
    $this->withToken($k['Token'])->postJson("/api/pos/v1/pesanan-online/{$pertama['Uuid']}/status", [
        'UuidPengguna' => $k['Kasir']->Uuid, 'Status' => 'Ditolak', 'Alasan' => 'Roti belum datang',
    ])->assertOk();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(ReservasiStok::query()->where('UuidSumber', $pertama['Uuid'])->sole()->Status)->toBe(StatusReservasiStok::Dilepas);
    HitungRoti($this, $k['Slug'], KirimanRoti($k, 2))->assertOk();

    // Pesanan kedua tidak dikonfirmasi sampai batas waktu → hangus → cadangannya dilepas.
    $this->travel(1)->days();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(app(KedaluwarsakanPesananOnline::class)->Jalankan())->toBe(1)
        ->and(ReservasiStok::query()->where('UuidSumber', $kedua['Uuid'])->sole()->Status)->toBe(StatusReservasiStok::Dilepas)
        ->and(ReservasiStok::query()->where('Status', StatusReservasiStok::Aktif->value)->count())->toBe(0)
        ->and(SaldoStok::query()->where('IdProduk', $k['Roti']->Id)->value('JumlahTersedia'))->toBe('3.0000');
});

it('kasir menagih pesanan → cadangan Dipakai dan stok berkurang lewat mutasi penjualan', function (): void {
    $k = SiapkanStokOnline($this);
    $kiriman = KirimanRoti($k, 2);
    $this->postJson("/{$k['Slug']}/pesan", $kiriman)->assertCreated();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PesananOnline::query()->where('Uuid', $kiriman['Uuid'])->sole()->forceFill(['Status' => StatusPesananOnline::Siap])->save();
    $item = BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Roti'], 'Jumlah' => '2', 'Harga' => '15000.00']],
    ], ['UuidPesananOnline' => $kiriman['Uuid'], 'Kanal' => 'Online']);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(ReservasiStok::query()->sole()->Status)->toBe(StatusReservasiStok::Dipakai)
        ->and(SaldoStok::query()->where('IdProduk', $k['Roti']->Id)->value('JumlahTersedia'))->toBe('1.0000');
    // Sisa satu roti tanpa cadangan aktif: satu bisa dipesan, dua tidak.
    HitungRoti($this, $k['Slug'], KirimanRoti($k, 1))->assertOk();
    HitungRoti($this, $k['Slug'], KirimanRoti($k, 2))->assertUnprocessable()->assertJsonPath('Galat.Kode', 'StokTidakCukup');
});

it('toko yang mengizinkan stok minus tetap menerima pesanan melebihi stok. Cadangannya tetap tercatat', function (): void {
    $k = SiapkanStokOnline($this, '1');
    BantuanPersediaan::AturMetodeHpp($k['Tenant'], MetodeHpp::RataRata, stokBolehMinus: true);

    $this->postJson("/{$k['Slug']}/pesan", KirimanRoti($k, 3))->assertCreated();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(ReservasiStok::query()->sole()->Jumlah)->toBe('3.0000');
});
