<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Penjualan\Model\PesananSendiri;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesanSendiri;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-17 v2.06 (keputusan pemilik produk): halaman tamu menampilkan perkiraan total lengkap (biaya layanan & pajak dari
 * mesin kalkulasi yang sama dengan kasir, tarif dari `TarifPajak` bertanggal) dan produk bervarian bisa dipesan
 * (baris menyimpan anak varian yang benar-benar dijual).
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

/**
 * Es teh dengan varian Ukuran Reguler/Jumbo (+ satu varian tanpa harga) di tenant `$k`.
 *
 * @param  array<string, mixed>  $k
 * @return array{Induk: Produk, Reguler: Produk, Jumbo: Produk, TanpaHarga: Produk}
 */
function BuatEsTehBervarian(array $k): array
{
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $induk = BantuanKatalog::BuatProduk([
        'Jenis' => JenisProduk::IndukVarian,
        'Nama' => 'Es Teh Manis Melati',
        'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['Reguler', 'Jumbo', 'Raksasa']]],
    ], harga: null);
    $anak = fn (string $ukuran, ?string $harga) => BantuanKatalog::BuatProduk([
        'Jenis' => JenisProduk::NonStok,
        'IdInduk' => $induk->Id,
        'Nama' => "Es Teh Manis Melati {$ukuran}",
        'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => $ukuran]],
        'KunciVarian' => 'ukuran='.strtolower($ukuran),
    ], $harga);

    return ['Induk' => $induk, 'Reguler' => $anak('Reguler', '8000.00'), 'Jumbo' => $anak('Jumbo', '12000.00'), 'TanpaHarga' => $anak('Raksasa', null)];
}

describe('F-17 v2.06 perkiraan total', function (): void {
    it('PBJT 10% dari DPP subtotal + biaya layanan 5% (tarif kota outlet): rincian & total sama dengan mesin kasir. Tersimpan di pesanan', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $k['Outlet']->forceFill(['KodeKota' => '33.72'])->save();
        BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000', true);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanPenjualan::AturProfilPajak($k, pungutPbjt: true, persenBiayaLayanan: '5.00');
        BantuanPenjualan::PasangKelompokPajak('Makan & minum PBJT', ['PbjtMakananMinuman' => 'SubtotalPlusLayanan'], $k['Kopi'], $k['Nasi']);

        // Kopi 25.000 + pilihan (gula sedikit 0, boba 5.000, keju 6.000) = 36.000 × 2 = 72.000; nasi 35.000.
        $respons = $this->postJson("{$k['Alamat']}/hitung", ['Baris' => [
            ['UuidProduk' => $k['Kopi']->Uuid, 'Jumlah' => 2, 'Pilihan' => [$k['GulaSedikit']->Uuid, $k['Boba']->Uuid, $k['Keju']->Uuid]],
            ['UuidProduk' => $k['Nasi']->Uuid, 'Jumlah' => 1],
        ]])->assertOk();

        // Subtotal 107.000; layanan 5.350; DPP 112.350; PBJT 11.235; total 123.585.
        expect($respons->json('Subtotal'))->toBe('107000.00')
            ->and($respons->json('BiayaLayanan'))->toBe('5350.00')
            ->and($respons->json('Pajak'))->toBe([['Kode' => 'PbjtMakananMinuman', 'Nama' => $respons->json('Pajak.0.Nama'), 'Tarif' => '10', 'Jumlah' => '11235.00']])
            ->and($respons->json('Pembulatan'))->toBe('0.00')
            ->and($respons->json('Total'))->toBe('123585.00');

        $kiriman = BantuanPesanSendiri::Kiriman([[$k['Kopi'], 2, [$k['GulaSedikit'], $k['Boba'], $k['Keju']]], [$k['Nasi'], 1]]);
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertCreated();
        $status = $this->getJson("{$k['Alamat']}/pesanan/{$kiriman['Uuid']}")->assertOk();
        expect($status->json('Perkiraan.Total'))->toBe('123585.00')
            ->and($status->json('Perkiraan.BiayaLayanan'))->toBe('5350.00');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PesananSendiri::query()->sole()->Perkiraan['Total'])->toBe('123585.00');
    });

    it('tanpa tarif terbit pajak tidak dihitung (tidak di-hard-code). Harga termasuk pajak tidak menambah total', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanPenjualan::AturProfilPajak($k, pungutPbjt: true);
        BantuanPenjualan::PasangKelompokPajak('Makan & minum PBJT', ['PbjtMakananMinuman' => 'Subtotal'], $k['Nasi']);

        $tanpaTarif = $this->postJson("{$k['Alamat']}/hitung", ['Baris' => [['UuidProduk' => $k['Nasi']->Uuid, 'Jumlah' => 1]]])->assertOk();
        expect($tanpaTarif->json('Pajak'))->toBe([])->and($tanpaTarif->json('Total'))->toBe('35000.00');

        $k['Outlet']->refresh()->forceFill(['KodeKota' => '33.72'])->save();
        BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanPenjualan::AturProfilPajak($k, pungutPbjt: true, hargaTermasukPajak: true);
        $termasuk = $this->postJson("{$k['Alamat']}/hitung", ['Baris' => [['UuidProduk' => $k['Nasi']->Uuid, 'Jumlah' => 1]]])->assertOk();
        expect($termasuk->json('Total'))->toBe('35000.00')
            ->and($termasuk->json('PajakTermasukHarga'))->not->toBe('0.00');
    });
});

describe('F-17 v2.06 varian', function (): void {
    it('menu: induk tampil satu kartu dengan varian & harga masing-masing, varian tanpa harga tidak tersedia, harga kartu termurah', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $v = BuatEsTehBervarian($k);

        $this->get($k['Alamat'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Menu.Produk', fn ($produk) => collect($produk)->where('Uuid', $v['Induk']->Uuid)->count() === 1
                && collect($produk)->where('Uuid', $v['Reguler']->Uuid)->isEmpty()
                && collect($produk)->firstWhere('Uuid', $v['Induk']->Uuid)['Harga'] === '8000.00'
                && collect($produk)->firstWhere('Uuid', $v['Induk']->Uuid)['NamaAtributVarian'] === 'Ukuran'
                && collect(collect($produk)->firstWhere('Uuid', $v['Induk']->Uuid)['Varian'])->map(fn ($x) => [$x['Nama'], $x['Harga'], $x['Tersedia']])->all() === [
                    ['Reguler', '8000.00', true],
                    ['Jumbo', '12000.00', true],
                    ['Raksasa', null, false],
                ]));
    });

    it('pesan varian: baris menyimpan anak varian + nama "Induk — Varian"; API POS memuat anak. Varian wajib & harus milik induk', function (): void {
        $k = BantuanPesanSendiri::Siapkan($this);
        $v = BuatEsTehBervarian($k);
        $lain = BuatEsTehBervarian($k);

        $hitung = $this->postJson("{$k['Alamat']}/hitung", ['Baris' => [['UuidProduk' => $v['Induk']->Uuid, 'UuidVarian' => $v['Jumbo']->Uuid, 'Jumlah' => 2]]])->assertOk();
        expect($hitung->json('Baris.0'))->toMatchArray([
            'UuidProduk' => $v['Jumbo']->Uuid,
            'UuidProdukInduk' => $v['Induk']->Uuid,
            'NamaVarian' => 'Jumbo',
            'NamaProduk' => 'Es Teh Manis Melati — Jumbo',
            'HargaSatuan' => '12000.00',
            'Total' => '24000.00',
        ]);

        $galat = fn (array $baris) => $this->postJson("{$k['Alamat']}/hitung", ['Baris' => [$baris]])->assertStatus(422)->json('Galat.Kode');
        expect($galat(['UuidProduk' => $v['Induk']->Uuid, 'Jumlah' => 1]))->toBe('VarianWajibDipilih')
            ->and($galat(['UuidProduk' => $v['Induk']->Uuid, 'UuidVarian' => $lain['Jumbo']->Uuid, 'Jumlah' => 1]))->toBe('VarianTidakValid')
            ->and($galat(['UuidProduk' => $v['Induk']->Uuid, 'UuidVarian' => $v['TanpaHarga']->Uuid, 'Jumlah' => 1]))->toBe('VarianTidakValid')
            ->and($galat(['UuidProduk' => $k['Nasi']->Uuid, 'UuidVarian' => $v['Jumbo']->Uuid, 'Jumlah' => 1]))->toBe('VarianTidakValid')
            ->and($galat(['UuidProduk' => $v['Jumbo']->Uuid, 'Jumlah' => 1]))->toBe('ProdukTidakTersedia');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $v['Reguler']->forceFill(['Aktif' => false])->save();
        expect($galat(['UuidProduk' => $v['Induk']->Uuid, 'UuidVarian' => $v['Reguler']->Uuid, 'Jumlah' => 1]))->toBe('VarianTidakValid');

        $kiriman = BantuanPesanSendiri::Kiriman([[$v['Induk'], 2, [], 'Es sedikit', $v['Jumbo']]]);
        $this->postJson("{$k['Alamat']}/pesan", $kiriman)->assertCreated();

        $pos = $this->withToken($k['Token'])->getJson('/api/pos/v1/pesan-sendiri')->assertOk();
        expect($pos->json('Pesanan.0.Baris.0'))->toMatchArray([
            'UuidProduk' => $v['Jumbo']->Uuid,
            'NamaProduk' => 'Es Teh Manis Melati — Jumbo',
            'HargaSatuan' => '12000.00',
            'Catatan' => 'Es sedikit',
        ]);
    });
});
