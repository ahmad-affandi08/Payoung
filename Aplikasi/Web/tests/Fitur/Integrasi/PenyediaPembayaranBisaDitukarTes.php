<?php

declare(strict_types=1);

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Billing\AdaptorTagihanDoku;
use App\Domain\Integrasi\Billing\PembuatPenyediaTagihan;
use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Integrasi\GerbangPembayaran\Adaptor\AdaptorDoku;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\KemampuanGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PermintaanRefund;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Integrasi\Merchant\AdaptorPendaftarDoku;
use App\Domain\Integrasi\Merchant\PembuatPendaftarMerchant;
use App\Domain\Integrasi\Merchant\PendaftarMerchantPembayaran;
use App\Domain\Pengelola\Tagihan\Aksi\RekonsiliasiPembayaranGerbangLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Pendukung\Integrasi\PenyediaTagihanPalsu;
use Tests\Pendukung\Tenant\BantuanTagihan;

/*
 * D-179 | P-05, P-08 (BR-P08.11), F-08: mengganti penyedia pembayaran cukup menulis satu adaptor dan mendaftarkannya.
 * Tes ini membuktikan dua hal: (1) pemilihan adaptor lewat konfigurasi, dan (2) kode tagihan langganan sungguhan
 * (bayar, rekonsiliasi, webhook) berjalan di atas penyedia lain tanpa satu kelas DOKU pun ikut terlibat.
 */

/** @return array{Tagihan: TagihanLangganan, Pembayaran: PembayaranLangganan} */
function BayarLewatPenyediaPalsu(object $tes): array
{
    $data = BantuanTagihan::DaftarTenant();
    BantuanTagihan::Masuk($tes, $data['Pengguna'], $data['Tenant'])
        ->post(BantuanTagihan::Url('/kelola/langganan/tagihan'), ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
        ->assertSessionHasNoErrors();
    $tes->flushSession();
    $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $data['Tenant']->Id)->latest('Id')->firstOrFail();

    $respons = BantuanTagihan::Masuk($tes, $data['Pengguna'], $data['Tenant'])
        ->postJson(BantuanTagihan::Url("/kelola/langganan/tagihan/{$tagihan->Uuid}/bayar-online"))
        ->assertOk();
    $tes->flushSession();

    $pembayaran = PembayaranLangganan::query()->withoutGlobalScopes()->where('IdTagihanLangganan', $tagihan->Id)->latest('Id')->firstOrFail();
    expect($respons->json('UrlBayar'))->toBe("https://bayar.penyedia-palsu.test/{$pembayaran->RefGateway}")
        ->and(PenyediaTagihanPalsu::$dibuat)->toBe([(string) $pembayaran->RefGateway]);

    return ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran];
}

describe('D-179 pemilihan adaptor lewat konfigurasi', function (): void {
    it('tagihan langganan: bawaan DOKU, kode tak dikenal ditolak dengan pesan yang menyebut pilihan', function (): void {
        expect(app(PenyediaTagihanPlatform::class))->toBeInstanceOf(AdaptorTagihanDoku::class)
            ->and(app(PenyediaTagihanPlatform::class)->AmbilKode())->toBe('Doku');

        config()->set('integrasi.PenyediaTagihan', 'Antah');

        expect(fn () => app(PenyediaTagihanPlatform::class))->toThrow(InvalidArgumentException::class, 'Pilihan: Doku');
        expect(PembuatPenyediaTagihan::PENYEDIA)->toBe(['Doku']);
    });

    it('pendaftaran merchant: bawaan DOKU, kode tak dikenal ditolak', function (): void {
        expect(app(PendaftarMerchantPembayaran::class))->toBeInstanceOf(AdaptorPendaftarDoku::class)
            ->and(app(PendaftarMerchantPembayaran::class)->AmbilLabel())->toBe('DOKU');

        config()->set('integrasi.PenyediaPendaftaranMerchant', 'Antah');

        expect(fn () => app(PendaftarMerchantPembayaran::class))->toThrow(InvalidArgumentException::class, 'Pilihan: Doku');
        expect(PembuatPendaftarMerchant::PENYEDIA)->toBe(['Doku']);
    });

    it('kode penyedia kosong di konfigurasi jatuh ke DOKU', function (): void {
        config()->set('integrasi.PenyediaTagihan', '');
        config()->set('integrasi.PenyediaPendaftaranMerchant', null);

        expect(PembuatPenyediaTagihan::AmbilKodeAktif())->toBe('Doku')
            ->and(PembuatPendaftarMerchant::AmbilKodeAktif())->toBe('Doku');
    });
});

describe('D-179 kemampuan gerbang QRIS', function (): void {
    it('DOKU menyatakan QRIS dinamis + cek dari nomor pesanan, belum refund; Refund ditolak jelas tanpa menyentuh jaringan', function (): void {
        $doku = new AdaptorDoku(['IdKlien' => 'BRN-1', 'Mode' => 'Sandbox'], ['KunciRahasia' => 'rahasia']);

        expect($doku->AmbilKemampuan())->toBe([KemampuanGerbang::QrisDinamis, KemampuanGerbang::CekStatusDariNomorPesanan])
            ->and($doku->CekMendukung(KemampuanGerbang::Refund))->toBeFalse();

        $galat = null;

        try {
            $doku->Refund(new PermintaanRefund('ORD-1', 'REF-1', Uang::Dari('15000.00'), 'Salah ketuk'));
        } catch (GalatGerbang $e) {
            $galat = $e;
        }

        expect($galat)->toBeInstanceOf(GalatGerbang::class)
            ->and($galat?->tidakPasti)->toBeFalse()
            ->and($galat?->getMessage())->toContain('manual');
    });

    it('label & isian penyedia gerbang dibaca dari penyedianya, bukan angka mati di pemanggil', function (): void {
        expect(PenyediaGerbang::Doku->AmbilLabel())->toBe('DOKU')
            ->and(PenyediaGerbang::Doku->AmbilBidangKredensial())->toBe([['Kunci' => 'KunciRahasia', 'Label' => 'Secret key', 'Wajib' => true]])
            ->and(array_column(PenyediaGerbang::Doku->AmbilBidangPengaturan(), 'Kunci'))->toBe(['IdKlien']);
    });

    it('semua kemampuan punya label', function (): void {
        foreach (KemampuanGerbang::cases() as $kemampuan) {
            expect($kemampuan->AmbilLabel())->not->toBe('');
        }
    });
});

describe('D-179 tagihan langganan di atas penyedia lain', function (): void {
    beforeEach(function (): void {
        Carbon::setTestNow('2026-09-27 03:00:00');
        BantuanTagihan::SiapkanPrasyarat();
        Mail::fake();
        PenyediaTagihanPalsu::Setel(StatusPembayaranGerbang::Lunas);
        app()->bind(PenyediaTagihanPlatform::class, static fn (): PenyediaTagihanPlatform => new PenyediaTagihanPalsu);
    });

    afterEach(function (): void {
        Carbon::setTestNow();
    });

    it('bayar online memakai penyedia yang terpasang dan rekonsiliasi melunasi tanpa kelas DOKU', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = BayarLewatPenyediaPalsu($this);
        Carbon::setTestNow('2026-09-27 03:16:00');

        $hasil = app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan();

        expect($hasil)->toBe(['Diperiksa' => 1, 'Selesai' => 1, 'Menunggu' => 0, 'Gagal' => 0])
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas);
    });

    it('rekonsiliasi: penyedia melapor masih menunggu = dibiarkan', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = BayarLewatPenyediaPalsu($this);
        PenyediaTagihanPalsu::$status = StatusPembayaranGerbang::Menunggu;
        Carbon::setTestNow('2026-09-27 03:16:00');

        expect(app(RekonsiliasiPembayaranGerbangLangganan::class)->Jalankan()['Menunggu'])->toBe(1)
            ->and($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Terbit);
    });

    it('webhook: tanda tangan tidak sah 401, sah + terkonfirmasi melunasi', function (): void {
        ['Tagihan' => $tagihan, 'Pembayaran' => $pembayaran] = BayarLewatPenyediaPalsu($this);
        $badan = ['NomorPesanan' => (string) $pembayaran->RefGateway, 'Jumlah' => (string) $tagihan->Total];

        $this->postJson('/webhook/billing/doku', $badan)->assertStatus(401);
        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Menunggu);

        $this->withHeaders(['X-Tanda-Tangan-Palsu' => 'sah'])->postJson('/webhook/billing/doku', $badan)
            ->assertOk()
            ->assertJson(['Diterima' => true]);

        expect($pembayaran->refresh()->Status)->toBe(StatusPembayaranLangganan::Diterima)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::Lunas);
    });
});
