<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Promo\Model\Promo;
use App\Domain\Promo\Model\PromoPemakaian;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16c bagian 3: promo metode bayar (semua pembayaran), ulang tahun, transaksi pertama, dan batas per pelanggan
 * dinilai ulang server dari data server (beda = diterima + tinjauan `PromoBerbeda`); data pelanggan untuk POS
 * (`HariLahir`, `JumlahTransaksi`, `PemakaianPromo`); promo bersyarat baru hanya untuk aplikasi `?lanjutan=1`;
 * formulir back-office.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $syarat  kunci Definisi bagian 3
 */
function BuatPromoLanjutan(string $kode, array $syarat, string $potongan = '5000'): Promo
{
    return Promo::query()->create([
        'Kode' => $kode,
        'Nama' => "Promo {$kode}",
        'Prioritas' => 1,
        'Definisi' => [
            'Hari' => [], 'JamMulai' => null, 'JamSelesai' => null, 'Outlet' => [], 'Kanal' => [], 'Tier' => [],
            'MinimalSubtotal' => '0.00',
            'Kondisi' => ['Jenis' => 'Semua', 'Uuid' => [], 'JumlahMinimal' => '0.0000'],
            'Aksi' => ['Jenis' => 'DiskonTetapPesanan', 'Jumlah' => $potongan],
            'BatasPerTransaksi' => null,
            ...$syarat,
        ],
    ]);
}

/**
 * Penjualan 2 × Rp 38.500 dengan potongan pesanan promo perangkat.
 *
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $opsi
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemPromoLanjutan(array $k, ?Promo $promo, array $opsi = [], array $timpa = []): array
{
    return BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']],
        'Promo' => $promo === null ? [] : [['Promo' => $promo, 'Baris' => [], 'Pesanan' => '5000.00']],
        ...$opsi,
    ], $timpa);
}

/**
 * @return array<string, mixed>
 */
function SiapkanPromoLanjutan(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kopi Senja Promo Lanjutan');

    return $k + ['Produk' => BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id)];
}

describe('F-16c bagian 3 promo bersyarat di penjualan POS', function (): void {
    it('metode bayar: dibayar penuh QRIS = sesuai server. Dibayar tunai padahal promo khusus QRIS = diterima + tinjauan', function (): void {
        $k = SiapkanPromoLanjutan($this);
        $promo = BuatPromoLanjutan('QRIS5K', ['MetodeBayar' => [$k['Qris']->Uuid]]);

        $qris = ItemPromoLanjutan($k, $promo, ['Pembayaran' => [['Metode' => $k['Qris'], 'Jumlah' => '72000.00']]]);
        $tunai = ItemPromoLanjutan($k, $promo);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$qris, $tunai]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $jualQris = Penjualan::query()->where('Uuid', $qris['Uuid'])->sole();
        $jualTunai = Penjualan::query()->where('Uuid', $tunai['Uuid'])->sole();
        expect($jualQris->PerluTinjauan)->toBeFalse()
            ->and((string) $jualQris->TotalAkhir)->toBe('72000.00')
            ->and($jualTunai->PerluTinjauan)->toBeTrue()
            ->and($jualTunai->AlasanTinjauan)->toContain('PromoBerbeda: perangkat: QRIS5K Rp 5.000; server: tanpa promo')
            // Penjualan memakai hitungan perangkat; pemakaian tetap tercatat.
            ->and((string) $jualTunai->TotalAkhir)->toBe('72000.00')
            ->and(PromoPemakaian::query()->count())->toBe(2);
    });

    it('transaksi pertama & ulang tahun dari data server. Transaksi kedua memakai promo transaksi pertama = tinjauan', function (): void {
        $k = SiapkanPromoLanjutan($this);
        $hariIni = CarbonImmutable::now($k['Outlet']->ZonaWaktu);
        $ani = Pelanggan::query()->create(['Nama' => 'Ani Rahmawati', 'NoHp' => '6281234567890', 'TanggalLahir' => $hariIni->subYears(30)->toDateString()]);
        $pertama = BuatPromoLanjutan('PERTAMA5K', ['TransaksiPertama' => true]);

        $satu = ItemPromoLanjutan($k, $pertama, timpa: ['UuidPelanggan' => $ani->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$satu]))->toBe([['Diterima', null]]);
        $dua = ItemPromoLanjutan($k, $pertama, ['DibuatPada' => CarbonImmutable::now()->subMinutes(3)], ['UuidPelanggan' => $ani->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$dua]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $satu['Uuid'])->sole()->PerluTinjauan)->toBeFalse()
            ->and(Penjualan::query()->where('Uuid', $dua['Uuid'])->sole()->AlasanTinjauan)->toContain('perangkat: PERTAMA5K Rp 5.000; server: tanpa promo');

        // Ulang tahun hari ini (zona outlet): promo hari-H berlaku di server tanpa tinjauan.
        $pertama->forceFill(['Status' => 'Diarsipkan'])->save();
        $ultah = BuatPromoLanjutan('ULTAH5K', ['UlangTahun' => ['Jenis' => 'Hari']]);
        $tiga = ItemPromoLanjutan($k, $ultah, ['DibuatPada' => CarbonImmutable::now()->subMinute()], ['UuidPelanggan' => $ani->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tiga]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $tiga['Uuid'])->sole()->PerluTinjauan)->toBeFalse();

        // Data pelanggan untuk POS: tanpa tahun lahir, jumlah transaksi & pemakaian promo hari ini.
        $cari = $this->withToken($k['Token'])->getJson('/api/pos/v1/pelanggan?kata=Ani')->assertOk();
        expect($cari->json('TanggalBisnis'))->toBe($hariIni->subHours(4)->toDateString())
            ->and($cari->json('Pelanggan.0.HariLahir'))->toBe($hariIni->format('m-d'))
            ->and($cari->json('Pelanggan.0.JumlahTransaksi'))->toBe(3)
            ->and($cari->json('Pelanggan.0.PemakaianPromo'))->toBe([
                $pertama->Uuid => ['Hari' => 2, 'Promo' => 2],
                $ultah->Uuid => ['Hari' => 1, 'Promo' => 1],
            ]);
    });

    it('batas per pelanggan per hari, tanpa pelanggan promo berbatas tidak berlaku', function (): void {
        $k = SiapkanPromoLanjutan($this);
        $ani = Pelanggan::query()->create(['Nama' => 'Ani Rahmawati', 'NoHp' => '6281234567890']);
        $harian = BuatPromoLanjutan('HARIAN5K', ['BatasPerPelanggan' => ['Jumlah' => 1, 'Periode' => 'Hari']]);

        $satu = ItemPromoLanjutan($k, $harian, timpa: ['UuidPelanggan' => $ani->Uuid]);
        $dua = ItemPromoLanjutan($k, $harian, timpa: ['UuidPelanggan' => $ani->Uuid]);
        $tanpa = ItemPromoLanjutan($k, null);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$satu, $dua, $tanpa]))->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $satu['Uuid'])->sole()->PerluTinjauan)->toBeFalse()
            ->and(Penjualan::query()->where('Uuid', $dua['Uuid'])->sole()->AlasanTinjauan)->toContain('perangkat: HARIAN5K Rp 5.000; server: tanpa promo')
            ->and(Penjualan::query()->where('Uuid', $tanpa['Uuid'])->sole()->PerluTinjauan)->toBeFalse();
    });

    it('GET /api/pos/v1/promo: promo bersyarat bagian 3 hanya untuk aplikasi yang meminta ?lanjutan=1', function (): void {
        $k = SiapkanPromoLanjutan($this);
        BuatPromoLanjutan('BIASA5K', []);
        BuatPromoLanjutan('QRIS5K', ['MetodeBayar' => [$k['Qris']->Uuid]]);

        $lama = $this->withToken($k['Token'])->getJson('/api/pos/v1/promo')->assertOk();
        $baru = $this->withToken($k['Token'])->getJson('/api/pos/v1/promo?lanjutan=1')->assertOk();
        expect(array_column($lama->json('Promo'), 'Kode'))->toBe(['BIASA5K'])
            ->and(array_column($baru->json('Promo'), 'Kode'))->toBe(['BIASA5K', 'QRIS5K']);
    });
});

describe('F-16c bagian 3 formulir promo', function (): void {
    it('menyimpan syarat bagian 3 ke Definisi. Validasi jarak ulang tahun, batas, dan metode bayar', function (): void {
        $k = SiapkanPromoLanjutan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $isian = IsianPromoLanjutan(['MetodeBayar' => [$k['Qris']->Uuid], 'UlangTahun' => 'Rentang', 'HariUlangTahun' => 7, 'TransaksiPertama' => true, 'BatasPerPelanggan' => 2, 'PeriodeBatasPelanggan' => 'Promo']);

        $this->post('/kelola/promo', [...$isian, 'HariUlangTahun' => 0])->assertSessionHasErrors(['HariUlangTahun' => 'Isi jarak 1–30 hari dari hari ulang tahun.']);
        $this->post('/kelola/promo', [...$isian, 'BatasPerPelanggan' => 0])->assertSessionHasErrors(['BatasPerPelanggan' => 'Batas per pelanggan 1–999 kali.']);
        $this->post('/kelola/promo', [...$isian, 'MetodeBayar' => ['01K5ZZZZZZZZZZZZZZZZZZZZZZ']])->assertSessionHasErrors(['MetodeBayar' => 'Pilih metode pembayaran dari daftar.']);
        $this->post('/kelola/promo', $isian)->assertRedirect('/kelola/promo');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $definisi = Promo::query()->where('Kode', 'LANJUT10')->sole()->Definisi;
        expect($definisi['MetodeBayar'])->toBe([$k['Qris']->Uuid])
            ->and($definisi['UlangTahun'])->toEqual(['Jenis' => 'Rentang', 'Hari' => 7])
            ->and($definisi['TransaksiPertama'])->toBeTrue()
            ->and($definisi['BatasPerPelanggan'])->toEqual(['Jumlah' => 2, 'Periode' => 'Promo']);

        // Promo tanpa syarat bagian 3 tidak menulis kuncinya (tetap terkirim ke aplikasi lama).
        $this->post('/kelola/promo', IsianPromoLanjutan(['Kode' => 'POLOS10']))->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Promo::query()->where('Kode', 'POLOS10')->sole()->CekSyaratLanjutan())->toBeFalse();
    });
});

/**
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsianPromoLanjutan(array $timpa = []): array
{
    return [
        'Kode' => 'LANJUT10', 'Nama' => 'Promo pelanggan setia', 'Prioritas' => 1, 'Eksklusif' => false,
        'TanggalMulai' => null, 'TanggalSelesai' => null, 'Kuota' => null, 'Hari' => [], 'JamMulai' => null, 'JamSelesai' => null,
        'Outlet' => [], 'Kanal' => [], 'Tier' => [], 'MinimalSubtotal' => '0', 'JenisKondisi' => 'Semua', 'UuidKondisi' => [],
        'JumlahMinimal' => '0', 'JenisAksi' => 'DiskonPersenPesanan', 'Persen' => '10',
        ...$timpa,
    ];
}
