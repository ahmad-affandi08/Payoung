<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Aksi;

use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pengelola\Tenant\Enum\JenisTugasTenant;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\Tenant\Layanan\PengumpulFiturTenant;
use App\Domain\Pengelola\Tenant\Layanan\PenilaiKesehatanTenant;
use App\Domain\Pengelola\Tenant\Model\SkorKesehatanTenant;
use App\Domain\Pengelola\Tenant\Model\TugasTenant;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * P-07 PGL-09: menghitung skor kesehatan harian semua tenant yang layak dinilai dan memelihara tugas otomatisnya.
 *
 * - **Layak dinilai**: tenant `Aktif` tanpa penanda `Uji`/`Demo`/`Internal` (dikecualikan dari metrik bisnis) dengan
 *   langganan `Trial`, `Aktif`, `Tertunggak`, atau `Gratis`. Ditangguhkan, berhenti, dan ditutup dilewati.
 * - Data dibaca lintas tenant lewat `KonteksPengelola::JalankanLintasTenant` (satu baris audit per proses), per
 *   `UKURAN_KELOMPOK` tenant dengan kueri teragregasi per kelompok, bukan kueri per tenant. Hanya angka agregat yang disimpan.
 * - Tanggal "hari ini" mengikuti zona waktu tenant. Hasil disimpan idempoten per tenant per tanggal.
 * - **Tugas otomatis**: `OnboardingMacet` (umur ≥ 48 jam dan belum pernah bertransaksi) dan `Berisiko` (umur ≥ 14 hari dan
 *   kategori Berisiko). Tugas ditutup otomatis saat kondisinya pulih atau tenant tak layak lagi; tugas yang ditutup
 *   manual tidak dibuka lagi selama `HARI_JEDA_TUGAS` hari. Skor lama dihapus setelah `HARI_SIMPAN_SKOR` hari.
 */
final class HitungSkorKesehatanTenant
{
    public const UKURAN_KELOMPOK = 500;

    public const JAM_ONBOARDING_MACET = 48;

    public const HARI_BERISIKO_MULAI = 14;

    public const HARI_JEDA_TUGAS = 14;

    public const HARI_SIMPAN_SKOR = 90;

    public const HARI_PERANGKAT_OFFLINE = 7;

    public const HARI_TIKET_LAMA = 3;

    /** Status langganan yang dinilai. */
    private const STATUS_DINILAI = [StatusLangganan::Trial, StatusLangganan::Aktif, StatusLangganan::Tertunggak, StatusLangganan::Gratis];

    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PenilaiKesehatanTenant $penilai,
        private readonly PengumpulFiturTenant $fitur,
    ) {}

    /**
     * @return array{Dinilai: int, TugasDibuka: int, TugasDitutup: int}
     */
    public function Jalankan(): array
    {
        $hasil = ['Dinilai' => 0, 'TugasDibuka' => 0, 'TugasDitutup' => 0];
        $statusDinilai = array_map(fn (StatusLangganan $s): string => $s->value, self::STATUS_DINILAI);

        $this->konteks->JalankanLintasTenant('PGL-09: menghitung skor kesehatan harian tenant', function (KonteksPengelola $k) use (&$hasil, $statusDinilai): void {
            Tenant::query()
                ->where('Status', StatusTenant::Aktif->value)
                ->whereNull('Penanda')
                ->whereIn('Id', Langganan::query()->select('IdTenant')->whereIn('Status', $statusDinilai))
                ->with('Langganan:Id,IdTenant,Status')
                ->chunkById(self::UKURAN_KELOMPOK, function ($kelompok) use (&$hasil, $k): void {
                    $sub = $this->ProsesKelompok($k, array_values($kelompok->all()));
                    $hasil['Dinilai'] += $sub['Dinilai'];
                    $hasil['TugasDibuka'] += $sub['TugasDibuka'];
                    $hasil['TugasDitutup'] += $sub['TugasDitutup'];
                });
        });

        // Tenant yang tak layak dinilai lagi (ditangguhkan, berhenti, ditutup, diberi penanda) tidak perlu ditelepon.
        $hasil['TugasDitutup'] += TugasTenant::query()
            ->where('Status', TugasTenant::TERBUKA)
            ->whereNotIn('IdTenant', Tenant::query()
                ->select('Id')
                ->where('Status', StatusTenant::Aktif->value)
                ->whereNull('Penanda')
                ->whereIn('Id', Langganan::query()->select('IdTenant')->whereIn('Status', $statusDinilai)))
            ->update(['Status' => TugasTenant::SELESAI, 'KunciTerbuka' => null, 'SelesaiPada' => now(), 'CaraSelesai' => 'Otomatis']);

        SkorKesehatanTenant::query()->where('Tanggal', '<', now()->subDays(self::HARI_SIMPAN_SKOR)->toDateString())->delete();

        return $hasil;
    }

    /**
     * @param  list<Tenant>  $tenant
     * @return array{Dinilai: int, TugasDibuka: int, TugasDitutup: int}
     */
    private function ProsesKelompok(KonteksPengelola $k, array $tenant): array
    {
        $idTenant = array_map(fn (Tenant $t): int => $t->Id, $tenant);
        $sekarang = CarbonImmutable::now();
        // Rentang tanggal bisnis selebar semua zona waktu; jendela per tenant dipotong di PHP menurut zonanya.
        $dari = $sekarang->subDays(self::HARI_BERISIKO_MULAI + 1)->toDateString();
        $sampai = $sekarang->addDay()->toDateString();

        /** @var array<int, array<string, int>> $perHari */
        $perHari = [];

        foreach ($k->KueriLintas(Penjualan::class)
            ->whereIn('IdTenant', $idTenant)
            ->where('Status', '<>', StatusPenjualan::Void->value)
            ->whereBetween('TanggalBisnis', [$dari, $sampai])
            ->groupBy('IdTenant', 'TanggalBisnis')
            ->get(['IdTenant', 'TanggalBisnis', DB::raw('COUNT(*) AS Jumlah')]) as $baris) {
            $perHari[(int) $baris->getAttribute('IdTenant')][CarbonImmutable::parse($baris->getAttribute('TanggalBisnis'))->toDateString()] = (int) $baris->getAttribute('Jumlah');
        }

        $pernah = $this->Himpun($k->KueriLintas(Penjualan::class)->whereIn('IdTenant', $idTenant)->where('Status', '<>', StatusPenjualan::Void->value));
        $fiturPerTenant = $this->fitur->Kumpulkan($k, $idTenant, $sekarang);
        $batasOffline = $sekarang->subDays(self::HARI_PERANGKAT_OFFLINE);
        $offline = $this->HitungPer($k->KueriLintas(Perangkat::class)
            ->whereIn('IdTenant', $idTenant)
            ->whereNull('DicabutPada')
            ->whereNotNull('DiaktifkanPada')
            ->where(fn ($q) => $q->where('TerakhirAktifPada', '<', $batasOffline)->orWhere(fn ($n) => $n->whereNull('TerakhirAktifPada')->where('DiaktifkanPada', '<', $batasOffline))));
        $tiket = $this->HitungPer($k->KueriLintas(TiketDukungan::class)
            ->whereIn('IdTenant', $idTenant)
            ->whereIn('Status', [StatusTiketDukungan::Baru->value, StatusTiketDukungan::Ditangani->value, StatusTiketDukungan::MenungguPelanggan->value])
            ->where('DibuatPada', '<', $sekarang->subDays(self::HARI_TIKET_LAMA)));

        $terbuka = TugasTenant::query()->whereIn('IdTenant', $idTenant)->where('Status', TugasTenant::TERBUKA)->get()->groupBy('IdTenant');
        $selesaiManual = TugasTenant::query()
            ->whereIn('IdTenant', $idTenant)
            ->where('CaraSelesai', 'Manual')
            ->where('SelesaiPada', '>=', $sekarang->subDays(self::HARI_JEDA_TUGAS))
            ->get(['IdTenant', 'Jenis'])
            ->map(fn (TugasTenant $t): string => $t->IdTenant.'|'.$t->Jenis->value)
            ->all();

        $hasil = ['Dinilai' => 0, 'TugasDibuka' => 0, 'TugasDitutup' => 0];

        foreach ($tenant as $t) {
            $zona = CarbonImmutable::now($t->ZonaWaktu);
            // Selisih hari dihitung antar tanggal kalender (keduanya tengah malam UTC), bukan antar waktu berbeda zona.
            $hariIni = CarbonImmutable::parse($zona->toDateString(), 'UTC');
            $hari = $perHari[$t->Id] ?? [];
            $umurHari = (int) $t->DibuatPada->diffInDays($sekarang, false);
            $umurJam = (int) $t->DibuatPada->diffInHours($sekarang, false);
            $status = $t->Langganan?->Status;
            $tertunggak = $status === StatusLangganan::Tertunggak;
            $bertransaksi = isset($pernah[$t->Id]);
            $hariAktif = 0;
            $ini = 0;
            $lalu = 0;

            foreach ($hari as $tanggal => $jumlah) {
                $selisih = (int) CarbonImmutable::parse($tanggal, 'UTC')->diffInDays($hariIni, false);

                if ($selisih >= 0 && $selisih < PenilaiKesehatanTenant::HARI_PENGUKURAN) {
                    $hariAktif++;
                }

                if ($selisih >= 0 && $selisih < 7) {
                    $ini += $jumlah;
                } elseif ($selisih >= 7 && $selisih < PenilaiKesehatanTenant::HARI_PENGUKURAN) {
                    $lalu += $jumlah;
                }
            }

            $nilai = $this->penilai->Nilai([
                'HariAktif' => $hariAktif,
                'TransaksiMingguIni' => $ini,
                'TransaksiMingguLalu' => $lalu,
                'FiturDipakai' => count($fiturPerTenant[$t->Id] ?? []),
                'PerangkatLamaOffline' => $offline[$t->Id] ?? 0,
                'TiketTerbukaLama' => $tiket[$t->Id] ?? 0,
                'Tertunggak' => $tertunggak,
                'UmurHari' => max(0, $umurHari),
            ]);

            SkorKesehatanTenant::query()->updateOrCreate(
                ['IdTenant' => $t->Id, 'Tanggal' => $hariIni->toDateString()],
                ['Skor' => $nilai['Skor'], 'Kategori' => $nilai['Kategori'], 'Faktor' => $nilai['Faktor']],
            );
            $hasil['Dinilai']++;

            $perlu = [];

            if ($umurJam >= self::JAM_ONBOARDING_MACET && ! $bertransaksi) {
                $perlu[JenisTugasTenant::OnboardingMacet->value] = "Belum bertransaksi {$umurHari} hari setelah mendaftar. Hubungi untuk bantuan onboarding.";
            }

            if ($umurHari >= self::HARI_BERISIKO_MULAI && $nilai['Kategori'] === KategoriKesehatan::Berisiko && $status !== StatusLangganan::Gratis) {
                $perlu[JenisTugasTenant::Berisiko->value] = "Skor kesehatan {$nilai['Skor']} (berisiko). Hubungi sebelum tenant berhenti berlangganan.";
            }

            foreach ($terbuka->get($t->Id) ?? [] as $tugas) {
                if (! isset($perlu[$tugas->Jenis->value])) {
                    $tugas->forceFill(['Status' => TugasTenant::SELESAI, 'KunciTerbuka' => null, 'SelesaiPada' => now(), 'CaraSelesai' => 'Otomatis'])->save();
                    $hasil['TugasDitutup']++;
                }
            }

            $sudahTerbuka = ($terbuka->get($t->Id) ?? collect())->map(fn (TugasTenant $x): string => $x->Jenis->value)->all();

            foreach ($perlu as $jenis => $ringkasan) {
                if (in_array($jenis, $sudahTerbuka, true) || in_array($t->Id.'|'.$jenis, $selesaiManual, true)) {
                    continue;
                }

                TugasTenant::query()->create(['IdTenant' => $t->Id, 'Jenis' => $jenis, 'Status' => TugasTenant::TERBUKA, 'KunciTerbuka' => 1, 'Ringkasan' => $ringkasan]);
                $hasil['TugasDibuka']++;
            }
        }

        return $hasil;
    }

    /**
     * IdTenant yang punya sedikitnya satu baris (kueri sudah dibatasi ke kelompok tenant).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $kueri
     * @return array<int, true>
     */
    private function Himpun($kueri): array
    {
        $hasil = [];

        foreach ($kueri->distinct()->pluck('IdTenant') as $id) {
            $hasil[(int) $id] = true;
        }

        return $hasil;
    }

    /**
     * Jumlah baris per IdTenant.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $kueri
     * @return array<int, int>
     */
    private function HitungPer($kueri): array
    {
        $hasil = [];

        foreach ($kueri->groupBy('IdTenant')->get(['IdTenant', DB::raw('COUNT(*) AS Jumlah')]) as $baris) {
            $hasil[(int) $baris->getAttribute('IdTenant')] = (int) $baris->getAttribute('Jumlah');
        }

        return $hasil;
    }
}
