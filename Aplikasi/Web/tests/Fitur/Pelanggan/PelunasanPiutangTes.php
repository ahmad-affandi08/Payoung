<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-12 bagian 1 back-office (PRD "Rincian F-12"): daftar piutang terbuka dengan umur 0–30/31–60/61–90/>90 hari,
 * pelunasan sebagian & banyak piutang sekaligus (jurnal Dr Kas/Bank, Cr Piutang Usaha), pembatalan dengan jurnal
 * pembalik, izin (`pelanggan.lihat` / `akuntansi.kelola`), isolasi tenant, dan limit kredit & termin di data pelanggan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant + dua penjualan tempo Rp 77.000 ke Toko Makmur Jaya (limit Rp 5 juta, termin 30 hari), pemilik masuk.
 *
 * @return array<string, mixed>
 */
function SiapkanPiutangDuaFaktur(TestCase $tes, string $nama = 'Grosir Sembako Pelunasan'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $nama);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $toko = Pelanggan::query()->create(['Nama' => 'Toko Makmur Jaya', 'NoHp' => '6281355550001', 'LimitKredit' => '5000000', 'TerminHari' => 30]);
    $item = fn (): array => BantuanPenjualan::Item(
        $k,
        ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $k['Tempo'], 'Jumlah' => '77000.00']]],
        ['UuidPelanggan' => $toko->Uuid],
    );

    expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$item(), $item()]))->toBe([['Diterima', null], ['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);
    $piutang = Piutang::query()->orderBy('Id')->get();

    return $k + ['Toko' => $toko, 'P1' => $piutang[0], 'P2' => $piutang[1]];
}

/**
 * @param  array<string, mixed>  $k
 * @param  list<array{0: Piutang, 1: string}>  $alokasi
 * @return array<string, mixed>
 */
function IsianPelunasan(array $k, array $alokasi, ?string $tanggal = null): array
{
    return [
        'UuidPelanggan' => $k['Toko']->Uuid,
        'UuidAkun' => BantuanPembelian::AkunKas()->Uuid,
        'Tanggal' => $tanggal ?? BantuanPembelian::Hari()->format('Y-m-d'),
        'Catatan' => 'Transfer BCA a.n. Toko Makmur Jaya',
        'Alokasi' => array_map(fn (array $a): array => ['UuidPiutang' => $a[0]->Uuid, 'Jumlah' => $a[1]], $alokasi),
    ];
}

function SaldoJurnalSeimbang(int $idJurnal): bool
{
    $debit = Uang::Nol();
    $kredit = Uang::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->get() as $b) {
        $debit = $debit->Tambah(Uang::Dari((string) $b->Debit));
        $kredit = $kredit->Tambah(Uang::Dari((string) $b->Kredit));
    }

    return $debit->Bandingkan($kredit) === 0 && ! $debit->BernilaiNol();
}

describe('F-12 pelunasan piutang', function (): void {
    it('melunasi banyak piutang sekaligus, sebagian; jurnal Dr Kas Cr Piutang Usaha seimbang; saldo Piutang Usaha = Σ sisa', function (): void {
        $k = SiapkanPiutangDuaFaktur($this);
        $idTenant = $k['Tenant']->Id;

        $this->get('/kelola/piutang')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Piutang/Daftar')->has('Piutang.Data', 2)->where('Piutang.Ringkasan.Total', '154000.00')
            ->where('Piutang.Data.0.Umur', 'BelumJatuhTempo')->where('Izin.Kelola', true));
        $this->get("/kelola/piutang/pelunasan/buat?pelanggan={$k['Toko']->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Piutang/Pelunasan/Form')->has('Piutang', 2)->where('Piutang.0.Sisa', '77000.00')->has('OpsiAkun'));

        $this->post('/kelola/piutang/pelunasan', IsianPelunasan($k, [[$k['P1'], '77000'], [$k['P2'], '30000.50']]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $bayar = PembayaranPiutang::query()->sole();

        expect($bayar->Nomor)->toStartWith('BP/')
            ->and((string) $bayar->Jumlah)->toBe('107000.50')
            ->and($k['P1']->refresh()->Status)->toBe(StatusPiutang::Lunas)
            ->and($k['P2']->refresh()->Status)->toBe(StatusPiutang::DibayarSebagian)
            ->and($k['P2']->AmbilSisa()->KeString())->toBe('46999.50')
            ->and(SaldoJurnalSeimbang((int) $bayar->IdJurnal))->toBeTrue()
            ->and(BantuanPembelian::SaldoPeran($idTenant, PeranAkun::PiutangUsaha))->toBe('46999.50')
            ->and(LogAudit::query()->where('Peristiwa', 'pelunasan-piutang.posting')->count())->toBe(1);

        $this->get("/kelola/piutang/pelunasan/{$bayar->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Piutang/Pelunasan/Detail')->has('Alokasi', 2)->where('Pelunasan.Jumlah', '107000.50')
            ->where('Tindakan.Batalkan', true)->has('Jurnal'));
        $this->getJson('/kelola/piutang/pelunasan?cari=')->assertOk()->assertJsonPath('Meta.Total', 1);
        $this->getJson('/kelola/piutang?cari=')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Ringkasan.Total', '46999.50');
    });

    it('menolak jumlah melebihi sisa, piutang pelanggan lain, dan tanggal setelah hari ini', function (): void {
        $k = SiapkanPiutangDuaFaktur($this);
        $lain = Pelanggan::query()->create(['Nama' => 'Warung Bu Sri', 'NoHp' => '6281355550002']);

        $this->post('/kelola/piutang/pelunasan', IsianPelunasan($k, [[$k['P1'], '77000.01']]))->assertSessionHasErrors("Alokasi.{$k['P1']->Uuid}");
        $this->post('/kelola/piutang/pelunasan', ['UuidPelanggan' => $lain->Uuid] + IsianPelunasan($k, [[$k['P1'], '1000']]))->assertSessionHasErrors("Alokasi.{$k['P1']->Uuid}");
        $this->post('/kelola/piutang/pelunasan', IsianPelunasan($k, [[$k['P1'], '1000']], BantuanPembelian::Hari()->addDays(2)->format('Y-m-d')))->assertSessionHasErrors('Tanggal');
        $this->post('/kelola/piutang/pelunasan', IsianPelunasan($k, [[$k['P1'], '0']]))->assertSessionHasErrors("Alokasi.{$k['P1']->Uuid}");

        expect(PembayaranPiutang::query()->count())->toBe(0)
            ->and($k['P1']->refresh()->Status)->toBe(StatusPiutang::BelumLunas);
    });

    it('pembatalan mengembalikan sisa piutang dengan jurnal pembalik; idempoten; void penjualan terbayar ditolak lebih dulu', function (): void {
        $k = SiapkanPiutangDuaFaktur($this);
        $this->post('/kelola/piutang/pelunasan', IsianPelunasan($k, [[$k['P1'], '77000'], [$k['P2'], '10000']]))->assertSessionHasNoErrors();
        $bayar = PembayaranPiutang::query()->sole();

        // Piutang yang sudah dibayar sebagian tidak bisa di-void (pakai retur).
        $jual = Penjualan::query()->whereKey($k['P2']->IdPenjualan)->sole();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Ditolak', 'VoidTidakDiizinkan']]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);

        $this->post("/kelola/piutang/pelunasan/{$bayar->Uuid}/batalkan", ['Alasan' => 'abc'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/piutang/pelunasan/{$bayar->Uuid}/batalkan", ['Alasan' => 'Salah pilih pelanggan'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post("/kelola/piutang/pelunasan/{$bayar->Uuid}/batalkan", ['Alasan' => 'Salah pilih pelanggan'])->assertSessionHasNoErrors();
        $bayar->refresh();

        expect($bayar->Status)->toBe(StatusPembayaranPiutang::Dibatalkan)
            ->and($bayar->IdJurnalPembatalan)->not->toBeNull()
            ->and(Jurnal::query()->whereKey($bayar->IdJurnalPembatalan)->count())->toBe(1)
            ->and(SaldoJurnalSeimbang((int) $bayar->IdJurnalPembatalan))->toBeTrue()
            ->and($k['P1']->refresh()->Status)->toBe(StatusPiutang::BelumLunas)
            ->and($k['P2']->refresh()->Status)->toBe(StatusPiutang::BelumLunas)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PiutangUsaha))->toBe('154000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'pelunasan-piutang.batalkan')->count())->toBe(1);

        $this->get("/kelola/piutang/pelunasan/{$bayar->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tindakan.Batalkan', false)->where('Pelunasan.AlasanBatal', 'Salah pilih pelanggan'));
    });

    it('umur piutang dikelompokkan per tanggal laporan dan bisa difilter', function (): void {
        $k = SiapkanPiutangDuaFaktur($this);
        // Umur piutang tingkat usaha dihitung dari tanggal kalender zona tenant (tanpa jam tutup buku outlet), jadi
        // jatuh tempo diatur dari tanggal kalender WIB agar test juga benar antara 00.00–04.00 WIB.
        $hariIni = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        Piutang::query()->whereKey($k['P1']->Id)->update(['JatuhTempo' => $hariIni->subDays(45)->toDateString()]);
        Piutang::query()->whereKey($k['P2']->Id)->update(['JatuhTempo' => $hariIni->subDays(95)->toDateString()]);

        $this->get('/kelola/piutang')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Piutang.Ringkasan.Kelompok.2.Kunci', 'Hari31Sampai60')->where('Piutang.Ringkasan.Kelompok.2.Sisa', '77000.00')
            ->where('Piutang.Ringkasan.Kelompok.4.Kunci', 'LebihDari90')->where('Piutang.Ringkasan.Kelompok.4.Jumlah', 1));
        $this->getJson('/kelola/piutang?saring[Umur]=Hari31Sampai60')->assertOk()
            ->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.Uuid', $k['P1']->Uuid)->assertJsonPath('Data.0.HariLewat', 45);
    });

    it('izin: kasir tidak bisa melihat piutang; supervisor melihat tetapi tidak bisa melunasi; tenant lain 404', function (): void {
        $k = SiapkanPiutangDuaFaktur($this);
        $this->post('/kelola/piutang/pelunasan', IsianPelunasan($k, [[$k['P1'], '5000']]))->assertSessionHasNoErrors();
        $bayar = PembayaranPiutang::query()->sole();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/piutang')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/piutang')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.Kelola', false));
        $this->get('/kelola/piutang/pelunasan/buat')->assertForbidden();
        $this->post('/kelola/piutang/pelunasan', IsianPelunasan($k, [[$k['P1'], '5000']]))->assertForbidden();
        $this->post("/kelola/piutang/pelunasan/{$bayar->Uuid}/batalkan", ['Alasan' => 'Tidak berwenang'])->assertForbidden();

        $b = BantuanPenjualan::Siapkan($this, 'Toko Tenant Lain');
        BantuanOrganisasi::Masuk($this, $b['Pemilik'], $b['Tenant']->Id);
        $this->get("/kelola/piutang/pelunasan/{$bayar->Uuid}")->assertNotFound();
        $this->get('/kelola/piutang')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Piutang.Data', 0));
    });

    it('limit kredit & termin disimpan dari formulir pelanggan; detail menampilkan posisi kredit', function (): void {
        $k = SiapkanPiutangDuaFaktur($this);
        $isian = ['Nama' => 'Toko Makmur Jaya', 'NoHp' => '081355550001', 'SetujuPemasaran' => false];

        $this->put("/kelola/pelanggan/{$k['Toko']->Uuid}", $isian + ['LimitKredit' => '12,5', 'TerminHari' => 14])->assertSessionHasErrors('LimitKredit');
        $this->put("/kelola/pelanggan/{$k['Toko']->Uuid}", $isian + ['LimitKredit' => '7500000', 'TerminHari' => 14])->assertSessionHasNoErrors();
        $toko = $k['Toko']->refresh();
        expect((string) $toko->LimitKredit)->toBe('7500000.00')->and($toko->TerminHari)->toBe(14);

        $this->get("/kelola/pelanggan/{$toko->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Pelanggan.LimitKredit', '7500000.00')->where('Pelanggan.TerminHari', 14)
            ->where('Kredit.SisaPiutang', '154000.00')->where('Kredit.HariLewatJatuhTempo', 0));

        // Tanpa kolom kredit (klien lama) = limit tidak berubah; limit kosong = tidak boleh tempo.
        $this->put("/kelola/pelanggan/{$toko->Uuid}", $isian)->assertSessionHasNoErrors();
        expect((string) $toko->refresh()->LimitKredit)->toBe('7500000.00');
        $this->put("/kelola/pelanggan/{$toko->Uuid}", $isian + ['LimitKredit' => '', 'TerminHari' => 30])->assertSessionHasNoErrors();
        expect($toko->refresh()->LimitKredit)->toBeNull();
    });
});

describe('F-12 metode Tempo', function (): void {
    it('limit kredit pertama menyiapkan metode Tempo sekali; tanpa limit tidak dibuat', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Bangunan Sumber Makmur');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $isian = ['Nama' => 'CV Karya Beton', 'NoHp' => '081355550009', 'SetujuPemasaran' => false];
        $tempo = fn (): int => MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tempo->value)->count();

        $this->post('/kelola/pelanggan', $isian + ['LimitKredit' => '', 'TerminHari' => 30])->assertSessionHasNoErrors();
        expect($tempo())->toBe(0);

        $cv = Pelanggan::query()->sole();
        $this->put("/kelola/pelanggan/{$cv->Uuid}", $isian + ['LimitKredit' => '25000000', 'TerminHari' => 45])->assertSessionHasNoErrors();
        $this->put("/kelola/pelanggan/{$cv->Uuid}", $isian + ['LimitKredit' => '30000000', 'TerminHari' => 45])->assertSessionHasNoErrors();

        expect($tempo())->toBe(1)
            ->and(MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::Tempo->value)->sole()->Aktif)->toBeTrue()
            ->and(LogAudit::query()->where('Peristiwa', 'metode-pembayaran.buat')->count())->toBeGreaterThanOrEqual(1);
    });
});
