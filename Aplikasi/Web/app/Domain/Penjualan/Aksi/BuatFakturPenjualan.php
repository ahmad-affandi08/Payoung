<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Data\DataFakturPenjualan;
use App\Domain\Penjualan\Layanan\PenomorGrosir;
use App\Domain\Penjualan\Layanan\PenyusunJurnalGrosir;
use App\Domain\Penjualan\Model\FakturPenjualan;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menerbitkan faktur penjualan grosir atas surat jalan tercatat (F-12, §9.7, **BR-12.4** & **BR-12.5**, J-12.2).
 *
 * **Faktur gabungan dibatasi satu bulan kalender** karena begitulah bunyi UU PPN Pasal 13 ayat (2)/(2a): satu Faktur
 * Pajak gabungan boleh memuat seluruh penyerahan kepada pembeli yang sama dalam satu bulan kalender, dibuat paling lama
 * pada akhir bulan penyerahan. Surat jalan dari bulan berbeda ditolak (`SuratJalanBedaBulan`) — bukan kerewelan sistem,
 * tetapi supaya satu faktur tidak menggabungkan dua Masa Pajak.
 *
 * Yang **tidak** terjadi di sini: pendapatan, HPP, dan PPN. Ketiganya sudah diakui saat penyerahan (BR-12.2), jadi
 * jurnalnya hanya reklasifikasi J-12.2 (Dr Piutang Usaha / Cr Piutang Belum Difakturkan). Karena itu menerbitkan faktur
 * tidak bisa menggandakan pendapatan, dan angka faktur selalu Σ surat jalannya — tidak pernah dari klien.
 *
 * Piutangnya dibuat lewat layanan Pelanggan yang sama dengan penjualan tempo (BR-12.5), sehingga aging, pengingat, dan
 * pelunasan F-12 bagian 1 berlaku apa adanya.
 */
final class BuatFakturPenjualan
{
    public function __construct(
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly PenomorGrosir $penomor,
        private readonly PenyusunJurnalGrosir $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatPiutangPenjualan $pencatatPiutang,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis FakturTanpaSuratJalan, SuratJalanTidakDitemukan, SuratJalanTidakAktif,
     *                                 SuratJalanSudahDifakturkan, SuratJalanBedaPelanggan, SuratJalanBedaOutlet,
     *                                 SuratJalanBedaBulan, TarifPpnBerbeda, TanggalFakturSebelumPenyerahan,
     *                                 TanggalMasaDepan
     */
    public function Jalankan(DataFakturPenjualan $data, int $idPengguna): FakturPenjualan
    {
        if ($data->uuidSuratJalan === []) {
            throw new PelanggaranAturanBisnis('FakturTanpaSuratJalan', 'Pilih minimal satu surat jalan untuk difakturkan.', 'SuratJalan');
        }

        return DB::transaction(fn (): FakturPenjualan => $this->Terbitkan($data, $idPengguna), max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    private function Terbitkan(DataFakturPenjualan $data, int $idPengguna): FakturPenjualan
    {
        $uuid = array_values(array_unique($data->uuidSuratJalan));
        $suratJalan = SuratJalan::query()->whereIn('Uuid', $uuid)->orderBy('Tanggal')->orderBy('Id')->lockForUpdate()->get();

        if ($suratJalan->count() !== count($uuid)) {
            throw new PelanggaranAturanBisnis('SuratJalanTidakDitemukan', 'Ada surat jalan yang tidak ditemukan.', 'SuratJalan');
        }

        $pertama = $suratJalan->first()
            ?? throw new PelanggaranAturanBisnis('SuratJalanTidakDitemukan', 'Ada surat jalan yang tidak ditemukan.', 'SuratJalan');
        $periode = $pertama->Tanggal->format('Y-m');

        foreach ($suratJalan as $sj) {
            $this->PastikanBisaDifakturkan($sj, $pertama, $periode);
        }

        $tanggal = CarbonImmutable::parse($data->tanggal->format('Y-m-d'));
        $this->pemeriksaLokasi->PastikanBukanMasaDepan($tanggal, $pertama->IdOutlet);
        $penyerahanTerakhir = $periode.'-01';

        foreach ($suratJalan as $sj) {
            $penyerahanTerakhir = max($penyerahanTerakhir, $sj->Tanggal->format('Y-m-d'));
        }

        if ($tanggal->format('Y-m-d') < $penyerahanTerakhir) {
            throw new PelanggaranAturanBisnis(
                'TanggalFakturSebelumPenyerahan',
                "Tanggal faktur tidak boleh sebelum penyerahan terakhir ({$penyerahanTerakhir}).",
                'Tanggal',
            );
        }

        $pelanggan = Pelanggan::query()->whereKey($pertama->IdPelanggan)->first()
            ?? throw new PelanggaranAturanBisnis('PelangganTidakDikenal', 'Pelanggan surat jalan ini tidak ditemukan.');
        $termin = (int) $pelanggan->TerminHari;
        $jumlah = $this->Jumlahkan(array_values($suratJalan->all()));

        $faktur = FakturPenjualan::query()->create([
            'Nomor' => $this->penomor->AmbilNomorOutlet(JenisDokumenBernomor::FakturPenjualan, $tanggal, $pertama->IdOutlet),
            'IdPelanggan' => $pertama->IdPelanggan,
            'IdOutlet' => $pertama->IdOutlet,
            'Tanggal' => $tanggal->toDateString(),
            'JatuhTempo' => $tanggal->addDays(max(0, $termin))->toDateString(),
            'TerminHari' => $termin,
            'PeriodePenyerahan' => $periode,
            'NomorFakturPajak' => $data->nomorFakturPajak,
            'TarifPpn' => $pertama->TarifPpn,
            'PengaliDppPembilang' => $pertama->PengaliDppPembilang,
            'PengaliDppPenyebut' => $pertama->PengaliDppPenyebut,
            'Subtotal' => $jumlah['Subtotal']->KeString(),
            'Diskon' => $jumlah['Diskon']->KeString(),
            'DasarPengenaanPajak' => $jumlah['DasarPengenaanPajak']->KeString(),
            'Pajak' => $jumlah['Pajak']->KeString(),
            'Total' => $jumlah['Total']->KeString(),
            'RincianPajak' => array_map(fn (Uang $n): string => $n->KeString(), $jumlah['RincianPajak']),
            'Catatan' => $data->catatan,
            'DibuatOleh' => $idPengguna,
            'DiubahOleh' => $idPengguna,
        ]);

        foreach ($suratJalan as $sj) {
            $sj->IdFakturPenjualan = $faktur->Id;
            $sj->DiubahOleh = $idPengguna;
            $sj->save();
        }

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::FakturPenjualan,
            idSumber: $faktur->Id,
            uuidSumber: $faktur->Uuid,
            nomorSumber: $faktur->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Faktur penjualan {$faktur->Nomor} kepada {$pelanggan->Nama}", 0, 255),
            baris: $this->penyusunJurnal->BarisFaktur($jumlah['Total'], $faktur->IdOutlet),
            idPengguna: $idPengguna,
        ));

        $faktur->fill(['IdJurnal' => $jurnal->idJurnal])->save();
        $piutang = $this->pencatatPiutang->CatatFaktur(
            $faktur->IdPelanggan,
            $faktur->Id,
            $faktur->IdOutlet,
            $faktur->Nomor,
            $tanggal,
            $jumlah['Total'],
            $termin,
        );

        $this->audit->Catat('grosir.faktur-buat', $faktur, nilaiBaru: [
            'Nomor' => $faktur->Nomor,
            'IdPelanggan' => $faktur->IdPelanggan,
            'PeriodePenyerahan' => $periode,
            'JumlahSuratJalan' => $suratJalan->count(),
            'NomorSuratJalan' => array_values($suratJalan->pluck('Nomor')->all()),
            'Total' => $faktur->Total,
            'JatuhTempo' => $faktur->JatuhTempo->toDateString(),
            'NomorJurnal' => $jurnal->nomor,
            'NomorPiutang' => $piutang->Nomor,
        ], idPengguna: $idPengguna);

        return $faktur->load('SuratJalan');
    }

    private function PastikanBisaDifakturkan(SuratJalan $sj, SuratJalan $pertama, string $periode): void
    {
        if ($sj->Status !== StatusDokumenTerposting::Diposting) {
            throw new PelanggaranAturanBisnis('SuratJalanTidakAktif', "Surat jalan {$sj->Nomor} sudah dibatalkan.", 'SuratJalan');
        }

        if ($sj->IdFakturPenjualan !== null) {
            throw new PelanggaranAturanBisnis('SuratJalanSudahDifakturkan', "Surat jalan {$sj->Nomor} sudah masuk faktur lain.", 'SuratJalan');
        }

        if ($sj->IdPelanggan !== $pertama->IdPelanggan) {
            throw new PelanggaranAturanBisnis('SuratJalanBedaPelanggan', 'Satu faktur hanya untuk satu pelanggan.', 'SuratJalan');
        }

        if ($sj->IdOutlet !== $pertama->IdOutlet) {
            throw new PelanggaranAturanBisnis('SuratJalanBedaOutlet', 'Satu faktur hanya untuk satu outlet penjual.', 'SuratJalan');
        }

        if ($sj->Tanggal->format('Y-m') !== $periode) {
            throw new PelanggaranAturanBisnis(
                'SuratJalanBedaBulan',
                "Surat jalan {$sj->Nomor} diserahkan di bulan lain. Faktur Pajak gabungan hanya boleh memuat penyerahan dalam satu bulan kalender (UU PPN Pasal 13 ayat 2a), jadi buat faktur terpisah per bulan.",
                'SuratJalan',
            );
        }

        if ((string) $sj->TarifPpn !== (string) $pertama->TarifPpn) {
            throw new PelanggaranAturanBisnis(
                'TarifPpnBerbeda',
                "Surat jalan {$sj->Nomor} memakai tarif PPN yang berbeda dari surat jalan lain di faktur ini. Buat faktur terpisah per tarif supaya Faktur Pajaknya benar.",
                'SuratJalan',
            );
        }
    }

    /**
     * @param  list<SuratJalan>  $suratJalan
     * @return array{Subtotal: Uang, Diskon: Uang, DasarPengenaanPajak: Uang, Pajak: Uang, Total: Uang, RincianPajak: array<string, Uang>}
     */
    private function Jumlahkan(array $suratJalan): array
    {
        $hasil = [
            'Subtotal' => Uang::Nol(),
            'Diskon' => Uang::Nol(),
            'DasarPengenaanPajak' => Uang::Nol(),
            'Pajak' => Uang::Nol(),
            'Total' => Uang::Nol(),
            'RincianPajak' => [],
        ];

        foreach ($suratJalan as $sj) {
            $hasil['Subtotal'] = $hasil['Subtotal']->Tambah(Uang::Dari($sj->Subtotal));
            $hasil['Diskon'] = $hasil['Diskon']->Tambah(Uang::Dari($sj->Diskon));
            $hasil['DasarPengenaanPajak'] = $hasil['DasarPengenaanPajak']->Tambah(Uang::Dari($sj->DasarPengenaanPajak));
            $hasil['Pajak'] = $hasil['Pajak']->Tambah(Uang::Dari($sj->Pajak));
            $hasil['Total'] = $hasil['Total']->Tambah($sj->AmbilTotal());

            foreach ($sj->AmbilRincianPajak() as $kode => $nilai) {
                $hasil['RincianPajak'][$kode] = ($hasil['RincianPajak'][$kode] ?? Uang::Nol())->Tambah($nilai);
            }
        }

        return $hasil;
    }
}
