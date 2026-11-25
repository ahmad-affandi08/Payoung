<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran\Adaptor;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\HasilQris;
use App\Domain\Integrasi\GerbangPembayaran\HasilStatusGerbang;
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

    /** Nilai `Pengaturan.SumberAkun` untuk gerbang uji yang memakai akun DOKU induk Payoung (D-316). */
    public const SUMBER_AKUN_PAYOUNG = 'Payoung';

    private ?KlienSnap $klien = null;

    /** Gerbang tenant uji yang memakai akun DOKU induk Payoung, bukan akun merchant milik toko. */
    private function CekAkunPayoung(): bool
    {
        return $this->Pengaturan('SumberAkun') === self::SUMBER_AKUN_PAYOUNG;
    }

    public function AmbilKode(): string
    {
        return 'Doku';
    }

    /** Bidang yang wajib terisi sebelum gerbang bisa dipakai, dengan nama yang dikenal pengguna di formulir. */
    public function AmbilKekurangan(): array
    {
        if ($this->CekAkunPayoung()) {
            $klien = $this->Klien();

            return $klien->CekSiap() && $klien->Pengaturan('IdPedagangQris') !== '' && $klien->Pengaturan('IdTerminalQris') !== '' && $klien->Pengaturan('KodePosQris') !== ''
                ? []
                : ['Akun DOKU Payoung belum lengkap di konsol (Integrasi › Akun DOKU Payoung)'];
        }

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

        if ($this->CekAkunPayoung()) {
            try {
                $this->Klien()->AmbilTokenB2b(paksaBaru: true);
            } catch (GalatGerbang $galat) {
                return HasilUjiLayanan::Gagal($this->Saring($galat->getMessage()));
            }

            return HasilUjiLayanan::Berhasil('Akun DOKU Payoung menerbitkan token SNAP. Gerbang uji ini memakai akun itu, jadi uang masuk ke akun Payoung.');
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
            'merchantId' => $this->Klien()->Pengaturan('IdPedagangQris'),
            'terminalId' => $this->Klien()->Pengaturan('IdTerminalQris'),
            'validityPeriod' => $permintaan->kedaluwarsaPada->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:sP'),
            'additionalInfo' => ['postalCode' => $this->Klien()->Pengaturan('KodePosQris'), 'feeType' => '1'],
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

    public function CekStatusDenganJumlah(string $nomorPesanan, string $idReferensi): HasilStatusGerbang
    {
        $hasil = $this->TanyaStatus($nomorPesanan, $idReferensi);

        return new HasilStatusGerbang($hasil['Status'], $hasil['Jumlah']);
    }

    /**
     * Notifikasi = pemicu. Nomor pesanan diambil dari badan, lalu status dan jumlahnya ditanyakan ke DOKU. Badan tanpa
     * nomor pesanan Payoung = null (dijawab "tidak dikenali"). Pertanyaan yang gagal dijawab = `Menunggu` bertanda
     * `belumTerkonfirmasi`: penerima menanyakan ulang dengan id transaksi tersimpan, dan bila masih gagal menjawab 503
     * supaya DOKU mengirim ulang (rekonsiliasi terjadwal tetap jaring pengaman terakhir).
     */
    public function UraiWebhook(Request $permintaan): ?HasilWebhook
    {
        if (preg_match(self::POLA_NOMOR, $permintaan->getContent(), $cocok) !== 1) {
            return null;
        }

        // `originalReferenceNo` wajib di permintaan status DOKU. Notifikasi membawanya (id transaksi DOKU) di badan.
        $badan = json_decode($permintaan->getContent(), true);
        $idReferensi = is_array($badan) && is_string($badan['originalReferenceNo'] ?? null) ? $badan['originalReferenceNo'] : '';

        try {
            $hasil = $this->TanyaStatus($cocok[0], $idReferensi);
        } catch (GalatGerbang) {
            // Status belum bisa dipastikan sekarang. Ditandai agar penerima menanyakan ulang memakai id transaksi
            // yang tersimpan, atau menjawab agar DOKU mengirim ulang, bukan menganggapnya "tidak ada kabar".
            return new HasilWebhook(
                $cocok[0],
                StatusPembayaranGerbang::Menunggu,
                idReferensi: $idReferensi !== '' ? $idReferensi : null,
                belumTerkonfirmasi: true,
            );
        }

        return new HasilWebhook($cocok[0], $hasil['Status'], $hasil['Jumlah'], $idReferensi !== '' ? $idReferensi : null);
    }

    /**
     * @return array{Status: StatusPembayaranGerbang, Jumlah: string|null}
     *
     * @throws GalatGerbang bila DOKU tidak bisa dihubungi, menolak permintaan status, atau jawabannya tak terbaca
     */
    private function TanyaStatus(string $nomorPesanan, string $idReferensi): array
    {
        // Menurut dokumentasi DOKU, keempat isian ini wajib. Tanpa id transaksi DOKU, nomor pesanan Payoung dipakai
        // sebagai gantinya (generate QRIS memakainya sebagai `partnerReferenceNo`, dan DOKU sering menyamakannya).
        $badan = [
            'originalReferenceNo' => trim($idReferensi) !== '' ? trim($idReferensi) : $nomorPesanan,
            'originalPartnerReferenceNo' => $nomorPesanan,
            'serviceCode' => '47',
            'merchantId' => $this->Klien()->Pengaturan('IdPedagangQris'),
        ];

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
        $this->CatatStatusTakDikenal($nomorPesanan, $data);
        $jumlah = $status === StatusPembayaranGerbang::Lunas ? PenguraiStatusSnap::AmbilJumlah($data) : '';

        return ['Status' => $status, 'Jumlah' => $jumlah === '' ? null : $jumlah];
    }

    /** Kode status di luar yang kita kenal dicatat supaya pembayaran yang "tidak sampai" bisa diusut dari log. */
    private function CatatStatusTakDikenal(string $nomorPesanan, array $data): void
    {
        $kode = is_scalar($data['latestTransactionStatus'] ?? null) ? (string) $data['latestTransactionStatus'] : '';

        if (in_array($kode, ['00', '03', '05', '06'], true)) {
            return;
        }

        Log::warning('DOKU SNAP menjawab status QRIS tenant dengan kode yang belum dikenal.', [
            'NomorPesanan' => $nomorPesanan,
            'KodeJawaban' => is_string($data['responseCode'] ?? null) ? $data['responseCode'] : '',
            'StatusTerakhir' => $kode,
            'KeteranganStatus' => $this->Saring(is_string($data['transactionStatusDesc'] ?? null) ? $data['transactionStatusDesc'] : ''),
        ]);
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

    /**
     * Pesan galat yang tampil ke tenant disaring dari rahasia. Pada akun Payoung (tenant uji, D-316) rahasianya milik
     * platform, bukan kredensial tenant yang disaring `AdaptorDasar`, jadi klien SNAP ikut menyamarkan kuncinya sendiri.
     */
    protected function Saring(string $pesan): string
    {
        $pesan = parent::Saring($pesan);

        return $this->CekAkunPayoung() ? $this->Klien()->Saring($pesan) : $pesan;
    }

    private function Klien(): KlienSnap
    {
        if ($this->CekAkunPayoung()) {
            // Akun DOKU induk Payoung (konfigurasi integrasi `GerbangBilling`): hanya untuk tenant uji (`PembuatGerbangPembayaran`).
            return $this->klien ??= new KlienSnap;
        }

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
