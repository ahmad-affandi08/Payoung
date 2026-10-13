<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Laporan;

use App\Domain\Bersama\Laporan\ItemRingkasan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Laporan\PembuatDefinisiLaporan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Laporan\Aksi\UbahLanggananInsightMingguan;
use App\Domain\Laporan\Data\DataPeriodeLaporan;
use App\Domain\Laporan\Kueri\LanggananInsight;
use App\Domain\Laporan\Kueri\LaporanPajak;
use App\Domain\Laporan\Kueri\LaporanPenjualan;
use App\Domain\Laporan\Kueri\LaporanStok;
use App\Domain\Laporan\Layanan\PenulisCsvLaporan;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Kueri\AgregatPenjualan;
use App\Domain\Penjualan\Layanan\PenulisXmlCoretax;
use App\Domain\Penjualan\Layanan\PenyusunFakturPajakCoretax;
use App\Domain\Penjualan\Layanan\PenyusunNotaReturPajak;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan back-office F-14a ("Rincian F-14a"), baca saja, dibatasi outlet akses pelaku:
 * - penjualan (`laporan.penjualan.lihat`): tab ringkasan harian, per produk (TabelData mode server lewat URL yang sama
 *   dengan `Accept: application/json`), kategori, jam, kasir, kanal, metode bayar, diskon; ekspor CSV sesuai saring;
 * - pajak (`laporan.keuangan.lihat`): PB1/PBJT per outlet per bulan & PPN keluaran per bulan; ekspor CSV; ringkasan & ekspor
 *   XML Faktur Pajak Keluaran Coretax dari faktur grosir (PRD v3.12) dan rekap nota retur pajak dari retur grosir (v4.08);
 * - stok (`persediaan.lihat`): nilai persediaan pada tanggal, stok kritis & batch kedaluwarsa (F-05g), saran restock
 *   dari laju pemakaian 28 hari (X6); ekspor CSV.
 */
final class LaporanKontroler extends DasarKelolaKontroler
{
    /** Batas periode laporan pajak (per bulan). */
    private const MAKS_HARI_PAJAK = 366;

    public function Penjualan(Request $permintaan, LaporanPenjualan $laporan, TanggalBisnisOutlet $tanggal, AksesPengguna $akses, LanggananInsight $langganan): Response|JsonResponse
    {
        $saring = $laporan->BacaSaring($permintaan->query(), $this->IdOutletBoleh(), $tanggal->Hitung(null));
        $tabel = $saring['Tab'] === 'detail'
            ? DataPermintaanTabel::Dari($permintaan->query(), AgregatPenjualan::KOLOM_URUT_DETAIL, AgregatPenjualan::URUT_BAWAAN_DETAIL)
            : DataPermintaanTabel::Dari($permintaan->query(), AgregatPenjualan::KOLOM_URUT_PRODUK, AgregatPenjualan::URUT_BAWAAN_PRODUK);

        if (ResponsTabel::MintaData($permintaan)) {
            return response()->json($laporan->AmbilIsiTab($saring['Tab'], $saring['Saring'], $tabel));
        }

        $pelaku = $this->Pelaku();
        $pemilik = ($akses->Ambil($this->IdTenant(), $pelaku->Id)['Pemilik'] ?? false) === true;

        return Inertia::render('Kelola/Laporan/Penjualan', [
            ...$laporan->AmbilHalaman($saring, $this->IdOutletBoleh(), $tabel),
            // X6 (v3.79, D-33): langganan insight mingguan lewat WhatsApp (Owner bawaan berlangganan).
            'InsightWhatsapp' => [
                'BisaWhatsapp' => PesanWhatsapp::AmbilNomorSah($pelaku->NoHp) !== null,
                'Aktif' => PesanWhatsapp::AmbilNomorSah($pelaku->NoHp) !== null && $langganan->CekAktif($pelaku->Id, $pemilik),
            ],
        ]);
    }

    public function UbahInsightWhatsapp(Request $permintaan, UbahLanggananInsightMingguan $ubah): RedirectResponse
    {
        $aktif = (bool) $permintaan->validate(['Aktif' => ['required', 'boolean']], attributes: ['Aktif' => 'insight mingguan'])['Aktif'];

        if ($aktif && PesanWhatsapp::AmbilNomorSah($this->Pelaku()->NoHp) === null) {
            return back()->withErrors(['Aktif' => 'Akun Anda belum punya nomor HP. Tambahkan nomor HP dulu untuk menerima insight.']);
        }

        $ubah->Jalankan($this->Pelaku()->Id, $aktif);

        return back()->with('Kilat', $aktif ? 'Insight penjualan dikirim ke WhatsApp Anda setiap Senin pagi.' : 'Insight mingguan lewat WhatsApp dimatikan.');
    }

    private const JUDUL_TAB_PENJUALAN = [
        'harian' => 'Ringkasan Harian', 'detail' => 'Detail Penjualan', 'produk' => 'Penjualan per Produk', 'kategori' => 'Penjualan per Kategori',
        'jam' => 'Penjualan per Jam', 'kasir' => 'Penjualan per Kasir', 'kanal' => 'Penjualan per Kanal', 'metode' => 'Metode Pembayaran',
        'diskon' => 'Diskon per Kasir', 'void-item' => 'Void Item Pesanan Meja', 'anti-fraud' => 'Anti-fraud Kasir', 'abc' => 'Analisis ABC', 'menu' => 'Menu Engineering', 'pelanggan' => 'Penjualan per Pelanggan', 'outlet' => 'Perbandingan Outlet',
        'waktu-saji' => 'Waktu Saji Dapur', 'food-cost' => 'Food Cost Teoretis vs Aktual', 'performa-kasir' => 'Performa Kasir', 'prediksi' => 'Prediksi Penjualan', 'bundling' => 'Rekomendasi Bundling', 'umpan-balik' => 'Umpan Balik Pelanggan',
    ];

    public function EksporPenjualan(Request $permintaan, LaporanPenjualan $laporan, TanggalBisnisOutlet $tanggal): SymfonyResponse
    {
        $saring = $laporan->BacaSaring($permintaan->query(), $this->IdOutletBoleh(), $tanggal->Hitung(null));
        $cari = is_string($permintaan->query('cari')) ? mb_substr(trim($permintaan->query('cari')), 0, 100) : '';
        [$kolom, $baris] = $laporan->AmbilEkspor($saring['Tab'], $saring['Saring'], $cari);
        $periode = $saring['Periode'];
        $label = $laporan->AmbilLabelSaringan($saring, $this->IdOutletBoleh());
        $judulTab = self::JUDUL_TAB_PENJUALAN[$saring['Tab']] ?? 'Penjualan';

        return $this->SajikanLaporan(
            $permintaan,
            $saring['Tab'] === 'detail' ? 'Detail Penjualan' : "Laporan Penjualan | {$judulTab}",
            PembuatDefinisiLaporan::NamaBerkas('laporan-penjualan', $saring['Tab'], $periode->dari->toDateString(), $periode->sampai->toDateString()),
            $kolom,
            $baris,
            [
                ['Periode', $this->LabelPeriode($periode->dari, $periode->sampai)],
                ['Jenis Order', $label['Kanal']],
                ['Kasir', $label['Kasir']],
                ['Pencarian', $cari === '' ? '-' : $cari],
            ],
            $laporan->RingkasanPeriode($saring['Saring']),
            $this->LabelCakupan($label['Outlet']),
            $laporan->WaktuTerakhirDiterima($saring['Saring']),
        );
    }

    public function Pajak(Request $permintaan, LaporanPajak $laporan, PetaUuidOutlet $outlet, TanggalBisnisOutlet $tanggal): Response
    {
        [$periode, $uuidOutlet, $idOutlet] = $this->BacaSaringPajak($permintaan, $outlet, $tanggal->Hitung(null));

        return Inertia::render('Kelola/Laporan/Pajak', [
            'Saring' => ['Dari' => $periode->dari->toDateString(), 'Sampai' => $periode->sampai->toDateString(), 'Outlet' => $uuidOutlet],
            'Peringatan' => $periode->peringatan,
            'OpsiOutlet' => array_map(fn (array $o): array => ['Nilai' => $o['Uuid'], 'Label' => $o['Nama']], $outlet->AmbilRingkas($this->IdOutletBoleh())),
            ...$laporan->Ambil($periode->dari, $periode->sampai, $idOutlet),
        ]);
    }

    public function EksporPajak(Request $permintaan, LaporanPajak $laporan, PetaUuidOutlet $outlet, TanggalBisnisOutlet $tanggal): SymfonyResponse
    {
        [$periode, $uuidOutlet, $idOutlet] = $this->BacaSaringPajak($permintaan, $outlet, $tanggal->Hitung(null));
        $isi = $laporan->Ambil($periode->dari, $periode->sampai, $idOutlet);

        if ($permintaan->query('jenis') === 'ppn-masukan') {
            return $this->EksporPpnMasukan($permintaan, $isi['PpnMasukan'], $periode, $uuidOutlet, $outlet);
        }

        $ppn = $permintaan->query('jenis') === 'ppn';
        $pilih = $ppn
            ? ['Bulan', 'NamaJenisPajak', 'Tarif', 'Dpp', 'Pajak', 'DppRetur', 'PajakRetur', 'DppBersih', 'PajakBersih', 'JumlahTransaksi']
            : ['Bulan', 'NamaOutlet', 'NamaJenisPajak', 'Tarif', 'Dpp', 'Pajak', 'DppRetur', 'PajakRetur', 'DppBersih', 'PajakBersih', 'JumlahTransaksi'];
        $angka = [
            new KolomLaporan('Tarif (%)', JenisKolom::Persen), new KolomLaporan('DPP', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('Pajak', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('DPP retur', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('Pajak retur', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('DPP bersih', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('Pajak bersih', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Jumlah transaksi', JenisKolom::Bilangan, jumlahkan: true),
        ];
        $kolom = $ppn
            ? [new KolomLaporan('Bulan'), new KolomLaporan('Jenis pajak', JenisKolom::Teks, 26), ...$angka]
            : [new KolomLaporan('Bulan'), new KolomLaporan('Outlet', JenisKolom::Teks, 26), new KolomLaporan('Jenis pajak', JenisKolom::Teks, 26), ...$angka];
        $baris = array_map(fn (array $b): array => array_map(fn (string $k): string|int|null => self::Sel($b[$k] ?? null), $pilih), $ppn ? $isi['Ppn'] : $isi['Pbjt']);
        $namaOutlet = '';

        foreach ($outlet->AmbilRingkas($this->IdOutletBoleh()) as $o) {
            if ($uuidOutlet !== '' && $o['Uuid'] === $uuidOutlet) {
                $namaOutlet = $o['Nama'];
            }
        }

        return $this->SajikanLaporan(
            $permintaan,
            $ppn ? 'Laporan Pajak | PPN Keluaran' : 'Laporan Pajak | PB1/PBJT',
            PembuatDefinisiLaporan::NamaBerkas('laporan-pajak', $ppn ? 'ppn' : 'pbjt', $periode->dari->toDateString(), $periode->sampai->toDateString()),
            $kolom,
            $baris,
            [['Periode', $this->LabelPeriode($periode->dari, $periode->sampai)]],
            null,
            $this->LabelCakupan($namaOutlet),
        );
    }

    /**
     * Ekspor PPN masukan (TAX-05): per bulan × tarif × dapat/tidaknya dikreditkan, dengan baris ringkas kurang/lebih bayar
     * di blok saringan kop.
     *
     * @param  list<array<string, mixed>>  $baris
     */
    private function EksporPpnMasukan(Request $permintaan, array $baris, DataPeriodeLaporan $periode, string $uuidOutlet, PetaUuidOutlet $outlet): SymfonyResponse
    {
        $kolom = [
            new KolomLaporan('Bulan'), new KolomLaporan('Tarif (%)', JenisKolom::Persen), new KolomLaporan('Dapat dikreditkan', JenisKolom::Teks, 16),
            new KolomLaporan('Jumlah faktur', JenisKolom::Bilangan, jumlahkan: true), new KolomLaporan('Nilai sebelum PPN', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('PPN masukan', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('Nilai retur', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('PPN retur', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('PPN masukan bersih', JenisKolom::Uang, jumlahkan: true),
        ];
        $isi = array_map(fn (array $b): array => [
            self::Sel($b['Bulan'] ?? null), self::Sel($b['Tarif'] ?? null), $b['Dikreditkan'] === true ? 'Ya' : 'Tidak', self::Sel($b['JumlahFaktur'] ?? null),
            self::Sel($b['Nilai'] ?? null), self::Sel($b['Pajak'] ?? null), self::Sel($b['NilaiRetur'] ?? null), self::Sel($b['PajakRetur'] ?? null), self::Sel($b['PajakBersih'] ?? null),
        ], $baris);
        $namaOutlet = '';

        foreach ($outlet->AmbilRingkas($this->IdOutletBoleh()) as $o) {
            if ($uuidOutlet !== '' && $o['Uuid'] === $uuidOutlet) {
                $namaOutlet = $o['Nama'];
            }
        }

        return $this->SajikanLaporan(
            $permintaan,
            'Laporan Pajak | PPN Masukan',
            PembuatDefinisiLaporan::NamaBerkas('laporan-pajak', 'ppn-masukan', $periode->dari->toDateString(), $periode->sampai->toDateString()),
            $kolom,
            $isi,
            [['Periode (tanggal faktur)', $this->LabelPeriode($periode->dari, $periode->sampai)]],
            null,
            $this->LabelCakupan($namaOutlet),
        );
    }

    /** Ringkasan kesiapan ekspor Faktur Pajak Coretax untuk periode & outlet terpilih (JSON; dimuat panel di halaman pajak). */
    public function RingkasFakturKeluaran(Request $permintaan, PenyusunFakturPajakCoretax $penyusun, PetaUuidOutlet $outlet, TanggalBisnisOutlet $tanggal): JsonResponse
    {
        [$periode, , $idOutlet] = $this->BacaSaringPajak($permintaan, $outlet, $tanggal->Hitung(null));

        return response()->json($penyusun->Susun($periode->dari, $periode->sampai, $idOutlet)->KeRingkasan());
    }

    public function EksporFakturKeluaran(
        Request $permintaan,
        PenyusunFakturPajakCoretax $penyusun,
        PenulisXmlCoretax $penulis,
        PetaUuidOutlet $outlet,
        TanggalBisnisOutlet $tanggal,
    ): SymfonyResponse {
        [$periode, , $idOutlet] = $this->BacaSaringPajak($permintaan, $outlet, $tanggal->Hitung(null));
        $hasil = $penyusun->Susun($periode->dari, $periode->sampai, $idOutlet);

        if (! $hasil->BisaDiekspor()) {
            abort(422, $hasil->masalahUmum[0] ?? 'Tidak ada faktur yang siap diekspor pada periode ini.');
        }

        return response($penulis->Tulis($hasil), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"faktur-pajak-keluaran-{$periode->dari->toDateString()}-{$periode->sampai->toDateString()}.xml\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Ringkasan nota retur pajak dari retur grosir pada periode & outlet terpilih (JSON; PRD v4.08). */
    public function RingkasNotaRetur(Request $permintaan, PenyusunNotaReturPajak $penyusun, PetaUuidOutlet $outlet, TanggalBisnisOutlet $tanggal): JsonResponse
    {
        [$periode, , $idOutlet] = $this->BacaSaringPajak($permintaan, $outlet, $tanggal->Hitung(null));

        return response()->json($penyusun->Susun($periode->dari, $periode->sampai, $idOutlet)->KeRingkasan());
    }

    /** CSV rekap nota retur pajak per barang (PRD v4.08); 422 bila tidak ada yang siap. */
    public function EksporNotaRetur(Request $permintaan, PenyusunNotaReturPajak $penyusun, PetaUuidOutlet $outlet, TanggalBisnisOutlet $tanggal): StreamedResponse
    {
        [$periode, , $idOutlet] = $this->BacaSaringPajak($permintaan, $outlet, $tanggal->Hitung(null));
        $hasil = $penyusun->Susun($periode->dari, $periode->sampai, $idOutlet);

        if (! $hasil->BisaDiekspor()) {
            abort(422, $hasil->masalahUmum[0] ?? 'Tidak ada nota retur yang siap pada periode ini.');
        }

        [$judul, $baris] = $hasil->KeCsv();

        return PenulisCsvLaporan::Alirkan("nota-retur-pajak-{$periode->dari->toDateString()}-{$periode->sampai->toDateString()}", $judul, $baris);
    }

    public function Stok(Request $permintaan, LaporanStok $laporan, TanggalBisnisOutlet $tanggal): Response
    {
        [$tab, $pada, $uuidGudang, $hari] = $this->BacaSaringStok($permintaan, $tanggal->Hitung(null));
        $gudang = $laporan->AmbilGudang($this->IdOutletBoleh());

        return Inertia::render('Kelola/Laporan/Stok', [
            'Saring' => ['Tab' => $tab, 'Tanggal' => $pada->toDateString(), 'Gudang' => $uuidGudang, 'Hari' => $hari],
            'OpsiGudang' => array_values(array_map(fn ($g): array => ['Nilai' => $g->uuid, 'Label' => $g->namaOutlet === null ? $g->nama : "{$g->nama} ({$g->namaOutlet})"], $gudang)),
            'Nilai' => $tab === 'nilai' ? $laporan->NilaiPersediaan($pada, $this->IdOutletBoleh(), $uuidGudang) : null,
            'Kritis' => $tab === 'kritis' ? $laporan->StokKritis($this->IdOutletBoleh(), $uuidGudang) : null,
            'Kedaluwarsa' => $tab === 'kedaluwarsa' ? $laporan->BatchKedaluwarsa($this->IdOutletBoleh(), $tanggal->Hitung(null), $uuidGudang) : null,
            'Perputaran' => $tab === 'perputaran' ? $laporan->Perputaran($this->IdOutletBoleh(), $tanggal->Hitung(null), $uuidGudang, $hari) : null,
            'Restock' => $tab === 'restock' ? $laporan->SaranRestock($this->IdOutletBoleh(), $tanggal->Hitung(null), $uuidGudang, $hari) : null,
            'Rencana' => $tab === 'rencana' ? $laporan->RencanaProduksi($this->IdOutletBoleh(), $tanggal->Hitung(null), $pada, $uuidGudang) : null,
        ]);
    }

    public function EksporStok(Request $permintaan, LaporanStok $laporan, TanggalBisnisOutlet $tanggal): SymfonyResponse
    {
        [$tab, $pada, $uuidGudang, $hari] = $this->BacaSaringStok($permintaan, $tanggal->Hitung(null));
        $namaGudang = 'Semua Lokasi Stok';

        foreach ($laporan->AmbilGudang($this->IdOutletBoleh()) as $g) {
            if ($uuidGudang !== '' && $g->uuid === $uuidGudang) {
                $namaGudang = $g->namaOutlet === null ? $g->nama : "{$g->nama} ({$g->namaOutlet})";
            }
        }

        $t = static fn (string $judul, int $lebar = 0): KolomLaporan => new KolomLaporan($judul, JenisKolom::Teks, $lebar > 0 ? $lebar : null);
        $q = static fn (string $judul): KolomLaporan => new KolomLaporan($judul, JenisKolom::Kuantitas);
        $n = static fn (string $judul): KolomLaporan => new KolomLaporan($judul, JenisKolom::Bilangan);
        $saringanGudang = ['Lokasi Stok', $namaGudang];

        if ($tab === 'perputaran') {
            $isi = $laporan->Perputaran($this->IdOutletBoleh(), $tanggal->Hitung(null), $uuidGudang, $hari)['Baris'];

            return $this->SajikanLaporan(
                $permintaan,
                'Laporan Stok | Slow & Fast Moving',
                "laporan-perputaran-stok-{$hari}-hari",
                [$t('Produk', 32), $t('SKU', 16), $t('Satuan', 10), $t('Lokasi stok', 22), $t('Outlet', 22), $t('Kelas', 10), $q("Terpakai {$hari} hari"), $q('Rata-rata per hari'), $q('Saldo'), new KolomLaporan('Nilai persediaan', JenisKolom::Uang), new KolomLaporan('Perputaran', JenisKolom::Kuantitas), $n('Cukup untuk (hari)')],
                array_map(
                    fn (array $b): array => [$b['NamaProduk'], $b['Sku'], $b['SimbolSatuan'], $b['NamaGudang'], $b['NamaOutlet'], $b['Kelas'], $b['Pakai'], $b['RataPerHari'], $b['Saldo'], $b['Nilai'], $b['Perputaran'], $b['HariCover']],
                    $isi,
                ),
                [['Tanggal', $this->LabelPeriode($tanggal->Hitung(null), $tanggal->Hitung(null))], $saringanGudang, ['Rentang pemakaian', "{$hari} hari terakhir (sampai kemarin)"]],
                null,
                $this->LabelCakupan(),
            );
        }

        if ($tab === 'rencana') {
            $rencana = $laporan->RencanaProduksi($this->IdOutletBoleh(), $tanggal->Hitung(null), $pada, $uuidGudang);

            return $this->SajikanLaporan(
                $permintaan,
                'Laporan Stok | Rencana Produksi',
                "rencana-produksi-{$rencana['Target']}",
                [$t('Produk', 32), $t('SKU', 16), $t('Satuan', 10), $t('Lokasi stok', 22), $t('Outlet', 22), $q('Rata-rata hari yang sama'), $q('Pre-order'), $q('Kebutuhan'), $q('Saldo'), $q('Rekomendasi produksi')],
                array_map(
                    fn (array $b): array => [$b['NamaProduk'], $b['Sku'], $b['SimbolSatuan'], $b['NamaGudang'], $b['NamaOutlet'], $b['RataHariSama'], $b['PreOrder'], $b['Kebutuhan'], $b['Saldo'], $b['Rekomendasi']],
                    $rencana['Baris'],
                ),
                [['Untuk tanggal', $this->LabelPeriode($pada, $pada)], $saringanGudang, ['Dasar permintaan', "rata-rata hari yang sama dalam {$rencana['Minggu']} minggu terakhir, dibandingkan pre-order"]],
                null,
                $this->LabelCakupan(),
            );
        }

        if ($tab === 'restock') {
            $isi = $laporan->SaranRestock($this->IdOutletBoleh(), $tanggal->Hitung(null), $uuidGudang, $hari)['Baris'];

            return $this->SajikanLaporan(
                $permintaan,
                'Laporan Stok | Saran Restock',
                "laporan-saran-restock-{$hari}-hari",
                [$t('Produk', 32), $t('SKU', 16), $t('Satuan', 10), $t('Lokasi stok', 22), $t('Outlet', 22), $q('Terpakai 28 hari'), $q('Rata-rata per hari'), new KolomLaporan('Faktor musim', JenisKolom::Kuantitas), $q('Perkiraan per hari'), $q('Saldo'), $n('Habis dalam (hari)'), $q('Saran beli')],
                array_map(
                    fn (array $b): array => [$b['NamaProduk'], $b['Sku'], $b['SimbolSatuan'], $b['NamaGudang'], $b['NamaOutlet'], $b['Pakai'], $b['RataPerHari'], $b['FaktorMusim'], $b['RataPerkiraan'], $b['Saldo'], $b['HariHabis'], $b['SaranBeli']],
                    $isi,
                ),
                [['Tanggal', $this->LabelPeriode($tanggal->Hitung(null), $tanggal->Hitung(null))], $saringanGudang, ['Cakupan', "{$hari} hari ke depan"]],
                null,
                $this->LabelCakupan(),
            );
        }

        if ($tab === 'kritis') {
            $isi = $laporan->StokKritis($this->IdOutletBoleh(), $uuidGudang)['Baris'];

            return $this->SajikanLaporan(
                $permintaan,
                'Laporan Stok | Stok Kritis',
                'laporan-stok-kritis',
                [$t('Produk', 32), $t('SKU', 16), $t('Satuan', 10), $t('Lokasi stok', 22), $t('Outlet', 22), $q('Saldo'), $q('Stok minimum'), $q('Kekurangan')],
                array_map(
                    fn (array $b): array => [$b['NamaProduk'], $b['Sku'], $b['SimbolSatuan'], $b['NamaGudang'], $b['NamaOutlet'], $b['Saldo'], $b['StokMinimum'], $b['Kekurangan']],
                    $isi,
                ),
                [['Per Tanggal', $this->LabelPeriode($tanggal->Hitung(null), $tanggal->Hitung(null))], $saringanGudang],
                null,
                $this->LabelCakupan(),
            );
        }

        if ($tab === 'kedaluwarsa') {
            $isi = $laporan->BatchKedaluwarsa($this->IdOutletBoleh(), $tanggal->Hitung(null), $uuidGudang)['Baris'];

            return $this->SajikanLaporan(
                $permintaan,
                'Laporan Stok | Batch Kedaluwarsa',
                'laporan-batch-kedaluwarsa',
                [$t('Produk', 32), $t('SKU', 16), $t('Satuan', 10), $t('Nomor batch', 18), $t('Lokasi stok', 22), $t('Outlet', 22), new KolomLaporan('Kedaluwarsa', JenisKolom::Tanggal), $n('Sisa hari'), $t('Status', 14), $q('Sisa')],
                array_map(
                    fn (array $b): array => [$b['NamaProduk'], $b['Sku'], $b['SimbolSatuan'], $b['NomorBatch'], $b['NamaGudang'], $b['NamaOutlet'], $b['TanggalKedaluwarsa'], $b['SisaHari'], $b['Status'], $b['Sisa']],
                    $isi,
                ),
                [['Per Tanggal', $this->LabelPeriode($tanggal->Hitung(null), $tanggal->Hitung(null))], $saringanGudang],
                null,
                $this->LabelCakupan(),
            );
        }

        $isi = $laporan->NilaiPersediaan($pada, $this->IdOutletBoleh(), $uuidGudang);
        $baris = [
            ...array_map(fn (array $b): array => ['Lokasi stok', "{$b['NamaGudang']} ({$b['NamaOutlet']})", $b['JumlahProduk'], $b['Nilai']], $isi['PerGudang']),
            ...array_map(fn (array $b): array => ['Kategori', $b['NamaKategori'], $b['JumlahProduk'], $b['Nilai']], $isi['PerKategori']),
        ];

        return $this->SajikanLaporan(
            $permintaan,
            'Laporan Stok | Nilai Persediaan',
            "laporan-nilai-persediaan-{$pada->toDateString()}",
            [$t('Kelompok', 16), $t('Nama', 40), $n('Jumlah produk'), new KolomLaporan('Nilai persediaan', JenisKolom::Uang)],
            $baris,
            [['Per Tanggal', $this->LabelPeriode($pada, $pada)], $saringanGudang],
            [
                new ItemRingkasan('Total Nilai Persediaan', (string) ($isi['Total']['Nilai'] ?? '0.00'), JenisKolom::Uang),
                new ItemRingkasan('Jumlah Produk', (int) ($isi['Total']['JumlahProduk'] ?? 0), JenisKolom::Bilangan),
            ],
            $this->LabelCakupan(),
        );
    }

    /**
     * @return array{0: DataPeriodeLaporan, 1: string, 2: list<int>|null}
     */
    private function BacaSaringPajak(Request $permintaan, PetaUuidOutlet $outlet, CarbonImmutable $hariIni): array
    {
        $sampai = DataPeriodeLaporan::Urai($permintaan->query('sampai')) ?? $hariIni;
        $dari = $permintaan->query('dari') ?? $sampai->startOfMonth()->toDateString();
        $periode = DataPeriodeLaporan::Baca($dari, $sampai->toDateString(), $hariIni, 31, self::MAKS_HARI_PAJAK);
        $uuidOutlet = is_string($permintaan->query('outlet')) ? $permintaan->query('outlet') : '';
        $boleh = $this->IdOutletBoleh();
        $idOutlet = $boleh;

        if ($uuidOutlet !== '') {
            $id = $outlet->AmbilIdDariUuid([$uuidOutlet])[$uuidOutlet] ?? null;
            $idOutlet = $id !== null && ($boleh === null || in_array($id, $boleh, true)) ? [$id] : [];
        }

        return [$periode, $uuidOutlet, $idOutlet];
    }

    /**
     * @return array{0: 'nilai'|'kritis'|'kedaluwarsa'|'restock'|'perputaran'|'rencana', 1: CarbonImmutable, 2: string, 3: int}
     */
    private function BacaSaringStok(Request $permintaan, CarbonImmutable $hariIni): array
    {
        $tab = in_array($permintaan->query('tab'), ['kritis', 'kedaluwarsa', 'restock', 'perputaran', 'rencana'], true) ? $permintaan->query('tab') : 'nilai';
        $pada = DataPeriodeLaporan::Urai($permintaan->query('tanggal')) ?? ($tab === 'rencana' ? $hariIni->addDay() : $hariIni);
        $uuidGudang = is_string($permintaan->query('gudang')) ? $permintaan->query('gudang') : '';
        // X6: cakupan saran restock 7/14/30 hari; nilai lain jatuh ke bawaan 14.
        $hari = in_array((string) $permintaan->query('hari'), ['7', '14', '30'], true) ? (int) $permintaan->query('hari') : 14;

        // Slow/fast moving: rentang pemakaian 30/60/90 hari (bawaan 30).
        if ($tab === 'perputaran') {
            $hari = in_array((string) $permintaan->query('hari'), ['30', '60', '90'], true) ? (int) $permintaan->query('hari') : LaporanStok::RENTANG_BAWAAN_PERPUTARAN;
        }

        // Rencana produksi memang untuk hari yang akan datang (sampai 60 hari ke depan, mulai hari ini); tab lain tidak melewati hari ini.
        if ($tab === 'rencana') {
            return [$tab, $pada->lessThan($hariIni) ? $hariIni : ($pada->greaterThan($hariIni->addDays(60)) ? $hariIni->addDays(60) : $pada), $uuidGudang, $hari];
        }

        return [$tab, $pada->greaterThan($hariIni) ? $hariIni : $pada, $uuidGudang, $hari];
    }

    private static function Sel(mixed $nilai): string|int|null
    {
        return is_string($nilai) || is_int($nilai) || $nilai === null ? $nilai : null;
    }
}
