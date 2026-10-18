<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Enum\StatusWajahKaryawan;
use App\Domain\Karyawan\Layanan\PencocokWajah;
use App\Domain\Karyawan\Layanan\PenyimpanSwafoto;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\WajahKaryawan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F-18 bagian 4 (D-37): karyawan mendaftarkan wajah dari halaman Absen setelah login. Wajib persetujuan pemrosesan data biometrik
 * (UU PDP Pasal 4 & 20), sejumlah `config('karyawan.JumlahFotoDaftarWajah')` foto + sidik wajah berpanjang sama. Sejak D-45
 * wajah yang didaftarkan **langsung Disetujui** (tanpa menunggu pengelola): karyawan bisa absen seketika. Pengelola tetap
 * melihat foto dan boleh menolak/mengatur ulang bila fotonya tidak layak. Daftar ulang hanya bila belum ada wajah
 * Menunggu/Disetujui; pendaftaran yang pernah ditolak dibersihkan.
 */
final class DaftarkanWajahKaryawan
{
    public function __construct(
        private readonly PencocokWajah $pencocok,
        private readonly PenyimpanSwafoto $penyimpan,
        private readonly HapusWajahKaryawan $hapus,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<mixed>  $daftarSidik
     * @param  list<mixed>  $daftarFoto  JPEG base64
     */
    public function Jalankan(Karyawan $karyawan, array $daftarSidik, array $daftarFoto, bool $setujuPemrosesan): WajahKaryawan
    {
        if ($karyawan->Status !== StatusKaryawan::Aktif) {
            throw new PelanggaranAturanBisnis('KaryawanNonaktif', "{$karyawan->Nama} sudah nonaktif sebagai karyawan.", 'Karyawan');
        }

        if (! $setujuPemrosesan) {
            throw new PelanggaranAturanBisnis('PersetujuanWajib', 'Centang persetujuan pemrosesan data wajah untuk melanjutkan.', 'Persetujuan');
        }

        $jumlah = (int) config('karyawan.JumlahFotoDaftarWajah');

        if (count($daftarSidik) !== $jumlah || count($daftarFoto) !== $jumlah) {
            throw new PelanggaranAturanBisnis('JumlahFotoWajah', "Ambil {$jumlah} foto wajah berturut-turut.", 'Foto');
        }

        $sidik = array_map(fn (mixed $s): array => $this->pencocok->Validasi($s), $daftarSidik);

        if (count(array_unique(array_map('count', $sidik))) !== 1) {
            throw new PelanggaranAturanBisnis('SidikWajahTidakValid', 'Data wajah tidak terbaca. Ulangi pemindaian wajah.', 'SidikWajah');
        }

        $aktif = WajahKaryawan::query()
            ->where('IdKaryawan', $karyawan->Id)
            ->whereIn('Status', [StatusWajahKaryawan::Menunggu->value, StatusWajahKaryawan::Disetujui->value])
            ->first();

        if ($aktif !== null) {
            throw new PelanggaranAturanBisnis('WajahSudahTerdaftar', $aktif->Status === StatusWajahKaryawan::Menunggu
                ? 'Wajah Anda sudah terdaftar dan menunggu persetujuan pengelola.'
                : 'Wajah Anda sudah terdaftar. Minta pengelola mengatur ulang bila perlu mendaftar lagi.', 'Wajah');
        }

        $path = [];

        try {
            foreach ($daftarFoto as $foto) {
                $path[] = $this->penyimpan->Simpan($karyawan->IdTenant, is_string($foto) ? $foto : '');
            }

            $wajah = DB::transaction(function () use ($karyawan, $sidik, $path): WajahKaryawan {
                $this->hapus->HapusSemua($karyawan);

                return WajahKaryawan::query()->create([
                    'IdKaryawan' => $karyawan->Id,
                    'SidikWajah' => $sidik,
                    'PathFoto' => $path,
                    'Status' => StatusWajahKaryawan::Disetujui,
                    'PersetujuanKaryawanPada' => now(),
                ]);
            });
        } catch (Throwable $galat) {
            array_map($this->penyimpan->Hapus(...), $path);

            throw $galat;
        }

        // Sidik & foto tidak pernah masuk log audit.
        $this->audit->Catat('karyawan.wajah.daftar', $wajah, null, ['Karyawan' => $karyawan->Nama]);

        return $wajah;
    }
}
