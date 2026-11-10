<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Kueri\SaldoUangLaci;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Pembelian\Data\DataBelanjaStok;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PengalokasiNilai;
use App\Domain\Pembelian\Layanan\PenomorPembelian;
use App\Domain\Pembelian\Layanan\PenyimpanLampiranPembelian;
use App\Domain\Pembelian\Layanan\PenyusunJurnalPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PembayaranHutang;
use App\Domain\Pembelian\Model\PembayaranHutangAlokasi;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Belanja stok satu langkah (mode UMKM, F-04 fase 1): pemasok opsional, lokasi, baris, ongkir, dan akun kas/bank
 * pembayar → GRN + faktur lunas + pembayaran dalam satu transaksi bersama stok & jurnal. Satu jurnal J-04.3 (sumber
 * GRN): Dr persediaan (harga landed) + Dr PPN masukan (bila dapat dikreditkan), Cr kas/bank sebesar total dibayar.
 * Bila dibayar dari uang laci kasir (D-256) kreditnya ke akun
 * penampung `KliringBelanjaLaci` (yang sebelumnya didebit kas keluar kasir), jadi kas tidak berkurang dua kali.
 * Faktur & pembayaran ditandai `BelanjaStok` (tanpa jurnal sendiri); ketiganya dibatalkan bersama lewat pembatalan
 * GRN. Audit `belanja-stok.simpan`.
 */
final class SimpanBelanjaStok
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PengaturanPersediaanTenant $pengaturanPersediaan,
        private readonly InfoGudang $infoGudang,
        private readonly DaftarAkunPilihan $akun,
        private readonly PenentuAkun $penentuAkun,
        private readonly SaldoUangLaci $saldoLaci,
        private readonly PemrosesPenerimaanBarang $pemroses,
        private readonly PengalokasiNilai $alokasi,
        private readonly PenomorPembelian $penomor,
        private readonly PenyusunJurnalPembelian $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PenyimpanLampiranPembelian $lampiran,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PemasokTidakDikenal, GudangTidakDikenal, AkunKasBankWajib, JumlahTidakValid, …
     */
    public function Jalankan(DataBelanjaStok $data): PenerimaanBarang
    {
        $idAkun = null;

        if (! $data->dariUangLaci) {
            $akun = $this->akun->CariKasBankDariUuid($data->uuidAkun);

            if ($akun === null) {
                throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas/bank aktif untuk membayar belanja.', 'UuidAkun');
            }

            $idAkun = $akun['Id'];
        }

        $berkas = $data->lampiran === null ? null : $this->lampiran->Simpan($this->konteks->Wajib(), 'belanja', $data->lampiran);

        try {
            return DB::transaction(fn (): PenerimaanBarang => $this->Proses($data, $idAkun, $berkas), 3);
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
    private function Proses(DataBelanjaStok $data, ?int $idAkun, ?array $berkas): PenerimaanBarang
    {
        $this->pengaturanPersediaan->AmbilDenganKunciBaca();
        $pemasok = $data->uuidPemasok === null ? null : Pemasok::query()->where('Uuid', $data->uuidPemasok)->where('Aktif', true)->first();

        if ($data->uuidPemasok !== null && $pemasok === null) {
            throw new PelanggaranAturanBisnis('PemasokTidakDikenal', 'Pemasok tidak ditemukan atau nonaktif.', 'UuidPemasok');
        }

        $gudang = $this->infoGudang->AmbilBanyak([$data->idGudang])[$data->idGudang] ?? null;

        if ($gudang === null || ! $gudang->aktif) {
            throw new PelanggaranAturanBisnis('GudangTidakDikenal', 'Lokasi stok tidak ditemukan atau diarsipkan.', 'UuidGudang');
        }

        $hasil = $this->pemroses->Buat(null, $pemasok, $gudang, $data->tanggal, $data->baris, $data->ongkir, $data->nomorNota, $data->catatan, $berkas, true, $data->idPengguna);
        $grn = $hasil['Dokumen'];
        $pajak = $hasil['Pajak'];
        $total = Uang::Dari($grn->Subtotal)->Tambah(Uang::Dari($grn->Ongkir))->Tambah($pajak->pajak);

        if ($total->Bandingkan(Uang::Nol()) <= 0) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Total belanja harus lebih dari Rp 0.', 'Baris');
        }

        // D-256: sumber dana uang laci melunasi akun penampung, bukan akun kas/bank.
        $idAkun ??= $this->AmbilAkunUangLaci($gudang->idOutlet, $total);

        $faktur = $this->BuatFaktur($grn, $data, $total);
        $pembayaran = PembayaranHutang::query()->create([
            'Nomor' => $this->penomor->AmbilNomorTenant(JenisDokumenBernomor::PembayaranHutang, $data->tanggal),
            'IdPemasok' => $pemasok?->Id,
            'IdAkun' => $idAkun,
            'IdOutlet' => $gudang->idOutlet,
            'Tanggal' => $data->tanggal->toDateString(),
            'Jumlah' => $total->KeString(),
            'Status' => StatusDokumenTerposting::Diposting,
            'BelanjaStok' => true,
            'Catatan' => "Pelunasan belanja stok {$grn->Nomor}".($data->dariUangLaci ? ' dari uang laci kasir' : ''),
            'DibuatOleh' => $data->idPengguna,
        ]);
        PembayaranHutangAlokasi::query()->create(['IdPembayaranHutang' => $pembayaran->Id, 'IdFakturPembelian' => $faktur->Id, 'Jumlah' => $total->KeString()]);

        $baris = PenyusunJurnalPembelian::Seimbangkan([
            ...$this->penyusunJurnal->BarisPersediaan($hasil['Mutasi'], $hasil['Produk'], $gudang->idOutlet),
            $pajak->dikreditkan ? DataBarisJurnal::DariSelisih(PeranAkun::PpnMasukan, $pajak->pajak, $gudang->idOutlet) : null,
            PenyusunJurnalPembelian::BarisAkun($idAkun, Uang::Nol()->Kurangi($total), $gudang->idOutlet, "Belanja stok {$grn->Nomor}"),
        ], $gudang->idOutlet);

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::PenerimaanBarang,
            idSumber: $grn->Id,
            uuidSumber: $grn->Uuid,
            nomorSumber: $grn->Nomor,
            tanggal: $data->tanggal,
            keterangan: mb_substr("Belanja stok {$grn->Nomor}".($pemasok === null ? '' : " dari {$pemasok->Nama}"), 0, 255),
            baris: $baris,
            idPengguna: $data->idPengguna,
        ));

        $grn->fill(['IdJurnal' => $jurnal->idJurnal, 'IdFakturPembelian' => $faktur->Id])->save();
        $faktur->IdJurnal = $jurnal->idJurnal;
        $faktur->save();
        $pembayaran->IdJurnal = $jurnal->idJurnal;
        $pembayaran->save();

        $this->riwayat->Catat(PenerimaanBarang::JENIS_DOKUMEN, $grn->Id, null, StatusDokumenTerposting::Diposting->value, $data->idPengguna);
        $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $faktur->Id, null, StatusFakturPembelian::Lunas->value, $data->idPengguna);
        $this->riwayat->Catat(PembayaranHutang::JENIS_DOKUMEN, $pembayaran->Id, null, StatusDokumenTerposting::Diposting->value, $data->idPengguna);
        $this->audit->Catat('belanja-stok.simpan', $grn, nilaiBaru: [
            'Nomor' => $grn->Nomor,
            'NomorFaktur' => $faktur->Nomor,
            'NomorPembayaran' => $pembayaran->Nomor,
            'Pemasok' => $pemasok?->Nama,
            'Total' => $total->KeString(),
            'SumberDana' => $data->dariUangLaci ? 'UangLaci' : 'KasBank',
            'NomorJurnal' => $jurnal->nomor,
        ], idPengguna: $data->idPengguna);

        return $grn;
    }

    /**
     * Akun penampung uang laci untuk outlet gudang, dikunci supaya dua belanja bersamaan tidak sama-sama memakai saldo
     * yang sama. Total belanja tidak boleh melebihi uang laci yang dicatat keluar di kasir outlet itu.
     *
     * @throws PelanggaranAturanBisnis UangLaciTidakAda, UangLaciTidakCukup
     */
    private function AmbilAkunUangLaci(?int $idOutlet, Uang $total): int
    {
        $idAkun = $this->penentuAkun->CariIdAkun(PeranAkun::KliringBelanjaLaci, null);

        if ($idAkun === null) {
            throw new PelanggaranAturanBisnis('UangLaciTidakAda', 'Belum ada uang laci yang dicatat untuk belanja stok. Catat dulu kas keluar "Belanja stok dari uang laci" di kasir, atau pilih akun kas/bank.', 'UuidAkun');
        }

        Akun::query()->whereKey($idAkun)->lockForUpdate()->first();
        $saldo = $this->saldoLaci->Outlet($idOutlet);

        if ($total->Bandingkan($saldo) > 0) {
            throw new PelanggaranAturanBisnis(
                'UangLaciTidakCukup',
                "Uang laci yang dicatat untuk belanja stok di outlet ini {$saldo->FormatRupiah()}, kurang dari total belanja {$total->FormatRupiah()}. Catat kas keluar di kasir dulu, atau pilih akun kas/bank untuk sisanya.",
                'UuidAkun',
            );
        }

        return $idAkun;
    }

    private function BuatFaktur(PenerimaanBarang $grn, DataBelanjaStok $data, Uang $total): FakturPembelian
    {
        $faktur = FakturPembelian::query()->create([
            'Nomor' => $this->penomor->AmbilNomorTenant(JenisDokumenBernomor::FakturPembelian, $data->tanggal),
            'NomorFakturPemasok' => mb_substr(trim((string) $data->nomorNota) !== '' ? trim((string) $data->nomorNota) : $grn->Nomor, 0, 60),
            'IdPemasok' => $grn->IdPemasok,
            'IdOutlet' => $grn->IdOutlet,
            'Tanggal' => $data->tanggal->toDateString(),
            'JatuhTempo' => $data->tanggal->toDateString(),
            'TerminHari' => 0,
            'Status' => StatusFakturPembelian::Lunas,
            'TarifPpn' => $grn->TarifPpn,
            'PengaliDppPembilang' => $grn->PengaliDppPembilang,
            'PengaliDppPenyebut' => $grn->PengaliDppPenyebut,
            'PpnDikreditkan' => $grn->PpnDikreditkan,
            'NilaiPenerimaan' => $grn->TotalNilai,
            'Subtotal' => $grn->Subtotal,
            'Ongkir' => $grn->Ongkir,
            'Pajak' => $grn->Pajak,
            'SelisihHarga' => '0.00',
            'Total' => $total->KeString(),
            'JumlahDibayar' => $total->KeString(),
            'BelanjaStok' => true,
            'DibuatOleh' => $data->idPengguna,
        ]);

        $detail = array_values(PenerimaanBarangDetail::query()->where('IdPenerimaanBarang', $grn->Id)->orderBy('Urutan')->get()->all());
        $bobot = array_values(array_map(fn (PenerimaanBarangDetail $d): Uang => Uang::Dari($d->Subtotal), $detail));
        $ongkir = $this->alokasi->Alokasikan(Uang::Dari($grn->Ongkir), $bobot);
        $pajak = $this->alokasi->Alokasikan(Uang::Dari($grn->Pajak), $bobot);

        foreach ($detail as $i => $d) {
            FakturPembelianDetail::query()->create([
                'IdFakturPembelian' => $faktur->Id,
                'Urutan' => $i + 1,
                'IdPenerimaanBarangDetail' => $d->Id,
                'IdProduk' => $d->IdProduk,
                'NamaProduk' => $d->NamaProduk,
                'SimbolSatuan' => $d->SimbolSatuan,
                'Jumlah' => $d->Jumlah,
                'JumlahDasar' => $d->JumlahDasar,
                'HargaPenerimaan' => $d->Harga,
                'SubtotalPenerimaan' => $d->Subtotal,
                'Harga' => $d->Harga,
                'Diskon' => $d->Diskon,
                'Subtotal' => $d->Subtotal,
                'NilaiPenerimaan' => $d->Nilai,
                'AlokasiOngkir' => $ongkir[$i]->KeString(),
                'Pajak' => $pajak[$i]->KeString(),
            ]);
        }

        return $faktur;
    }
}
