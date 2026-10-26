<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Tenant\Model\EksporDataTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Menyusun ekspor data lengkap satu tenant (audit E5, UU PDP): ZIP berisi satu CSV per tabel data usaha (semua tabel
 * ber-`IdTenant`), `Tenant.csv` (profil usaha), `Anggota.csv`, dan `BACA-SAYA.txt`. Nama kolom CSV = nama kolom
 * database (PascalCase), jadi mudah dibaca dan diimpor ulang.
 *
 * Yang **tidak** ikut: tabel pembawa rahasia atau biometrik (kredensial gerbang, token, tautan undangan, kunci
 * idempotensi, sidik wajah, berkas KYC, log antrean), kolom rahasia (kata sandi, token, hash, kunci PIN, sidik), dan
 * identitas pajak terenkripsi (NIK, NPWP) yang tidak berarti bila diekspor sebagai teks acak. Daftar yang dikecualikan
 * ditulis di `BACA-SAYA.txt` supaya jelas bagi pemilik. Sel yang berawalan rumus (`=`, `+`, `-`, `@`) dinetralkan
 * agar aman dibuka di aplikasi lembar kerja. Pembacaan selalu disaring `IdTenant`; hanya dipanggil dalam konteks tenant.
 */
final class PenyusunEksporDataTenant
{
    /** Tabel ber-IdTenant yang sengaja tidak diekspor. */
    public const TABEL_DIKECUALIKAN = [
        'EksporDataTenant', 'GerbangPembayaranTenant', 'WhatsappTenant', 'WebhookTenant', 'TokenApiTenant', 'KodeAktivasi',
        'KodeMasukPelanggan', 'SesiPelangganOnline', 'UndanganPengguna', 'KunciIdempotensi', 'WajahKaryawan',
        'PendaftaranMerchantPembayaran', 'KirimanWebhook', 'TenantPengguna', 'PermintaanPenghapusanData',
    ];

    /** Kolom rahasia atau identitas terenkripsi yang tidak diekspor. */
    private const POLA_KOLOM_DIKECUALIKAN = '/(Sandi|Token|Hash|Rahasia|Kredensial|Sidik|Verifier|KunciPin|Secret)/i';

    private const KOLOM_IDENTITAS_TERENKRIPSI = ['Nik', 'Npwp', 'NamaNpwp', 'AlamatNpwp'];

    private const BARIS_PER_KELOMPOK = 1000;

    /**
     * @return array{JumlahTabel: int, JumlahBaris: int, UkuranByte: int, Path: string}
     */
    public function Susun(EksporDataTenant $ekspor, int $idTenant): array
    {
        $folder = sys_get_temp_dir().'/ekspor-data-'.$ekspor->Uuid;
        $berkasZip = $folder.'.zip';

        if (! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('Folder kerja ekspor tidak bisa dibuat.');
        }

        try {
            $ringkasan = [];
            $jumlahBaris = 0;

            foreach ($this->DaftarTabel() as $tabel) {
                $hasil = $this->TulisTabel($folder, $tabel, $idTenant);
                $ringkasan[] = $hasil;
                $jumlahBaris += $hasil['Baris'];
            }

            $ringkasan[] = $this->TulisProfil($folder, $idTenant);
            $ringkasan[] = $this->TulisAnggota($folder, $idTenant);
            file_put_contents($folder.'/BACA-SAYA.txt', $this->SusunPetunjuk($ringkasan, $ekspor));

            $zip = new ZipArchive;

            if ($zip->open($berkasZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Berkas ZIP ekspor tidak bisa dibuat.');
            }

            foreach (glob($folder.'/*') ?: [] as $berkas) {
                $zip->addFile($berkas, basename($berkas));
            }

            $zip->close();

            $disk = Storage::disk((string) config('tenant.DiskEkspor'));
            $path = "ekspor-data/{$idTenant}/{$ekspor->Uuid}.zip";
            $strim = fopen($berkasZip, 'rb');

            if ($strim === false || ! $disk->put($path, $strim)) {
                throw new RuntimeException('Berkas ekspor gagal disimpan.');
            }

            fclose($strim);

            return ['JumlahTabel' => count($ringkasan), 'JumlahBaris' => $jumlahBaris, 'UkuranByte' => (int) filesize($berkasZip), 'Path' => $path];
        } finally {
            foreach (glob($folder.'/*') ?: [] as $berkas) {
                @unlink($berkas);
            }

            @rmdir($folder);
            @unlink($berkasZip);
        }
    }

    /**
     * @return list<string>
     */
    public function DaftarTabel(): array
    {
        $hasil = [];

        foreach (Schema::getTableListing() as $nama) {
            $tabel = str_contains($nama, '.') ? substr((string) strrchr($nama, '.'), 1) : $nama;

            if (in_array($tabel, self::TABEL_DIKECUALIKAN, true) || ! Schema::hasColumn($tabel, 'IdTenant')) {
                continue;
            }

            $hasil[] = $tabel;
        }

        sort($hasil);

        return $hasil;
    }

    /**
     * @return array{Nama: string, Baris: int, KolomDikecualikan: list<string>}
     */
    private function TulisTabel(string $folder, string $tabel, int $idTenant): array
    {
        $kolom = array_values(Schema::getColumnListing($tabel));
        [$diekspor, $dikecualikan] = $this->PisahKolom($kolom);
        $kueri = DB::table($tabel)->where('IdTenant', $idTenant);

        return $this->TulisCsv($folder, $tabel, $diekspor, $dikecualikan, $kueri, in_array('Id', $kolom, true));
    }

    /**
     * @return array{Nama: string, Baris: int, KolomDikecualikan: list<string>}
     */
    private function TulisProfil(string $folder, int $idTenant): array
    {
        [$diekspor, $dikecualikan] = $this->PisahKolom(array_values(Schema::getColumnListing('Tenant')));

        return $this->TulisCsv($folder, 'Tenant', $diekspor, $dikecualikan, DB::table('Tenant')->where('Id', $idTenant), true);
    }

    /**
     * @return array{Nama: string, Baris: int, KolomDikecualikan: list<string>}
     */
    private function TulisAnggota(string $folder, int $idTenant): array
    {
        $kueri = DB::table('TenantPengguna')
            ->join('Pengguna', 'Pengguna.Id', '=', 'TenantPengguna.IdPengguna')
            ->where('TenantPengguna.IdTenant', $idTenant)
            ->select('TenantPengguna.Id as Id', 'Pengguna.Nama as Nama', 'Pengguna.Email as Email', 'Pengguna.NoHp as NoHp', 'TenantPengguna.Pemilik as Pemilik', 'TenantPengguna.Status as Status', 'TenantPengguna.DinonaktifkanPada as DinonaktifkanPada');
        $hasil = $this->TulisCsv($folder, 'Anggota', ['Id', 'Nama', 'Email', 'NoHp', 'Pemilik', 'Status', 'DinonaktifkanPada'], ['PIN dan akses perangkat'], $kueri, true, 'TenantPengguna.Id');

        return $hasil;
    }

    /**
     * @param  list<string>  $kolom
     * @return array{0: list<string>, 1: list<string>}
     */
    private function PisahKolom(array $kolom): array
    {
        $diekspor = [];
        $dikecualikan = [];

        foreach ($kolom as $nama) {
            if (preg_match(self::POLA_KOLOM_DIKECUALIKAN, $nama) === 1 || in_array($nama, self::KOLOM_IDENTITAS_TERENKRIPSI, true)) {
                $dikecualikan[] = $nama;
            } else {
                $diekspor[] = $nama;
            }
        }

        return [$diekspor, $dikecualikan];
    }

    /**
     * @param  list<string>  $kolom
     * @param  list<string>  $dikecualikan
     * @return array{Nama: string, Baris: int, KolomDikecualikan: list<string>}
     */
    private function TulisCsv(string $folder, string $nama, array $kolom, array $dikecualikan, Builder $kueri, bool $punyaId, string $kolomUrut = 'Id'): array
    {
        $penanganan = fopen("{$folder}/{$nama}.csv", 'wb');

        if ($penanganan === false) {
            throw new RuntimeException("Berkas {$nama}.csv tidak bisa dibuat.");
        }

        fwrite($penanganan, "\xEF\xBB\xBF");
        fputcsv($penanganan, $kolom, ',', '"', '');
        $baris = 0;
        $tulis = function (iterable $kelompok) use ($penanganan, $kolom, &$baris): void {
            foreach ($kelompok as $data) {
                $larik = (array) $data;
                fputcsv($penanganan, array_map(fn (string $k): string => self::Netralkan($larik[$k] ?? null), $kolom), ',', '"', '');
                $baris++;
            }
        };

        if ($punyaId) {
            $kueri->orderBy($kolomUrut)->chunk(self::BARIS_PER_KELOMPOK, $tulis);
        } else {
            foreach ($kueri->cursor() as $data) {
                $tulis([$data]);
            }
        }

        fclose($penanganan);

        return ['Nama' => $nama, 'Baris' => $baris, 'KolomDikecualikan' => $dikecualikan];
    }

    /** Nilai sel sebagai teks CSV: kosong untuk null/rahasia terenkripsi, rumus dinetralkan. */
    private static function Netralkan(mixed $nilai): string
    {
        if ($nilai === null) {
            return '';
        }

        if (is_bool($nilai)) {
            return $nilai ? '1' : '0';
        }

        $teks = (string) $nilai;

        // Payload terenkripsi Laravel (base64 JSON {"iv":...}) tidak berguna bagi pemilik.
        if (str_starts_with($teks, 'eyJpdiI6')) {
            return '';
        }

        if ($teks !== '' && ! is_numeric($teks) && in_array($teks[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$teks;
        }

        return $teks;
    }

    /**
     * @param  list<array{Nama: string, Baris: int, KolomDikecualikan: list<string>}>  $ringkasan
     */
    private function SusunPetunjuk(array $ringkasan, EksporDataTenant $ekspor): string
    {
        $baris = [
            'EKSPOR DATA USAHA',
            'Dibuat: '.CarbonImmutable::now('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
            '',
            'Berkas ini berisi data usaha Anda: satu berkas CSV per tabel (UTF-8, pemisah koma). Nama kolom sama dengan nama kolom di sistem.',
            'Jumlah baris per berkas ada di bawah. Simpan berkas ini di tempat aman. Isinya mencakup data pelanggan dan karyawan Anda.',
            '',
            'Tidak ikut diekspor demi keamanan: kata sandi, PIN, token dan kunci akses, kredensial integrasi, sidik wajah karyawan,',
            'identitas pajak terenkripsi (NIK dan NPWP), foto dan lampiran (berkas gambar/dokumen, hanya jalurnya yang tercatat), serta log teknis.',
            'Tabel yang tidak diekspor seluruhnya: '.implode(', ', self::TABEL_DIKECUALIKAN).'.',
            '',
            'Daftar berkas:',
        ];

        foreach ($ringkasan as $tabel) {
            $baris[] = sprintf('- %s.csv : %d baris', $tabel['Nama'], $tabel['Baris']).($tabel['KolomDikecualikan'] === [] ? '' : ' (kolom tidak diekspor: '.implode(', ', $tabel['KolomDikecualikan']).')');
        }

        $baris[] = '';
        $baris[] = 'Kode permintaan: '.$ekspor->Uuid;

        return implode("\r\n", $baris)."\r\n";
    }
}
