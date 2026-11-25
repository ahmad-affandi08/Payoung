<?php

declare(strict_types=1);

namespace Tests\Pendukung\Penjualan;

use App\Domain\Integrasi\Aksi\SimpanGerbangPembayaranTenant;
use App\Domain\Integrasi\Enum\LingkunganGerbang;
use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use App\Domain\Integrasi\Snap\PembuatKunciSnap;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\TestCase;

/**
 * Gerbang pembayaran QRIS dinamis milik tenant (v2.06) untuk test: langsung aktif & lolos uji (jalur simpan → uji →
 * aktifkan diuji terpisah di `GerbangPembayaranTenantTes`). Sejak D-314 tenant memakai DOKU SNAP, jadi helper ini
 * membuat gerbang DOKU berkunci RSA beserta DOKU palsu (`Http::fake`): pembuatan QR (`qr-mpm-generate`), pertanyaan status
 * (`qr-mpm-query`), dan token B2B dijawab satu bentuk jawaban gabungan (`ResponsStatus`).
 *
 * Notifikasi DOKU hanya pemicu: status yang "diumumkan" webhook palsu ditanyakan balik oleh aplikasi ke DOKU palsu, jadi
 * `KirimWebhook` mengumumkannya lewat `$diumumkan` selama permintaan berjalan, supaya jawaban `qr-mpm-query` ikut berubah
 * seperti DOKU sungguhan yang sudah menerima uangnya.
 */
final class BantuanGerbangTenant
{
    public const ID_KLIEN_UJI = 'BRN-UJI-0001';

    public const KUNCI_RAHASIA_UJI = 'SK-doku-uji-rahasia-9911';

    public const ID_PEDAGANG_UJI = '111642';

    /** @var array{Status: string, Jumlah: string}|null */
    private static ?array $diumumkan = null;

    /** Jumlah yang dilaporkan DOKU palsu pada jawaban status status SUCCESS di luar webhook (polling, rekonsiliasi). Null = tidak melaporkan. */
    private static ?string $jumlahStatus = null;

    public static function AturJumlahStatus(?string $jumlah): void
    {
        self::$jumlahStatus = $jumlah;
    }

    /** @var array{Pribadi: string, Publik: string}|null */
    private static ?array $pasanganKunci = null;

    /**
     * Pasangan kunci RSA uji (dibuat sekali per proses tes, 2048 bit lambat dibuat).
     *
     * @return array{Pribadi: string, Publik: string}
     */
    public static function AmbilPasanganKunci(): array
    {
        return self::$pasanganKunci ??= (new PembuatKunciSnap)->Buat();
    }

    /**
     * @param  array<string, string>|null  $kredensial
     * @param  array<string, string|int>|null  $pengaturan
     */
    public static function Aktifkan(
        int $idTenant,
        PenyediaGerbang $penyedia = PenyediaGerbang::Doku,
        ?array $kredensial = null,
        ?array $pengaturan = null,
    ): GerbangPembayaranTenant {
        $kunci = self::AmbilPasanganKunci();
        $kredensial ??= ['KunciRahasia' => self::KUNCI_RAHASIA_UJI, 'KunciPribadiSnap' => $kunci['Pribadi']];
        $pengaturan ??= [
            'IdKlien' => self::ID_KLIEN_UJI,
            'IdPedagangQris' => self::ID_PEDAGANG_UJI,
            'IdTerminalQris' => 'A01',
            'KodePosQris' => '57214',
            'KunciPublikSnap' => $kunci['Publik'],
        ];
        BantuanOrganisasi::AturKonteks($idTenant);

        return GerbangPembayaranTenant::query()->create([
            'Uuid' => (string) Str::ulid(),
            'Penyedia' => $penyedia,
            'Lingkungan' => LingkunganGerbang::Sandbox,
            'Pengaturan' => $pengaturan,
            'Kredensial' => $kredensial,
            'PetunjukKredensial' => array_map(SimpanGerbangPembayaranTenant::BuatPetunjuk(...), array_diff_key($kredensial, ['KunciPribadiSnap' => true])),
            'StatusUji' => StatusUjiGerbang::Berhasil,
            'Aktif' => true,
            'TokenWebhook' => SimpanGerbangPembayaranTenant::BuatToken($idTenant),
        ]);
    }

    public static function Nonaktifkan(int $idTenant): void
    {
        BantuanOrganisasi::AturKonteks($idTenant);
        GerbangPembayaranTenant::query()->update(['Aktif' => false]);
    }

    /** Permintaan membuat QRIS ke DOKU SNAP. */
    public static function CekPermintaanBuat(PermintaanHttp $permintaan): bool
    {
        return str_ends_with($permintaan->url(), '/qr/qr-mpm-generate');
    }

    /** Permintaan menanyakan status QRIS ke DOKU SNAP. */
    public static function CekPermintaanStatus(PermintaanHttp $permintaan): bool
    {
        return str_ends_with($permintaan->url(), '/qr/qr-mpm-query');
    }

    /** Jawaban DOKU SNAP yang sukses: isi QRIS (EMVCo) dan nomor referensi DOKU `{awalan}-{nomor pesanan}`. */
    public static function ResponsBuat(PermintaanHttp $permintaan, string $awalan = 'trx'): PromiseInterface
    {
        $nomor = (string) ($permintaan['partnerReferenceNo'] ?? '');

        return Http::response([
            'responseCode' => '2004700',
            'responseMessage' => 'Successful',
            'referenceNo' => "{$awalan}-{$nomor}",
            'partnerReferenceNo' => $nomor,
            'qrContent' => "00020101021226QRISUJI{$nomor}",
        ]);
    }

    /**
     * Jawaban status DOKU SNAP (`PENDING`, `SUCCESS`, `EXPIRED`, `FAILED`). Satu jawaban gabungan juga memuat
     * `accessToken`, jadi permintaan token B2B yang jatuh ke fake yang sama tetap sukses tanpa mengubah fake di tes.
     */
    public static function ResponsStatus(string $status): PromiseInterface
    {
        $jumlah = self::$jumlahStatus;

        if (self::$diumumkan !== null) {
            $status = self::$diumumkan['Status'];
            $jumlah = self::$diumumkan['Jumlah'];
        }

        $kode = match ($status) {
            'SUCCESS' => '00',
            'FAILED', 'EXPIRED' => '06',
            default => '03',
        };

        return Http::response([
            'responseCode' => '2004700',
            'responseMessage' => 'Successful',
            'accessToken' => 'token-b2b-uji',
            'tokenType' => 'Bearer',
            'expiresIn' => '900',
            'latestTransactionStatus' => $kode,
            ...($jumlah !== null && $kode === '00' ? ['amount' => ['value' => $jumlah, 'currency' => 'IDR']] : []),
        ]);
    }

    /** Jawaban penolakan DOKU (HTTP 401) yang pesannya memuat kunci rahasia, untuk menguji penyaringan. */
    public static function ResponsTolak(string $kunci = self::KUNCI_RAHASIA_UJI): PromiseInterface
    {
        return Http::response(['responseCode' => '4014700', 'responseMessage' => "Invalid signature for key {$kunci}"], 401);
    }

    /**
     * Notifikasi DOKU ke URL webhook tenant: `/webhook/{kode}/{tokenWebhook}`. Isinya hanya memuat nomor pesanan; status
     * dan jumlah (`$status`, `$jumlah`) diumumkan ke DOKU palsu selama permintaan berjalan, karena aplikasi menanyakannya
     * sendiri ke DOKU (D-314).
     *
     * @return TestResponse<Response>
     */
    public static function KirimWebhook(
        TestCase $tes,
        string $tokenWebhook,
        string $nomorPesanan,
        string $jumlah = '38500.00',
        string $status = 'SUCCESS',
        string $jalur = '',
        string $kodeUrl = 'doku',
    ): TestResponse {
        $isi = (string) json_encode([
            'order' => ['invoice_number' => $nomorPesanan, 'amount' => (int) explode('.', $jumlah)[0]],
            'transaction' => ['status' => $status],
        ]);
        $target = "/webhook/{$kodeUrl}/{$tokenWebhook}".($jalur === '' ? '' : "/{$jalur}");
        self::$diumumkan = ['Status' => $status, 'Jumlah' => $jumlah];

        try {
            return $tes->call('POST', $target, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], $isi);
        } finally {
            self::$diumumkan = null;
        }
    }
}
