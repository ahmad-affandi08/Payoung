<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Layanan\PelacakNomorSeri;
use App\Domain\Persediaan\Model\NomorSeri;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Persediaan\BantuanPelacakan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Kode galat `PelanggaranAturanBisnis` dari `$jalankan`, atau null bila tidak melempar. */
function AmbilKodeGalatSeriUji(Closure $jalankan): ?string
{
    try {
        $jalankan();
    } catch (PelanggaranAturanBisnis $e) {
        return $e->kode;
    }

    return null;
}

describe('F-05a PelacakNomorSeri (DesainF05a C.4, F-05h)', function (): void {
    it('KunciMasuk membuat nomor seri (belum di stok) dan menguncinya; TandaiMasuk menjadikannya Tersedia di lokasi stok', function (): void {
        $t = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $rice = $t['Produk']['Seri'];
        $pelacak = app(PelacakNomorSeri::class);
        $kueri = [];
        DB::listen(function (QueryExecuted $q) use (&$kueri): void {
            $kueri[] = $q->sql;
        });

        $seri = DB::transaction(fn (): NomorSeri => $pelacak->KunciMasuk($rice->Id, $t['Gudang']->Id, '  RC18-2609-000123  '));
        expect($seri->Nomor)->toBe('RC18-2609-000123')
            ->and($seri->Status)->toBe(StatusNomorSeri::Keluar)
            ->and($seri->IdGudang)->toBeNull()
            ->and($seri->Uuid)->toHaveLength(26)
            ->and(collect($kueri)->contains(fn (string $sql): bool => str_contains($sql, 'from `NomorSeri`') && str_contains($sql, 'for update')))->toBeTrue();

        $pelacak->TandaiMasuk($seri, $t['Gudang']->Id);
        $seri->refresh();
        expect($seri->Status)->toBe(StatusNomorSeri::Tersedia)->and($seri->IdGudang)->toBe($t['Gudang']->Id);
    });

    it('nomor seri yang masih Tersedia (juga di lokasi lain) atau dalam perjalanan ditolak NomorSeriSudahAda', function (): void {
        $t = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $rice = $t['Produk']['Seri'];
        $pelacak = app(PelacakNomorSeri::class);
        $pelacak->TandaiMasuk($pelacak->KunciMasuk($rice->Id, $t['Gudang']->Id, 'RC18-2609-000123'), $t['Gudang']->Id);

        expect(AmbilKodeGalatSeriUji(fn () => $pelacak->KunciMasuk($rice->Id, $t['Gudang']->Id, 'RC18-2609-000123')))->toBe('NomorSeriSudahAda')
            ->and(AmbilKodeGalatSeriUji(fn () => $pelacak->KunciMasuk($rice->Id, $t['GudangBelakang']->Id, 'RC18-2609-000123')))->toBe('NomorSeriSudahAda');

        $jalan = $pelacak->KunciMasuk($rice->Id, $t['Gudang']->Id, 'RC18-2609-000124');
        $pelacak->TandaiKeluar($jalan, StatusNomorSeri::DalamPerjalanan);
        expect(fn () => $pelacak->KunciMasuk($rice->Id, $t['GudangBelakang']->Id, 'RC18-2609-000124'))
            ->toThrow(PelanggaranAturanBisnis::class, 'Nomor seri RC18-2609-000124 sedang dalam perjalanan transfer.');
    });

    it('H-19: nomor seri unik per produk, bukan per tenant. Tenant lain bebas memakai nomor yang sama (isolasi tenant)', function (): void {
        $a = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $pelacak = app(PelacakNomorSeri::class);
        $blender = BantuanKatalog::BuatProduk(['Nama' => 'Blender Kaca 1,5 Liter (nomor seri)', 'Pelacakan' => 'Seri'], '425000.00', $a['Pcs']);
        $seriA = $pelacak->KunciMasuk($a['Produk']['Seri']->Id, $a['Gudang']->Id, 'SN-0001');
        $pelacak->TandaiMasuk($seriA, $a['Gudang']->Id);
        $pelacak->TandaiMasuk($pelacak->KunciMasuk($blender->Id, $a['Gudang']->Id, 'SN-0001'), $a['Gudang']->Id);
        expect(NomorSeri::query()->where('Nomor', 'SN-0001')->count())->toBe(2);

        $b = BantuanPelacakan::SiapkanTenant('Toko Elektronik Sebelah');
        $seriB = $pelacak->KunciMasuk($b['Produk']['Seri']->Id, $b['Gudang']->Id, 'SN-0001');
        $pelacak->TandaiMasuk($seriB, $b['Gudang']->Id);
        expect(NomorSeri::query()->count())->toBe(1)->and($seriB->refresh()->IdTenant)->toBe($b['Tenant']->Id);

        // Dari tenant B, nomor seri tenant A tidak terlihat.
        expect(AmbilKodeGalatSeriUji(fn () => $pelacak->KunciKeluar($seriA->Id, $a['Produk']['Seri']->Id, $a['Gudang']->Id)))->toBe('NomorSeriTidakTersedia');
    });

    it('nomor seri Terjual/Keluar diaktifkan kembali (baris yang sama) saat masuk lagi', function (): void {
        $t = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $rice = $t['Produk']['Seri'];
        $pelacak = app(PelacakNomorSeri::class);
        $seri = $pelacak->KunciMasuk($rice->Id, $t['Gudang']->Id, 'RC18-2609-000123');
        $pelacak->TandaiMasuk($seri, $t['Gudang']->Id);

        $keluar = $pelacak->KunciKeluar($seri->Id, $rice->Id, $t['Gudang']->Id);
        $pelacak->TandaiKeluar($keluar, StatusNomorSeri::Terjual);
        expect($seri->refresh()->Status)->toBe(StatusNomorSeri::Terjual)->and($seri->IdGudang)->toBeNull();

        $lagi = $pelacak->KunciMasuk($rice->Id, $t['GudangBelakang']->Id, 'RC18-2609-000123');
        $pelacak->TandaiMasuk($lagi, $t['GudangBelakang']->Id);
        expect($lagi->Id)->toBe($seri->Id)
            ->and($seri->refresh()->Status)->toBe(StatusNomorSeri::Tersedia)
            ->and($seri->IdGudang)->toBe($t['GudangBelakang']->Id)
            ->and(NomorSeri::query()->count())->toBe(1);
    });

    it('KunciKeluar: nomor seri wajib Tersedia di lokasi stok itu dan milik produk itu (NomorSeriTidakTersedia)', function (): void {
        $t = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $rice = $t['Produk']['Seri'];
        $pelacak = app(PelacakNomorSeri::class);
        $seri = $pelacak->KunciMasuk($rice->Id, $t['Gudang']->Id, 'RC18-2609-000123');

        expect(AmbilKodeGalatSeriUji(fn () => $pelacak->KunciKeluar($seri->Id, $rice->Id, $t['Gudang']->Id)))->toBe('NomorSeriTidakTersedia');

        $pelacak->TandaiMasuk($seri, $t['Gudang']->Id);
        expect(AmbilKodeGalatSeriUji(fn () => $pelacak->KunciKeluar($seri->Id, $rice->Id, $t['GudangBelakang']->Id)))->toBe('NomorSeriTidakTersedia')
            ->and(AmbilKodeGalatSeriUji(fn () => $pelacak->KunciKeluar($seri->Id, $t['Produk']['Stok']->Id, $t['Gudang']->Id)))->toBe('NomorSeriTidakTersedia')
            ->and(AmbilKodeGalatSeriUji(fn () => $pelacak->KunciKeluar(987654321, $rice->Id, $t['Gudang']->Id)))->toBe('NomorSeriTidakTersedia')
            ->and($pelacak->KunciKeluar($seri->Id, $rice->Id, $t['Gudang']->Id)->Id)->toBe($seri->Id);

        expect(fn () => $pelacak->TandaiKeluar($seri, StatusNomorSeri::Tersedia))->toThrow(LogicException::class);
    });

    it('nomor seri baru ikut batal bila transaksi mutasi batal', function (): void {
        $t = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $pelacak = app(PelacakNomorSeri::class);

        try {
            DB::transaction(function () use ($pelacak, $t): void {
                $pelacak->TandaiMasuk($pelacak->KunciMasuk($t['Produk']['Seri']->Id, $t['Gudang']->Id, 'RC18-2609-000999'), $t['Gudang']->Id);

                throw new PelanggaranAturanBisnis('StokTidakCukup', 'Stok tidak cukup.');
            });
        } catch (PelanggaranAturanBisnis) {
        }

        expect(NomorSeri::query()->count())->toBe(0);
    });

    it('invarian: nomor seri Tersedia ⇔ Σ MutasiStok seri = 1', function (): void {
        $t = BantuanPelacakan::SiapkanTenant('Toko Elektronik Maju Jaya');
        $rice = $t['Produk']['Seri'];
        $pelacak = app(PelacakNomorSeri::class);
        $satu = $pelacak->KunciMasuk($rice->Id, $t['Gudang']->Id, 'RC18-2609-000001');
        $pelacak->TandaiMasuk($satu, $t['Gudang']->Id);
        BantuanPelacakan::BuatMutasiMentah($rice, $t['Gudang'], '1.0000', ['IdNomorSeri' => $satu->Id]);
        $dua = $pelacak->KunciMasuk($rice->Id, $t['Gudang']->Id, 'RC18-2609-000002');
        $pelacak->TandaiMasuk($dua, $t['Gudang']->Id);
        BantuanPelacakan::BuatMutasiMentah($rice, $t['Gudang'], '1.0000', ['IdNomorSeri' => $dua->Id, 'SaldoSetelah' => '2.0000']);

        $pelacak->TandaiKeluar($pelacak->KunciKeluar($dua->Id, $rice->Id, $t['Gudang']->Id), StatusNomorSeri::Terjual);
        BantuanPelacakan::BuatMutasiMentah($rice, $t['Gudang'], '-1.0000', ['IdNomorSeri' => $dua->Id, 'SaldoSetelah' => '1.0000', 'JenisMutasi' => 'Penjualan', 'JenisReferensi' => 'Penjualan']);

        expect(PemeriksaInvarian::PeriksaNomorSeri($t['Tenant']->Id))->toBe([]);
    });
});
