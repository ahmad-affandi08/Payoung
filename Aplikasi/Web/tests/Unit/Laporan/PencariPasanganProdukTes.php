<?php

declare(strict_types=1);

use App\Domain\Laporan\Layanan\PencariPasanganProduk;

/*
 * Rekomendasi bundling §10.10: analisis keranjang (support, keyakinan, lift), tanpa float.
 */

it('menghitung support, keyakinan A→B / B→A, dan lift. Pasangan di bawah support minimum dibuang', function (): void {
    // 10 transaksi: kopi(1)+roti(2) ×4, kopi saja ×3, roti saja ×1, teh(3)+roti(2) ×1 (support 1), teh saja ×1.
    $keranjang = [
        [1, 2], [2, 1], [1, 2], [1, 2],
        [1], [1], [1],
        [2],
        [3, 2],
        [3],
    ];
    $hasil = (new PencariPasanganProduk)->Cari($keranjang);

    expect($hasil['JumlahTransaksi'])->toBe(10)
        ->and($hasil['Baris'])->toHaveCount(1);
    // kopi muncul 7×, roti 6×, bersama 4× → lift = 4×10 ÷ (7×6) = 0,95; keyakinan kopi→roti 4/7, roti→kopi 4/6.
    expect($hasil['Baris'][0])->toBe([
        'IdProdukA' => 1,
        'IdProdukB' => 2,
        'Support' => 4,
        'PorsiTransaksi' => '40.00',
        'KeyakinanAB' => '57.14',
        'KeyakinanBA' => '66.67',
        'Lift' => '0.95',
    ]);
});

it('urut lift terbesar lalu support. Produk ganda dalam satu transaksi dihitung sekali. Keranjang terlalu besar dilewati', function (): void {
    $keranjang = [];

    for ($i = 0; $i < 3; $i++) {
        $keranjang[] = [1, 1, 2];      // kopi+roti ×3
        $keranjang[] = [3, 4];          // nasi+teh ×3 (lift tinggi karena jarang dibeli sendiri)
    }

    for ($i = 0; $i < 6; $i++) {
        $keranjang[] = [1];             // kopi sering dibeli sendiri → lift kopi+roti lebih rendah
    }

    $keranjang[] = range(100, 140);     // 41 produk: dilewati untuk pasangan

    $hasil = (new PencariPasanganProduk)->Cari($keranjang);

    expect(array_map(fn (array $b): array => [$b['IdProdukA'], $b['IdProdukB']], $hasil['Baris']))->toBe([[3, 4], [1, 2]])
        ->and($hasil['Baris'][0]['Support'])->toBe(3)
        ->and($hasil['JumlahTransaksi'])->toBe(13);
});

it('tanpa transaksi: tidak ada pasangan dan tanpa pembagian nol', function (): void {
    expect((new PencariPasanganProduk)->Cari([]))->toBe(['JumlahTransaksi' => 0, 'Baris' => []]);
});
