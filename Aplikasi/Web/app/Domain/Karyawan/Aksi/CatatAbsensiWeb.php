<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Data\DataAbsensiWeb;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Enum\StatusWajahKaryawan;
use App\Domain\Karyawan\Kueri\AturanKehadiranTenant;
use App\Domain\Karyawan\Layanan\PencocokWajah;
use App\Domain\Karyawan\Layanan\PenegakJadwalAbsen;
use App\Domain\Karyawan\Layanan\PengukurJarak;
use App\Domain\Karyawan\Layanan\PenutupAbsensiOtomatis;
use App\Domain\Karyawan\Layanan\PenyimpanSwafoto;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\WajahKaryawan;
use App\Domain\Organisasi\Kueri\LokasiAbsensiOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F-18 bagian 4 (D-37, D-180): absen masuk/keluar dari HP pribadi setelah login. Syarat dinilai di server:
 *
 * 1. Karyawan aktif yang ikut absensi, dengan wajah terdaftar. **Ditolak** (tidak ada baris tercatat) bila tidak.
 * 2. Wajah: kemiripan sidik wajah saat ini dengan sidik terdaftar ≥ `AmbilKemiripanWajah`. Selalu diperiksa dan
 *    **menolak** bila tidak cocok (jejak percobaan di log audit tanpa sidiknya). Swafoto disimpan sebagai bukti hanya
 *    bila aturan `SimpanFotoBukti` menyala.
 * 3. Jadwal (D-44): bila aturan `WajibJadwal` aktif, masuk hanya sah dalam jendela jadwal di outlet yang sama
 *    (`PenegakJadwalAbsen`); ditolak dengan `TidakAdaJadwal`/`TerlaluAwal`/`ShiftSudahBerakhir`/`JadwalDiOutletLain`.
 * 4. Lokasi: outlet terdekat yang radiusnya memuat posisi karyawan, di antara outlet utama karyawan & outlet jadwalnya
 *    pada tanggal bisnis hari ini atau kemarin (shift malam). Di luar radius, sinyal GPS lemah, atau titik lokasi outlet
 *    belum diatur **tidak menolak** (GPS di dalam ruko sering meleset): absen tetap tercatat dan ditandai `PerluDicek`
 *    dengan catatan untuk pengelola. Hanya bila aturan `TolakDiLuarRadius` menyala, lokasi yang janggal ditolak.
 *
 * Idempoten per Uuid: masuk dengan Uuid yang sudah tercatat atau keluar untuk absensi yang sudah ditutup mengembalikan
 * absensi itu tanpa mengubah apa pun.
 */
final class CatatAbsensiWeb
{
    public function __construct(
        private readonly LokasiAbsensiOutlet $lokasi,
        private readonly PengukurJarak $pengukur,
        private readonly PencocokWajah $pencocok,
        private readonly PenyimpanSwafoto $swafoto,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatAudit $audit,
        private readonly PenegakJadwalAbsen $jadwal,
        private readonly AturanKehadiranTenant $aturan,
        private readonly PenutupAbsensiOtomatis $penutup,
    ) {}

    public function Masuk(Karyawan $karyawan, DataAbsensiWeb $data): Absensi
    {
        $lama = Absensi::query()->where('Uuid', $data->uuid)->first();

        if ($lama !== null) {
            return $lama->IdKaryawan === $karyawan->Id
                ? $lama
                : throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Muat ulang halaman lalu absen lagi.', 'Uuid');
        }

        $this->PastikanBolehAbsen($karyawan);
        // D-250: catatan kemarin yang lupa keluar ditutup dulu (sesuai jam selesai jadwal), jadi tidak menahan absen hari ini.
        $this->penutup->TutupYangLewat(CarbonImmutable::now(), $karyawan->Id);
        $this->PastikanBelumMasuk($karyawan);

        $sekarang = CarbonImmutable::now();
        $aturan = $this->aturan->Ambil();
        ['Outlet' => $outlet, 'Jarak' => $jarak, 'Catatan' => $catatan] = $this->NilaiLokasi($karyawan, $data, $this->AmbilIdOutletBoleh($karyawan, $sekarang), $aturan['TolakDiLuarRadius']);
        // D-44: jadwal diperiksa sebelum wajah/swafoto diproses, supaya absen yang pasti ditolak tidak membuang kerja.
        $langgar = $this->jadwal->Periksa($karyawan->Id, $outlet['Id'], $sekarang);

        if ($langgar !== null) {
            $this->audit->Catat('absensi.web.ditolak-jadwal', $karyawan, null, ['Kode' => $langgar['Kode'], 'IdOutlet' => $outlet['Id']], $karyawan->IdTenant);

            throw new PelanggaranAturanBisnis($langgar['Kode'], $langgar['Pesan'], 'Jadwal');
        }

        $kemiripan = $this->CocokkanWajah($karyawan, $data);
        $path = $this->SimpanFoto($karyawan, $data, $aturan['SimpanFotoBukti']);

        try {
            return DB::transaction(function () use ($karyawan, $data, $outlet, $jarak, $kemiripan, $path, $sekarang, $catatan): Absensi {
                // Dua kiriman bersamaan (dua tab, ketukan ganda dengan Uuid berbeda) diserialkan lewat kunci baris
                // karyawan, lalu syarat "belum masuk" diperiksa ulang di dalam kunci.
                Karyawan::query()->whereKey($karyawan->Id)->lockForUpdate()->first();
                $lama = Absensi::query()->where('Uuid', $data->uuid)->first();

                if ($lama !== null && $lama->IdKaryawan === $karyawan->Id) {
                    $this->swafoto->Hapus($path);

                    return $lama;
                }

                $this->PastikanBelumMasuk($karyawan);

                return Absensi::query()->create([
                    'Uuid' => $data->uuid,
                    'IdKaryawan' => $karyawan->Id,
                    'IdOutlet' => $outlet['Id'],
                    'IdPerangkat' => null,
                    'TanggalBisnis' => $this->tanggalBisnis->HitungKalender($outlet['Id'], $sekarang)->toDateString(),
                    'MasukPada' => $sekarang->utc(),
                    'PathSwafotoMasuk' => $path,
                    'Sumber' => Absensi::SUMBER_WEB,
                    'LintangMasuk' => $data->lintang,
                    'BujurMasuk' => $data->bujur,
                    'AkurasiMasukMeter' => $data->akurasiMeter,
                    'JarakMasukMeter' => $jarak,
                    'KemiripanWajahMasuk' => (string) $kemiripan,
                    'PerluDicek' => $catatan !== [],
                    'CatatanPeriksa' => $this->GabungCatatan($catatan),
                ]);
            }, 3);
        } catch (Throwable $galat) {
            $this->swafoto->Hapus($path);

            throw $galat;
        }
    }

    public function Keluar(Karyawan $karyawan, DataAbsensiWeb $data): Absensi
    {
        $absensi = Absensi::query()->where('Uuid', $data->uuid)->where('IdKaryawan', $karyawan->Id)->first();

        if ($absensi === null) {
            throw new PelanggaranAturanBisnis('BelumAbsenMasuk', 'Absensi masuk tidak ditemukan. Muat ulang halaman.', 'Absensi');
        }

        if ($absensi->KeluarPada !== null) {
            return $absensi;
        }

        $this->PastikanBolehAbsen($karyawan);
        $aturan = $this->aturan->Ambil();
        ['Jarak' => $jarak, 'Catatan' => $catatan] = $this->NilaiLokasi($karyawan, $data, [$absensi->IdOutlet], $aturan['TolakDiLuarRadius'], true, $absensi->IdOutlet);
        $kemiripan = $this->CocokkanWajah($karyawan, $data);
        $path = $this->SimpanFoto($karyawan, $data, $aturan['SimpanFotoBukti']);

        try {
            return DB::transaction(function () use ($absensi, $data, $jarak, $kemiripan, $path, $catatan): Absensi {
                $terkini = Absensi::query()->lockForUpdate()->findOrFail($absensi->Id);

                if ($terkini->KeluarPada !== null) {
                    $this->swafoto->Hapus($path);

                    return $terkini;
                }

                $terkini->forceFill([
                    'KeluarPada' => now()->utc(),
                    'PathSwafotoKeluar' => $path,
                    'LintangKeluar' => $data->lintang,
                    'BujurKeluar' => $data->bujur,
                    'AkurasiKeluarMeter' => $data->akurasiMeter,
                    'JarakKeluarMeter' => $jarak,
                    'KemiripanWajahKeluar' => (string) $kemiripan,
                    // Tanda masuk dipertahankan; catatan keluar ditambahkan di belakangnya.
                    'PerluDicek' => $terkini->PerluDicek || $catatan !== [],
                    'CatatanPeriksa' => $this->GabungCatatan([...($terkini->CatatanPeriksa === null ? [] : [$terkini->CatatanPeriksa]), ...array_map(fn (string $c): string => "Keluar: {$c}", $catatan)]),
                ])->save();

                return $terkini;
            }, 3);
        } catch (Throwable $galat) {
            $this->swafoto->Hapus($path);

            throw $galat;
        }
    }

    private function PastikanBolehAbsen(Karyawan $karyawan): void
    {
        if ($karyawan->Status !== StatusKaryawan::Aktif) {
            throw new PelanggaranAturanBisnis('KaryawanNonaktif', "{$karyawan->Nama} sudah nonaktif sebagai karyawan.", 'Karyawan');
        }

        if (! $karyawan->IkutAbsensi) {
            throw new PelanggaranAturanBisnis('TidakIkutAbsensi', "{$karyawan->Nama} tidak diwajibkan absen. Pengelola bisa mengubahnya di menu Tim.", 'Karyawan');
        }
    }

    private function PastikanBelumMasuk(Karyawan $karyawan): void
    {
        if (Absensi::query()->where('IdKaryawan', $karyawan->Id)->whereNull('KeluarPada')->exists()) {
            throw new PelanggaranAturanBisnis('SudahAbsenMasuk', 'Anda sudah absen masuk. Absen keluar dulu sebelum masuk lagi.', 'Absensi');
        }
    }

    /**
     * Outlet utama karyawan + outlet jadwalnya pada tanggal kalender hari ini dan kemarin (tanggal kalender outlet utama,
     * bukan tanggal UTC; kemarin untuk shift malam yang melewati tengah malam). Tanpa outlet utama = semua outlet.
     *
     * @return list<int>|null
     */
    private function AmbilIdOutletBoleh(Karyawan $karyawan, CarbonImmutable $sekarang): ?array
    {
        if ($karyawan->IdOutlet === null) {
            return null;
        }

        $hariIni = $this->tanggalBisnis->HitungKalender($karyawan->IdOutlet, $sekarang);
        $jadwal = JadwalKerja::query()
            ->where('IdKaryawan', $karyawan->Id)
            ->whereBetween('Tanggal', [$hariIni->subDay()->toDateString(), $hariIni->toDateString()])
            ->pluck('IdOutlet')
            ->all();

        return array_values(array_unique([$karyawan->IdOutlet, ...array_map('intval', $jadwal)]));
    }

    /**
     * Nilai lokasi absen. Pilih outlet terdekat yang radiusnya memuat posisi karyawan (outlet bisa berdekatan dengan
     * radius berbeda, jadi outlet terdekat belum tentu yang memuatnya). Yang janggal menjadi catatan "perlu dicek",
     * kecuali aturan `TolakDiLuarRadius` menyala: maka ditolak dengan kode galat yang jelas.
     *
     * @param  list<int>|null  $idOutlet
     * @return array{Outlet: array{Id: int, Nama: string}, Jarak: int|null, Catatan: list<string>}
     */
    private function NilaiLokasi(Karyawan $karyawan, DataAbsensiWeb $data, ?array $idOutlet, bool $tolak, bool $keluar = false, ?int $idOutletAsal = null): array
    {
        $daftar = $this->lokasi->Ambil($idOutlet);

        if ($daftar === []) {
            // Titik lokasi outlet belum diatur: tidak ada yang bisa dibuktikan. Absen tetap tercatat di outlet karyawan.
            if ($tolak) {
                throw new PelanggaranAturanBisnis('OutletTanpaLokasi', $keluar
                    ? 'Lokasi outlet tempat Anda absen masuk sudah dihapus. Minta pengelola mencatat jam keluar Anda di koreksi absensi.'
                    : 'Lokasi outlet Anda belum diatur. Minta pengelola mengisi titik lokasi outlet.', 'Lokasi');
            }

            $idPakai = $idOutletAsal ?? $karyawan->IdOutlet ?? ($idOutlet[0] ?? null);

            if ($idPakai === null) {
                throw new PelanggaranAturanBisnis('OutletTidakDiketahui', 'Anda belum ditugaskan ke outlet mana pun. Minta pengelola mengisi outlet utama atau jadwal Anda.', 'Lokasi');
            }

            return [
                'Outlet' => ['Id' => $idPakai, 'Nama' => $this->lokasi->AmbilNama($idPakai) ?? 'Outlet'],
                'Jarak' => null,
                'Catatan' => ['Titik lokasi outlet belum diatur, jarak tidak bisa dinilai'],
            ];
        }

        $terdekat = null;
        $jarakTerdekat = PHP_INT_MAX;
        $terpilih = null;
        $jarakTerpilih = PHP_INT_MAX;

        foreach ($daftar as $outlet) {
            $jarak = $this->pengukur->HitungMeter($outlet['Lintang'], $outlet['Bujur'], $data->lintang, $data->bujur);

            if ($jarak < $jarakTerdekat) {
                [$terdekat, $jarakTerdekat] = [$outlet, $jarak];
            }

            if ($jarak <= $outlet['RadiusMeter'] && $jarak < $jarakTerpilih) {
                [$terpilih, $jarakTerpilih] = [$outlet, $jarak];
            }
        }

        $acuan = $terpilih ?? $terdekat;

        if ($acuan === null) {
            throw new PelanggaranAturanBisnis('OutletTanpaLokasi', 'Lokasi outlet Anda belum diatur. Minta pengelola mengisi titik lokasi outlet.', 'Lokasi');
        }

        $catatan = [];
        $batasAkurasi = max($acuan['RadiusMeter'], (int) config('karyawan.BatasAkurasiMinimalMeter'));

        if ($data->akurasiMeter > $batasAkurasi) {
            if ($tolak) {
                throw new PelanggaranAturanBisnis('AkurasiLokasiRendah', "Sinyal lokasi lemah (±{$data->akurasiMeter} m). Nyalakan GPS, dekati pintu atau jendela, lalu coba lagi.", 'Lokasi');
            }

            $catatan[] = "Sinyal GPS lemah (±{$data->akurasiMeter} m)";
        }

        if ($terpilih === null) {
            $this->audit->Catat('absensi.web.di-luar-radius', $karyawan, null, ['Outlet' => $acuan['Nama'], 'JarakMeter' => $jarakTerdekat, 'RadiusMeter' => $acuan['RadiusMeter']]);

            if ($tolak) {
                throw new PelanggaranAturanBisnis('DiLuarRadius', "Anda berada ±{$jarakTerdekat} m dari {$acuan['Nama']}. Absen hanya bisa dalam radius {$acuan['RadiusMeter']} m dari outlet.", 'Lokasi');
            }

            $catatan[] = "Di luar radius: ±{$jarakTerdekat} m dari {$acuan['Nama']} (batas {$acuan['RadiusMeter']} m)";

            return ['Outlet' => ['Id' => $acuan['Id'], 'Nama' => $acuan['Nama']], 'Jarak' => $jarakTerdekat, 'Catatan' => $catatan];
        }

        return ['Outlet' => ['Id' => $terpilih['Id'], 'Nama' => $terpilih['Nama']], 'Jarak' => $jarakTerpilih, 'Catatan' => $catatan];
    }

    /** Foto bukti hanya disimpan bila aturan menyala; bila menyala, foto wajib ada. */
    private function SimpanFoto(Karyawan $karyawan, DataAbsensiWeb $data, bool $simpan): ?string
    {
        if (! $simpan) {
            return null;
        }

        if ($data->swafoto === null || $data->swafoto === '') {
            throw new PelanggaranAturanBisnis('SwafotoWajib', 'Foto wajah wajib dikirim bersama absen. Coba lagi.', 'Swafoto');
        }

        return $this->swafoto->Simpan($karyawan->IdTenant, $data->swafoto);
    }

    /**
     * @param  list<string>  $catatan
     */
    private function GabungCatatan(array $catatan): ?string
    {
        return $catatan === [] ? null : mb_substr(implode('; ', $catatan), 0, 200);
    }

    private function CocokkanWajah(Karyawan $karyawan, DataAbsensiWeb $data): BigDecimal
    {
        $wajah = WajahKaryawan::query()->where('IdKaryawan', $karyawan->Id)->where('Status', StatusWajahKaryawan::Disetujui->value)->first();

        if ($wajah === null) {
            throw new PelanggaranAturanBisnis('WajahBelumDisetujui', 'Wajah Anda belum terdaftar atau belum disetujui pengelola.', 'Wajah');
        }

        $kemiripan = $this->pencocok->HitungKemiripan($this->pencocok->Validasi($data->sidikWajah), $wajah->SidikWajah);

        if (! $this->pencocok->CekCocok($kemiripan)) {
            $this->audit->Catat('absensi.web.wajah-tidak-cocok', $karyawan, null, ['Kemiripan' => (string) $kemiripan]);

            throw new PelanggaranAturanBisnis('WajahTidakCocok', 'Wajah tidak cocok dengan wajah terdaftar. Hadapkan wajah lurus ke kamera di tempat terang, lalu coba lagi.', 'Wajah');
        }

        return $kemiripan;
    }
}
