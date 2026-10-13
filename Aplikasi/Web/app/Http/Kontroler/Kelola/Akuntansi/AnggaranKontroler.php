<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Aksi\SalinAnggaranTahunLalu;
use App\Domain\Akuntansi\Aksi\SimpanAnggaran;
use App\Domain\Akuntansi\Data\SaringLaporanKeuangan;
use App\Domain\Akuntansi\Kueri\AnggaranVsRealisasi;
use App\Domain\Akuntansi\Kueri\DaftarAnggaran;
use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Http\Permintaan\Kelola\Akuntansi\SimpanAnggaranPermintaan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Anggaran vs realisasi (F8, FIN-11): laporan (izin `laporan.keuangan.lihat`), susun & salin anggaran
 * (`akuntansi.kelola`), ekspor D-43. Cakupan anggaran = satu outlet atau seluruh usaha (outlet kosong). Pelaku berbatas
 * outlet tidak punya cakupan seluruh usaha: tanpa pilihan, outlet pertama aksesnya dipakai.
 */
final class AnggaranKontroler extends DasarAkuntansiKontroler
{
    public function Laporan(Request $permintaan, AnggaranVsRealisasi $kueri): Response
    {
        [$tahun, $dari, $sampai, $outlet] = $this->BacaSaring($permintaan);
        $saring = $this->BuatSaring($tahun, $dari, $sampai, $outlet);

        return Inertia::render('Kelola/Akuntansi/Anggaran/Laporan', [
            'Saring' => ['Tahun' => $tahun, 'BulanDari' => $dari, 'BulanSampai' => $sampai, 'Outlet' => $outlet],
            'Laporan' => $kueri->Ambil($tahun, $dari, $sampai, $saring),
            'OpsiOutlet' => $this->AmbilOpsiOutlet(),
            'SeluruhUsahaBoleh' => $this->IdOutletBoleh() === null,
            'Izin' => ['Kelola' => $this->CekIzinKelola()],
        ]);
    }

    public function Ekspor(Request $permintaan, AnggaranVsRealisasi $kueri): SymfonyResponse
    {
        [$tahun, $dari, $sampai, $outlet] = $this->BacaSaring($permintaan);
        $saring = $this->BuatSaring($tahun, $dari, $sampai, $outlet);
        $laporan = $kueri->Ambil($tahun, $dari, $sampai, $saring);
        $namaOutlet = '';

        foreach ($this->AmbilOpsiOutlet() as $o) {
            if ($o['Uuid'] === $outlet) {
                $namaOutlet = $o['Nama'];
            }
        }

        $uang = static fn (string $judul): KolomLaporan => new KolomLaporan($judul, JenisKolom::Uang, 22);
        $periode = $laporan['Periode'];
        $ringkasan = [];

        foreach (['LabaKotor' => 'Laba kotor', 'LabaBersih' => 'Laba bersih'] as $kunci => $label) {
            $ringkasan[] = new ItemRingkasan("{$label} (anggaran)", $laporan['Ringkasan'][$kunci]['Anggaran'], JenisKolom::Uang);
            $ringkasan[] = new ItemRingkasan("{$label} (realisasi)", $laporan['Ringkasan'][$kunci]['Realisasi'], JenisKolom::Uang);
        }

        return $this->SajikanLaporan(
            $permintaan,
            'Laporan Keuangan | Anggaran vs Realisasi',
            "anggaran-vs-realisasi-{$tahun}-".sprintf('%02d-%02d', $dari, $sampai),
            [new KolomLaporan('Kode', JenisKolom::Teks, 12), new KolomLaporan('Keterangan', JenisKolom::Teks, 40), $uang('Anggaran'), $uang('Realisasi'), $uang('Selisih'), new KolomLaporan('Capaian (%)', JenisKolom::Teks, 14), new KolomLaporan('Status', JenisKolom::Teks, 14)],
            array_map(fn (array $b): array => [
                is_string($b['Kode']) ? $b['Kode'] : '',
                (string) $b['Label'],
                is_string($b['Anggaran']) ? $b['Anggaran'] : '',
                is_string($b['Realisasi']) ? $b['Realisasi'] : '',
                is_string($b['Selisih']) ? $b['Selisih'] : '',
                is_string($b['Capaian']) ? str_replace('.', ',', $b['Capaian']) : '',
                is_string($b['Status']) ? $b['Status'] : '',
            ], array_values(array_filter($laporan['Baris'], fn (array $b): bool => $b['Jenis'] !== 'Kepala'))),
            [['Periode', $this->LabelPeriode(CarbonImmutable::parse($periode['Dari']), CarbonImmutable::parse($periode['Sampai']))], ['Anggaran', $outlet === '' ? 'Seluruh usaha' : $namaOutlet]],
            $ringkasan,
            $this->LabelCakupan($namaOutlet),
        );
    }

    public function Susun(Request $permintaan, DaftarAnggaran $daftar): Response
    {
        $tahun = $this->BacaTahun($permintaan);
        $uuidOutlet = $this->BacaOutlet($permintaan);
        $idOutlet = $uuidOutlet === '' ? null : $this->CariOutlet($uuidOutlet)->Id;

        return Inertia::render('Kelola/Akuntansi/Anggaran/Susun', [
            'Tahun' => $tahun,
            'Outlet' => $uuidOutlet,
            'OpsiOutlet' => $this->AmbilOpsiOutlet(),
            'SeluruhUsahaBoleh' => $this->IdOutletBoleh() === null,
            'Akun' => $daftar->Ambil($tahun, $idOutlet),
        ]);
    }

    public function Simpan(SimpanAnggaranPermintaan $permintaan, SimpanAnggaran $simpan): RedirectResponse
    {
        $uuidOutlet = $permintaan->AmbilUuidOutlet();
        $idOutlet = $uuidOutlet === null ? null : $this->CariOutlet($uuidOutlet)->Id;
        $data = $permintaan->AmbilData($idOutlet, $this->IdOutletBoleh());
        $hasil = $simpan->Jalankan($data);
        $berubah = $hasil['Dibuat'] + $hasil['Diubah'] + $hasil['Dihapus'];

        return to_route('kelola.akuntansi.anggaran.susun', ['tahun' => $data->tahun, 'outlet' => $uuidOutlet ?? ''])
            ->with('Kilat', $berubah === 0 ? 'Tidak ada angka anggaran yang berubah.' : "Anggaran {$data->tahun} disimpan ({$berubah} angka bulanan berubah).");
    }

    public function Salin(Request $permintaan, SalinAnggaranTahunLalu $salin): RedirectResponse
    {
        $tahun = $this->BacaTahun($permintaan);
        $uuidOutlet = $this->BacaOutlet($permintaan);
        $idOutlet = $uuidOutlet === '' ? null : $this->CariOutlet($uuidOutlet)->Id;
        $jumlah = $salin->Jalankan($tahun, $idOutlet, $this->IdOutletBoleh());

        return to_route('kelola.akuntansi.anggaran.susun', ['tahun' => $tahun, 'outlet' => $uuidOutlet])
            ->with('Kilat', 'Anggaran '.($tahun - 1)." disalin ke {$tahun} ({$jumlah} angka bulanan). Sesuaikan angkanya lalu simpan.");
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string} tahun, bulan awal, bulan akhir, uuid outlet ('' = seluruh usaha)
     */
    private function BacaSaring(Request $permintaan): array
    {
        $hariIni = CarbonImmutable::today();
        $tahun = $this->BacaTahun($permintaan);
        $bawaanSampai = $tahun === $hariIni->year ? $hariIni->month : 12;
        $dari = $this->BacaBulan($permintaan->query('bulanDari'), 1);
        $sampai = $this->BacaBulan($permintaan->query('bulanSampai'), $bawaanSampai);

        if ($sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        return [$tahun, $dari, $sampai, $this->BacaOutlet($permintaan)];
    }

    private function BacaTahun(Request $permintaan): int
    {
        $tahun = $permintaan->query('tahun');

        return is_string($tahun) && preg_match('/^\d{4}$/', $tahun) === 1 && (int) $tahun >= SimpanAnggaran::TAHUN_MINIMAL && (int) $tahun <= SimpanAnggaran::TAHUN_MAKSIMAL
            ? (int) $tahun
            : CarbonImmutable::today()->year;
    }

    private function BacaBulan(mixed $nilai, int $bawaan): int
    {
        return is_string($nilai) && preg_match('/^\d{1,2}$/', $nilai) === 1 && (int) $nilai >= 1 && (int) $nilai <= 12 ? (int) $nilai : $bawaan;
    }

    /**
     * Uuid outlet pilihan ('' = seluruh usaha). Pelaku berbatas outlet tanpa pilihan memakai outlet aksesnya yang pertama.
     */
    private function BacaOutlet(Request $permintaan): string
    {
        $outlet = $permintaan->query('outlet');

        if (is_string($outlet) && $outlet !== '') {
            return $outlet;
        }

        if ($this->IdOutletBoleh() !== null) {
            return $this->AmbilOpsiOutlet()[0]['Uuid'] ?? '';
        }

        return '';
    }

    private function BuatSaring(int $tahun, int $dari, int $sampai, string $uuidOutlet): SaringLaporanKeuangan
    {
        $awal = CarbonImmutable::parse(sprintf('%04d-%02d-01', $tahun, $dari));
        $akhir = CarbonImmutable::parse(sprintf('%04d-%02d-01', $tahun, $sampai))->endOfMonth();

        return new SaringLaporanKeuangan(
            $awal->toDateString(),
            $akhir->toDateString(),
            $uuidOutlet === '' ? null : $this->CariOutlet($uuidOutlet)->Id,
            $this->IdOutletBoleh(),
        );
    }
}
