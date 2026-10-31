<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Persediaan\Aksi\BatalkanBahanTerbuang;
use App\Domain\Persediaan\Aksi\CatatBahanTerbuang;
use App\Domain\Persediaan\Data\DataBahanTerbuang;
use App\Domain\Persediaan\Enum\AlasanBahanTerbuang;
use App\Domain\Persediaan\Enum\StatusBahanTerbuang;
use App\Domain\Persediaan\Model\BahanTerbuang;
use App\Domain\Persediaan\Model\MutasiStok;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-05f bahan terbuang (INV-10, J-05.4): menu resep diuraikan ke bahan (resep terbaru), bahan berstok langsung; mutasi
 * Susut bernilai HPP berjalan; jurnal Dr Susut & Barang Rusak / Cr persediaan. Back-office menolak stok kurang, kasir
 * (outbox `BahanTerbuang.Catat`, idempoten) mencatat + tinjauan. Pembatalan membalik stok & jurnal. Invarian di tiap skenario.
 */

beforeEach(function (): void {
    // Jam dibekukan di tengah bulan dan tengah hari WIB, supaya hasil tidak bergantung kapan tes berjalan (batas bulan/hari).
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Kopi 1.000 g @200, susu 5.000 ml @20 di gudang; menu Es Kopi Susu = 18 g kopi + 150 ml susu per cup.
 *
 * @param  array<string, mixed>  $t
 * @return array{Kopi: Produk, Susu: Produk, Menu: Produk}
 */
function SiapkanBahanTerbuang(array $t, Gudang $gudang): array
{
    $kopi = BantuanKomposisi::BuatBahan();
    $susu = BantuanKomposisi::BuatBahan('Susu Segar Full Cream Pasteurisasi', 'ml', 'Mililiter');
    $menu = BantuanKomposisi::BuatProdukResep();
    BantuanKomposisi::SimpanResep($menu, [[$kopi, '18'], [$susu, '150']]);
    BantuanStokAwal::BuatDanPosting($gudang, [
        BantuanStokAwal::Baris($kopi, '1000', '200'),
        BantuanStokAwal::Baris($susu, '5000', '20'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    return ['Kopi' => $kopi, 'Susu' => $susu, 'Menu' => $menu];
}

function CatatTerbuang(Gudang $gudang, Produk $produk, string $jumlah, int $idPengguna, ?string $uuid = null): BahanTerbuang
{
    return DB::transaction(fn (): BahanTerbuang => app(CatatBahanTerbuang::class)->Jalankan(new DataBahanTerbuang(
        uuid: $uuid ?? strtoupper((string) Str::ulid()),
        idOutlet: $gudang->IdOutlet,
        idGudang: $gudang->Id,
        idPerangkat: null,
        idProduk: $produk->Id,
        jumlah: Kuantitas::Dari($jumlah),
        alasan: AlasanBahanTerbuang::SalahBuat,
        catatan: 'Pesanan salah ukuran',
        idPengguna: $idPengguna,
        sumber: BahanTerbuang::SUMBER_BACK_OFFICE,
        dibuatOfflinePada: null,
        tanggalBisnis: CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
    )));
}

/**
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemBahanTerbuang(array $k, Produk $produk, string $jumlah, mixed $pengguna = null, ?string $uuid = null): array
{
    return [
        'Jenis' => 'BahanTerbuang.Catat',
        'Uuid' => $uuid ?? BantuanKasir::Uuid(),
        'Data' => [
            'UuidProduk' => $produk->Uuid,
            'Jumlah' => $jumlah,
            'Alasan' => 'Tumpah',
            'Catatan' => 'Jatuh saat diantar',
            'UuidPengguna' => ($pengguna ?? $k['Supervisor'])->Uuid,
            'DibuatPada' => CarbonImmutable::now()->subMinute()->utc()->toIso8601ZuluString(),
        ],
    ];
}

describe('F-05f catat bahan terbuang', function (): void {
    it('menu resep diuraikan ke bahan, Susut bernilai HPP berjalan, jurnal J-05.4 seimbang, idempoten', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = SiapkanBahanTerbuang($t, $t['Gudang']);
        $uuid = strtoupper((string) Str::ulid());
        $catatan = CatatTerbuang($t['Gudang'], $p['Menu'], '2', $t['Pemilik']->Id, $uuid);
        $jurnal = Jurnal::query()->where('JenisSumber', 'BahanTerbuang')->sole();

        expect($catatan->Status)->toBe(StatusBahanTerbuang::Tercatat)
            ->and($catatan->Nilai)->toBe('13200.00')
            ->and($catatan->PerluTinjauan)->toBeFalse()
            ->and($catatan->IdJurnal)->toBe($jurnal->Id)
            ->and(B::Saldo($p['Kopi'], $t['Gudang']))->toBe(['964.0000', '192800.00'])
            ->and(B::Saldo($p['Susu'], $t['Gudang']))->toBe(['4700.0000', '94000.00'])
            ->and(MutasiStok::query()->where('JenisReferensi', 'BahanTerbuang')->pluck('JenisMutasi')->map->value->all())->toBe(['Susut', 'Susut'])
            ->and(B::BarisJurnal($jurnal->Id))->toEqualCanonicalizing([
                ['SusutPersediaan', '13200.00', '0.00', $t['Outlet']->Id],
                ['PersediaanBahanBaku', '0.00', '13200.00', $t['Outlet']->Id],
            ])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);

        CatatTerbuang($t['Gudang'], $p['Menu'], '2', $t['Pemilik']->Id, $uuid);
        expect(BahanTerbuang::query()->count())->toBe(1)
            ->and(MutasiStok::query()->where('JenisReferensi', 'BahanTerbuang')->count())->toBe(2)
            ->and(Jurnal::query()->where('JenisSumber', 'BahanTerbuang')->count())->toBe(1);
    });

    it('back-office menolak stok kurang, produk tanpa stok, konsinyasi & berpelacakan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = SiapkanBahanTerbuang($t, $t['Gudang']);
        $jenis = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $id = $t['Pemilik']->Id;

        expect(B::KodeGalat(fn () => CatatTerbuang($t['Gudang'], $p['Kopi'], '1001', $id)))->toBe('StokTidakCukup')
            ->and(B::KodeGalat(fn () => CatatTerbuang($t['Gudang'], $jenis['Jasa'], '1', $id)))->toBe('ProdukTanpaStok')
            ->and(B::KodeGalat(fn () => CatatTerbuang($t['Gudang'], $jenis['Konsinyasi'], '1', $id)))->toBe('ProdukKonsinyasi')
            ->and(B::KodeGalat(fn () => CatatTerbuang($t['Gudang'], $jenis['Batch'], '1', $id)))->toBe('PelacakanTidakDidukung')
            ->and(B::KodeGalat(fn () => CatatTerbuang($t['Gudang'], $p['Kopi'], '0', $id)))->toBe('JumlahTidakValid')
            ->and(BahanTerbuang::query()->count())->toBe(0)
            ->and(MutasiStok::query()->where('JenisReferensi', 'BahanTerbuang')->count())->toBe(0);
    });

    it('pembatalan mengembalikan stok pada nilai asal dan membalik jurnal; idempoten', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = SiapkanBahanTerbuang($t, $t['Gudang']);
        $catatan = CatatTerbuang($t['Gudang'], $p['Kopi'], '50', $t['Pemilik']->Id);

        expect(B::KodeGalat(fn () => app(BatalkanBahanTerbuang::class)->Jalankan($catatan, 'tdk', $t['Pemilik']->Id)))->toBe('AlasanTidakValid');

        $catatan = app(BatalkanBahanTerbuang::class)->Jalankan($catatan, 'Salah input, kopi tidak jadi dibuang', $t['Pemilik']->Id);
        app(BatalkanBahanTerbuang::class)->Jalankan($catatan, 'Salah input, kopi tidak jadi dibuang', $t['Pemilik']->Id);
        $jurnal = Jurnal::query()->where('JenisSumber', 'BahanTerbuang')->orderBy('Id')->get();

        expect($catatan->Status)->toBe(StatusBahanTerbuang::Dibatalkan)
            ->and($catatan->IdJurnalPembatalan)->toBe($jurnal[1]->Id)
            ->and(B::Saldo($p['Kopi'], $t['Gudang']))->toBe(['1000.0000', '200000.00'])
            ->and(MutasiStok::query()->where('JenisReferensi', 'BahanTerbuang')->count())->toBe(2)
            ->and($jurnal)->toHaveCount(2)
            ->and(B::BarisJurnal($jurnal[1]->Id))->toEqualCanonicalizing([
                ['SusutPersediaan', '0.00', '10000.00', $t['Outlet']->Id],
                ['PersediaanBahanBaku', '10000.00', '0.00', $t['Outlet']->Id],
            ])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

describe('F-05f outbox kasir BahanTerbuang.Catat', function (): void {
    it('dicatat dari lokasi Toko, idempoten, stok kurang & tanpa izin jadi tinjauan', function (): void {
        /** @var TestCase $this */
        $k = BantuanPenjualan::Siapkan($this, 'Kafe Kopi Senja Terbuang');
        $p = SiapkanBahanTerbuang($k, $k['Gudang']);
        $item = ItemBahanTerbuang($k, $p['Menu'], '1');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Uuid sama dengan jumlah berbeda bukan kiriman ulang: ditolak, tidak diam-diam dianggap Duplikat.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemBahanTerbuang($k, $p['Menu'], '2', null, $item['Uuid'])]))->toBe([['Ditolak', 'UuidSudahDipakai']]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $catatan = BahanTerbuang::query()->sole();
        expect($catatan->Sumber)->toBe(BahanTerbuang::SUMBER_POS)
            ->and($catatan->IdPerangkat)->toBe($k['Perangkat']->Id)
            ->and($catatan->Alasan)->toBe(AlasanBahanTerbuang::Tumpah)
            ->and($catatan->Nilai)->toBe('6600.00')
            ->and($catatan->PerluTinjauan)->toBeFalse()
            ->and(B::Saldo($p['Kopi'], $k['Gudang']))->toBe(['982.0000', '196400.00']);

        // Kasir tanpa izin & stok kurang: tetap dicatat (kejadian sudah terjadi) tetapi perlu ditinjau.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemBahanTerbuang($k, $p['Susu'], '6000', $k['Kasir'])]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kedua = BahanTerbuang::query()->orderByDesc('Id')->firstOrFail();

        expect($kedua->PerluTinjauan)->toBeTrue()
            ->and($kedua->AlasanTinjauan)->toContain('IzinBerubah')
            ->and($kedua->AlasanTinjauan)->toContain('StokTidakCukup')
            ->and(B::Saldo($p['Susu'], $k['Gudang'])[0])->toBe('-1150.0000');
    });

    it('menolak produk tidak dikenal dan waktu di masa depan', function (): void {
        /** @var TestCase $this */
        $k = BantuanPenjualan::Siapkan($this, 'Kafe Kopi Senja Tolak');
        $p = SiapkanBahanTerbuang($k, $k['Gudang']);
        $asing = ItemBahanTerbuang($k, $p['Kopi'], '1');
        $asing['Data']['UuidProduk'] = BantuanKasir::Uuid();
        $masaDepan = ItemBahanTerbuang($k, $p['Kopi'], '1');
        $masaDepan['Data']['DibuatPada'] = CarbonImmutable::now()->addHour()->utc()->toIso8601ZuluString();

        $hasil = BantuanKasir::KirimRingkas($this, $k['Token'], [$asing, $masaDepan]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(array_column($hasil, 0))->toBe(['Ditolak', 'Ditolak'])
            ->and(BahanTerbuang::query()->count())->toBe(0);
    });
});

describe('F-05f HTTP back-office', function (): void {
    it('catat & batalkan lewat rute, daftar + ringkasan food cost, isolasi tenant', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = SiapkanBahanTerbuang($t, $t['Gudang']);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/persediaan/bahan-terbuang', [
            'UuidGudang' => $t['Gudang']->Uuid,
            'UuidProduk' => $p['Menu']->Uuid,
            'Jumlah' => '3',
            'Alasan' => 'Kedaluwarsa',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $catatan = BahanTerbuang::query()->sole();
        expect($catatan->Nilai)->toBe('19800.00')->and($catatan->Sumber)->toBe(BahanTerbuang::SUMBER_BACK_OFFICE);

        $this->get('/kelola/persediaan/bahan-terbuang')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/BahanTerbuang/Daftar')
            ->where('BahanTerbuang.Data.0.Uuid', $catatan->Uuid)
            ->where('Ringkasan.NilaiTerbuang', '19800.00')
            ->where('Izin.Catat', true)
            ->where('Izin.Batalkan', true));

        $this->post("/kelola/persediaan/bahan-terbuang/{$catatan->Uuid}/batalkan", ['Alasan' => 'Ternyata masih layak dijual'])
            ->assertRedirect()->assertSessionHasNoErrors();
        expect($catatan->refresh()->Status)->toBe(StatusBahanTerbuang::Dibatalkan)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);

        $lain = BantuanPersediaan::SiapkanTenant('Kafe Lain Sentosa');
        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->post("/kelola/persediaan/bahan-terbuang/{$catatan->Uuid}/batalkan", ['Alasan' => 'Coba batalkan milik orang'])->assertNotFound();
        $this->post('/kelola/persediaan/bahan-terbuang', [
            'UuidGudang' => $t['Gudang']->Uuid,
            'UuidProduk' => $p['Menu']->Uuid,
            'Jumlah' => '1',
            'Alasan' => 'Rusak',
        ])->assertNotFound();
    });

    it('kasir tanpa izin tidak bisa mencatat dari back-office', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = SiapkanBahanTerbuang($t, $t['Gudang']);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);

        $this->post('/kelola/persediaan/bahan-terbuang', [
            'UuidGudang' => $t['Gudang']->Uuid,
            'UuidProduk' => $p['Kopi']->Uuid,
            'Jumlah' => '1',
            'Alasan' => 'Rusak',
        ])->assertForbidden();
    });
});
