<?php

declare(strict_types=1);

use App\Domain\Pengelola\TimInternal\Model\LogAuditPengelola;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Pendukung\Tenant\BantuanTagihan;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    BantuanTagihan::SiapkanPrasyarat();
    Storage::fake('local');
    Mail::fake();
    ['Tenant' => $this->tenant, 'Pengguna' => $this->pemilik] = BantuanTagihan::DaftarTenant();
    // Langganan aktif sebulan yang berakhir 1 Oktober 2026 10.00 WIB.
    DB::table('Langganan')->where('IdTenant', $this->tenant->Id)->update([
        'Status' => StatusLangganan::Aktif->value,
        'PeriodeMulai' => Carbon::parse('2026-09-01 10:00:00', 'Asia/Jakarta')->utc(),
        'PeriodeSelesai' => Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta')->utc(),
    ]);
});

function StatusLanggananTunggakanUji(int $idTenant): StatusLangganan
{
    return Langganan::query()->where('IdTenant', $idTenant)->sole()->Status;
}

describe('Penjadwal tunggakan (P-08 langkah 4, F-00 state machine)', function (): void {
    it('jatuh tempo lewat → Tertunggak. Setelah masa tenggang 7 hari → Ditangguhkan. Idempoten & tercatat audit', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan'])
            ->assertSessionHasNoErrors();
        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->sole();

        $this->travelTo(Carbon::parse('2026-10-01 09:59:00', 'Asia/Jakarta'));
        $this->artisan('tagihan:proses-tunggakan')->expectsOutputToContain('0 tagihan lewat jatuh tempo, 0 langganan Tertunggak, 0 langganan Ditangguhkan')->assertSuccessful();
        expect(StatusLanggananTunggakanUji($this->tenant->Id))->toBe(StatusLangganan::Aktif);

        $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta'));
        $this->artisan('tagihan:proses-tunggakan')->expectsOutputToContain('1 tagihan lewat jatuh tempo, 1 langganan Tertunggak, 0 langganan Ditangguhkan')->assertSuccessful();
        $this->artisan('tagihan:proses-tunggakan')->expectsOutputToContain('0 tagihan lewat jatuh tempo, 0 langganan Tertunggak, 0 langganan Ditangguhkan')->assertSuccessful();
        expect(StatusLanggananTunggakanUji($this->tenant->Id))->toBe(StatusLangganan::Tertunggak)
            ->and($tagihan->refresh()->Status)->toBe(StatusTagihanLangganan::JatuhTempo);

        $this->travelTo(Carbon::parse('2026-10-08 09:59:00', 'Asia/Jakarta'));
        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();
        expect(StatusLanggananTunggakanUji($this->tenant->Id))->toBe(StatusLangganan::Tertunggak);

        $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'Asia/Jakarta'));
        $this->artisan('tagihan:proses-tunggakan')->expectsOutputToContain('1 langganan Ditangguhkan')->assertSuccessful();
        expect(StatusLanggananTunggakanUji($this->tenant->Id))->toBe(StatusLangganan::Ditangguhkan);

        $log = LogAuditPengelola::query()->where('Aksi', 'langganan.status.otomatis')->orderBy('Id')->get();
        expect($log)->toHaveCount(2)
            ->and($log->pluck('IdTenant')->unique()->all())->toBe([$this->tenant->Id])
            ->and($log->pluck('IdPenggunaPengelola')->unique()->all())->toBe([null])
            ->and(LogAuditPengelola::query()->where('Aksi', 'tagihan.jatuh-tempo')->sole()->IdTenant)->toBe($this->tenant->Id);
    });

    it('tidak menangguhkan tenant yang bukti transfernya sedang menunggu verifikasi', function (): void {
        BantuanTagihan::Masuk($this, $this->pemilik, $this->tenant)
            ->post('/kelola/langganan/tagihan', ['KodePaket' => 'PRO', 'Siklus' => 'Bulanan']);
        $tagihan = TagihanLangganan::query()->withoutGlobalScopes()->sole();
        $this->travelTo(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta'));
        $this->artisan('tagihan:proses-tunggakan');
        BantuanTagihan::UnggahBuktiLangsung($this->tenant, $this->pemilik, $tagihan, '2026-10-03');

        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'Asia/Jakarta'));
        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();

        expect(StatusLanggananTunggakanUji($this->tenant->Id))->toBe(StatusLangganan::Tertunggak)
            ->and(DB::table('PembayaranLangganan')->value('Status'))->toBe(StatusPembayaranLangganan::Menunggu->value);
    });

    it('langganan Trial, Gratis, atau Aktif tanpa periode tidak disentuh', function (): void {
        DB::table('Langganan')->where('IdTenant', $this->tenant->Id)->update(['Status' => 'Trial']);
        $this->travelTo(Carbon::parse('2026-12-01 10:00:00', 'Asia/Jakarta'));

        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();

        expect(StatusLanggananTunggakanUji($this->tenant->Id))->toBe(StatusLangganan::Trial);
    });
});

describe('Penanda tenant (P-07)', function (): void {
    it('tenant berpenanda Uji/Demo/Internal dikecualikan dari tunggakan otomatis', function (): void {
        DB::table('Tenant')->where('Id', $this->tenant->Id)->update(['Penanda' => 'Demo']);

        $this->travelTo(Carbon::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();
        $this->artisan('tagihan:proses-tunggakan')->assertSuccessful();

        expect(StatusLanggananTunggakanUji($this->tenant->Id))->toBe(StatusLangganan::Aktif);
    });
});
