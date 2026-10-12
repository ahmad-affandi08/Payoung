<?php

declare(strict_types=1);

namespace App\Domain\Laporan\Aksi;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Laporan\Kueri\InsightMingguan;
use App\Domain\Laporan\Model\LanggananInsightMingguan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\KontakAnggotaTenant;
use App\Domain\Organisasi\Kueri\KonteksTindakanPengguna;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * X6 insight mingguan (v3.79; lewat WhatsApp sejak D-33): tiap Senin pagi kirim ringkasan minggu lalu ke anggota tenant
 * aktif yang berlangganan, punya nomor HP sah, dan boleh melihat laporan penjualan (Owner bawaan berlangganan). Angka mengikuti outlet akses penerima. Tanpa
 * penjualan di dua minggu terakhir = tidak dikirim. Paling banyak sekali per minggu per penerima (`TerakhirDikirim` =
 * Senin minggu laporan). Dipanggil dalam konteks tenant.
 */
final class KirimInsightMingguan
{
    public const KUNCI_FITUR = 'laporan.insight';

    public function __construct(
        private readonly KontakAnggotaTenant $kontak,
        private readonly KonteksTindakanPengguna $konteks,
        private readonly InsightMingguan $insight,
        private readonly ProfilTenant $profil,
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly PemeriksaFiturTenant $fitur,
    ) {}

    /** @return int jumlah pesan terkirim */
    public function Jalankan(int $idTenant, CarbonImmutable $hariIni): int
    {
        // D-111: insight mingguan adalah add-on "Forecast & insight" (`laporan.insight`); tanpa fiturnya tidak dikirim.
        if ((bool) config('tenant.PenegakanFiturPaket', true) && ! $this->fitur->CekAktif($idTenant, self::KUNCI_FITUR)) {
            return 0;
        }

        $pengirim = $this->whatsapp->AmbilAktif();

        if ($pengirim === null) {
            return 0;
        }

        $templat = $pengirim->CekResmi() ? $this->whatsapp->AmbilTemplatInsightMingguan() : null;
        $langganan = LanggananInsightMingguan::query()->get()->keyBy('IdPengguna');
        $namaUsaha = $this->profil->Ambil($idTenant)['Nama'];
        $senin = $hariIni->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
        $terkirim = 0;
        $simpanan = [];

        foreach ($this->kontak->AmbilWhatsapp($idTenant) as $anggota) {
            $baris = $langganan->get($anggota['Id']);

            if (! ($baris->Aktif ?? $anggota['Pemilik']) || $baris?->TerakhirDikirim?->toDateString() === $senin->toDateString()) {
                continue;
            }

            $konteks = $this->konteks->Buat($idTenant, $anggota['Id'], $hariIni);

            if (! $konteks->CekIzin(IzinTenant::LaporanPenjualanLihat->value)) {
                continue;
            }

            $kunci = $konteks->idOutletBoleh === null ? 'semua' : implode(',', $konteks->idOutletBoleh);
            $isi = $simpanan[$kunci] ??= $this->insight->Susun($konteks->idOutletBoleh, $hariIni);

            if ($isi === null) {
                continue;
            }

            // Saran restock = data stok; halaman sumbernya (`laporan/stok?tab=restock`) mensyaratkan `persediaan.lihat`.
            if (! $konteks->CekIzin(IzinTenant::PersediaanLihat->value)) {
                $isi['Restock'] = [];
            }

            $periode = $senin->translatedFormat('j M').' – '.$senin->addDays(6)->translatedFormat('j M Y');
            $tampil = self::FormatTampil($isi);
            $tautanLaporan = AlamatDomain::BuatUrlAbsolutTenant("/kelola/laporan/penjualan?dari={$isi['Dari']}&sampai={$isi['Sampai']}");
            $ringkas = "penjualan bersih {$tampil['Bersih']}".($tampil['Perubahan'] === null ? '' : " ({$tampil['Perubahan']})").", {$tampil['JumlahTransaksi']} transaksi";

            try {
                $hasil = $pengirim->Kirim(new PesanWhatsapp(
                    $anggota['NoHp'],
                    self::SusunTeks($anggota['Nama'], $namaUsaha, $periode, $tampil, $tautanLaporan, AlamatDomain::BuatUrlAbsolutTenant('/kelola/laporan/stok?tab=restock')),
                    $templat,
                    $templat === null ? [] : [$anggota['Nama'], $namaUsaha, $ringkas, $tautanLaporan],
                ));
            } catch (Throwable $galat) {
                report($galat);

                continue;
            }

            if (! $hasil->berhasil) {
                continue;
            }

            $baris ??= new LanggananInsightMingguan(['IdPengguna' => $anggota['Id'], 'Aktif' => true]);
            $baris->setAttribute('TerakhirDikirim', $senin->toDateString());
            $baris->save();
            $terkirim++;
        }

        return $terkirim;
    }

    /**
     * Teks WhatsApp insight mingguan (D-33): angka utama, produk terlaris/naik/turun, stok yang segera habis, tautan.
     *
     * @param  array<string, mixed>  $tampil  hasil `FormatTampil`
     */
    public static function SusunTeks(string $nama, string $namaUsaha, string $periode, array $tampil, string $tautanLaporan, string $tautanRestock): string
    {
        $baris = ["Halo {$nama}, ringkasan penjualan {$namaUsaha} {$periode}:", ''];
        $baris[] = "Penjualan bersih: {$tampil['Bersih']}".($tampil['Perubahan'] === null ? '' : " ({$tampil['Perubahan']} dari minggu sebelumnya, {$tampil['BersihSebelumnya']})");
        $baris[] = 'Transaksi: '.preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', (string) $tampil['JumlahTransaksi']).", rata-rata {$tampil['RataTransaksi']}";

        if ($tampil['HariTeramai'] !== null) {
            $baris[] = "Hari teramai: {$tampil['HariTeramai']}";
        }

        if ($tampil['Lebaran'] !== null) {
            $baris[] = '';
            $baris[] = "Musim Lebaran: {$tampil['Lebaran']}";
        }

        foreach ([['Terlaris', 'Produk terlaris', 'Bersih'], ['Naik', 'Naik paling banyak', 'Selisih'], ['Turun', 'Turun paling banyak', 'Selisih']] as [$kunci, $judul, $kolom]) {
            if ($tampil[$kunci] !== []) {
                $baris[] = '';
                $baris[] = "{$judul}:";

                foreach ($tampil[$kunci] as $p) {
                    $baris[] = "• {$p['Nama']}: {$p[$kolom]}";
                }
            }
        }

        if ($tampil['Restock'] !== []) {
            $baris[] = '';
            $baris[] = 'Stok yang segera habis:';

            foreach ($tampil['Restock'] as $r) {
                $baris[] = "• {$r['Nama']} ({$r['Lokasi']}, {$r['Habis']}): beli {$r['Saran']}";
            }

            $baris[] = "Saran restock: {$tautanRestock}";
        }

        $baris[] = '';
        $baris[] = "Laporan penjualan: {$tautanLaporan}";

        return implode("\n", $baris);
    }

    /**
     * Angka siap tampil di pesan (rupiah, persen berkoma, tanggal Indonesia).
     *
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>
     */
    public static function FormatTampil(array $isi): array
    {
        $rupiah = fn (mixed $nilai): string => Uang::Dari(is_string($nilai) ? $nilai : '0')->FormatRupiah();
        $persen = is_string($isi['PersenPerubahan'] ?? null) ? $isi['PersenPerubahan'] : null;

        return [
            'Bersih' => $rupiah($isi['Bersih']),
            'BersihSebelumnya' => $rupiah($isi['BersihSebelumnya']),
            'Perubahan' => $persen === null ? null : (str_starts_with($persen, '-') ? 'turun ' : 'naik ').str_replace('.', ',', ltrim($persen, '-')).'%',
            'JumlahTransaksi' => $isi['JumlahTransaksi'],
            'JumlahTransaksiSebelumnya' => $isi['JumlahTransaksiSebelumnya'],
            'RataTransaksi' => $rupiah($isi['RataTransaksi']),
            'HariTeramai' => is_array($isi['HariTeramai'] ?? null)
                ? CarbonImmutable::parse($isi['HariTeramai']['Tanggal'])->translatedFormat('l, j F').' ('.$rupiah($isi['HariTeramai']['Bersih']).')'
                : null,
            'Terlaris' => array_map(fn (array $p): array => ['Nama' => $p['NamaProduk'], 'Bersih' => $rupiah($p['Bersih'])], (array) $isi['Terlaris']),
            'Naik' => array_map(fn (array $p): array => ['Nama' => $p['NamaProduk'], 'Selisih' => '+'.$rupiah($p['Selisih'])], (array) $isi['Naik']),
            'Turun' => array_map(fn (array $p): array => ['Nama' => $p['NamaProduk'], 'Selisih' => $rupiah($p['Selisih'])], (array) $isi['Turun']),
            'Restock' => array_map(fn (array $r): array => [
                'Nama' => $r['NamaProduk'],
                'Lokasi' => $r['NamaGudang'],
                'Habis' => $r['HariHabis'] === 0 ? 'sudah habis' : "habis ±{$r['HariHabis']} hari lagi",
                'Saran' => rtrim(rtrim(str_replace('.', ',', $r['SaranBeli']), '0'), ',').' '.$r['SimbolSatuan'],
            ], (array) $isi['Restock']),
            'Lebaran' => is_array($isi['Lebaran'] ?? null)
                ? 'Idul Fitri '.CarbonImmutable::parse($isi['Lebaran']['Tanggal'])->translatedFormat('j F Y').' tinggal '.$isi['Lebaran']['SisaHari'].' hari. Saran restock sudah memperhitungkan penjualan Ramadan tahun lalu.'
                : null,
        ];
    }
}
