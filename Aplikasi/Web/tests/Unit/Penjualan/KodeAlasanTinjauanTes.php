<?php

declare(strict_types=1);

use App\Domain\Penjualan\Enum\KodeAlasanTinjauan;

/*
 * PRD v1.46 saran (6): `AlasanTinjauan` (`Kode: keterangan; ...`) diurai menjadi label manusiawi untuk halaman web.
 */

it('mengurai beberapa alasan. Titik koma di dalam keterangan tidak memecah alasan. Kode tidak dikenal tetap tampil', function (): void {
    $alasan = 'IzinBerubah: Sari tidak lagi terdaftar di outlet ini; Sari tidak lagi punya izin berjualan; '
        .'PengaturanBerbeda: biaya layanan di perangkat 5%, pengaturan outlet 0%; StokTidakCukup: Minyak Goreng 2 Liter (sisa −3); '
        .'KodeBaru: sesuatu yang belum dikenal';

    expect(KodeAlasanTinjauan::Urai($alasan))->toBe([
        ['Kode' => 'IzinBerubah', 'Label' => 'Izin atau outlet kasir berubah', 'Keterangan' => 'Sari tidak lagi terdaftar di outlet ini; Sari tidak lagi punya izin berjualan'],
        ['Kode' => 'PengaturanBerbeda', 'Label' => 'Pengaturan kasir di perangkat berbeda', 'Keterangan' => 'biaya layanan di perangkat 5%, pengaturan outlet 0%'],
        ['Kode' => 'StokTidakCukup', 'Label' => 'Stok tidak cukup saat penjualan diterima', 'Keterangan' => 'Minyak Goreng 2 Liter (sisa −3)'],
        ['Kode' => null, 'Label' => 'Perlu diperiksa', 'Keterangan' => 'KodeBaru: sesuatu yang belum dikenal'],
    ])
        ->and(KodeAlasanTinjauan::Urai(null))->toBe([])
        ->and(KodeAlasanTinjauan::Urai('  '))->toBe([]);
});

it('setiap kode punya label Bahasa Indonesia yang bukan kode mesin', function (): void {
    foreach (KodeAlasanTinjauan::cases() as $kode) {
        expect($kode->AmbilLabel())->not->toBe($kode->value)->and($kode->AmbilLabel())->toMatch('/^[A-Z][a-z]/');
    }
});
