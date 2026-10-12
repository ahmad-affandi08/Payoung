<?php

declare(strict_types=1);

namespace Tests\Pendukung\Tenant;

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Pengelola\Referensi\Aksi\SiapkanPajakBawaan;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Aksi\UnggahBuktiTransfer;
use App\Domain\Tenant\Data\DataBuktiTransfer;
use App\Domain\Tenant\Layanan\PengaturanPenagihanPlatform;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\PengaturanPlatform;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Perantara\IdentifikasiTenantSesi;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prasyarat P-08 untuk test: paket & harga terbit (P-04), tarif PPN terbit (P-02), rekening tujuan platform.
 * Data master diterbitkan langsung lewat query builder (alur four-eyes-nya diuji di test P-02/P-04).
 */
final class BantuanTagihan
{
    public const ID_KLIEN_DOKU = 'BRN-0201-1700000000000';

    public const KUNCI_RAHASIA_DOKU = 'SK-kunci-billing-doku-uji';

    public const ALAMAT_DOKU = 'https://api-sandbox.doku.com';

    public static function SiapkanPrasyarat(bool $denganPpn = true): void
    {
        BantuanPendaftaran::SiapkanPrasyarat();
        HargaPaket::query()->toBase()->update(['Status' => StatusDataMaster::Terbit->value, 'BerlakuMulai' => '2026-01-01']);

        if ($denganPpn) {
            app(SiapkanPajakBawaan::class)->Jalankan();
            DB::table('TarifPajak')->update(['Status' => StatusDataMaster::Terbit->value, 'BerlakuMulai' => '2025-01-01']);
        }

        config()->set('integrasi.GerbangBilling', [
            'Penyedia' => 'DokuBilling',
            'Pengaturan' => ['Mode' => 'Sandbox', 'IdKlien' => self::ID_KLIEN_DOKU],
            'Kredensial' => ['KunciRahasia' => self::KUNCI_RAHASIA_DOKU],
        ]);
    }

    public const URL_BAYAR_DOKU = 'https://sandbox.doku.com/checkout/link/uji-billing';

    /**
     * Jawaban API status DOKU yang dipakai `FakeDoku()`; null = koneksi gagal.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $jawabanStatusDoku = null;

    private static int $kodeStatusDoku = 200;

    private static int $statusDokuDipanggil = 0;

    /**
     * Jawaban pembuatan transaksi Checkout yang dipaksakan (kode HTTP, badan); null = berhasil seperti biasa.
     *
     * @var array{int, array<string, mixed>}|null
     */
    private static ?array $jawabanCheckoutDoku = null;

    /**
     * Menyiapkan DOKU palsu: pembuatan transaksi Checkout berhasil memberi `URL_BAYAR_DOKU`, dan API status menjawab
     * sesuai `AturJawabanStatusDoku()` (bawaan: koneksi gagal).
     */
    public static function FakeDoku(): void
    {
        self::AturJawabanStatusDoku(null);
        self::$jawabanCheckoutDoku = null;
        Http::fake(function (Request $permintaan) {
            $url = $permintaan->url();

            if ($url === self::ALAMAT_DOKU.'/checkout/v1/payment') {
                if (self::$jawabanCheckoutDoku !== null) {
                    return Http::response(self::$jawabanCheckoutDoku[1], self::$jawabanCheckoutDoku[0]);
                }

                return Http::response([
                    'message' => ['SUCCESS'],
                    'response' => [
                        'order' => ['invoice_number' => $permintaan['order']['invoice_number'], 'amount' => $permintaan['order']['amount']],
                        'payment' => ['url' => self::URL_BAYAR_DOKU, 'token_id' => 'tok-doku-uji', 'expired_date' => '20260927040000'],
                    ],
                ]);
            }

            if (str_starts_with($url, self::ALAMAT_DOKU.'/orders/v1/status/')) {
                self::$statusDokuDipanggil++;

                if (self::$jawabanStatusDoku === null) {
                    throw new ConnectionException('DOKU tidak terjangkau.');
                }

                return Http::response(self::$jawabanStatusDoku, self::$kodeStatusDoku);
            }

            return Http::response([], 404);
        });
    }

    /** @param  array<string, mixed>  $badan */
    public static function PaksaJawabanCheckoutDoku(int $kodeHttp, array $badan): void
    {
        self::$jawabanCheckoutDoku = [$kodeHttp, $badan];
    }

    /** @param  array<string, mixed>|null  $jawaban */
    public static function AturJawabanStatusDoku(?array $jawaban, int $kodeHttp = 200): void
    {
        self::$jawabanStatusDoku = $jawaban;
        self::$kodeStatusDoku = $kodeHttp;
        self::$statusDokuDipanggil = 0;
    }

    public static function JumlahPanggilanStatusDoku(): int
    {
        return self::$statusDokuDipanggil;
    }

    /** @return array<string, mixed> Bentuk jawaban `GET /orders/v1/status/{invoice}` DOKU. */
    public static function JawabanStatusDoku(string $nomorPesanan, string $status, string $jumlah): array
    {
        return [
            'order' => ['invoice_number' => $nomorPesanan, 'amount' => (int) $jumlah],
            'transaction' => ['status' => $status, 'date' => '2026-09-27T03:10:00Z', 'original_request_id' => 'req-doku-uji-1'],
        ];
    }

    /**
     * @return array{Tenant: Tenant, Pengguna: Pengguna}
     */
    public static function DaftarTenant(string $email = 'rina@kopinusantara.id', string $noHp = '081234567890', string $namaUsaha = 'Kopi Nusantara'): array
    {
        return app(DaftarkanTenant::class)->Jalankan(BantuanPendaftaran::Data($email, $noHp, 'PRO', $namaUsaha));
    }

    /** URL absolut back-office tenant: setelah request ke subdomain pengelola, URL relatif ikut host terakhir. */
    public static function Url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * Masuk back-office sebagai pengguna dengan tenant aktif di sesi. Sesi dikosongkan dulu, seperti login sungguhan:
     * `AuthenticateSession` menolak sesi yang masih membawa jejak pengguna sebelumnya.
     */
    public static function Masuk(TestCase $tes, Pengguna $pengguna, Tenant $tenant): TestCase
    {
        $tes->flushSession();

        return $tes->actingAs($pengguna, 'web')->withSession([IdentifikasiTenantSesi::KUNCI_SESI => $tenant->Id]);
    }

    /** Menjalankan kode domain tenant di luar request (aksi tenant membaca KonteksTenant). */
    public static function AturTenant(Tenant $tenant): void
    {
        app(KonteksTenant::class)->Atur($tenant->Id);
    }

    /** Rekening tujuan transfer platform disimpan di pengaturan konsol (D-114), bukan di `.env`. */
    public static function AturRekeningTujuan(): void
    {
        PengaturanPlatform::query()->updateOrCreate(
            ['Kunci' => PengaturanPenagihanPlatform::KUNCI_REKENING],
            ['Nilai' => json_encode([[
                'Kode' => 'UTAMA',
                'NamaBank' => 'Bank Central Asia',
                'NomorRekening' => '1234567890',
                'AtasNama' => 'PT Kasir Nusantara Digital',
            ]], JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * Pembayaran `Menunggu` berupa bukti transfer, lewat Aksi langsung. Halaman tagihan tenant tidak lagi menerima
     * unggahan bukti (tenant membayar lewat gerbang billing), tetapi Aksi & verifikasi platform atas bukti yang sudah
     * masuk tetap ada dan tetap diuji dari sini.
     */
    public static function UnggahBuktiLangsung(Tenant $tenant, Pengguna $pemilik, TagihanLangganan $tagihan, ?string $tanggalTransfer = null): PembayaranLangganan
    {
        self::AturRekeningTujuan();
        self::AturTenant($tenant);

        try {
            return app(UnggahBuktiTransfer::class)->Jalankan(
                $tagihan->Uuid,
                new DataBuktiTransfer(
                    UploadedFile::fake()->image('mutasi.png'),
                    $tagihan->Total,
                    CarbonImmutable::parse($tanggalTransfer ?? now('Asia/Jakarta')->toDateString()),
                    'Bank Mandiri',
                    'Rina Wulandari',
                    'UTAMA',
                ),
                $pemilik->Id,
                $pemilik->Nama,
                (string) $pemilik->Email,
            );
        } finally {
            app(KonteksTenant::class)->Kosongkan();
        }
    }
}
