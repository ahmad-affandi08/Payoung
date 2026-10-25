<?php

declare(strict_types=1);

use App\Domain\Dukungan\Layanan\PenyamarDataPribadi;

/*
 * PGL-16 / BR-P09.1: penyamaran memakai nama kunci, jadi kunci data pribadi BARU yang dipakai di respons harus ikut
 * tercakup. Test ini memindai seluruh kode aplikasi untuk kunci berpola data pribadi (`'Kunci' =>`) dan gagal bila ada yang
 * tidak disamarkan `PenyamarDataPribadi` dan tidak tercatat di daftar pengecualian beralasan. Cara memperbaiki: tambahkan
 * kunci ke `PenyamarDataPribadi` (atau, bila memang bukan data pribadi pelanggan, ke daftar di bawah dengan alasan).
 */

const POLA_KUNCI_PRIBADI = '/^(NoHp|NoWa|NoTelepon|NomorHp|NomorTelepon|Telepon|Email|Alamat|Npwp|Nik|NomorRekening|TanggalLahir|Nama(Pelanggan|Pemesan|Pembeli|Kontak|Pasien|Penerima|Tamu|Dokter|PemilikRekening)|Kontak|Penerima|Pemesan)\w*$/';

/** @return array<string, string> kunci => alasan bukan data pribadi pelanggan atau sudah tersamar dari sumbernya */
function KunciPribadiDikecualikan(): array
{
    return [
        'AlamatIp' => 'Alamat jaringan perangkat/pengakses, bukan alamat tempat tinggal.',
        'AlamatApi' => 'URL layanan API, bukan alamat orang.',
        'AlamatSitus' => 'URL situs.',
        'AlamatOutlet' => 'Alamat usaha (outlet) pada pratinjau struk, bukan alamat pelanggan.',
        'TeleponOutlet' => 'Telepon usaha (outlet) pada pratinjau struk, bukan nomor pelanggan.',
        'AlamatModelWajah' => 'URL berkas model pengenalan wajah, bukan data orang.',
        'AlamatKode' => 'URL/penampung kode, bukan alamat orang.',
        'NikTersamar' => 'Sudah tersamar dari sumbernya.',
        'NpwpTersamar' => 'Sudah tersamar dari sumbernya.',
        'NoHpTersamar' => 'Sudah tersamar dari sumbernya.',
        'NoHpTerverifikasi' => 'Penanda benar/salah, bukan nomor.',
        'NoHpTerverifikasiPada' => 'Waktu verifikasi, bukan nomor.',
        'KontakWhatsapp' => 'Nomor WhatsApp bantuan Payoung (milik platform).',
        'Penerimaan' => 'Penerimaan barang/uang, bukan penerima orang.',
        'PenerimaanBarang' => 'Dokumen penerimaan barang.',
        'PenerimaanKe' => 'Urutan penerimaan.',
        'PenerimaanPada' => 'Waktu penerimaan.',
    ];
}

it('semua kunci berpola data pribadi di kode aplikasi tersamar atau dikecualikan dengan alasan', function (): void {
    $penyamar = new PenyamarDataPribadi;
    $kecuali = KunciPribadiDikecualikan();
    $ditemukan = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $berkas) {
        if (! $berkas instanceof SplFileInfo || $berkas->getExtension() !== 'php') {
            continue;
        }

        $isi = (string) file_get_contents($berkas->getPathname());

        if (preg_match_all("/'([A-Z][A-Za-z0-9]*)'\\s*=>/", $isi, $cocok) === false) {
            continue;
        }

        foreach ($cocok[1] as $kunci) {
            if (preg_match(POLA_KUNCI_PRIBADI, $kunci) === 1) {
                $ditemukan[$kunci] = true;
            }
        }
    }

    $tidakTercakup = [];

    foreach (array_keys($ditemukan) as $kunci) {
        if (isset($kecuali[$kunci])) {
            continue;
        }

        $contoh = str_starts_with($kunci, 'Email') ? 'orang@contoh.id' : 'Contoh123';
        $hasil = $penyamar->Samarkan([$kunci => $contoh]);

        if ($hasil[$kunci] === $contoh) {
            $tidakTercakup[] = $kunci;
        }
    }

    sort($tidakTercakup);
    expect($tidakTercakup)->toBe([]);
});

it('daftar pengecualian tidak berisi kunci yang sebenarnya sudah tersamar (alasan basi)', function (): void {
    $penyamar = new PenyamarDataPribadi;
    $basi = [];

    foreach (array_keys(KunciPribadiDikecualikan()) as $kunci) {
        $contoh = str_starts_with($kunci, 'Email') ? 'orang@contoh.id' : 'Contoh123';

        if ($penyamar->Samarkan([$kunci => $contoh])[$kunci] !== $contoh) {
            $basi[] = $kunci;
        }
    }

    expect($basi)->toBe([]);
});
