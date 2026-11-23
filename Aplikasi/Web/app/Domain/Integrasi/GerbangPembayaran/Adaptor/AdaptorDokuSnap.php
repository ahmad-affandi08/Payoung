<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran\Adaptor;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\HasilQris;
use App\Domain\Integrasi\GerbangPembayaran\HasilWebhook;
use App\Domain\Integrasi\GerbangPembayaran\PermintaanQris;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Integrasi\HasilUjiLayanan;
use App\Domain\Integrasi\Snap\KlienSnap;
use App\Domain\Integrasi\Snap\PenguraiStatusSnap;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * QRIS dinamis toko lewat **DOKU SNAP** (D-314), akun merchant milik tenant sendiri sehingga dana langsung masuk ke
 * rekening toko. Menggantikan DOKU Checkout: QR yang tampil di layar kasir dan pelanggan adalah QRIS (string EMVCo,
 * `qrContent`) yang bisa langsung dipindai e-wallet atau mobile banking mana pun, bukan tautan halaman bayar.
 *
 * Protokol sama dengan tagihan langganan Payoung (`KlienSnap`): token B2B bertanda tangan RSA (kunci pribadi dibuat
 * server per tenant, kunci publiknya didaftarkan tenant di dasbor DOKU) lalu permintaan layanan bertanda tangan
 * HMAC-SHA512. Endpoint `qr-mpm-generate` membuat QR, `qr-mpm-query` menanyakan status.
 *
 * **Notifikasi hanya pemicu.** Isi notifikasi DOKU tidak dipercaya dan tidak diverifikasi tanda tangannya: dari isinya
 * hanya diambil nomor pesanan, lalu statusnya (dan jumlahnya) ditanyakan sendiri ke DOKU lewat API bertanda tangan.
 * Notifikasi palsu karenanya tidak bisa melunasi apa pun (pola yang sama dengan `GerbangBillingSnap`, D-159).
 */
final class AdaptorDokuSnap extends AdaptorDasar
{
    private const TARGET_BUAT = '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate';

    private const TARGET_STATUS = '/snap-adapter/b2b/v1.0/qr/qr-mpm-query';

    /** Nomor pesanan Payoung di mana pun ia muncul dalam badan notifikasi (bentuk badan QRIS DOKU bisa berubah). */
    private const POLA_NOMOR = '/PY[0-9a-z]{1,13}-[0-9A-HJKMNP-TV-Z]{26}/';

    private ?KlienSnap $klien = null;

    public function AmbilKode(): string
    {
        return 'Doku';
    }

    /** Bidang yang wajib terisi sebelum gerbang bisa dipakai, dengan nama yang dikenal pengguna di formulir. */
    public function AmbilKekurangan(): array
    {
        $kurang = [];

        foreach ([
            'IdKlien' => 'Client ID',
            'IdPedagangQris' => 'Merchant ID QRIS',
            'IdTerminalQris' => 'Terminal ID QRIS',
            'KodePosQris' => 'Kode pos QRIS',
        ] as $kunci => $label) {
            if ($this->Pengaturan($kunci) === '') {
                $kurang[] = $label;
            }
        }

        if ($this->Kredensial('KunciRahasia') === '') {
            $kurang[] = 'Secret key';
        }

        if ($this->Kredensial('KunciPribadiSnap') === '') {
            $kurang[] = 'Kunci SNAP (simpan ulang akun merchant agar dibuat)';
        }

        return $kurang;
    }

    public function UjiKoneksi(): HasilUjiLayanan
    {
        $kurang = $this->AmbilKekurangan();

        if ($kurang !== []) {
            return HasilUjiLayanan::Gagal('Lengkapi dulu: '.implode(', ', $kurang).'.');
        }

        $hasil = KlienSnap::UjiTokenB2b($this->Pengaturan('IdKlien'), $this->Kredensial('KunciPribadiSnap'), $this->CekSandbox());

        return $hasil['Berhasil']
            ? HasilUjiLayanan::Berhasil($hasil['Pesan'].' Merchant ID dan Terminal ID baru terbukti saat QRIS pertama dibuat.')
            : HasilUjiLayanan::Gagal($this->Saring($hasil['Pesan']));
    }

    public function BuatQris(PermintaanQris $permintaan): HasilQris
    {
        $kurang = $this->AmbilKekurangan();

        if ($kurang !== []) {
            throw new GalatGerbang('Pengaturan QRIS toko belum lengkap: '.implode(', ', $kurang).'.');
        }

        $respons = $this->Klien()->Kirim(self::TARGET_BUAT, [
            'partnerReferenceNo' => $permintaan->nomorPesanan,
            'amount' => ['value' => self::FormatNominal($permintaan->jumlah), 'currency' => 'IDR'],
            'merchantId' => $this->Pengaturan('IdPedagangQris'),
            'terminalId' => $this->Pengaturan('IdTerminalQris'),
            'validityPeriod' => $permintaan->kedaluwarsaPada->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'additionalInfo' => ['postalCode' => $this->Pengaturan('KodePosQris'), 'feeType' => '1'],
        ]);
        $isiQr = $respons->json('qrContent');

        if (! KlienSnap::CekResponsSukses($respons) || ! is_string($isiQr) || $isiQr === '') {
            // 4xx yang jelas = tidak ada QR yang dibuat. 5xx atau jawaban sukses yang isinya tak terbaca = mungkin sudah dibuat.
            throw new GalatGerbang(
                $this->Saring("DOKU menolak permintaan QRIS (HTTP {$respons->status()}): ".$this->Klien()->PesanPenyedia($respons)),
                tidakPasti: $respons->successful() || $respons->serverError() || in_array($respons->status(), [408, 409, 425, 429], true),
            );
        }

        $referensi = $respons->json('referenceNo');

        return new HasilQris(is_string($referensi) ? trim($referensi) : $permintaan->nomorPesanan, $isiQr, $permintaan->kedaluwarsaPada);
    }

    /** Status ditanyakan dengan nomor pesanan Payoung (`originalPartnerReferenceNo`), tanpa id referensi DOKU. */
    public function CekDapatCekDariNomorPesanan(): bool
    {
        return true;
    }

    public function CekStatus(string $nomorPesanan, string $idReferensi): StatusPembayaranGerbang
    {
        return $this->TanyaStatus($nomorPesanan, $idReferensi)['Status'];
    }

    /**
     * Notifikasi = pemicu. Nomor pesanan diambil dari badan, lalu status dan jumlahnya ditanyakan ke DOKU. Badan tanpa
     * nomor pesanan Payoung = null (dijawab "tidak dikenali"). Pertanyaan yang gagal dijawab = `Menunggu` (tidak ada
     * yang berubah; rekonsiliasi terjadwal dan polling kasir menjadi jaring pengaman).
     */
    public function UraiWebhook(Request $permintaan): ?HasilWebhook
    {
        if (preg_match(self::POLA_NOMOR, $permintaan->getContent(), $cocok) !== 1) {
            return null;
        }

        try {
            $hasil = $this->TanyaStatus($cocok[0], '');
        } catch (GalatGerbang) {
            return new HasilWebhook($cocok[0], StatusPembayaranGerbang::Menunggu);
        }

        return new HasilWebhook($cocok[0], $hasil['Status'], $hasil['Jumlah']);
    }

    /**
     * @return array{Status: StatusPembayaranGerbang, Jumlah: string|null}
     *
     * @throws GalatGerbang bila DOKU tidak bisa dihubungi, menolak permintaan status, atau jawabannya tak terbaca
     */
    private function TanyaStatus(string $nomorPesanan, string $idReferensi): array
    {
        $badan = [
            'originalPartnerReferenceNo' => $nomorPesanan,
            'serviceCode' => '47',
            'merchantId' => $this->Pengaturan('IdPedagangQris'),
        ];

        if (trim($idReferensi) !== '' && trim($idReferensi) !== $nomorPesanan) {
            $badan['originalReferenceNo'] = trim($idReferensi);
        }

        $respons = $this->Klien()->Kirim(self::TARGET_STATUS, $badan);
        $data = $respons->json();

        if ($respons->serverError() || ! is_array($data)) {
            throw new GalatGerbang('Status QRIS belum bisa dipastikan.', tidakPasti: true);
        }

        if (! $respons->successful()) {
            // 4xx = pertanyaan status kita ditolak (format, kredensial), bukan kabar tentang pembayarannya.
            $this->CatatPenolakanStatus($nomorPesanan, $respons);

            throw new GalatGerbang('Status QRIS belum bisa dipastikan.', tidakPasti: true);
        }

        $status = PenguraiStatusSnap::UraiDompetAtauQris($data);
        $jumlah = $status === StatusPembayaranGerbang::Lunas ? PenguraiStatusSnap::AmbilJumlah($data) : '';

        return ['Status' => $status, 'Jumlah' => $jumlah === '' ? null : $jumlah];
    }

    private function CatatPenolakanStatus(string $nomorPesanan, Response $respons): void
    {
        Log::warning('DOKU SNAP menolak pertanyaan status QRIS tenant.', [
            'NomorPesanan' => $nomorPesanan,
            'HttpStatus' => $respons->status(),
            'KodeJawaban' => is_string($respons->json('responseCode')) ? $respons->json('responseCode') : '',
            'PesanJawaban' => $this->Saring((string) ($respons->json('responseMessage') ?? '')),
        ]);
    }

    private function Klien(): KlienSnap
    {
        return $this->klien ??= KlienSnap::DariKredensial(
            $this->Pengaturan('IdKlien'),
            $this->Kredensial('KunciRahasia'),
            $this->Kredensial('KunciPribadiSnap'),
            $this->CekSandbox(),
            [
                'IdPedagangQris' => $this->Pengaturan('IdPedagangQris'),
                'IdTerminalQris' => $this->Pengaturan('IdTerminalQris'),
                'KodePosQris' => $this->Pengaturan('KodePosQris'),
            ],
        );
    }

    /** Nominal SNAP: string desimal dua digit ("165389.00"), dihitung dengan BigDecimal (bukan float, aturan #7). */
    private static function FormatNominal(Uang $jumlah): string
    {
        return (string) BigDecimal::of($jumlah->KeString())->toScale(2, RoundingMode::HalfUp);
    }
}
