<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tagihan\Aksi\ProsesTunggakanLangganan;
use App\Domain\Pengelola\Tagihan\Kueri\LaporanLanggananPlatform;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Kueri\SumberFiturTenant;
use App\Domain\Tenant\Layanan\EvaluatorFitur;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Layanan\PenghitungProrataAddon;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\PaketFitur;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\TagihanLanggananAddon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanTagihan;
use Tests\TestCase;

/*
 * D-49 (F-19): pembelian add-on mandiri. Beli = tagihan `Addon` prorata sampai akhir periode; lunas = add-on aktif
 * (fitur dibuka) tanpa mengubah paket/periode; perpanjangan paket menagih & memperpanjang add-on; berhenti = aktif
 * sampai akhir periode lalu tidak ditagih; tagihan add-on yang tidak dibayar tidak menghalangi tagihan paket.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 08:20:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
    // PRO bulanan aktif: 1 Sep 10.00 → 1 Okt 10.00 WIB (30 hari). Sisa dari 23 Sep 08.20 = 9 hari (dibulatkan ke atas).
    DB::table('Langganan')->where('IdTenant', $this->tenant->Id)->update([
        'Status' => StatusLangganan::Aktif->value,
        'SiklusTagihan' => SiklusTagihan::Bulanan->value,
        'PeriodeMulai' => Carbon::parse('2026-09-01 10:00:00', 'Asia/Jakarta')->utc(),
        'PeriodeSelesai' => Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta')->utc(),
    ]);
    // D-86: Pro sudah memuat toko online; alur beli add-on diuji untuk fitur di luar paket, jadi dicabut di database uji.
    PaketFitur::query()->where('KunciFitur', 'kanal.toko-online')->delete();
    Addon::query()->where('Kode', 'TOKO_ONLINE')->update(['Status' => StatusPaket::Aktif->value, 'HargaBulanan' => '60000']);
    $this->keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
});

function BeliAddonUji(TestCase $tes, object $pemilik, object $tenant, string $kode = 'TOKO_ONLINE', array $tambahan = []): TagihanLangganan
{
    BantuanTagihan::Masuk($tes, $pemilik, $tenant)
        ->post(BantuanTagihan::Url('/kelola/langganan/addon/beli'), ['KodeAddon' => $kode, ...$tambahan])
        ->assertSessionHasNoErrors();

    return TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $tenant->Id)->latest('Id')->firstOrFail();
}

/** Tagihan dibayar lewat bukti transfer yang diterima Keuangan (jalur pelunasan yang sama dengan notifikasi gerbang). */
function BayarTagihanAddonUji(TestCase $tes, object $pemilik, object $tenant, PenggunaPengelola $keuangan, TagihanLangganan $tagihan): void
{
    $pembayaran = BantuanTagihan::UnggahBuktiLangsung($tenant, $pemilik, $tagihan);
    $tes->actingAs($keuangan, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi())
        ->post(BantuanPengelola::Url("/tagihan/pembayaran/{$pembayaran->Uuid}/terima"), ['JumlahDiterima' => (string) ((int) $tagihan->Total)])
        ->assertSessionHasNoErrors();
}

function FiturAddonAktifUji(object $tenant, string $kunci = 'kanal.toko-online'): bool
{
    return app(PemeriksaFiturTenant::class)->CekAktif($tenant->Id, $kunci);
}

describe('Penghitung prorata (D-49)', function (): void {
    it('D-98: prorata untuk jumlah penuh (selisih naik paket) memakai aturan hari yang sama', function (): void {
        $hitung = new PenghitungProrataAddon;
        $mulai = Carbon::parse('2026-09-01 10:00:00');
        $selesai = Carbon::parse('2026-10-01 10:00:00');

        $awal = $hitung->HitungDariJumlahPenuh(Uang::Dari('200000'), $mulai, $mulai, $selesai);
        expect($awal['Prorata'])->toBeFalse()->and($awal['Subtotal']->KeString())->toBe('200000.00')->and($awal['HariDitagih'])->toBe(30);

        $tengah = $hitung->HitungDariJumlahPenuh(Uang::Dari('200000'), Carbon::parse('2026-09-21 10:00:00'), $mulai, $selesai);
        expect($tengah['HariDitagih'])->toBe(10)->and($tengah['HariPeriode'])->toBe(30)->and($tengah['Subtotal']->KeString())->toBe('66667.00');

        $akhir = $hitung->HitungDariJumlahPenuh(Uang::Dari('200000'), Carbon::parse('2026-10-01 09:59:00'), $mulai, $selesai);
        expect($akhir['HariDitagih'])->toBe(1)->and($akhir['Subtotal']->KeString())->toBe('6667.00');
    });

    it('harga penuh di awal periode; prorata hari tersisa dibulatkan ke atas; minimal sehari; tahunan = 12 bulan', function (): void {
        $hitung = new PenghitungProrataAddon;
        $mulai = Carbon::parse('2026-09-01 10:00:00');
        $selesai = Carbon::parse('2026-10-01 10:00:00');

        $awal = $hitung->Hitung(Uang::Dari('60000'), 1, 1, $mulai, $mulai, $selesai);
        expect($awal['Prorata'])->toBeFalse()->and($awal['Subtotal']->KeString())->toBe('60000.00')->and($awal['HariDitagih'])->toBe(30);

        $tengah = $hitung->Hitung(Uang::Dari('60000'), 1, 1, Carbon::parse('2026-09-23 08:20:00'), $mulai, $selesai);
        expect($tengah['Prorata'])->toBeTrue()->and($tengah['HariDitagih'])->toBe(9)->and($tengah['Subtotal']->KeString())->toBe('18000.00');

        $akhir = $hitung->Hitung(Uang::Dari('60000'), 1, 1, Carbon::parse('2026-10-01 09:59:00'), $mulai, $selesai);
        expect($akhir['HariDitagih'])->toBe(1)->and($akhir['Subtotal']->KeString())->toBe('2000.00');

        $dua = $hitung->Hitung(Uang::Dari('99000'), 12, 2, $mulai, $mulai, Carbon::parse('2027-09-01 10:00:00'));
        expect($dua['Subtotal']->KeString())->toBe('2376000.00');
    });
});

describe('Beli add-on → bayar → aktif', function (): void {
    it('tagihan Addon prorata terbit sekali (klik ganda), fitur baru terbuka setelah lunas, paket & periode tidak berubah', function (): void {
        expect(FiturAddonAktifUji($this->tenant))->toBeFalse();

        $tagihan = BeliAddonUji($this, $this->pemilik, $this->tenant);
        $rincian = TagihanLanggananAddon::query()->where('IdTagihanLangganan', $tagihan->Id)->sole();

        expect($tagihan->Jenis)->toBe(JenisTagihanLangganan::Addon)
            ->and($tagihan->Status)->toBe(StatusTagihanLangganan::Terbit)
            ->and($tagihan->Subtotal)->toBe('18000.00')
            ->and($tagihan->Diskon)->toBe('0.00')
            ->and(Uang::Dari($tagihan->Total)->KeString())->toBe(Uang::Dari($tagihan->Subtotal)->Kurangi(Uang::Dari($tagihan->Diskon))->Tambah(Uang::Dari($tagihan->JumlahPpn))->KeString())
            ->and($tagihan->JatuhTempoPada->lessThanOrEqualTo(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta')))->toBeTrue()
            ->and($rincian->KodeAddon)->toBe('TOKO_ONLINE')
            ->and($rincian->Prorata)->toBeTrue()
            ->and([$rincian->HariDitagih, $rincian->HariPeriode])->toBe([9, 30]);
        expect(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'langganan.tagihan-addon')->count())->toBe(1);

        // Klik ganda / kirim ulang: tagihan add-on yang sama dikembalikan, bukan tagihan kedua.
        $ulang = BeliAddonUji($this, $this->pemilik, $this->tenant);
        expect($ulang->Id)->toBe($tagihan->Id)
            ->and(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->count())->toBe(1);
        expect(FiturAddonAktifUji($this->tenant))->toBeFalse();

        BayarTagihanAddonUji($this, $this->pemilik, $this->tenant, $this->keuangan, $tagihan);

        $tagihan->refresh();
        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        $milik = LanggananAddon::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect($tagihan->Status)->toBe(StatusTagihanLangganan::Lunas)
            ->and(FiturAddonAktifUji($this->tenant))->toBeTrue()
            ->and($milik->SelesaiPada->equalTo($langganan->PeriodeSelesai))->toBeTrue()
            ->and($milik->PerpanjangOtomatis)->toBeTrue()
            ->and($milik->BerhentiPada)->toBeNull()
            ->and($langganan->Status)->toBe(StatusLangganan::Aktif)
            ->and($langganan->Paket->Kode)->toBe('PRO')
            ->and($langganan->PeriodeSelesai->equalTo(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta')))->toBeTrue();

        // Sudah aktif & diperpanjang otomatis → beli lagi ditolak.
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/addon/beli'), ['KodeAddon' => 'TOKO_ONLINE'])
            ->assertSessionHasErrors('KodeAddon');

        // Setelah periode berakhir tanpa perpanjangan, fitur terkunci lagi.
        $this->travelTo(Carbon::parse('2026-10-01 11:00:00', 'Asia/Jakarta'));
        expect(FiturAddonAktifUji($this->tenant))->toBeFalse();
    });

    it('add-on penambah batas bisa dibeli lebih dari satu dan menaikkan batas efektif', function (): void {
        Addon::query()->where('Kode', 'PERANGKAT_TAMBAHAN')->update(['Status' => StatusPaket::Aktif->value]);
        $sebelum = app(EvaluatorFitur::class)->HitungBatasEfektif(app(SumberFiturTenant::class)->Ambil($this->tenant->Id));

        $tagihan = BeliAddonUji($this, $this->pemilik, $this->tenant, 'PERANGKAT_TAMBAHAN', ['Jumlah' => 3]);
        expect(TanggihanJumlahUji($tagihan))->toBe(3);
        BayarTagihanAddonUji($this, $this->pemilik, $this->tenant, $this->keuangan, $tagihan);

        $sesudah = app(EvaluatorFitur::class)->HitungBatasEfektif(app(SumberFiturTenant::class)->Ambil($this->tenant->Id));
        expect($sesudah['BatasPerangkatPerOutlet'])->toBe($sebelum['BatasPerangkatPerOutlet'] + 3);
    });

    it('ditolak: langganan belum berbayar aktif (trial), paket Gratis, add-on diarsipkan; tidak ada tagihan dibuat', function (): void {
        Langganan::query()->where('IdTenant', $this->tenant->Id)->update(['Status' => StatusLangganan::Trial->value]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/addon/beli'), ['KodeAddon' => 'TOKO_ONLINE'])->assertSessionHasErrors('Umum');

        Langganan::query()->where('IdTenant', $this->tenant->Id)->update(['Status' => StatusLangganan::Tertunggak->value]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/addon/beli'), ['KodeAddon' => 'TOKO_ONLINE'])->assertSessionHasErrors('Umum');

        Langganan::query()->where('IdTenant', $this->tenant->Id)->update(['Status' => StatusLangganan::Aktif->value]);
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post(BantuanTagihan::Url('/kelola/langganan/addon/beli'), ['KodeAddon' => 'WA_1000'])->assertSessionHasErrors('KodeAddon');

        expect(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->count())->toBe(0);
    });
});

describe('Perpanjangan, berhenti, dan tagihan terbuka', function (): void {
    it('perpanjangan otomatis H-7 menagih add-on (tanpa kupon) dan memperpanjangnya setelah lunas', function (): void {
        $addon = BeliAddonUji($this, $this->pemilik, $this->tenant);
        BayarTagihanAddonUji($this, $this->pemilik, $this->tenant, $this->keuangan, $addon);

        $this->travelTo(Carbon::parse('2026-09-24 08:20', 'Asia/Jakarta'));
        $this->artisan('tagihan:terbitkan-perpanjangan')->expectsOutputToContain('1 tagihan perpanjangan terbit')->assertSuccessful();

        $perpanjangan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->where('Jenis', JenisTagihanLangganan::Perpanjangan->value)->sole();
        $baris = TagihanLanggananAddon::query()->where('IdTagihanLangganan', $perpanjangan->Id)->sole();
        expect($perpanjangan->Subtotal)->toBe('309000.00')
            ->and($baris->Subtotal)->toBe('60000.00')
            ->and($baris->Prorata)->toBeFalse()
            ->and(Uang::Dari($perpanjangan->Total)->KeString())->toBe(Uang::Dari($perpanjangan->Subtotal)->Tambah(Uang::Dari($perpanjangan->JumlahPpn))->KeString());

        BayarTagihanAddonUji($this, $this->pemilik, $this->tenant, $this->keuangan, $perpanjangan);

        $langganan = Langganan::query()->where('IdTenant', $this->tenant->Id)->sole();
        $milik = LanggananAddon::query()->where('IdTenant', $this->tenant->Id)->sole();
        expect($langganan->PeriodeSelesai->equalTo(Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta')))->toBeTrue()
            ->and($milik->SelesaiPada->equalTo($langganan->PeriodeSelesai))->toBeTrue();
        $this->travelTo(Carbon::parse('2026-10-15 12:00', 'Asia/Jakarta'));
        expect(FiturAddonAktifUji($this->tenant))->toBeTrue();
    });

    it('berhenti berlangganan: aktif sampai akhir periode, tidak ditagih lagi di perpanjangan, bisa dilanjutkan selama masih aktif', function (): void {
        $addon = BeliAddonUji($this, $this->pemilik, $this->tenant);
        BayarTagihanAddonUji($this, $this->pemilik, $this->tenant, $this->keuangan, $addon);
        $milik = LanggananAddon::query()->where('IdTenant', $this->tenant->Id)->sole();

        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post(BantuanTagihan::Url("/kelola/langganan/addon/{$milik->Uuid}/berhenti"))->assertSessionHasNoErrors();
        expect($milik->refresh()->BerhentiPada)->not->toBeNull()
            ->and($milik->PerpanjangOtomatis)->toBeFalse()
            ->and(FiturAddonAktifUji($this->tenant))->toBeTrue();

        // Lanjutkan lagi lalu berhenti lagi (idempoten, audit tercatat).
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post(BantuanTagihan::Url("/kelola/langganan/addon/{$milik->Uuid}/lanjut"))->assertSessionHasNoErrors();
        expect($milik->refresh()->BerhentiPada)->toBeNull();
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post(BantuanTagihan::Url("/kelola/langganan/addon/{$milik->Uuid}/berhenti"))->assertSessionHasNoErrors();
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post(BantuanTagihan::Url("/kelola/langganan/addon/{$milik->Uuid}/berhenti"))->assertSessionHasNoErrors();
        expect(LogAudit::query()->withoutGlobalScopes()->where('Peristiwa', 'langganan.addon-berhenti')->count())->toBe(2);

        $this->travelTo(Carbon::parse('2026-09-24 08:20', 'Asia/Jakarta'));
        $this->artisan('tagihan:terbitkan-perpanjangan')->assertSuccessful();
        $perpanjangan = TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->where('Jenis', JenisTagihanLangganan::Perpanjangan->value)->sole();
        expect($perpanjangan->Subtotal)->toBe('249000.00')
            ->and(TagihanLanggananAddon::query()->where('IdTagihanLangganan', $perpanjangan->Id)->count())->toBe(0);
        BayarTagihanAddonUji($this, $this->pemilik, $this->tenant, $this->keuangan, $perpanjangan);

        // Periode baru berjalan: add-on yang dihentikan sudah tidak aktif dan tidak bisa dilanjutkan, hanya dibeli ulang.
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Asia/Jakarta'));
        expect(FiturAddonAktifUji($this->tenant))->toBeFalse();
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)->post(BantuanTagihan::Url("/kelola/langganan/addon/{$milik->Uuid}/lanjut"))->assertSessionHasErrors('Umum');
    });

    it('tagihan add-on yang belum dibayar tidak menghalangi perpanjangan: dibatalkan otomatis saat tagihan paket terbit', function (): void {
        $addon = BeliAddonUji($this, $this->pemilik, $this->tenant);

        $this->travelTo(Carbon::parse('2026-09-24 08:20', 'Asia/Jakarta'));
        $this->artisan('tagihan:terbitkan-perpanjangan')->expectsOutputToContain('1 tagihan perpanjangan terbit')->assertSuccessful();

        expect($addon->refresh()->Status)->toBe(StatusTagihanLangganan::Dibatalkan)
            ->and(TagihanLangganan::query()->withoutGlobalScopes()->where('IdTenant', $this->tenant->Id)->where('Jenis', JenisTagihanLangganan::Perpanjangan->value)->count())->toBe(1);
        // Tagihan add-on yang sudah lunas dalam jendela H-7 juga tidak dianggap tagihan paket.
    });

    it('tagihan add-on lewat jatuh tempo tanpa dibayar dibatalkan oleh penjadwal; paket tidak ikut tertunggak', function (): void {
        $addon = BeliAddonUji($this, $this->pemilik, $this->tenant);

        $this->travelTo($addon->JatuhTempoPada->copy()->addMinute());
        app(ProsesTunggakanLangganan::class)->Jalankan();

        $addon->refresh();
        expect($addon->Status)->toBe(StatusTagihanLangganan::Dibatalkan)
            ->and($addon->AlasanBatal)->toContain('Kedaluwarsa')
            ->and(Langganan::query()->where('IdTenant', $this->tenant->Id)->sole()->Status)->toBe(StatusLangganan::Aktif);
    });

    it('laporan langganan: MRR tetap dari tagihan paket; pendapatan add-on tampil di grup Add-on', function (): void {
        $paket = BeliAddonUji($this, $this->pemilik, $this->tenant);
        BayarTagihanAddonUji($this, $this->pemilik, $this->tenant, $this->keuangan, $paket);

        $laporan = app(LaporanLanggananPlatform::class)
            ->Ambil(Carbon::parse('2026-09-01')->toImmutable(), Carbon::parse('2026-09-30')->toImmutable());
        $grup = collect($laporan['PendapatanPerPaket'])->firstWhere('Kunci', 'addon');

        expect($grup['Nama'])->toBe('Add-on')->and($grup['Pendapatan'])->toBe('18000.00')
            ->and($laporan['Ringkasan']['Mrr'] ?? '0.00')->toBe('0.00');
    });
});

function TanggihanJumlahUji(TagihanLangganan $tagihan): int
{
    return TagihanLanggananAddon::query()->where('IdTagihanLangganan', $tagihan->Id)->sole()->Jumlah;
}
