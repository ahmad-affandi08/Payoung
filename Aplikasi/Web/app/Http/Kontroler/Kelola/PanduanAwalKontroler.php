<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\PanduanAwal\Aksi\KonfirmasiPajakPanduan;
use App\Domain\PanduanAwal\Aksi\SelesaikanPanduanAwal;
use App\Domain\PanduanAwal\Aksi\SiapkanOtomatisPanduan;
use App\Domain\PanduanAwal\Aksi\SimpanProfilUsaha;
use App\Domain\PanduanAwal\Aksi\TandaiLangkahPanduan;
use App\Domain\PanduanAwal\Aksi\TerapkanTemplateSektor;
use App\Domain\PanduanAwal\Data\DataPajakPanduan;
use App\Domain\PanduanAwal\Data\HasilPenerapanTemplate;
use App\Domain\PanduanAwal\Enum\LangkahPanduan;
use App\Domain\PanduanAwal\Enum\StatusLangkahPanduan;
use App\Domain\PanduanAwal\Kueri\PilihanTemplate;
use App\Domain\PanduanAwal\Kueri\ProgresPanduan;
use App\Domain\PanduanAwal\Kueri\TemplateTerbit;
use App\Domain\PanduanAwal\Kueri\UsulanPajak;
use App\Domain\PanduanAwal\Model\ProgresPanduanAwal;
use App\Domain\Referensi\Kueri\WilayahKota;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PenyimpanLogoTenant;
use App\Http\Permintaan\Kelola\PanduanAwal\SimpanPajakPanduanPermintaan;
use App\Http\Permintaan\Kelola\PanduanAwal\SimpanProfilUsahaPermintaan;
use App\Http\Permintaan\Kelola\PanduanAwal\TerapkanTemplateSektorPermintaan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F-01 Panduan awal: ringkasan, langkah 1 (profil usaha), 2 (template sektor), 3 (pajak), lewati/selesai per langkah,
 * dan selesaikan panduan. Kontroler tipis: validasi di Permintaan, logika di Aksi/Kueri domain.
 */
final class PanduanAwalKontroler extends DasarPanduanAwalKontroler
{
    public function Indeks(): Response
    {
        $this->OutletPanduan();

        return Inertia::render('Kelola/PanduanAwal/Indeks', ['Progres' => $this->Progres()]);
    }

    public function TampilkanProfilUsaha(ProfilTenant $profilTenant, WilayahKota $wilayahKota): Response
    {
        $outlet = $this->OutletPanduan();
        $profil = $profilTenant->Ambil($this->IdTenant());

        return Inertia::render('Kelola/PanduanAwal/ProfilUsaha', [
            'Progres' => $this->Progres(),
            'Profil' => [
                'NamaUsaha' => $profil['Nama'],
                'Alamat' => $outlet->Alamat,
                'KodeKota' => $outlet->KodeKota,
                'Npwp' => $profil['Npwp'],
                'Pkp' => $profil['Pkp'],
                'TautanLogo' => $profil['PathLogo'] === null ? null : route('kelola.panduan-awal.profil-usaha.logo', ['v' => substr(hash('sha256', $profil['PathLogo']), 0, 12)]),
            ],
            'Kota' => array_map(fn (array $kota): array => [
                'Kode' => $kota['Kode'],
                'Nama' => $kota['Nama'],
                'NamaProvinsi' => $kota['NamaProvinsi'],
                'ZonaWaktu' => $kota['ZonaWaktu'],
            ], $wilayahKota->AmbilSemua()),
            'BatasLogo' => ['UkuranMaksimalKb' => (int) config('tenant.UkuranMaksimalLogoKb'), 'Ekstensi' => array_values((array) config('tenant.EkstensiLogo'))],
        ]);
    }

    public function SimpanProfilUsaha(SimpanProfilUsahaPermintaan $permintaan, SimpanProfilUsaha $simpan): RedirectResponse
    {
        $simpan->Jalankan($this->OutletPanduan(), $permintaan->AmbilData(), $permintaan->AmbilLogo(), $permintaan->boolean('HapusLogo'));

        return redirect()->route('kelola.panduan-awal.sektor')->with('Kilat', 'Profil usaha disimpan.');
    }

    public function UnduhLogo(ProfilTenant $profilTenant, PenyimpanLogoTenant $penyimpan): StreamedResponse
    {
        $path = $profilTenant->Ambil($this->IdTenant())['PathLogo'];
        abort_if($path === null, 404);

        return $penyimpan->Unduh($path);
    }

    public function TampilkanSektor(PilihanTemplate $pilihan): Response
    {
        return Inertia::render('Kelola/PanduanAwal/Sektor', [
            'Progres' => $this->Progres(),
            ...$pilihan->Ambil($this->OutletPanduanRingkas()),
        ]);
    }

    public function TerapkanSektor(TerapkanTemplateSektorPermintaan $permintaan, TerapkanTemplateSektor $terapkan, UsulanPajak $usulan, KonfirmasiPajakPanduan $konfirmasi): RedirectResponse
    {
        $hasil = $terapkan->Jalankan($this->OutletPanduan(), $permintaan->string('KodeTemplate')->toString(), $permintaan->AmbilSektorLain());

        // Audit kemudahan pakai #23: usaha bukan PKP yang tidak diusulkan memungut pajak daerah/biaya layanan (umumnya
        // non-F&B) tidak perlu melihat langkah Pajak: usulan langsung disimpan dan bisa diubah di Pengaturan › Pajak.
        $pajak = $usulan->Ambil($this->OutletPanduanRingkas());
        $nilai = $pajak['Nilai'];

        if (! $pajak['Pkp'] && ! $pajak['SudahDikonfirmasi'] && ! $nilai['PungutPbjt'] && ! $nilai['BiayaLayananAktif']) {
            $konfirmasi->Jalankan($this->OutletPanduan(), new DataPajakPanduan(false, false, '0', $nilai['HargaTermasukPajak']));

            return redirect()->route('kelola.panduan-awal.produk')->with('Kilat', self::PesanPenerapan($hasil).' Usaha Anda bukan PKP dan tidak memungut pajak daerah, jadi langkah Pajak dilewati otomatis (bisa diubah di Pengaturan › Pajak).');
        }

        return redirect()->route('kelola.panduan-awal.pajak')->with('Kilat', self::PesanPenerapan($hasil));
    }

    /** D-23 A: terapkan template + pajak usulan + produk contoh + metode bayar dalam satu klik, lalu ke langkah Perangkat. */
    public function SiapkanOtomatis(TerapkanTemplateSektorPermintaan $permintaan, SiapkanOtomatisPanduan $siapkan, SelesaikanPanduanAwal $selesaikan): RedirectResponse
    {
        $hasil = $siapkan->Jalankan($this->OutletPanduan(), $permintaan->string('KodeTemplate')->toString(), $permintaan->AmbilSektorLain());
        $progres = ProgresPanduanAwal::query()->first();
        $kurang = $progres?->Wajib === true ? app(ProgresPanduan::class)->AmbilLangkahWajibBelumSelesai() : [];
        $pesan = array_filter([
            "Template {$hasil['Template']} diterapkan.",
            $hasil['PajakDikonfirmasi'] ? 'Pajak diatur sesuai usulan.' : 'Periksa pengaturan pajak di langkah Pajak.',
            $hasil['JumlahProduk'] > 0 ? "{$hasil['JumlahProduk']} produk contoh ditambahkan. Ubah harganya kapan saja di menu Produk." : null,
            $hasil['ProdukTerlewatKuota'] > 0 ? "{$hasil['ProdukTerlewatKuota']} produk contoh tidak ditambahkan karena kuota paket penuh." : null,
            'Tunai siap dipakai.',
        ]);

        // Audit kemudahan pakai: jalur otomatis langsung menyelesaikan panduan bila semua langkah wajib sudah beres,
        // jadi back-office langsung terbuka; perangkat kasir tetap bisa ditambahkan di halaman berikutnya.
        if ($kurang === []) {
            $selesaikan->Jalankan($this->Pelaku()->Id, $this->OutletPanduan()->Id);

            // D-167: usaha Perusahaan tanpa kasir langsung diarahkan ke invoice pertamanya, bukan ke perangkat kasir.
            if (str_starts_with($permintaan->string('KodeTemplate')->toString(), 'BIZ-')) {
                return redirect()->route('kelola.invoice.buat')->with('Kilat', implode(' ', [...$pesan, 'Siap menagih. Buat invoice pertama Anda di sini.']));
            }

            return redirect()->route('kelola.panduan-awal.perangkat')->with('Kilat', implode(' ', [...$pesan, 'Toko siap berjualan. Tambahkan perangkat kasir sekarang, atau nanti dari Pengaturan › Perangkat kasir.']));
        }

        return redirect()->route($kurang[0]->AmbilNamaRute())->with('Kilat', implode(' ', [...$pesan, "Tinggal satu hal: {$kurang[0]->AmbilJudul()}."]));
    }

    public function TampilkanPajak(UsulanPajak $usulan): Response
    {
        return Inertia::render('Kelola/PanduanAwal/Pajak', [
            'Progres' => $this->Progres(),
            ...$usulan->Ambil($this->OutletPanduanRingkas()),
        ]);
    }

    public function SimpanPajak(SimpanPajakPanduanPermintaan $permintaan, KonfirmasiPajakPanduan $konfirmasi): RedirectResponse
    {
        $konfirmasi->Jalankan($this->OutletPanduan(), $permintaan->AmbilData());

        return redirect()->route('kelola.panduan-awal.produk')->with('Kilat', 'Pengaturan pajak disimpan.');
    }

    public function Lewati(string $langkah, TandaiLangkahPanduan $tandai): RedirectResponse
    {
        $kunci = LangkahPanduan::DariSlug($langkah) ?? abort(404);
        $tandai->Jalankan($kunci, StatusLangkahPanduan::Dilewati, $this->OutletPanduan()->Id);

        return $this->KeLangkahBerikutnya($kunci, "Langkah {$kunci->AmbilJudul()} dilewati. Anda bisa kembali kapan saja.");
    }

    public function TandaiSelesai(string $langkah, TandaiLangkahPanduan $tandai, TemplateTerbit $template): RedirectResponse
    {
        $kunci = LangkahPanduan::DariSlug($langkah) ?? abort(404);
        // Audit kemudahan pakai: belum ada template sektor terbit = langkah Sektor tidak boleh mengunci tenant baru;
        // pemilik lanjut tanpa template dan bisa menerapkannya nanti dari Pengaturan.
        $sektorTanpaTemplate = $kunci === LangkahPanduan::Sektor && $template->AmbilKode() === [];
        abort_unless($kunci->CekBisaDitandaiSelesaiLangsung() || $sektorTanpaTemplate, 404);
        $tandai->Jalankan($kunci, StatusLangkahPanduan::Selesai, $this->OutletPanduan()->Id);

        return $this->KeLangkahBerikutnya($kunci, "Langkah {$kunci->AmbilJudul()} selesai.");
    }

    public function Selesaikan(SelesaikanPanduanAwal $selesaikan): RedirectResponse
    {
        $selesaikan->Jalankan($this->Pelaku()->Id, $this->OutletPanduan()->Id);

        return redirect()->route('kelola.beranda')->with('Kilat', 'Panduan awal selesai. Lanjutkan dengan langkah berikutnya di bawah.');
    }

    private static function PesanPenerapan(HasilPenerapanTemplate $hasil): string
    {
        $bagian = array_filter([
            $hasil->jumlahAkun > 0 ? "{$hasil->jumlahAkun} akun" : null,
            $hasil->jumlahKategori > 0 ? "{$hasil->jumlahKategori} kategori" : null,
            $hasil->jumlahSatuan > 0 ? "{$hasil->jumlahSatuan} satuan" : null,
            $hasil->jumlahKelompokPajak > 0 ? "{$hasil->jumlahKelompokPajak} kelompok pajak" : null,
        ]);

        if ($bagian === []) {
            return $hasil->versiBerubah
                ? "Template {$hasil->namaTemplate} versi {$hasil->versi} diterapkan. Tidak ada data baru."
                : 'Template sudah diterapkan, tidak ada data baru.';
        }

        return "Template {$hasil->namaTemplate} versi {$hasil->versi} diterapkan: ".implode(', ', $bagian).' ditambahkan.';
    }
}
