<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pengelola\Komunikasi\Enum\SektorProspek;
use App\Domain\Pengelola\Komunikasi\Enum\StatusKontakProspek;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Memasukkan kontak prospek dari berkas CSV (D-216). Hanya dua kolom yang dibaca: `Email` dan `Sektor` (kode atau nama
 * sektor Payoung); kolom lain, termasuk nama dan telepon, diabaikan dan tidak pernah disimpan. Alamat yang sudah ada
 * (termasuk yang pernah berhenti atau pentalan) tidak dimasukkan lagi, sehingga orang yang sudah berhenti tidak bisa
 * "hidup kembali" lewat impor ulang. `Sumber` wajib dan dicantumkan di setiap email sebagai asal alamat.
 */
final class ImporKontakProspek
{
    public const MAKS_BARIS = 5000;

    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    /**
     * @return array{Ditambah: int, SudahAda: int, TidakValid: int, SektorTidakDikenal: int}
     */
    public function Jalankan(PenggunaPengelola $pelaku, string $isiCsv, string $sumber): array
    {
        $sumber = trim($sumber);

        if (mb_strlen($sumber) < 5) {
            throw new PelanggaranAturanBisnis('P-12', 'Tulis sumber data (misal nama dataset dan situsnya). Sumber dicantumkan di setiap email.', 'Sumber');
        }

        $baris = $this->Baca($isiCsv);

        $hasil = ['Ditambah' => 0, 'SudahAda' => 0, 'TidakValid' => 0, 'SektorTidakDikenal' => 0];
        /** @var array<string, SektorProspek> $kandidat */
        $kandidat = [];

        foreach ($baris as [$email, $teksSektor]) {
            $email = mb_strtolower(trim($email));

            if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $hasil['TidakValid']++;

                continue;
            }

            $sektor = SektorProspek::DariTeks($teksSektor);

            if ($sektor === null) {
                $hasil['SektorTidakDikenal']++;

                continue;
            }

            if (isset($kandidat[$email])) {
                $hasil['SudahAda']++;

                continue;
            }

            $kandidat[$email] = $sektor;
        }

        DB::transaction(function () use (&$hasil, $kandidat, $sumber): void {
            $sekarang = Carbon::now();

            foreach (array_chunk($kandidat, 500, true) as $kelompok) {
                $ada = array_flip(DB::table('KontakProspek')->whereIn('Email', array_keys($kelompok))->pluck('Email')->all());
                $baru = [];

                foreach ($kelompok as $email => $sektor) {
                    if (isset($ada[$email])) {
                        $hasil['SudahAda']++;

                        continue;
                    }

                    $baru[] = [
                        'Uuid' => (string) Str::ulid(),
                        'Email' => $email,
                        'Sektor' => $sektor->value,
                        'Sumber' => Str::limit($sumber, 160, ''),
                        'Status' => StatusKontakProspek::Aktif->value,
                        'DibuatPada' => $sekarang,
                        'DiubahPada' => $sekarang,
                    ];
                }

                if ($baru !== []) {
                    DB::table('KontakProspek')->insert($baru);
                    $hasil['Ditambah'] += count($baru);
                }
            }
        });

        $this->audit->Catat('prospek.kontak.impor', nilaiBaru: $hasil + ['Sumber' => $sumber], idPelaku: $pelaku->Id);

        return $hasil;
    }

    /**
     * @return list<array{0: string, 1: string}> pasangan [email, sektor] per baris data
     */
    private function Baca(string $isiCsv): array
    {
        $isiCsv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $isiCsv);
        $barisPertama = strtok($isiCsv, "\n");
        $pemisah = is_string($barisPertama) && substr_count($barisPertama, ';') > substr_count($barisPertama, ',') ? ';' : ',';
        $aliran = fopen('php://temp', 'r+');

        if ($aliran === false) {
            throw new PelanggaranAturanBisnis('P-12', 'Berkas tidak bisa dibaca.', 'Berkas');
        }

        fwrite($aliran, $isiCsv);
        rewind($aliran);

        $kepala = fgetcsv($aliran, 0, $pemisah, '"', '\\');
        $kolom = is_array($kepala) ? array_map(fn ($nama): string => mb_strtolower(trim((string) $nama)), $kepala) : [];
        $indeksEmail = array_search('email', $kolom, true);
        $indeksSektor = array_search('sektor', $kolom, true);

        if ($indeksEmail === false || $indeksSektor === false) {
            fclose($aliran);

            throw new PelanggaranAturanBisnis('P-12', 'Baris pertama berkas harus berisi kolom Email dan Sektor.', 'Berkas');
        }

        $hasil = [];

        while (($isi = fgetcsv($aliran, 0, $pemisah, '"', '\\')) !== false) {
            if ($isi === [null]) {
                continue;
            }

            $hasil[] = [(string) ($isi[$indeksEmail] ?? ''), (string) ($isi[$indeksSektor] ?? '')];

            if (count($hasil) > self::MAKS_BARIS) {
                fclose($aliran);

                throw new PelanggaranAturanBisnis('P-12', 'Paling banyak '.self::MAKS_BARIS.' baris per berkas. Pecah berkasnya, lalu impor bertahap.', 'Berkas');
            }
        }

        fclose($aliran);

        return $hasil;
    }
}
