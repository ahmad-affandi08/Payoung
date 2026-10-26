<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Bersama\Sinkron\Kontrak\PenanganItemSinkron;
use App\Domain\Katalog\Kontrak\PemeriksaPemakaianProduk;
use App\Domain\Katalog\Kontrak\PemeriksaRiwayatStok;
use App\Domain\Katalog\Kontrak\PengosongStokProduk;
use App\Domain\Katalog\Kontrak\PenyediaHppBahan;
use App\Domain\Persediaan\Aksi\KosongkanStokProduk;
use App\Domain\Persediaan\Kueri\HppBahanDariSaldo;
use App\Domain\Persediaan\Kueri\PemakaianProdukDiPersediaan;
use App\Domain\Persediaan\Kueri\RiwayatStokProduk;
use App\Domain\Persediaan\Layanan\PenanganSinkronBahanTerbuang;
use Illuminate\Support\ServiceProvider;

/**
 * Provider F-05a stok awal & buku stok (DesainF05a G, pemilik Tim 0; perubahan lewat permintaan ke lead).
 * Mengikat kontrak Katalog ke implementasi domain Persediaan.
 */
final class PenyediaPersediaan extends ServiceProvider
{
    public function register(): void
    {
        // C.4: Katalog mengunci `Pelacakan` begitu produk punya riwayat stok (implementasi Tim D).
        $this->app->bind(PemeriksaRiwayatStok::class, RiwayatStokProduk::class);

        // C.8 (Tim F): HPP bahan resep dari SaldoStok (`bind` menimpa `bindIf` Katalog, BR-03.5) dan pemeriksa
        // pemakaian produk dari sisi persediaan (riwayat stok / draf stok awal, BR-03.2).
        $this->app->bind(PenyediaHppBahan::class, HppBahanDariSaldo::class);
        $this->app->tag(PemakaianProdukDiPersediaan::class, PemeriksaPemakaianProduk::TAG);

        // D-218: hapus permanen produk menghabiskan sisa stok lewat penyesuaian stok (Katalog hanya mengenal kontraknya).
        $this->app->bind(PengosongStokProduk::class, KosongkanStokProduk::class);

        // F-05f: item outbox POS `BahanTerbuang.Catat`.
        $this->app->tag([PenanganSinkronBahanTerbuang::class], PenanganItemSinkron::TAG);
    }

    public function boot(): void
    {
        // Tidak ada yang perlu di-boot: rute didaftarkan dari routes/Persediaan*.php dan routes/Akuntansi.php.
    }
}
