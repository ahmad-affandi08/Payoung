<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\Pengelola\Tenant\Layanan\PengumpulFiturTenant;
use App\Domain\Pengelola\Tenant\Model\AnalitikTenant;
use App\Domain\Tenant\Model\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * P-07 PGL-24: analitik platform dari bahan harian `PerbaruiAnalitikTenant` (funnel pendaftaran, adopsi fitur per sektor,
 * retensi kohort bulanan). Semuanya angka agregat: tidak ada nama tenant, pelanggan, atau data pribadi yang keluar, jadi
 * aman untuk peran Analis (§19.3). Tenant bertanda Uji/Demo/Internal tidak dihitung.
 *
 * - **Funnel**: Daftar → Panduan awal selesai → Transaksi pertama → Bayar pertama (tagihan langganan berbayar lunas),
 *   dengan persen terhadap pendaftar dan terhadap tahap sebelumnya serta rata-rata hari dari pendaftaran.
 * - **Adopsi fitur**: dari tenant yang menjual dalam 30 hari terakhir, berapa yang memakai tiap fitur utama.
 * - **Retensi kohort**: kohort = bulan pendaftaran; M+n = persen tenant kohort yang punya transaksi pada bulan ke-n.
 */
final class LaporanAnalitikPlatform
{
    public const BULAN_KOHORT = 12;

    private const TAHAP = [
        'Daftar' => 'Mendaftar',
        'Panduan' => 'Panduan awal selesai',
        'Transaksi' => 'Transaksi pertama',
        'Bayar' => 'Langganan berbayar pertama',
    ];

    /**
     * @return list<array{Nilai: string, Label: string}>
     */
    public function AmbilPilihanSektor(): array
    {
        $kode = AnalitikTenant::query()->whereNotNull('KodeSektor')->distinct()->orderBy('KodeSektor')->pluck('KodeSektor')->all();
        $nama = $this->AmbilNamaSektor();

        return array_values(array_map(fn (mixed $k): array => ['Nilai' => (string) $k, 'Label' => $nama[(string) $k] ?? (string) $k], $kode));
    }

    /**
     * @return array{Tahap: list<array{Kunci: string, Label: string, Jumlah: int, PersenDaftar: string, PersenSebelumnya: string|null, RataRataHari: string|null}>, PerSektor: list<array{Kunci: string, Sektor: string, Daftar: int, Panduan: int, Transaksi: int, Bayar: int, PersenBayar: string}>}
     */
    public function Funnel(?CarbonImmutable $dari, ?CarbonImmutable $sampai, ?string $sektor): array
    {
        $total = $this->DasarFunnel($dari, $sampai, $sektor)
            ->selectRaw('COUNT(*) AS Daftar, COUNT(a.PanduanSelesaiPada) AS Panduan, COUNT(a.TransaksiPertamaPada) AS Transaksi, COUNT(a.BayarPertamaPada) AS Bayar, '
                .'ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(HOUR, Tenant.DibuatPada, a.PanduanSelesaiPada))) / 24, 1) AS HariPanduan, '
                .'ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(HOUR, Tenant.DibuatPada, a.TransaksiPertamaPada))) / 24, 1) AS HariTransaksi, '
                .'ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(HOUR, Tenant.DibuatPada, a.BayarPertamaPada))) / 24, 1) AS HariBayar')
            ->toBase()
            ->first();

        $jumlah = [
            'Daftar' => (int) ($total->Daftar ?? 0),
            'Panduan' => (int) ($total->Panduan ?? 0),
            'Transaksi' => (int) ($total->Transaksi ?? 0),
            'Bayar' => (int) ($total->Bayar ?? 0),
        ];
        $hari = [
            'Daftar' => null,
            'Panduan' => $total->HariPanduan ?? null,
            'Transaksi' => $total->HariTransaksi ?? null,
            'Bayar' => $total->HariBayar ?? null,
        ];
        $tahap = [];
        $sebelumnya = null;

        foreach (self::TAHAP as $kunci => $label) {
            $tahap[] = [
                'Kunci' => $kunci,
                'Label' => $label,
                'Jumlah' => $jumlah[$kunci],
                'PersenDaftar' => self::Persen($jumlah[$kunci], $jumlah['Daftar']),
                'PersenSebelumnya' => $sebelumnya === null ? null : self::Persen($jumlah[$kunci], $sebelumnya),
                'RataRataHari' => $hari[$kunci] === null ? null : (string) $hari[$kunci],
            ];
            $sebelumnya = $jumlah[$kunci];
        }

        $nama = $this->AmbilNamaSektor();
        $perSektor = [];

        $baris = $this->DasarFunnel($dari, $sampai, $sektor)
            ->selectRaw('a.KodeSektor AS KodeSektor, COUNT(*) AS Daftar, COUNT(a.PanduanSelesaiPada) AS Panduan, COUNT(a.TransaksiPertamaPada) AS Transaksi, COUNT(a.BayarPertamaPada) AS Bayar')
            ->groupBy('a.KodeSektor')
            ->toBase()
            ->get();

        foreach ($baris as $b) {
            $kode = $b->KodeSektor === null ? null : (string) $b->KodeSektor;
            $perSektor[] = [
                'Kunci' => $kode ?? '-',
                'Sektor' => $kode === null ? 'Belum memilih sektor' : ($nama[$kode] ?? $kode),
                'Daftar' => (int) $b->Daftar,
                'Panduan' => (int) $b->Panduan,
                'Transaksi' => (int) $b->Transaksi,
                'Bayar' => (int) $b->Bayar,
                'PersenBayar' => self::Persen((int) $b->Bayar, (int) $b->Daftar),
            ];
        }

        return ['Tahap' => $tahap, 'PerSektor' => $perSektor];
    }

    /**
     * @return array{Fitur: list<array{Kunci: string, Label: string}>, Baris: list<array{Kunci: string, Sektor: string, TenantAktif: int, Fitur: array<string, array{Jumlah: int, Persen: string}>}>}
     */
    public function AdopsiFitur(): array
    {
        $nama = $this->AmbilNamaSektor();
        $baris = $this->DasarAnalitik()
            ->whereRaw("JSON_CONTAINS(a.FiturDipakai, '\"Penjualan\"')")
            ->selectRaw('a.KodeSektor AS KodeSektor, COUNT(*) AS Aktif, '
                ."SUM(JSON_CONTAINS(a.FiturDipakai, '\"Opname\"')) AS Opname, "
                ."SUM(JSON_CONTAINS(a.FiturDipakai, '\"PesananPembelian\"')) AS PesananPembelian, "
                ."SUM(JSON_CONTAINS(a.FiturDipakai, '\"Pelanggan\"')) AS Pelanggan, "
                ."SUM(JSON_CONTAINS(a.FiturDipakai, '\"Promo\"')) AS Promo, "
                ."SUM(JSON_CONTAINS(a.FiturDipakai, '\"Karyawan\"')) AS Karyawan")
            ->groupBy('a.KodeSektor')
            ->toBase()
            ->get();

        $hasil = [];
        $semua = ['Aktif' => 0, 'Fitur' => array_fill_keys(array_keys(PengumpulFiturTenant::FITUR), 0)];

        foreach ($baris as $b) {
            $kode = $b->KodeSektor === null ? null : (string) $b->KodeSektor;
            $aktif = (int) $b->Aktif;
            $fitur = [];

            foreach (array_keys(PengumpulFiturTenant::FITUR) as $kunci) {
                $n = (int) $b->{$kunci};
                $fitur[$kunci] = ['Jumlah' => $n, 'Persen' => self::Persen($n, $aktif)];
                $semua['Fitur'][$kunci] += $n;
            }

            $semua['Aktif'] += $aktif;
            $hasil[] = ['Kunci' => $kode ?? '-', 'Sektor' => $kode === null ? 'Belum memilih sektor' : ($nama[$kode] ?? $kode), 'TenantAktif' => $aktif, 'Fitur' => $fitur];
        }

        usort($hasil, fn (array $a, array $b): int => $b['TenantAktif'] <=> $a['TenantAktif']);

        if ($hasil !== []) {
            $fiturSemua = [];

            foreach ($semua['Fitur'] as $kunci => $n) {
                $fiturSemua[$kunci] = ['Jumlah' => $n, 'Persen' => self::Persen($n, $semua['Aktif'])];
            }

            array_unshift($hasil, ['Kunci' => '*', 'Sektor' => 'Semua sektor', 'TenantAktif' => $semua['Aktif'], 'Fitur' => $fiturSemua]);
        }

        $daftarFitur = [];

        foreach (PengumpulFiturTenant::FITUR as $kunci => $label) {
            $daftarFitur[] = ['Kunci' => $kunci, 'Label' => $label];
        }

        return ['Fitur' => $daftarFitur, 'Baris' => $hasil];
    }

    /**
     * @return array{Bulan: list<string>, Baris: list<array{Kunci: string, Label: string, Ukuran: int, Retensi: list<array{Persen: string, Aktif: int}|null>}>}
     */
    public function RetensiKohort(?string $sektor, ?CarbonImmutable $sekarang = null): array
    {
        $sekarang ??= CarbonImmutable::now('Asia/Jakarta');
        $bulanIni = $sekarang->startOfMonth();
        $awal = $bulanIni->subMonths(self::BULAN_KOHORT - 1);
        // Jam pendaftaran disimpan UTC; kohort dihitung menurut bulan kalender WIB, sama dengan tanggal bisnis transaksi.
        $awalUtc = $awal->setTimezone('UTC')->toDateTimeString();

        $ukuran = $this->DasarKohort($sektor, $awalUtc)
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(Tenant.DibuatPada, '+00:00', '+07:00'), '%Y-%m-01') AS Kohort, COUNT(*) AS Ukuran")
            ->groupBy('Kohort')
            ->toBase()
            ->get();

        $aktif = $this->DasarKohort($sektor, $awalUtc)
            ->join('AktivitasTenantBulanan AS ab', 'ab.IdTenant', '=', 'Tenant.Id')
            ->where('ab.JumlahTransaksi', '>', 0)
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(Tenant.DibuatPada, '+00:00', '+07:00'), '%Y-%m-01') AS Kohort, ab.Bulan AS Bulan, COUNT(DISTINCT ab.IdTenant) AS Aktif")
            ->groupBy('Kohort', 'ab.Bulan')
            ->toBase()
            ->get();

        $ukuranKohort = [];

        foreach ($ukuran as $u) {
            $ukuranKohort[(string) $u->Kohort] = (int) $u->Ukuran;
        }

        $aktifKohort = [];

        foreach ($aktif as $a) {
            $aktifKohort[(string) $a->Kohort][substr((string) $a->Bulan, 0, 7)] = (int) $a->Aktif;
        }

        $baris = [];
        $kolom = [];

        for ($k = $awal; $k->lessThanOrEqualTo($bulanIni); $k = $k->addMonth()) {
            $kunci = $k->format('Y-m-01');
            $n = $ukuranKohort[$kunci] ?? 0;
            $retensi = [];

            for ($offset = 0; $offset <= self::BULAN_KOHORT - 1; $offset++) {
                $bulan = $k->addMonths($offset);

                if ($bulan->greaterThan($bulanIni)) {
                    $retensi[] = null;

                    continue;
                }

                $jumlah = $aktifKohort[$kunci][$bulan->format('Y-m')] ?? 0;
                $retensi[] = ['Persen' => self::Persen($jumlah, $n), 'Aktif' => $jumlah];
            }

            $baris[] = ['Kunci' => $k->format('Y-m'), 'Label' => $k->translatedFormat('F Y'), 'Ukuran' => $n, 'Retensi' => $retensi];
        }

        for ($offset = 0; $offset <= self::BULAN_KOHORT - 1; $offset++) {
            $kolom[] = 'M+'.$offset;
        }

        return ['Bulan' => $kolom, 'Baris' => $baris];
    }

    /**
     * @return Builder<Tenant>
     */
    private function DasarFunnel(?CarbonImmutable $dari, ?CarbonImmutable $sampai, ?string $sektor): Builder
    {
        $kueri = Tenant::query()
            ->leftJoin('AnalitikTenant AS a', 'a.IdTenant', '=', 'Tenant.Id')
            ->whereNull('Tenant.Penanda');

        if ($dari !== null) {
            $kueri->where('Tenant.DibuatPada', '>=', $dari->startOfDay()->setTimezone('UTC')->toDateTimeString());
        }

        if ($sampai !== null) {
            $kueri->where('Tenant.DibuatPada', '<=', $sampai->endOfDay()->setTimezone('UTC')->toDateTimeString());
        }

        if ($sektor !== null && $sektor !== '') {
            $kueri->where('a.KodeSektor', $sektor);
        }

        return $kueri;
    }

    /**
     * @return Builder<Tenant>
     */
    private function DasarAnalitik(): Builder
    {
        return Tenant::query()
            ->join('AnalitikTenant AS a', 'a.IdTenant', '=', 'Tenant.Id')
            ->whereNull('Tenant.Penanda');
    }

    /**
     * @return Builder<Tenant>
     */
    private function DasarKohort(?string $sektor, string $awalUtc): Builder
    {
        $kueri = Tenant::query()
            ->whereNull('Tenant.Penanda')
            ->where('Tenant.DibuatPada', '>=', $awalUtc);

        if ($sektor !== null && $sektor !== '') {
            $kueri->join('AnalitikTenant AS a', 'a.IdTenant', '=', 'Tenant.Id')->where('a.KodeSektor', $sektor);
        }

        return $kueri;
    }

    /**
     * @return array<string, string>
     */
    private function AmbilNamaSektor(): array
    {
        $hasil = [];

        foreach (TemplateSektor::query()->orderBy('Kode')->get(['Kode', 'Nama']) as $t) {
            $hasil[$t->Kode] ??= $t->Nama;
        }

        return $hasil;
    }

    /** Persen satu desimal sebagai string ("45.5"); penyebut nol = "0.0". */
    private static function Persen(int $bagian, int $penyebut): string
    {
        if ($penyebut <= 0) {
            return '0.0';
        }

        return (string) BigDecimal::of($bagian)->multipliedBy(100)->dividedBy($penyebut, 1, RoundingMode::HalfUp);
    }
}
