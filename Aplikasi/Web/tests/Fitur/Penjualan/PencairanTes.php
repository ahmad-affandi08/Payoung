<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Aksi\BatalkanPencairan;
use App\Domain\Penjualan\Aksi\BuatPencairan;
use App\Domain\Penjualan\Aksi\UbahBatasHariMenungguMetode;
use App\Domain\Penjualan\Data\DataPencairan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Pencairan;
use App\Domain\Penjualan\Model\PencairanDetail;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanPembayaran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-08 BR-08.4 & J-08.1 pencairan dana non-tunai.
 *
 * Yang dijaga di sini adalah **akun kliring bisa kembali nol**. Sebelum dokumen ini ada, setiap pembayaran QRIS/kartu/
 * ojol mendebet Piutang Pencairan dan tidak ada apa pun yang mengkreditnya, sehingga saldonya tumbuh selamanya dan
 * potongan platform tidak pernah masuk laba-rugi. Test pertama membuktikan saldo itu kembali nol dan potongannya muncul
 * sebagai beban; sisanya menjaga agar satu setoran tidak bisa dibukukan dua kali.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-28 04:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Saldo satu peran akun di seluruh jurnal tenant (debit − kredit), skala 2. */
function SaldoPeranPencairanUji(PeranAkun $peran, int $idOutlet): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun($peran, $idOutlet);
    $saldo = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Uang::Dari($b->Debit))->Kurangi(Uang::Dari($b->Kredit));
    }

    return $saldo->KeString();
}

/** Saldo satu akun (per Id) di seluruh jurnal tenant. */
function SaldoAkunPencairanUji(int $idAkun): string
{
    $saldo = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Uang::Dari($b->Debit))->Kurangi(Uang::Dari($b->Kredit));
    }

    return $saldo->KeString();
}

/**
 * Satu tenant dengan penjualan QRIS statis yang sudah tersinkron, siap dicairkan.
 *
 * @return array{K: array<string, mixed>, Metode: MetodePembayaran, Bank: Akun, Penjualan: list<Penjualan>}
 */
function SiapkanPencairanUji(object $tes, string $nama = 'Kedai Kopi Senja'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $qris = MetodePembayaran::query()->create([
        'Jenis' => JenisMetodePembayaran::QrisStatis,
        'Nama' => 'QRIS Statis',
        // MDR QRIS 0,7% — dipakai sebagai perkiraan potongan, bukan sebagai angka yang dibukukan.
        'PersenBiaya' => '0.7',
        'Aktif' => true,
        'Urutan' => 10,
    ]);
    $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren', 'Jenis' => JenisProduk::NonStok], '25000.00');
    $item = [];

    foreach (['100000.00', '50000.00'] as $indeks => $jumlah) {
        $item[] = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $kopi, 'Jumlah' => (string) (((int) $jumlah) / 25000), 'Harga' => '25000.00']],
            'Pembayaran' => [['Metode' => $qris, 'Jumlah' => $jumlah, 'Referensi' => 'QR-'.($indeks + 1)]],
        ]);
    }

    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], $item))->toBe([['Diterima', null], ['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    // Akun penerima setoran = akun berperan `Bank` dari pemetaan akun tenant (bukan Kas Outlet: uang platform masuk
    // rekening, bukan ke laci kasir).
    $bank = Akun::query()->whereKey(app(PenentuAkun::class)->AmbilIdAkun(PeranAkun::Bank, $k['Outlet']->Id))->firstOrFail();

    return [
        'K' => $k,
        'Metode' => $qris,
        'Bank' => $bank,
        'Penjualan' => array_values(array_map(
            fn (array $i): Penjualan => Penjualan::query()->where('Uuid', $i['Uuid'])->sole(),
            $item,
        )),
    ];
}

/**
 * @param  list<string>  $uuidPembayaran
 */
function DataPencairanUji(array $k, MetodePembayaran $metode, Akun $bank, array $uuidPembayaran, string $bersih, string $tanggal = '2026-09-28'): DataPencairan
{
    return new DataPencairan(
        uuidMetodePembayaran: $metode->Uuid,
        uuidOutlet: $k['Outlet']->Uuid,
        uuidAkunTujuan: $bank->Uuid,
        tanggal: CarbonImmutable::parse($tanggal),
        jumlahBersih: Uang::Dari($bersih),
        uuidPembayaran: $uuidPembayaran,
        referensi: 'SETTLE-0001',
    );
}

/**
 * @return list<string>
 */
function UuidPembayaranUji(MetodePembayaran $metode): array
{
    return array_values(PenjualanPembayaran::query()->where('IdMetodePembayaran', $metode->Id)->orderBy('Id')->pluck('Uuid')->all());
}

describe('BR-08.4 pencairan dana non-tunai', function (): void {
    it('melunasi akun kliring sampai nol, membebankan potongan platform, dan menambah bank sebesar uang yang masuk', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank] = SiapkanPencairanUji($this);
        $idOutlet = $k['Outlet']->Id;

        // Sebelum dicairkan: 150.000 menggantung di Piutang Pencairan.
        expect(SaldoPeranPencairanUji(PeranAkun::PiutangPencairan, $idOutlet))->toBe('150000.00');

        // Platform menyetor 148.950 (potongan 1.050 = 0,7%).
        $pencairan = app(BuatPencairan::class)->Jalankan(
            DataPencairanUji($k, $qris, $bank, UuidPembayaranUji($qris), '148950.00'),
            $k['Pemilik']->Id,
        );

        expect($pencairan->Nomor)->toStartWith('PC/2026/09/')
            ->and($pencairan->JumlahKotor)->toBe('150000.00')
            ->and($pencairan->JumlahBersih)->toBe('148950.00')
            ->and($pencairan->Biaya)->toBe('1050.00')
            // Perkiraan dari pengaturan metode (0,7% × 150.000) kebetulan sama; yang dibukukan tetap selisihnya.
            ->and($pencairan->BiayaDiharapkan)->toBe('1050.00')
            ->and($pencairan->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and($pencairan->IdJurnal)->not->toBeNull()
            // Akun kliring bersih: tidak ada lagi uang yang menggantung.
            ->and(SaldoPeranPencairanUji(PeranAkun::PiutangPencairan, $idOutlet))->toBe('0.00')
            ->and(SaldoPeranPencairanUji(PeranAkun::BebanBiayaPembayaran, $idOutlet))->toBe('1050.00')
            ->and(SaldoAkunPencairanUji($bank->Id))->toBe('148950.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        $jurnal = Jurnal::query()->whereKey($pencairan->IdJurnal)->sole();
        expect($jurnal->TotalDebit)->toBe($jurnal->TotalKredit)
            ->and(PencairanDetail::query()->where('IdPencairan', $pencairan->Id)->count())->toBe(2);
    });

    it('kelebihan setor masuk Pendapatan Lain, bukan beban bernilai negatif', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank] = SiapkanPencairanUji($this);
        $idOutlet = $k['Outlet']->Id;

        // Platform menyetor 151.000 — 1.000 lebih besar daripada nilai transaksinya.
        $pencairan = app(BuatPencairan::class)->Jalankan(
            DataPencairanUji($k, $qris, $bank, UuidPembayaranUji($qris), '151000.00'),
            $k['Pemilik']->Id,
        );

        expect($pencairan->Biaya)->toBe('-1000.00')
            ->and(SaldoPeranPencairanUji(PeranAkun::PiutangPencairan, $idOutlet))->toBe('0.00')
            // Bukan beban negatif: laba-rugi tidak boleh terbaca seolah biaya pembayaran sedang turun.
            ->and(SaldoPeranPencairanUji(PeranAkun::BebanBiayaPembayaran, $idOutlet))->toBe('0.00')
            ->and(SaldoPeranPencairanUji(PeranAkun::PendapatanLain, $idOutlet))->toBe('-1000.00')
            ->and(SaldoAkunPencairanUji($bank->Id))->toBe('151000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('satu pembayaran tidak bisa dicairkan dua kali', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank] = SiapkanPencairanUji($this);
        $uuid = UuidPembayaranUji($qris);

        app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, [$uuid[0]], '99300.00'), $k['Pemilik']->Id);

        expect(fn () => app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, [$uuid[0]], '99300.00'), $k['Pemilik']->Id))
            ->toThrow(fn (PelanggaranAturanBisnis $g) => expect($g->getMessage())->toContain('sudah masuk pencairan lain'));

        // Yang belum dicairkan tetap bisa dicairkan sendiri.
        $kedua = app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, [$uuid[1]], '49650.00'), $k['Pemilik']->Id);
        expect($kedua->JumlahKotor)->toBe('50000.00')
            ->and(SaldoPeranPencairanUji(PeranAkun::PiutangPencairan, $k['Outlet']->Id))->toBe('0.00');
    });

    it('menolak metode tunai, pembayaran beda metode, dan jumlah bersih negatif', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank] = SiapkanPencairanUji($this);
        $uuid = UuidPembayaranUji($qris);
        $tunai = MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tunai->value)->firstOrFail();
        $edc = MetodePembayaran::query()->create([
            'Jenis' => JenisMetodePembayaran::Edc,
            'Nama' => 'EDC Debit',
            'PersenBiaya' => '2',
            'Aktif' => true,
            'Urutan' => 11,
        ]);

        // Tunai tidak pernah lewat akun kliring, jadi tidak ada yang bisa dicairkan.
        expect(fn () => app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $tunai, $bank, $uuid, '1000.00'), $k['Pemilik']->Id))
            ->toThrow(fn (PelanggaranAturanBisnis $g) => expect($g->getMessage())->toContain('tidak memakai akun kliring'));

        // Pembayaran QRIS tidak boleh masuk pencairan metode EDC: akun kliringnya bukan sumbernya.
        expect(fn () => app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $edc, $bank, $uuid, '1000.00'), $k['Pemilik']->Id))
            ->toThrow(fn (PelanggaranAturanBisnis $g) => expect($g->getMessage())->toContain('Satu pencairan hanya untuk satu metode'));

        expect(fn () => app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, $uuid, '-1.00'), $k['Pemilik']->Id))
            ->toThrow(fn (PelanggaranAturanBisnis $g) => expect($g->getMessage())->toContain('tidak boleh negatif'));

        expect(fn () => app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, [], '1000.00'), $k['Pemilik']->Id))
            ->toThrow(fn (PelanggaranAturanBisnis $g) => expect($g->getMessage())->toContain('minimal satu pembayaran'));

        // Setoran bertanggal besok belum terjadi.
        expect(fn () => app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, $uuid, '1000.00', '2026-09-30'), $k['Pemilik']->Id))
            ->toThrow(fn (PelanggaranAturanBisnis $g) => expect($g->getMessage())->toContain('masa depan'));
    });

    it('pembayaran dari penjualan yang di-void ditolak karena jurnalnya sudah dibalik', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank, 'Penjualan' => $penjualan] = SiapkanPencairanUji($this);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $penjualan[0])]))
            ->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        // Void sudah mengembalikan 100.000 dari akun kliring; sisanya cuma penjualan kedua.
        expect(SaldoPeranPencairanUji(PeranAkun::PiutangPencairan, $k['Outlet']->Id))->toBe('50000.00');

        expect(fn () => app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, UuidPembayaranUji($qris), '148950.00'), $k['Pemilik']->Id))
            ->toThrow(fn (PelanggaranAturanBisnis $g) => expect($g->getMessage())->toContain('sudah di-void'));
    });

    it('pembatalan membalik jurnalnya dan melepas pembayarannya untuk dicairkan ulang', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank] = SiapkanPencairanUji($this);
        $idOutlet = $k['Outlet']->Id;
        $uuid = UuidPembayaranUji($qris);

        // Salah catat: jumlah masuknya keliru.
        $salah = app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, $uuid, '120000.00'), $k['Pemilik']->Id);
        $batal = app(BatalkanPencairan::class)->Jalankan($salah->Uuid, 'Salah baca mutasi rekening, bukan 120.000.', $k['Pemilik']->Id);

        expect($batal->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and($batal->IdJurnalPembatalan)->not->toBeNull()
            // Semuanya kembali seperti sebelum dicairkan.
            ->and(SaldoPeranPencairanUji(PeranAkun::PiutangPencairan, $idOutlet))->toBe('150000.00')
            ->and(SaldoPeranPencairanUji(PeranAkun::BebanBiayaPembayaran, $idOutlet))->toBe('0.00')
            ->and(SaldoAkunPencairanUji($bank->Id))->toBe('0.00')
            // Barisnya tetap terbaca, tetapi klaimnya dilepas.
            ->and(PencairanDetail::query()->where('IdPencairan', $batal->Id)->count())->toBe(2)
            ->and(PencairanDetail::query()->where('IdPencairan', $batal->Id)->whereNotNull('IdPembayaranAktif')->count())->toBe(0)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        // Pembatalan idempoten: tidak ada jurnal kedua.
        $ulang = app(BatalkanPencairan::class)->Jalankan($salah->Uuid, 'Diklik dua kali.', $k['Pemilik']->Id);
        expect($ulang->IdJurnalPembatalan)->toBe($batal->IdJurnalPembatalan);

        // Dan pembayarannya bisa dicairkan ulang dengan angka yang benar.
        $benar = app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, $uuid, '148950.00'), $k['Pemilik']->Id);
        expect($benar->Biaya)->toBe('1050.00')
            ->and(SaldoPeranPencairanUji(PeranAkun::PiutangPencairan, $idOutlet))->toBe('0.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('penjualan yang diretur tetap bisa dicairkan: platform tetap menyetor brutonya', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank, 'Penjualan' => $penjualan] = SiapkanPencairanUji($this);
        $detail = $penjualan[1]->Detail()->orderBy('Urutan')->first();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemRetur($k, $penjualan[1], [['Detail' => $detail, 'Jumlah' => '1']]),
        ]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $pencairan = app(BuatPencairan::class)->Jalankan(
            DataPencairanUji($k, $qris, $bank, UuidPembayaranUji($qris), '148950.00'),
            $k['Pemilik']->Id,
        );

        expect($pencairan->JumlahKotor)->toBe('150000.00')
            ->and(SaldoPeranPencairanUji(PeranAkun::PiutangPencairan, $k['Outlet']->Id))->toBe('0.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });
});

describe('BR-08.4 pengingat & rekap potongan', function (): void {
    it('butir Kotak Tindakan muncul hanya setelah lewat batas wajar menunggu, dan hilang setelah dicairkan', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank] = SiapkanPencairanUji($this);
        $tindakan = function () use ($k): ?array {
            $butir = null;
            $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id])
                ->get('/kelola/tindakan')->assertOk()->assertInertia(function (AssertableInertia $h) use (&$butir) {
                    foreach ($h->toArray()['props']['Butir'] as $b) {
                        if ($b['Kunci'] === 'pencairan.belum-cair') {
                            $butir = $b;
                        }
                    }

                    return $h;
                });

            return $butir;
        };

        // Transaksinya hari ini (28 Sep): QRIS wajar menunggu 3 hari, jadi belum ada apa-apa untuk diperingatkan.
        expect($tindakan())->toBeNull();

        // Tiga hari kemudian uangnya masih belum masuk rekening.
        Carbon::setTestNow('2026-10-01 04:00:00');
        $butir = $tindakan();
        expect($butir)->not->toBeNull()
            ->and($butir['Tingkat'])->toBe('Penting')
            ->and($butir['Jumlah'])->toBe(1)
            // Butir pengingat: tidak ada dokumen yang ditandai "sudah dicek", selesai sendiri saat dicairkan.
            ->and($butir['BolehTandai'])->toBeFalse()
            ->and($butir['Rincian'][0]['Judul'])->toBe('QRIS Statis')
            ->and($butir['Rincian'][0]['Keterangan'])->toContain('2 pembayaran')
            ->and($butir['Rincian'][0]['Keterangan'])->toContain('wajar sampai 3 hari');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(BuatPencairan::class)->Jalankan(
            DataPencairanUji($k, $qris, $bank, UuidPembayaranUji($qris), '148950.00', '2026-10-01'),
            $k['Pemilik']->Id,
        );

        expect($tindakan())->toBeNull();
    });

    it('batas hari menunggu bisa diatur tenant per metode: lebih pendek memunculkan butir lebih cepat, kosong = bawaan jenis. Tunai dan angka tidak sah ditolak', function (): void {
        ['K' => $k, 'Metode' => $qris] = SiapkanPencairanUji($this);
        $tindakan = function () use ($k): ?array {
            $butir = null;
            $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id])
                ->get('/kelola/tindakan')->assertOk()->assertInertia(function (AssertableInertia $h) use (&$butir) {
                    foreach ($h->toArray()['props']['Butir'] as $b) {
                        if ($b['Kunci'] === 'pencairan.belum-cair') {
                            $butir = $b;
                        }
                    }

                    return $h;
                });

            return $butir;
        };

        // Transaksi 28 Sep; dua hari kemudian bawaan QRIS (3 hari) belum memperingatkan.
        Carbon::setTestNow('2026-09-30 04:00:00');
        expect($tindakan())->toBeNull();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(UbahBatasHariMenungguMetode::class)->Jalankan($qris, 1);
        expect($qris->fresh()->BatasHariMenunggu)->toBe(1);
        $butir = $tindakan();
        expect($butir)->not->toBeNull()
            ->and($butir['Rincian'][0]['Keterangan'])->toContain('wajar sampai 1 hari');

        // Dikosongkan: kembali ke bawaan jenis (3 hari).
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        app(UbahBatasHariMenungguMetode::class)->Jalankan($qris, null);
        expect($qris->fresh()->BatasHariMenunggu)->toBeNull()
            ->and($tindakan())->toBeNull();

        // Tunai tidak lewat pencairan; 0 dan 61 di luar 1–60.
        $tunai = MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tunai->value)->firstOrFail();
        $kode = fn (callable $aksi): string => (function () use ($aksi): string {
            try {
                $aksi();
            } catch (PelanggaranAturanBisnis $e) {
                return $e->kode;
            }

            return 'tidak-ditolak';
        })();
        expect($kode(fn () => app(UbahBatasHariMenungguMetode::class)->Jalankan($tunai, 5)))->toBe('MetodeTanpaPencairan')
            ->and($kode(fn () => app(UbahBatasHariMenungguMetode::class)->Jalankan($qris, 0)))->toBe('BatasHariTidakSah')
            ->and($kode(fn () => app(UbahBatasHariMenungguMetode::class)->Jalankan($qris, 61)))->toBe('BatasHariTidakSah');
    });

    it('HTTP: atur batas hari menunggu lewat halaman metode pembayaran. Daftar metode membawa batas berlaku & kustom', function (): void {
        ['K' => $k, 'Metode' => $qris] = SiapkanPencairanUji($this);
        $pemilik = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);

        $pemilik()->post("/kelola/panduan-awal/metode-pembayaran/{$qris->Uuid}/batas-hari-menunggu", ['BatasHariMenunggu' => 7])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($qris->fresh()->BatasHariMenunggu)->toBe(7);

        $pemilik()->get('/kelola/panduan-awal/metode-pembayaran')->assertOk()->assertInertia(function (AssertableInertia $h) use ($qris) {
            $metode = collect($h->toArray()['props']['MetodePembayaran'])->keyBy('Uuid');
            expect($metode[$qris->Uuid]['BatasHariMenunggu'])->toBe(7)
                ->and($metode[$qris->Uuid]['BatasHariKustom'])->toBe(7)
                ->and(collect($metode)->firstWhere('Jenis', 'Tunai')['BatasHariMenunggu'])->toBeNull();

            return $h;
        });

        $pemilik()->post("/kelola/panduan-awal/metode-pembayaran/{$qris->Uuid}/batas-hari-menunggu", ['BatasHariMenunggu' => 99])->assertSessionHasErrors('BatasHariMenunggu');
        $pemilik()->post("/kelola/panduan-awal/metode-pembayaran/{$qris->Uuid}/batas-hari-menunggu", ['BatasHariMenunggu' => null])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($qris->fresh()->BatasHariMenunggu)->toBeNull();
    });

    it('rekap potongan per metode ikut filter tabel dan hanya menghitung yang diposting', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank] = SiapkanPencairanUji($this);
        $uuid = UuidPembayaranUji($qris);
        $pemilik = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);

        // Potongan 2.000 dari 100.000 = 2%, jauh di atas perkiraan 0,7% (700).
        app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, [$uuid[0]], '98000.00'), $k['Pemilik']->Id);
        // Pencairan kedua di bulan berikutnya, supaya bisa dibuktikan saringan tanggalnya bekerja. Waktu uji ikut
        // dimajukan karena setoran bertanggal masa depan memang ditolak.
        Carbon::setTestNow('2026-10-05 04:00:00');
        app(BuatPencairan::class)->Jalankan(DataPencairanUji($k, $qris, $bank, [$uuid[1]], '49650.00', '2026-10-05'), $k['Pemilik']->Id);

        $pemilik()->getJson('/kelola/akuntansi/pencairan')->assertOk()
            ->assertJsonPath('Ringkasan.0.Nama', 'QRIS Statis')
            ->assertJsonPath('Ringkasan.0.Jumlah', 2)
            ->assertJsonPath('Ringkasan.0.JumlahKotor', '150000.00')
            ->assertJsonPath('Ringkasan.0.Biaya', '2350.00')
            ->assertJsonPath('Ringkasan.0.BiayaDiharapkan', '1050.00')
            ->assertJsonPath('Ringkasan.0.Selisih', '1300.00');

        // Disaring ke September saja: tinggal pencairan pertama, dan persen efektifnya 2%.
        $pemilik()->getJson('/kelola/akuntansi/pencairan?'.http_build_query(['saring' => ['Tanggal' => '2026-09-01..2026-09-30']]))
            ->assertOk()
            ->assertJsonPath('Ringkasan.0.Jumlah', 1)
            ->assertJsonPath('Ringkasan.0.JumlahKotor', '100000.00')
            ->assertJsonPath('Ringkasan.0.Biaya', '2000.00')
            ->assertJsonPath('Ringkasan.0.PersenEfektif', '2.0000');

        // Ekspor CSV memakai saringan yang sama dengan tabel: September saja.
        $ekspor = $pemilik()->get('/kelola/akuntansi/pencairan/rekap-potongan/ekspor?'.http_build_query(['saring' => ['Tanggal' => '2026-09-01..2026-09-30']]))->assertOk();
        expect($ekspor->headers->get('Content-Type'))->toContain('text/csv')
            ->and($ekspor->streamedContent())->toContain('Dipotong')
            ->toContain('"QRIS Statis",1,100000.00,2000.00,700.00,1300.00,2.0000')
            ->not->toContain('150000.00');

        // Yang dibatalkan tidak ikut: jurnalnya sudah dibalik, jadi potongannya tidak pernah terjadi.
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pertama = Pencairan::query()->orderBy('Id')->firstOrFail();
        app(BatalkanPencairan::class)->Jalankan($pertama->Uuid, 'Salah metode, seharusnya EDC.', $k['Pemilik']->Id);

        $pemilik()->getJson('/kelola/akuntansi/pencairan')->assertOk()
            ->assertJsonPath('Ringkasan.0.Jumlah', 1)
            ->assertJsonPath('Ringkasan.0.Biaya', '350.00');
    });
});

describe('HTTP pencairan', function (): void {
    it('daftar, formulir, simpan, detail, dan batalkan lewat rute', function (): void {
        ['K' => $k, 'Metode' => $qris, 'Bank' => $bank] = SiapkanPencairanUji($this);
        $pemilik = fn () => $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id]);

        // Daftar menunjukkan uang yang masih menggantung, walau belum ada pencairan sama sekali.
        $pemilik()->get('/kelola/akuntansi/pencairan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/Pencairan/Daftar')
            ->where('BelumDicairkan.0.Nama', 'QRIS Statis')
            ->where('BelumDicairkan.0.Jumlah', 2)
            ->where('BelumDicairkan.0.Total', '150000.00')
            ->where('Izin.Kelola', true)
            ->has('Pencairan.Data', 0));

        $pemilik()->get("/kelola/akuntansi/pencairan/buat?metode={$qris->Uuid}&outlet={$k['Outlet']->Uuid}")
            ->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/Pencairan/Buat')
            ->has('Pembayaran.Data', 2)
            ->where('Pembayaran.Total', '150000.00')
            ->where('Pembayaran.Terpotong', false));

        $pemilik()->post('/kelola/akuntansi/pencairan', [
            'UuidMetodePembayaran' => $qris->Uuid,
            'UuidOutlet' => $k['Outlet']->Uuid,
            'UuidAkunTujuan' => $bank->Uuid,
            'Tanggal' => '2026-09-28',
            'JumlahBersih' => '148950.00',
            'Referensi' => 'SETTLE-0001',
            'UuidPembayaran' => UuidPembayaranUji($qris),
        ])->assertSessionHasNoErrors()->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pencairan = Pencairan::query()->sole();

        $pemilik()->get("/kelola/akuntansi/pencairan/{$pencairan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/Pencairan/Detail')
            ->where('Pencairan.Biaya', '1050.00')
            ->where('Pencairan.SelisihBiaya', '0.00')
            ->where('Tindakan.Batalkan', true)
            ->has('Baris', 2)
            ->has('Jurnal', 1));

        // Setelah dicairkan, tidak ada lagi yang menggantung di daftar.
        $pemilik()->get('/kelola/akuntansi/pencairan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('BelumDicairkan', 0)
            ->has('Pencairan.Data', 1));

        $pemilik()->post("/kelola/akuntansi/pencairan/{$pencairan->Uuid}/batalkan", ['Alasan' => 'Setoran ternyata milik outlet lain.'])
            ->assertSessionHasNoErrors()->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($pencairan->refresh()->Status)->toBe(StatusDokumenTerposting::Dibatalkan);
    });

    it('kasir tidak boleh melihat maupun mencatat, dan dokumen tak dikenal 404', function (): void {
        ['K' => $k] = SiapkanPencairanUji($this);

        BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/akuntansi/pencairan')->assertForbidden();
        $this->post('/kelola/akuntansi/pencairan', [])->assertForbidden();

        // Sesi kasir dibersihkan dulu: `AuthenticateSession` menolak sesi yang masih membawa jejak pengguna lain.
        $this->flushSession();
        $this->actingAs($k['Pemilik'])->withSession(['IdTenantAktif' => $k['Tenant']->Id])
            ->get('/kelola/akuntansi/pencairan/'.strtoupper((string) Str::ulid()))->assertNotFound();
    });
});
