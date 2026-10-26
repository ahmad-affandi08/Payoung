<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\SaringLaporanKeuangan;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\LabaRugi;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    // 10 Februari 2026 (WIB): tahun buku 2025 sudah berakhir. Prasyarat (dokumen legal berlaku) dibuat sesudahnya.
    Carbon::setTestNow(Carbon::parse('2026-02-10 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(fn () => Carbon::setTestNow());

/** Jurnal uji dua baris (debit peran A, kredit peran B) di satu outlet. */
function JurnalUjiTutupTahun(int $id, string $tanggal, PeranAkun $debit, PeranAkun $kredit, string $nilai, ?int $idOutlet): void
{
    BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: $id, tanggal: $tanggal, baris: [
        DataBarisJurnal::Debit($debit, Uang::Dari($nilai), $idOutlet),
        DataBarisJurnal::Kredit($kredit, Uang::Dari($nilai), $idOutlet),
    ]));
}

/** Σ (debit − kredit) akun bertipe laba rugi sampai tanggal, termasuk jurnal penutup. */
function SaldoLabaRugiTutupTahun(string $sampai): string
{
    $idAkun = Akun::query()->whereIn('Jenis', [TipeAkun::Pendapatan->value, TipeAkun::Hpp->value, TipeAkun::Beban->value])->pluck('Id');

    return (string) JurnalDetail::query()->whereIn('IdAkun', $idAkun)->where('Tanggal', '<=', $sampai)->selectRaw('CAST(COALESCE(SUM(`Debit` - `Kredit`), 0) AS DECIMAL(20,2)) AS s')->value('s');
}

describe('F-15 tutup tahun (J-15.1)', function (): void {
    it('syarat 12 bulan terkunci & tahun berakhir. Jurnal penutup per outlet ke Laba Ditahan. Laba rugi tetap. Tidak bisa dua kali. Bulan tidak bisa dibuka', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $utama = $t['Outlet']->Id;
        $solo = BantuanJurnal::BuatOutlet()->Id;
        JurnalUjiTutupTahun(1, '2025-03-10', PeranAkun::KasOutlet, PeranAkun::Penjualan, '1000000.00', $utama);
        JurnalUjiTutupTahun(2, '2025-03-10', PeranAkun::Hpp, PeranAkun::PersediaanBarangDagang, '600000.00', $utama);
        JurnalUjiTutupTahun(3, '2025-11-05', PeranAkun::BebanSelisihKas, PeranAkun::KasOutlet, '50000.00', $solo);
        JurnalUjiTutupTahun(4, '2026-01-15', PeranAkun::KasOutlet, PeranAkun::Penjualan, '200000.00', $utama);
        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->post('/kelola/akuntansi/tutup-buku/tahun/2025/tutup')->assertSessionHasErrors('Tahun');
        foreach (range(1, 11) as $bulan) {
            BantuanPersediaan::KunciPeriode(sprintf('2025-%02d', $bulan));
        }
        $this->post('/kelola/akuntansi/tutup-buku/tahun/2025/tutup')
            ->assertSessionHasErrors(['Tahun' => 'Kunci dulu semua bulan tahun 2025. Belum dikunci: Desember 2025.']);
        BantuanPersediaan::KunciPeriode('2025-12');
        $this->post('/kelola/akuntansi/tutup-buku/tahun/2026/tutup')
            ->assertSessionHasErrors(['Tahun' => 'Tahun 2026 belum berakhir. Tahun buku hanya bisa ditutup setelah 31 Desember.']);

        $this->post('/kelola/akuntansi/tutup-buku/tahun/2025/tutup')->assertRedirect('/kelola/akuntansi/tutup-buku');

        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $penutup = Jurnal::query()->where('JenisSumber', JenisSumberJurnal::TutupTahun->value)->sole();
        $idLabaDitahan = BantuanJurnal::IdAkunPeran(PeranAkun::LabaDitahan);
        $labaDitahan = JurnalDetail::query()->where('IdJurnal', $penutup->Id)->where('IdAkun', $idLabaDitahan)->get()->keyBy('IdOutlet');
        expect($penutup->Tanggal->toDateString())->toBe('2025-12-31')
            ->and($penutup->IdSumber)->toBe(2025)
            ->and($penutup->TotalDebit)->toBe($penutup->TotalKredit)
            ->and($labaDitahan[$utama]->Kredit)->toBe('400000.00')
            ->and($labaDitahan[$solo]->Debit)->toBe('50000.00')
            // Saldo laba rugi 2025 nol setelah penutupan; 2026 tidak tersentuh.
            ->and(SaldoLabaRugiTutupTahun('2025-12-31'))->toBe('0.00')
            ->and(SaldoLabaRugiTutupTahun('2026-12-31'))->toBe('-200000.00')
            // Invarian: Σ debit = Σ kredit seluruh jurnal.
            ->and((string) JurnalDetail::query()->selectRaw('CAST(SUM(`Debit`) - SUM(`Kredit`) AS DECIMAL(20,2)) AS s')->value('s'))->toBe('0.00')
            ->and(LogAudit::query()->where('Peristiwa', 'akuntansi.tahun.tutup')->sole()->NilaiBaru['LabaBersih'] ?? null)->toBe('350000.00');

        // Laba rugi 2025 tidak ikut jurnal penutup.
        $lr = app(LabaRugi::class)->Ambil(new SaringLaporanKeuangan('2025-01-01', '2025-12-31', null, null));
        expect($lr['Ringkasan']['LabaBersih']['Nilai'])->toBe('350000.00');

        $this->post('/kelola/akuntansi/tutup-buku/tahun/2025/tutup')->assertSessionHasErrors(['Tahun' => 'Tahun buku 2025 sudah ditutup.']);
        $this->post('/kelola/akuntansi/tutup-buku/2025-06/buka-kunci', ['Alasan' => 'Faktur pemasok Juni baru datang'])
            ->assertSessionHasErrors(['Periode' => 'Tahun buku 2025 sudah ditutup, jadi kunci Juni 2025 tidak bisa dibuka. Catat koreksinya di tahun berjalan.']);

        $this->get('/kelola/akuntansi/tutup-buku')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Tahun.0.Tahun', 2025)
            ->where('Tahun.0.BulanTerkunci', 12)
            ->where('Tahun.0.Ditutup', true)
            ->where('Tahun.0.NomorJurnal', $penutup->Nomor)
            ->where('Periode.1.Periode', '2026-01')
            ->where('Periode.1.TahunDitutup', false)
            ->where('Periode.2.Periode', '2025-12')
            ->where('Periode.2.TahunDitutup', true));
    });

    it('tahun tanpa saldo laba rugi tidak perlu ditutup; Kasir tidak berizin. Tenant lain tidak terpengaruh', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Kopi Senja Solo');
        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        foreach (range(1, 12) as $bulan) {
            BantuanPersediaan::KunciPeriode(sprintf('2025-%02d', $bulan));
        }

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->post('/kelola/akuntansi/tutup-buku/tahun/2025/tutup')->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/akuntansi/tutup-buku/tahun/2025/tutup')
            ->assertSessionHasErrors(['Tahun' => 'Tidak ada saldo pendapatan, HPP, atau beban di tahun 2025. Tahun ini tidak perlu ditutup.']);

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        JurnalUjiTutupTahun(1, '2025-05-01', PeranAkun::KasOutlet, PeranAkun::Penjualan, '75000.00', $b['Outlet']->Id);
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Pemilik);
        // Kunci bulan milik tenant A tidak berlaku untuk tenant B.
        $this->post('/kelola/akuntansi/tutup-buku/tahun/2025/tutup')->assertSessionHasErrors('Tahun');
        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::TutupTahun->value)->count())->toBe(0);
    });
});
