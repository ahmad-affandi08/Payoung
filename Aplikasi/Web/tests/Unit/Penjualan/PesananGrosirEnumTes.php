<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;

/*
 * Grosir bagian 1 (F-12, §9.7, D-32): status SO grosir, penomoran, dan izinnya.
 */

describe('StatusPesananGrosir', function (): void {
    it('Draf hanya boleh dikonfirmasi atau dibatalkan', function (): void {
        expect(StatusPesananGrosir::Draf->BisaBerubahKe(StatusPesananGrosir::Dikonfirmasi))->toBeTrue()
            ->and(StatusPesananGrosir::Draf->BisaBerubahKe(StatusPesananGrosir::Dibatalkan))->toBeTrue()
            // Tidak boleh melompat ke pengiriman tanpa konfirmasi: limit kredit BR-12.6 diperiksa saat konfirmasi.
            ->and(StatusPesananGrosir::Draf->BisaBerubahKe(StatusPesananGrosir::SebagianDikirim))->toBeFalse()
            ->and(StatusPesananGrosir::Draf->BisaBerubahKe(StatusPesananGrosir::Selesai))->toBeFalse();
    });

    it('Selesai bisa kembali ke SebagianDikirim atau Dikonfirmasi karena surat jalan masih bisa dibatalkan', function (): void {
        // J-12.3: surat jalan yang belum difakturkan boleh dibatalkan, dan SO harus kembali mencerminkan jumlah
        // yang benar-benar terkirim. Karena itu Selesai sengaja BUKAN status final.
        expect(StatusPesananGrosir::Selesai->BisaBerubahKe(StatusPesananGrosir::SebagianDikirim))->toBeTrue()
            ->and(StatusPesananGrosir::Selesai->BisaBerubahKe(StatusPesananGrosir::Dikonfirmasi))->toBeTrue()
            ->and(StatusPesananGrosir::SebagianDikirim->BisaBerubahKe(StatusPesananGrosir::Dikonfirmasi))->toBeTrue();
    });

    it('Dibatalkan final: koreksi setelah barang berjalan lewat retur, bukan menghidupkan pesanan', function (): void {
        foreach (StatusPesananGrosir::cases() as $tujuan) {
            expect(StatusPesananGrosir::Dibatalkan->BisaBerubahKe($tujuan))->toBeFalse($tujuan->value);
        }
    });

    it('SO tidak bisa dibatalkan setelah ada pengiriman', function (): void {
        expect(StatusPesananGrosir::Dikonfirmasi->BisaBerubahKe(StatusPesananGrosir::Dibatalkan))->toBeTrue()
            ->and(StatusPesananGrosir::SebagianDikirim->BisaBerubahKe(StatusPesananGrosir::Dibatalkan))->toBeFalse()
            ->and(StatusPesananGrosir::Selesai->BisaBerubahKe(StatusPesananGrosir::Dibatalkan))->toBeFalse();
    });

    it('hanya Draf boleh diubah. Hanya Dikonfirmasi & SebagianDikirim boleh dikirim', function (): void {
        expect(StatusPesananGrosir::Draf->CekBolehDiubah())->toBeTrue()
            ->and(StatusPesananGrosir::Dikonfirmasi->CekBolehDiubah())->toBeFalse()
            ->and(StatusPesananGrosir::Dikonfirmasi->CekBolehDikirim())->toBeTrue()
            ->and(StatusPesananGrosir::SebagianDikirim->CekBolehDikirim())->toBeTrue()
            ->and(StatusPesananGrosir::Draf->CekBolehDikirim())->toBeFalse()
            ->and(StatusPesananGrosir::Selesai->CekBolehDikirim())->toBeFalse()
            ->and(StatusPesananGrosir::Dibatalkan->CekBolehDikirim())->toBeFalse();
    });

    it('setiap status punya label berteks (§17.6.3: warna hanya penguat)', function (): void {
        foreach (StatusPesananGrosir::cases() as $status) {
            expect($status->AmbilLabel())->not->toBe('');
        }
    });
});

describe('penomoran grosir', function (): void {
    it('awalan PG dengan urut 4 digit, dan tidak bertabrakan dengan awalan yang sudah dipakai', function (): void {
        expect(JenisDokumenBernomor::PesananGrosir->AmbilAwalan())->toBe('PG')
            ->and(JenisDokumenBernomor::PesananGrosir->AmbilPanjangUrut())->toBe(4)
            // `SO` sudah milik StokOpname, jadi SO grosir tidak boleh memakainya.
            ->and(JenisDokumenBernomor::StokOpname->AmbilAwalan())->toBe('SO');

        $awalan = array_map(fn (JenisDokumenBernomor $j): string => $j->AmbilAwalan(), JenisDokumenBernomor::cases());
        expect($awalan)->toHaveCount(count(array_unique($awalan)), 'awalan nomor dokumen harus unik: '.implode(',', $awalan));
    });
});

describe('izin grosir', function (): void {
    it('grosir.kelola bawaan Manajer Outlet & Supervisor. Persetujuan kredit hanya Manajer Outlet', function (): void {
        $izin = fn (PeranTenantBawaan $peran): array => array_map(fn (IzinTenant $i): string => $i->value, $peran->AmbilIzin());

        expect($izin(PeranTenantBawaan::ManajerOutlet))->toContain('grosir.kelola')
            ->and($izin(PeranTenantBawaan::ManajerOutlet))->toContain('grosir.setujui-kredit')
            ->and($izin(PeranTenantBawaan::Supervisor))->toContain('grosir.kelola')
            // BR-12.6 menyangkut risiko piutang usaha, bukan kelonggaran satu transaksi seperti diskon atau
            // selisih kas, jadi Supervisor tidak mendapatkannya secara bawaan.
            ->and($izin(PeranTenantBawaan::Supervisor))->not->toContain('grosir.setujui-kredit')
            ->and($izin(PeranTenantBawaan::Kasir))->not->toContain('grosir.kelola');
    });

    it('izin grosir bukan khusus Pemilik, jadi bisa dipakai peran kustom', function (): void {
        expect(IzinTenant::GrosirKelola->CekKhususPemilik())->toBeFalse()
            ->and(IzinTenant::GrosirSetujuiKredit->CekKhususPemilik())->toBeFalse()
            ->and(IzinTenant::GrosirKelola->AmbilKelompok())->toBe('Penjualan')
            ->and(IzinTenant::GrosirKelola->AmbilLabel())->not->toBe('');
    });
});
