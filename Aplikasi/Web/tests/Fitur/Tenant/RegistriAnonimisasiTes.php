<?php

declare(strict_types=1);

use App\Domain\Tenant\Layanan\DaftarFolderBerkasTenant;
use App\Domain\Tenant\Layanan\PengukurPenyimpanan;
use App\Domain\Tenant\Layanan\RegistriAnonimisasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Penjaga penghapusan data (UU PDP, P-07). Registri anonimisasi dicocokkan dengan skema nyata, jadi tabel atau kolom
 * data pribadi baru tidak bisa lolos tanpa keputusan: kalau test ini gagal, tambahkan kolomnya ke
 * `RegistriAnonimisasi` (diganti, atau dipertahankan dengan alasan tertulis), jangan melonggarkan test.
 */

/** Pola nama kolom yang membawa data pribadi, kredensial, atau lokasi. */
const POLA_KOLOM_PRIBADI = '/^(NoHp|NomorHp|Telepon|Email|Alamat\w*|Nik|Npwp|NamaNpwp|TanggalLahir|Lintang\w*|Bujur\w*|Latitude|Longitude|Nama(Pelanggan|Pemesan|Kontak|Pihak|Penerima|Pengirim|Pasien|Dokter|PemilikRekening|Pemberitahuan)|AtasNamaRekening|NomorRekening\w*|NomorPolisi|NomorRangka|NomorMesin|Ip|HashIp\w*|HashNoHp|Path\w+|Token\w*|HashToken\w*|Sidik\w*|KunciPin\w*|HashPin|Rahasia\w*|KataSandi\w*)$/';

/** @return array<string, array<string, array{tipe: string, nullable: bool, turunan: bool}>> */
function SkemaKolomUji(): array
{
    $baris = DB::select('SELECT TABLE_NAME AS t, COLUMN_NAME AS k, DATA_TYPE AS tipe, IS_NULLABLE AS n, GENERATION_EXPRESSION AS g FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
    $hasil = [];

    foreach ($baris as $b) {
        $hasil[$b->t][$b->k] = ['tipe' => (string) $b->tipe, 'nullable' => $b->n === 'YES', 'turunan' => (string) $b->g !== ''];
    }

    return $hasil;
}

/** @return list<string> tabel yang punya kolom IdTenant */
function TabelBerTenantUji(): array
{
    return array_values(array_filter(array_keys(SkemaKolomUji()), fn (string $t): bool => isset(SkemaKolomUji()[$t]['IdTenant'])));
}

describe('P-07 registri anonimisasi cocok dengan skema', function (): void {
    it('setiap tabel dan kolom registri ada, dan strategi cocok dengan tipe serta nullability kolom', function (): void {
        $skema = SkemaKolomUji();
        $salah = [];

        foreach (RegistriAnonimisasi::ANONIMKAN as $tabel => $kolom) {
            if (! isset($skema[$tabel])) {
                $salah[] = "Tabel {$tabel} tidak ada";

                continue;
            }

            if (! isset($skema[$tabel][RegistriAnonimisasi::KUNCI_KHUSUS[$tabel] ?? 'IdTenant'])) {
                $salah[] = "Tabel {$tabel} tidak punya kolom kunci tenant";
            }

            foreach ($kolom as $nama => $strategi) {
                if (! isset($skema[$tabel][$nama])) {
                    $salah[] = "{$tabel}.{$nama} tidak ada";

                    continue;
                }

                $info = $skema[$tabel][$nama];

                if ($info['turunan']) {
                    $salah[] = "{$tabel}.{$nama} kolom turunan (generated): tidak bisa diubah langsung, ubah kolom asalnya";
                }

                if ($strategi === 'null' && ! $info['nullable']) {
                    $salah[] = "{$tabel}.{$nama} wajib terisi, tidak bisa dikosongkan (pakai teks:/nol/enkripsi)";
                }

                if (str_starts_with($strategi, 'unik:') && ! in_array($info['tipe'], ['varchar', 'char', 'text'], true)) {
                    $salah[] = "{$tabel}.{$nama} bukan kolom teks untuk strategi unik";
                }

                if ($strategi === 'enkripsi' && ! in_array($info['tipe'], ['text', 'longtext', 'varchar'], true)) {
                    $salah[] = "{$tabel}.{$nama} tidak cocok untuk strategi enkripsi";
                }

                if ($strategi === 'sekarang' && ! in_array($info['tipe'], ['timestamp', 'datetime'], true)) {
                    $salah[] = "{$tabel}.{$nama} bukan kolom waktu untuk strategi sekarang";
                }
            }
        }

        expect($salah)->toBe([]);
    });

    it('tabel yang dihapus barisnya ada, ber-IdTenant, dan tidak dirujuk kunci asing dari tabel yang dipertahankan', function (): void {
        $skema = SkemaKolomUji();
        $tidakAda = array_values(array_filter(RegistriAnonimisasi::HAPUS_BARIS, fn (string $t): bool => ! isset($skema[$t]['IdTenant'])));
        expect($tidakAda)->toBe([]);

        $rujukan = DB::select('SELECT TABLE_NAME AS asal, REFERENCED_TABLE_NAME AS tujuan FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
        $bermasalah = [];

        foreach ($rujukan as $r) {
            if (in_array($r->tujuan, RegistriAnonimisasi::HAPUS_BARIS, true) && ! in_array($r->asal, RegistriAnonimisasi::HAPUS_BARIS, true)) {
                $bermasalah[] = "{$r->asal} merujuk {$r->tujuan}";
            }
        }

        expect($bermasalah)->toBe([]);

        // Urutan penghapusan: tabel anak selalu mendahului induknya.
        $posisi = array_flip(RegistriAnonimisasi::HAPUS_BARIS);
        $terbalik = [];

        foreach ($rujukan as $r) {
            if (isset($posisi[$r->asal], $posisi[$r->tujuan]) && $posisi[$r->asal] > $posisi[$r->tujuan]) {
                $terbalik[] = "{$r->asal} harus dihapus sebelum {$r->tujuan}";
            }
        }

        expect($terbalik)->toBe([]);
    });

    it('kolom berpola data pribadi di tabel tenant selalu tercatat: diganti, dipertahankan beralasan, atau tabelnya dihapus', function (): void {
        $skema = SkemaKolomUji();
        $belumTercatat = [];

        foreach (TabelBerTenantUji() as $tabel) {
            if (in_array($tabel, RegistriAnonimisasi::HAPUS_BARIS, true)) {
                continue;
            }

            foreach (array_keys($skema[$tabel]) as $kolom) {
                if (preg_match(POLA_KOLOM_PRIBADI, $kolom) !== 1) {
                    continue;
                }

                if (isset(RegistriAnonimisasi::ANONIMKAN[$tabel][$kolom]) || isset(RegistriAnonimisasi::DIPERTAHANKAN["{$tabel}.{$kolom}"])) {
                    continue;
                }

                $belumTercatat[] = "{$tabel}.{$kolom}";
            }
        }

        expect($belumTercatat)->toBe([]);
    });

    it('entri DIPERTAHANKAN selalu merujuk kolom yang masih ada dan berpola data pribadi (tidak ada alasan basi)', function (): void {
        $skema = SkemaKolomUji();
        $basi = [];

        foreach (RegistriAnonimisasi::DIPERTAHANKAN as $kunci => $alasan) {
            [$tabel, $kolom] = explode('.', $kunci, 2);

            if (! isset($skema[$tabel][$kolom]) || preg_match(POLA_KOLOM_PRIBADI, $kolom) !== 1 || trim($alasan) === '') {
                $basi[] = $kunci;
            }
        }

        expect($basi)->toBe([]);
    });

    it('tabel pembawa pesan, kredensial, atau token yang ditambahkan nanti harus masuk HAPUS_BARIS atau diputuskan', function (): void {
        // Tabel ber-IdTenant dengan kolom Tujuan/Isi pesan terkirim atau hash kredensial menyimpan kontak pelanggan.
        $skema = SkemaKolomUji();
        $rawan = [];

        foreach (TabelBerTenantUji() as $tabel) {
            if (in_array($tabel, RegistriAnonimisasi::HAPUS_BARIS, true) || isset(RegistriAnonimisasi::ANONIMKAN[$tabel])) {
                continue;
            }

            if (isset($skema[$tabel]['Tujuan']) || isset($skema[$tabel]['HashKode']) || isset($skema[$tabel]['SidikWajah'])) {
                $rawan[] = $tabel;
            }
        }

        expect($rawan)->toBe([]);
    });
});

describe('P-07 folder berkas tenant', function (): void {
    it('setiap folder yang diukur untuk kuota penyimpanan ditetapkan nasibnya saat akun ditutup', function (): void {
        $terdaftar = array_merge(DaftarFolderBerkasTenant::PRIBADI, DaftarFolderBerkasTenant::PEMBUKUAN);
        $tidakTercakup = array_values(array_filter(PengukurPenyimpanan::FOLDER, fn (array $folder): bool => ! in_array($folder, $terdaftar, true)));

        expect($tidakTercakup)->toBe([]);
    });

    it('semua kunci disk di daftar folder adalah konfigurasi yang ada', function (): void {
        $hilang = [];

        foreach (array_merge(DaftarFolderBerkasTenant::PRIBADI, DaftarFolderBerkasTenant::PEMBUKUAN) as [$kunci, $pola]) {
            if (config($kunci) === null) {
                $hilang[] = $kunci;
            }
        }

        expect($hilang)->toBe([]);
    });

    it('tabel yang menyimpan jalur berkas pribadi dikosongkan jalurnya (jalur tidak menggantung)', function (): void {
        // Setiap kolom Path* di tabel ber-IdTenant kini ada di registri (diganti) atau di daftar bukti pembukuan.
        $skema = SkemaKolomUji();
        $path = [];

        foreach (TabelBerTenantUji() as $tabel) {
            foreach (array_keys($skema[$tabel]) as $kolom) {
                if (str_starts_with($kolom, 'Path') && ! in_array($tabel, RegistriAnonimisasi::HAPUS_BARIS, true)
                    && ! isset(RegistriAnonimisasi::ANONIMKAN[$tabel][$kolom]) && ! isset(RegistriAnonimisasi::DIPERTAHANKAN["{$tabel}.{$kolom}"])) {
                    $path[] = "{$tabel}.{$kolom}";
                }
            }
        }

        expect($path)->toBe([])->and(Schema::hasTable('PermintaanPenghapusanData'))->toBeTrue();
    });
});
