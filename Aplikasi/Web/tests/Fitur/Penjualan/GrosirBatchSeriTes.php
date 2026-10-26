<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Aksi\BatalkanReturGrosir;
use App\Domain\Penjualan\Aksi\BatalkanSuratJalan;
use App\Domain\Penjualan\Aksi\BuatReturGrosir;
use App\Domain\Penjualan\Aksi\KirimPesananGrosir;
use App\Domain\Penjualan\Aksi\KonfirmasiPesananGrosir;
use App\Domain\Penjualan\Aksi\SimpanPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisReturGrosir;
use App\Domain\Penjualan\Data\DataBarisSuratJalan;
use App\Domain\Penjualan\Data\DataPesananGrosir;
use App\Domain\Penjualan\Data\DataReturGrosir;
use App\Domain\Penjualan\Data\DataSuratJalan;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\NomorSeri;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Grosir × batch (F-05g) dan nomor seri (F-05h), F-12 §9.7: surat jalan mengalokasikan batch FEFO di server atau mencatat
 * nomor seri tiap unit; retur mengembalikan ke batch asal (terbalik dari alokasi) atau nomor yang disebut; pembatalan
 * membalik semuanya. Invarian stok & jurnal dicek di akhir tiap skenario.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Grosir Susu & Elektronik Jaya');
    $this->toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Jaya', 'NoHp' => '6281355550006', 'LimitKredit' => '500000000', 'TerminHari' => 30]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param  list<int|string>  $nomorSeri */
function KirimGrosirUji($tes, Produk $produk, string $jumlah, array $nomorSeri = [], string $hargaUnit = '20000.00'): SuratJalan
{
    $pesanan = app(SimpanPesananGrosir::class)->Jalankan(new DataPesananGrosir(
        uuidPelanggan: $tes->toko->Uuid,
        idOutlet: $tes->k['Outlet']->Id,
        tanggal: CarbonImmutable::parse('2026-09-20'),
        baris: [new DataBarisPesananGrosir($produk->Uuid, BantuanHarga::SatuanDasar($produk)->Uuid, Kuantitas::Dari($jumlah), Uang::Nol())],
    ), $tes->k['Pemilik']->Id);
    $pesanan = app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $tes->k['Pemilik']->Id, true, 'Pelanggan lama');

    return app(KirimPesananGrosir::class)->Jalankan(new DataSuratJalan(
        uuidPesanan: $pesanan->Uuid,
        idGudang: $tes->k['Gudang']->Id,
        tanggal: CarbonImmutable::parse('2026-09-20'),
        baris: [new DataBarisSuratJalan(1, Kuantitas::Dari($jumlah), array_map('strval', $nomorSeri))],
    ), $tes->k['Pemilik']->Id);
}

/** @param  list<string>  $nomorSeri */
function ReturGrosirBatchSeriUji($tes, SuratJalan $suratJalan, string $jumlah, array $nomorSeri = []): ReturGrosir
{
    return app(BuatReturGrosir::class)->Jalankan(new DataReturGrosir(
        uuidSuratJalan: $suratJalan->Uuid,
        tanggal: CarbonImmutable::parse('2026-09-27'),
        alasan: 'Kemasan penyok saat bongkar',
        baris: [new DataBarisReturGrosir(1, Kuantitas::Dari($jumlah), nomorSeri: $nomorSeri)],
    ), $tes->k['Pemilik']->Id);
}

function KodeGalatGrosirUji(Closure $fn): ?string
{
    try {
        $fn();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

/** @return array<string, string> NomorBatch → JumlahSisa */
function SisaBatchGrosirUji(Produk $produk): array
{
    return BatchStok::query()->where('IdProduk', $produk->Id)->orderBy('NomorBatch')->pluck('JumlahSisa', 'NomorBatch')->map(fn ($v): string => (string) Kuantitas::Dari((string) $v)->KeString())->all();
}

describe('Grosir produk ber-batch (FEFO)', function (): void {
    beforeEach(function (): void {
        $this->susu = BantuanKatalog::BuatProduk(['Nama' => 'Susu UHT Full Cream 1 Liter', 'Pelacakan' => PelacakanProduk::Batch->value], '20000.00', $this->k['Pcs']);
        BantuanStokAwal::BuatDanPosting($this->k['Gudang'], [
            BantuanStokAwal::Baris($this->susu, '40', '12000', 'UHT-B', '2027-03-31'),
            BantuanStokAwal::Baris($this->susu, '30', '10000', 'UHT-A', '2026-12-31'),
        ], $this->k['Pemilik']->Id, '2026-09-01');
    });

    it('surat jalan mengalokasikan batch berkedaluwarsa terdekat dulu dan menyimpan rincian + HPP per batch', function (): void {
        $suratJalan = KirimGrosirUji($this, $this->susu, '50');
        $detail = $suratJalan->Detail->first();

        expect(SisaBatchGrosirUji($this->susu))->toBe(['UHT-A' => '0.0000', 'UHT-B' => '20.0000'])
            // Metode HPP rata-rata bergerak: kedua batch dinilai pada rata-rata saat keluar (780.000 / 70 unit).
            ->and($detail?->TotalHpp)->toBe('557142.85')
            ->and($detail?->HppSatuan)->toBe('11142.857000')
            ->and($detail?->RincianPelacakan)->toEqual([
                ['NomorBatch' => 'UHT-A', 'TanggalKedaluwarsa' => '2026-12-31', 'JumlahDasar' => '30.0000', 'HppSatuan' => '11142.857143'],
                ['NomorBatch' => 'UHT-B', 'TanggalKedaluwarsa' => '2027-03-31', 'JumlahDasar' => '20.0000', 'HppSatuan' => '11142.857143'],
            ])
            ->and($suratJalan->TotalHpp)->toBe('557142.85')
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('stok batch kurang ditolak BatchTidakCukup tanpa dokumen setengah jadi', function (): void {
        expect(KodeGalatGrosirUji(fn () => KirimGrosirUji($this, $this->susu, '100')))->toBe('BatchTidakCukup')
            ->and(SuratJalan::query()->count())->toBe(0)
            ->and(SisaBatchGrosirUji($this->susu))->toBe(['UHT-A' => '30.0000', 'UHT-B' => '40.0000']);
    });

    it('retur kembali ke batch asal terbalik dari alokasi, pada HPP tiap batch. Pembatalan membalik. Surat jalan beretur tak bisa dibatalkan', function (): void {
        $suratJalan = KirimGrosirUji($this, $this->susu, '50');

        $retur = ReturGrosirBatchSeriUji($this, $suratJalan, '25');

        // Yang keluar terakhir kembali dulu: 20 ke UHT-B lalu 5 ke UHT-A, pada HPP saat barang keluar.
        expect($retur->Detail->first()?->RincianPelacakan)->toEqual([
            ['NomorBatch' => 'UHT-A', 'TanggalKedaluwarsa' => '2026-12-31', 'JumlahDasar' => '5.0000', 'HppSatuan' => '11142.857143'],
            ['NomorBatch' => 'UHT-B', 'TanggalKedaluwarsa' => '2027-03-31', 'JumlahDasar' => '20.0000', 'HppSatuan' => '11142.857143'],
        ])
            ->and($retur->TotalHpp)->toBe('278571.43')
            ->and(SisaBatchGrosirUji($this->susu))->toBe(['UHT-A' => '5.0000', 'UHT-B' => '40.0000']);

        // Sisa yang bisa diretur berikutnya mengikuti batch yang belum kembali (25 lagi: 25 dari UHT-A).
        $kedua = ReturGrosirBatchSeriUji($this, $suratJalan->refresh(), '25');
        expect($kedua->Detail->first()?->RincianPelacakan)->toEqual([
            ['NomorBatch' => 'UHT-A', 'TanggalKedaluwarsa' => '2026-12-31', 'JumlahDasar' => '25.0000', 'HppSatuan' => '11142.857143'],
        ])
            ->and(KodeGalatGrosirUji(fn () => ReturGrosirBatchSeriUji($this, $suratJalan->refresh(), '1')))->toBe('JumlahReturMelebihiSisa');

        expect(KodeGalatGrosirUji(fn () => app(BatalkanSuratJalan::class)->Jalankan($suratJalan->Uuid, 'Pesanan salah gudang', $this->k['Pemilik']->Id)))->toBe('SudahAdaRetur');

        app(BatalkanReturGrosir::class)->Jalankan($kedua->Uuid, 'Barangnya tidak jadi dikembalikan', $this->k['Pemilik']->Id);
        app(BatalkanReturGrosir::class)->Jalankan($retur->Uuid, 'Barangnya tidak jadi dikembalikan', $this->k['Pemilik']->Id);
        expect(SisaBatchGrosirUji($this->susu))->toBe(['UHT-A' => '0.0000', 'UHT-B' => '20.0000']);

        $batal = app(BatalkanSuratJalan::class)->Jalankan($suratJalan->Uuid, 'Pesanan salah gudang', $this->k['Pemilik']->Id);
        expect($batal->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(SisaBatchGrosirUji($this->susu))->toBe(['UHT-A' => '30.0000', 'UHT-B' => '40.0000'])
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });
});

describe('Grosir produk bernomor seri', function (): void {
    beforeEach(function (): void {
        $this->ponsel = BantuanKatalog::BuatProduk(['Nama' => 'Ponsel Android 8/256 GB', 'Pelacakan' => PelacakanProduk::Seri->value], '2500000.00', $this->k['Pcs']);
        BantuanStokAwal::BuatDanPosting($this->k['Gudang'], [
            BantuanStokAwal::Baris($this->ponsel, '3', '2000000', nomorSeri: ['IMEI-0001', 'IMEI-0002', 'IMEI-0003']),
        ], $this->k['Pemilik']->Id, '2026-09-01');
    });

    it('surat jalan wajib menyebut satu nomor seri per unit yang tersedia. Nomor yang dikirim jadi Terjual', function (): void {
        expect(KodeGalatGrosirUji(fn () => KirimGrosirUji($this, $this->ponsel, '2', ['IMEI-0001'])))->toBe('NomorSeriTidakSesuai')
            ->and(KodeGalatGrosirUji(fn () => KirimGrosirUji($this, $this->ponsel, '2', ['IMEI-0001', 'imei-0001'])))->toBe('NomorSeriGanda')
            ->and(KodeGalatGrosirUji(fn () => KirimGrosirUji($this, $this->ponsel, '2', ['IMEI-0001', 'IMEI-9999'])))->toBe('NomorSeriTidakTersedia')
            ->and(SuratJalan::query()->count())->toBe(0);

        $suratJalan = KirimGrosirUji($this, $this->ponsel, '2', ['IMEI-0001', 'IMEI-0002']);
        $detail = $suratJalan->Detail->first();

        expect($detail?->RincianPelacakan)->toBe([
            ['Nomor' => 'IMEI-0001', 'HppSatuan' => '2000000.000000'],
            ['Nomor' => 'IMEI-0002', 'HppSatuan' => '2000000.000000'],
        ])
            ->and($detail?->TotalHpp)->toBe('4000000.00')
            ->and(NomorSeri::query()->where('Nomor', 'IMEI-0001')->value('Status'))->toBe(StatusNomorSeri::Terjual)
            ->and(NomorSeri::query()->where('Nomor', 'IMEI-0003')->value('Status'))->toBe(StatusNomorSeri::Tersedia);

        // Nomor yang sudah terjual tidak bisa dikirim lagi.
        expect(KodeGalatGrosirUji(fn () => KirimGrosirUji($this, $this->ponsel, '1', ['IMEI-0001'])))->toBe('NomorSeriTidakTersedia')
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });

    it('retur hanya nomor yang diserahkan dan belum diretur. Nomor kembali Tersedia, pembatalan retur menjualnya lagi', function (): void {
        $suratJalan = KirimGrosirUji($this, $this->ponsel, '2', ['IMEI-0001', 'IMEI-0002']);

        expect(KodeGalatGrosirUji(fn () => ReturGrosirBatchSeriUji($this, $suratJalan, '1', ['IMEI-0003'])))->toBe('NomorSeriReturTidakValid')
            ->and(KodeGalatGrosirUji(fn () => ReturGrosirBatchSeriUji($this, $suratJalan, '1')))->toBe('NomorSeriTidakSesuai');

        $retur = ReturGrosirBatchSeriUji($this, $suratJalan, '1', ['imei-0002']);

        expect($retur->TotalHpp)->toBe('2000000.00')
            ->and($retur->Detail->first()?->RincianPelacakan)->toEqual([['Nomor' => 'IMEI-0002', 'HppSatuan' => '2000000.000000']])
            ->and(NomorSeri::query()->where('Nomor', 'IMEI-0002')->value('Status'))->toBe(StatusNomorSeri::Tersedia)
            ->and(KodeGalatGrosirUji(fn () => ReturGrosirBatchSeriUji($this, $suratJalan->refresh(), '1', ['IMEI-0002'])))->toBe('NomorSeriReturTidakValid');

        app(BatalkanReturGrosir::class)->Jalankan($retur->Uuid, 'Pembeli batal mengembalikan ponsel', $this->k['Pemilik']->Id);
        expect(NomorSeri::query()->where('Nomor', 'IMEI-0002')->value('Status'))->toBe(StatusNomorSeri::Keluar);

        $batal = app(BatalkanSuratJalan::class)->Jalankan($suratJalan->Uuid, 'Pesanan dibatalkan pembeli', $this->k['Pemilik']->Id);
        expect($batal->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(NomorSeri::query()->whereIn('Nomor', ['IMEI-0001', 'IMEI-0002'])->pluck('Status')->all())->toBe([StatusNomorSeri::Tersedia, StatusNomorSeri::Tersedia])
            ->and(PemeriksaInvarian::PeriksaSemua($this->k['Tenant']->Id))->toBe([]);
    });
});
