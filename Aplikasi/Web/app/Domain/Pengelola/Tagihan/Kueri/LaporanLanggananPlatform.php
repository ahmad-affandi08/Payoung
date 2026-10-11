<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * P-08 langkah 7 (PRD v4.09): laporan langganan platform untuk Keuangan/Super Admin — MRR, ARR, churn, piutang
 * langganan & umurnya, serta pendapatan per paket dan per sektor. Sumbernya **tagihan langganan**, bukan harga
 * katalog, supaya kupon, harga negosiasi, dan grandfathering ikut tercermin.
 *
 * Definisi (tertulis juga di halaman):
 * - **Pelanggan berbayar pada waktu T** = tenant yang punya tagihan `Lunas` dengan `PeriodeMulai ≤ T` dan
 *   `PeriodeSelesai + masa tenggang > T` (tenant tertunggak dalam masa tenggang masih dihitung, sama dengan aksesnya).
 * - **MRR** = Σ (Subtotal − Diskon) ÷ JumlahBulan tagihan yang sedang menutup T (tanpa PPN). **ARR** = MRR × 12.
 * - **Churn periode** = pelanggan berbayar di awal periode yang tidak lagi berbayar di akhir periode, dibagi pelanggan
 *   berbayar di awal periode; **churn MRR** memakai MRR awal mereka.
 * - **Pendapatan** = Σ (Subtotal − Diskon) tagihan yang `DibayarPada` dalam periode (basis kas, tanpa PPN), per paket
 *   dan per sektor utama tenant (`Pengaturan.Sektor[0]`).
 * - **Piutang** = tagihan terbuka per hari ini, dikelompokkan menurut hari lewat jatuh tempo.
 *
 * Tenant berpenanda Uji/Demo/Internal dikecualikan (P-07). Lingkup tenant dilepas lewat `KonteksPengelola`
 * (`DaftarTagihanPlatform::KueriTagihan`), CLAUDE.md #11.
 */
final class LaporanLanggananPlatform
{
    private const KELOMPOK_UMUR = [
        'BelumJatuhTempo' => 'Belum jatuh tempo',
        'Hari1Sampai30' => '1–30 hari',
        'Hari31Sampai60' => '31–60 hari',
        'Hari61Sampai90' => '61–90 hari',
        'LebihDari90' => 'Lebih dari 90 hari',
    ];

    /**
     * @return array<string, mixed>
     */
    public function Ambil(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $sekarang = CarbonImmutable::now();
        $awal = $dari->startOfDay();
        $akhir = $sampai->endOfDay()->lessThan($sekarang) ? $sampai->endOfDay() : $sekarang;
        $tenant = Tenant::query()->whereNull('Penanda')->get(['Id', 'Pengaturan'])->keyBy('Id');
        $idTenant = array_values(array_map('intval', $tenant->keys()->all()));
        $paket = Paket::query()->get(['Id', 'Kode', 'Nama'])->keyBy('Id');
        $lunas = array_values(DaftarTagihanPlatform::KueriTagihan()
            ->whereIn('IdTenant', $idTenant)
            ->where('Status', StatusTagihanLangganan::Lunas->value)
            ->whereNotNull('PeriodeMulai')
            ->whereNotNull('PeriodeSelesai')
            ->get(['Id', 'IdTenant', 'IdPaket', 'Jenis', 'JumlahBulan', 'Subtotal', 'Diskon', 'PeriodeMulai', 'PeriodeSelesai', 'DibayarPada'])
            ->all());

        // D-49/D-98: MRR/ARR/churn dihitung dari tagihan paket (aktivasi, perpanjangan); tagihan add-on hanya masuk ke
        // pendapatan (grup "Add-on"), tagihan naik paket (selisih prorata) masuk pendapatan paket barunya. MRR paket baru
        // terbaca penuh sejak perpanjangan berikutnya.
        $lunasPaket = array_values(array_filter($lunas, fn (TagihanLangganan $t): bool => ! $t->Jenis->CekTengahPeriode()));
        $mrrAkhir = $this->HitungMrrPerTenant($lunasPaket, $akhir);
        $mrrAwal = $this->HitungMrrPerTenant($lunasPaket, $awal);

        return [
            'Ringkasan' => $this->SusunRingkasan($mrrAkhir, $akhir),
            'Churn' => $this->SusunChurn($mrrAwal, $mrrAkhir),
            'MrrPerPaket' => $this->SusunMrrPerPaket($mrrAkhir, $paket->all()),
            'PendapatanPerPaket' => $this->SusunPendapatan($lunas, $awal, $sampai->endOfDay(), fn (TagihanLangganan $t): array => $t->Jenis === JenisTagihanLangganan::Addon
                ? ['addon', 'Add-on']
                : [(string) $t->IdPaket, $paket->get($t->IdPaket)->Nama ?? '—']),
            'PendapatanPerSektor' => $this->SusunPendapatan($lunas, $awal, $sampai->endOfDay(), function (TagihanLangganan $t) use ($tenant): array {
                $sektor = $tenant->get($t->IdTenant)->Pengaturan['Sektor'][0] ?? null;

                return is_string($sektor) && $sektor !== '' ? [$sektor, $sektor] : ['-', 'Belum memilih sektor'];
            }),
            'Piutang' => $this->SusunPiutang($idTenant, $sekarang),
        ];
    }

    /**
     * @param  list<TagihanLangganan>  $lunas
     * @return array<int, array{Mrr: Uang, IdPaket: int}> kunci = IdTenant
     */
    private function HitungMrrPerTenant(array $lunas, CarbonImmutable $pada): array
    {
        $tenggang = (int) config('tagihan.HariMasaTenggang');
        $terpilih = [];

        foreach ($lunas as $t) {
            if ($t->PeriodeMulai === null || $t->PeriodeSelesai === null
                || $t->PeriodeMulai->greaterThan($pada)
                || ! CarbonImmutable::instance($t->PeriodeSelesai)->addDays($tenggang)->greaterThan($pada)) {
                continue;
            }

            $lama = $terpilih[$t->IdTenant] ?? null;

            if ($lama === null || $lama->PeriodeMulai === null || $t->PeriodeMulai->greaterThan($lama->PeriodeMulai)) {
                $terpilih[$t->IdTenant] = $t;
            }
        }

        return array_map(fn (TagihanLangganan $t): array => ['Mrr' => self::Bulanan($t), 'IdPaket' => $t->IdPaket], $terpilih);
    }

    /** (Subtotal − Diskon) ÷ JumlahBulan, dibulatkan ke sen. */
    private static function Bulanan(TagihanLangganan $t): Uang
    {
        $bersih = BigDecimal::of(self::Bersih($t)->KeString());

        return Uang::Dari((string) $bersih->dividedBy(max(1, $t->JumlahBulan), 2, RoundingMode::HalfUp));
    }

    private static function Bersih(TagihanLangganan $t): Uang
    {
        return Uang::Dari($t->Subtotal)->Kurangi(Uang::Dari($t->Diskon));
    }

    /**
     * @param  array<int, array{Mrr: Uang, IdPaket: int}>  $mrr
     * @return array<string, mixed>
     */
    private function SusunRingkasan(array $mrr, CarbonImmutable $pada): array
    {
        $total = self::Jumlahkan($mrr);
        $jumlah = count($mrr);

        return [
            'Pada' => $pada->toIso8601ZuluString(),
            'Mrr' => $total->KeString(),
            'Arr' => $total->Kali(12)->KeString(),
            'PelangganBerbayar' => $jumlah,
            'RataRataPerPelanggan' => $jumlah === 0 ? '0.00' : (string) BigDecimal::of($total->KeString())->dividedBy($jumlah, 2, RoundingMode::HalfUp),
        ];
    }

    /**
     * @param  array<int, array{Mrr: Uang, IdPaket: int}>  $awal
     * @param  array<int, array{Mrr: Uang, IdPaket: int}>  $akhir
     * @return array<string, mixed>
     */
    private function SusunChurn(array $awal, array $akhir): array
    {
        $berhenti = array_diff_key($awal, $akhir);
        $baru = array_diff_key($akhir, $awal);
        $mrrAwal = self::Jumlahkan($awal);
        $mrrBerhenti = self::Jumlahkan($berhenti);

        return [
            'PelangganAwal' => count($awal),
            'PelangganAkhir' => count($akhir),
            'PelangganBaru' => count($baru),
            'PelangganBerhenti' => count($berhenti),
            'PersenChurn' => self::Persen(count($berhenti), count($awal)),
            'MrrAwal' => $mrrAwal->KeString(),
            'MrrBaru' => self::Jumlahkan($baru)->KeString(),
            'MrrBerhenti' => $mrrBerhenti->KeString(),
            'PersenChurnMrr' => self::Persen($mrrBerhenti->KeString(), $mrrAwal->KeString()),
        ];
    }

    /**
     * @param  array<int, array{Mrr: Uang, IdPaket: int}>  $mrr
     * @param  array<int, Paket>  $paket
     * @return list<array{Kunci: string, Nama: string, Pelanggan: int, Mrr: string}>
     */
    private function SusunMrrPerPaket(array $mrr, array $paket): array
    {
        $kelompok = [];

        foreach ($mrr as $baris) {
            $k = $baris['IdPaket'];
            $kelompok[$k] ??= ['Kunci' => (string) $k, 'Nama' => isset($paket[$k]) ? $paket[$k]->Nama : '—', 'Pelanggan' => 0, 'Mrr' => Uang::Nol()];
            $kelompok[$k]['Pelanggan']++;
            $kelompok[$k]['Mrr'] = $kelompok[$k]['Mrr']->Tambah($baris['Mrr']);
        }

        usort($kelompok, fn (array $a, array $b): int => $b['Mrr']->Bandingkan($a['Mrr']));

        return array_map(fn (array $b): array => [...$b, 'Mrr' => $b['Mrr']->KeString()], $kelompok);
    }

    /**
     * @param  list<TagihanLangganan>  $lunas
     * @param  callable(TagihanLangganan): array{0: string, 1: string}  $kunci  [kunci, label]
     * @return list<array{Kunci: string, Nama: string, JumlahTagihan: int, Pendapatan: string}>
     */
    private function SusunPendapatan(array $lunas, CarbonImmutable $dari, CarbonImmutable $sampai, callable $kunci): array
    {
        $kelompok = [];

        foreach ($lunas as $t) {
            if ($t->DibayarPada === null || $t->DibayarPada->lessThan($dari) || $t->DibayarPada->greaterThan($sampai)) {
                continue;
            }

            [$k, $nama] = $kunci($t);
            $kelompok[$k] ??= ['Kunci' => $k, 'Nama' => $nama, 'JumlahTagihan' => 0, 'Pendapatan' => Uang::Nol()];
            $kelompok[$k]['JumlahTagihan']++;
            $kelompok[$k]['Pendapatan'] = $kelompok[$k]['Pendapatan']->Tambah(self::Bersih($t));
        }

        usort($kelompok, fn (array $a, array $b): int => $b['Pendapatan']->Bandingkan($a['Pendapatan']));

        return array_values(array_map(fn (array $b): array => [...$b, 'Pendapatan' => $b['Pendapatan']->KeString()], $kelompok));
    }

    /**
     * @param  list<int>  $idTenant
     * @return array{Total: string, Jumlah: int, Umur: list<array{Kunci: string, Label: string, Jumlah: int, Total: string}>}
     */
    private function SusunPiutang(array $idTenant, CarbonImmutable $sekarang): array
    {
        $hariIni = $sekarang->setTimezone('Asia/Jakarta')->startOfDay();
        $umur = array_map(fn (string $label): array => ['Jumlah' => 0, 'Total' => Uang::Nol()], self::KELOMPOK_UMUR);
        $total = Uang::Nol();
        $terbuka = DaftarTagihanPlatform::KueriTagihan()
            ->whereIn('IdTenant', $idTenant)
            ->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())
            ->get(['Id', 'Total', 'JatuhTempoPada']);

        foreach ($terbuka as $t) {
            $lewat = (int) CarbonImmutable::instance($t->JatuhTempoPada)->setTimezone('Asia/Jakarta')->startOfDay()->diffInDays($hariIni, false);
            $k = match (true) {
                $lewat <= 0 => 'BelumJatuhTempo',
                $lewat <= 30 => 'Hari1Sampai30',
                $lewat <= 60 => 'Hari31Sampai60',
                $lewat <= 90 => 'Hari61Sampai90',
                default => 'LebihDari90',
            };
            $umur[$k]['Jumlah']++;
            $umur[$k]['Total'] = $umur[$k]['Total']->Tambah(Uang::Dari($t->Total));
            $total = $total->Tambah(Uang::Dari($t->Total));
        }

        return [
            'Total' => $total->KeString(),
            'Jumlah' => $terbuka->count(),
            'Umur' => array_values(array_map(
                fn (string $k, array $b): array => ['Kunci' => $k, 'Label' => self::KELOMPOK_UMUR[$k], 'Jumlah' => $b['Jumlah'], 'Total' => $b['Total']->KeString()],
                array_keys($umur),
                $umur,
            )),
        ];
    }

    /** @param  array<int, array{Mrr: Uang, IdPaket: int}>  $mrr */
    private static function Jumlahkan(array $mrr): Uang
    {
        $total = Uang::Nol();

        foreach ($mrr as $b) {
            $total = $total->Tambah($b['Mrr']);
        }

        return $total;
    }

    /** Persentase dua desimal; pembagi nol = "0.00". */
    private static function Persen(int|string $pembilang, int|string $penyebut): string
    {
        $bawah = BigDecimal::of((string) $penyebut);

        return $bawah->isZero() ? '0.00' : (string) BigDecimal::of((string) $pembilang)->multipliedBy(100)->dividedBy($bawah, 2, RoundingMode::HalfUp);
    }
}
