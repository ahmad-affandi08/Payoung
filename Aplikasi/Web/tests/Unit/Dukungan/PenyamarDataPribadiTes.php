<?php

declare(strict_types=1);

use App\Domain\Dukungan\Layanan\PenyamarDataPribadi;

/* PGL-16 / BR-P09.1: penyamaran data pribadi pada data yang dilihat petugas dukungan. */

beforeEach(function (): void {
    $this->penyamar = new PenyamarDataPribadi;
});

it('menyamarkan nomor HP (sisakan tiga angka), email, alamat, tanggal lahir, identitas pajak, dan rekening', function (): void {
    $hasil = $this->penyamar->Samarkan([
        'NoHp' => '6281234567890',
        'Email' => 'budi.santoso@contoh.id',
        'Alamat' => 'Jl. Melati No. 12',
        'TanggalLahir' => '1990-05-17',
        'Npwp' => '12.345.678.9-012.000',
        'Nik' => '3372011705900001',
        'NomorRekening' => '1234567890',
    ]);

    expect($hasil['NoHp'])->toBe('••••••890')
        ->and($hasil['Email'])->toBe('b•••@contoh.id')
        ->and($hasil['Alamat'])->toBe('•••••')
        ->and($hasil['TanggalLahir'])->toBe('•••••')
        ->and($hasil['Npwp'])->toBe('•••••')
        ->and($hasil['Nik'])->toBe('•••••')
        ->and($hasil['NomorRekening'])->toBe('•••••');
});

it('menyamarkan nama orang pada kunci nama pelanggan, pemesan, dan kontak', function (): void {
    $hasil = $this->penyamar->Samarkan(['NamaPelanggan' => 'Budi Santoso', 'NamaPemesan' => 'Ani', 'Pelanggan' => 'Citra Dewi']);

    expect($hasil)->toBe(['NamaPelanggan' => 'B•••', 'NamaPemesan' => 'A•••', 'Pelanggan' => 'C•••']);
});

it('`Nama` hanya disamarkan di dalam objek pelanggan, bukan nama paket atau pengguna di halaman yang sama', function (): void {
    $hasil = $this->penyamar->Samarkan([
        'Pengguna' => ['Nama' => 'Rina Pemilik'],
        'Paket' => ['Nama' => 'Pro'],
        'Pelanggan' => ['Nama' => 'Budi Santoso', 'NoHp' => '081234567890', 'Tier' => ['Nama' => 'Gold']],
    ]);

    expect($hasil['Pengguna']['Nama'])->toBe('Rina Pemilik')
        ->and($hasil['Paket']['Nama'])->toBe('Pro')
        ->and($hasil['Pelanggan']['Nama'])->toBe('B•••')
        ->and($hasil['Pelanggan']['NoHp'])->toBe('••••••890')
        ->and($hasil['Pelanggan']['Tier']['Nama'])->toBe('G•••');
});

it('baris tabel pelanggan memakai bendera nama orang', function (): void {
    $hasil = $this->penyamar->Samarkan([['Nama' => 'Budi', 'NoHp' => '0812345']], true);

    expect($hasil[0]['Nama'])->toBe('B•••');
});

it('kunci berawalan Email yang bukan alamat (waktu verifikasi, status) tidak disentuh', function (): void {
    $hasil = $this->penyamar->Samarkan(['EmailTerverifikasi' => true, 'EmailDiverifikasiPada' => '2026-10-01T00:00:00Z', 'EmailNotifikasi' => 'owner@toko.id']);

    expect($hasil['EmailTerverifikasi'])->toBeTrue()
        ->and($hasil['EmailDiverifikasiPada'])->toBe('2026-10-01T00:00:00Z')
        ->and($hasil['EmailNotifikasi'])->toBe('o•••@toko.id');
});

it('alamat jaringan (AlamatIp, AlamatApi) dan nilai kosong tidak disamarkan', function (): void {
    $hasil = $this->penyamar->Samarkan(['AlamatIp' => '10.0.0.1', 'AlamatApi' => 'https://api.contoh.id', 'Alamat' => null, 'NoHp' => '']);

    expect($hasil['AlamatIp'])->toBe('10.0.0.1')
        ->and($hasil['AlamatApi'])->toBe('https://api.contoh.id')
        ->and($hasil['Alamat'])->toBeNull()
        ->and($hasil['NoHp'])->toBe('');
});

it('alamat email di dalam teks bebas (catatan, pesan) ikut disamarkan', function (): void {
    $hasil = $this->penyamar->Samarkan(['Catatan' => 'Hubungi budi.santoso@contoh.id sebelum jam 5.']);

    expect($hasil['Catatan'])->toBe('Hubungi b•••@contoh.id sebelum jam 5.');
});
