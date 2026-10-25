<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\Tagihan\Kueri\LaporanLanggananPlatform;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Model\SkorKesehatanTenant;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Bahan bisnis untuk Beranda konsol sebagai pusat komando (D-207): MRR/ARR, tren 12 bulan, churn, pendapatan per paket,
 * sebaran tenant per sektor, tenant berisiko, konversi uji coba, dan aktivitas terbaru. Tiap bagian hanya diisi bila
 * pelaku berizin melihat sumbernya (tagihan, tenant). Bagian berbiaya (laporan langganan, tren) disimpan 5 menit karena
 * Beranda dibuka paling sering dan angkanya berubah per tagihan, bukan per detik. Tenant berpenanda Uji/Demo/Internal
 * tidak dihitung sebagai pelanggan.
 */
final class DasborBisnisPengelola
{
    private const DETIK_SIMPAN = 300;

    /** Awalan kode template sektor → nama sektor untuk pembaca. */
    private const NAMA_SEKTOR = [
        'RTL' => 'Retail & minimarket',
        'FNB' => 'F&B',
        'SVC' => 'Jasa & layanan',
        'WHS' => 'Grosir & distribusi',
        'BIZ' => 'Perusahaan',
    ];

    public function __construct(private readonly LaporanLanggananPlatform $laporan) {}

    /**
     * @return array{Bisnis: array<string, mixed>|null, TenantPerSektor: list<array{Nama: string, Jumlah: int}>|null, TenantRisiko: list<array<string, mixed>>|null, Konversi: array<string, mixed>|null, Aktivitas: list<array<string, mixed>>|null}
     */
    public function Ambil(PenggunaPengelola $pelaku): array
    {
        $bolehTagihan = $pelaku->PunyaIzin(IzinPengelola::TagihanLihat);
        $bolehTenant = $pelaku->PunyaIzin(IzinPengelola::TenantLihat);

        return [
            'Bisnis' => $bolehTagihan ? Cache::remember('pengelola:beranda:bisnis', self::DETIK_SIMPAN, fn (): array => $this->AmbilBisnis()) : null,
            'TenantPerSektor' => $bolehTenant ? $this->AmbilTenantPerSektor() : null,
            'TenantRisiko' => $bolehTenant ? $this->AmbilTenantRisiko() : null,
            'Konversi' => $bolehTenant ? $this->AmbilKonversi() : null,
            'Aktivitas' => $bolehTenant || $bolehTagihan ? $this->AmbilAktivitas($bolehTenant, $bolehTagihan) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function AmbilBisnis(): array
    {
        $sekarang = CarbonImmutable::now();
        $laporan = $this->laporan->Ambil($sekarang->startOfMonth(), $sekarang);
        $tren = $this->laporan->TrenMrr(12);
        $bulanLalu = $tren[count($tren) - 2]['Mrr'] ?? null;
        $lewatTempo = array_values(array_filter($laporan['Piutang']['Umur'], fn (array $u): bool => $u['Kunci'] !== 'BelumJatuhTempo'));
        $nilaiLewat = Uang::Nol();
        $jumlahLewat = 0;

        foreach ($lewatTempo as $u) {
            $nilaiLewat = $nilaiLewat->Tambah(Uang::Dari($u['Total']));
            $jumlahLewat += $u['Jumlah'];
        }

        return [
            'Mrr' => $laporan['Ringkasan']['Mrr'],
            'Arr' => $laporan['Ringkasan']['Arr'],
            'PelangganBerbayar' => $laporan['Ringkasan']['PelangganBerbayar'],
            'PerubahanMrrPersen' => $bulanLalu === null || BigDecimal::of($bulanLalu)->isZero() ? null : (string) BigDecimal::of($laporan['Ringkasan']['Mrr'])->minus($bulanLalu)->multipliedBy(100)->dividedBy($bulanLalu, 1, RoundingMode::HalfUp),
            'Churn' => [
                'PersenChurn' => $laporan['Churn']['PersenChurn'],
                'PelangganBerhenti' => $laporan['Churn']['PelangganBerhenti'],
                'PelangganBaru' => $laporan['Churn']['PelangganBaru'],
                'MrrBaru' => $laporan['Churn']['MrrBaru'],
                'MrrBerhenti' => $laporan['Churn']['MrrBerhenti'],
            ],
            'MrrPerPaket' => $laporan['MrrPerPaket'],
            'PendapatanBulanIni' => $laporan['PendapatanPerPaket'],
            'Tren' => $tren,
            'LewatTempo' => ['Jumlah' => $jumlahLewat, 'Total' => $nilaiLewat->KeString()],
        ];
    }

    /**
     * @return list<array{Nama: string, Jumlah: int}>
     */
    private function AmbilTenantPerSektor(): array
    {
        $hitung = [];

        foreach (Tenant::query()->whereNull('Penanda')->where('Status', StatusTenant::Aktif->value)->get(['Id', 'Pengaturan']) as $t) {
            $kode = $t->Pengaturan['Sektor'][0] ?? null;
            $awalan = is_string($kode) && $kode !== '' ? strtoupper(explode('-', $kode)[0]) : '';
            $nama = self::NAMA_SEKTOR[$awalan] ?? ($awalan === '' ? 'Belum memilih sektor' : 'Lainnya');
            $hitung[$nama] = ($hitung[$nama] ?? 0) + 1;
        }

        arsort($hitung);

        return array_values(array_map(fn (string $nama, int $jumlah): array => ['Nama' => $nama, 'Jumlah' => $jumlah], array_keys($hitung), $hitung));
    }

    /**
     * Tenant berkategori Berisiko pada skor terbaru, skor terendah dulu (paling tidak sehat di atas).
     *
     * @return list<array<string, mixed>>
     */
    private function AmbilTenantRisiko(): array
    {
        $tanggal = SkorKesehatanTenant::query()->max('Tanggal');

        if ($tanggal === null) {
            return [];
        }

        $skor = SkorKesehatanTenant::query()
            ->where('Tanggal', $tanggal)
            ->where('Kategori', KategoriKesehatan::Berisiko->value)
            ->orderBy('Skor')
            ->limit(20)
            ->get(['IdTenant', 'Skor']);
        $tenant = Tenant::query()->whereNull('Penanda')->whereIn('Id', $skor->pluck('IdTenant')->all())->get(['Id', 'Uuid', 'Nama'])->keyBy('Id');
        $langganan = Langganan::query()->whereIn('IdTenant', $tenant->keys()->all())->get(['IdTenant', 'IdPaket', 'Status'])->keyBy('IdTenant');
        $paket = Paket::query()->pluck('Nama', 'Id');
        $hasil = [];

        foreach ($skor as $s) {
            $t = $tenant->get($s->IdTenant);

            if ($t === null) {
                continue;
            }

            $l = $langganan->get($s->IdTenant);
            $hasil[] = [
                'Uuid' => $t->Uuid,
                'Nama' => $t->Nama,
                'Paket' => $l === null ? null : (string) ($paket[$l->IdPaket] ?? '—'),
                'StatusLangganan' => $l?->Status->value,
                'Skor' => $s->Skor,
            ];

            if (count($hasil) === 5) {
                break;
            }
        }

        return $hasil;
    }

    /**
     * Konversi uji coba: dari tenant yang mendaftar 30–60 hari lalu (sudah cukup umur untuk memutuskan), berapa yang kini
     * berlangganan berbayar. Kohort yang terlalu baru sengaja tidak dihitung supaya angkanya tidak menipu.
     *
     * @return array{Kohort: int, Berbayar: int, Persen: string, UjiCobaBerjalan: int}
     */
    private function AmbilKonversi(): array
    {
        $kohort = Tenant::query()->whereNull('Penanda')
            ->where('DibuatPada', '>=', now()->subDays(60))
            ->where('DibuatPada', '<', now()->subDays(30))
            ->pluck('Id')->all();
        $berbayar = $kohort === [] ? 0 : Langganan::query()->whereIn('IdTenant', $kohort)->where('Status', StatusLangganan::Aktif->value)->count();
        $idPelanggan = Tenant::query()->whereNull('Penanda')->where('Status', StatusTenant::Aktif->value)->pluck('Id')->all();

        return [
            'Kohort' => count($kohort),
            'Berbayar' => $berbayar,
            'Persen' => $kohort === [] ? '0.0' : (string) BigDecimal::of($berbayar)->multipliedBy(100)->dividedBy(count($kohort), 1, RoundingMode::HalfUp),
            'UjiCobaBerjalan' => Langganan::query()->whereIn('IdTenant', $idPelanggan)->where('Status', StatusLangganan::Trial->value)->count(),
        ];
    }

    /**
     * Peristiwa bisnis terbaru: tenant baru dan tagihan yang lunas. Sengaja bukan dari log audit petugas (pembacanya
     * dibatasi test arsitektur); ini kabar bisnis, bukan jejak petugas.
     *
     * @return list<array{Waktu: string, Teks: string, Tautan: string|null}>
     */
    private function AmbilAktivitas(bool $bolehTenant, bool $bolehTagihan): array
    {
        $butir = [];

        if ($bolehTenant) {
            foreach (Tenant::query()->whereNull('Penanda')->orderByDesc('DibuatPada')->limit(4)->get(['Uuid', 'Nama', 'DibuatPada']) as $t) {
                $butir[] = ['Waktu' => $t->DibuatPada->toIso8601String(), 'Teks' => "Tenant baru: {$t->Nama}", 'Tautan' => "/tenant/{$t->Uuid}"];
            }
        }

        if ($bolehTagihan) {
            $lunas = DaftarTagihanPlatform::KueriTagihan()
                ->where('Status', StatusTagihanLangganan::Lunas->value)
                ->whereNotNull('DibayarPada')
                ->orderByDesc('DibayarPada')
                ->limit(4)
                ->get(['Nomor', 'Total', 'DibayarPada']);

            foreach ($lunas as $tagihan) {
                /** @var TagihanLangganan $tagihan */
                $butir[] = ['Waktu' => ($tagihan->DibayarPada ?? now())->toIso8601String(), 'Teks' => "Tagihan {$tagihan->Nomor} lunas ".Uang::Dari($tagihan->Total)->FormatRupiah(), 'Tautan' => '/tagihan'];
            }
        }

        usort($butir, fn (array $a, array $b): int => strcmp($b['Waktu'], $a['Waktu']));

        return array_slice($butir, 0, 6);
    }
}
