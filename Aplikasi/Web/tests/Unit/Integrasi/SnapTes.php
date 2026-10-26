<?php

declare(strict_types=1);

use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Integrasi\Snap\Data\KanalVaSnap;
use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use App\Domain\Integrasi\Snap\PembuatKunciSnap;
use App\Domain\Integrasi\Snap\PenguraiStatusSnap;

/*
 * D-159: aturan murni DOKU SNAP — pembacaan status, daftar bank virtual account, dan kunci RSA.
 * Aturan keselamatan: hanya bukti lunas yang tegas yang melunasi; jawaban tak dikenal = menunggu.
 */

describe('PenguraiStatusSnap', function (): void {
    it('QRIS & e-wallet: 00 lunas, 05/06 gagal, lainnya menunggu', function (string $kode, StatusPembayaranGerbang $harapan): void {
        expect(PenguraiStatusSnap::UraiDompetAtauQris(['latestTransactionStatus' => $kode]))->toBe($harapan);
    })->with([
        ['00', StatusPembayaranGerbang::Lunas],
        ['05', StatusPembayaranGerbang::Gagal],
        ['06', StatusPembayaranGerbang::Gagal],
        ['01', StatusPembayaranGerbang::Menunggu],
        ['03', StatusPembayaranGerbang::Menunggu],
        ['07', StatusPembayaranGerbang::Menunggu],
        ['99', StatusPembayaranGerbang::Menunggu],
    ]);

    it('QRIS tanpa kolom status sama sekali tidak melunasi', function (): void {
        expect(PenguraiStatusSnap::UraiDompetAtauQris([]))->toBe(StatusPembayaranGerbang::Menunggu);
    });

    it('virtual account: paymentFlagStatus 00 lunas. Jawaban Pending dengan paidAmount terisi TIDAK lunas', function (): void {
        expect(PenguraiStatusSnap::UraiVirtualAccount(['virtualAccountData' => ['paymentFlagStatus' => '00']]))->toBe(StatusPembayaranGerbang::Lunas)
            ->and(PenguraiStatusSnap::UraiVirtualAccount(['virtualAccountData' => ['paymentFlagStatus' => '01']]))->toBe(StatusPembayaranGerbang::Menunggu)
            ->and(PenguraiStatusSnap::UraiVirtualAccount([
                'virtualAccountData' => ['paymentFlagReason' => ['english' => 'Pending', 'indonesia' => 'Menunggu'], 'paidAmount' => ['value' => '149000.00']],
            ]))->toBe(StatusPembayaranGerbang::Menunggu)
            ->and(PenguraiStatusSnap::UraiVirtualAccount(['virtualAccountData' => ['paymentFlagReason' => ['english' => 'Success']]]))->toBe(StatusPembayaranGerbang::Lunas)
            ->and(PenguraiStatusSnap::UraiVirtualAccount([]))->toBe(StatusPembayaranGerbang::Menunggu);
    });

    it('jumlah dibayar diambil dari paidAmount/amount/transAmount, kosong bila tak ada atau nol', function (): void {
        expect(PenguraiStatusSnap::AmbilJumlah(['virtualAccountData' => ['paidAmount' => ['value' => '149000.00']]]))->toBe('149000.00')
            ->and(PenguraiStatusSnap::AmbilJumlah(['amount' => ['value' => '99000.00']]))->toBe('99000.00')
            ->and(PenguraiStatusSnap::AmbilJumlah(['transAmount' => ['value' => '59000.00']]))->toBe('59000.00')
            ->and(PenguraiStatusSnap::AmbilJumlah(['amount' => ['value' => '0.00']]))->toBe('')
            ->and(PenguraiStatusSnap::AmbilJumlah([]))->toBe('');
    });
});

describe('KanalVaSnap', function (): void {
    it('mengurai entri sah dipisah titik koma atau baris baru dan membuang yang tidak lengkap', function (): void {
        $daftar = KanalVaSnap::Urai("BNI|VIRTUAL_ACCOUNT_BNI|98829172|3; BRI|VIRTUAL_ACCOUNT_BRI|12345|7\nrusak|tanpa-bagian;huruf kecil|virtual_account_x|1|2;Tanpa Prefiks|VIRTUAL_ACCOUNT_X|123|");

        expect($daftar)->toHaveCount(2)
            ->and($daftar[0]->label)->toBe('BNI')
            ->and($daftar[0]->kode)->toBe('VIRTUAL_ACCOUNT_BNI')
            ->and($daftar[0]->partnerServiceId)->toBe('98829172')
            ->and($daftar[0]->prefiksPelanggan)->toBe('3')
            ->and($daftar[1]->AmbilPartnerServiceIdBerisi())->toBe('   12345');
    });

    it('kode channel ganda hanya dihitung sekali (yang terakhir menang) dan teks kosong menghasilkan daftar kosong', function (): void {
        expect(KanalVaSnap::Urai('A|VIRTUAL_ACCOUNT_BNI|1|1;B|VIRTUAL_ACCOUNT_BNI|2|2'))->toHaveCount(1)
            ->and(KanalVaSnap::Urai(''))->toBe([]);
    });
});

describe('MetodeBayarSnap & PembuatKunciSnap', function (): void {
    it('tampilan per metode dan kode kanal dompet sesuai dokumen DOKU', function (): void {
        expect(MetodeBayarSnap::Qris->AmbilTampilan())->toBe('Qr')
            ->and(MetodeBayarSnap::VirtualAccount->AmbilTampilan())->toBe('NomorVa')
            ->and(MetodeBayarSnap::Dana->AmbilTampilan())->toBe('Alihkan')
            ->and(MetodeBayarSnap::Dana->AmbilKodeKanalDompet())->toBe('EMONEY_DANA_SNAP')
            ->and(MetodeBayarSnap::ShopeePay->AmbilKodeKanalDompet())->toBe('EMONEY_SHOPEE_PAY_SNAP')
            ->and(MetodeBayarSnap::Qris->AmbilKodeKanalDompet())->toBeNull();
    });

    it('pasangan kunci RSA 2048: kunci publik memverifikasi tanda tangan kunci pribadi', function (): void {
        $pasangan = (new PembuatKunciSnap)->Buat();

        expect($pasangan['Pribadi'])->toContain('PRIVATE KEY')
            ->and($pasangan['Publik'])->toStartWith('-----BEGIN PUBLIC KEY-----');

        openssl_sign('uji', $tandaTangan, $pasangan['Pribadi'], OPENSSL_ALGO_SHA256);

        expect(openssl_verify('uji', $tandaTangan, $pasangan['Publik'], OPENSSL_ALGO_SHA256))->toBe(1)
            ->and(strlen(PembuatKunciSnap::BuatSidikJari($pasangan['Publik'])))->toBe(64);
    });
});
