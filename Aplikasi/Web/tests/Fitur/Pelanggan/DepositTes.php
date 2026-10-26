<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Pelanggan\Enum\JenisMutasiDeposit;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Model\MutasiDeposit;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusIsiDeposit;
use App\Domain\Penjualan\Kueri\RingkasanPenjualanShift;
use App\Domain\Penjualan\Model\IsiDeposit;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16d bagian 1 (PRD "Rincian F-16d", CRM-04): deposit pelanggan. Isi deposit di kasir lewat outbox `Deposit.Isi`
 * (offline, idempoten, jurnal J-16.1 Dr kas/kliring Cr Saldo Deposit Pelanggan, masuk kas shift), bayar penjualan dengan
 * deposit (saldo dipotong; saldo kurang = diterima + tinjauan DepositKurang), void mengembalikan, retur boleh direfund ke
 * deposit, tarik & sesuaikan deposit di back-office, batal isi deposit, isolasi tenant, dan invarian
 * Σ SaldoDeposit = −saldo akun Deposit Pelanggan = Σ MutasiDeposit.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant kasir + produk Rp 38.500 + Ani (pelanggan) + metode Deposit.
 *
 * @return array<string, mixed>
 */
function SiapkanDeposit(TestCase $tes, string $namaUsaha = 'Laundry Bersih Deposit Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $ani = Pelanggan::query()->create(['Nama' => 'Ani Rahmawati', 'NoHp' => '6281234567890']);

    return $k + [
        'Produk' => $produk,
        'Ani' => $ani,
        'Deposit' => BantuanPenjualan::BuatMetode(JenisMetodePembayaran::Deposit, 'Deposit pelanggan'),
    ];
}

/**
 * Item outbox `Deposit.Isi`.
 *
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemIsiDeposit(array $k, string $jumlah = '100000.00', int $urutan = 1, array $timpa = [], ?string $uuid = null): array
{
    $waktu = CarbonImmutable::now()->subMinutes(3);
    $tanggal = $waktu->setTimezone($k['Outlet']->ZonaWaktu)->format('ymd');

    return [
        'Jenis' => 'Deposit.Isi',
        'Uuid' => $uuid ?? BantuanKasir::Uuid(),
        'Data' => array_replace([
            'UuidPelanggan' => $k['Ani']->Uuid,
            'Jumlah' => $jumlah,
            'UuidMetodePembayaran' => $k['Tunai']->Uuid,
            'Referensi' => null,
            'UuidShift' => $k['UuidShift'],
            'UuidPengguna' => $k['Kasir']->Uuid,
            'Nomor' => "DEP/{$k['Outlet']->Kode}/{$tanggal}/{$k['Perangkat']->Kode}-".str_pad((string) $urutan, 4, '0', STR_PAD_LEFT),
            'DibuatPada' => $waktu->utc()->toIso8601ZuluString(),
        ], $timpa),
    ];
}

/**
 * @param  array<string, mixed>  $k
 */
function IsiDepositAni(TestCase $tes, array $k, string $jumlah = '100000.00', int $urutan = 1): IsiDeposit
{
    $item = ItemIsiDeposit($k, $jumlah, $urutan);
    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    return IsiDeposit::query()->where('Uuid', $item['Uuid'])->firstOrFail();
}

function SaldoDepositAni(Pelanggan $p): string
{
    return (string) Pelanggan::query()->whereKey($p->Id)->value('SaldoDeposit');
}

/**
 * Invarian deposit: Σ `Pelanggan.SaldoDeposit` = Σ `MutasiDeposit.Jumlah`; −saldo akun Deposit Pelanggan = Σ mutasi + isi
 * deposit pelanggan tak dikenal yang belum dibatalkan; setiap
 * `SaldoSetelah` mutasi = jumlah berjalan, plus jurnal seimbang.
 *
 * @return list<string>
 */
function PeriksaInvarianDeposit(int $idTenant): array
{
    $galat = PemeriksaInvarian::PeriksaJurnalSeimbang($idTenant);
    $cache = (string) DB::selectOne('SELECT CAST(COALESCE(SUM(`SaldoDeposit`), 0) AS DECIMAL(18,2)) AS S FROM `Pelanggan` WHERE `IdTenant` = ?', [$idTenant])->S;
    $ledger = (string) DB::selectOne('SELECT CAST(COALESCE(SUM(`Jumlah`), 0) AS DECIMAL(18,2)) AS S FROM `MutasiDeposit` WHERE `IdTenant` = ?', [$idTenant])->S;
    $akun = BantuanPembelian::SaldoPeran($idTenant, PeranAkun::DepositPelanggan);

    if (! BigDecimal::of($cache)->isEqualTo(BigDecimal::of($ledger))) {
        $galat[] = "Σ SaldoDeposit {$cache} ≠ Σ MutasiDeposit {$ledger}";
    }

    // Isi deposit untuk pelanggan yang belum dikenal server (tinjauan) sudah dikredit ke akun, belum ke saldo pelanggan.
    $belumTeralokasi = (string) DB::selectOne("SELECT CAST(COALESCE(SUM(`Jumlah`), 0) AS DECIMAL(18,2)) AS S FROM `IsiDeposit` WHERE `IdTenant` = ? AND `IdPelanggan` IS NULL AND `Status` = 'Diterima'", [$idTenant])->S;
    $harapan = BigDecimal::of($ledger)->plus($belumTeralokasi);

    if (! BigDecimal::of($akun)->isEqualTo($harapan->negated())) {
        $galat[] = "Saldo akun DepositPelanggan {$akun} ≠ −(Σ MutasiDeposit {$ledger} + isi belum teralokasi {$belumTeralokasi})";
    }

    $berjalan = [];

    foreach (DB::table('MutasiDeposit')->where('IdTenant', $idTenant)->orderBy('Id')->get() as $m) {
        $berjalan[$m->IdPelanggan] = BigDecimal::of($berjalan[$m->IdPelanggan] ?? '0')->plus((string) $m->Jumlah);

        if (! $berjalan[$m->IdPelanggan]->isEqualTo(BigDecimal::of((string) $m->SaldoSetelah))) {
            $galat[] = "MutasiDeposit {$m->Id} SaldoSetelah {$m->SaldoSetelah} ≠ jumlah berjalan {$berjalan[$m->IdPelanggan]}";
        }
    }

    return $galat;
}

describe('F-16d isi deposit dari POS', function (): void {
    it('isi tunai: dokumen Diterima, saldo bertambah, jurnal J-16.1 Dr Kas Outlet Cr Deposit Pelanggan, masuk kas shift. Kirim ulang = Duplikat', function (): void {
        $k = SiapkanDeposit($this);
        $item = ItemIsiDeposit($k, '150000.00');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $isi = IsiDeposit::query()->where('Uuid', $item['Uuid'])->sole();
        expect($isi->Status)->toBe(StatusIsiDeposit::Diterima)
            ->and($isi->PerluTinjauan)->toBeFalse()
            ->and($isi->Jumlah)->toBe('150000.00')
            ->and($isi->IdJurnal)->not->toBeNull()
            ->and(SaldoDepositAni($k['Ani']))->toBe('150000.00')
            ->and(MutasiDeposit::query()->where('IdPelanggan', $k['Ani']->Id)->sole()->Jenis)->toBe(JenisMutasiDeposit::Isi)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::DepositPelanggan))->toBe('-150000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::KasOutlet))->toBe('150000.00');

        $ringkasan = app(RingkasanPenjualanShift::class)->Ambil((int) Shift::query()->where('Uuid', $k['UuidShift'])->value('Id'));
        expect($ringkasan->jumlahIsiDeposit)->toBe(1)
            ->and($ringkasan->nominalIsiDeposit?->KeString())->toBe('150000.00')
            ->and($ringkasan->tunaiMasukBersih->KeString())->toBe('150000.00')
            ->and($ringkasan->jumlahTransaksi)->toBe(0);
        expect(PeriksaInvarianDeposit($k['Tenant']->Id))->toBe([]);

        // Data awal POS: pengaturan deposit & nomor urut isi deposit perangkat (pasang ulang aplikasi tidak memakai ulang nomor).
        $tanggal = substr(explode('/', $isi->Nomor)[2], 0, 6);
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()
            ->assertJsonPath('Deposit.Berlaku', true)
            ->assertJsonPath('Deposit.MinimalIsi', '1000.00')
            ->assertJsonPath('Deposit.MaksimalIsi', '10000000.00')
            ->assertJsonPath("Perangkat.NomorUrutIsiDeposit.{$tanggal}", 1);
    });

    it('menolak jumlah bukan Rupiah bulat/di luar batas, nomor salah format atau dipakai, metode Deposit/Tempo, dan Uuid dipakai ulang dengan isi berbeda', function (): void {
        $k = SiapkanDeposit($this);
        $kirim = fn (array $item): array => BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);

        expect($kirim(ItemIsiDeposit($k, '100000.50')))->toBe([['Ditolak', 'JumlahTidakValid']])
            ->and($kirim(ItemIsiDeposit($k, '500.00')))->toBe([['Ditolak', 'JumlahTidakValid']])
            ->and($kirim(ItemIsiDeposit($k, '10000001.00')))->toBe([['Ditolak', 'JumlahTidakValid']])
            ->and($kirim(ItemIsiDeposit($k, timpa: ['Nomor' => 'DEP/SALAH/260101/X-0001'])))->toBe([['Ditolak', 'NomorTidakValid']])
            ->and($kirim(ItemIsiDeposit($k, timpa: ['UuidMetodePembayaran' => $k['Deposit']->Uuid])))->toBe([['Ditolak', 'MetodeBayarBelumDidukung']])
            ->and($kirim(ItemIsiDeposit($k, timpa: ['UuidMetodePembayaran' => $k['Tempo']->Uuid])))->toBe([['Ditolak', 'MetodeBayarBelumDidukung']]);

        $pertama = ItemIsiDeposit($k, '50000.00', 7);
        expect($kirim($pertama))->toBe([['Diterima', null]])
            ->and($kirim(ItemIsiDeposit($k, '60000.00', 7)))->toBe([['Ditolak', 'NomorSudahDipakai']])
            ->and($kirim(ItemIsiDeposit($k, '60000.00', 8, uuid: $pertama['Uuid'])))->toBe([['Ditolak', 'UuidSudahDipakai']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(IsiDeposit::query()->count())->toBe(1)
            ->and(SaldoDepositAni($k['Ani']))->toBe('50000.00');
    });

    it('pelanggan belum dikenal server: diterima + tinjauan, saldo tidak bertambah; QRIS didebit ke kliring', function (): void {
        $k = SiapkanDeposit($this);
        $item = ItemIsiDeposit($k, '75000.00', timpa: ['UuidPelanggan' => BantuanKasir::Uuid(), 'UuidMetodePembayaran' => $k['Qris']->Uuid, 'Referensi' => 'QR-8899']);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $isi = IsiDeposit::query()->sole();
        expect($isi->PerluTinjauan)->toBeTrue()
            ->and($isi->AlasanTinjauan)->toContain('PelangganTidakDikenal')
            ->and($isi->IdPelanggan)->toBeNull()
            ->and(MutasiDeposit::query()->count())->toBe(0)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PiutangPencairan))->toBe('75000.00');
    });

    it('paket tanpa fitur pelanggan.deposit: ditolak FiturTidakAktif', function (): void {
        $k = SiapkanDeposit($this);
        Langganan::query()->where('IdTenant', $k['Tenant']->Id)->update(['IdPaket' => Paket::query()->where('Kode', 'STARTER')->value('Id')]);
        cache()->flush();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemIsiDeposit($k)]))->toBe([['Ditolak', 'FiturTidakAktif']]);
    });
});

/**
 * Jual 2 × Rp 38.500 atas nama Ani, dibayar deposit [deposit] (sisa tunai).
 *
 * @param  array<string, mixed>  $k
 */
function JualDenganDeposit(TestCase $tes, array $k, string $deposit = '77000.00', string $status = 'Diterima'): ?Penjualan
{
    $pembayaran = [['Metode' => $k['Deposit'], 'Jumlah' => $deposit]];

    if ($deposit !== '77000.00') {
        $pembayaran[] = ['Metode' => $k['Tunai'], 'Jumlah' => null];
    }

    $item = BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']],
        'Pembayaran' => $pembayaran,
    ], ['UuidPelanggan' => $k['Ani']->Uuid]);
    $hasil = BantuanKasir::KirimRingkas($tes, $k['Token'], [$item]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($hasil[0][0])->toBe($status);

    return Penjualan::query()->where('Uuid', $item['Uuid'])->first();
}

describe('F-16d bayar dengan deposit', function (): void {
    it('memotong saldo di transaksi penjualan, bukan uang masuk laci. Void mengembalikan saldo. Invarian terjaga', function (): void {
        $k = SiapkanDeposit($this);
        IsiDepositAni($this, $k, '100000.00');

        $jual = JualDenganDeposit($this, $k);
        expect($jual?->PerluTinjauan)->toBeFalse()
            ->and(SaldoDepositAni($k['Ani']))->toBe('23000.00')
            ->and(MutasiDeposit::query()->where('Jenis', JenisMutasiDeposit::Pemakaian->value)->sole()->Jumlah)->toBe('-77000.00');

        $ringkasan = app(RingkasanPenjualanShift::class)->Ambil((int) Shift::query()->where('Uuid', $k['UuidShift'])->value('Id'));
        expect($ringkasan->tunaiMasukBersih->KeString())->toBe('100000.00');
        expect(PeriksaInvarianDeposit($k['Tenant']->Id))->toBe([]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoDepositAni($k['Ani']))->toBe('100000.00')
            ->and(MutasiDeposit::query()->where('Jenis', JenisMutasiDeposit::BatalPemakaian->value)->sole()->Jumlah)->toBe('77000.00');
        expect(PeriksaInvarianDeposit($k['Tenant']->Id))->toBe([]);
    });

    it('saldo kurang (dipakai dua perangkat): tetap diterima, saldo minus, tinjauan DepositKurang, tanpa pelanggan atau dua pembayaran deposit ditolak', function (): void {
        $k = SiapkanDeposit($this);
        IsiDepositAni($this, $k, '50000.00');

        $jual = JualDenganDeposit($this, $k);
        expect($jual?->PerluTinjauan)->toBeTrue()
            ->and($jual?->AlasanTinjauan)->toContain('DepositKurang')
            ->and(SaldoDepositAni($k['Ani']))->toBe('-27000.00');
        expect(PeriksaInvarianDeposit($k['Tenant']->Id))->toBe([]);

        $tanpaPelanggan = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['Deposit'], 'Jumlah' => '38500.00']],
        ]);
        $duaDeposit = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['Deposit'], 'Jumlah' => '20000.00'], ['Metode' => $k['Deposit'], 'Jumlah' => '18500.00']],
        ], ['UuidPelanggan' => $k['Ani']->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpaPelanggan]))->toBe([['Ditolak', 'DepositTanpaPelanggan']])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$duaDeposit]))->toBe([['Ditolak', 'PembayaranTidakValid']]);
    });

    it('retur penjualan berpelanggan boleh direfund ke deposit (saldo bertambah, jurnal Cr Deposit Pelanggan)', function (): void {
        $k = SiapkanDeposit($this);
        IsiDepositAni($this, $k, '100000.00');
        $jual = JualDenganDeposit($this, $k, '50000.00');
        expect(SaldoDepositAni($k['Ani']))->toBe('50000.00');

        $detail = PenjualanDetail::query()->where('IdPenjualan', $jual?->Id)->firstOrFail();
        $retur = BantuanPenjualan::ItemRetur($k, $jual, [['Detail' => $detail, 'Jumlah' => '1']], ['Refund' => [['Metode' => $k['Deposit'], 'Jumlah' => null]]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$retur]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(SaldoDepositAni($k['Ani']))->toBe('88500.00')
            ->and(MutasiDeposit::query()->where('Jenis', JenisMutasiDeposit::Refund->value)->sole()->Jumlah)->toBe('38500.00');
        expect(PeriksaInvarianDeposit($k['Tenant']->Id))->toBe([]);
    });
});

describe('F-16d deposit di back-office', function (): void {
    it('detail pelanggan menampilkan saldo & riwayat; tarik deposit ke kas (Dr Deposit Cr Kas); sesuaikan +/−; audit; tidak boleh melebihi saldo', function (): void {
        $k = SiapkanDeposit($this);
        IsiDepositAni($this, $k, '200000.00');
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $ani = $k['Ani'];

        $this->get("/kelola/pelanggan/{$ani->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Deposit.Saldo', '200000.00')->where('Deposit.Berlaku', true)->has('Deposit.Riwayat', 1)
            ->where('Izin.KelolaDeposit', true)->has('Deposit.AkunKasBank'));
        $this->get('/kelola/pelanggan?urut=-SaldoDeposit', ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('Data.0.SaldoDeposit', '200000.00');

        $kas = BantuanPembelian::AkunKas()->Uuid;
        $this->post("/kelola/pelanggan/{$ani->Uuid}/deposit/tarik", ['Jumlah' => '250000', 'UuidAkun' => $kas, 'Alasan' => 'Pelanggan pindah kota'])
            ->assertSessionHasErrors('Jumlah');
        $this->post("/kelola/pelanggan/{$ani->Uuid}/deposit/tarik", ['Jumlah' => '50000', 'UuidAkun' => $kas, 'Alasan' => 'abc'])
            ->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pelanggan/{$ani->Uuid}/deposit/tarik", ['Jumlah' => '50000', 'UuidAkun' => $kas, 'Alasan' => 'Pelanggan pindah kota'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post("/kelola/pelanggan/{$ani->Uuid}/deposit/sesuaikan", ['Jumlah' => '-25000', 'Alasan' => 'Koreksi salah input kasir'])
            ->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/{$ani->Uuid}/deposit/sesuaikan", ['Jumlah' => '5000', 'Alasan' => 'Kompensasi keterlambatan'])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoDepositAni($ani))->toBe('130000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::DepositPelanggan))->toBe('-130000.00')
            ->and(DB::table('LogAudit')->where('IdTenant', $k['Tenant']->Id)->where('Peristiwa', 'pelanggan.deposit-tarik')->count())->toBe(1)
            ->and(DB::table('LogAudit')->where('IdTenant', $k['Tenant']->Id)->where('Peristiwa', 'pelanggan.deposit-sesuaikan')->count())->toBe(2);
        expect(PeriksaInvarianDeposit($k['Tenant']->Id))->toBe([]);
    });

    it('batal isi deposit: status Dibatalkan, saldo & jurnal dibalik. Tidak bisa bila saldo sudah dipakai. Daftar isi deposit', function (): void {
        $k = SiapkanDeposit($this);
        $isi = IsiDepositAni($this, $k, '100000.00');
        $isiKedua = IsiDepositAni($this, $k, '30000.00', 2);
        JualDenganDeposit($this, $k, '77000.00');
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->get('/kelola/pelanggan/isi-deposit', ['Accept' => 'application/json'])->assertOk()->assertJsonPath('Meta.Total', 2);
        $this->post("/kelola/pelanggan/isi-deposit/{$isi->Uuid}/batal", ['Alasan' => 'Salah pelanggan'])->assertSessionHasErrors();
        $this->post("/kelola/pelanggan/isi-deposit/{$isiKedua->Uuid}/batal", ['Alasan' => 'Salah pelanggan'])->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/isi-deposit/{$isiKedua->Uuid}/batal", ['Alasan' => 'Salah pelanggan'])->assertSessionHasErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($isiKedua->refresh()->Status)->toBe(StatusIsiDeposit::Dibatalkan)
            ->and($isiKedua->IdJurnalBatal)->not->toBeNull()
            ->and(SaldoDepositAni($k['Ani']))->toBe('23000.00');
        expect(PeriksaInvarianDeposit($k['Tenant']->Id))->toBe([]);
    });

    it('kasir tanpa izin pelanggan.deposit.kelola tidak bisa menarik deposit', function (): void {
        $k = SiapkanDeposit($this);
        IsiDepositAni($this, $k, '100000.00');
        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);

        $this->post("/kelola/pelanggan/{$k['Ani']->Uuid}/deposit/tarik", ['Jumlah' => '1000', 'UuidAkun' => BantuanPembelian::AkunKas()->Uuid, 'Alasan' => 'Coba tarik'])
            ->assertForbidden();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoDepositAni($k['Ani']))->toBe('100000.00');
    });
});

describe('F-16d API POS saldo deposit & isolasi tenant', function (): void {
    it('perangkat membaca saldo pelanggan sendiri. Pelanggan tenant lain atau diarsipkan = 404', function (): void {
        $k = SiapkanDeposit($this);
        IsiDepositAni($this, $k, '100000.00');

        $this->withToken($k['Token'])->getJson("/api/pos/v1/pelanggan/{$k['Ani']->Uuid}/deposit")->assertOk()
            ->assertJsonPath('Pelanggan.SaldoDeposit', '100000.00')->assertJsonPath('Berlaku', true);

        $lain = SiapkanDeposit($this, 'Toko Tetangga Deposit');
        IsiDepositAni($this, $lain, '60000.00');
        $this->withToken($k['Token'])->getJson("/api/pos/v1/pelanggan/{$lain['Ani']->Uuid}/deposit")->assertNotFound();

        // Isi deposit tenant A tidak bisa memakai pelanggan tenant B.
        $silang = ItemIsiDeposit($k, '10000.00', 5, ['UuidPelanggan' => $lain['Ani']->Uuid]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$silang]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
        expect(SaldoDepositAni($lain['Ani']))->toBe('60000.00');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(IsiDeposit::query()->where('Uuid', $silang['Uuid'])->sole()->PerluTinjauan)->toBeTrue();

        Pelanggan::query()->whereKey($k['Ani']->Id)->update(['Status' => StatusPelanggan::Diarsipkan->value]);
        $this->withToken($k['Token'])->getJson("/api/pos/v1/pelanggan/{$k['Ani']->Uuid}/deposit")->assertNotFound();
        expect(PeriksaInvarianDeposit($k['Tenant']->Id))->toBe([])
            ->and(PeriksaInvarianDeposit($lain['Tenant']->Id))->toBe([]);
    });
});
