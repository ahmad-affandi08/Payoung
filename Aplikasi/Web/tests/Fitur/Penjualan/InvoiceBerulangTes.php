<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Aksi\JalankanInvoiceBerulang;
use App\Domain\Penjualan\Aksi\SimpanInvoiceBerulang;
use App\Domain\Penjualan\Aksi\UbahStatusInvoiceBerulang;
use App\Domain\Penjualan\Data\DataBarisInvoice;
use App\Domain\Penjualan\Data\DataInvoice;
use App\Domain\Penjualan\Data\DataInvoiceBerulang;
use App\Domain\Penjualan\Enum\FrekuensiInvoiceBerulang;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\ModeInvoiceBerulang;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use App\Domain\Penjualan\Model\PengirimanInvoice;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Invoice berulang (F-21): templat tagihan periodik yang menerbitkan invoice biasa. Yang dijaga: jadwal dihitung dari tanggal
 * mulai (tanggal 31 tidak menyusut), satu siklus tidak pernah terbit dua kali, galat tidak memajukan jadwal dan tidak
 * menggugurkan templat lain, jeda tidak menumpuk invoice, jadwal terkunci setelah siklus pertama, dan templat yang pasti gagal
 * ditolak sejak disimpan.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9401']])]);
    $this->k = BantuanPersediaan::SiapkanTenant('Penyedia Hosting Nusantara');
    OverrideTenant::query()->create([
        'IdTenant' => $this->k['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(90),
        'Alasan' => 'Uji invoice berulang',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    $this->klien = Pelanggan::query()->create(['Nama' => 'PT Sumber Makmur Sentosa', 'NoHp' => '6281355550101', 'TerminHari' => 14]);
    $this->jasa = BantuanKatalog::BuatProduk(['Nama' => 'Hosting & maintenance bulanan', 'Jenis' => JenisProduk::Jasa], '2500000.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function DataBerulangUji($tes, array $ubah = []): DataInvoiceBerulang
{
    $isi = new DataInvoice(
        jenis: JenisInvoice::Penjualan,
        uuidPelanggan: $tes->klien->Uuid,
        idOutlet: $tes->k['Outlet']->Id,
        tanggal: CarbonImmutable::parse('2026-09-27'),
        terminHari: null,
        baris: [new DataBarisInvoice($tes->jasa->Uuid, 'Hosting & maintenance bulanan', null, JenisBarisInvoice::Jasa, 'bln', Kuantitas::Dari('1'), Uang::Dari('2500000.00'), Uang::Nol(), null)],
        perihal: 'Maintenance {periode}',
        catatan: 'Tagihan untuk {bulan} {tahun}',
    );

    return new DataInvoiceBerulang(
        nama: $ubah['nama'] ?? 'Hosting PT Sumber Makmur',
        frekuensi: $ubah['frekuensi'] ?? FrekuensiInvoiceBerulang::Bulanan,
        interval: $ubah['interval'] ?? 1,
        tanggalMulai: $ubah['mulai'] ?? CarbonImmutable::parse('2026-09-27'),
        tanggalBerakhir: $ubah['berakhir'] ?? null,
        batasKali: $ubah['batas'] ?? null,
        mode: $ubah['mode'] ?? ModeInvoiceBerulang::Terbitkan,
        isi: $ubah['isi'] ?? $isi,
    );
}

function BuatBerulangUji($tes, array $ubah = []): InvoiceBerulang
{
    return app(SimpanInvoiceBerulang::class)->Jalankan(DataBerulangUji($tes, $ubah), $tes->k['Pemilik']->Id);
}

function JalankanBerulangUji($tes, string $hariIni, ?InvoiceBerulang $templat = null): int
{
    $templat ??= InvoiceBerulang::query()->firstOrFail();
    // Waktu aplikasi ikut maju: invoice tidak boleh bertanggal masa depan, dan perintah terjadwal memang berjalan pada harinya.
    Carbon::setTestNow($hariIni.' 03:00:00');

    return app(JalankanInvoiceBerulang::class)->Jalankan($tes->k['Tenant']->Id, $templat->Uuid, CarbonImmutable::parse($hariIni));
}

function GalatBerulangUji(callable $aksi): string
{
    try {
        $aksi();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return 'tidak-ada-galat';
}

describe('membuat templat', function (): void {
    it('menyimpan templat aktif dengan siklus pertama pada tanggal mulai', function (): void {
        $templat = BuatBerulangUji($this);

        expect($templat->Status)->toBe(StatusInvoiceBerulang::Aktif)
            ->and($templat->BerikutnyaPada?->toDateString())->toBe('2026-09-27')
            ->and($templat->JumlahTerbit)->toBe(0)
            ->and($templat->Detail)->toHaveCount(1)
            ->and($templat->Detail->first()->Harga)->toBe('2500000.00');
    });

    it('menolak isian yang pasti gagal sejak disimpan', function (): void {
        $simpan = app(SimpanInvoiceBerulang::class);
        $id = $this->k['Pemilik']->Id;

        expect(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['nama' => '  ']), $id)))->toBe('NamaWajib')
            ->and(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['interval' => 0]), $id)))->toBe('IntervalTidakValid')
            ->and(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['interval' => 13]), $id)))->toBe('IntervalTidakValid')
            ->and(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['batas' => 0]), $id)))->toBe('BatasKaliTidakValid')
            ->and(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['mulai' => CarbonImmutable::parse('2026-09-20')]), $id)))->toBe('TanggalMulaiLampau')
            ->and(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['berakhir' => CarbonImmutable::parse('2026-09-01')]), $id)))->toBe('TanggalBerakhirTidakValid');

        $dp = new DataInvoice(JenisInvoice::UangMuka, $this->klien->Uuid, $this->k['Outlet']->Id, CarbonImmutable::parse('2026-09-27'), null, [new DataBarisInvoice(null, 'DP', null, JenisBarisInvoice::Jasa, null, Kuantitas::Dari('1'), Uang::Dari('1000'), Uang::Nol(), null)]);
        expect(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['isi' => $dp]), $id)))->toBe('JenisTidakDidukung');

        $stok = BantuanKatalog::BuatProduk(['Nama' => 'Beras 5 kg'], '70000.00');
        $berstok = new DataInvoice(JenisInvoice::Penjualan, $this->klien->Uuid, $this->k['Outlet']->Id, CarbonImmutable::parse('2026-09-27'), null, [new DataBarisInvoice($stok->Uuid, 'Beras', null, JenisBarisInvoice::Barang, null, Kuantitas::Dari('1'), Uang::Dari('70000'), Uang::Nol(), null)]);
        expect(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['isi' => $berstok]), $id)))->toBe('ProdukBerstok');

        $this->klien->forceFill(['Status' => StatusPelanggan::Diarsipkan->value])->save();
        expect(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this), $id)))->toBe('PelangganNonaktif')
            ->and(InvoiceBerulang::query()->count())->toBe(0);
    });
});

describe('menerbitkan siklus', function (): void {
    it('menerbitkan invoice bertanggal siklus dengan placeholder terisi, lalu memajukan jadwal', function (): void {
        $templat = BuatBerulangUji($this);

        expect(JalankanBerulangUji($this, '2026-09-26'))->toBe(0);
        expect(JalankanBerulangUji($this, '2026-09-27'))->toBe(1);

        $invoice = Invoice::query()->sole();
        $templat->refresh();
        expect($invoice->Status)->toBe(StatusInvoice::Terbit)
            ->and($invoice->Tanggal->toDateString())->toBe('2026-09-27')
            ->and($invoice->Perihal)->toBe('Maintenance September 2026')
            ->and($invoice->Catatan)->toBe('Tagihan untuk September 2026')
            ->and($invoice->IdInvoiceBerulang)->toBe($templat->Id)
            ->and($invoice->TanggalSiklus?->toDateString())->toBe('2026-09-27')
            ->and($invoice->DibuatOleh)->toBe($this->k['Pemilik']->Id)
            ->and($invoice->Total)->toBe('2500000.00')
            ->and($templat->JumlahTerbit)->toBe(1)
            ->and($templat->BerikutnyaPada?->toDateString())->toBe('2026-10-27')
            ->and($templat->GalatTerakhir)->toBeNull();
    });

    it('idempoten: dijalankan dua kali di hari yang sama tidak menerbitkan dua invoice', function (): void {
        BuatBerulangUji($this);

        expect(JalankanBerulangUji($this, '2026-09-27'))->toBe(1)
            ->and(JalankanBerulangUji($this, '2026-09-27'))->toBe(0)
            ->and(Invoice::query()->count())->toBe(1);

        // Siklus yang sudah punya invoice (mis. jadwal digeser manual) hanya memajukan jadwal, tidak membuat invoice kedua.
        $templat = InvoiceBerulang::query()->sole();
        $templat->forceFill(['BerikutnyaPada' => '2026-09-27', 'SiklusBerikutnya' => 0, 'JumlahTerbit' => 0])->save();
        expect(JalankanBerulangUji($this, '2026-09-27'))->toBe(1)
            ->and(Invoice::query()->count())->toBe(1);
    });

    it('tanggal 31 kembali ke 31 setelah bulan pendek (jadwal dihitung dari tanggal mulai)', function (): void {
        Carbon::setTestNow('2026-12-31 03:00:00');
        $templat = BuatBerulangUji($this, ['mulai' => CarbonImmutable::parse('2026-12-31')]);

        JalankanBerulangUji($this, '2026-12-31', $templat);
        expect($templat->refresh()->BerikutnyaPada?->toDateString())->toBe('2027-01-31');
        JalankanBerulangUji($this, '2027-01-31', $templat);
        expect($templat->refresh()->BerikutnyaPada?->toDateString())->toBe('2027-02-28');
        JalankanBerulangUji($this, '2027-02-28', $templat);
        expect($templat->refresh()->BerikutnyaPada?->toDateString())->toBe('2027-03-31');
    });

    it('frekuensi mingguan dan tahunan serta interval', function (): void {
        $mingguan = BuatBerulangUji($this, ['frekuensi' => FrekuensiInvoiceBerulang::Mingguan, 'interval' => 2]);
        JalankanBerulangUji($this, '2026-09-27', $mingguan);
        expect($mingguan->refresh()->BerikutnyaPada?->toDateString())->toBe('2026-10-11');

        $tahunan = BuatBerulangUji($this, ['frekuensi' => FrekuensiInvoiceBerulang::Tahunan, 'nama' => 'Lisensi tahunan']);
        JalankanBerulangUji($this, '2026-09-27', $tahunan);
        expect($tahunan->refresh()->BerikutnyaPada?->toDateString())->toBe('2027-09-27');
    });

    it('berhenti sendiri setelah batas kali atau melewati tanggal berakhir', function (): void {
        $batas = BuatBerulangUji($this, ['batas' => 2, 'nama' => 'Dua kali']);
        JalankanBerulangUji($this, '2026-09-27', $batas);
        expect($batas->refresh()->Status)->toBe(StatusInvoiceBerulang::Aktif);
        JalankanBerulangUji($this, '2026-10-27', $batas);
        expect($batas->refresh()->Status)->toBe(StatusInvoiceBerulang::Selesai)
            ->and($batas->BerikutnyaPada)->toBeNull();
        expect(JalankanBerulangUji($this, '2026-11-27', $batas))->toBe(0);

        Carbon::setTestNow('2026-09-27 03:00:00');
        $berakhir = BuatBerulangUji($this, ['berakhir' => CarbonImmutable::parse('2026-10-15'), 'nama' => 'Sampai Oktober']);
        JalankanBerulangUji($this, '2026-09-27', $berakhir);
        expect($berakhir->refresh()->Status)->toBe(StatusInvoiceBerulang::Selesai);
    });

    it('mengejar siklus yang tertinggal paling banyak tiga per putaran', function (): void {
        $templat = BuatBerulangUji($this, ['frekuensi' => FrekuensiInvoiceBerulang::Mingguan]);

        expect(JalankanBerulangUji($this, '2026-11-15', $templat))->toBe(JalankanInvoiceBerulang::MAKS_SIKLUS_PER_JALAN)
            ->and(Invoice::query()->count())->toBe(3)
            ->and($templat->refresh()->BerikutnyaPada?->toDateString())->toBe('2026-10-18');
        expect(Invoice::query()->orderBy('Tanggal')->pluck('Tanggal')->map(fn ($t) => $t->toDateString())->all())->toBe(['2026-09-27', '2026-10-04', '2026-10-11']);
    });

    it('mode BuatDraf hanya membuat draf; TerbitkanDanKirim menerbitkan lalu mengirim WhatsApp', function (): void {
        $draf = BuatBerulangUji($this, ['mode' => ModeInvoiceBerulang::BuatDraf, 'nama' => 'Draf saja']);
        JalankanBerulangUji($this, '2026-09-27', $draf);
        $invoice = Invoice::query()->sole();
        expect($invoice->Status)->toBe(StatusInvoice::Draf)->and($invoice->Nomor)->toBeNull();

        $kirim = BuatBerulangUji($this, ['mode' => ModeInvoiceBerulang::TerbitkanDanKirim, 'nama' => 'Otomatis kirim']);
        JalankanBerulangUji($this, '2026-09-27', $kirim);
        $terbit = Invoice::query()->where('IdInvoiceBerulang', $kirim->Id)->sole();
        expect($terbit->Status)->toBe(StatusInvoice::Terbit)
            ->and(PengirimanInvoice::query()->where('IdInvoice', $terbit->Id)->sole()->Status->value)->toBe('Terkirim')
            ->and($terbit->refresh()->TerkirimPada)->not->toBeNull();
    });

    it('periode terkunci: invoice bertanggal hari ini, bukan ditolak', function (): void {
        $templat = BuatBerulangUji($this);
        BantuanPersediaan::KunciPeriode('2026-09', $this->k['Pemilik']->Id);

        // Siklus 27 September terkunci; hari ini sudah Oktober dan periodenya terbuka.
        expect(JalankanBerulangUji($this, '2026-10-02', $templat))->toBeGreaterThanOrEqual(1);
        expect(Invoice::query()->orderBy('Id')->first()->Tanggal->toDateString())->toBe('2026-10-02');
    });
});

describe('masalah dan jeda', function (): void {
    it('masalah dicatat, jadwal tidak maju, templat lain tetap jalan, dan pulih setelah masalahnya beres', function (): void {
        $a = BuatBerulangUji($this, ['nama' => 'Templat A']);
        $b = BuatBerulangUji($this, ['nama' => 'Templat B']);
        $this->klien->forceFill(['Status' => StatusPelanggan::Diarsipkan->value])->save();

        expect(JalankanBerulangUji($this, '2026-09-27', $a))->toBe(0);
        $a->refresh();
        expect($a->GalatTerakhir)->toContain('diarsipkan')
            ->and($a->BerikutnyaPada?->toDateString())->toBe('2026-09-27')
            ->and($a->JumlahTerbit)->toBe(0)
            ->and(Invoice::query()->count())->toBe(0);

        // Masalah diperbaiki: siklus yang sama terbit, galat dibersihkan.
        $this->klien->forceFill(['Status' => StatusPelanggan::Aktif->value])->save();
        expect(JalankanBerulangUji($this, '2026-09-27', $a))->toBe(1);
        expect($a->refresh()->GalatTerakhir)->toBeNull()->and($a->JumlahTerbit)->toBe(1);

        // Templat B tidak terpengaruh galat A.
        expect(JalankanBerulangUji($this, '2026-09-27', $b))->toBe(1);
    });

    it('dijeda tidak terbit; dilanjutkan melompati siklus yang terlewat tanpa menumpuk invoice', function (): void {
        $templat = BuatBerulangUji($this);
        $ubah = app(UbahStatusInvoiceBerulang::class);
        $ubah->Jalankan($templat->Uuid, StatusInvoiceBerulang::Dijeda, $this->k['Pemilik']->Id);

        expect(JalankanBerulangUji($this, '2026-12-27', $templat))->toBe(0)
            ->and(Invoice::query()->count())->toBe(0);

        Carbon::setTestNow('2026-12-10 03:00:00');
        $ubah->Jalankan($templat->Uuid, StatusInvoiceBerulang::Aktif, $this->k['Pemilik']->Id);
        expect($templat->refresh()->BerikutnyaPada?->toDateString())->toBe('2026-12-27')
            ->and($templat->Status)->toBe(StatusInvoiceBerulang::Aktif)
            ->and(Invoice::query()->count())->toBe(0);
    });

    it('hentikan bersifat tetap; templat selesai tidak bisa diubah atau dihidupkan lagi', function (): void {
        $templat = BuatBerulangUji($this);
        $ubah = app(UbahStatusInvoiceBerulang::class);
        $ubah->Jalankan($templat->Uuid, StatusInvoiceBerulang::Selesai, $this->k['Pemilik']->Id);

        expect($templat->refresh()->Status)->toBe(StatusInvoiceBerulang::Selesai)
            ->and($templat->BerikutnyaPada)->toBeNull()
            ->and(GalatBerulangUji(fn () => $ubah->Jalankan($templat->Uuid, StatusInvoiceBerulang::Aktif, 1)))->toBe('StatusTidakBisaBerubah')
            ->and(GalatBerulangUji(fn () => app(SimpanInvoiceBerulang::class)->Jalankan(DataBerulangUji($this), 1, $templat->Uuid)))->toBe('TemplatSelesai')
            ->and(JalankanBerulangUji($this, '2026-12-01', $templat))->toBe(0);
    });

    it('jadwal terkunci setelah siklus pertama terbit, tetapi isi tagihan masih bisa diubah untuk siklus berikutnya', function (): void {
        $templat = BuatBerulangUji($this);
        $simpan = app(SimpanInvoiceBerulang::class);

        // Sebelum siklus pertama jadwal masih bebas.
        $simpan->Jalankan(DataBerulangUji($this, ['frekuensi' => FrekuensiInvoiceBerulang::Mingguan]), $this->k['Pemilik']->Id, $templat->Uuid);
        $simpan->Jalankan(DataBerulangUji($this), $this->k['Pemilik']->Id, $templat->Uuid);

        JalankanBerulangUji($this, '2026-09-27', $templat);
        expect(GalatBerulangUji(fn () => $simpan->Jalankan(DataBerulangUji($this, ['frekuensi' => FrekuensiInvoiceBerulang::Tahunan]), 1, $templat->Uuid)))->toBe('JadwalTerkunci');

        $isi = new DataInvoice(JenisInvoice::Penjualan, $this->klien->Uuid, $this->k['Outlet']->Id, CarbonImmutable::parse('2026-09-27'), null, [new DataBarisInvoice(null, 'Hosting naik paket', null, JenisBarisInvoice::Jasa, 'bln', Kuantitas::Dari('1'), Uang::Dari('3000000.00'), Uang::Nol(), null)]);
        $simpan->Jalankan(DataBerulangUji($this, ['isi' => $isi]), $this->k['Pemilik']->Id, $templat->Uuid);
        JalankanBerulangUji($this, '2026-10-27', $templat);

        $totals = Invoice::query()->orderBy('Tanggal')->pluck('Total')->all();
        expect($totals)->toBe(['2500000.00', '3000000.00']);
    });

    it('invoice yang sudah terbit dari templat tidak berubah ketika templatnya diubah atau dihentikan', function (): void {
        $templat = BuatBerulangUji($this);
        JalankanBerulangUji($this, '2026-09-27', $templat);
        $invoice = Invoice::query()->sole();

        app(UbahStatusInvoiceBerulang::class)->Jalankan($templat->Uuid, StatusInvoiceBerulang::Selesai, $this->k['Pemilik']->Id);

        expect($invoice->refresh()->Status)->toBe(StatusInvoice::Terbit)->and($invoice->Total)->toBe('2500000.00');
    });
});

it('perintah invoice:jalankan-berulang menerbitkan templat yang jatuh tempo per tenant', function (): void {
    BuatBerulangUji($this);

    $this->artisan('invoice:jalankan-berulang', ['--tenant' => [$this->k['Tenant']->Id]])->assertSuccessful();
    expect(Invoice::query()->count())->toBe(1);
    $this->artisan('invoice:jalankan-berulang', ['--tenant' => [$this->k['Tenant']->Id]])->assertSuccessful();
    expect(Invoice::query()->count())->toBe(1);
});

it('placeholder perihal berbahasa Indonesia sesuai tanggal invoice', function (): void {
    $tanggal = CarbonImmutable::parse('2026-10-27');

    expect(JalankanInvoiceBerulang::IsiPlaceholder('Maintenance {periode}', $tanggal))->toBe('Maintenance Oktober 2026')
        ->and(JalankanInvoiceBerulang::IsiPlaceholder('Bulan {bulan} tahun {tahun}', CarbonImmutable::parse('2027-08-01')))->toBe('Bulan Agustus tahun 2027')
        ->and(JalankanInvoiceBerulang::IsiPlaceholder('Tanpa placeholder', $tanggal))->toBe('Tanpa placeholder')
        ->and(JalankanInvoiceBerulang::IsiPlaceholder(null, $tanggal))->toBeNull();
});
