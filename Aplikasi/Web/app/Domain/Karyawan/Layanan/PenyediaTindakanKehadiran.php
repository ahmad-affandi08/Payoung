<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Layanan;

use App\Domain\Bersama\Tindakan\Data\DataButirTindakan;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Bersama\Tindakan\Data\DataRincianTindakan;
use App\Domain\Bersama\Tindakan\Enum\TingkatTindakan;
use App\Domain\Bersama\Tindakan\Kontrak\PenyediaTindakan;
use App\Domain\Karyawan\Kueri\DaftarPermintaanKoreksi;
use App\Domain\Karyawan\Kueri\DaftarRekapGaji;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\Karyawan;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kotak Tindakan kehadiran (F-18 bagian 5, D-44, D-180, izin `karyawan.lihat`, dibatasi outlet akses):
 * - **absen di luar jadwal** (Perhatian): absen dari aplikasi kasir 7 hari terakhir yang diterima walau tidak sesuai jadwal;
 * - **absen perlu dicek** (Perhatian): absen dari HP 7 hari terakhir yang lokasinya janggal (di luar radius, GPS lemah, titik
 *   outlet belum diatur) tetapi tetap dicatat;
 * - **absen keluar otomatis** (Perhatian, D-250): karyawan lupa absen keluar, jam keluarnya diisi sistem sesuai jam selesai
 *   jadwal. Periksa lalu koreksi jamnya bila perlu, atau tandai sudah dicek;
 * - **permintaan koreksi absensi** (Perhatian): karyawan yang gagal absen meminta manajer mencatat jamnya;
 * - **rekap gaji mingguan siap dibuat** (Perhatian, izin `karyawan.kelola`, D-219): ada karyawan bergaji mingguan dan
 *   tujuh hari sejak rekap mingguan terakhir sudah lewat.
 */
final class PenyediaTindakanKehadiran implements PenyediaTindakan
{
    private const HARI = 7;

    public function __construct(
        private readonly DaftarPermintaanKoreksi $koreksi,
        private readonly DaftarRekapGaji $rekapGaji,
    ) {}

    public function Kumpulkan(DataKonteksTindakan $konteks): array
    {
        $gaji = $konteks->CekIzin('karyawan.kelola') ? [$this->ButirGajiMingguan()] : [];

        if (! $konteks->CekIzin('karyawan.lihat')) {
            return array_values(array_filter($gaji));
        }

        $dari = $konteks->hariIni->subDays(self::HARI)->toDateString();
        $sampai = $konteks->hariIni->toDateString();

        return array_values(array_filter([
            $this->ButirAbsen($konteks, $dari, $sampai, 'DiluarJadwal', 'karyawan.absen-diluar-jadwal', 'Absen di luar jadwal', 'Karyawan absen di kasir padahal tidak sesuai jadwal kerjanya. Periksa, lalu jadwalkan atau koreksi absensinya.', 'Absen masuk tidak sesuai jadwal'),
            $this->ButirAbsen($konteks, $dari, $sampai, 'PerluDicek', 'karyawan.absen-perlu-dicek', 'Absen perlu dicek', 'Karyawan absen dari HP, tetapi lokasinya jauh dari outlet atau sinyal GPS lemah. Absennya tetap tercatat. Periksa lalu tandai sudah dicek.', null, fn (Builder $k): Builder => $k->where('KeluarOtomatis', false)),
            $this->ButirAbsen($konteks, $dari, $sampai, 'KeluarOtomatis', 'karyawan.absen-keluar-otomatis', 'Lupa absen keluar', 'Karyawan lupa absen keluar, jadi sistem menutup absensinya sesuai jam selesai jadwal. Periksa jam keluarnya, koreksi bila ia pulang di jam lain, lalu tandai sudah dicek.', 'Keluar ditutup otomatis', fn (Builder $k): Builder => $k->where('PerluDicek', true)),
            $this->ButirKoreksi($konteks),
            ...$gaji,
        ]));
    }

    private function ButirAbsen(DataKonteksTindakan $konteks, string $dari, string $sampai, string $kolom, string $kunci, string $judul, string $keterangan, ?string $teksTetap, ?Closure $batasi = null): ?DataButirTindakan
    {
        $kueri = fn (): Builder => Absensi::query()
            ->where($kolom, true)
            ->when($batasi !== null, fn (Builder $k) => $batasi($k))
            ->where('TanggalBisnis', '>=', $dari)
            ->when($konteks->idOutletBoleh !== null, fn (Builder $k) => $k->whereIn('IdOutlet', $konteks->idOutletBoleh ?: [0]));
        $jumlah = $kueri()->count();

        if ($jumlah === 0) {
            return null;
        }

        $daftar = $kueri()->orderByDesc('MasukPada')->limit(DataButirTindakan::BATAS_RINCIAN)->get();
        $nama = Karyawan::query()->whereKey($daftar->pluck('IdKaryawan')->all())->pluck('Nama', 'Id');
        $tautan = "/kelola/karyawan/absensi?saring[{$kolom}]=1&saring[TanggalBisnis]={$dari}..{$sampai}";

        return new DataButirTindakan(
            $kunci,
            'Karyawan',
            TingkatTindakan::Perhatian,
            $judul,
            $keterangan,
            $jumlah,
            $tautan,
            'Lihat absensi',
            array_values($daftar->map(fn (Absensi $a): DataRincianTindakan => new DataRincianTindakan(
                $a->Uuid,
                (string) ($nama->get($a->IdKaryawan) ?? 'Karyawan'),
                $teksTetap ?? ($a->CatatanPeriksa ?? 'Lokasi perlu dicek'),
                $a->TanggalBisnis->toDateString(),
                "/kelola/karyawan/absensi?saring[{$kolom}]=1",
            ))->all()),
        );
    }

    private function ButirGajiMingguan(): ?DataButirTindakan
    {
        $usulan = $this->rekapGaji->AmbilUsulanMingguan();

        if ($usulan['JumlahKaryawan'] === 0 || ! $usulan['SudahLewat']) {
            return null;
        }

        return new DataButirTindakan(
            'karyawan.rekap-gaji-mingguan',
            'Karyawan',
            TingkatTindakan::Perhatian,
            'Rekap gaji mingguan siap dibuat',
            "Rekap gaji mingguan {$usulan['LabelUsulan']} untuk {$usulan['JumlahKaryawan']} karyawan bergaji mingguan sudah bisa dibuat. Buat draf, periksa hari masuknya, lalu bayar.",
            1,
            '/kelola/karyawan/gaji',
            'Buka rekap gaji',
        );
    }

    private function ButirKoreksi(DataKonteksTindakan $konteks): ?DataButirTindakan
    {
        $jumlah = $this->koreksi->Hitung($konteks->idOutletBoleh);

        if ($jumlah === 0) {
            return null;
        }

        return new DataButirTindakan(
            'karyawan.permintaan-koreksi',
            'Karyawan',
            TingkatTindakan::Perhatian,
            'Permintaan koreksi absensi',
            'Karyawan gagal absen dan meminta Anda mencatat jam masuk atau pulangnya. Periksa alasannya, catat absensinya, lalu tutup permintaan.',
            $jumlah,
            '/kelola/karyawan/absensi',
            'Lihat permintaan',
            array_values(array_map(fn (array $p): DataRincianTindakan => new DataRincianTindakan(
                $p['Uuid'],
                $p['NamaKaryawan'],
                "Absen {$p['Jenis']} | {$p['Alasan']}",
                substr($p['DiajukanPada'], 0, 10),
                '/kelola/karyawan/absensi',
            ), array_slice($this->koreksi->Ambil($konteks->idOutletBoleh, DataButirTindakan::BATAS_RINCIAN), 0, DataButirTindakan::BATAS_RINCIAN))),
        );
    }

    public function AmbilJenisDokumen(): array
    {
        return [];
    }

    public function SaringDokumen(string $jenisDokumen, array $uuid): array
    {
        return [];
    }
}
