<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Kasir\Kueri\LaporanShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusPesananPenjualan;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesananPenjualan;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-12 bagian 2 (SLS-02 pre-order & uang muka, PRD "Rincian F-12 bagian 2"): pre-order dibuat kasir offline lewat
 * outbox `PesananPenjualan.Buat` (DP = Uang Muka Pelanggan, J-07.3, tanpa stok & pendapatan, ikut kas shift),
 * diambil lewat `Penjualan.Buat` bermetode Uang Muka (pendapatan & stok saat diserahkan), void mengembalikan DP ke
 * pesanan, back-office tandai siap & batal (DP dikembalikan/hangus), izin & isolasi tenant.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Tenant kasir + produk kue Rp 38.500 berstok 10 + pelanggan.
 *
 * @return array<string, mixed>
 */
function SiapkanPreOrder(TestCase $tes, string $namaUsaha = 'Bakery Senja Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Kue Ulang Tahun Cokelat Diameter 20 cm');
    $pelanggan = Pelanggan::query()->create(['Nama' => 'Ibu Ratna Kusuma', 'NoHp' => '6281355550077']);

    return $k + ['Produk' => $produk, 'Pelanggan' => $pelanggan];
}

/**
 * Item outbox `PesananPenjualan.Buat`: 2 kue (Rp 77.000), DP [pembayaran] (bawaan tunai Rp 50.000).
 *
 * @param  array<string, mixed>  $k
 * @param  list<array{0: MetodePembayaran, 1: string}>|null  $pembayaran
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemPreOrder(array $k, ?array $pembayaran = null, array $timpa = [], int $urutan = 1): array
{
    $waktu = CarbonImmutable::now()->subMinutes(5);

    return [
        'Jenis' => 'PesananPenjualan.Buat',
        'Uuid' => BantuanKasir::Uuid(),
        'Data' => array_replace([
            'UuidShift' => $k['UuidShift'],
            'UuidPengguna' => $k['Kasir']->Uuid,
            'UuidPelanggan' => $k['Pelanggan']->Uuid,
            'Nomor' => str_replace('INV/', 'SO/', BantuanPenjualan::Nomor($k, $urutan, $waktu)),
            'DipesanPada' => $waktu->utc()->toIso8601ZuluString(),
            'TanggalAmbil' => $waktu->setTimezone('Asia/Jakarta')->addDays(3)->toDateString(),
            'Catatan' => 'Tulisan: Selamat ulang tahun Dimas ke-7',
            'TotalPesanan' => '77000.00',
            'Baris' => [[
                'Uuid' => BantuanKasir::Uuid(),
                'UuidProduk' => $k['Produk']->Uuid,
                'UuidProdukSatuan' => null,
                'Jumlah' => '2',
                'HargaSatuan' => '38500.00',
                'HargaPilihan' => '0.00',
                'Pilihan' => [],
                'Catatan' => 'Krim vanila',
            ]],
            'Pembayaran' => array_map(fn (array $p): array => [
                'Uuid' => BantuanKasir::Uuid(),
                'UuidMetodePembayaran' => $p[0]->Uuid,
                'Jumlah' => $p[1],
                'Referensi' => null,
            ], $pembayaran ?? [[$k['Tunai'], '50000.00']]),
        ], $timpa),
    ];
}

/**
 * Penjualan pengambilan: 2 kue Rp 77.000, DP dipakai [dp] lewat metode Uang Muka, sisanya tunai.
 *
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemAmbil(array $k, PesananPenjualan $pesanan, string $dp = '50000.00'): array
{
    return BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']],
        'Pembayaran' => [
            ['Metode' => MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::UangMuka->value)->sole(), 'Jumlah' => $dp],
            ['Metode' => $k['Tunai'], 'Jumlah' => null],
        ],
    ], ['UuidPesananPenjualan' => $pesanan->Uuid, 'UuidPelanggan' => $k['Pelanggan']->Uuid]);
}

function SaldoStokKue(array $k): string
{
    return (string) SaldoStok::query()->where('IdProduk', $k['Produk']->Id)->where('IdGudang', $k['Gudang']->Id)->value('JumlahTersedia');
}

describe('F-12 bagian 2 pre-order dari POS', function (): void {
    it('DP diterima: Uang Muka Pelanggan (J-07.3) tanpa stok & pendapatan, ikut kas shift, idempoten', function (): void {
        $k = SiapkanPreOrder($this);
        $item = ItemPreOrder($k, [[$k['Tunai'], '30000.00'], [$k['Qris'], '20000.00']]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan = PesananPenjualan::query()->where('Uuid', $item['Uuid'])->sole();
        expect($pesanan->Status)->toBe(StatusPesananPenjualan::Dipesan)
            ->and((string) $pesanan->UangMuka)->toBe('50000.00')
            ->and($pesanan->AmbilSisaUangMuka()->KeString())->toBe('50000.00')
            ->and($pesanan->Detail()->sole()->UuidProduk)->toBe($k['Produk']->Uuid)
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::UangMukaPelanggan))->toBe('-50000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::Penjualan))->toBe('0.00')
            ->and(Penjualan::query()->count())->toBe(0)
            ->and(SaldoStokKue($k))->toBe('10.0000')
            ->and(MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::UangMuka->value)->count())->toBe(1)
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);

        $laporan = app(LaporanShift::class)->Hitung(Shift::query()->where('Uuid', $k['UuidShift'])->sole());
        expect($laporan->penjualan->jumlahUangMuka)->toBe(1)
            ->and($laporan->penjualan->nominalUangMuka->KeString())->toBe('50000.00')
            ->and($laporan->penjualan->tunaiMasukBersih->KeString())->toBe('30000.00')
            ->and($laporan->kasSeharusnya->KeString())->toBe('530000.00')
            ->and($laporan->penjualan->AmbilJumlahMetode($k['Qris']->Uuid)->KeString())->toBe('20000.00');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
    });

    it('menolak DP melebihi total, DP tempo, nomor salah, pelanggan tak dikenal, tanggal ambil sebelum tanggal pesan', function (): void {
        $k = SiapkanPreOrder($this);
        $kirim = fn (array $item) => BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);

        expect($kirim(ItemPreOrder($k, [[$k['Tunai'], '80000.00']])))->toBe([['Ditolak', 'UangMukaMelebihiTotal']])
            ->and($kirim(ItemPreOrder($k, [[$k['Tempo'], '50000.00']])))->toBe([['Ditolak', 'MetodeBayarBelumDidukung']])
            ->and($kirim(ItemPreOrder($k, timpa: ['Nomor' => 'SO/X/260101/Y-0001'])))->toBe([['Ditolak', 'NomorTidakValid']])
            ->and($kirim(ItemPreOrder($k, timpa: ['UuidPelanggan' => BantuanKasir::Uuid()])))->toBe([['Ditolak', 'PelangganTidakDikenal']])
            ->and($kirim(ItemPreOrder($k, timpa: ['TanggalAmbil' => '2020-01-01'])))->toBe([['Ditolak', 'TanggalAmbilTidakValid']]);
    });

    it('cari & ambil: DP dipakai lewat metode Uang Muka → pendapatan & stok saat diserahkan. Void mengembalikan DP', function (): void {
        $k = SiapkanPreOrder($this);
        $item = ItemPreOrder($k);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan = PesananPenjualan::query()->where('Uuid', $item['Uuid'])->sole();

        $cari = $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-penjualan?kata=ratna')->assertOk();
        expect($cari->json('Pesanan.0.Nomor'))->toBe($pesanan->Nomor)
            ->and($cari->json('Pesanan.0.SisaUangMuka'))->toBe('50000.00')
            ->and($cari->json('Pesanan.0.Pelanggan.Nama'))->toBe('Ibu Ratna Kusuma')
            ->and($cari->json('Pesanan.0.Baris.0.UuidProduk'))->toBe($k['Produk']->Uuid)
            ->and($cari->json('MetodeUangMuka.Nama'))->toBe('Uang muka (DP)');
        expect($this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-penjualan?kata='.urlencode(substr($pesanan->Nomor, -6)))->json('Pesanan'))->toHaveCount(1);

        $ambil = ItemAmbil($k, $pesanan);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$ambil]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jual = Penjualan::query()->where('Uuid', $ambil['Uuid'])->sole();
        $pesanan->refresh();
        expect($jual->PerluTinjauan)->toBeFalse()
            ->and($jual->IdPesananPenjualan)->toBe($pesanan->Id)
            ->and($pesanan->Status)->toBe(StatusPesananPenjualan::Diambil)
            ->and($pesanan->IdPenjualan)->toBe($jual->Id)
            ->and($pesanan->AmbilSisaUangMuka()->KeString())->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::UangMukaPelanggan))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::Penjualan))->toBe('-77000.00')
            ->and(SaldoStokKue($k))->toBe('8.0000')
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);
        expect($this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-penjualan?kata=ratna')->json('Pesanan'))->toBe([]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan->refresh();
        expect($pesanan->Status)->toBe(StatusPesananPenjualan::Siap)
            ->and($pesanan->IdPenjualan)->toBeNull()
            ->and($pesanan->AmbilSisaUangMuka()->KeString())->toBe('50000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::UangMukaPelanggan))->toBe('-50000.00')
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);
    });

    it('DP dipakai melebihi sisa atau pesanan sudah diambil: penjualan tetap diterima + tinjauan UangMukaBermasalah', function (): void {
        $k = SiapkanPreOrder($this);
        $item = ItemPreOrder($k);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pesanan = PesananPenjualan::query()->where('Uuid', $item['Uuid'])->sole();

        $lebih = ItemAmbil($k, $pesanan, '60000.00');
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lebih]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $lebih['Uuid'])->sole()->AlasanTinjauan)
            ->toContain('UangMukaBermasalah: uang muka dipakai Rp 60.000 melebihi sisa DP');

        $lagi = ItemAmbil($k, $pesanan, '10000.00');
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lagi]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $lagi['Uuid'])->sole()->AlasanTinjauan)->toContain('sudah Diambil');

        $tanpa = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '1', 'Harga' => '38500.00']],
            'Pembayaran' => [
                ['Metode' => MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::UangMuka->value)->sole(), 'Jumlah' => '10000.00'],
                ['Metode' => $k['Tunai'], 'Jumlah' => null],
            ],
        ]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tanpa]))->toBe([['Ditolak', 'UangMukaTanpaPesanan']]);
    });
});

describe('F-12 bagian 2 pre-order back-office', function (): void {
    it('daftar & detail; tandai siap; batal dengan DP dikembalikan dari bank atau hangus; audit', function (): void {
        $k = SiapkanPreOrder($this);
        $a = ItemPreOrder($k, urutan: 1);
        $b = ItemPreOrder($k, urutan: 2);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$a, $b]);
        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pa = PesananPenjualan::query()->where('Uuid', $a['Uuid'])->sole();
        $pb = PesananPenjualan::query()->where('Uuid', $b['Uuid'])->sole();

        $this->get('/kelola/pre-order')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/PreOrder/Daftar'));
        $json = $this->getJson('/kelola/pre-order?cari=ratna&saring[Status]=Dipesan')->assertOk();
        expect($json->json('Meta.Total'))->toBe(2)
            ->and($json->json('Data.0.Pelanggan'))->toBe('Ibu Ratna Kusuma');

        $this->post("/kelola/pre-order/{$pa->Uuid}/siap")->assertSessionHasNoErrors();
        expect($pa->refresh()->Status)->toBe(StatusPesananPenjualan::Siap);
        $this->post("/kelola/pre-order/{$pa->Uuid}/siap")->assertSessionHasErrors('Status');

        $this->get("/kelola/pre-order/{$pa->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/PreOrder/Detail')
            ->where('Pesanan.Status', 'Siap')
            ->where('Pesanan.Baris.0.Catatan', 'Krim vanila')
            ->where('Izin.Selesaikan', true));

        $bank = collect(app(DaftarAkunPilihan::class)->AmbilKasBank())->firstWhere('Kode', '1-1200') ?? app(DaftarAkunPilihan::class)->AmbilKasBank()[0];
        $this->post("/kelola/pre-order/{$pa->Uuid}/selesaikan-uang-muka", ['Cara' => 'Dikembalikan', 'Alasan' => 'x'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pre-order/{$pa->Uuid}/selesaikan-uang-muka", ['Cara' => 'Dikembalikan', 'Alasan' => 'Pelanggan batal acara'])->assertSessionHasErrors('UuidAkun');
        $this->post("/kelola/pre-order/{$pa->Uuid}/selesaikan-uang-muka", ['Cara' => 'Dikembalikan', 'UuidAkun' => $bank['Uuid'], 'Alasan' => 'Pelanggan batal acara'])
            ->assertSessionHasNoErrors();
        $this->post("/kelola/pre-order/{$pb->Uuid}/selesaikan-uang-muka", ['Cara' => 'Hangus', 'Alasan' => 'Tidak diambil lewat 30 hari'])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pa->refresh();
        $pb->refresh();
        expect($pa->Status)->toBe(StatusPesananPenjualan::Dibatalkan)
            ->and((string) $pa->UangMukaDikembalikan)->toBe('50000.00')
            ->and($pb->Status)->toBe(StatusPesananPenjualan::Dibatalkan)
            ->and((string) $pb->UangMukaHangus)->toBe('50000.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::UangMukaPelanggan))->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PendapatanLain))->toBe('-50000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'pesanan-penjualan.batalkan')->count())->toBe(2)
            ->and(BantuanPembelian::PeriksaInvarian($k['Tenant']->Id))->toBe([]);
        $this->post("/kelola/pre-order/{$pa->Uuid}/selesaikan-uang-muka", ['Cara' => 'Hangus', 'Alasan' => 'Coba lagi dua kali'])->assertSessionHasErrors('Status');
    });

    it('kasir tanpa akuntansi.kelola tidak bisa membatalkan. Pre-order tenant lain 404', function (): void {
        $k = SiapkanPreOrder($this);
        $item = ItemPreOrder($k);
        BantuanKasir::KirimRingkas($this, $k['Token'], [$item]);
        $lain = SiapkanPreOrder($this, 'Bakery Lain Klaten');
        $itemLain = ItemPreOrder($lain);
        BantuanKasir::KirimRingkas($this, $lain['Token'], [$itemLain]);

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->get("/kelola/pre-order/{$itemLain['Uuid']}")->assertNotFound();

        BantuanOrganisasi::Masuk($this, $k['Kasir'], $k['Tenant']->Id);
        $this->post("/kelola/pre-order/{$item['Uuid']}/selesaikan-uang-muka", ['Cara' => 'Hangus', 'Alasan' => 'Pelanggan tidak datang'])->assertForbidden();
    });
});
