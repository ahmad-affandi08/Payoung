<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Pembelian\Data\DataBarisFakturPembelian;
use App\Domain\Pembelian\Data\DataFakturPembelian;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PengalokasiNilai;
use App\Domain\Pembelian\Layanan\PenghitungPajakPembelian;
use App\Domain\Pembelian\Layanan\PenomorPembelian;
use App\Domain\Pembelian\Layanan\PenyimpanLampiranPembelian;
use App\Domain\Pembelian\Layanan\PenyusunJurnalPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Faktur pembelian dengan 3-way matching PO–GRN–faktur (F-04 fase 1, izin `pembelian.kelola`): GRN Diposting satu
 * pemasok & satu outlet yang belum difakturkan; satu baris per baris GRN untuk jumlah yang belum diretur (jumlah tidak
 * bisa diubah; harga & diskon mengikuti faktur pemasok). PPN masukan dari `TarifPajak` pada tanggal faktur (pemasok
 * PKP), jatuh tempo = tanggal + termin (atau isian). Jurnal J-04.2: Dr `HutangBelumDifakturkan` (nilai GRN tersisa) +
 * Dr `PpnMasukan` (dapat dikreditkan), Cr `HutangUsaha` (Total); selisih harga BR-04.4 fase 1 seluruhnya ke
 * `SelisihHpp`. Nomor faktur pemasok unik per pemasok. Audit `faktur-pembelian.posting`.
 *
 * Urutan kunci: GRN (urut Id) → faktur baru → penghitung FB & JU (L7).
 */
final class SimpanFakturPembelian
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemrosesPenerimaanBarang $pemroses,
        private readonly PenghitungPajakPembelian $pajak,
        private readonly PengalokasiNilai $alokasi,
        private readonly PenomorPembelian $penomor,
        private readonly PostingJurnal $postingJurnal,
        private readonly PenyimpanLampiranPembelian $lampiran,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PemasokTidakDikenal, NomorFakturGanda, PenerimaanTidakValid, OutletBerbeda, …
     */
    public function Jalankan(DataFakturPembelian $data): FakturPembelian
    {
        $berkas = $data->lampiran === null ? null : $this->lampiran->Simpan($this->konteks->Wajib(), 'faktur', $data->lampiran);

        try {
            return DB::transaction(fn (): FakturPembelian => $this->Proses($data, $berkas), 3);
        } catch (Throwable $galat) {
            if ($berkas !== null) {
                $this->lampiran->Hapus($berkas['PathLampiran']);
            }

            throw $galat;
        }
    }

    /**
     * @param  array{PathLampiran: string, NamaLampiran: string, MimeLampiran: string, UkuranLampiran: int}|null  $berkas
     */
    private function Proses(DataFakturPembelian $data, ?array $berkas): FakturPembelian
    {
        $pemasok = Pemasok::query()->withTrashed()->where('Uuid', $data->uuidPemasok)->first();

        if ($pemasok === null) {
            throw new PelanggaranAturanBisnis('PemasokTidakDikenal', 'Pemasok tidak ditemukan.', 'UuidPemasok');
        }

        $nomorPemasok = trim($data->nomorFakturPemasok);

        if ($nomorPemasok === '' || mb_strlen($nomorPemasok) > 60) {
            throw new PelanggaranAturanBisnis('NomorFakturTidakValid', 'Nomor faktur pemasok wajib diisi, maksimal 60 karakter.', 'NomorFakturPemasok');
        }

        $grn = $this->KunciPenerimaan($data, $pemasok);
        $idOutlet = $grn[0]->IdOutlet;
        $this->pemroses->PastikanTanggal($data->tanggal, $idOutlet);

        $ganda = FakturPembelian::query()->where('IdPemasok', $pemasok->Id)->where('NomorFakturPemasok', $nomorPemasok)->where('Status', '!=', StatusFakturPembelian::Dibatalkan->value)->exists();

        if ($ganda) {
            throw new PelanggaranAturanBisnis('NomorFakturGanda', "Faktur {$nomorPemasok} dari {$pemasok->Nama} sudah dicatat.", 'NomorFakturPemasok');
        }

        $baris = $this->SusunBaris($grn, $data->baris);
        $subtotal = array_reduce($baris, fn (Uang $t, array $b): Uang => $t->Tambah($b['Subtotal']), Uang::Nol());
        $nilaiPenerimaan = array_reduce($baris, fn (Uang $t, array $b): Uang => $t->Tambah($b['NilaiPenerimaan']), Uang::Nol());
        $ongkir = $data->ongkir ?? array_reduce($grn, fn (Uang $t, PenerimaanBarang $g): Uang => $t->Tambah(Uang::Dari($g->Ongkir)), Uang::Nol());

        if ($ongkir->BernilaiNegatif()) {
            throw new PelanggaranAturanBisnis('HargaTidakValid', 'Ongkir tidak boleh negatif.', 'Ongkir');
        }

        $pajak = $this->pajak->Hitung($grn[0]->Pkp, $idOutlet, $data->tanggal, $subtotal);
        $total = $subtotal->Tambah($ongkir)->Tambah($pajak->pajak);
        $ppnKredit = $pajak->dikreditkan ? $pajak->pajak : Uang::Nol();
        $terminHari = $grn[0]->TerminHari;
        $jatuhTempo = $data->jatuhTempo ?? $data->tanggal->addDays($terminHari);

        if ($jatuhTempo->toDateString() < $data->tanggal->toDateString()) {
            throw new PelanggaranAturanBisnis('JatuhTempoTidakValid', 'Jatuh tempo tidak boleh sebelum tanggal faktur.', 'JatuhTempo');
        }

        $faktur = FakturPembelian::query()->create([
            'Nomor' => $this->penomor->AmbilNomorTenant(JenisDokumenBernomor::FakturPembelian, $data->tanggal),
            'NomorFakturPemasok' => $nomorPemasok,
            'IdPemasok' => $pemasok->Id,
            'IdOutlet' => $idOutlet,
            'Tanggal' => $data->tanggal->toDateString(),
            'JatuhTempo' => $jatuhTempo->toDateString(),
            'TerminHari' => $terminHari,
            'Status' => StatusFakturPembelian::BelumDibayar,
            'TarifPpn' => $pajak->tarif,
            'PengaliDppPembilang' => $pajak->pengaliDppPembilang,
            'PengaliDppPenyebut' => $pajak->pengaliDppPenyebut,
            'PpnDikreditkan' => $pajak->dikreditkan,
            'NilaiPenerimaan' => $nilaiPenerimaan->KeString(),
            'Subtotal' => $subtotal->KeString(),
            'Ongkir' => $ongkir->KeString(),
            'Pajak' => $pajak->pajak->KeString(),
            'SelisihHarga' => $total->Kurangi($nilaiPenerimaan)->Kurangi($ppnKredit)->KeString(),
            'Total' => $total->KeString(),
            'Catatan' => $data->catatan === null || trim($data->catatan) === '' ? null : mb_substr(trim($data->catatan), 0, 500),
            'DibuatOleh' => $data->idPengguna,
            ...($berkas ?? []),
        ]);

        $bobot = array_map(fn (array $b): Uang => $b['Subtotal'], $baris);
        $alokasiOngkir = $this->alokasi->Alokasikan($ongkir, $bobot);
        $alokasiPajak = $this->alokasi->Alokasikan($pajak->pajak, $bobot);

        foreach ($baris as $i => $b) {
            /** @var PenerimaanBarangDetail $d */
            $d = $b['Detail'];
            FakturPembelianDetail::query()->create([
                'IdFakturPembelian' => $faktur->Id,
                'Urutan' => $i + 1,
                'IdPenerimaanBarangDetail' => $d->Id,
                'IdProduk' => $d->IdProduk,
                'NamaProduk' => $d->NamaProduk,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $b['Jumlah']->KeString(),
                'JumlahDasar' => $b['JumlahDasar']->KeString(),
                'HargaPenerimaan' => $d->Harga,
                'SubtotalPenerimaan' => $b['SubtotalPenerimaan']->KeString(),
                'Harga' => $b['Harga']->KeString(),
                'Diskon' => $b['Diskon']->KeString(),
                'Subtotal' => $b['Subtotal']->KeString(),
                'NilaiPenerimaan' => $b['NilaiPenerimaan']->KeString(),
                'AlokasiOngkir' => $alokasiOngkir[$i]->KeString(),
                'Pajak' => $alokasiPajak[$i]->KeString(),
            ]);
        }

        foreach ($grn as $g) {
            $g->IdFakturPembelian = $faktur->Id;
            $g->save();
        }

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::FakturPembelian,
            idSumber: $faktur->Id,
            uuidSumber: $faktur->Uuid,
            nomorSumber: $faktur->Nomor,
            tanggal: $data->tanggal,
            keterangan: mb_substr("Faktur pembelian {$faktur->Nomor} ({$nomorPemasok}) dari {$pemasok->Nama}", 0, 255),
            baris: PenyusunJurnalPembelian::Seimbangkan([
                DataBarisJurnal::DariSelisih(PeranAkun::HutangBelumDifakturkan, $nilaiPenerimaan, $idOutlet),
                DataBarisJurnal::DariSelisih(PeranAkun::PpnMasukan, $ppnKredit, $idOutlet),
                DataBarisJurnal::DariSelisih(PeranAkun::HutangUsaha, Uang::Nol()->Kurangi($total), $idOutlet),
            ], $idOutlet),
            idPengguna: $data->idPengguna,
        ));

        $faktur->IdJurnal = $jurnal->idJurnal;
        $faktur->save();

        $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $faktur->Id, null, StatusFakturPembelian::BelumDibayar->value, $data->idPengguna);
        $this->audit->Catat('faktur-pembelian.posting', $faktur, nilaiBaru: [
            'Nomor' => $faktur->Nomor,
            'NomorFakturPemasok' => $nomorPemasok,
            'Pemasok' => $pemasok->Nama,
            'Penerimaan' => array_map(fn (PenerimaanBarang $g): string => $g->Nomor, $grn),
            'Total' => $faktur->Total,
            'SelisihHarga' => $faktur->SelisihHarga,
            'NomorJurnal' => $jurnal->nomor,
        ], idPengguna: $data->idPengguna);

        return $faktur;
    }

    /**
     * @return non-empty-list<PenerimaanBarang>
     */
    private function KunciPenerimaan(DataFakturPembelian $data, Pemasok $pemasok): array
    {
        $uuid = array_values(array_unique($data->uuidPenerimaan));

        if ($uuid === []) {
            throw new PelanggaranAturanBisnis('PenerimaanTidakValid', 'Pilih minimal satu penerimaan barang yang akan difakturkan.', 'UuidPenerimaan');
        }

        $grn = array_values(PenerimaanBarang::query()->whereIn('Uuid', $uuid)->orderBy('Id')->lockForUpdate()->get()->all());

        if (count($grn) !== count($uuid)) {
            throw new PelanggaranAturanBisnis('PenerimaanTidakValid', 'Penerimaan barang tidak ditemukan.', 'UuidPenerimaan');
        }

        foreach ($grn as $g) {
            if ($g->Status !== StatusDokumenTerposting::Diposting || $g->BelanjaStok || $g->IdFakturPembelian !== null || $g->IdPemasok !== $pemasok->Id) {
                throw new PelanggaranAturanBisnis('PenerimaanTidakValid', "Penerimaan {$g->Nomor} tidak bisa difakturkan: harus Tercatat, dari {$pemasok->Nama}, dan belum difakturkan.", 'UuidPenerimaan');
            }

            if ($g->IdOutlet !== $grn[0]->IdOutlet) {
                throw new PelanggaranAturanBisnis('OutletBerbeda', 'Satu faktur hanya untuk penerimaan di outlet yang sama.', 'UuidPenerimaan');
            }
        }

        return $grn;
    }

    /**
     * Baris faktur per baris GRN yang masih punya jumlah (diterima − diretur). Harga & diskon = isian faktur, bawaan
     * harga GRN dan diskon GRN sebanding jumlah.
     *
     * @param  list<PenerimaanBarang>  $grn
     * @param  list<DataBarisFakturPembelian>  $isian
     * @return list<array{Detail: PenerimaanBarangDetail, Jumlah: Kuantitas, JumlahDasar: Kuantitas, SubtotalPenerimaan: Uang, Harga: Uang, Diskon: Uang, Subtotal: Uang, NilaiPenerimaan: Uang}>
     */
    private function SusunBaris(array $grn, array $isian): array
    {
        $perDetail = [];

        foreach ($isian as $b) {
            $perDetail[$b->idPenerimaanBarangDetail] = $b;
        }

        $detail = PenerimaanBarangDetail::query()->whereIn('IdPenerimaanBarang', array_map(fn (PenerimaanBarang $g): int => $g->Id, $grn))->orderBy('IdPenerimaanBarang')->orderBy('Urutan')->get();
        $hasil = [];

        foreach ($detail as $i => $d) {
            $dasar = Kuantitas::Dari($d->JumlahDasar);
            $sisaDasar = $dasar->Kurangi(Kuantitas::Dari($d->JumlahDiretur));

            if (! $sisaDasar->KeDesimal()->isPositive()) {
                continue;
            }

            $utuh = $sisaDasar->SamaDengan($dasar);
            $rasio = $sisaDasar->KeDesimal()->dividedBy($dasar->KeDesimal(), 12, RoundingMode::HalfUp);
            $jumlah = $utuh ? Kuantitas::Dari($d->Jumlah) : Kuantitas::Dari(BigDecimal::of($d->Jumlah)->multipliedBy($rasio)->toScale(Kuantitas::SKALA, RoundingMode::HalfUp));
            $masukan = $perDetail[$d->Id] ?? null;
            $harga = $masukan->harga ?? Uang::Dari($d->Harga);
            $diskon = $masukan->diskon ?? ($utuh ? Uang::Dari($d->Diskon) : Uang::Dari(BigDecimal::of($d->Diskon)->multipliedBy($rasio)->toScale(Uang::SKALA, RoundingMode::HalfUp)));
            $bruto = Uang::Dari(BigDecimal::of($harga->KeString())->multipliedBy($jumlah->KeDesimal())->toScale(Uang::SKALA, RoundingMode::HalfUp));

            if ($harga->BernilaiNegatif() || $diskon->BernilaiNegatif() || $diskon->Bandingkan($bruto) > 0) {
                throw new PelanggaranAturanBisnis('HargaTidakValid', "Harga atau diskon {$d->NamaProduk} tidak valid (diskon maksimal {$bruto->FormatRupiah()}).", "Baris.{$i}.Harga");
            }

            $hasil[] = [
                'Detail' => $d,
                'Jumlah' => $jumlah,
                'JumlahDasar' => $sisaDasar,
                'SubtotalPenerimaan' => $utuh ? Uang::Dari($d->Subtotal) : Uang::Dari(BigDecimal::of($d->Subtotal)->multipliedBy($rasio)->toScale(Uang::SKALA, RoundingMode::HalfUp)),
                'Harga' => $harga,
                'Diskon' => $diskon,
                'Subtotal' => $bruto->Kurangi($diskon),
                'NilaiPenerimaan' => Uang::Dari($d->Nilai)->Kurangi(Uang::Dari($d->NilaiDiretur)),
            ];
        }

        if ($hasil === []) {
            throw new PelanggaranAturanBisnis('PenerimaanTidakValid', 'Semua barang di penerimaan ini sudah diretur; tidak ada yang bisa difakturkan.', 'UuidPenerimaan');
        }

        return $hasil;
    }
}
