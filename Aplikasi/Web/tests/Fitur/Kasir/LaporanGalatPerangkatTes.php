<?php

declare(strict_types=1);

use App\Domain\Organisasi\Aksi\CatatLaporanGalatPerangkat;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-21 (§17.2.6, §20 "error tracking dengan konteks IdTenant, IdPerangkat, tanpa PII berlebih"): aplikasi kasir
 * mengirim log galat ke `POST /api/pos/v1/perangkat/galat`; server menulisnya ke kanal `galat-perangkat` setelah
 * menyamarkan email, nomor panjang, dan token.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('galat ditulis ke kanal galat-perangkat dengan konteks perangkat & tanpa data pribadi; validasi & token wajib', function (): void {
    $k = BantuanKasir::Siapkan($this);
    $dicatat = [];
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->andReturnUsing(function (string $pesan, array $konteks) use (&$dicatat): void {
        $dicatat[] = ['error', $pesan, $konteks];
    });
    $logger->shouldReceive('warning')->andReturnUsing(function (string $pesan, array $konteks) use (&$dicatat): void {
        $dicatat[] = ['warning', $pesan, $konteks];
    });
    Log::shouldReceive('channel')->with('galat-perangkat')->andReturn($logger);

    $this->withToken($k['Token'])->withHeader('X-Versi-Aplikasi', '1.4.2')->postJson('/api/pos/v1/perangkat/galat', ['Galat' => [
        [
            'Waktu' => '2026-10-02T03:00:00Z',
            'Tingkat' => 'Galat',
            'Sumber' => 'Flutter',
            'Pesan' => 'Gagal kirim struk WA ke 0812-3456-7890 (rina@contoh.id)',
            'Jejak' => "#0 KirimStruk (Struk.dart:10)\nAuthorization: Bearer abc.def-123",
        ],
        ['Waktu' => '2026-10-02T03:01:00Z', 'Tingkat' => 'Peringatan', 'Sumber' => 'Printer', 'Pesan' => 'Printer tidak menjawab', 'Jejak' => null],
    ]])->assertOk()->assertJsonPath('Diterima', 2);

    expect($dicatat)->toHaveCount(2)
        ->and($dicatat[0][0])->toBe('error')
        ->and($dicatat[0][1])->toBe('Gagal kirim struk WA ke [disamarkan] ([disamarkan])')
        ->and($dicatat[0][2]['IdTenant'])->toBe($k['Tenant']->Id)
        ->and($dicatat[0][2]['IdPerangkat'])->toBe($k['Perangkat']->Id)
        ->and($dicatat[0][2]['Versi'])->toBe('1.4.2')
        ->and($dicatat[0][2]['Jejak'])->toContain('Bearer [disamarkan]')->not->toContain('abc.def')
        ->and($dicatat[1][0])->toBe('warning');

    $this->withToken($k['Token'])->postJson('/api/pos/v1/perangkat/galat', ['Galat' => [['Tingkat' => 'Bencana', 'Sumber' => 'X', 'Pesan' => 'x', 'Waktu' => 'kemarin']]])
        ->assertUnprocessable();
    $this->withoutToken()->postJson('/api/pos/v1/perangkat/galat', ['Galat' => []])->assertUnauthorized();
});

it('filter menyamarkan NIK 16 digit, nomor kartu berspasi, dan +62, tetapi tidak angka pendek', function (): void {
    expect(CatatLaporanGalatPerangkat::Saring('NIK 3372011234567890 kartu 4111 1111 1111 1111 hp +62 812 3456 7890 baris 42 kode 500'))
        ->toBe('NIK [disamarkan] kartu [disamarkan] hp [disamarkan] baris 42 kode 500');
});
