<?php

declare(strict_types=1);

use App\Domain\Tenant\Enum\AplikasiRilis;
use App\Domain\Tenant\Layanan\PenyusunUnduhanAplikasi;
use App\Domain\Tenant\Model\RilisAplikasi;

/*
 * v7.35 (F-02b langkah 5, P-10): tautan unduh aplikasi kasir untuk halaman back-office.
 *
 * Regresi yang dijaga: halaman Perangkat & panduan awal meminta kode aktivasi tanpa pernah memberi tahu di mana
 * aplikasinya diunduh. Sumbernya harus rilis `Aktif` kanal `Stabil` versi tertinggi, tanpa memandang rollout,
 * lalu cadangan `config/aplikasi.php`.
 */

beforeEach(function (): void {
    config([
        'aplikasi.Pos.Android.TautanUnduh' => null,
        'aplikasi.Pos.Android.VersiTerbaru' => '1.0.0',
        'aplikasi.Pos.Ios.TautanUnduh' => null,
        'aplikasi.Pos.Windows.TautanUnduh' => null,
    ]);
});

function UnduhanUjiRilis(string $versi, string $status = 'Aktif', string $kanal = 'Stabil', ?string $url = null, int $rollout = 0): RilisAplikasi
{
    return RilisAplikasi::query()->create([
        'Aplikasi' => AplikasiRilis::Pos->value,
        'Platform' => 'Android',
        'Kanal' => $kanal,
        'Versi' => $versi,
        'Status' => $status,
        'PersenRollout' => $rollout,
        'UrlUnduh' => $url ?? "https://play.google.com/store/apps/details?id=id.payoung.kasir&v={$versi}",
    ]);
}

describe('F-02b/P-10 tautan unduh aplikasi kasir di back-office', function (): void {
    it('memakai rilis Stabil berstatus Aktif versi tertinggi tanpa memandang rollout', function (): void {
        UnduhanUjiRilis('1.0.0', rollout: 100);
        UnduhanUjiRilis('1.2.0', rollout: 0);

        $hasil = app(PenyusunUnduhanAplikasi::class)->Susun(AplikasiRilis::Pos);

        expect($hasil)->toHaveCount(1)
            ->and($hasil[0]['Platform'])->toBe('Android')
            ->and($hasil[0]['Label'])->toBe('Android')
            ->and($hasil[0]['Versi'])->toBe('1.2.0')
            ->and($hasil[0]['Url'])->toContain('v=1.2.0')
            ->and($hasil[0]['QrSvg'])->toContain('<svg');
    });

    it('mengabaikan draf, rilis dihentikan, kanal beta, dan rilis tanpa tautan', function (): void {
        UnduhanUjiRilis('9.0.0', status: 'Draf');
        UnduhanUjiRilis('8.0.0', status: 'Dihentikan');
        UnduhanUjiRilis('7.0.0', kanal: 'Beta');
        UnduhanUjiRilis('6.0.0', url: '');
        UnduhanUjiRilis('1.0.0');

        $hasil = app(PenyusunUnduhanAplikasi::class)->Susun(AplikasiRilis::Pos);

        expect($hasil[0]['Versi'])->toBe('1.0.0');
    });

    it('memakai cadangan config per platform dan melewati platform tanpa tautan', function (): void {
        config([
            'aplikasi.Pos.Android.TautanUnduh' => 'https://play.google.com/store/apps/details?id=id.payoung.kasir',
            'aplikasi.Pos.Windows.TautanUnduh' => 'https://unduh.payoung.id/Payoung-Kasir-Pemasang-1.0.0.exe',
        ]);

        $hasil = app(PenyusunUnduhanAplikasi::class)->Susun(AplikasiRilis::Pos);

        expect(array_column($hasil, 'Platform'))->toBe(['Android', 'Windows'])
            ->and($hasil[0]['Versi'])->toBe('1.0.0')
            // QR hanya pada satu platform (Android didahulukan), bukan tiga gambar QR bertumpuk.
            ->and($hasil[0]['QrSvg'])->toContain('<svg')
            ->and($hasil[1]['QrSvg'])->toBeNull();
    });

    it('mengembalikan daftar kosong bila belum ada rilis maupun tautan config', function (): void {
        expect(app(PenyusunUnduhanAplikasi::class)->Susun(AplikasiRilis::Pos))->toBe([]);
    });
});
