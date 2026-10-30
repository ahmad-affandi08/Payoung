<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Layanan\PelunasTagihanLangganan;
use App\Domain\Tenant\Layanan\PengaturanLamaLangganan;
use App\Domain\Tenant\Layanan\PenghitungHargaPeriode;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PengaturanPlatform;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Tenant\BantuanTagihan;

/**
 * D-249: lama langganan bebas (jumlah bulan apa pun dalam rentang yang diatur Super Admin) dengan tangga diskon yang
 * seluruhnya berasal dari pengaturan (konsol atau `config/tagihan.php`), bukan angka di kode.
 */
function BuatTagihanLamaUji(object $tes, object $pengguna, Tenant $tenant, array $isian): TagihanLangganan
{
    BantuanTagihan::Masuk($tes, $pengguna, $tenant)
        ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', ...$isian])
        ->assertSessionHasNoErrors();

    return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
}

function SimpanAturanLamaUji(array $nilai): void
{
    PengaturanPlatform::query()->create(['Kunci' => PengaturanLamaLangganan::KUNCI, 'Nilai' => json_encode($nilai, JSON_THROW_ON_ERROR)]);
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
});

describe('PenghitungHargaPeriode (D-249): harga menurut lama', function (): void {
    // PRO: Rp 249.000/bulan, Rp 2.390.400/tahun. Tangga bawaan: 3 bulan 7%, 6 bulan 12%, 12 bulan 20%.
    $tangga = [['DariBulan' => 3, 'Persen' => '7'], ['DariBulan' => 6, 'Persen' => '12'], ['DariBulan' => 12, 'Persen' => '20']];

    it('menghitung lama bebas dengan anak tangga tertinggi yang terlewati', function (int $bulan, string $subtotal, string $persen) use ($tangga): void {
        $hitung = (new PenghitungHargaPeriode(new PengaturanLamaLangganan))->Hitung(Uang::Dari('249000'), Uang::Dari('2390400'), $bulan, $tangga);

        expect($hitung['Subtotal']->KeString())->toBe($subtotal)
            ->and($hitung['DiskonPersen'])->toBe($persen);
    })->with([
        '1 bulan' => [1, '249000.00', '0'],
        '2 bulan' => [2, '498000.00', '0'],
        '3 bulan' => [3, '694710.00', '7'],
        '5 bulan' => [5, '1157850.00', '7'],
        '6 bulan' => [6, '1314720.00', '12'],
        '13 bulan' => [13, '2589600.00', '20'],
    ]);

    it('lama kelipatan 12 bulan memakai harga tahunan katalog, dan lama lebih pendek tidak pernah lebih mahal dari satu tahun', function () use ($tangga): void {
        $penghitung = new PenghitungHargaPeriode(new PengaturanLamaLangganan);

        expect($penghitung->Hitung(Uang::Dari('249000'), Uang::Dari('2390400'), 12, $tangga)['Subtotal']->KeString())->toBe('2390400.00')
            ->and($penghitung->Hitung(Uang::Dari('249000'), Uang::Dari('2390400'), 24, $tangga)['Subtotal']->KeString())->toBe('4780800.00')
            // 11 bulan × 12% = 2.410.320 > harga setahun 2.390.400, jadi dibatasi harga setahun.
            ->and($penghitung->Hitung(Uang::Dari('249000'), Uang::Dari('2390400'), 11, $tangga)['Subtotal']->KeString())->toBe('2390400.00');
    });

    it('tanpa harga tahunan katalog, tangga diskon tetap berlaku di semua lama', function () use ($tangga): void {
        $hitung = (new PenghitungHargaPeriode(new PengaturanLamaLangganan))->Hitung(Uang::Dari('249000'), null, 12, $tangga);

        expect($hitung['Subtotal']->KeString())->toBe('2390400.00');
    });

    it('tanpa tangga diskon harga murni bulanan × lama', function (): void {
        $hitung = (new PenghitungHargaPeriode(new PengaturanLamaLangganan))->Hitung(Uang::Dari('249000'), null, 7, []);

        expect($hitung['Subtotal']->KeString())->toBe('1743000.00')->and($hitung['Dasar'])->toBe('Bulanan');
    });
});

describe('PengaturanLamaLangganan (D-249): tidak ada angka di kode', function (): void {
    it('memakai nilai awal config selama belum diubah dari konsol, lalu nilai konsol menang', function (): void {
        $pengaturan = new PengaturanLamaLangganan;
        $awal = $pengaturan->Ambil();

        expect($pengaturan->DariKonsol())->toBeFalse()
            ->and($awal['MinimalBulan'])->toBe((int) config('tagihan.LamaLangganan.MinimalBulan'))
            ->and($awal['MaksimalBulan'])->toBe((int) config('tagihan.LamaLangganan.MaksimalBulan'))
            ->and($awal['TanggaDiskon'])->toHaveCount(count(config('tagihan.LamaLangganan.TanggaDiskon')));

        SimpanAturanLamaUji(['MinimalBulan' => 2, 'MaksimalBulan' => 24, 'TanggaDiskon' => [['DariBulan' => 4, 'Persen' => '10']]]);
        $konsol = $pengaturan->Ambil();

        expect($pengaturan->DariKonsol())->toBeTrue()
            ->and($konsol['MinimalBulan'])->toBe(2)
            ->and($konsol['MaksimalBulan'])->toBe(24)
            ->and($konsol['TanggaDiskon'])->toBe([['DariBulan' => 4, 'Persen' => '10']]);
    });

    it('maksimal dari konsol tidak melampaui batas pengaman server, dan nilai rusak jatuh ke nilai awal', function (): void {
        $batas = (int) config('tagihan.LamaLangganan.BatasTertinggiBulan');
        SimpanAturanLamaUji(['MinimalBulan' => 1, 'MaksimalBulan' => $batas + 100, 'TanggaDiskon' => []]);

        expect((new PengaturanLamaLangganan)->Ambil()['MaksimalBulan'])->toBe($batas);

        PengaturanPlatform::query()->where('Kunci', PengaturanLamaLangganan::KUNCI)->update(['Nilai' => '{bukan json']);

        expect((new PengaturanLamaLangganan)->Ambil()['MaksimalBulan'])->toBe((int) config('tagihan.LamaLangganan.MaksimalBulan'));
    });

    it('lama di luar rentang ditolak, di dalamnya lolos, dan tagihan manual punya batas sendiri', function (): void {
        $pengaturan = new PengaturanLamaLangganan;
        $maksimal = $pengaturan->Ambil()['MaksimalBulan'];

        $pengaturan->PastikanSah(1);
        $pengaturan->PastikanSah($maksimal);
        expect(fn () => $pengaturan->PastikanSah(0))->toThrow(PelanggaranAturanBisnis::class)
            ->and(fn () => $pengaturan->PastikanSah($maksimal + 1))->toThrow(PelanggaranAturanBisnis::class);

        $pengaturan->PastikanSahManual($pengaturan->Ambil()['MaksimalBulanManual']);
        expect(fn () => $pengaturan->PastikanSahManual($pengaturan->Ambil()['MaksimalBulanManual'] + 1))->toThrow(PelanggaranAturanBisnis::class);
    });

    it('tangga dirapikan: urut menurut lama, duplikat dan persen yang turun dibuang', function (): void {
        $tangga = PengaturanLamaLangganan::RapikanTangga([
            ['DariBulan' => 6, 'Persen' => '12'], ['DariBulan' => 3, 'Persen' => '7'], ['DariBulan' => 9, 'Persen' => '5'], ['DariBulan' => 0, 'Persen' => '1'], ['DariBulan' => 12, 'Persen' => '101'],
        ]);

        expect($tangga)->toBe([['DariBulan' => 3, 'Persen' => '7'], ['DariBulan' => 6, 'Persen' => '12']]);
    });
});

describe('Tagihan dengan lama bebas (D-249)', function (): void {
    it('2 bulan: dua kali harga bulanan tanpa diskon, tercatat 2 bulan dan siklus Kustom', function (): void {
        $tagihan = BuatTagihanLamaUji($this, $this->pemilik, $this->tenant, ['JumlahBulan' => 2]);

        expect($tagihan->JumlahBulan)->toBe(2)
            ->and($tagihan->Siklus)->toBe(SiklusTagihan::Kustom)
            ->and($tagihan->Subtotal)->toBe('498000.00')
            // DPP = ⌊498.000 × 11/12⌋ = 456.500; PPN = ⌊456.500 × 12%⌋ = 54.780.
            ->and($tagihan->Total)->toBe('552780.00');
    });

    it('3 bulan memakai diskon tangga 7% (harga akhir sudah dikurangi, bukan kupon)', function (): void {
        $tagihan = BuatTagihanLamaUji($this, $this->pemilik, $this->tenant, ['JumlahBulan' => 3]);

        expect($tagihan->Subtotal)->toBe('694710.00')->and($tagihan->Diskon)->toBe('0.00')->and($tagihan->JumlahBulan)->toBe(3);
    });

    it('klien lama yang masih mengirim Siklus tetap diterima: Bulanan = 1 bulan, Tahunan = 12 bulan harga katalog', function (): void {
        $bulanan = BuatTagihanLamaUji($this, $this->pemilik, $this->tenant, ['Siklus' => 'Bulanan']);
        expect([$bulanan->JumlahBulan, $bulanan->Siklus, $bulanan->Subtotal])->toBe([1, SiklusTagihan::Bulanan, '249000.00']);

        $this->post("/kelola/langganan/tagihan/{$bulanan->Uuid}/batalkan", ['Alasan' => 'Ganti lama'])->assertSessionHasNoErrors();
        $tahunan = BuatTagihanLamaUji($this, $this->pemilik, $this->tenant, ['Siklus' => 'Tahunan']);
        expect([$tahunan->JumlahBulan, $tahunan->Siklus, $tahunan->Subtotal])->toBe([12, SiklusTagihan::Tahunan, '2390400.00']);
    });

    it('menolak lama di luar rentang dan permintaan tanpa lama atau siklus', function (array $isian, string $bidang): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', ...$isian])
            ->assertSessionHasErrors($bidang);
        expect(TagihanLangganan::query()->withoutGlobalScopes()->count())->toBe(0);
    })->with([
        'nol' => [['JumlahBulan' => 0], 'JumlahBulan'],
        'di atas maksimal' => [['JumlahBulan' => 13], 'JumlahBulan'],
        'bukan angka' => [['JumlahBulan' => 'dua'], 'JumlahBulan'],
        'kosong' => [[], 'JumlahBulan'],
    ]);

    it('rentang mengikuti pengaturan konsol: maksimal dinaikkan membuat 18 bulan sah dengan diskon tangga konsol', function (): void {
        SimpanAturanLamaUji(['MinimalBulan' => 2, 'MaksimalBulan' => 24, 'TanggaDiskon' => [['DariBulan' => 13, 'Persen' => '25']]]);

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'JumlahBulan' => 1])
            ->assertSessionHasErrors('JumlahBulan');

        $tagihan = BuatTagihanLamaUji($this, $this->pemilik, $this->tenant, ['JumlahBulan' => 18]);

        // 18 × 249.000 = 4.482.000, diskon 25% = 1.120.500, dibatasi harga setahun×2 (4.780.800) tidak tercapai.
        expect($tagihan->JumlahBulan)->toBe(18)->and($tagihan->Subtotal)->toBe('3361500.00');
    });

    it('halaman Langganan membawa aturan lama dan harga server per lama untuk setiap paket', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->get('/kelola/langganan')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman
                ->component('Kelola/Langganan/Indeks')
                ->where('AturanLama.MinimalBulan', 1)
                ->where('AturanLama.MaksimalBulan', 12)
                ->has('AturanLama.TanggaDiskon', 3)
                ->has('PilihanPaket.1.HargaPerLama', 12)
                ->where('PilihanPaket.1.HargaPerLama.2.JumlahBulan', 3)
                ->where('PilihanPaket.1.HargaPerLama.2.Subtotal', '694710.00')
                ->where('PilihanPaket.1.HargaPerLama.2.DiskonPersen', '7')
                ->where('PilihanPaket.1.HargaPerLama.11.Subtotal', '2390400.00'));
    });
});

describe('Pelunasan lama bebas (D-249): periode dan langganan mengikuti jumlah bulan', function (): void {
    it('tagihan 2 bulan lunas: periode dua bulan, langganan mencatat 2 bulan dan siklus Kustom', function (): void {
        $tagihan = BuatTagihanLamaUji($this, $this->pemilik, $this->tenant, ['JumlahBulan' => 2]);
        $pembayaran = BantuanTagihan::UnggahBuktiLangsung($this->tenant, $this->pemilik, $tagihan);
        $langganan = Langganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->sole();

        BantuanTagihan::AturTenant($this->tenant);

        try {
            DB::transaction(fn () => app(PelunasTagihanLangganan::class)->Lunasi($langganan, $tagihan->fresh(), $pembayaran, Uang::Dari($tagihan->Total), CarbonImmutable::now()));
        } finally {
            app(KonteksTenant::class)->Kosongkan();
        }

        $langganan->refresh();
        $mulai = CarbonImmutable::instance($langganan->PeriodeMulai);

        expect($langganan->SiklusTagihan)->toBe(SiklusTagihan::Kustom)
            ->and($langganan->JumlahBulanSiklus)->toBe(2)
            ->and($langganan->AmbilJumlahBulanSiklus())->toBe(2)
            ->and(CarbonImmutable::instance($langganan->PeriodeSelesai)->equalTo($mulai->addMonthsNoOverflow(2)))->toBeTrue();
    });

    it('langganan lama Bulanan dan Tahunan tanpa kolom jumlah bulan tetap terbaca 1 dan 12 bulan', function (): void {
        $langganan = Langganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->sole();

        $langganan->forceFill(['SiklusTagihan' => SiklusTagihan::Bulanan, 'JumlahBulanSiklus' => null]);
        expect($langganan->AmbilJumlahBulanSiklus())->toBe(1);

        $langganan->forceFill(['SiklusTagihan' => SiklusTagihan::Tahunan, 'JumlahBulanSiklus' => null]);
        expect($langganan->AmbilJumlahBulanSiklus())->toBe(12);
    });
});
