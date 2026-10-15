<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\Billing\NomorPesananBilling;
use App\Domain\Integrasi\Billing\NotifikasiBilling;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Integrasi\Snap\Data\KanalVaSnap;
use App\Domain\Integrasi\Snap\Data\PembayaranSnapDibuat;
use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\Response;

/**
 * Pembayaran tagihan langganan Payoung lewat DOKU SNAP (D-159): QRIS dinamis, virtual account per bank, dan e-wallet
 * (DANA, ShopeePay), semuanya ditampilkan di halaman bayar Payoung sendiri. Akunnya akun induk Payoung
 * (`GerbangBilling`), jalur terpisah dari QRIS milik toko (D-19).
 *
 * Pelunasan **tidak pernah** dari peramban atau dari isi notifikasi: hanya dari jawaban API status yang kita minta
 * sendiri (bertanda tangan), diproses jalur yang sama dengan Checkout (`TerimaNotifikasiBillingLangganan`).
 *
 * Bentuk permintaan mengikuti dokumen DOKU SNAP; hal yang belum bisa dipastikan tanpa sandbox ditandai di
 * `Panduan/DokuSnap.md` (path ShopeePay, kolom penanda lunas VA, nilai `customerNo` DGPC).
 */
final class GerbangBillingSnap
{
    /** Batas bayar satu pembayaran; setelahnya tenant membuat pembayaran baru dari tagihan yang sama. */
    public const MENIT_KEDALUWARSA = 60;

    private const TARGET_QRIS_BUAT = '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate';

    private const TARGET_QRIS_STATUS = '/snap-adapter/b2b/v1.0/qr/qr-mpm-query';

    private const TARGET_VA_BUAT = '/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va';

    private const TARGET_VA_STATUS = '/orders/v1.0/transfer-va/status';

    private const TARGET_DOMPET_BUAT = '/direct-debit/core/v1/debit/payment-host-to-host';

    private const TARGET_DOMPET_STATUS = '/orders/v1.0/debit/status';

    public function __construct(private readonly KlienSnap $klien) {}

    public function CekAktif(): bool
    {
        return $this->klien->CekSiap() && $this->AmbilPilihan() !== [];
    }

    /**
     * Pilihan bayar yang sudah lengkap pengaturannya di konsol; yang belum lengkap tidak ditawarkan.
     *
     * @return list<array{Metode: string, Label: string, Kanal: string|null}>
     */
    public function AmbilPilihan(): array
    {
        if (! $this->klien->CekSiap()) {
            return [];
        }

        $pilihan = [];

        if ($this->klien->Pengaturan('IdPedagangQris') !== '' && $this->klien->Pengaturan('IdTerminalQris') !== '' && $this->klien->Pengaturan('KodePosQris') !== '') {
            $pilihan[] = ['Metode' => MetodeBayarSnap::Qris->value, 'Label' => 'QRIS', 'Kanal' => null];
        }

        foreach ($this->AmbilKanalVa() as $kanal) {
            $pilihan[] = ['Metode' => MetodeBayarSnap::VirtualAccount->value, 'Label' => 'Virtual account '.$kanal->label, 'Kanal' => $kanal->kode];
        }

        foreach ([MetodeBayarSnap::Dana, MetodeBayarSnap::ShopeePay] as $dompet) {
            if ($this->klien->Pengaturan('Dompet'.$dompet->value) === 'Aktif') {
                $pilihan[] = ['Metode' => $dompet->value, 'Label' => $dompet->AmbilLabel(), 'Kanal' => null];
            }
        }

        return $pilihan;
    }

    /**
     * @return list<KanalVaSnap>
     */
    public function AmbilKanalVa(): array
    {
        return KanalVaSnap::Urai($this->klien->Pengaturan('KanalVa'));
    }

    /**
     * @param  string|null  $ipPelanggan  alamat IPv4 peramban tenant (dibutuhkan e-wallet; diabaikan bila bukan IPv4)
     *
     * @throws GalatGerbang
     */
    public function Buat(
        MetodeBayarSnap $metode,
        ?string $kanal,
        string $nomorPesanan,
        Uang $total,
        string $namaPembayar,
        string $emailPembayar,
        string $urlKembali,
        ?string $ipPelanggan = null,
    ): PembayaranSnapDibuat {
        $tersedia = array_filter($this->AmbilPilihan(), static fn (array $baris): bool => $baris['Metode'] === $metode->value && $baris['Kanal'] === $kanal);

        if ($tersedia === []) {
            throw new GalatGerbang('Cara bayar ini belum tersedia. Pilih cara bayar lain.');
        }

        // Disimpan dalam UTC (zona basis data); format +07:00 hanya saat dikirim ke DOKU.
        $kedaluwarsa = CarbonImmutable::now()->addMinutes(self::MENIT_KEDALUWARSA);
        $nilai = self::FormatNominal($total->KeString());
        $jumlah = ['value' => $nilai, 'currency' => 'IDR'];
        $nama = self::BersihkanNama($namaPembayar);

        return match ($metode) {
            MetodeBayarSnap::Qris => $this->BuatQris($nomorPesanan, $jumlah, $kedaluwarsa),
            MetodeBayarSnap::VirtualAccount => $this->BuatVa($kanal ?? '', $nomorPesanan, $jumlah, $nama, $emailPembayar, $kedaluwarsa),
            MetodeBayarSnap::Dana, MetodeBayarSnap::ShopeePay => $this->BuatDompet($metode, $nomorPesanan, $jumlah, $urlKembali, $ipPelanggan, $kedaluwarsa),
        };
    }

    /**
     * Status satu pembayaran, ditanyakan ke DOKU. Hasilnya dibentuk sama dengan notifikasi webhook supaya diproses
     * jalur yang sama. null = DOKU tidak bisa dihubungi atau jawabannya tidak terbaca (dicoba lagi nanti).
     *
     * @param  array<string, string>  $rincian
     */
    public function CekStatus(string $nomorPesanan, MetodeBayarSnap $metode, array $rincian, DateTimeInterface $kedaluwarsaPada, string $jumlah): ?NotifikasiBilling
    {
        $bagian = NomorPesananBilling::Urai($nomorPesanan);

        if ($bagian === null || ! $this->klien->CekSiap()) {
            return null;
        }

        try {
            [$respons, $status] = match ($metode) {
                MetodeBayarSnap::Qris => $this->TanyaQris($nomorPesanan, $rincian, $jumlah),
                MetodeBayarSnap::VirtualAccount => $this->TanyaVa($rincian),
                MetodeBayarSnap::Dana, MetodeBayarSnap::ShopeePay => $this->TanyaDompet($nomorPesanan, $rincian, $jumlah),
            };
        } catch (GalatGerbang) {
            return null;
        }

        // 5xx / jawaban tak terbaca: tidak berarti apa-apa tentang pembayarannya.
        if ($respons->serverError() || ! is_array($respons->json())) {
            return null;
        }

        $data = $respons->json();
        $paid = $status === StatusPembayaranGerbang::Lunas;

        // Belum lunas dan lewat batas bayar (ditambah jeda notifikasi DOKU) = kedaluwarsa.
        if ($status === StatusPembayaranGerbang::Menunggu && $kedaluwarsaPada->getTimestamp() + 15 * 60 < CarbonImmutable::now()->getTimestamp()) {
            $status = StatusPembayaranGerbang::Kedaluwarsa;
        }

        return new NotifikasiBilling(
            nomorPesanan: $nomorPesanan,
            idTenant: $bagian['IdTenant'],
            uuidPembayaran: $bagian['Uuid'],
            status: $status,
            jumlah: $paid ? PenguraiStatusSnap::AmbilJumlah($data) : '',
            idTransaksi: (string) ($rincian['ReferensiGerbang'] ?? ''),
            statusAsli: (string) ($data['latestTransactionStatus'] ?? $data['virtualAccountData']['paymentFlagStatus'] ?? $data['responseCode'] ?? ''),
        );
    }

    /**
     * @param  array{value: string, currency: string}  $jumlah
     */
    private function BuatQris(string $nomorPesanan, array $jumlah, CarbonImmutable $kedaluwarsa): PembayaranSnapDibuat
    {
        $respons = $this->klien->Kirim(self::TARGET_QRIS_BUAT, [
            'partnerReferenceNo' => $nomorPesanan,
            'amount' => $jumlah,
            'merchantId' => $this->klien->Pengaturan('IdPedagangQris'),
            'terminalId' => $this->klien->Pengaturan('IdTerminalQris'),
            'validityPeriod' => self::FormatWaktuDoku($kedaluwarsa),
            'additionalInfo' => ['postalCode' => $this->klien->Pengaturan('KodePosQris'), 'feeType' => '1'],
        ]);
        $isiQr = $respons->json('qrContent');
        $this->PastikanBerhasil($respons, is_string($isiQr) && $isiQr !== '');

        return new PembayaranSnapDibuat(MetodeBayarSnap::Qris, null, $kedaluwarsa, [
            'IsiQr' => (string) $isiQr,
            'ReferensiGerbang' => self::Teks($respons->json('referenceNo')),
        ]);
    }

    /**
     * @param  array{value: string, currency: string}  $jumlah
     */
    private function BuatVa(string $kodeKanal, string $nomorPesanan, array $jumlah, string $nama, string $email, CarbonImmutable $kedaluwarsa): PembayaranSnapDibuat
    {
        $kanal = collect($this->AmbilKanalVa())->first(static fn (KanalVaSnap $baris): bool => $baris->kode === $kodeKanal)
            ?? throw new GalatGerbang('Bank virtual account ini belum tersedia. Pilih bank lain.');
        // Contoh DOKU (VA Mandiri, BIN 5 digit) menulis nomor VA = partnerServiceId berspasi 8 karakter + customerNo,
        // sama persis dengan awalan `partnerServiceId`; BIN 8 digit (BNI) tidak terpengaruh.
        $partner = $kanal->AmbilPartnerServiceIdBerisi();
        $badan = [
            'partnerServiceId' => $kanal->AmbilPartnerServiceIdBerisi(),
            'customerNo' => $kanal->prefiksPelanggan,
            'virtualAccountNo' => $partner.$kanal->prefiksPelanggan,
            'virtualAccountName' => $nama,
            'trxId' => $nomorPesanan,
            'virtualAccountTrxType' => 'C',
            'totalAmount' => $jumlah,
            'expiredDate' => self::FormatWaktuDoku($kedaluwarsa),
            'additionalInfo' => ['channel' => $kanal->kode, 'virtualAccountConfig' => ['reusableStatus' => false]],
        ];

        if ($email !== '') {
            $badan['virtualAccountEmail'] = mb_substr($email, 0, 255);
        }

        $respons = $this->klien->Kirim(self::TARGET_VA_BUAT, $badan);
        $nomorVa = self::Teks($respons->json('virtualAccountData.virtualAccountNo'));
        $this->PastikanBerhasil($respons, $nomorVa !== '');

        return new PembayaranSnapDibuat(MetodeBayarSnap::VirtualAccount, $kanal->kode, $kedaluwarsa, [
            'NomorVa' => $nomorVa,
            'NamaBank' => $kanal->label,
            'PartnerServiceId' => self::Teks($respons->json('virtualAccountData.partnerServiceId')) ?: $kanal->AmbilPartnerServiceIdBerisi(),
            'CustomerNo' => self::Teks($respons->json('virtualAccountData.customerNo')),
            'ReferensiGerbang' => $nomorPesanan,
        ]);
    }

    /**
     * @param  array{value: string, currency: string}  $jumlah
     */
    private function BuatDompet(MetodeBayarSnap $metode, string $nomorPesanan, array $jumlah, string $urlKembali, ?string $ip, CarbonImmutable $kedaluwarsa): PembayaranSnapDibuat
    {
        $url = ['url' => mb_substr($urlKembali, 0, 255), 'type' => 'PAY_RETURN', 'isDeepLink' => 'N'];
        $badan = [
            'partnerReferenceNo' => $nomorPesanan,
            'amount' => $jumlah,
            'validUpTo' => self::FormatWaktuDoku($kedaluwarsa),
            // DANA memakai satu objek `urlParam`, ShopeePay daftar `urlParams` (sesuai dokumen DOKU masing-masing).
            ...($metode === MetodeBayarSnap::Dana ? ['urlParam' => $url] : ['urlParams' => [$url]]),
            'additionalInfo' => ['channel' => $metode->AmbilKodeKanalDompet(), ...($metode === MetodeBayarSnap::Dana ? ['orderTitle' => 'Langganan Payoung'] : [])],
        ];
        $header = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? ['X-IP-ADDRESS' => $ip] : [];
        $respons = $this->klien->Kirim(self::TARGET_DOMPET_BUAT, $badan, $header);
        $alih = $respons->json('webRedirectUrl');
        $this->PastikanBerhasil($respons, is_string($alih) && str_starts_with($alih, 'https://'));

        return new PembayaranSnapDibuat($metode, null, $kedaluwarsa, [
            'UrlAlih' => (string) $alih,
            'ReferensiGerbang' => self::Teks($respons->json('referenceNo')),
        ]);
    }

    /**
     * @param  array<string, string>  $rincian
     * @return array{0: Response, 1: StatusPembayaranGerbang}
     */
    private function TanyaQris(string $nomorPesanan, array $rincian, string $jumlah): array
    {
        $respons = $this->klien->Kirim(self::TARGET_QRIS_STATUS, [
            'originalReferenceNo' => (string) ($rincian['ReferensiGerbang'] ?? ''),
            'originalPartnerReferenceNo' => $nomorPesanan,
            'serviceCode' => '47',
            'merchantId' => $this->klien->Pengaturan('IdPedagangQris'),
        ]);

        return [$respons, $respons->successful() && is_array($respons->json()) ? PenguraiStatusSnap::UraiDompetAtauQris($respons->json()) : StatusPembayaranGerbang::Menunggu];
    }

    /**
     * @param  array<string, string>  $rincian
     * @return array{0: Response, 1: StatusPembayaranGerbang}
     */
    private function TanyaVa(array $rincian): array
    {
        $respons = $this->klien->Kirim(self::TARGET_VA_STATUS, [
            'partnerServiceId' => (string) ($rincian['PartnerServiceId'] ?? ''),
            'customerNo' => (string) ($rincian['CustomerNo'] ?? ''),
            'virtualAccountNo' => (string) ($rincian['NomorVa'] ?? ''),
        ]);

        return [$respons, $respons->successful() && is_array($respons->json()) ? PenguraiStatusSnap::UraiVirtualAccount($respons->json()) : StatusPembayaranGerbang::Menunggu];
    }

    /**
     * @param  array<string, string>  $rincian
     * @return array{0: Response, 1: StatusPembayaranGerbang}
     */
    private function TanyaDompet(string $nomorPesanan, array $rincian, string $jumlah): array
    {
        $badan = [
            'serviceCode' => '55',
            'originalPartnerReferenceNo' => $nomorPesanan,
            'amount' => ['value' => self::FormatNominal($jumlah), 'currency' => 'IDR'],
        ];

        if (($rincian['ReferensiGerbang'] ?? '') !== '') {
            $badan['originalReferenceNo'] = $rincian['ReferensiGerbang'];
        }

        $respons = $this->klien->Kirim(self::TARGET_DOMPET_STATUS, $badan);

        return [$respons, $respons->successful() && is_array($respons->json()) ? PenguraiStatusSnap::UraiDompetAtauQris($respons->json()) : StatusPembayaranGerbang::Menunggu];
    }

    /**
     * Jawaban DOKU yang tidak sukses atau tidak memuat data inti. Galat 4xx yang jelas = tidak ada yang dibuat;
     * 5xx atau jawaban sukses yang tak terbaca = mungkin sudah dibuat (`tidakPasti`).
     *
     * @throws GalatGerbang
     */
    private function PastikanBerhasil(Response $respons, bool $dataAda): void
    {
        if (KlienSnap::CekResponsSukses($respons) && $dataAda) {
            return;
        }

        throw new GalatGerbang(
            $this->klien->Saring("DOKU menolak permintaan (HTTP {$respons->status()}): ".$this->klien->PesanPenyedia($respons)),
            tidakPasti: $respons->successful() || $respons->serverError() || in_array($respons->status(), [408, 409, 425, 429], true),
        );
    }

    private static function FormatWaktuDoku(CarbonImmutable $waktu): string
    {
        return $waktu->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:sP');
    }

    private static function Teks(mixed $nilai): string
    {
        return is_string($nilai) || is_int($nilai) ? trim((string) $nilai) : '';
    }

    private static function BersihkanNama(string $nama): string
    {
        $bersih = trim((string) preg_replace('/[^\p{L}\p{N} .,\'-]/u', '', $nama));

        return mb_substr($bersih !== '' ? $bersih : 'Pelanggan Payoung', 0, 100);
    }

    /** Nominal DOKU SNAP: string desimal dua digit ("165390.00"), dihitung dengan BigDecimal (bukan float, aturan #7). */
    private static function FormatNominal(string $jumlah): string
    {
        return (string) BigDecimal::of($jumlah)->toScale(2, RoundingMode::HalfUp);
    }
}
