<?php

declare(strict_types=1);

use App\Domain\Laporan\Layanan\PenilaiRencanaProduksi;
use Carbon\CarbonImmutable;

/*
 * §9.11 Bakery: rencana produksi harian = kebutuhan (terbesar dari rata-rata hari yang sama dan pre-order) − saldo.
 * Fungsi murni, bilangan desimal tanpa float.
 */

function Rencana(array $harian, string $hariIni, string $target, string $preOrder = '0', string $saldo = '0', bool $desimal = false): array
{
    return (new PenilaiRencanaProduksi)->Hitung($harian, CarbonImmutable::parse($hariIni), CarbonImmutable::parse($target), $preOrder, $saldo, $desimal);
}

// Rabu 14 Okt 2026; target Jumat 16 Okt. Jumat sebelumnya: 9 Okt, 2 Okt, 25 Sep, 18 Sep.
const HARIAN_JUMAT = ['2026-10-09' => '10.0000', '2026-10-02' => '6.0000', '2026-09-25' => '8.0000'];

it('rata-rata hari yang sama dari empat minggu terakhir. Hari tanpa penjualan dihitung nol', function (): void {
    $hasil = Rencana(HARIAN_JUMAT, '2026-10-14', '2026-10-16');

    expect($hasil['Sampel'])->toBe(4)
        ->and($hasil['RataHariSama'])->toBe('6.0000')
        ->and($hasil['Kebutuhan'])->toBe('6.0000')
        ->and($hasil['Rekomendasi'])->toBe('6.0000');
});

it('hari lain dalam seminggu tidak ikut dihitung', function (): void {
    $hasil = Rencana([...HARIAN_JUMAT, '2026-10-08' => '500.0000', '2026-10-10' => '500.0000'], '2026-10-14', '2026-10-16');

    expect($hasil['RataHariSama'])->toBe('6.0000');
});

it('kebutuhan = yang terbesar antara rata-rata dan pre-order (tidak dijumlahkan)', function (): void {
    $kecil = Rencana(HARIAN_JUMAT, '2026-10-14', '2026-10-16', preOrder: '4');
    $besar = Rencana(HARIAN_JUMAT, '2026-10-14', '2026-10-16', preOrder: '9');

    expect($kecil['Kebutuhan'])->toBe('6.0000')
        ->and($besar['Kebutuhan'])->toBe('9.0000')
        ->and($besar['PreOrder'])->toBe('9.0000');
});

it('rekomendasi = kebutuhan − saldo, tidak pernah negatif. Satuan bulat dibulatkan ke atas, desimal tetap empat angka', function (): void {
    expect(Rencana(HARIAN_JUMAT, '2026-10-14', '2026-10-16', saldo: '2')['Rekomendasi'])->toBe('4.0000')
        ->and(Rencana(HARIAN_JUMAT, '2026-10-14', '2026-10-16', saldo: '6')['Rekomendasi'])->toBe('0.0000')
        ->and(Rencana(HARIAN_JUMAT, '2026-10-14', '2026-10-16', saldo: '40')['Rekomendasi'])->toBe('0.0000')
        // Saldo minus (stok boleh minus) menambah kebutuhan produksi.
        ->and(Rencana(HARIAN_JUMAT, '2026-10-14', '2026-10-16', saldo: '-3')['Rekomendasi'])->toBe('9.0000')
        // Rata-rata 4,25: satuan bulat → 5 (dibulatkan ke atas); satuan desimal → 4,25.
        ->and(Rencana(['2026-10-09' => '17.0000'], '2026-10-14', '2026-10-16')['Rekomendasi'])->toBe('5.0000')
        ->and(Rencana(['2026-10-09' => '17.0000'], '2026-10-14', '2026-10-16', desimal: true)['Rekomendasi'])->toBe('4.2500');
});

it('hari ini tidak pernah dihitung sebagai riwayat (belum selesai), tanpa riwayat hanya pre-order yang berlaku', function (): void {
    // Target Rabu 21 Okt: minggu sebelumnya jatuh pada hari ini (14 Okt) sehingga dilewati; tersisa 7 Okt, 30 Sep, 23 Sep.
    $hasil = Rencana(['2026-10-14' => '99.0000'], '2026-10-14', '2026-10-21');

    expect($hasil['Sampel'])->toBe(3)
        ->and($hasil['RataHariSama'])->toBe('0.0000')
        ->and(Rencana([], '2026-10-14', '2026-10-21', preOrder: '12')['Rekomendasi'])->toBe('12.0000')
        ->and(Rencana([], '2026-10-14', '2026-10-21')['Rekomendasi'])->toBe('0.0000');
});

it('zona waktu tidak menggeser hari: jam dan zona pada tanggal masukan diabaikan', function (): void {
    $pagi = (new PenilaiRencanaProduksi)->Hitung(HARIAN_JUMAT, CarbonImmutable::parse('2026-10-14 23:30:00', 'Asia/Jakarta'), CarbonImmutable::parse('2026-10-16 00:10:00', 'Asia/Jakarta'), '0', '0', false);

    expect($pagi['RataHariSama'])->toBe('6.0000');
});
