<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Persediaan\Aksi\AjukanPostingStokAwal;
use App\Domain\Persediaan\Aksi\PostingStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Tugas\PostingStokAwalTugas;
use Illuminate\Support\Facades\Queue;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05a Tim C: posting stok awal besar lewat antrean (DesainF05a C.6.3). Di atas BatasPostingLangsung baris mutasi
 * (seri dihitung per nomor), dokumen Draf → Memproses lalu PostingStokAwalTugas (membawa IdTenant) memposting;
 * pelanggaran aturan bisnis mengembalikan dokumen ke Draf dengan PesanGalat.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array{0: array<string, mixed>, 1: array<string, Produk>, 2: StokAwal}
 */
function SiapkanDrafStokAwalBesar(): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    config(['persediaan.StokAwal.BatasPostingLangsung' => 2]);
    $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '120', '37500'),
        BantuanStokAwal::Baris($p['BahanBaku'], '50', '14750'),
        BantuanStokAwal::Baris($p['Produksi'], '30', '9500'),
    ]);

    return [$t, $p, $draf];
}

describe('F-05a posting stok awal lewat antrean', function (): void {
    it('di atas batas: Draf → Memproses, satu tugas bertenant dikirim; kirim ulang tidak menggandakan; posting langsung ditolak SedangDiproses; tugas memposting', function (): void {
        Queue::fake();
        [$t, , $draf] = SiapkanDrafStokAwalBesar();

        $status = app(AjukanPostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);
        $ulang = app(AjukanPostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);

        expect($status)->toBe(StatusStokAwal::Memproses)
            ->and($ulang)->toBe(StatusStokAwal::Memproses)
            ->and($draf->fresh()?->Status)->toBe(StatusStokAwal::Memproses)
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.posting-diajukan')->count())->toBe(1)
            ->and(MutasiStok::query()->count())->toBe(0);
        Queue::assertPushed(PostingStokAwalTugas::class, 1);
        Queue::assertPushed(PostingStokAwalTugas::class, fn (PostingStokAwalTugas $tugas): bool => $tugas->idTenant === $t['Tenant']->Id && $tugas->idStokAwal === $draf->Id && $tugas->idPengguna === $t['Pemilik']->Id);

        try {
            app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);
            $kode = null;
        } catch (PelanggaranAturanBisnis $galat) {
            $kode = $galat->kode;
        }
        expect($kode)->toBe('SedangDiproses');

        app()->call([new PostingStokAwalTugas($t['Tenant']->Id, $t['Pemilik']->Id, $draf->Id), 'handle']);

        $dokumen = $draf->fresh();
        expect($dokumen?->Status)->toBe(StatusStokAwal::Diposting)
            ->and($dokumen?->Nomor)->not->toBeNull()
            ->and(MutasiStok::query()->count())->toBe(3)
            ->and(Jurnal::query()->count())->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('baris seri dihitung per nomor untuk batas posting langsung', function (): void {
        Queue::fake();
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        config(['persediaan.StokAwal.BatasPostingLangsung' => 2]);
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Seri'], '3', '650000', nomorSeri: ['RC-01', 'RC-02', 'RC-03'])]);

        expect(app(AjukanPostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id))->toBe(StatusStokAwal::Memproses);
        Queue::assertPushed(PostingStokAwalTugas::class, 1);
    });

    it('di bawah batas diposting langsung tanpa tugas', function (): void {
        Queue::fake();
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);

        expect(app(AjukanPostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id))->toBe(StatusStokAwal::Diposting);
        Queue::assertNothingPushed();
    });

    it('antrean sinkron: tugas berjalan setelah commit sehingga dokumen langsung Diposting', function (): void {
        [$t, , $draf] = SiapkanDrafStokAwalBesar();

        expect(app(AjukanPostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id))->toBe(StatusStokAwal::Memproses)
            ->and($draf->fresh()?->Status)->toBe(StatusStokAwal::Diposting)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('pelanggaran aturan bisnis di tugas: Memproses → Draf dengan PesanGalat dan audit posting-gagal, tanpa efek stok', function (): void {
        Queue::fake();
        [$t, $p, $draf] = SiapkanDrafStokAwalBesar();
        app(AjukanPostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);
        $p['BahanBaku']->update(['DiarsipkanPada' => now(), 'Aktif' => false]);

        app()->call([new PostingStokAwalTugas($t['Tenant']->Id, $t['Pemilik']->Id, $draf->Id), 'handle']);

        $dokumen = $draf->fresh();
        expect($dokumen?->Status)->toBe(StatusStokAwal::Draf)
            ->and($dokumen?->PesanGalat)->toContain($p['BahanBaku']->Nama)
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.posting-gagal')->count())->toBe(1)
            ->and(MutasiStok::query()->count())->toBe(0)
            ->and(Jurnal::query()->count())->toBe(0);
    });

    it('masalah sistem terakhir (failed): dokumen kembali ke Draf dengan pesan umum', function (): void {
        Queue::fake();
        [$t, , $draf] = SiapkanDrafStokAwalBesar();
        app(AjukanPostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);

        (new PostingStokAwalTugas($t['Tenant']->Id, $t['Pemilik']->Id, $draf->Id))->failed(new RuntimeException('Deadlock berulang'));

        expect($draf->fresh()?->Status)->toBe(StatusStokAwal::Draf)
            ->and($draf->fresh()?->PesanGalat)->toContain('coba catat lagi');
    });
});
