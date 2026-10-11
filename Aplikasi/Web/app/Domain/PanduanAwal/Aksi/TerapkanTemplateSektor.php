<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Aksi;

use App\Domain\Akuntansi\Aksi\TambahkanAkunTemplate;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Kasir\Aksi\SiapkanKategoriBiayaBawaan;
use App\Domain\Katalog\Aksi\TambahkanKategoriTemplate;
use App\Domain\Katalog\Aksi\TambahkanSatuanTemplate;
use App\Domain\Katalog\Data\DataSatuanStandar;
use App\Domain\Organisasi\Aksi\CatatTemplateOutlet;
use App\Domain\Organisasi\Aksi\TambahkanStasiunDapurTemplate;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pajak\Aksi\TambahkanKelompokPajakTemplate;
use App\Domain\PanduanAwal\Data\HasilPenerapanTemplate;
use App\Domain\PanduanAwal\Enum\LangkahPanduan;
use App\Domain\PanduanAwal\Enum\StatusLangkahPanduan;
use App\Domain\PanduanAwal\Kueri\TemplateTerbit;
use App\Domain\PanduanAwal\Layanan\PembacaIsiTemplate;
use App\Domain\Penjualan\Aksi\SiapkanMetodePembayaranBawaan;
use App\Domain\Referensi\Kueri\SatuanStandarAktif;
use App\Domain\Tenant\Aksi\LengkapiPengaturanTenant;
use App\Domain\Tenant\Aksi\TambahkanFiturOutletTemplate;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * F-01 langkah 2: menerapkan versi terbit template sektor ke tenant & outlet wizard.
 * - BR-01.1: idempoten & aditif. Data yang sudah ada (akun, kategori, satuan, kelompok pajak, fitur outlet,
 *   pengaturan tenant) tidak ditimpa atau dihapus; menerapkan dua kali tidak menambah apa pun.
 * - BR-01.2: COA inti + ekstensi sektor dan pemetaan akun dibuat dari template.
 * - BR-01.3: modul template menjadi `OutletFitur`; fitur efektif tetap dibatasi paket (P-04).
 * - BR-P03.1: template & versi dicatat di outlet; versi baru tidak mengubah tenant sampai diterapkan lagi.
 * Semua penulisan lewat Aksi publik domain pemiliknya, dalam satu transaksi. Urutan kunci Tenant → Outlet → baris data
 * (v1.28) sehingga kirim ganda & langkah lain yang berjalan bersamaan aman dari deadlock.
 */
final class TerapkanTemplateSektor
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly TemplateTerbit $templateTerbit,
        private readonly PembacaIsiTemplate $pembaca,
        private readonly PenguncianTenant $penguncian,
        private readonly SatuanStandarAktif $satuanStandar,
        private readonly TambahkanAkunTemplate $tambahAkun,
        private readonly TambahkanSatuanTemplate $tambahSatuan,
        private readonly TambahkanKategoriTemplate $tambahKategori,
        private readonly TambahkanKelompokPajakTemplate $tambahKelompokPajak,
        private readonly TambahkanFiturOutletTemplate $tambahFitur,
        private readonly LengkapiPengaturanTenant $lengkapiPengaturan,
        private readonly SiapkanMetodePembayaranBawaan $siapkanMetodePembayaran,
        private readonly SiapkanKategoriBiayaBawaan $siapkanKategoriBiaya,
        private readonly CatatTemplateOutlet $catatTemplate,
        private readonly TambahkanStasiunDapurTemplate $tambahStasiun,
        private readonly TandaiLangkahPanduan $tandai,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<string>  $sektorLain  kode template sektor tambahan untuk usaha campuran (hanya dicatat)
     */
    public function Jalankan(Outlet $outlet, string $kodeTemplate, array $sektorLain = []): HasilPenerapanTemplate
    {
        $versi = $this->templateTerbit->Cari($kodeTemplate)
            ?? throw new PelanggaranAturanBisnis('TemplateTidakTersedia', 'Template ini belum tersedia. Pilih template lain.', 'KodeTemplate');
        $sektorLain = $this->PeriksaSektorLain($kodeTemplate, $sektorLain);
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($outlet, $versi, $kodeTemplate, $sektorLain, $idTenant): HasilPenerapanTemplate {
            // Urutan kunci: Tenant → Outlet (dicatat lebih dulu) → baris data template.
            $this->penguncian->Kunci($idTenant);
            $versiBerubah = $this->catatTemplate->Jalankan($outlet, $kodeTemplate, $versi->Id);
            $isi = $this->pembaca->Baca($versi->Isi);

            $akun = $this->tambahAkun->Jalankan($isi->akun, $isi->pemetaanAkun);
            $satuan = $this->tambahSatuan->Jalankan(array_map(
                fn (array $baris): DataSatuanStandar => new DataSatuanStandar($baris['Kode'], $baris['Nama'], $baris['Simbol'], $baris['BolehDesimal']),
                $this->satuanStandar->AmbilBerdasarkanKode($isi->kodeSatuan),
            ));
            $kategori = $this->tambahKategori->Jalankan($isi->kategori);
            $kelompokPajak = $this->tambahKelompokPajak->Jalankan($isi->kelompokPajak);
            $stasiun = $this->tambahStasiun->Jalankan($isi->stasiunDapur);
            $fitur = $this->tambahFitur->Jalankan(
                $outlet->Id,
                $isi->kunciFitur,
                $isi->modeKasir === [] ? null : ['ModeKasir' => $isi->modeKasir, 'ModeKasirDefault' => $isi->modeKasirDefault],
            );
            // Sektor usaha campuran bertambah (union) setiap kali template diterapkan; pengaturan lain hanya dilengkapi.
            $pengaturan = $this->lengkapiPengaturan->Jalankan(
                $idTenant,
                $isi->pengaturan->AmbilPengaturanTenant(),
                ['Sektor' => array_values(array_unique([$kodeTemplate, ...$sektorLain]))],
            );
            $this->siapkanMetodePembayaran->Jalankan($idTenant);
            $this->siapkanKategoriBiaya->Jalankan();
            $this->tandai->Jalankan(LangkahPanduan::Sektor, StatusLangkahPanduan::Selesai, $outlet->Id);

            $hasil = new HasilPenerapanTemplate(
                kodeTemplate: $kodeTemplate,
                namaTemplate: $versi->TemplateSektor->Nama,
                versi: $versi->Versi,
                jumlahAkun: count($akun['Akun']),
                jumlahPemetaan: count($akun['Pemetaan']),
                jumlahKategori: count($kategori),
                jumlahSatuan: count($satuan),
                jumlahKelompokPajak: count($kelompokPajak),
                jumlahFitur: count($fitur),
                pengaturanDitambahkan: count($pengaturan),
                versiBerubah: $versiBerubah,
                jumlahStasiunDapur: count($stasiun),
            );

            if ($hasil->CekAdaPerubahan()) {
                $this->audit->Catat('template-sektor.terapkan', $outlet, nilaiBaru: [
                    'KodeTemplate' => $kodeTemplate,
                    'Versi' => $versi->Versi,
                    'Ditambahkan' => [
                        'Akun' => $hasil->jumlahAkun,
                        'Pemetaan' => $hasil->jumlahPemetaan,
                        'Kategori' => $hasil->jumlahKategori,
                        'Satuan' => $hasil->jumlahSatuan,
                        'KelompokPajak' => $hasil->jumlahKelompokPajak,
                        'Fitur' => $hasil->jumlahFitur,
                        'Pengaturan' => $hasil->pengaturanDitambahkan,
                        'StasiunDapur' => $hasil->jumlahStasiunDapur,
                    ],
                ]);
            }

            return $hasil;
        });
    }

    /**
     * @param  list<string>  $sektorLain
     * @return list<string>
     */
    private function PeriksaSektorLain(string $kodeTemplate, array $sektorLain): array
    {
        if ($sektorLain === []) {
            return [];
        }

        $dikenal = $this->templateTerbit->AmbilKode();
        $hasil = [];

        foreach ($sektorLain as $kode) {
            if (! in_array($kode, $dikenal, true)) {
                throw new PelanggaranAturanBisnis('SektorTidakDikenal', 'Pilih jenis usaha tambahan dari daftar.', 'SektorLain');
            }

            if ($kode !== $kodeTemplate) {
                $hasil[] = $kode;
            }
        }

        return array_values(array_unique($hasil));
    }
}
