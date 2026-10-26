<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Penjualan\Data\DataBarisSuratJalan;
use App\Domain\Penjualan\Data\DataSuratJalan;
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Layanan\PenghitungGrosir;
use App\Domain\Penjualan\Layanan\PenomorGrosir;
use App\Domain\Penjualan\Layanan\PenyusunJurnalGrosir;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Penjualan\Model\PesananGrosirDetail;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Penjualan\Model\SuratJalanDetail;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\HasilBarisMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\InfoBatchStok;
use App\Domain\Persediaan\Kueri\InfoNomorSeri;
use App\Domain\Persediaan\Layanan\AlokatorBatchFefo;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PetaAkunPersediaan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menyerahkan barang SO grosir lewat surat jalan (F-12, §9.7, **BR-12.2**, J-12.1). Ini titik pengakuan, dan seluruh
 * akibatnya terjadi **sinkron di satu transaksi DB** (CLAUDE.md #10):
 *
 * 1. stok keluar lewat `MutasiStok` (`JenisMutasi::Penjualan`, nilai HPP berjalan) — stok kurang ditolak BR-05.2;
 * 2. angka dokumen dihitung `PenghitungGrosir` dengan tarif pada **tanggal penyerahan**, bukan tanggal SO;
 * 3. jurnal J-12.1 dicatat: Dr HPP + Dr Piutang Belum Difakturkan / Cr persediaan + Cr Penjualan + Cr PPN Keluaran;
 * 4. `JumlahTerkirim` baris SO bertambah dan status SO menjadi SebagianDikirim atau Selesai.
 *
 * Harga & diskon **tidak pernah datang dari klien**: disalin dari snapshot baris SO yang sudah dikonfirmasi. Diskon
 * baris dialokasikan sebanding jumlah kirim, dan pengiriman yang menutup baris menerima sisa diskonnya, sehingga
 * Σ diskon surat jalan sama persis dengan diskon SO tanpa selisih pembulatan.
 *
 * Produk **ber-batch** dialokasikan FEFO oleh server (batch berkedaluwarsa terdekat keluar dulu; stok batch kurang =
 * `BatchTidakCukup`). Produk **bernomor seri** wajib menyebut nomor seri tiap unit (`NomorSeri` baris, satu per unit
 * dasar), semuanya harus `Tersedia` di lokasi pengiriman (`NomorSeriTidakTersedia`). Rincian yang diserahkan disimpan
 * di `SuratJalanDetail.RincianPelacakan` untuk retur dan cetak.
 */
final class KirimPesananGrosir
{
    public function __construct(
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly PenghitungGrosir $penghitung,
        private readonly PenomorGrosir $penomor,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly InfoProdukStok $infoProduk,
        private readonly PetaAkunPersediaan $petaAkunPersediaan,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly AlokatorBatchFefo $alokatorBatch,
        private readonly InfoNomorSeri $infoSeri,
        private readonly InfoBatchStok $infoBatch,
        private readonly PenyusunJurnalGrosir $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PesananTidakDitemukan, PesananBelumBisaDikirim, GudangBukanMilikOutlet,
     *                                 SuratJalanTanpaBaris, BarisTidakDikenal, JumlahKirimTidakValid,
     *                                 JumlahKirimMelebihiSisa, TanggalMasaDepan, NomorSeriTidakSesuai, NomorSeriGanda,
     *                                 NomorSeriTidakTersedia, BatchTidakCukup
     */
    public function Jalankan(DataSuratJalan $data, int $idPengguna): SuratJalan
    {
        if ($data->baris === []) {
            throw new PelanggaranAturanBisnis('SuratJalanTanpaBaris', 'Surat jalan harus punya minimal satu barang.', 'Baris');
        }

        return DB::transaction(fn (): SuratJalan => $this->Kirim($data, $idPengguna), max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    private function Kirim(DataSuratJalan $data, int $idPengguna): SuratJalan
    {
        $pesanan = PesananGrosir::query()->with('Outlet')->where('Uuid', $data->uuidPesanan)->lockForUpdate()->first()
            ?? throw new PelanggaranAturanBisnis('PesananTidakDitemukan', 'Pesanan grosir tidak ditemukan.');

        if (! $pesanan->Status->CekBolehDikirim()) {
            throw new PelanggaranAturanBisnis(
                'PesananBelumBisaDikirim',
                "Pesanan {$pesanan->Nomor} berstatus {$pesanan->Status->AmbilLabel()}; hanya pesanan yang sudah dikonfirmasi bisa dikirim.",
            );
        }

        $gudang = $this->pemeriksaLokasi->AmbilLokasi($data->idGudang, 'IdGudang');

        if ($gudang->idOutlet !== null && $gudang->idOutlet !== $pesanan->IdOutlet) {
            throw new PelanggaranAturanBisnis(
                'GudangBukanMilikOutlet',
                "Lokasi stok {$gudang->nama} bukan milik outlet pesanan ini.",
                'IdGudang',
            );
        }

        $tanggal = CarbonImmutable::parse($data->tanggal->format('Y-m-d'));
        $this->pemeriksaLokasi->PastikanBukanMasaDepan($tanggal, $pesanan->IdOutlet);

        $detailPesanan = PesananGrosirDetail::query()
            ->where('IdPesananGrosir', $pesanan->Id)
            ->orderBy('Urutan')
            ->lockForUpdate()
            ->get()
            ->keyBy('Urutan');
        $baris = $this->SusunBaris($data->baris, $detailPesanan->all());
        $hasil = $this->penghitung->Hitung($pesanan->IdOutlet, $pesanan->Outlet->KodeKota, array_map(
            fn (array $b): array => [
                'Jumlah' => $b['Jumlah'],
                'HargaSatuan' => $b['Harga'],
                'Diskon' => $b['Diskon'],
                'IdKelompokPajak' => $b['IdKelompokPajak'],
                'HargaTermasukPajak' => $b['HargaTermasukPajak'],
            ],
            $baris,
        ), $tanggal);

        $this->pengunciSaldo->Kunci($this->PasanganSaldo($baris, $gudang->id));

        $suratJalan = SuratJalan::query()->create([
            'Nomor' => $this->penomor->AmbilNomorOutlet(JenisDokumenBernomor::SuratJalan, $tanggal, $pesanan->IdOutlet),
            'IdPesananGrosir' => $pesanan->Id,
            'IdPelanggan' => $pesanan->IdPelanggan,
            'IdOutlet' => $pesanan->IdOutlet,
            'IdGudang' => $gudang->id,
            'Tanggal' => $tanggal->toDateString(),
            'TarifPpn' => $hasil->tarifPpn,
            'PengaliDppPembilang' => $hasil->pengaliDppPembilang,
            'PengaliDppPenyebut' => $hasil->pengaliDppPenyebut,
            'Subtotal' => $hasil->subtotal->KeString(),
            'Diskon' => $hasil->diskon->KeString(),
            'DasarPengenaanPajak' => $hasil->dasarPengenaanPajak->KeString(),
            'Pajak' => $hasil->pajak->KeString(),
            'Total' => $hasil->total->KeString(),
            'RincianPajak' => array_map(fn (Uang $jumlah): string => $jumlah->KeString(), $hasil->rincianPajak),
            'NamaPengirim' => $data->namaPengirim,
            'NomorKendaraan' => $data->nomorKendaraan,
            'NamaPenerima' => $data->namaPenerima,
            'Catatan' => $data->catatan,
            'DibuatOleh' => $idPengguna,
            'DiubahOleh' => $idPengguna,
        ]);
        $detailSurat = [];

        foreach ($baris as $urutan => $satuBaris) {
            $detailSurat[$satuBaris['Urutan']] = SuratJalanDetail::query()->create([
                'IdSuratJalan' => $suratJalan->Id,
                'Urutan' => $urutan + 1,
                'IdPesananGrosirDetail' => $satuBaris['IdPesananGrosirDetail'],
                'IdProduk' => $satuBaris['IdProduk'],
                'NamaProduk' => $satuBaris['NamaProduk'],
                'Sku' => $satuBaris['Sku'],
                'IdProdukSatuan' => $satuBaris['IdProdukSatuan'],
                'SimbolSatuan' => $satuBaris['SimbolSatuan'],
                'Konversi' => $satuBaris['Konversi'],
                'Jumlah' => $satuBaris['Jumlah']->KeString(),
                'JumlahDasar' => $satuBaris['JumlahDasar']->KeString(),
                'Harga' => $satuBaris['Harga']->KeString(),
                'Diskon' => $satuBaris['Diskon']->KeString(),
                'Subtotal' => $satuBaris['Harga']->Kali($satuBaris['Jumlah']->KeString())->KeString(),
                'HargaTermasukPajak' => $satuBaris['HargaTermasukPajak'],
                'IdKelompokPajak' => $satuBaris['IdKelompokPajak'],
            ]);
        }

        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_column($baris, 'IdProduk'))), true);
        [$barisMutasi, $kunciPerBaris] = $this->SusunMutasi($baris, $produk, $gudang->id);
        $hasilMutasi = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            jenisReferensi: JenisReferensiMutasi::SuratJalan,
            idReferensi: $suratJalan->Id,
            uuidReferensi: $suratJalan->Uuid,
            nomorReferensi: $suratJalan->Nomor,
            tanggalBisnis: $tanggal,
            idPengguna: $idPengguna,
            idPerangkat: null,
            baris: array_map(fn (DataBarisMutasi $b): DataBarisMutasi => new DataBarisMutasi(
                kunciBaris: $b->kunciBaris,
                idProduk: $b->idProduk,
                idGudang: $b->idGudang,
                jenisMutasi: $b->jenisMutasi,
                jumlah: $b->jumlah,
                modeNilai: $b->modeNilai,
                idReferensiDetail: $detailSurat[$kunciPerBaris[$b->kunciBaris]]->Id,
                idBatchStok: $b->idBatchStok,
                idNomorSeri: $b->idNomorSeri,
            ), $barisMutasi),
        ));

        $perubahanPersediaan = [];
        $totalHpp = Uang::Nol();

        foreach ($baris as $satuBaris) {
            $rows = array_values(array_filter($hasilMutasi->baris, fn (HasilBarisMutasi $m, string $kunci): bool => $kunciPerBaris[$kunci] === $satuBaris['Urutan'], ARRAY_FILTER_USE_BOTH));
            $detail = $detailSurat[$satuBaris['Urutan']];
            $hppBaris = Uang::Nol();

            foreach ($rows as $m) {
                $hppBaris = $hppBaris->Kurangi($m->totalHpp);
            }

            $info = $produk[$satuBaris['IdProduk']];
            $detail->HppSatuan = $info->pelacakan === PelacakanProduk::Tidak
                ? (string) $rows[0]->hppSatuan
                : (string) BigDecimal::of($hppBaris->KeString())->dividedBy($satuBaris['JumlahDasar']->KeDesimal(), 6, RoundingMode::HalfUp);
            $detail->TotalHpp = $hppBaris->KeString();
            $detail->RincianPelacakan = $this->RincianPelacakan($info->pelacakan, $rows);
            $detail->save();
            $totalHpp = $totalHpp->Tambah($hppBaris);
            $peran = $this->petaAkunPersediaan->UntukJenis($info->jenis)->value;

            foreach ($rows as $m) {
                $perubahanPersediaan[$peran] = ($perubahanPersediaan[$peran] ?? Uang::Nol())->Tambah($m->totalHpp);
            }
        }

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::SuratJalan,
            idSumber: $suratJalan->Id,
            uuidSumber: $suratJalan->Uuid,
            nomorSumber: $suratJalan->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Surat jalan {$suratJalan->Nomor} atas pesanan {$pesanan->Nomor}", 0, 255),
            baris: $this->penyusunJurnal->BarisSuratJalan(
                $hasil->total,
                $hasil->diskon,
                $hasil->dasarPengenaanPajak->Tambah($hasil->diskon),
                $hasil->rincianPajak,
                $perubahanPersediaan,
                $pesanan->IdOutlet,
            ),
            idPengguna: $idPengguna,
        ));

        $suratJalan->fill(['TotalHpp' => $totalHpp->KeString(), 'IdJurnal' => $jurnal->idJurnal])->save();
        $this->MajukanPesanan($pesanan, $baris, $detailPesanan->all(), $idPengguna);

        $this->audit->Catat('grosir.surat-jalan-kirim', $suratJalan, nilaiBaru: [
            'Nomor' => $suratJalan->Nomor,
            'NomorPesanan' => $pesanan->Nomor,
            'IdGudang' => $gudang->id,
            'JumlahBaris' => count($baris),
            'Total' => $suratJalan->Total,
            'TotalHpp' => $suratJalan->TotalHpp,
            'NomorJurnal' => $jurnal->nomor,
            'StatusPesanan' => $pesanan->Status->value,
        ], idPengguna: $idPengguna);

        return $suratJalan->load('Detail');
    }

    /**
     * Menyalin snapshot baris SO ke baris surat jalan dan mengalokasikan diskonnya.
     *
     * @param  list<DataBarisSuratJalan>  $diminta
     * @param  array<int, PesananGrosirDetail>  $detailPesanan  berkunci Urutan
     * @return list<array{Urutan: int, IdPesananGrosirDetail: int, IdProduk: int, NamaProduk: string, Sku: string|null, IdProdukSatuan: int|null, SimbolSatuan: string, Konversi: string, Jumlah: Kuantitas, JumlahDasar: Kuantitas, Harga: Uang, Diskon: Uang, HargaTermasukPajak: bool|null, IdKelompokPajak: int|null, NomorSeri: list<string>}>
     */
    private function SusunBaris(array $diminta, array $detailPesanan): array
    {
        $hasil = [];
        $sudah = [];

        foreach ($diminta as $baris) {
            $detail = $detailPesanan[$baris->urutanPesanan] ?? null;

            if ($detail === null) {
                throw new PelanggaranAturanBisnis('BarisTidakDikenal', "Baris nomor {$baris->urutanPesanan} tidak ada di pesanan ini.", 'Baris');
            }

            if (isset($sudah[$baris->urutanPesanan])) {
                throw new PelanggaranAturanBisnis('BarisGanda', "Baris nomor {$baris->urutanPesanan} dikirim dua kali dalam satu surat jalan.", 'Baris');
            }

            $sudah[$baris->urutanPesanan] = true;

            if ($baris->jumlah->Bandingkan(Kuantitas::Nol()) <= 0) {
                throw new PelanggaranAturanBisnis('JumlahKirimTidakValid', "Jumlah kirim baris {$detail->NamaProduk} harus lebih dari nol.", 'Baris');
            }

            $sisa = $detail->AmbilSisaKirim();

            if ($baris->jumlah->Bandingkan($sisa) > 0) {
                throw new PelanggaranAturanBisnis(
                    'JumlahKirimMelebihiSisa',
                    "Jumlah kirim {$detail->NamaProduk} melebihi sisa pesanan (sisa {$sisa->KeString()} {$detail->SimbolSatuan}).",
                    'Baris',
                    detail: ['Urutan' => $detail->Urutan, 'Sisa' => $sisa->KeString()],
                );
            }

            $hasil[] = [
                'Urutan' => $detail->Urutan,
                'IdPesananGrosirDetail' => $detail->Id,
                'IdProduk' => $detail->IdProduk,
                'NamaProduk' => $detail->NamaProduk,
                'Sku' => $detail->Sku,
                'IdProdukSatuan' => $detail->IdProdukSatuan,
                'SimbolSatuan' => $detail->SimbolSatuan,
                'Konversi' => $detail->Konversi,
                'Jumlah' => $baris->jumlah,
                'JumlahDasar' => $baris->jumlah->Kali($detail->Konversi),
                'Harga' => $detail->AmbilHarga(),
                'Diskon' => $this->AlokasikanDiskon($detail, $baris->jumlah, $sisa),
                'HargaTermasukPajak' => $detail->HargaTermasukPajak,
                'IdKelompokPajak' => $detail->IdKelompokPajak,
                'NomorSeri' => $baris->nomorSeri,
            ];
        }

        return $hasil;
    }

    /**
     * Baris mutasi keluar per baris surat jalan. Produk biasa = satu baris `P/{urutan}`; ber-batch = satu baris per batch
     * hasil alokasi FEFO (`P/{urutan}#n`); bernomor seri = satu baris per unit. `$kunciPerBaris` memetakan kunci mutasi
     * ke Urutan baris surat jalan.
     *
     * @param  list<array{Urutan: int, IdProduk: int, JumlahDasar: Kuantitas, NomorSeri: list<string>, NamaProduk: string}>  $baris
     * @param  array<int, DataInfoProdukStok>  $produk
     * @return array{0: list<DataBarisMutasi>, 1: array<string, int>}
     */
    private function SusunMutasi(array $baris, array $produk, int $idGudang): array
    {
        $hasil = [];
        $kunciPerBaris = [];

        foreach ($baris as $b) {
            $info = $produk[$b['IdProduk']];
            $dasar = 'P/'.$b['Urutan'];

            if ($info->pelacakan === PelacakanProduk::Batch) {
                $rencana = $this->alokatorBatch->Susun([['Kunci' => $dasar, 'IdProduk' => $b['IdProduk'], 'IdGudang' => $idGudang, 'Jumlah' => $b['JumlahDasar']]], true);

                if (isset($rencana['Sisa'][$dasar])) {
                    throw new PelanggaranAturanBisnis(
                        'BatchTidakCukup',
                        "Stok batch {$b['NamaProduk']} di lokasi ini kurang {$rencana['Sisa'][$dasar]->KeString()} dari yang akan dikirim.",
                        'Baris',
                    );
                }

                foreach (array_values($rencana['Alokasi'][$dasar] ?? []) as $n => $a) {
                    $kunci = $dasar.'#'.($n + 1);
                    $kunciPerBaris[$kunci] = $b['Urutan'];
                    $hasil[] = new DataBarisMutasi($kunci, $b['IdProduk'], $idGudang, JenisMutasi::Penjualan, $a['Jumlah']->Negasi(), ModeNilaiMutasi::Berjalan, idBatchStok: $a['IdBatchStok']);
                }

                continue;
            }

            if ($info->pelacakan === PelacakanProduk::Seri) {
                $nomor = array_values(array_map(fn (string $n): string => trim($n), $b['NomorSeri']));
                $bulat = $b['JumlahDasar']->KeDesimal()->toScale(0, RoundingMode::Down);

                if (! $b['JumlahDasar']->KeDesimal()->isEqualTo($bulat) || count($nomor) !== $bulat->toInt()) {
                    throw new PelanggaranAturanBisnis(
                        'NomorSeriTidakSesuai',
                        "{$b['NamaProduk']} memakai nomor seri: isi {$bulat} nomor seri (satu per unit), bukan ".count($nomor).'.',
                        'Baris',
                    );
                }

                if (count(array_unique(array_map('mb_strtoupper', $nomor))) !== count($nomor)) {
                    throw new PelanggaranAturanBisnis('NomorSeriGanda', "Ada nomor seri {$b['NamaProduk']} yang diisi dua kali.", 'Baris');
                }

                $tersedia = $this->infoSeri->CariTersedia($b['IdProduk'], $idGudang, $nomor, true);
                $hilang = array_values(array_filter($nomor, fn (string $n): bool => ! isset($tersedia[mb_strtoupper($n)])));

                if ($hilang !== []) {
                    throw new PelanggaranAturanBisnis(
                        'NomorSeriTidakTersedia',
                        'Nomor seri '.implode(', ', $hilang)." tidak tersedia di lokasi stok pengiriman ({$b['NamaProduk']}).",
                        'Baris',
                    );
                }

                foreach ($nomor as $n => $no) {
                    $kunci = $dasar.'#'.($n + 1);
                    $kunciPerBaris[$kunci] = $b['Urutan'];
                    $hasil[] = new DataBarisMutasi($kunci, $b['IdProduk'], $idGudang, JenisMutasi::Penjualan, Kuantitas::Dari('-1'), ModeNilaiMutasi::Berjalan, idNomorSeri: $tersedia[mb_strtoupper($no)]);
                }

                continue;
            }

            $kunciPerBaris[$dasar] = $b['Urutan'];
            $hasil[] = new DataBarisMutasi($dasar, $b['IdProduk'], $idGudang, JenisMutasi::Penjualan, $b['JumlahDasar']->Negasi(), ModeNilaiMutasi::Berjalan);
        }

        return [$hasil, $kunciPerBaris];
    }

    /**
     * Snapshot batch/nomor seri yang keluar beserta HPP-nya, disimpan di baris surat jalan untuk retur & cetak.
     *
     * @param  list<HasilBarisMutasi>  $rows
     * @return list<array<string, string|null>>|null
     */
    private function RincianPelacakan(PelacakanProduk $pelacakan, array $rows): ?array
    {
        if ($pelacakan === PelacakanProduk::Tidak) {
            return null;
        }

        $batch = $this->infoBatch->AmbilBanyak(array_values(array_filter(array_map(fn (HasilBarisMutasi $m): ?int => $m->idBatchStok, $rows))));
        $seri = $this->infoSeri->AmbilNomor(array_values(array_filter(array_map(fn (HasilBarisMutasi $m): ?int => $m->idNomorSeri, $rows))));
        $rincian = [];

        foreach ($rows as $m) {
            if ($pelacakan === PelacakanProduk::Batch && $m->idBatchStok !== null) {
                $b = $batch[$m->idBatchStok];
                $rincian[] = [
                    'NomorBatch' => $b->nomorBatch,
                    'TanggalKedaluwarsa' => $b->tanggalKedaluwarsa?->toDateString(),
                    'JumlahDasar' => $m->jumlah->Negasi()->KeString(),
                    'HppSatuan' => (string) $m->hppSatuan,
                ];
            } elseif ($pelacakan === PelacakanProduk::Seri && $m->idNomorSeri !== null) {
                $rincian[] = ['Nomor' => $seri[$m->idNomorSeri], 'HppSatuan' => (string) $m->hppSatuan];
            }
        }

        return $rincian === [] ? null : $rincian;
    }

    /**
     * Diskon baris SO dibagi sebanding jumlah kirim. Pengiriman yang **menutup** baris menerima seluruh sisa diskon,
     * supaya Σ diskon surat jalan tepat sama dengan diskon baris SO walau pembagiannya tidak bulat.
     */
    private function AlokasikanDiskon(PesananGrosirDetail $detail, Kuantitas $jumlahKirim, Kuantitas $sisa): Uang
    {
        $diskon = Uang::Dari($detail->Diskon);

        if ($diskon->BernilaiNol()) {
            return Uang::Nol();
        }

        $terpakai = Uang::Nol();

        foreach (SuratJalanDetail::query()
            ->where('IdPesananGrosirDetail', $detail->Id)
            ->whereIn('IdSuratJalan', SuratJalan::query()->where('Status', StatusDokumenTerposting::Diposting->value)->select('Id'))
            ->get() as $sudah) {
            $terpakai = $terpakai->Tambah($sudah->AmbilDiskon());
        }

        if ($jumlahKirim->SamaDengan($sisa)) {
            return $diskon->Kurangi($terpakai);
        }

        return $diskon->Kali($jumlahKirim->KeDesimal()->dividedBy($detail->AmbilJumlah()->KeDesimal(), 10, RoundingMode::HalfUp), RoundingMode::HalfUp);
    }

    /**
     * Pasangan produk-gudang yang dikunci, tanpa duplikat dan **urut IdProduk** supaya tidak deadlock dengan dokumen
     * lain yang mengunci pasangan yang sama (aturan akuntansi & stok).
     *
     * @param  list<array{IdProduk: int}>  $baris
     * @return list<array{0: int, 1: int}>
     */
    private function PasanganSaldo(array $baris, int $idGudang): array
    {
        $pasangan = [];

        foreach ($baris as $satuBaris) {
            $pasangan[$satuBaris['IdProduk']] = [$satuBaris['IdProduk'], $idGudang];
        }

        ksort($pasangan);

        return array_values($pasangan);
    }

    /**
     * `JumlahTerkirim` baris SO bertambah, lalu status SO menyusul: Selesai bila semua baris terkirim penuh,
     * SebagianDikirim bila masih ada sisa. Status yang sudah tepat tidak diubah lagi.
     *
     * @param  list<array{Urutan: int, Jumlah: Kuantitas}>  $baris
     * @param  array<int, PesananGrosirDetail>  $detailPesanan
     */
    private function MajukanPesanan(PesananGrosir $pesanan, array $baris, array $detailPesanan, int $idPengguna): void
    {
        foreach ($baris as $satuBaris) {
            $detail = $detailPesanan[$satuBaris['Urutan']];
            $detail->JumlahTerkirim = $detail->AmbilJumlahTerkirim()->Tambah($satuBaris['Jumlah'])->KeString();
            $detail->save();
        }

        $selesai = true;

        foreach ($detailPesanan as $detail) {
            if ($detail->AmbilSisaKirim()->Bandingkan(Kuantitas::Nol()) > 0) {
                $selesai = false;

                break;
            }
        }

        $tujuan = $selesai ? StatusPesananGrosir::Selesai : StatusPesananGrosir::SebagianDikirim;

        if ($pesanan->Status === $tujuan) {
            return;
        }

        $asal = $pesanan->Status->value;
        $pesanan->UbahStatus($tujuan);
        $pesanan->fill([
            'SelesaiPada' => $selesai ? CarbonImmutable::now() : null,
            'DiubahOleh' => $idPengguna,
        ])->save();
        $this->riwayat->Catat(PesananGrosir::JENIS_DOKUMEN, $pesanan->Id, $asal, $tujuan->value, $idPengguna);
    }
}
