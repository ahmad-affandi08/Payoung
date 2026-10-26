<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanLaporan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * D-218: hapus produk beserta seluruh riwayatnya (keputusan pemilik produk). Hanya Pemilik (`data.kelola`), nama produk
 * diketik ulang, stok harus nol, dan produk tidak boleh masih jadi bahan/komponen/pilihan produk lain.
 */
describe('D-218 hapus permanen produk beserta riwayat', function (): void {
    it('produk yang sudah terjual: pratinjau menghitung riwayat. Hapus membuang semua barisnya tetapi nota, jurnal, dan stok produk lain utuh', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Premium Pulen 5 Kg', '10', '60000', '75000.00');
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Minyak Goreng 2 Liter', '5', '30000', '38500.00');
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $beras, 'Jumlah' => '10', 'Harga' => '75000.00']]]);
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id);

        $pratinjau = $masuk()->getJson("/kelola/produk/{$beras->Uuid}/hapus-permanen")->assertOk()->assertJsonPath('Penghalang', null);
        $jumlah = array_column($pratinjau->json('Rincian'), 'Jumlah', 'Tabel');
        expect($jumlah)->toHaveKeys(['PenjualanDetail', 'MutasiStok'])
            ->and($pratinjau->json('Total'))->toBeGreaterThan(2);

        $penjualan = DB::table('Penjualan')->count();
        $jurnal = DB::table('Jurnal')->count();
        $jurnalDetail = DB::table('JurnalDetail')->count();
        $mutasiMinyak = DB::table('MutasiStok')->where('IdProduk', $minyak->Id)->count();

        // Nama salah: tidak ada yang terhapus.
        $masuk()->delete("/kelola/produk/{$beras->Uuid}/hapus-permanen", ['KonfirmasiNama' => 'beras'])->assertSessionHasErrors('KonfirmasiNama');
        expect(DB::table('PenjualanDetail')->where('IdProduk', $beras->Id)->count())->toBe(1);

        $masuk()->delete("/kelola/produk/{$beras->Uuid}/hapus-permanen", ['KonfirmasiNama' => 'Beras Premium Pulen 5 Kg'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/kelola/produk');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Produk::query()->withTrashed()->whereKey($beras->Id)->sole()->DihapusPada)->not->toBeNull()
            ->and(DB::table('PenjualanDetail')->where('IdProduk', $beras->Id)->count())->toBe(0)
            ->and(DB::table('MutasiStok')->where('IdProduk', $beras->Id)->count())->toBe(0)
            ->and(DB::table('SaldoStok')->where('IdProduk', $beras->Id)->count())->toBe(0)
            ->and(DB::table('Penjualan')->count())->toBe($penjualan)
            ->and(DB::table('Jurnal')->count())->toBe($jurnal)
            ->and(DB::table('JurnalDetail')->count())->toBe($jurnalDetail)
            ->and(DB::table('MutasiStok')->where('IdProduk', $minyak->Id)->count())->toBe($mutasiMinyak)
            ->and(Produk::query()->whereKey($minyak->Id)->exists())->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'produk.hapus-permanen')->sole()->NilaiBaru)->toMatchArray(['Baris' => $pratinjau->json('Total')]);
    });

    it('stok masih ada → dihabiskan otomatis lewat penyesuaian Hilang (jurnal seimbang), lalu produk dan riwayatnya terhapus', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Premium Pulen 5 Kg', '10', '60000', '75000.00');
        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id);
        $jurnalSebelum = DB::table('Jurnal')->count();

        $masuk()->getJson("/kelola/produk/{$beras->Uuid}/hapus-permanen")->assertOk()
            ->assertJsonPath('Penghalang', null)
            ->assertJsonPath('Stok.Lokasi', 1)
            ->assertJsonPath('Stok.Nilai', '600000.00');
        $masuk()->delete("/kelola/produk/{$beras->Uuid}/hapus-permanen", ['KonfirmasiNama' => 'Beras Premium Pulen 5 Kg'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/kelola/produk');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Produk::query()->withTrashed()->whereKey($beras->Id)->sole()->DihapusPada)->not->toBeNull()
            ->and(DB::table('MutasiStok')->where('IdProduk', $beras->Id)->count())->toBe(0)
            ->and(DB::table('SaldoStok')->where('IdProduk', $beras->Id)->count())->toBe(0)
            ->and(DB::table('PenyesuaianStok')->count())->toBe(1)
            ->and(DB::table('Jurnal')->count())->toBe($jurnalSebelum + 1)
            ->and(DB::table('JurnalDetail')->sum('Debit'))->toEqual(DB::table('JurnalDetail')->sum('Kredit'))
            ->and(LogAudit::query()->where('Peristiwa', 'produk.hapus-permanen')->sole()->NilaiBaru)->toMatchArray(['PenyesuaianStok' => 1]);
    });

    it('stok minus atau produk ber-batch → ditolak dengan petunjuk. Tidak ada yang terhapus', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $batch = $produk['Batch'];
        BantuanLaporan::CatatMutasi($batch, $t['Gudang'], '5.0000', '50000.00');
        $minus = $produk['Stok'];
        BantuanLaporan::CatatMutasi($minus, $t['Gudang'], '-2.0000', '-20000.00', '2026-09-01', JenisMutasi::PenyesuaianKeluar, JenisReferensiMutasi::PenyesuaianStok);

        $masuk = fn () => BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $masuk()->getJson("/kelola/produk/{$batch->Uuid}/hapus-permanen")->assertOk()
            ->assertJsonPath('Penghalang', "{$batch->Nama} memakai pelacakan batch atau nomor seri. Kosongkan stoknya lewat Penyesuaian stok (pilih batch atau nomor serinya), lalu hapus permanen.");
        $masuk()->delete("/kelola/produk/{$batch->Uuid}/hapus-permanen", ['KonfirmasiNama' => $batch->Nama])->assertSessionHasErrors('Umum');
        $masuk()->delete("/kelola/produk/{$minus->Uuid}/hapus-permanen", ['KonfirmasiNama' => $minus->Nama])
            ->assertSessionHasErrors(['Umum' => "Stok {$minus->Nama} minus di sebagian lokasi. Perbaiki dulu lewat Penyesuaian stok atau Stok opname, lalu hapus permanen."]);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->whereKey([$batch->Id, $minus->Id])->count())->toBe(2)
            ->and(DB::table('PenyesuaianStok')->count())->toBe(0);
    });

    it('masih dipakai sebagai bahan resep produk lain → ditolak sebut nama produknya', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $bahan = BantuanKomposisi::BuatBahan();
        $menu = BantuanKomposisi::BuatProdukResep();
        BantuanKomposisi::SimpanResep($menu, [[$bahan, '18']]);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
            ->delete("/kelola/produk/{$bahan->Uuid}/hapus-permanen", ['KonfirmasiNama' => $bahan->Nama])
            ->assertSessionHasErrors(['Umum' => 'Produk ini masih dipakai sebagai bahan resep Es Kopi Susu Gula Aren Ukuran Besar. Lepaskan dulu dari sana, lalu hapus permanen.']);

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->whereKey($bahan->Id)->exists())->toBeTrue();
    });

    it('resep milik produk itu sendiri ikut terhapus, bahannya tetap ada', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $bahan = BantuanKomposisi::BuatBahan();
        $hasil = BantuanKomposisi::BuatProdukResep('Roti Isi Cokelat Kemasan', JenisProduk::Produksi);
        BantuanKomposisi::SimpanResep($hasil, [[$bahan, '18']]);
        expect(DB::table('Resep')->where('IdProduk', $hasil->Id)->count())->toBe(1)
            ->and(DB::table('ResepDetail')->count())->toBe(1);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
            ->delete("/kelola/produk/{$hasil->Uuid}/hapus-permanen", ['KonfirmasiNama' => 'Roti Isi Cokelat Kemasan'])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(DB::table('Resep')->count())->toBe(0)
            ->and(DB::table('ResepDetail')->count())->toBe(0)
            ->and(Produk::query()->whereKey($bahan->Id)->exists())->toBeTrue()
            ->and(Produk::query()->withTrashed()->whereKey($hasil->Id)->sole()->DihapusPada)->not->toBeNull();
    });

    it('hanya Pemilik (data.kelola): Admin ditolak, produk tetap ada', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $produk = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Curah'], '15000.00', $t['Kg']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Admin)
            ->deleteJson("/kelola/produk/{$produk->Uuid}/hapus-permanen", ['KonfirmasiNama' => 'Gula Pasir Curah'])
            ->assertForbidden();
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Admin)
            ->getJson("/kelola/produk/{$produk->Uuid}/hapus-permanen")
            ->assertForbidden();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->whereKey($produk->Id)->exists())->toBeTrue();
    });

    it('induk varian: seluruh varian dan riwayatnya ikut terhapus', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $induk = BantuanKatalog::BuatProduk(['Jenis' => JenisProduk::IndukVarian, 'Nama' => 'Kaos Polos Katun', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => ['S', 'M']]]], null, $t['Pcs']);
        $anakS = BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'Nama' => 'Kaos Polos Katun S', 'KunciVarian' => 'ukuran=s', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => 'S']]], null, $t['Pcs']);
        $anakM = BantuanKatalog::BuatProduk(['IdInduk' => $induk->Id, 'Nama' => 'Kaos Polos Katun M', 'KunciVarian' => 'ukuran=m', 'AtributVarian' => [['Nama' => 'Ukuran', 'Nilai' => 'M']]], null, $t['Pcs']);

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id)
            ->delete("/kelola/produk/{$induk->Uuid}/hapus-permanen", ['KonfirmasiNama' => 'Kaos Polos Katun'])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        expect(Produk::query()->count())->toBe(0)
            ->and(Produk::query()->withTrashed()->whereKey([$induk->Id, $anakS->Id, $anakM->Id])->whereNotNull('DihapusPada')->count())->toBe(3);
    });
});
