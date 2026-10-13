<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Impor\Layanan\PembacaBerkasTabel;
use App\Domain\Pembelian\Model\Pemasok;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Impor pemasok dari Excel (.xlsx) atau CSV (PLT-10, F31), dua langkah seperti impor pelanggan: **periksa** (tanpa
 * menyimpan apa pun) lalu **terapkan** berkas yang sama. Kunci pemasok adalah Kode: kode yang sudah terdaftar (termasuk
 * pemasok yang pernah dihapus) **dilewati, tidak ditimpa**; berkas tanpa kolom Kode memakai Nama sebagai kunci dan kode
 * dibuat otomatis `PMS-0001`, `PMS-0002`, ... saat diterapkan. Kode atau nama dobel di berkas = baris pertama yang dipakai.
 * Baris bermasalah dilaporkan per nomor baris spreadsheet; baris yang sah tetap diimpor. Satu audit `pemasok.impor` berisi
 * jumlah (tanpa data kontak atau rekening).
 *
 * Judul kolom dikenali tanpa peduli huruf besar & spasi: Nama (wajib), Kode, NamaKontak, NoHp, Email, Alamat, Npwp, Pkp
 * (Ya/Tidak), TerminHari (angka 0–365, atau "Tunai"), NamaBank, NomorRekening, AtasNamaRekening, Catatan.
 */
final class ImporPemasok
{
    public const MAKSIMAL_BARIS = 2000;

    public const MAKSIMAL_MASALAH = 200;

    private const AWALAN_KODE = 'PMS-';

    /** @var array<string, list<string>> */
    private const SINONIM = [
        'Kode' => ['kode', 'kodepemasok', 'id'],
        'Nama' => ['nama', 'namapemasok', 'pemasok'],
        'NamaKontak' => ['namakontak', 'kontak', 'cp', 'pic', 'kontakperson'],
        'NoHp' => ['nohp', 'nomorhp', 'hp', 'nohandphone', 'telepon', 'telp', 'notelp', 'whatsapp', 'wa', 'nowa', 'phone'],
        'Email' => ['email', 'surel', 'e-mail'],
        'Alamat' => ['alamat', 'address'],
        'Npwp' => ['npwp'],
        'Pkp' => ['pkp', 'statuspkp'],
        'TerminHari' => ['terminhari', 'termin', 'top', 'tempo', 'jatuhtempohari'],
        'NamaBank' => ['namabank', 'bank'],
        'NomorRekening' => ['nomorrekening', 'norekening', 'rekening', 'norek'],
        'AtasNamaRekening' => ['atasnamarekening', 'atasnama', 'an'],
        'Catatan' => ['catatan', 'keterangan'],
    ];

    public function __construct(
        private readonly PembacaBerkasTabel $pembaca,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return array{Terapkan: bool, JumlahBaris: int, Baru: int, SudahAda: int, Bermasalah: int, Masalah: list<array{Baris: int, Nama: string, Pesan: string}>}
     *
     * @throws PelanggaranAturanBisnis BerkasImporTidakValid, KolomWajibTidakAda, BarisTerlaluBanyak
     */
    public function Jalankan(string $path, string $namaBerkas, bool $terapkan, ?int $idPengguna): array
    {
        $format = $this->pembaca->TentukanFormat($path, $namaBerkas);
        $sementara = null;
        $pemisah = null;

        if ($format === PembacaBerkasTabel::FORMAT_CSV) {
            $isi = $this->pembaca->NormalisasiCsv((string) file_get_contents($path));
            $pemisah = $this->pembaca->DeteksiPemisah($isi);
            // Salinan ternormalisasi di disk privat, dihapus begitu selesai dibaca.
            $sementara = 'impor-pemasok/'.Str::ulid().'.csv';
            Storage::disk('local')->put($sementara, $isi);
            $path = Storage::disk('local')->path($sementara);
        }

        try {
            [$calon, $masalah, $jumlah] = $this->BacaCalon($path, $format, $pemisah);
        } finally {
            if ($sementara !== null) {
                Storage::disk('local')->delete($sementara);
            }
        }

        // Yang sudah terdaftar dilewati (bukan galat). Dicek ulang di bawah kunci saat menerapkan.
        $baru = array_diff_key($calon, $this->AmbilTerdaftar($calon));

        if ($terapkan && $baru !== []) {
            $baru = DB::transaction(function () use ($baru, $idPengguna): array {
                $masihBaru = array_diff_key($baru, $this->AmbilTerdaftar($baru, true));
                $dipakai = Pemasok::query()->withTrashed()->lockForUpdate()->pluck('Kode')->map(fn (mixed $k): string => mb_strtoupper((string) $k))->flip()->all();
                $berikut = $this->HitungNomorTerakhir(array_keys($dipakai)) + 1;

                foreach ($masihBaru as $isian) {
                    $kode = $isian['Kode'];

                    if ($kode === null) {
                        while (isset($dipakai[self::AWALAN_KODE.str_pad((string) $berikut, 4, '0', STR_PAD_LEFT)])) {
                            $berikut++;
                        }

                        $kode = self::AWALAN_KODE.str_pad((string) $berikut, 4, '0', STR_PAD_LEFT);
                        $berikut++;
                    }

                    $dipakai[$kode] = 0;
                    Pemasok::query()->create([...$isian, 'Kode' => $kode, 'DibuatOleh' => $idPengguna]);
                }

                return $masihBaru;
            }, 3);
        }

        $hasil = [
            'Terapkan' => $terapkan,
            'JumlahBaris' => $jumlah,
            'Baru' => count($baru),
            'SudahAda' => count($calon) - count($baru),
            'Bermasalah' => count($masalah),
            'Masalah' => array_slice($masalah, 0, self::MAKSIMAL_MASALAH),
        ];

        if ($terapkan) {
            $this->audit->Catat('pemasok.impor', null, nilaiBaru: [
                'Berkas' => mb_substr($namaBerkas, 0, 120),
                'JumlahBaris' => $jumlah,
                'Baru' => $hasil['Baru'],
                'SudahAda' => $hasil['SudahAda'],
                'Bermasalah' => $hasil['Bermasalah'],
            ], idPengguna: $idPengguna);
        }

        return $hasil;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: list<array{Baris: int, Nama: string, Pesan: string}>, 2: int}
     */
    private function BacaCalon(string $path, string $format, ?string $pemisah): array
    {
        $kolom = null;
        $calon = [];
        $masalah = [];
        $jumlah = 0;

        foreach ($this->pembaca->BacaBaris($path, $format, $pemisah) as $nomor => $sel) {
            if ($kolom === null) {
                $kolom = self::PetakanJudul($sel);

                continue;
            }

            $jumlah++;

            if ($jumlah > self::MAKSIMAL_BARIS) {
                throw new PelanggaranAturanBisnis('BarisTerlaluBanyak', 'Paling banyak 2.000 pemasok per berkas. Pecah berkasnya, lalu impor bertahap.', 'Berkas');
            }

            $ambil = fn (string $bidang): string => isset($kolom[$bidang]) ? trim($sel[$kolom[$bidang]] ?? '') : '';
            $nama = mb_substr($ambil('Nama'), 0, 150);

            try {
                $isian = self::SusunIsian($nama, $ambil);
            } catch (PelanggaranAturanBisnis $galat) {
                $masalah[] = ['Baris' => (int) $nomor, 'Nama' => $nama, 'Pesan' => $galat->getMessage()];

                continue;
            }

            $kunci = self::KunciBaris($isian);

            if (isset($calon[$kunci])) {
                $masalah[] = ['Baris' => (int) $nomor, 'Nama' => $nama, 'Pesan' => ($isian['Kode'] === null ? 'Nama' : 'Kode').' sama dengan baris sebelumnya di berkas ini; baris ini dilewati.'];

                continue;
            }

            $calon[$kunci] = $isian;
        }

        if ($kolom === null) {
            throw new PelanggaranAturanBisnis('BerkasImporTidakValid', 'Berkas tidak berisi data. Baris pertama harus judul kolom (Nama, Kode, ...).', 'Berkas');
        }

        return [$calon, $masalah, $jumlah];
    }

    /**
     * Indeks kolom per bidang dari baris judul. Hanya Nama yang wajib ada.
     *
     * @param  list<string>  $judul
     * @return array<string, int>
     */
    private static function PetakanJudul(array $judul): array
    {
        $peta = [];

        foreach ($judul as $indeks => $teks) {
            $kunci = (string) preg_replace('/[^a-z\-]/', '', mb_strtolower($teks));

            foreach (self::SINONIM as $bidang => $daftar) {
                if (! isset($peta[$bidang]) && in_array($kunci, $daftar, true)) {
                    $peta[$bidang] = $indeks;
                }
            }
        }

        if (! isset($peta['Nama'])) {
            throw new PelanggaranAturanBisnis('KolomWajibTidakAda', 'Kolom Nama tidak ditemukan di baris pertama. Unduh templat untuk contoh judul kolom.', 'Berkas');
        }

        return $peta;
    }

    /**
     * @param  callable(string): string  $ambil
     * @return array<string, mixed>
     *
     * @throws PelanggaranAturanBisnis
     */
    private static function SusunIsian(string $nama, callable $ambil): array
    {
        if ($nama === '') {
            throw new PelanggaranAturanBisnis('NamaWajib', 'Nama kosong.');
        }

        $kode = mb_strtoupper($ambil('Kode'));

        if ($kode !== '' && (mb_strlen($kode) > 30 || preg_match('/^[A-Z0-9._-]+$/', $kode) !== 1)) {
            throw new PelanggaranAturanBisnis('KodeTidakValid', 'Kode hanya huruf, angka, titik, garis bawah, atau tanda hubung (maksimal 30).');
        }

        $noHp = $ambil('NoHp');

        if ($noHp !== '' && (mb_strlen($noHp) > 30 || preg_match('/^[0-9+() -]+$/', $noHp) !== 1)) {
            throw new PelanggaranAturanBisnis('NoHpTidakValid', 'Nomor HP hanya angka, spasi, +, (, ), atau tanda hubung.');
        }

        $email = mb_strtolower($ambil('Email'));

        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150)) {
            throw new PelanggaranAturanBisnis('EmailTidakValid', 'Email tidak valid.');
        }

        return [
            'Kode' => $kode === '' ? null : $kode,
            'Nama' => $nama,
            'NamaKontak' => self::Potong($ambil('NamaKontak'), 100),
            'NoHp' => $noHp === '' ? null : $noHp,
            'Email' => $email === '' ? null : $email,
            'Alamat' => self::Potong($ambil('Alamat'), 500),
            'Npwp' => self::Potong($ambil('Npwp'), 30),
            'Pkp' => in_array(mb_strtolower($ambil('Pkp')), ['ya', 'y', 'true', '1', 'pkp'], true),
            'TerminHari' => self::UraiTermin($ambil('TerminHari')),
            'NamaBank' => self::Potong($ambil('NamaBank'), 100),
            'NomorRekening' => self::Potong($ambil('NomorRekening'), 50),
            'AtasNamaRekening' => self::Potong($ambil('AtasNamaRekening'), 150),
            'Catatan' => self::Potong($ambil('Catatan'), 500),
        ];
    }

    /**
     * "30", "30 hari", atau "Tunai"/kosong (= 0); 0–365.
     *
     * @throws PelanggaranAturanBisnis
     */
    private static function UraiTermin(string $teks): int
    {
        $bersih = mb_strtolower($teks);

        if ($bersih === '' || in_array($bersih, ['tunai', 'cash', 'cod', 'lunas'], true)) {
            return 0;
        }

        if (preg_match('/^(\d{1,3})(?:\s*hari)?$/', $bersih, $cocok) !== 1 || (int) $cocok[1] > 365) {
            throw new PelanggaranAturanBisnis('TerminTidakValid', 'Termin harus angka 0 sampai 365 hari atau "Tunai".');
        }

        return (int) $cocok[1];
    }

    private static function Potong(string $teks, int $panjang): ?string
    {
        return $teks === '' ? null : mb_substr($teks, 0, $panjang);
    }

    /**
     * Kode (huruf besar) bila ada, jika tidak nama (huruf kecil) sebagai kunci dalam berkas.
     *
     * @param  array<string, mixed>  $isian
     */
    private static function KunciBaris(array $isian): string
    {
        return $isian['Kode'] !== null ? 'kode:'.$isian['Kode'] : 'nama:'.mb_strtolower((string) $isian['Nama']);
    }

    /**
     * Bagian calon yang sudah terdaftar di tenant ini: per kode (termasuk pemasok yang sudah dihapus, karena kode tetap
     * unik), atau per nama pada pemasok yang masih ada bila berkas tanpa kode.
     *
     * @param  array<string, array<string, mixed>>  $calon
     * @return array<string, true>
     */
    private function AmbilTerdaftar(array $calon, bool $kunci = false): array
    {
        $hasil = [];
        $perKode = [];
        $perNama = [];

        foreach ($calon as $kunciBaris => $isian) {
            if ($isian['Kode'] !== null) {
                $perKode[(string) $isian['Kode']] = $kunciBaris;
            } else {
                $perNama[mb_strtolower((string) $isian['Nama'])] = $kunciBaris;
            }
        }

        foreach (array_chunk(array_keys($perKode), 1000) as $potongan) {
            $kueri = Pemasok::query()->withTrashed()->whereIn('Kode', $potongan);

            foreach (($kunci ? $kueri->lockForUpdate() : $kueri)->pluck('Kode') as $kode) {
                $kunciBaris = $perKode[mb_strtoupper((string) $kode)] ?? null;

                if ($kunciBaris !== null) {
                    $hasil[$kunciBaris] = true;
                }
            }
        }

        foreach (array_chunk(array_keys($perNama), 1000) as $potongan) {
            $kueri = Pemasok::query()->whereIn('Nama', $potongan);

            foreach (($kunci ? $kueri->lockForUpdate() : $kueri)->pluck('Nama') as $nama) {
                $kunciBaris = $perNama[mb_strtolower((string) $nama)] ?? null;

                if ($kunciBaris !== null) {
                    $hasil[$kunciBaris] = true;
                }
            }
        }

        return $hasil;
    }

    /**
     * Nomor tertinggi pada kode berpola `PMS-0001`; 0 bila belum ada.
     *
     * @param  list<int|string>  $kode
     */
    private function HitungNomorTerakhir(array $kode): int
    {
        $tertinggi = 0;

        foreach ($kode as $satu) {
            if (preg_match('/^'.preg_quote(self::AWALAN_KODE, '/').'(\d+)$/', (string) $satu, $cocok) === 1) {
                $tertinggi = max($tertinggi, (int) $cocok[1]);
            }
        }

        return $tertinggi;
    }
}
