<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\AnalitikSitus\Kueri;

use App\Domain\Pengelola\AnalitikSitus\Data\RentangAnalitik;
use App\Domain\Pengelola\AnalitikSitus\Layanan\KlienGoogleAnalytics;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Laporan Google Analytics 4 untuk konsol Pengelola (D-215): angka yang sama dengan yang tersedia di Google Analytics,
 * disusun per tab (ringkasan, real-time, akuisisi, halaman, event, pengguna, teknologi). Semua laporan berbentuk
 * `{Laporan, Kartu[], Tabel[]}` sehingga halaman cukup satu komponen generik. Tidak ada hitungan di sini: angka dikirim
 * apa adanya dari Google (rasio sebagai pecahan, durasi dalam detik) dan diformat di tampilan. Hasil disimpan sebentar di
 * cache karena kuota Data API terbatas dan datanya sendiri baru stabil setelah beberapa jam.
 *
 * @phpstan-type Kolom array{Kunci: string, Label: string, Jenis: string}
 * @phpstan-type Tabel array{Id: string, Judul: string, Kolom: list<Kolom>, Baris: list<array<string, string>>}
 * @phpstan-type Kartu array{Kunci: string, Label: string, Jenis: string, Nilai: string, Pembanding: string|null}
 * @phpstan-type Hasil array{Laporan: string, Kartu: list<Kartu>, Tabel: list<Tabel>}
 */
final class LaporanAnalitikSitus
{
    /** @var list<string> */
    public const DAFTAR_LAPORAN = ['ringkasan', 'realtime', 'akuisisi', 'halaman', 'event', 'pengguna', 'teknologi'];

    private const DETIK_CACHE = 600;

    private const DETIK_CACHE_REALTIME = 20;

    private const BATAS_BARIS = 50;

    /**
     * Nama metrik GA4 → [kunci tampilan, label, jenis]. Jenis: Bilangan, Persen (pecahan 0–1), Durasi (detik), Desimal.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const METRIK = [
        'activeUsers' => ['PenggunaAktif', 'Pengguna aktif', 'Bilangan'],
        'newUsers' => ['PenggunaBaru', 'Pengguna baru', 'Bilangan'],
        'totalUsers' => ['TotalPengguna', 'Total pengguna', 'Bilangan'],
        'sessions' => ['Sesi', 'Sesi', 'Bilangan'],
        'engagedSessions' => ['SesiTerlibat', 'Sesi terlibat', 'Bilangan'],
        'engagementRate' => ['TingkatKeterlibatan', 'Tingkat keterlibatan', 'Persen'],
        'bounceRate' => ['TingkatPentalan', 'Tingkat pentalan', 'Persen'],
        'averageSessionDuration' => ['RataRataDurasiSesi', 'Rata-rata durasi sesi', 'Durasi'],
        'userEngagementDuration' => ['DurasiKeterlibatan', 'Durasi keterlibatan', 'Durasi'],
        'screenPageViews' => ['TampilanHalaman', 'Tampilan halaman', 'Bilangan'],
        'screenPageViewsPerSession' => ['HalamanPerSesi', 'Halaman per sesi', 'Desimal'],
        'eventCount' => ['JumlahEvent', 'Jumlah event', 'Bilangan'],
        'eventCountPerUser' => ['EventPerPengguna', 'Event per pengguna', 'Desimal'],
        'keyEvents' => ['EventKunci', 'Event kunci', 'Bilangan'],
    ];

    /**
     * Nama dimensi GA4 → [kunci tampilan, label].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DIMENSI = [
        'date' => ['Tanggal', 'Tanggal'],
        'minutesAgo' => ['MenitLalu', 'Menit lalu'],
        'sessionDefaultChannelGroup' => ['Saluran', 'Saluran'],
        'sessionSourceMedium' => ['SumberMedium', 'Sumber / medium'],
        'sessionCampaignName' => ['Kampanye', 'Kampanye'],
        'pagePath' => ['Halaman', 'Halaman'],
        'pageTitle' => ['JudulHalaman', 'Judul halaman'],
        'landingPage' => ['HalamanLanding', 'Halaman landing'],
        'unifiedScreenName' => ['LayarHalaman', 'Halaman / layar'],
        'eventName' => ['NamaEvent', 'Nama event'],
        'country' => ['Negara', 'Negara'],
        'city' => ['Kota', 'Kota'],
        'language' => ['Bahasa', 'Bahasa'],
        'newVsReturning' => ['JenisPengunjung', 'Pengunjung'],
        'deviceCategory' => ['Perangkat', 'Perangkat'],
        'operatingSystem' => ['SistemOperasi', 'Sistem operasi'],
        'browser' => ['Peramban', 'Peramban'],
        'screenResolution' => ['ResolusiLayar', 'Resolusi layar'],
        'platform' => ['Platform', 'Platform'],
    ];

    /**
     * @return Hasil
     */
    public function Ambil(KlienGoogleAnalytics $klien, string $laporan, RentangAnalitik $rentang, bool $segarkan = false): array
    {
        if (! in_array($laporan, self::DAFTAR_LAPORAN, true)) {
            throw new InvalidArgumentException("Laporan tidak dikenal: {$laporan}");
        }

        $kunci = "ga-laporan:{$klien->AmbilIdProperti()}:{$laporan}:{$rentang->dari->toDateString()}:{$rentang->sampai->toDateString()}";

        if ($segarkan) {
            Cache::forget($kunci);
        }

        /** @var Hasil $hasil */
        $hasil = Cache::remember($kunci, $laporan === 'realtime' ? self::DETIK_CACHE_REALTIME : self::DETIK_CACHE, fn (): array => match ($laporan) {
            'ringkasan' => $this->Ringkasan($klien, $rentang),
            'realtime' => $this->Realtime($klien),
            'akuisisi' => $this->Akuisisi($klien, $rentang),
            'halaman' => $this->Halaman($klien, $rentang),
            'event' => $this->Event($klien, $rentang),
            'pengguna' => $this->Pengguna($klien, $rentang),
            default => $this->Teknologi($klien, $rentang),
        });

        return $hasil;
    }

    /**
     * @return Hasil
     */
    private function Ringkasan(KlienGoogleAnalytics $klien, RentangAnalitik $rentang): array
    {
        $metrik = ['activeUsers', 'newUsers', 'sessions', 'engagedSessions', 'engagementRate', 'bounceRate', 'averageSessionDuration', 'screenPageViews', 'eventCount', 'keyEvents'];
        $harian = ['activeUsers', 'sessions', 'screenPageViews', 'keyEvents'];

        $laporan = $klien->LaporanBatch([
            $this->Permintaan([], $metrik, $rentang),
            $this->Permintaan([], $metrik, $rentang->Sebelumnya()),
            $this->Permintaan(['date'], $harian, $rentang, urutDimensi: 'date', batas: RentangAnalitik::MAKS_HARI),
        ]);

        $sekarang = $laporan[0] ?? [];
        $sebelum = $laporan[1] ?? [];
        $kartu = [];

        foreach ($metrik as $indeks => $nama) {
            [$kunci, $label, $jenis] = self::METRIK[$nama];
            $kartu[] = [
                'Kunci' => $kunci,
                'Label' => $label,
                'Jenis' => $jenis,
                'Nilai' => self::NilaiAngka(self::Nilai($this->BarisPertama($sekarang), 'metricValues', $indeks)),
                'Pembanding' => self::NilaiAngka(self::Nilai($this->BarisPertama($sebelum), 'metricValues', $indeks)),
            ];
        }

        return [
            'Laporan' => 'ringkasan',
            'Kartu' => $kartu,
            'Tabel' => [$this->SusunTabel('harian', 'Tren harian', ['date'], $harian, $laporan[2] ?? [])],
        ];
    }

    /**
     * @return Hasil
     */
    private function Realtime(KlienGoogleAnalytics $klien): array
    {
        $tigaPuluh = $klien->Realtime(['metrics' => [['name' => 'activeUsers']]]);
        $lima = $klien->Realtime([
            'metrics' => [['name' => 'activeUsers']],
            'minuteRanges' => [['startMinutesAgo' => 4, 'endMinutesAgo' => 0]],
        ]);

        $kartu = [
            ['Kunci' => 'PenggunaAktif30Menit', 'Label' => 'Pengguna aktif 30 menit terakhir', 'Jenis' => 'Bilangan', 'Nilai' => self::NilaiAngka(self::Nilai($this->BarisPertama($tigaPuluh), 'metricValues', 0)), 'Pembanding' => null],
            ['Kunci' => 'PenggunaAktif5Menit', 'Label' => 'Pengguna aktif 5 menit terakhir', 'Jenis' => 'Bilangan', 'Nilai' => self::NilaiAngka(self::Nilai($this->BarisPertama($lima), 'metricValues', 0)), 'Pembanding' => null],
        ];

        $spesifikasi = [
            ['per-menit', 'Pengguna aktif per menit', 'minutesAgo', 'activeUsers', 30],
            ['negara', 'Negara', 'country', 'activeUsers', 10],
            ['halaman', 'Halaman yang sedang dilihat', 'unifiedScreenName', 'activeUsers', 10],
            ['perangkat', 'Perangkat', 'deviceCategory', 'activeUsers', 10],
            ['event', 'Event 30 menit terakhir', 'eventName', 'eventCount', 10],
        ];

        $tabel = [];

        foreach ($spesifikasi as [$id, $judul, $dimensi, $metrik, $batas]) {
            $badan = [
                'dimensions' => [['name' => $dimensi]],
                'metrics' => [['name' => $metrik]],
                'limit' => $batas,
                'orderBys' => $dimensi === 'minutesAgo'
                    ? [['dimension' => ['dimensionName' => 'minutesAgo'], 'desc' => false]]
                    : [['metric' => ['metricName' => $metrik], 'desc' => true]],
            ];
            $tabel[] = $this->SusunTabel($id, $judul, [$dimensi], [$metrik], $klien->Realtime($badan));
        }

        return ['Laporan' => 'realtime', 'Kartu' => $kartu, 'Tabel' => $tabel];
    }

    /**
     * @return Hasil
     */
    private function Akuisisi(KlienGoogleAnalytics $klien, RentangAnalitik $rentang): array
    {
        $metrik = ['sessions', 'totalUsers', 'newUsers', 'engagedSessions', 'engagementRate', 'keyEvents'];

        return $this->LaporanTabel($klien, 'akuisisi', $rentang, [
            ['saluran', 'Saluran akuisisi', ['sessionDefaultChannelGroup'], $metrik, 'sessions'],
            ['sumber-medium', 'Sumber / medium', ['sessionSourceMedium'], $metrik, 'sessions'],
            ['kampanye', 'Kampanye', ['sessionCampaignName'], $metrik, 'sessions'],
        ]);
    }

    /**
     * @return Hasil
     */
    private function Halaman(KlienGoogleAnalytics $klien, RentangAnalitik $rentang): array
    {
        return $this->LaporanTabel($klien, 'halaman', $rentang, [
            ['halaman', 'Halaman teratas', ['pagePath', 'pageTitle'], ['screenPageViews', 'activeUsers', 'userEngagementDuration', 'eventCount', 'keyEvents'], 'screenPageViews'],
            ['landing', 'Halaman landing', ['landingPage'], ['sessions', 'newUsers', 'engagedSessions', 'engagementRate', 'keyEvents'], 'sessions'],
        ]);
    }

    /**
     * @return Hasil
     */
    private function Event(KlienGoogleAnalytics $klien, RentangAnalitik $rentang): array
    {
        return $this->LaporanTabel($klien, 'event', $rentang, [
            ['event', 'Event', ['eventName'], ['eventCount', 'totalUsers', 'eventCountPerUser', 'keyEvents'], 'eventCount'],
        ]);
    }

    /**
     * @return Hasil
     */
    private function Pengguna(KlienGoogleAnalytics $klien, RentangAnalitik $rentang): array
    {
        $metrik = ['totalUsers', 'newUsers', 'sessions', 'engagementRate', 'keyEvents'];

        return $this->LaporanTabel($klien, 'pengguna', $rentang, [
            ['negara', 'Negara', ['country'], $metrik, 'totalUsers'],
            ['kota', 'Kota', ['city'], $metrik, 'totalUsers'],
            ['bahasa', 'Bahasa', ['language'], $metrik, 'totalUsers'],
            ['baru-kembali', 'Pengunjung baru dan kembali', ['newVsReturning'], $metrik, 'totalUsers'],
        ]);
    }

    /**
     * @return Hasil
     */
    private function Teknologi(KlienGoogleAnalytics $klien, RentangAnalitik $rentang): array
    {
        $metrik = ['totalUsers', 'sessions', 'engagementRate', 'keyEvents'];

        return $this->LaporanTabel($klien, 'teknologi', $rentang, [
            ['perangkat', 'Kategori perangkat', ['deviceCategory'], $metrik, 'totalUsers'],
            ['sistem-operasi', 'Sistem operasi', ['operatingSystem'], $metrik, 'totalUsers'],
            ['peramban', 'Peramban', ['browser'], $metrik, 'totalUsers'],
            ['resolusi', 'Resolusi layar', ['screenResolution'], $metrik, 'totalUsers'],
            ['platform', 'Platform', ['platform'], $metrik, 'totalUsers'],
        ]);
    }

    /**
     * Satu panggilan batch (maks. lima laporan) → satu tabel per spesifikasi.
     *
     * @param  list<array{0: string, 1: string, 2: list<string>, 3: list<string>, 4: string}>  $spesifikasi
     * @return Hasil
     */
    private function LaporanTabel(KlienGoogleAnalytics $klien, string $laporan, RentangAnalitik $rentang, array $spesifikasi): array
    {
        $respons = $klien->LaporanBatch(array_map(
            fn (array $s): array => $this->Permintaan($s[2], $s[3], $rentang, urutMetrik: $s[4]),
            $spesifikasi,
        ));

        $tabel = [];

        foreach ($spesifikasi as $indeks => [$id, $judul, $dimensi, $metrik]) {
            $tabel[] = $this->SusunTabel($id, $judul, $dimensi, $metrik, $respons[$indeks] ?? []);
        }

        return ['Laporan' => $laporan, 'Kartu' => [], 'Tabel' => $tabel];
    }

    /**
     * @param  list<string>  $dimensi
     * @param  list<string>  $metrik
     * @return array<string, mixed>
     */
    private function Permintaan(array $dimensi, array $metrik, RentangAnalitik $rentang, ?string $urutMetrik = null, ?string $urutDimensi = null, int $batas = self::BATAS_BARIS): array
    {
        $badan = [
            'dateRanges' => [$rentang->UntukGoogle()],
            'metrics' => array_map(fn (string $nama): array => ['name' => $nama], $metrik),
        ];

        if ($dimensi !== []) {
            $badan['dimensions'] = array_map(fn (string $nama): array => ['name' => $nama], $dimensi);
            $badan['limit'] = $batas;
            $badan['orderBys'] = $urutDimensi !== null
                ? [['dimension' => ['dimensionName' => $urutDimensi], 'desc' => false]]
                : [['metric' => ['metricName' => $urutMetrik ?? $metrik[0]], 'desc' => true]];
        }

        return $badan;
    }

    /**
     * @param  list<string>  $dimensi
     * @param  list<string>  $metrik
     * @param  array<string, mixed>  $respons
     * @return Tabel
     */
    private function SusunTabel(string $id, string $judul, array $dimensi, array $metrik, array $respons): array
    {
        $kolom = [];

        foreach ($dimensi as $nama) {
            [$kunci, $label] = self::DIMENSI[$nama];
            $kolom[] = ['Kunci' => $kunci, 'Label' => $label, 'Jenis' => 'Teks'];
        }

        foreach ($metrik as $nama) {
            [$kunci, $label, $jenis] = self::METRIK[$nama];
            $kolom[] = ['Kunci' => $kunci, 'Label' => $label, 'Jenis' => $jenis];
        }

        $baris = [];
        $rows = $respons['rows'] ?? [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $isi = [];

            foreach ($dimensi as $i => $nama) {
                $isi[self::DIMENSI[$nama][0]] = self::UbahDimensi($nama, self::Nilai($row, 'dimensionValues', $i));
            }

            foreach ($metrik as $j => $nama) {
                $isi[self::METRIK[$nama][0]] = self::NilaiAngka(self::Nilai($row, 'metricValues', $j));
            }

            $baris[] = $isi;
        }

        return ['Id' => $id, 'Judul' => $judul, 'Kolom' => $kolom, 'Baris' => $baris];
    }

    /**
     * @param  array<string, mixed>  $respons
     */
    private function BarisPertama(array $respons): mixed
    {
        $rows = $respons['rows'] ?? null;

        return is_array($rows) ? ($rows[0] ?? null) : null;
    }

    private static function Nilai(mixed $baris, string $kelompok, int $indeks): string
    {
        if (! is_array($baris) || ! is_array($baris[$kelompok] ?? null)) {
            return '';
        }

        $sel = $baris[$kelompok][$indeks] ?? null;
        $nilai = is_array($sel) ? ($sel['value'] ?? '') : '';

        return is_scalar($nilai) ? (string) $nilai : '';
    }

    private static function NilaiAngka(string $nilai): string
    {
        return is_numeric($nilai) ? $nilai : '0';
    }

    private static function UbahDimensi(string $dimensi, string $nilai): string
    {
        if ($nilai === '' || $nilai === '(not set)') {
            return '(tidak diketahui)';
        }

        return match ($dimensi) {
            'date' => preg_match('/^(\d{4})(\d{2})(\d{2})$/', $nilai, $cocok) === 1 ? "{$cocok[1]}-{$cocok[2]}-{$cocok[3]}" : $nilai,
            'newVsReturning' => match ($nilai) {
                'new' => 'Baru',
                'returning' => 'Kembali',
                default => $nilai,
            },
            'deviceCategory' => match ($nilai) {
                'desktop' => 'Desktop',
                'mobile' => 'Seluler',
                'tablet' => 'Tablet',
                default => $nilai,
            },
            default => $nilai,
        };
    }
}
