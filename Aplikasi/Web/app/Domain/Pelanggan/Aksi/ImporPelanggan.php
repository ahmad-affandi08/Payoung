<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Katalog\Impor\Layanan\PembacaBerkasTabel;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pelanggan\Model\Pelanggan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Impor pelanggan dari Excel (.xlsx) atau CSV (F-16a, v3.36), dua langkah: **periksa** (tanpa menyimpan apa pun) lalu
 * **terapkan** berkas yang sama. Nomor HP adalah kunci pelanggan: baris yang nomornya sudah terdaftar **dilewati, tidak
 * ditimpa** (data yang sudah dirawat kasir tidak tertimpa berkas lama), nomor dobel di berkas = baris pertama yang
 * dipakai. Baris bermasalah dilaporkan per nomor baris spreadsheet; baris yang sah tetap diimpor. Satu audit
 * `pelanggan.impor` berisi jumlah (tanpa data pribadi).
 *
 * Judul kolom dikenali tanpa peduli huruf besar & spasi: Nama, NoHp (No HP, Nomor HP, HP, Telepon, WhatsApp), Email,
 * TanggalLahir (Y-m-d, d/m/Y, d-m-Y), Alamat, Tag (dipisah `;` atau `,`), Catatan, SetujuPemasaran (Ya/Tidak). Kolom
 * lain (misalnya hasil ekspor: Tier, Poin, SaldoDeposit) diabaikan: poin, deposit, dan tier tidak bisa diimpor karena
 * saldo hanya lahir dari peristiwa (aturan #9).
 */
final class ImporPelanggan
{
    /** Sama dengan teks galat `BarisTerlaluBanyak` ("5.000"). */
    public const MAKSIMAL_BARIS = 5000;

    public const MAKSIMAL_MASALAH = 200;

    /** @var array<string, list<string>> */
    private const SINONIM = [
        'Nama' => ['nama', 'namapelanggan', 'pelanggan', 'name'],
        'NoHp' => ['nohp', 'nomorhp', 'hp', 'nohandphone', 'telepon', 'telp', 'notelp', 'whatsapp', 'wa', 'nowa', 'phone'],
        'Email' => ['email', 'surel', 'e-mail'],
        'TanggalLahir' => ['tanggallahir', 'tgllahir', 'lahir', 'ulangtahun'],
        'Alamat' => ['alamat', 'address'],
        'Tag' => ['tag', 'label', 'kelompok'],
        'Catatan' => ['catatan', 'keterangan'],
        'SetujuPemasaran' => ['setujupemasaran', 'bolehpromo', 'pemasaran'],
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
            $sementara = 'impor-pelanggan/'.Str::ulid().'.csv';
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

        // Nomor yang sudah terdaftar dilewati (bukan galat). Dicek ulang di bawah kunci saat menerapkan.
        $terdaftar = $this->AmbilTerdaftar(array_keys($calon));
        $baru = array_diff_key($calon, $terdaftar);

        if ($terapkan && $baru !== []) {
            $baru = DB::transaction(function () use ($baru, $idPengguna): array {
                $masihBaru = array_diff_key($baru, $this->AmbilTerdaftar(array_keys($baru), true));

                foreach (array_chunk($masihBaru, 500, true) as $potongan) {
                    foreach ($potongan as $isian) {
                        Pelanggan::query()->create([...$isian, 'DibuatOleh' => $idPengguna]);
                    }
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
            $this->audit->Catat('pelanggan.impor', null, nilaiBaru: [
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
                throw new PelanggaranAturanBisnis('BarisTerlaluBanyak', 'Paling banyak 5.000 pelanggan per berkas. Pecah berkasnya, lalu impor bertahap.', 'Berkas');
            }

            $ambil = fn (string $bidang): string => isset($kolom[$bidang]) ? trim($sel[$kolom[$bidang]] ?? '') : '';
            $nama = mb_substr($ambil('Nama'), 0, 150);

            try {
                $isian = self::SusunIsian($nama, $ambil);
            } catch (PelanggaranAturanBisnis $galat) {
                $masalah[] = ['Baris' => (int) $nomor, 'Nama' => $nama, 'Pesan' => $galat->getMessage()];

                continue;
            }

            if (isset($calon[$isian['NoHp']])) {
                $masalah[] = ['Baris' => (int) $nomor, 'Nama' => $nama, 'Pesan' => 'Nomor HP sama dengan baris sebelumnya di berkas ini. Baris ini dilewati.'];

                continue;
            }

            $calon[$isian['NoHp']] = $isian;
        }

        if ($kolom === null) {
            throw new PelanggaranAturanBisnis('BerkasImporTidakValid', 'Berkas tidak berisi data. Baris pertama harus judul kolom (Nama, NoHp, ...).', 'Berkas');
        }

        return [$calon, $masalah, $jumlah];
    }

    /**
     * Indeks kolom per bidang dari baris judul. Nama & NoHp wajib ada.
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

        $kurang = array_diff(['Nama', 'NoHp'], array_keys($peta));

        if ($kurang !== []) {
            throw new PelanggaranAturanBisnis('KolomWajibTidakAda', 'Kolom '.implode(' dan ', $kurang).' tidak ditemukan di baris pertama. Unduh templat untuk contoh judul kolom.', 'Berkas');
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

        $noHp = NomorHp::Normalisasi($ambil('NoHp'));

        if ($noHp === null) {
            throw new PelanggaranAturanBisnis('NoHpTidakValid', $ambil('NoHp') === '' ? 'Nomor HP kosong.' : 'Nomor HP tidak valid.');
        }

        $email = mb_strtolower($ambil('Email'));

        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150)) {
            throw new PelanggaranAturanBisnis('EmailTidakValid', 'Email tidak valid.');
        }

        $tag = array_values(array_unique(array_filter(array_map(
            fn (string $t): string => mb_substr(trim($t), 0, 40),
            preg_split('/[;,]/', $ambil('Tag')) ?: [],
        ), fn (string $t): bool => $t !== '')));

        return [
            'Nama' => $nama,
            'NoHp' => $noHp,
            'Email' => $email === '' ? null : $email,
            'TanggalLahir' => self::UraiTanggal($ambil('TanggalLahir')),
            'Alamat' => self::Potong($ambil('Alamat'), 500),
            'Tag' => $tag === [] ? null : array_slice($tag, 0, 20),
            'Catatan' => self::Potong($ambil('Catatan'), 500),
            'SetujuPemasaran' => in_array(mb_strtolower($ambil('SetujuPemasaran')), ['ya', 'y', 'true', '1', 'setuju'], true),
        ];
    }

    /** @throws PelanggaranAturanBisnis */
    private static function UraiTanggal(string $teks): ?string
    {
        if ($teks === '') {
            return null;
        }

        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!j/n/Y'] as $pola) {
            try {
                $tanggal = CarbonImmutable::createFromFormat($pola, $teks);
            } catch (Throwable) {
                $tanggal = null;
            }

            if ($tanggal instanceof CarbonImmutable && $tanggal->format(ltrim($pola, '!')) === $teks) {
                if ($tanggal->isFuture() || $tanggal->year < 1900) {
                    break;
                }

                return $tanggal->toDateString();
            }
        }

        throw new PelanggaranAturanBisnis('TanggalLahirTidakValid', 'Tanggal lahir tidak valid (contoh 1990-08-17 atau 17/08/1990).');
    }

    private static function Potong(string $teks, int $panjang): ?string
    {
        return $teks === '' ? null : mb_substr($teks, 0, $panjang);
    }

    /**
     * Nomor HP (ternormalisasi) yang sudah terdaftar di tenant ini, sebagai kunci.
     *
     * @param  list<string>  $noHp
     * @return array<string, true>
     */
    private function AmbilTerdaftar(array $noHp, bool $kunci = false): array
    {
        $hasil = [];

        foreach (array_chunk($noHp, 1000) as $potongan) {
            $kueri = Pelanggan::query()->whereIn('NoHp', $potongan);

            foreach (($kunci ? $kueri->lockForUpdate() : $kueri)->pluck('NoHp') as $nomor) {
                $hasil[(string) $nomor] = true;
            }
        }

        return $hasil;
    }
}
