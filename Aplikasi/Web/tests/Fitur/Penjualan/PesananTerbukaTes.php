<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Model\Meja;
use App\Domain\Organisasi\Model\StasiunDapur;
use App\Domain\Pemenuhan\Enum\StatusBarisTiket;
use App\Domain\Pemenuhan\Enum\StatusTiketDapur;
use App\Domain\Pemenuhan\Model\TiketDapur;
use App\Domain\Pemenuhan\Model\TiketDapurDetail;
use App\Domain\Penjualan\Enum\StatusBarisPesanan;
use App\Domain\Penjualan\Enum\StatusPesananTerbuka;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PesananTerbuka;
use App\Domain\Penjualan\Model\PesananTerbukaDetail;
use App\Domain\Persediaan\Enum\AlasanBahanTerbuang;
use App\Domain\Persediaan\Model\BahanTerbuang;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-07 mode meja & F-10b fase 1 (PRD "Rincian F-07 mode meja & F-10b fase 1"): pesanan terbuka tersinkron lewat
 * outbox, tiket dapur per stasiun & ronde, pembayaran menutup pesanan (stok & jurnal hanya dari penjualan), void item
 * BR-07.5, last-writer-wins header, kunci bayar, bayar ganda offline, API KDS, idempotensi, dan isolasi.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array<string, mixed>
 */
function SiapkanRestoran(TestCase $tes): array
{
    return BantuanPesananTerbuka::SiapkanRestoran($tes);
}

/**
 * @param  array<string, mixed>  $k
 * @param  list<array{Jenis: string, Uuid: string, Data: array<string, mixed>}>  $item
 * @return list<array{0: string, 1: string|null}>
 */
function KirimPesanan(TestCase $tes, array $k, array $item, ?string $token = null): array
{
    return BantuanPesananTerbuka::Kirim($tes, $k, $item, $token);
}

describe('F-07 mode meja: pesanan terbuka', function (): void {
    it('buka di meja, kirim ronde 1 ke dapur per stasiun, tahan ronde 2 lalu kirim, tarik snapshot + ETag, bayar menutup pesanan', function (): void {
        $k = SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        $uuid = $buka['Uuid'];
        $ronde1 = BantuanPesananTerbuka::ItemTambah($k, $uuid, [[$k['Kopi'], '2', '25000.00', 'Es sedikit'], [$k['Nasi'], '1', '35000.00', 'Pedas level 3']]);
        expect(KirimPesanan($this, $k, [$buka, $ronde1]))->toBe([['Diterima', null], ['Diterima', null]]);

        $pesanan = PesananTerbuka::query()->where('Uuid', $uuid)->sole();
        expect($pesanan->Status)->toBe(StatusPesananTerbuka::Terbuka)->and($pesanan->IdMeja)->toBe($k['Meja']->Id)
            ->and(TiketDapur::query()->count())->toBe(2);
        $tiketBar = TiketDapur::query()->where('IdStasiunDapur', $k['Bar']->Id)->sole();
        expect($tiketBar->NamaMeja)->toBe('7')->and($tiketBar->Status)->toBe(StatusTiketDapur::Antre)
            ->and(TiketDapurDetail::query()->where('IdTiketDapur', $tiketBar->Id)->sole()->NamaProduk)->toBe('Es Kopi Susu Gula Aren Ukuran Besar');

        // Ronde 2 ditahan (tanpa kirim) lalu dikirim.
        $ronde2 = BantuanPesananTerbuka::ItemTambah($k, $uuid, [[$k['Kopi'], '1', '25000.00']], 2, false);
        $uuidBarisRonde2 = $ronde2['Data']['Baris'][0]['Uuid'];
        expect(KirimPesanan($this, $k, [$ronde2]))->toBe([['Diterima', null]])->and(TiketDapur::query()->count())->toBe(2);
        $kirim = BantuanPesananTerbuka::Item($k, 'KirimDapur', $uuid, ['Ronde' => 2, 'UuidBaris' => [$uuidBarisRonde2]], 'DikirimPada');
        expect(KirimPesanan($this, $k, [$kirim]))->toBe([['Diterima', null]])
            ->and(KirimPesanan($this, $k, [$kirim]))->toBe([['Duplikat', null]])
            ->and(TiketDapur::query()->where('Ronde', 2)->sole()->IdStasiunDapur)->toBe($k['Bar']->Id);

        // Snapshot untuk perangkat lain + ETag.
        $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()
            ->assertJsonPath('Pesanan.0.Uuid', $uuid)
            ->assertJsonPath('Pesanan.0.NamaMeja', '7')
            ->assertJsonCount(3, 'Pesanan.0.Baris')
            ->assertJsonPath('Pesanan.0.Baris.0.StatusDapur', 'Antre')
            ->assertJsonPath('Pesanan.0.Baris.0.Catatan', 'Es sedikit')
            ->assertJsonPath('Pesanan.0.Baris.0.UuidProduk', $k['Kopi']->Uuid)
            ->assertJsonPath('Ditutup', []);
        $etag = (string) $respons->headers->get('ETag');
        $this->withToken($k['Token'])->withHeader('If-None-Match', $etag)->getJson('/api/pos/v1/pesanan-terbuka')->assertStatus(304);

        // Stok tidak berubah oleh pesanan terbuka; baru berkurang saat dibayar.
        $stok = fn (): string => (string) DB::table('SaldoStok')->where('IdProduk', $k['Kopi']->Id)->where('IdGudang', $k['Gudang']->Id)->value('JumlahTersedia');
        $stokSebelum = $stok();
        expect(BigDecimal::of($stokSebelum)->isEqualTo('50'))->toBeTrue();

        // Bayar: satu penjualan lunas merujuk pesanan.
        $bayar = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '3', 'Harga' => '25000.00'], ['Produk' => $k['Nasi'], 'Jumlah' => '1', 'Harga' => '35000.00']]], ['UuidPesananTerbuka' => $uuid]);
        expect(KirimPesanan($this, $k, [$bayar]))->toBe([['Diterima', null]]);
        $penjualan = Penjualan::query()->where('Uuid', $bayar['Uuid'])->sole();
        expect($penjualan->IdPesananTerbuka)->toBe($pesanan->Id)->and($penjualan->PerluTinjauan)->toBeFalse()
            ->and($pesanan->refresh()->Status)->toBe(StatusPesananTerbuka::Dibayar)->and($pesanan->IdPenjualan)->toBe($penjualan->Id)
            ->and(BigDecimal::of($stok())->isEqualTo('47'))->toBeTrue();

        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()
            ->assertJsonPath('Pesanan', [])
            ->assertJsonPath('Ditutup.0', ['Uuid' => $uuid, 'Status' => 'Dibayar']);
        // Pesanan yang sudah dibayar tidak bisa ditambah.
        expect(KirimPesanan($this, $k, [BantuanPesananTerbuka::ItemTambah($k, $uuid, [[$k['Kopi'], '1', '25000.00']], 3)]))->toBe([['Ditolak', 'PesananSudahDitutup']]);
        expect(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('idempoten: buka & tambah dikirim ulang = Duplikat, tanpa tiket ganda. Meja arsip / produk tak dikenal ditolak', function (): void {
        $k = SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        $tambah = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00']]);
        expect(KirimPesanan($this, $k, [$buka, $tambah]))->toBe([['Diterima', null], ['Diterima', null]])
            ->and(KirimPesanan($this, $k, [$buka, $tambah]))->toBe([['Duplikat', null], ['Duplikat', null]])
            ->and(PesananTerbuka::query()->count())->toBe(1)->and(PesananTerbukaDetail::query()->count())->toBe(1)
            ->and(TiketDapur::query()->count())->toBe(1);

        $k['Meja9']->update(['Status' => 'Diarsipkan']);
        expect(KirimPesanan($this, $k, [BantuanPesananTerbuka::ItemBuka($k, $k['Meja9'])]))->toBe([['Ditolak', 'MejaTidakDitemukan']]);
        $asing = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00']]);
        $asing['Data']['Baris'][0]['UuidProduk'] = BantuanKasir::Uuid();
        expect(KirimPesanan($this, $k, [$asing]))->toBe([['Ditolak', 'ProdukTidakDikenal']])
            ->and(KirimPesanan($this, $k, [BantuanPesananTerbuka::ItemTambah($k, BantuanKasir::Uuid(), [[$k['Kopi'], '1', '25000.00']])]))->toBe([['Ditolak', 'PesananTidakDitemukan']]);
    });

    it('BR-07.5 void item: belum dikirim bebas. Sudah dikirim wajib alasan & penyetuju ber-izin void, tiketnya dibatalkan, diaudit', function (): void {
        $k = SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        $terkirim = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Nasi'], '1', '35000.00']]);
        $ditahan = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00']], 2, false);
        KirimPesanan($this, $k, [$buka, $terkirim, $ditahan]);
        $uuidTerkirim = $terkirim['Data']['Baris'][0]['Uuid'];
        $uuidDitahan = $ditahan['Data']['Baris'][0]['Uuid'];

        expect(KirimPesanan($this, $k, [BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$uuidDitahan]], 'DibatalkanPada')]))->toBe([['Diterima', null]]);
        expect(KirimPesanan($this, $k, [BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$uuidTerkirim]], 'DibatalkanPada')]))->toBe([['Ditolak', 'AlasanWajib']])
            ->and(KirimPesanan($this, $k, [BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$uuidTerkirim], 'Alasan' => 'Pelanggan batal'], 'DibatalkanPada')]))->toBe([['Ditolak', 'PenyetujuTidakBerwenang']]);

        $sah = BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$uuidTerkirim], 'Alasan' => 'Pelanggan batal', 'UuidPenyetuju' => $k['Supervisor']->Uuid], 'DibatalkanPada');
        expect(KirimPesanan($this, $k, [$sah]))->toBe([['Diterima', null]])
            ->and(KirimPesanan($this, $k, [$sah]))->toBe([['Duplikat', null]]);
        $baris = PesananTerbukaDetail::query()->where('Uuid', $uuidTerkirim)->sole();
        expect($baris->Status)->toBe(StatusBarisPesanan::Dibatalkan)->and($baris->IdPenyetujuBatal)->toBe($k['Supervisor']->Id)
            ->and(TiketDapurDetail::query()->where('UuidBaris', $uuidTerkirim)->sole()->Status)->toBe(StatusBarisTiket::Dibatalkan)
            ->and(LogAudit::query()->where('Peristiwa', 'pesanan-terbuka.void-item')->sole()->NilaiBaru)->toMatchArray(['Alasan' => 'Pelanggan batal']);
    });

    it('BR-07.5 opsi waste: void item yang sudah dibuat dicatat sebagai bahan terbuang (stok berkurang sekali, idempoten, diaudit), tanpa opsi stok tidak berubah', function (): void {
        $k = SiapkanRestoran($this);
        $stok = fn (): string => (string) DB::table('SaldoStok')->where('IdProduk', $k['Kopi']->Id)->where('IdGudang', $k['Gudang']->Id)->value('JumlahTersedia');
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        $dibuat = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '2', '25000.00']]);
        $tidakDibuat = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00']], 2);
        KirimPesanan($this, $k, [$buka, $dibuat, $tidakDibuat]);
        $uuidDibuat = $dibuat['Data']['Baris'][0]['Uuid'];
        $uuidTidak = $tidakDibuat['Data']['Baris'][0]['Uuid'];

        // Tanpa opsi: bahan tidak dikurangi.
        $tanpa = BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$uuidTidak], 'Alasan' => 'Tamu berubah pikiran', 'UuidPenyetuju' => $k['Supervisor']->Uuid], 'DibatalkanPada');
        expect(KirimPesanan($this, $k, [$tanpa]))->toBe([['Diterima', null]])
            ->and(BigDecimal::of($stok())->isEqualTo('50'))->toBeTrue()
            ->and(BahanTerbuang::query()->count())->toBe(0)
            ->and(PesananTerbukaDetail::query()->where('Uuid', $uuidTidak)->sole()->TerbuangDicatatPada)->toBeNull();

        // Dengan opsi: dua gelas yang sudah dibuat terbuang.
        $dengan = BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$uuidDibuat], 'Alasan' => 'Salah buat, tamu minta ganti', 'UuidPenyetuju' => $k['Supervisor']->Uuid, 'CatatTerbuang' => true], 'DibatalkanPada');
        expect(KirimPesanan($this, $k, [$dengan]))->toBe([['Diterima', null]])
            ->and(KirimPesanan($this, $k, [$dengan]))->toBe([['Duplikat', null]])
            ->and(BigDecimal::of($stok())->isEqualTo('48'))->toBeTrue();

        $terbuang = BahanTerbuang::query()->sole();
        expect($terbuang->Uuid)->toBe($uuidDibuat)
            ->and($terbuang->Alasan)->toBe(AlasanBahanTerbuang::VoidItem)
            ->and(BigDecimal::of($terbuang->Jumlah)->isEqualTo('2'))->toBeTrue()
            ->and($terbuang->IdProduk)->toBe($k['Kopi']->Id)
            ->and($terbuang->Catatan)->toBe('Salah buat, tamu minta ganti')
            ->and($terbuang->PerluTinjauan)->toBeFalse()
            ->and(PesananTerbukaDetail::query()->where('Uuid', $uuidDibuat)->sole()->TerbuangDicatatPada)->not->toBeNull();
        $audit = LogAudit::query()->where('Peristiwa', 'pesanan-terbuka.void-item')->orderByDesc('Id')->firstOrFail()->NilaiBaru;
        expect($audit)->toMatchArray(['CatatTerbuang' => true, 'TerbuangDicatat' => ['Es Kopi Susu Gula Aren Ukuran Besar'], 'TerbuangDilewati' => []])
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('BR-07.5 opsi waste: item jasa tanpa stok dilewati dengan keterangan tanpa menggagalkan void. Stok kurang dicatat dengan tinjauan, bukan ditolak', function (): void {
        $k = SiapkanRestoran($this);
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Biaya Layanan Meja Privat', 'Jenis' => JenisProduk::Jasa], '10000.00');
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        $tambah = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$jasa, '1', '10000.00'], [$k['Nasi'], '60', '35000.00']]);
        KirimPesanan($this, $k, [$buka, $tambah]);

        $void = BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => array_column($tambah['Data']['Baris'], 'Uuid'), 'Alasan' => 'Tamu pergi', 'UuidPenyetuju' => $k['Supervisor']->Uuid, 'CatatTerbuang' => true], 'DibatalkanPada');
        expect(KirimPesanan($this, $k, [$void]))->toBe([['Diterima', null]]);

        $audit = LogAudit::query()->where('Peristiwa', 'pesanan-terbuka.void-item')->firstOrFail()->NilaiBaru;
        expect($audit['TerbuangDilewati'])->toHaveCount(1)
            ->and($audit['TerbuangDilewati'][0]['Nama'])->toBe('Biaya Layanan Meja Privat')
            ->and($audit['TerbuangDicatat'])->toBe(['Nasi Goreng Kampung Spesial Telur Mata Sapi'])
            ->and(PesananTerbukaDetail::query()->where('NamaProduk', 'Biaya Layanan Meja Privat')->sole()->TerbuangDicatatPada)->toBeNull()
            ->and(BahanTerbuang::query()->sole()->PerluTinjauan)->toBeTrue();
    });

    it('BR-07.5 laporan void item: item yang dibatalkan setelah dikirim ke dapur tampil dengan alasan, pembatal, penyetuju, dan status terbuang. Item belum dikirim tidak', function (): void {
        $k = SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        $terkirim = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Nasi'], '1', '35000.00'], [$k['Kopi'], '2', '25000.00']]);
        $ditahan = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00']], 2, false);
        KirimPesanan($this, $k, [$buka, $terkirim, $ditahan]);
        $uuidNasi = $terkirim['Data']['Baris'][0]['Uuid'];
        $uuidKopi = $terkirim['Data']['Baris'][1]['Uuid'];
        KirimPesanan($this, $k, [
            BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$ditahan['Data']['Baris'][0]['Uuid']]], 'DibatalkanPada'),
            BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$uuidNasi], 'Alasan' => 'Pelanggan batal', 'UuidPenyetuju' => $k['Supervisor']->Uuid], 'DibatalkanPada'),
            BantuanPesananTerbuka::Item($k, 'BatalkanBaris', $buka['Uuid'], ['UuidBaris' => [$uuidKopi], 'Alasan' => 'Salah buat', 'UuidPenyetuju' => $k['Supervisor']->Uuid, 'CatatTerbuang' => true], 'DibatalkanPada'),
        ]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $hari = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $url = "/kelola/laporan/penjualan?tab=void-item&dari={$hari}&sampai={$hari}";
        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Saring.Tab', 'void-item')
            ->has('Isi', 2)
            ->where('Isi.0.NamaProduk', 'Es Kopi Susu Gula Aren Ukuran Besar')
            ->where('Isi.0.Jumlah', '2.0000')
            ->where('Isi.0.Nilai', '50000.00')
            ->where('Isi.0.Alasan', 'Salah buat')
            ->where('Isi.0.NamaPenyetuju', $k['Supervisor']->Nama)
            ->where('Isi.0.Terbuang', true)
            ->where('Isi.1.NamaProduk', 'Nasi Goreng Kampung Spesial Telur Mata Sapi')
            ->where('Isi.1.Nilai', '35000.00')
            ->where('Isi.1.Terbuang', false));

        $kemarin = CarbonImmutable::now('Asia/Jakarta')->subDays(3)->toDateString();
        $this->get("/kelola/laporan/penjualan?tab=void-item&dari={$kemarin}&sampai={$kemarin}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Isi', 0));

        $csv = $this->get("/kelola/laporan/penjualan/ekspor?tab=void-item&dari={$hari}&sampai={$hari}")->assertOk()->streamedContent();
        expect($csv)->toContain('Pelanggan batal')->and($csv)->toContain('Salah buat')->and($csv)->toContain('Bahan terbuang');
    });

    it('ubah header last-writer-wins (pindah meja diaudit). Batal pesanan dengan item terkirim butuh penyetuju. Bayar setelah batal = tinjauan', function (): void {
        $k = SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        KirimPesanan($this, $k, [$buka, BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00']])]);
        $uuid = $buka['Uuid'];

        $baru = BantuanPesananTerbuka::Item($k, 'Ubah', $uuid, ['UuidMeja' => $k['Meja9']->Uuid, 'JumlahTamu' => 2], 'DiubahPada', waktu: CarbonImmutable::now()->subMinutes(5));
        $lama = BantuanPesananTerbuka::Item($k, 'Ubah', $uuid, ['UuidMeja' => $k['Meja']->Uuid, 'Label' => 'Pak Budi'], 'DiubahPada', waktu: CarbonImmutable::now()->subMinutes(8));
        expect(KirimPesanan($this, $k, [$baru, $lama]))->toBe([['Diterima', null], ['Diterima', null]]);
        $pesanan = PesananTerbuka::query()->where('Uuid', $uuid)->sole();
        expect($pesanan->IdMeja)->toBe($k['Meja9']->Id)->and($pesanan->JumlahTamu)->toBe(2)->and($pesanan->Label)->toBeNull()
            ->and(LogAudit::query()->where('Peristiwa', 'pesanan-terbuka.pindah-meja')->sole()->NilaiBaru)->toBe(['Meja' => '9']);

        expect(KirimPesanan($this, $k, [BantuanPesananTerbuka::Item($k, 'Batal', $uuid, ['Alasan' => 'Tamu pergi'], 'DibatalkanPada')]))->toBe([['Ditolak', 'PenyetujuTidakBerwenang']]);
        $batal = BantuanPesananTerbuka::Item($k, 'Batal', $uuid, ['Alasan' => 'Tamu pergi', 'UuidPenyetuju' => $k['Supervisor']->Uuid], 'DibatalkanPada');
        expect(KirimPesanan($this, $k, [$batal]))->toBe([['Diterima', null]])
            ->and(KirimPesanan($this, $k, [$batal]))->toBe([['Duplikat', null]])
            ->and($pesanan->refresh()->Status)->toBe(StatusPesananTerbuka::Dibatalkan)
            ->and(TiketDapurDetail::query()->sole()->Status)->toBe(StatusBarisTiket::Dibatalkan);

        // Perangkat lain sempat menagih offline: uang tetap diterima, ditandai tinjauan.
        $bayar = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]], ['UuidPesananTerbuka' => $uuid]);
        expect(KirimPesanan($this, $k, [$bayar]))->toBe([['Diterima', null]]);
        $penjualan = Penjualan::query()->where('Uuid', $bayar['Uuid'])->sole();
        expect($penjualan->PerluTinjauan)->toBeTrue()->and((string) $penjualan->AlasanTinjauan)->toContain('PesananDibayarGanda')
            ->and($penjualan->IdPesananTerbuka)->toBe($pesanan->Id);

        // Pesanan belum dikenal server (perangkat pembuka belum sinkron): tetap diterima + tinjauan.
        $tanpa = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '1', 'Harga' => '25000.00']]], ['UuidPesananTerbuka' => BantuanKasir::Uuid()]);
        expect(KirimPesanan($this, $k, [$tanpa]))->toBe([['Diterima', null]])
            ->and((string) Penjualan::query()->where('Uuid', $tanpa['Uuid'])->value('AlasanTinjauan'))->toContain('PesananTidakDikenal');
    });

    it('kunci bayar online: perangkat lain 409 sampai dilepas. Mode cepat KirimDapur membuat tiket dari penjualan', function (): void {
        $k = SiapkanRestoran($this);
        ['Kode' => $kode] = BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $k['Outlet'], JenisPerangkat::Pelayan, 'Tablet Pelayan');
        $tokenPelayan = BantuanPerangkat::Aktifkan($this, $kode);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        KirimPesanan($this, $k, [$buka]);
        $alamat = "/api/pos/v1/pesanan-terbuka/{$buka['Uuid']}/kunci-bayar";

        $this->withToken($k['Token'])->postJson($alamat)->assertOk()->assertJsonStructure(['KunciBayarSampai']);
        $this->withToken($tokenPelayan)->postJson($alamat)->assertStatus(409)->assertJsonPath('Galat.Kode', 'PesananSedangDibayar');
        $this->withToken($k['Token'])->postJson($alamat)->assertOk();
        $this->withToken($tokenPelayan)->getJson('/api/pos/v1/pesanan-terbuka')->assertJsonPath('Pesanan.0.DikunciBayar', true);
        $this->withToken($tokenPelayan)->deleteJson($alamat)->assertNoContent();
        $this->withToken($tokenPelayan)->postJson($alamat)->assertStatus(409);
        $this->withToken($k['Token'])->deleteJson($alamat)->assertNoContent();
        $this->withToken($tokenPelayan)->postJson($alamat)->assertOk();

        $cepat = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Nasi'], 'Jumlah' => '2', 'Harga' => '35000.00', 'Catatan' => 'Tanpa kecap']]], ['KirimDapur' => true]);
        expect(KirimPesanan($this, $k, [$cepat]))->toBe([['Diterima', null]]);
        $tiket = TiketDapur::query()->where('IdPenjualan', Penjualan::query()->where('Uuid', $cepat['Uuid'])->value('Id'))->sole();
        expect($tiket->IdStasiunDapur)->toBe($k['Dapur']->Id)->and(TiketDapurDetail::query()->where('IdTiketDapur', $tiket->Id)->sole()->Catatan)->toBe('Tanpa kecap');
    });

    it('isolasi: pesanan outlet/tenant lain tidak bisa diubah, dikunci, atau ditarik', function (): void {
        $a = SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($a, $a['Meja']);
        KirimPesanan($this, $a, [$buka]);

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        $produkB = BantuanPenjualan::BuatProdukBerstok($b['Gudang'], $b['Pemilik']->Id, 'Bakso Urat Jumbo');
        expect(KirimPesanan($this, $b, [BantuanPesananTerbuka::ItemTambah($b, $buka['Uuid'], [[$produkB, '1', '20000.00']])]))->toBe([['Ditolak', 'PesananTidakDitemukan']])
            ->and(KirimPesanan($this, $b, [BantuanPesananTerbuka::ItemBuka($b, null, $buka['Uuid'])]))->toBe([['Ditolak', 'UuidSudahDipakai']]);
        $this->withToken($b['Token'])->postJson("/api/pos/v1/pesanan-terbuka/{$buka['Uuid']}/kunci-bayar")->assertNotFound();
        $this->withToken($b['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()->assertJsonPath('Pesanan', []);
        $this->withToken($b['Token'])->getJson('/api/pos/v1/meja')->assertOk()->assertJsonPath('Meja', [])->assertJsonPath('StasiunDapur', []);
    });
});

describe('API POS batas laju', function (): void {
    it('batas per rute per perangkat: perangkat lain di IP yang sama & rute lain tidak ikut terkena 429', function (): void {
        $k = SiapkanRestoran($this);
        ['Kode' => $kode] = BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $k['Outlet'], JenisPerangkat::Pelayan, 'Tablet Pelayan');
        $tokenPelayan = BantuanPerangkat::Aktifkan($this, $kode);

        foreach (range(1, 30) as $i) {
            $this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->assertOk();
        }

        $this->withToken($k['Token'])->getJson('/api/pos/v1/meja')->assertStatus(429)->assertJsonPath('Galat.Kode', 'TerlaluBanyakPermintaan');
        $this->withToken($tokenPelayan)->getJson('/api/pos/v1/meja')->assertOk();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk();
    });
});

describe('F-10b KDS', function (): void {
    it('tiket per stasiun, ubah status satu langkah (maju/mundur), waktu dicatat, lompat ditolak, tiket outlet lain 404. Data meja', function (): void {
        $k = SiapkanRestoran($this);
        ['Kode' => $kode] = BantuanPerangkat::BuatPerangkat($k['Tenant']->Id, $k['Outlet'], JenisPerangkat::Kds, 'Layar Dapur');
        $tokenKds = BantuanPerangkat::Aktifkan($this, $kode);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        KirimPesanan($this, $k, [$buka, BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '2', '25000.00'], [$k['Nasi'], '1', '35000.00']])]);

        $this->withToken($tokenKds)->getJson('/api/pos/v1/meja')->assertOk()
            ->assertJsonPath('ModeMejaAktif', true)
            ->assertJsonPath('Meja.0.Nama', '7')
            ->assertJsonPath('StasiunDapur.0.Nama', 'Bar')
            ->assertJsonPath('UuidStasiunBawaan', $k['Bar']->Uuid);
        // Cetak struk bagian 4c: peta kategori → stasiun aktif untuk tiket dapur tercetak offline; stasiun diarsipkan
        // tidak ikut (kategorinya dirutekan ke stasiun bawaan oleh perangkat).
        $peta = collect($this->withToken($tokenKds)->getJson('/api/pos/v1/meja')->json('KategoriStasiun'))->pluck('UuidStasiun', 'UuidKategori')->all();
        expect($peta)->toEqual([$k['Minuman']->Uuid => $k['Bar']->Uuid, $k['Makanan']->Uuid => $k['Dapur']->Uuid]);
        StasiunDapur::query()->whereKey($k['Dapur']->Id)->update(['Status' => 'Diarsipkan']);
        expect($this->withToken($tokenKds)->getJson('/api/pos/v1/meja')->json('KategoriStasiun'))
            ->toBe([['UuidKategori' => $k['Minuman']->Uuid, 'UuidStasiun' => $k['Bar']->Uuid]]);
        StasiunDapur::query()->whereKey($k['Dapur']->Id)->update(['Status' => 'Aktif']);
        $this->withToken($tokenKds)->getJson('/api/pos/v1/dapur/tiket')->assertOk()->assertJsonCount(2, 'Tiket');
        $respons = $this->withToken($tokenKds)->getJson('/api/pos/v1/dapur/tiket?stasiun[]='.$k['Dapur']->Uuid)->assertOk()
            ->assertJsonCount(1, 'Tiket')
            ->assertJsonPath('Tiket.0.UuidStasiun', $k['Dapur']->Uuid)
            ->assertJsonPath('Tiket.0.NamaMeja', '7')
            ->assertJsonPath('Tiket.0.Baris.0.NamaProduk', 'Nasi Goreng Kampung Spesial Telur Mata Sapi');
        $uuidTiket = (string) $respons->json('Tiket.0.Uuid');
        $alamat = "/api/pos/v1/dapur/tiket/{$uuidTiket}/status";

        $this->withToken($tokenKds)->postJson($alamat, ['Status' => 'Siap'])->assertStatus(422)->assertJsonPath('Galat.Kode', 'StatusTiketTidakValid');
        $this->withToken($tokenKds)->postJson($alamat, ['Status' => 'Dimasak'])->assertOk()->assertJsonPath('Status', 'Dimasak');
        $this->withToken($tokenKds)->postJson($alamat, ['Status' => 'Siap'])->assertOk();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tiket = TiketDapur::query()->where('Uuid', $uuidTiket)->sole();
        expect($tiket->MulaiPada)->not->toBeNull()->and($tiket->SiapPada)->not->toBeNull();
        $this->withToken($tokenKds)->postJson($alamat, ['Status' => 'Dimasak'])->assertOk();
        expect($tiket->refresh()->SiapPada)->toBeNull()->and($tiket->MulaiPada)->not->toBeNull();

        // Status dapur tampil di pesanan kasir.
        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertJsonPath('Pesanan.0.Baris.1.StatusDapur', 'Dimasak');

        $lain = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        $this->withToken($lain['Token'])->postJson($alamat, ['Status' => 'Siap'])->assertNotFound();
    });
});
