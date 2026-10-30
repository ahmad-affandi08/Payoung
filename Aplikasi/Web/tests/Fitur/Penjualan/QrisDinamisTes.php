<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Penjualan\Aksi\TutupTagihanQrisTanpaPenjualan;
use App\Domain\Penjualan\Enum\HasilPenutupanTagihanQris;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Layanan\PenyediaTindakanPenjualan;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanPembayaran;
use App\Domain\Penjualan\Model\TagihanQris;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanGerbangTenant;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-08 QRIS dinamis (BR-08.5, PRD v2.04 katalog gerbang P-05): tagihan dibuat POS lewat gerbang aktif (idempoten per
 * Uuid), lunas lewat webhook bertanda tangan atau cek status (dijatah 5 detik), batal, isolasi tenant, dan pemakaian di
 * `Penjualan.Buat` (tertaut + jurnal J-07.1 ke akun kliring; bermasalah = diterima + tinjauan `QrisDinamis*`).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * DOKU palsu: status order = isi `$status` saat dipanggil (`PENDING`, `SUCCESS`, `EXPIRED`); `tolak` = pembuatan
 * tagihan ditolak (401, pesan memuat secret key).
 */
function PalsukanDoku(string &$status): void
{
    Http::fake(function (PermintaanHttp $r) use (&$status) {
        if ($status === 'tolak') {
            return BantuanGerbangTenant::ResponsTolak();
        }

        if (BantuanGerbangTenant::CekPermintaanBuat($r)) {
            return BantuanGerbangTenant::ResponsBuat($r);
        }

        return BantuanGerbangTenant::ResponsStatus($status);
    });
}

function HitungPanggilanDoku(string $potongan): int
{
    return count(Http::recorded(fn (PermintaanHttp $r): bool => str_contains($r->url(), $potongan)));
}

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $ubah
 */
function BuatQrisPos(TestCase $tes, array $k, MetodePembayaran $metode, string $jumlah = '38500.00', ?string $uuid = null, array $ubah = []): TestResponse
{
    return $tes->withToken($k['Token'])->postJson('/api/pos/v1/qris', [
        'Uuid' => $uuid ?? BantuanKasir::Uuid(),
        'UuidMetode' => $metode->Uuid,
        'Jumlah' => $jumlah,
        'Keterangan' => 'Pembayaran meja 7',
        ...$ubah,
    ]);
}

/**
 * Notifikasi DOKU ke URL webhook tenant pemilik `$k` (v2.06 `/webhook/{penyedia}/{tokenWebhook}`), bertanda tangan
 * HMACSHA256 dengan secret key `$kunci`.
 *
 * @param  array<string, mixed>  $k
 */
function WebhookDoku(TestCase $tes, array $k, string $nomor, string $jumlah = '38500.00', string $status = 'SUCCESS', string $kunci = BantuanGerbangTenant::KUNCI_RAHASIA_UJI, string $penyedia = 'doku'): TestResponse
{
    return BantuanGerbangTenant::KirimWebhook($tes, $k['TokenWebhook'], $nomor, $jumlah, $status, $kunci, kodeUrl: $penyedia);
}

/**
 * @return array<string, mixed>
 */
function SiapkanQrisDinamis(TestCase $tes, string $namaUsaha = 'Toko Kelontong Berkah Solo'): array
{
    $k = BantuanPenjualan::Siapkan($tes, $namaUsaha);
    // v2.06: gerbang milik tenant (akun merchant tenant sendiri), sudah lolos uji & aktif.
    $gerbang = BantuanGerbangTenant::Aktifkan($k['Tenant']->Id);

    return $k + [
        'TokenWebhook' => $gerbang->TokenWebhook,
        'QrisDinamis' => BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis'),
        'Minyak' => BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id),
    ];
}

function SaldoPeranJurnalQris(int $idJurnal, PeranAkun $peran, int $idOutlet): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun($peran, $idOutlet);
    $saldo = Kuantitas::Nol();

    foreach (JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', $idAkun)->get() as $b) {
        $saldo = $saldo->Tambah(Kuantitas::Dari($b->Debit))->Kurangi(Kuantitas::Dari($b->Kredit));
    }

    return (string) $saldo->KeDesimal()->toScale(2);
}

describe('F-08 QRIS dinamis: buat tagihan dari POS', function (): void {
    it('201 berisi QR & nomor pesanan ber-tenant; Uuid sama = 200 isi sama tanpa memanggil gerbang lagi (idempoten)', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $uuid = BantuanKasir::Uuid();

        $pertama = BuatQrisPos($this, $k, $k['QrisDinamis'], '38500.00', $uuid)->assertCreated();
        $nomor = 'PY'.base_convert((string) $k['Tenant']->Id, 10, 36).'-'.$uuid;

        expect($pertama->json())->toBe([
            'Uuid' => $uuid,
            'NomorPesanan' => $nomor,
            'IsiQr' => "https://sandbox.doku.com/checkout/link/{$nomor}",
            'HalamanBayar' => true,
            'KedaluwarsaPada' => $pertama->json('KedaluwarsaPada'),
            'Status' => 'Menunggu',
            'Jumlah' => '38500.00',
        ])->and(now()->diffInMinutes($pertama->json('KedaluwarsaPada')))->toBeGreaterThan(14.9)->toBeLessThanOrEqual(15.0)
            ->and($pertama->json('KedaluwarsaPada'))->toEndWith('Z');

        BuatQrisPos($this, $k, $k['QrisDinamis'], '38500.00', $uuid)->assertOk()->assertExactJson($pertama->json());

        expect(HitungPanggilanDoku('/checkout/v1/payment'))->toBe(1);
        Http::assertSent(fn (PermintaanHttp $r) => BantuanGerbangTenant::CekPermintaanBuat($r)
            && $r['order'] === ['amount' => 38500, 'invoice_number' => $nomor, 'callback_url' => url('/webhook/doku/'.$k['TokenWebhook'])]
            && $r['payment']['payment_method_types'] === ['QRIS']
            && $r->hasHeader('Client-Id', BantuanGerbangTenant::ID_KLIEN_UJI));

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tagihan = TagihanQris::query()->sole();
        expect($tagihan->IdOutlet)->toBe($k['Outlet']->Id)
            ->and($tagihan->IdPerangkat)->toBe($k['Perangkat']->Id)
            ->and($tagihan->Penyedia)->toBe('Doku')
            ->and($tagihan->IdReferensi)->toBe('trx-'.$nomor)
            ->and($tagihan->Keterangan)->toBe('Pembayaran meja 7')
            ->and(LogAudit::query()->where('Peristiwa', 'tagihan-qris.buat')->count())->toBe(1);
    });

    it('masalah: 409 GerbangBelumAktif; 422 MetodeBukanQrisDinamis (QRIS statis, nonaktif, tidak dikenal); 422 JumlahTidakBulat/JumlahTidakValid, tanpa panggilan gerbang', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $nonaktif = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::QrisDinamis, 'QRIS Otomatis lama', false);
        $galat = fn (TestResponse $r): array => [$r->status(), $r->json('Galat.Kode')];

        expect($galat(BuatQrisPos($this, $k, $k['Qris'])))->toBe([422, 'MetodeBukanQrisDinamis'])
            ->and($galat(BuatQrisPos($this, $k, $nonaktif)))->toBe([422, 'MetodeBukanQrisDinamis'])
            ->and($galat(BuatQrisPos($this, $k, $k['QrisDinamis'], ubah: ['UuidMetode' => BantuanKasir::Uuid()])))->toBe([422, 'MetodeBukanQrisDinamis'])
            ->and($galat(BuatQrisPos($this, $k, $k['QrisDinamis'], '38500.50')))->toBe([422, 'JumlahTidakBulat'])
            ->and($galat(BuatQrisPos($this, $k, $k['QrisDinamis'], '0')))->toBe([422, 'JumlahTidakValid'])
            ->and($galat(BuatQrisPos($this, $k, $k['QrisDinamis'], '100000001')))->toBe([422, 'JumlahTidakValid'])
            ->and($galat(BuatQrisPos($this, $k, $k['QrisDinamis'], '-5000')))->toBe([422, 'JumlahTidakValid'])
            ->and($galat(BuatQrisPos($this, $k, $k['QrisDinamis'], '100000000.00')))->toBe([201, null]);

        BantuanGerbangTenant::Nonaktifkan($k['Tenant']->Id);
        expect($galat(BuatQrisPos($this, $k, $k['QrisDinamis'])))->toBe([409, 'GerbangBelumAktif'])
            ->and(HitungPanggilanDoku('/checkout/v1/payment'))->toBe(1);
    });

    it('gerbang menolak: 502 GerbangGagal tanpa membocorkan secret key. Tidak ada tagihan tersisa sehingga Uuid sama bisa dicoba lagi', function (): void {
        $status = 'tolak';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $uuid = BantuanKasir::Uuid();

        $respons = BuatQrisPos($this, $k, $k['QrisDinamis'], '38500', $uuid)->assertStatus(502);

        expect($respons->json('Galat.Kode'))->toBe('GerbangGagal')
            ->and($respons->getContent())->not->toContain(BantuanGerbangTenant::KUNCI_RAHASIA_UJI)
            ->and($respons->json('Galat.Pesan'))->toContain('••••');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TagihanQris::query()->count())->toBe(0);

        $status = 'PENDING';
        BuatQrisPos($this, $k, $k['QrisDinamis'], '38500', $uuid)->assertCreated();
    });
});

/**
 * DOKU palsu untuk hasil tidak pasti (audit P0 F-02): `$charge` = 'putus' (koneksi putus/waktu habis), '500', atau
 * 'rusak' (HTTP 200 tanpa halaman bayar); status order = isi `$status`, atau per Uuid tagihan (`[Uuid => status]`,
 * lainnya 'PENDING').
 *
 * @param  string|array<string, string>  $status
 */
function PalsukanDokuTidakPasti(string &$charge, string|array &$status): void
{
    Http::fake(function (PermintaanHttp $r) use (&$charge, &$status) {
        if (BantuanGerbangTenant::CekPermintaanBuat($r)) {
            return match ($charge) {
                'putus' => throw new ConnectionException('cURL error 28: Operation timed out'),
                '500' => Http::response(['message' => ['Internal error']], 500),
                'rusak' => Http::response(['response' => ['payment' => []]]),
                default => BantuanGerbangTenant::ResponsBuat($r),
            };
        }

        $hasil = is_string($status) ? $status : (collect($status)->first(fn (string $nilai, string $uuid): bool => str_contains($r->url(), $uuid)) ?? 'PENDING');

        return BantuanGerbangTenant::ResponsStatus($hasil);
    });
}

describe('audit P0 F-02 QRIS dinamis: hasil gerbang tidak pasti', function (): void {
    it('koneksi putus/5xx/respons rusak: tagihan tidak dihapus (TidakPasti + NomorPesanan), Uuid sama 409 TagihanTidakPasti, Uuid baru bisa', function (string $mode): void {
        $charge = $mode;
        $status = 'PENDING';
        PalsukanDokuTidakPasti($charge, $status);
        $k = SiapkanQrisDinamis($this);
        $uuid = BantuanKasir::Uuid();

        expect(BuatQrisPos($this, $k, $k['QrisDinamis'], '38500', $uuid)->assertStatus(502)->json('Galat.Kode'))->toBe('GerbangTidakPasti')
            ->and(BuatQrisPos($this, $k, $k['QrisDinamis'], '38500', $uuid)->assertStatus(409)->json('Galat.Kode'))->toBe('TagihanTidakPasti');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tagihan = TagihanQris::query()->sole();
        expect($tagihan->Uuid)->toBe(strtoupper($uuid))
            ->and($tagihan->Status)->toBe(StatusTagihanQris::TidakPasti)
            ->and($tagihan->IsiQr)->toBe('')
            ->and($tagihan->NomorPesanan)->toStartWith('PY')
            ->and($tagihan->PesanGalatGerbang)->not->toBeNull()
            ->and(LogAudit::query()->where('Peristiwa', 'tagihan-qris.tidak-pasti')->count())->toBe(1)
            // QR tagihan tidak pasti tidak pernah dikirim ke kasir.
            ->and($this->withToken($k['Token'])->getJson("/api/pos/v1/qris/{$uuid}")->assertNotFound()->json('Galat.Kode'))->toBe('TagihanTidakDitemukan');

        $charge = 'ok';
        BuatQrisPos($this, $k, $k['QrisDinamis'])->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TagihanQris::query()->count())->toBe(2);
    })->with(['putus', '500', 'rusak']);

    it('webhook lunas untuk tagihan tidak pasti = Lunas (uang nyata menang) + tinjauan di Kotak Tindakan', function (): void {
        $charge = 'putus';
        $status = 'PENDING';
        PalsukanDokuTidakPasti($charge, $status);
        $k = SiapkanQrisDinamis($this);
        BuatQrisPos($this, $k, $k['QrisDinamis'], '38500')->assertStatus(502);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $nomor = TagihanQris::query()->sole()->NomorPesanan;

        WebhookDoku($this, $k, $nomor)->assertOk()->assertExactJson(['Diterima' => true]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tagihan = TagihanQris::query()->sole();

        expect($tagihan->Status)->toBe(StatusTagihanQris::Lunas)
            ->and($tagihan->PerluTinjauan)->toBeTrue()
            ->and($tagihan->AlasanTinjauan)->toContain('TidakPasti');

        $konteks = new DataKonteksTindakan($k['Tenant']->Id, $k['Pemilik']->Id, true, [], null, CarbonImmutable::now('Asia/Jakarta')->startOfDay());
        $butir = collect(app(PenyediaTindakanPenjualan::class)->Kumpulkan($konteks))->firstWhere('kunci', 'tagihan-qris.tinjauan');
        expect($butir->jumlah)->toBe(1)
            ->and($butir->rincian[0]->judul)->toBe($nomor)
            ->and(app(PenyediaTindakanPenjualan::class)->SaringDokumen('TagihanQris', [$tagihan->Uuid]))->toBe([$tagihan->Uuid]);
    });

    it('rekonsiliasi terjadwal: gerbang ditanya dengan NomorPesanan. Lunas diterapkan, tanpa kabar lewat batas = Kedaluwarsa', function (): void {
        $charge = '500';
        $status = 'PENDING';
        PalsukanDokuTidakPasti($charge, $status);
        $k = SiapkanQrisDinamis($this);
        $satu = BantuanKasir::Uuid();
        $dua = BantuanKasir::Uuid();
        BuatQrisPos($this, $k, $k['QrisDinamis'], '38500', $satu)->assertStatus(502);
        BuatQrisPos($this, $k, $k['QrisDinamis'], '12000', $dua)->assertStatus(502);
        $ambil = function (string $uuid) use ($k): TagihanQris {
            BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

            return TagihanQris::query()->where('Uuid', strtoupper($uuid))->sole();
        };

        $this->artisan('penjualan:rekonsiliasi-qris')->assertSuccessful();
        expect($ambil($satu)->Status)->toBe(StatusTagihanQris::TidakPasti)
            ->and($ambil($satu)->PercobaanRekonsiliasi)->toBe(1)
            ->and(HitungPanggilanDoku('/orders/v1/status/'))->toBe(2);

        // Tagihan pertama ternyata dibayar; yang kedua tidak pernah ada kabar.
        $status = [strtoupper($satu) => 'SUCCESS'];
        $this->travel(18)->minutes();
        $this->artisan('penjualan:rekonsiliasi-qris')->assertSuccessful();

        expect($ambil($satu)->Status)->toBe(StatusTagihanQris::Lunas)
            ->and($ambil($satu)->PerluTinjauan)->toBeTrue()
            ->and($ambil($dua)->Status)->toBe(StatusTagihanQris::Kedaluwarsa)
            ->and($ambil($dua)->PerluTinjauan)->toBeFalse();
    });

    it('kedaluwarsa terjadwal: tagihan Menunggu yang tidak pernah dibaca lagi menjadi Kedaluwarsa setelah batas + tenggang, tidak sebelumnya', function (): void {
        $charge = 'ok';
        $status = 'PENDING';
        PalsukanDokuTidakPasti($charge, $status);
        $k = SiapkanQrisDinamis($this);
        $uuid = BantuanKasir::Uuid();
        BuatQrisPos($this, $k, $k['QrisDinamis'], '38500', $uuid)->assertCreated();
        $ambil = function () use ($k): TagihanQris {
            BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

            return TagihanQris::query()->sole();
        };

        $this->artisan('penjualan:rekonsiliasi-qris')->assertSuccessful();
        expect($ambil()->Status)->toBe(StatusTagihanQris::Menunggu);

        $this->travel(18)->minutes();
        $this->artisan('penjualan:rekonsiliasi-qris')->assertSuccessful();
        expect($ambil()->Status)->toBe(StatusTagihanQris::Kedaluwarsa);
    });

    it('proses terhenti setelah memanggil gerbang (cadangan basi tanpa QR) tidak dihapus lagi: menjadi TidakPasti', function (): void {
        $charge = 'ok';
        $status = 'PENDING';
        PalsukanDokuTidakPasti($charge, $status);
        $k = SiapkanQrisDinamis($this);
        $uuid = BantuanKasir::Uuid();
        BuatQrisPos($this, $k, $k['QrisDinamis'], '38500', $uuid)->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Tiru proses yang terhenti sebelum QR tersimpan.
        TagihanQris::query()->update(['IsiQr' => '', 'DibuatPada' => now()->subMinutes(2)]);

        expect(BuatQrisPos($this, $k, $k['QrisDinamis'], '38500', $uuid)->assertStatus(409)->json('Galat.Kode'))->toBe('TagihanTidakPasti');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TagihanQris::query()->sole()->Status)->toBe(StatusTagihanQris::TidakPasti);
    });
});

describe('F-08 QRIS dinamis: status, webhook, batal', function (): void {
    it('cek status: gerbang ditanya paling sering sekali per 5 detik per tagihan; SUCCESS = Lunas. Lewat batas + 2 menit tanpa lunas = Kedaluwarsa lokal', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $uuid = BuatQrisPos($this, $k, $k['QrisDinamis'])->assertCreated()->json('Uuid');
        $cek = fn () => $this->withToken($k['Token'])->getJson("/api/pos/v1/qris/{$uuid}")->assertOk();

        expect($cek()->json())->toBe(['Uuid' => $uuid, 'Status' => 'Menunggu', 'Jumlah' => '38500.00', 'LunasPada' => null, 'KedaluwarsaPada' => $cek()->json('KedaluwarsaPada')])
            ->and(HitungPanggilanDoku('/orders/v1/status/'))->toBe(1);

        $this->travel(6)->seconds();
        $cek();
        expect(HitungPanggilanDoku('/orders/v1/status/'))->toBe(2);

        $status = 'SUCCESS';
        $cek();
        expect(HitungPanggilanDoku('/orders/v1/status/'))->toBe(2);
        $this->travel(6)->seconds();
        $lunas = $cek()->json();
        expect($lunas['Status'])->toBe('Lunas')->and($lunas['LunasPada'])->toEndWith('Z')
            ->and(HitungPanggilanDoku('/orders/v1/status/'))->toBe(3);
        $this->travel(6)->seconds();
        $cek();
        expect(HitungPanggilanDoku('/orders/v1/status/'))->toBe(3);

        // Tagihan lain yang tidak pernah dibayar.
        $status = 'PENDING';
        $lain = BuatQrisPos($this, $k, $k['QrisDinamis'])->json('Uuid');
        $this->travel(16)->minutes();
        expect($this->withToken($k['Token'])->getJson("/api/pos/v1/qris/{$lain}")->json('Status'))->toBe('Menunggu');
        $this->travel(2)->minutes();
        expect($this->withToken($k['Token'])->getJson("/api/pos/v1/qris/{$lain}")->json('Status'))->toBe('Kedaluwarsa');
    });

    it('webhook DOKU bertanda tangan sah = Lunas (idempoten). Tanda tangan salah 401. Penyedia lain/yang sudah dihapus 404. Jumlah berbeda tidak melunasi (tercatat). Tagihan tak dikenal 200 Diterima false', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $buat = BuatQrisPos($this, $k, $k['QrisDinamis'])->json();

        WebhookDoku($this, $k, $buat['NomorPesanan'], kunci: 'kunci-palsu')->assertStatus(401);
        WebhookDoku($this, $k, $buat['NomorPesanan'], penyedia: 'xendit')->assertNotFound();
        // Penyedia lama yang sudah dihapus tidak punya jalur webhook sama sekali.
        WebhookDoku($this, $k, $buat['NomorPesanan'], penyedia: 'midtrans')->assertNotFound();
        WebhookDoku($this, $k, $buat['NomorPesanan'], '20000.00')->assertOk()->assertExactJson(['Diterima' => true]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tagihan = TagihanQris::query()->sole();
        expect($tagihan->Status)->toBe(StatusTagihanQris::Menunggu)
            ->and(LogAudit::query()->where('Peristiwa', 'tagihan-qris.jumlah-berbeda')->count())->toBe(1);

        WebhookDoku($this, $k, $buat['NomorPesanan'])->assertOk()->assertExactJson(['Diterima' => true]);
        WebhookDoku($this, $k, $buat['NomorPesanan'])->assertOk()->assertExactJson(['Diterima' => true]);
        WebhookDoku($this, $k, $buat['NomorPesanan'], status: 'EXPIRED')->assertOk();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tagihan->refresh();
        expect($tagihan->Status)->toBe(StatusTagihanQris::Lunas)
            ->and($tagihan->JumlahDiterima)->toBe('38500.00')
            ->and($tagihan->LunasPada)->not->toBeNull()
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'TagihanQris')->where('StatusKe', 'Lunas')->count())->toBe(1);

        WebhookDoku($this, $k, 'PY'.base_convert((string) $k['Tenant']->Id, 10, 36).'-'.BantuanKasir::Uuid())->assertOk()->assertExactJson(['Diterima' => false]);
        WebhookDoku($this, $k, 'ORDER-LAIN-1')->assertOk()->assertExactJson(['Diterima' => false]);

        BantuanGerbangTenant::Nonaktifkan($k['Tenant']->Id);
        WebhookDoku($this, $k, $buat['NomorPesanan'])->assertNotFound();
    });

    it('batal: Menunggu = Dibatalkan (idempoten); Lunas = 409 SudahLunas. Gerbang ditanya dulu sehingga pembayaran yang baru masuk tidak dibatalkan', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $batal = fn (string $uuid) => $this->withToken($k['Token'])->postJson("/api/pos/v1/qris/{$uuid}/batal");

        $satu = BuatQrisPos($this, $k, $k['QrisDinamis'])->json('Uuid');
        $batal($satu)->assertOk()->assertExactJson(['Uuid' => $satu, 'Status' => 'Dibatalkan']);
        $batal($satu)->assertOk()->assertExactJson(['Uuid' => $satu, 'Status' => 'Dibatalkan']);

        $dua = BuatQrisPos($this, $k, $k['QrisDinamis'])->json();
        WebhookDoku($this, $k, $dua['NomorPesanan'])->assertOk();
        expect($batal($dua['Uuid'])->assertStatus(409)->json('Galat.Kode'))->toBe('SudahLunas');

        $tiga = BuatQrisPos($this, $k, $k['QrisDinamis'])->json('Uuid');
        $status = 'SUCCESS';
        expect($batal($tiga)->assertStatus(409)->json('Galat.Kode'))->toBe('SudahLunas');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(TagihanQris::query()->where('Uuid', $tiga)->value('Status'))->toBe(StatusTagihanQris::Lunas);
    });

    it('isolasi tenant: perangkat tenant lain 404 (baca & batal). Webhook memulihkan tenant dari nomor pesanan tanpa menyentuh tenant lain', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $a = SiapkanQrisDinamis($this, 'Toko Kelontong Berkah Solo');
        $b = SiapkanQrisDinamis($this, 'Warung Makan Sederhana Klaten');
        $tagihanA = BuatQrisPos($this, $a, $a['QrisDinamis'], '38500')->assertCreated()->json();
        $tagihanB = BuatQrisPos($this, $b, $b['QrisDinamis'], '12000')->assertCreated()->json();

        expect($this->withToken($b['Token'])->getJson("/api/pos/v1/qris/{$tagihanA['Uuid']}")->assertNotFound()->json('Galat.Kode'))->toBe('TagihanTidakDitemukan');
        $this->withToken($b['Token'])->postJson("/api/pos/v1/qris/{$tagihanA['Uuid']}/batal")->assertNotFound();
        // Metode tenant A tidak dikenal perangkat tenant B.
        expect(BuatQrisPos($this, $b, $a['QrisDinamis'])->assertStatus(422)->json('Galat.Kode'))->toBe('MetodeBukanQrisDinamis');

        // v2.06: notifikasi sah lewat URL webhook tenant A tidak bisa melunasi tagihan tenant B.
        WebhookDoku($this, $a, $tagihanB['NomorPesanan'], '12000.00')->assertOk()->assertExactJson(['Diterima' => false]);
        WebhookDoku($this, $b, $tagihanB['NomorPesanan'], '12000.00')->assertOk()->assertExactJson(['Diterima' => true]);

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(TagihanQris::query()->sole()->Status)->toBe(StatusTagihanQris::Lunas);
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(TagihanQris::query()->sole()->Status)->toBe(StatusTagihanQris::Menunggu);
        expect($this->withToken($a['Token'])->getJson("/api/pos/v1/qris/{$tagihanA['Uuid']}")->assertOk()->json('Status'))->toBe('Menunggu');
    });
});

describe('F-08 QRIS dinamis: pemakaian di Penjualan.Buat', function (): void {
    it('tagihan Lunas: penjualan diterima tanpa tinjauan, tagihan tertaut, RefEksternal = nomor pesanan, jurnal ke akun kliring (Piutang Pencairan) dan seimbang', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $buat = BuatQrisPos($this, $k, $k['QrisDinamis'])->json();
        WebhookDoku($this, $k, $buat['NomorPesanan'])->assertOk();

        $item = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Minyak'], 'Jumlah' => '1', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['QrisDinamis'], 'Jumlah' => '38500.00', 'Referensi' => $buat['Uuid']]],
        ]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penjualan = Penjualan::query()->sole();
        $bayar = PenjualanPembayaran::query()->sole();

        expect($penjualan->PerluTinjauan)->toBeFalse()
            ->and(TagihanQris::query()->sole()->UuidPenjualan)->toBe($penjualan->Uuid)
            ->and($bayar->JenisMetode)->toBe(JenisMetodePembayaran::QrisDinamis)
            ->and($bayar->Referensi)->toBe($buat['Uuid'])
            ->and($bayar->RefEksternal)->toBe($buat['NomorPesanan'])
            ->and(SaldoPeranJurnalQris((int) $penjualan->IdJurnal, PeranAkun::PiutangPencairan, $k['Outlet']->Id))->toBe('38500.00')
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('tagihan dipakai ulang, belum lunas, tidak dikenal, atau jumlah berbeda: penjualan tetap diterima + PerluTinjauan dengan kode QrisDinamis*', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $lunas = BuatQrisPos($this, $k, $k['QrisDinamis'])->json();
        WebhookDoku($this, $k, $lunas['NomorPesanan'])->assertOk();
        $menunggu = BuatQrisPos($this, $k, $k['QrisDinamis'])->json();
        $kecil = BuatQrisPos($this, $k, $k['QrisDinamis'], '20000')->json();
        WebhookDoku($this, $k, $kecil['NomorPesanan'], '20000.00')->assertOk();
        $jual = fn (?string $referensi) => BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Minyak'], 'Jumlah' => '1', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['QrisDinamis'], 'Jumlah' => '38500.00', 'Referensi' => $referensi]],
        ]);
        $items = [$jual($lunas['Uuid']), $jual($lunas['Uuid']), $jual($menunggu['Uuid']), $jual(BantuanKasir::Uuid()), $jual(null), $jual($kecil['Uuid'])];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], $items))->toBe(array_fill(0, 6, ['Diterima', null]));

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $alasan = fn (array $item): ?string => Penjualan::query()->where('Uuid', $item['Uuid'])->value('AlasanTinjauan');

        expect($alasan($items[0]))->toBeNull()
            ->and($alasan($items[1]))->toStartWith('QrisDinamisDipakaiUlang: tagihan '.$lunas['NomorPesanan'])
            ->and($alasan($items[2]))->toStartWith('QrisDinamisBelumLunas: tagihan '.$menunggu['NomorPesanan'].' berstatus Menunggu')
            ->and($alasan($items[3]))->toStartWith('QrisDinamisTidakDikenal:')
            ->and($alasan($items[4]))->toStartWith('QrisDinamisTidakDikenal:')
            ->and($alasan($items[5]))->toStartWith('QrisDinamisJumlahBerbeda:')
            ->and(TagihanQris::query()->where('Uuid', $lunas['Uuid'])->value('UuidPenjualan'))->toBe($items[0]['Uuid'])
            ->and(TagihanQris::query()->where('Uuid', $menunggu['Uuid'])->value('UuidPenjualan'))->toBe($items[2]['Uuid'])
            ->and(PenjualanPembayaran::query()->whereNotNull('RefEksternal')->count())->toBe(3)
            ->and(Penjualan::query()->where('PerluTinjauan', true)->count())->toBe(5)
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });
});

describe('F-08 QRIS dinamis: tagihan yang sudah ditutup operator', function (): void {
    it('penjualan offline yang tersinkron sesudah penutupan tidak menautkan tagihan itu lagi: diterima sebagai tinjauan, uangnya tidak diakui dua kali', function (): void {
        $status = 'PENDING';
        PalsukanDoku($status);
        $k = SiapkanQrisDinamis($this);
        $buat = BuatQrisPos($this, $k, $k['QrisDinamis'])->json();
        WebhookDoku($this, $k, $buat['NomorPesanan'])->assertOk();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Uang masuk tanpa penjualan: inilah tagihan yang muncul di antrean "QRIS tanpa penjualan" untuk ditutup operator.
        TagihanQris::query()->where('Uuid', $buat['Uuid'])->update(['PerluTinjauan' => true]);
        app(TutupTagihanQrisTanpaPenjualan::class)->Jalankan($buat['Uuid'], HasilPenutupanTagihanQris::Dikembalikan, 'Pembeli batal, uang dikembalikan tunai', null, null, $k['Pemilik']->Id);

        $item = BantuanPenjualan::Item($k, [
            'Baris' => [['Produk' => $k['Minyak'], 'Jumlah' => '1', 'Harga' => '38500.00']],
            'Pembayaran' => [['Metode' => $k['QrisDinamis'], 'Jumlah' => '38500.00', 'Referensi' => $buat['Uuid']]],
        ]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penjualan = Penjualan::query()->sole();

        expect($penjualan->PerluTinjauan)->toBeTrue()
            ->and($penjualan->AlasanTinjauan)->toStartWith('QrisDinamisDipakaiUlang: tagihan '.$buat['NomorPesanan'].' sudah ditutup')
            ->and(TagihanQris::query()->where('Uuid', $buat['Uuid'])->value('UuidPenjualan'))->toBeNull()
            ->and(PenjualanPembayaran::query()->whereNotNull('RefEksternal')->count())->toBe(0)
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });
});

describe('F-08 QRIS dinamis: back-office & data awal POS', function (): void {
    it('pemilik menambah metode QRIS dinamis tanpa gambar/bank. Halaman memberi tahu gerbang aktif (tanpa kredensial). Data-awal POS memuatnya', function (): void {
        ['Tenant' => $tenant, 'Pemilik' => $pemilik] = BantuanPanduanAwal::BuatTenant();
        BantuanGerbangTenant::Aktifkan($tenant->Id);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/metode-pembayaran')
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->where('GerbangPembayaran.Aktif', true)
                ->where('GerbangPembayaran.Penyedia', 'DOKU')
                ->where('GerbangPembayaran.Tautan', '/kelola/panduan-awal/metode-pembayaran')
                ->where('GerbangPembayaran.NamaMenu', 'Metode pembayaran')
                ->where('JenisTersedia', fn ($jenis) => collect($jenis)->pluck('Nilai')->contains('QrisDinamis')));
        expect(BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/metode-pembayaran')->getContent())
            ->not->toContain(BantuanGerbangTenant::KUNCI_RAHASIA_UJI);

        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)
            ->post('/kelola/panduan-awal/metode-pembayaran', ['Jenis' => 'QrisDinamis', 'Nama' => 'QRIS Otomatis', 'PersenBiaya' => '0.7'])
            ->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($tenant->Id);
        $metode = MetodePembayaran::query()->where('Jenis', 'QrisDinamis')->sole();
        expect($metode->Nama)->toBe('QRIS Otomatis')->and($metode->PathGambarQris)->toBeNull()->and($metode->IdReferensiBank)->toBeNull();

        BantuanGerbangTenant::Nonaktifkan($tenant->Id);
        BantuanPanduanAwal::Masuk($this, $pemilik, $tenant)->get('/kelola/panduan-awal/metode-pembayaran')
            ->assertInertia(fn (AssertableInertia $h) => $h
                ->where('GerbangPembayaran.Aktif', false)
                ->where('GerbangPembayaran.Penyedia', null)
                ->where('GerbangPembayaran.Tautan', '/kelola/panduan-awal/metode-pembayaran'));
    });

    it('data-awal POS memuat metode QRIS dinamis aktif', function (): void {
        $k = SiapkanQrisDinamis($this);

        $metode = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->json('MetodePembayaran');

        expect(array_column($metode, 'Jenis'))->toContain('QrisDinamis')
            ->and(collect($metode)->firstWhere('Jenis', 'QrisDinamis'))->toMatchArray(['Uuid' => $k['QrisDinamis']->Uuid, 'Nama' => 'QRIS Otomatis', 'AdaGambarQris' => false]);
    });
});
