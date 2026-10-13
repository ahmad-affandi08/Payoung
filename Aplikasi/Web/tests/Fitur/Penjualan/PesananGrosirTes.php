<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Aksi\BatalkanPesananGrosir;
use App\Domain\Penjualan\Aksi\KonfirmasiPesananGrosir;
use App\Domain\Penjualan\Aksi\SimpanPesananGrosir;
use App\Domain\Penjualan\Data\DataBarisPesananGrosir;
use App\Domain\Penjualan\Data\DataPesananGrosir;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Model\PesananGrosir;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Grosir bagian 1 (F-12, §9.7, D-32): simpan draf, konfirmasi dengan BR-12.6 limit kredit, dan pembatalan.
 *
 * Cakupan pajak: outlet di pembantu test ini bukan PKP dan kelompok pajaknya tidak menaut jenis pajak, jadi yang
 * dibuktikan di sini adalah **perkabelannya** — subtotal dari harga snapshot, diskon diterapkan, dan `TarifPpn` tetap
 * null ketika tidak ada tarif berlaku (CLAUDE.md #12). Aritmetika PPN inklusif/eksklusif sendiri sudah diuji milik
 * `MesinKalkulasi`, mesin yang sama yang dipanggil `PenghitungGrosir`; mengujinya lagi di sini berarti menguji mesin
 * itu, bukan grosir. Jalur PKP grosir dari ujung ke ujung belum tercakup dan menunggu surat jalan (tahap berikutnya).
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    // Dokumen legal & pengaturan pendaftaran (BR-P06.2) harus ada sebelum tenant uji bisa didaftarkan.
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->t = BantuanKatalog::SiapkanTenantProduk('Grosir Sumber Pangan');
    $this->produk = BantuanKatalog::BuatProduk(
        ['Nama' => 'Gula Pasir Kemasan 1 kg', 'IdKelompokPajak' => $this->t['KelompokPajak']->Id],
        '15000.00',
        $this->t['Pcs'],
    );
    $this->satuan = BantuanHarga::SatuanDasar($this->produk);
    $this->toko = Pelanggan::query()->create([
        'Nama' => 'Toko Makmur Jaya',
        'NoHp' => '6281355550001',
        'LimitKredit' => '5000000',
        'TerminHari' => 30,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param  list<array{Uuid: string, Jumlah: string, Diskon?: string}>  $baris */
function DataGrosirUji(array $konteks, Pelanggan $toko, array $baris): DataPesananGrosir
{
    return new DataPesananGrosir(
        uuidPelanggan: $toko->Uuid,
        idOutlet: $konteks['Outlet']->Id,
        tanggal: CarbonImmutable::parse('2026-09-27'),
        baris: array_map(fn (array $b): DataBarisPesananGrosir => new DataBarisPesananGrosir(
            $b['UuidProduk'],
            $b['Uuid'],
            Kuantitas::Dari($b['Jumlah']),
            Uang::Dari($b['Diskon'] ?? '0'),
        ), $baris),
    );
}

function SimpanGrosirUji($tes, array $baris, ?string $uuid = null): PesananGrosir
{
    return app(SimpanPesananGrosir::class)->Jalankan(
        DataGrosirUji($tes->t, $tes->toko, $baris),
        $tes->t['Pemilik']->Id,
        $uuid,
    );
}

describe('SimpanPesananGrosir', function (): void {
    it('membuat draf bernomor per outlet dengan harga dari price engine, bukan dari klien', function (): void {
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '200']]);

        $kode = mb_strtoupper($this->t['Outlet']->Kode);
        expect($pesanan->Nomor)->toBe("PG/{$kode}/2609/0001")
            ->and($pesanan->Status)->toBe(StatusPesananGrosir::Draf)
            ->and($pesanan->Detail)->toHaveCount(1)
            // Harga tidak dikirim klien: 200 x 15.000 dari harga dasar produk.
            ->and($pesanan->Detail[0]->Harga)->toBe('15000.00')
            ->and($pesanan->Detail[0]->Subtotal)->toBe('3000000.00')
            ->and($pesanan->Subtotal)->toBe('3000000.00')
            // Outlet bukan PKP & kelompok pajak tanpa jenis pajak: tidak ada PPN yang diasumsikan.
            ->and($pesanan->TarifPpn)->toBeNull()
            ->and($pesanan->Pajak)->toBe('0.00')
            ->and($pesanan->Total)->toBe('3000000.00')
            // Snapshot baris: nama & satuan ikut disimpan supaya dokumen tetap terbaca bila produk berubah.
            ->and($pesanan->Detail[0]->NamaProduk)->toBe('Gula Pasir Kemasan 1 kg')
            ->and($pesanan->Detail[0]->SimbolSatuan)->toBe('pcs')
            ->and($pesanan->Detail[0]->JumlahTerkirim)->toBe('0.0000');
    });

    it('diskon baris mengurangi subtotal dokumen', function (): void {
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '100', 'Diskon' => '150000']]);

        expect($pesanan->Subtotal)->toBe('1500000.00')
            ->and($pesanan->Diskon)->toBe('150000.00')
            ->and($pesanan->Total)->toBe('1350000.00');
    });

    it('nomor berurut per outlet & periode', function (): void {
        $satu = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '10']]);
        $dua = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '10']]);

        $kode = mb_strtoupper($this->t['Outlet']->Kode);
        expect([$satu->Nomor, $dua->Nomor])->toBe(["PG/{$kode}/2609/0001", "PG/{$kode}/2609/0002"]);
    });

    it('draf boleh diubah dan barisnya diganti seluruhnya, bukan ditambal', function (): void {
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '10']]);
        $nomor = $pesanan->Nomor;

        $ubah = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '40']], $pesanan->Uuid);

        expect($ubah->Id)->toBe($pesanan->Id)
            ->and($ubah->Nomor)->toBe($nomor)
            ->and($ubah->Detail)->toHaveCount(1)
            ->and($ubah->Detail[0]->Jumlah)->toBe('40.0000')
            ->and($ubah->Total)->toBe('600000.00');
    });

    it('baris kosong ditolak', function (): void {
        expect(fn (): PesananGrosir => SimpanGrosirUji($this, []))
            ->toThrow(PelanggaranAturanBisnis::class);
    });

    it('cakupan: produk jasa ditolak dengan sebabnya; produk ber-batch dan bernomor seri kini diterima (alokasi di surat jalan)', function (): void {
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Antar Barang', 'Jenis' => JenisProduk::Jasa->value], '50000.00', $this->t['Pcs']);
        $batch = BantuanKatalog::BuatProduk(['Nama' => 'Susu UHT 1 liter', 'Pelacakan' => PelacakanProduk::Batch->value], '20000.00', $this->t['Pcs']);
        $seri = BantuanKatalog::BuatProduk(['Nama' => 'Ponsel Android 8/256', 'Pelacakan' => PelacakanProduk::Seri->value], '2500000.00', $this->t['Pcs']);

        $galat = null;

        try {
            SimpanGrosirUji($this, [['UuidProduk' => $jasa->Uuid, 'Uuid' => BantuanHarga::SatuanDasar($jasa)->Uuid, 'Jumlah' => '1']]);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('JenisProdukBelumDidukung');

        $pesanan = SimpanGrosirUji($this, [
            ['UuidProduk' => $batch->Uuid, 'Uuid' => BantuanHarga::SatuanDasar($batch)->Uuid, 'Jumlah' => '12'],
            ['UuidProduk' => $seri->Uuid, 'Uuid' => BantuanHarga::SatuanDasar($seri)->Uuid, 'Jumlah' => '3'],
        ]);

        expect($pesanan->Detail)->toHaveCount(2);
    });
});

describe('KonfirmasiPesananGrosir (BR-12.6)', function (): void {
    it('limit kredit cukup: dikonfirmasi tanpa penyetuju, termin pelanggan di-snapshot', function (): void {
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '200']]);

        $hasil = app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->t['Pemilik']->Id, false);

        expect($hasil->Status)->toBe(StatusPesananGrosir::Dikonfirmasi)
            ->and($hasil->IdPenyetujuKredit)->toBeNull()
            ->and($hasil->TerminHari)->toBe(30)
            ->and($hasil->DikonfirmasiOleh)->toBe($this->t['Pemilik']->Id);
    });

    it('melebihi limit: ditolak tanpa izin persetujuan kredit', function (): void {
        // 400 x 15.000 = 6.000.000 > limit 5.000.000.
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '400']]);

        $galat = null;

        try {
            app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->t['Pemilik']->Id, false);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('ButuhPersetujuanKredit')
            ->and($galat?->getMessage())->toContain('melebihi limit')
            ->and(PesananGrosir::query()->whereKey($pesanan->Id)->sole()->Status)->toBe(StatusPesananGrosir::Draf);
    });

    it('penyetuju wajib menulis alasan, dan alasannya tersimpan', function (): void {
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '400']]);
        $galat = null;

        try {
            app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->t['Pemilik']->Id, true, 'oke');
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('AlasanPersetujuanWajib');

        $hasil = app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->t['Pemilik']->Id, true, 'Pelanggan lama, pembayaran selalu tepat waktu');

        expect($hasil->Status)->toBe(StatusPesananGrosir::Dikonfirmasi)
            ->and($hasil->IdPenyetujuKredit)->toBe($this->t['Pemilik']->Id)
            ->and($hasil->AlasanPersetujuanKredit)->toBe('Pelanggan lama, pembayaran selalu tepat waktu');
    });

    it('SO lain yang sudah dikonfirmasi tetapi belum terkirim ikut menghabiskan limit', function (): void {
        // Inti BR-12.6: dua SO masing-masing 3.000.000 masih di bawah limit 5.000.000 sendiri-sendiri, tetapi
        // bersama-sama melewatinya. Tanpa penjumlahan paparan, pelanggan bisa menembus limit lewat banyak SO kecil.
        $pertama = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '200']]);
        app(KonfirmasiPesananGrosir::class)->Jalankan($pertama->Uuid, $this->t['Pemilik']->Id, false);

        $kedua = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '200']]);
        $galat = null;

        try {
            app(KonfirmasiPesananGrosir::class)->Jalankan($kedua->Uuid, $this->t['Pemilik']->Id, false);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('ButuhPersetujuanKredit')
            ->and($galat?->getMessage())->toContain('6.000.000');
    });

    it('pelanggan tanpa limit kredit selalu butuh persetujuan', function (): void {
        $tanpaLimit = Pelanggan::query()->create(['Nama' => 'Warung Baru Berkah', 'NoHp' => '6281355550002', 'TerminHari' => 14]);
        $this->toko = $tanpaLimit;
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '1']]);
        $galat = null;

        try {
            app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->t['Pemilik']->Id, false);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('ButuhPersetujuanKredit')
            ->and($galat?->getMessage())->toContain('belum punya limit kredit');
    });

    it('pesanan yang sudah dikonfirmasi tidak bisa diubah lagi maupun dikonfirmasi dua kali', function (): void {
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '10']]);
        app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->t['Pemilik']->Id, false);

        $galatUbah = null;

        try {
            SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '20']], $pesanan->Uuid);
        } catch (PelanggaranAturanBisnis $e) {
            $galatUbah = $e;
        }

        expect($galatUbah?->kode)->toBe('PesananTidakBisaDiubah');

        $galatKonfirmasi = null;

        try {
            app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->t['Pemilik']->Id, false);
        } catch (PelanggaranAturanBisnis $e) {
            $galatKonfirmasi = $e;
        }

        expect($galatKonfirmasi?->kode)->toBe('PesananBukanDraf');
    });
});

describe('BatalkanPesananGrosir', function (): void {
    it('alasan minimal 5 karakter, dan pembatalan dicatat di riwayat status', function (): void {
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '10']]);
        app(KonfirmasiPesananGrosir::class)->Jalankan($pesanan->Uuid, $this->t['Pemilik']->Id, false);

        $galat = null;

        try {
            app(BatalkanPesananGrosir::class)->Jalankan($pesanan->Uuid, 'x', $this->t['Pemilik']->Id);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('AlasanBatalWajib');

        $hasil = app(BatalkanPesananGrosir::class)->Jalankan($pesanan->Uuid, 'Pembeli membatalkan pesanan', $this->t['Pemilik']->Id);

        expect($hasil->Status)->toBe(StatusPesananGrosir::Dibatalkan)
            ->and($hasil->AlasanBatal)->toBe('Pembeli membatalkan pesanan');

        $riwayat = RiwayatStatusDokumen::query()
            ->where('JenisDokumen', PesananGrosir::JENIS_DOKUMEN)
            ->where('IdDokumen', $pesanan->Id)
            ->orderBy('Id')
            ->get();

        expect($riwayat->pluck('StatusKe')->all())->toBe(['Dikonfirmasi', 'Dibatalkan']);
    });

    it('pesanan yang sudah dibatalkan tidak bisa dibatalkan lagi', function (): void {
        $pesanan = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '10']]);
        app(BatalkanPesananGrosir::class)->Jalankan($pesanan->Uuid, 'Salah input pelanggan', $this->t['Pemilik']->Id);

        $galat = null;

        try {
            app(BatalkanPesananGrosir::class)->Jalankan($pesanan->Uuid, 'Salah input pelanggan', $this->t['Pemilik']->Id);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('PesananSudahDibatalkan');
    });

    it('nomor yang sudah terpakai tidak didaur ulang setelah pembatalan', function (): void {
        $batal = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '10']]);
        app(BatalkanPesananGrosir::class)->Jalankan($batal->Uuid, 'Salah input pelanggan', $this->t['Pemilik']->Id);

        $berikutnya = SimpanGrosirUji($this, [['UuidProduk' => $this->produk->Uuid, 'Uuid' => $this->satuan->Uuid, 'Jumlah' => '10']]);

        $kode = mb_strtoupper($this->t['Outlet']->Kode);
        expect($berikutnya->Nomor)->toBe("PG/{$kode}/2609/0002");
    });
});
