<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\LokasiProduksi;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function SaldoLokasiProduksi(int $idProduk, int $idGudang): ?string
{
    return SaldoStok::query()->where('IdProduk', $idProduk)->where('IdGudang', $idGudang)->value('JumlahTersedia');
}

/**
 * @return array{0: array<string, mixed>, 1: Produk, 2: Produk}
 */
function SiapkanResepLokasiProduksi(mixed $uji, ?LokasiProduksi $lokasi, ?JenisGudang $jenisGudangProduksi): array
{
    $k = BantuanPenjualan::Siapkan($uji);
    $susu = BantuanKomposisi::BuatBahan('Susu Segar Karamel Dingin');
    BantuanPenjualan::IsiStok($k['Gudang'], $susu, '1000', '50', $k['Pemilik']->Id);
    $esKopi = BantuanKomposisi::BuatProdukResep();
    BantuanKomposisi::SimpanResep($esKopi, [[$susu, '15']]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    Produk::query()->whereKey($esKopi->Id)->update(['LokasiProduksi' => $lokasi?->value]);

    if ($jenisGudangProduksi !== null) {
        $k['GudangProduksi'] = BantuanPersediaan::BuatGudang($k['Outlet'], $jenisGudangProduksi->AmbilLabel(), $jenisGudangProduksi);
        BantuanPenjualan::IsiStok($k['GudangProduksi'], $susu, '500', '50', $k['Pemilik']->Id);
    }

    return [$k, $susu, $esKopi];
}

describe('BR-05.4 bahan resep dikurangi dari lokasi produksi', function (): void {
    it('resep berlokasi Dapur: bahan keluar dari lokasi Dapur outlet, lokasi Toko tidak berubah. Void mengembalikan ke Dapur. Invariant terjaga', function (): void {
        [$k, $susu, $esKopi] = SiapkanResepLokasiProduksi($this, LokasiProduksi::Dapur, JenisGudang::Dapur);

        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $esKopi, 'Jumlah' => '2', 'Harga' => '25000.00']]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penjualan = Penjualan::query()->sole();

        expect(SaldoLokasiProduksi($susu->Id, $k['GudangProduksi']->Id))->toBe('470.0000')
            ->and(SaldoLokasiProduksi($susu->Id, $k['Gudang']->Id))->toBe('1000.0000')
            ->and(MutasiStok::query()->where('IdProduk', $susu->Id)->where('JenisMutasi', JenisMutasi::Penjualan->value)->pluck('IdGudang')->unique()->all())->toBe([$k['GudangProduksi']->Id])
            ->and($penjualan->TotalHpp)->toBe('1500.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $penjualan)]))->toBe([['Diterima', null]]);

        expect(SaldoLokasiProduksi($susu->Id, $k['GudangProduksi']->Id))->toBe('500.0000')
            ->and(SaldoLokasiProduksi($susu->Id, $k['Gudang']->Id))->toBe('1000.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('lokasi Bar memakai lokasi Bar. Resep tanpa lokasi produksi tetap mengurangi lokasi Toko (perilaku lama)', function (): void {
        [$k, $susu, $esKopi] = SiapkanResepLokasiProduksi($this, LokasiProduksi::Bar, JenisGudang::Bar);
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $esKopi, 'Jumlah' => '1', 'Harga' => '25000.00']]]);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoLokasiProduksi($susu->Id, $k['GudangProduksi']->Id))->toBe('485.0000')
            ->and(SaldoLokasiProduksi($susu->Id, $k['Gudang']->Id))->toBe('1000.0000');

        Produk::query()->whereKey($esKopi->Id)->update(['LokasiProduksi' => null]);
        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $esKopi, 'Jumlah' => '1', 'Harga' => '25000.00']]])]);

        expect(SaldoLokasiProduksi($susu->Id, $k['GudangProduksi']->Id))->toBe('485.0000')
            ->and(SaldoLokasiProduksi($susu->Id, $k['Gudang']->Id))->toBe('985.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('outlet tidak punya lokasi Dapur aktif: penjualan tetap diterima dan jatuh ke lokasi Toko', function (): void {
        [$k, $susu, $esKopi] = SiapkanResepLokasiProduksi($this, LokasiProduksi::Dapur, null);

        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $esKopi, 'Jumlah' => '1', 'Harga' => '25000.00']]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoLokasiProduksi($susu->Id, $k['Gudang']->Id))->toBe('985.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('bahan terbuang dari kasir untuk menu berlokasi Dapur mengurangi bahan di lokasi Dapur dan jurnalnya seimbang', function (): void {
        [$k, $susu, $esKopi] = SiapkanResepLokasiProduksi($this, LokasiProduksi::Dapur, JenisGudang::Dapur);
        $item = [
            'Jenis' => 'BahanTerbuang.Catat',
            'Uuid' => BantuanKasir::Uuid(),
            'Data' => [
                'UuidProduk' => $esKopi->Uuid,
                'Jumlah' => '2',
                'Alasan' => 'Tumpah',
                'Catatan' => 'Jatuh saat diantar',
                'UuidPengguna' => $k['Supervisor']->Uuid,
                'DibuatPada' => CarbonImmutable::now()->subMinute()->utc()->toIso8601ZuluString(),
            ],
        ];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoLokasiProduksi($susu->Id, $k['GudangProduksi']->Id))->toBe('470.0000')
            ->and(SaldoLokasiProduksi($susu->Id, $k['Gudang']->Id))->toBe('1000.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });
});
