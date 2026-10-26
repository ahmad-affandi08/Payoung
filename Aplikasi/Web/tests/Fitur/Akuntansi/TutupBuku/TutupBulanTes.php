<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\KunciPeriode;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    // 15 Oktober 2026 pukul 10.00 WIB: September 2026 sudah lewat, Oktober masih berjalan.
    Carbon::setTestNow(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

describe('F-15 tutup bulan: kunci & buka kunci periode', function (): void {
    it('daftar 24 periode. Kunci September → jurnal September ditolak, Oktober tetap bisa. Audit tercatat', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->get('/kelola/akuntansi/tutup-buku')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Akuntansi/TutupBuku')
            ->has('Periode', 24)
            ->where('Periode.0.Periode', '2026-10')
            ->where('Periode.0.Berjalan', true)
            ->where('Periode.1.Periode', '2026-09')
            ->where('Periode.1.Label', 'September 2026')
            ->where('Periode.1.Terkunci', false)
            ->where('Izin.Kelola', true));

        $this->post('/kelola/akuntansi/tutup-buku/2026-09/kunci')->assertRedirect('/kelola/akuntansi/tutup-buku');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pelaku = auth()->user();
        expect(KunciPeriode::query()->where('Periode', '2026-09')->value('DikunciOleh'))->toBe($pelaku?->getAuthIdentifier())
            ->and(LogAudit::query()->where('Peristiwa', 'akuntansi.periode.kunci')->count())->toBe(1);
        expect(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(tanggal: '2026-09-30')))
            ->toThrow(PelanggaranAturanBisnis::class, 'Periode September 2026 sudah dikunci.');
        expect(BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(tanggal: '2026-10-01'))->idJurnal)->toBeInt();

        $this->get('/kelola/akuntansi/tutup-buku')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Periode.1.Terkunci', true)
            ->where('Periode.1.DikunciOleh', $pelaku?->Nama));
    });

    it('bulan berjalan, periode sudah terkunci, dan shift belum ditutup tidak bisa dikunci', function (): void {
        $k = BantuanKasir::Siapkan($this);
        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanKasir::ItemBukaShift($k['Kasir'], timpa: ['DibukaPada' => '2026-09-30T02:00:00Z'])]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->post('/kelola/akuntansi/tutup-buku/2026-10/kunci')->assertSessionHasErrors(['Periode' => 'Periode Oktober 2026 belum berakhir. Periode hanya bisa dikunci setelah bulannya lewat.']);
        $this->post('/kelola/akuntansi/tutup-buku/2026-09/kunci')->assertSessionHasErrors('Periode');
        $this->get('/kelola/akuntansi/tutup-buku')->assertInertia(fn (AssertableInertia $h) => $h->where('Periode.1.ShiftBelumDitutup', 1));
        // Periode sebelum shift dibuka tetap bisa dikunci.
        $this->post('/kelola/akuntansi/tutup-buku/2026-08/kunci')->assertRedirect();
        $this->post('/kelola/akuntansi/tutup-buku/2026-08/kunci')->assertSessionHasErrors(['Periode' => 'Periode Agustus 2026 sudah dikunci.']);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(KunciPeriode::query()->pluck('Periode')->all())->toBe(['2026-08']);
    });

    it('buka kunci wajib alasan, tercatat audit dengan siapa & kapan dikunci. Setelah dibuka jurnal bisa lagi', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::KunciPeriode('2026-08', $t['Pemilik']->Id);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->post('/kelola/akuntansi/tutup-buku/2026-08/buka-kunci', ['Alasan' => 'salah'])->assertSessionHasErrors('Alasan');
        $this->post('/kelola/akuntansi/tutup-buku/2026-07/buka-kunci', ['Alasan' => 'Faktur pemasok Juli baru datang'])
            ->assertSessionHasErrors(['Periode' => 'Periode Juli 2026 tidak sedang dikunci.']);
        $this->post('/kelola/akuntansi/tutup-buku/2026-08/buka-kunci', ['Alasan' => 'Faktur pemasok Agustus baru datang'])
            ->assertRedirect('/kelola/akuntansi/tutup-buku');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $log = LogAudit::query()->where('Peristiwa', 'akuntansi.periode.buka')->sole();
        expect(KunciPeriode::query()->count())->toBe(0)
            ->and($log->NilaiBaru)->toBe(['Alasan' => 'Faktur pemasok Agustus baru datang'])
            ->and($log->NilaiLama['DikunciOleh'] ?? null)->toBe($t['Pemilik']->Id)
            ->and(BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(tanggal: '2026-08-31'))->idJurnal)->toBeInt()
            ->and(Jurnal::query()->count())->toBe(1);
    });

    it('izin: Kasir tidak bisa melihat, tanpa akuntansi.kelola tidak bisa mengunci. Kunci tenant lain tidak berpengaruh', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Kopi Senja Solo');
        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/akuntansi/tutup-buku')->assertForbidden();
        $this->post('/kelola/akuntansi/tutup-buku/2026-09/kunci')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/akuntansi/tutup-buku/2026-09/kunci')->assertRedirect();

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(KunciPeriode::query()->count())->toBe(0)
            ->and(BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(tanggal: '2026-09-30'))->idJurnal)->toBeInt();
    });
});
