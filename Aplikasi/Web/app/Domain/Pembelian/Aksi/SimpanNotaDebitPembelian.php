<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Pembelian\Data\DataNotaDebitPembelian;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PengalokasiNilai;
use App\Domain\Pembelian\Layanan\PenomorPembelian;
use App\Domain\Pembelian\Layanan\PenyusunJurnalPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\NotaDebitPembelian;
use App\Domain\Pembelian\Model\NotaDebitPembelianDetail;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\SaldoStokPasangan;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PetaAkunPersediaan;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Kueri\PengaturanPembelianTenant;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nota debit pembelian (v6.07, F-04 BR-04.10; izin `pembelian.kelola`): pengurang hutang atas satu faktur pembelian
 * karena **selisih harga atau klaim pemasok tanpa barang kembali** (barang kembali = retur pembelian).
 *
 * 1. `NilaiKlaim` dibagi ke baris faktur yang masih punya sisa nilai (harga faktur + ongkir − diretur − nota debit
 *    aktif) sebanding sisa itu; tidak boleh melebihi total sisa (`MelebihiNilaiFaktur`). PPN masukan kontra sebanding
 *    tarif efektif baris faktur (tanpa tarif di kode), hanya bila PPN faktur dikreditkan.
 * 2. Per produk di gudang penerimaan: bagian untuk stok yang **masih ada** (saldo ÷ diterima bersih) menurunkan nilai
 *    persediaan lewat penilaian ulang (seluruh saldo keluar `RevaluasiKeluar` pada nilai berjalan, masuk lagi pada nilai
 *    − klaim; jumlah bersih nol, `SaldoStok` tetap = Σ mutasi); sisanya (sudah terjual) mengurangi HPP (BR-04.4).
 *    Produk ber-batch/seri/konsinyasi dan saldo ≤ 0 seluruhnya ke HPP. `SelisihHpp` tidak dipakai.
 * 3. Hutang: bagian pemotong sisa hutang faktur (`PotongHutang`, menambah `FakturPembelian.JumlahRetur`); bagian yang
 *    melebihi sisa (faktur sudah dibayar) = `NilaiRefund` diterima ke kas/bank pilihan operator (BR-04.9).
 * 4. Jurnal J-04.7: Dr Hutang Usaha (+ Dr kas/bank refund), Cr persediaan (per peran jenis produk), Cr HPP, Cr PPN Masukan.
 *
 * Nota di atas `BatasPersetujuanPo` hanya bisa dicatat pemegang izin `pembelian.po.setujui` (`bolehMelebihiBatas`).
 * Tanggal tidak boleh sebelum tanggal faktur, di masa depan, atau di periode terkunci. Audit `nota-debit.posting`.
 *
 * Urutan kunci: L1 Tenant (S) → GRN faktur → faktur → penghitung ND (L7) → buku stok → penghitung JU.
 */
final class SimpanNotaDebitPembelian
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturanPersediaan,
        private readonly PengaturanPembelianTenant $pengaturanPembelian,
        private readonly DaftarAkunPilihan $akun,
        private readonly InfoProdukStok $infoProduk,
        private readonly SaldoStokPasangan $saldo,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PetaAkunPersediaan $petaAkun,
        private readonly PengalokasiNilai $pengalokasi,
        private readonly PemrosesPenerimaanBarang $pemroses,
        private readonly PenomorPembelian $penomor,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, NilaiTidakValid, FakturTidakValid, MelebihiNilaiFaktur, AkunRefundWajib, …
     */
    public function Jalankan(DataNotaDebitPembelian $data): NotaDebitPembelian
    {
        $alasan = trim($data->alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan nota debit wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        if ($data->nilaiKlaim->Bandingkan(Uang::Nol()) <= 0) {
            throw new PelanggaranAturanBisnis('NilaiTidakValid', 'Nilai nota debit harus lebih dari Rp 0.', 'NilaiKlaim');
        }

        $nomorPemasok = $data->nomorNotaPemasok === null ? '' : trim($data->nomorNotaPemasok);

        if (mb_strlen($nomorPemasok) > 60) {
            throw new PelanggaranAturanBisnis('NomorNotaPemasokTerlaluPanjang', 'Nomor nota dari pemasok paling banyak 60 karakter.', 'NomorNotaPemasok');
        }

        return DB::transaction(fn (): NotaDebitPembelian => $this->Proses($data, $alasan, $nomorPemasok === '' ? null : $nomorPemasok), 3);
    }

    private function Proses(DataNotaDebitPembelian $data, string $alasan, ?string $nomorPemasok): NotaDebitPembelian
    {
        $this->pengaturanPersediaan->AmbilDenganKunciBaca();
        $grn = PenerimaanBarang::query()->where('IdFakturPembelian', $data->idFakturPembelian)->orderBy('Id')->lockForUpdate()->get()->keyBy('Id');
        $faktur = FakturPembelian::query()->whereKey($data->idFakturPembelian)->lockForUpdate()->first();

        if ($faktur === null || $faktur->Status === StatusFakturPembelian::Dibatalkan) {
            throw new PelanggaranAturanBisnis('FakturTidakValid', 'Faktur pembelian tidak ditemukan atau sudah dibatalkan.', 'UuidFaktur');
        }

        if ($data->tanggal->toDateString() < $faktur->Tanggal->toDateString()) {
            throw new PelanggaranAturanBisnis('TanggalSebelumFaktur', 'Tanggal nota debit tidak boleh sebelum tanggal faktur ('.$faktur->Tanggal->format('d/m/Y').').', 'Tanggal');
        }

        $this->pemroses->PastikanTanggal($data->tanggal, $faktur->IdOutlet);

        /** @var Collection<int, FakturPembelianDetail> $semuaBaris */
        $semuaBaris = FakturPembelianDetail::query()->where('IdFakturPembelian', $faktur->Id)->orderBy('Urutan')->orderBy('Id')->get()->keyBy('Id');
        $sudah = self::HitungSudahDinotakan(array_values($semuaBaris->keys()->all()));
        $pilihan = array_values(array_unique($data->idFakturPembelianDetail));

        foreach ($pilihan as $id) {
            if (! $semuaBaris->has($id)) {
                throw new PelanggaranAturanBisnis('BarisTidakDikenal', 'Ada baris yang bukan bagian faktur ini.', 'Baris');
            }
        }

        /** @var list<array{Baris: FakturPembelianDetail, Kapasitas: Uang}> $terpilih */
        $terpilih = [];

        foreach ($semuaBaris as $id => $b) {
            if ($pilihan !== [] && ! in_array($id, $pilihan, true)) {
                continue;
            }

            $kapasitas = Uang::Dari($b->Subtotal)->Tambah(Uang::Dari($b->AlokasiOngkir))->Kurangi(Uang::Dari($b->NilaiDiretur))->Kurangi($sudah[$id]['NilaiKlaim'] ?? Uang::Nol());

            if ($kapasitas->Bandingkan(Uang::Nol()) > 0) {
                $terpilih[] = ['Baris' => $b, 'Kapasitas' => $kapasitas];
            }
        }

        $totalKapasitas = array_reduce($terpilih, fn (Uang $t, array $o): Uang => $t->Tambah($o['Kapasitas']), Uang::Nol());

        if ($terpilih === [] || $data->nilaiKlaim->Bandingkan($totalKapasitas) > 0) {
            throw new PelanggaranAturanBisnis(
                'MelebihiNilaiFaktur',
                'Nilai nota debit '.$data->nilaiKlaim->FormatRupiah().' melebihi sisa nilai faktur yang bisa diklaim ('.$totalKapasitas->FormatRupiah().').',
                'NilaiKlaim',
            );
        }

        $alokasi = $this->pengalokasi->Alokasikan($data->nilaiKlaim, array_map(fn (array $o): Uang => $o['Kapasitas'], $terpilih));
        $pajak = $this->HitungPajak($faktur, $terpilih, $alokasi, $sudah);
        $totalPajak = array_reduce($pajak, fn (Uang $t, Uang $p): Uang => $t->Tambah($p), Uang::Nol());
        $total = $data->nilaiKlaim->Tambah($totalPajak);
        $batas = $this->pengaturanPembelian->Ambil()->batasPersetujuanPo;

        if (! $data->bolehMelebihiBatas && $total->Bandingkan($batas) > 0) {
            throw new PelanggaranAturanBisnis(
                'MelebihiBatasPersetujuan',
                'Nota debit '.$total->FormatRupiah().' melebihi batas persetujuan pembelian ('.$batas->FormatRupiah().'). Minta pemegang izin menyetujui pesanan pembelian untuk mencatatnya.',
                'NilaiKlaim',
            );
        }

        $sisaHutang = $faktur->AmbilSisa()->BernilaiNegatif() ? Uang::Nol() : $faktur->AmbilSisa();
        $potongHutang = $total->Bandingkan($sisaHutang) > 0 ? $sisaHutang : $total;
        $refund = $total->Kurangi($potongHutang);
        $idAkunRefund = null;

        if (! $refund->BernilaiNol()) {
            $idAkunRefund = $data->uuidAkunRefund === null || $data->uuidAkunRefund === '' ? null : ($this->akun->CariKasBankDariUuid($data->uuidAkunRefund)['Id'] ?? null);

            if ($idAkunRefund === null) {
                throw new PelanggaranAturanBisnis(
                    'AkunRefundWajib',
                    "Nota debit {$total->FormatRupiah()} melebihi sisa hutang faktur {$faktur->Nomor} ({$sisaHutang->FormatRupiah()}). Pilih akun kas/bank penerima pengembalian uang {$refund->FormatRupiah()} dari pemasok.",
                    'UuidAkunRefund',
                );
            }
        }

        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (array $o): int => $o['Baris']->IdProduk, $terpilih))), denganTerhapus: true);
        [$ke1, $kelompok] = $this->HitungPorsi($semuaBaris->all(), $terpilih, $alokasi, $produk, $grn->all());

        $nota = NotaDebitPembelian::query()->create([
            'Nomor' => $this->penomor->AmbilNomorTenant(JenisDokumenBernomor::NotaDebitPembelian, $data->tanggal),
            'NomorNotaPemasok' => $nomorPemasok,
            'IdFakturPembelian' => $faktur->Id,
            'IdPemasok' => $faktur->IdPemasok,
            'IdOutlet' => $faktur->IdOutlet,
            'Jenis' => $data->jenis,
            'Tanggal' => $data->tanggal->toDateString(),
            'NilaiKlaim' => $data->nilaiKlaim->KeString(),
            'Pajak' => $totalPajak->KeString(),
            'Total' => $total->KeString(),
            'PotongHutang' => $potongHutang->KeString(),
            'NilaiRefund' => $refund->KeString(),
            'IdAkunRefund' => $idAkunRefund,
            'Alasan' => $alasan,
            'Status' => StatusDokumenTerposting::Diposting,
            'DibuatOleh' => $data->idPengguna,
        ]);

        $ke1 = $this->NilaiUlang($nota, $data, $kelompok, $ke1, $terpilih);
        $kePersediaan = array_reduce($ke1, fn (Uang $t, Uang $u): Uang => $t->Tambah($u), Uang::Nol());
        $keHpp = $data->nilaiKlaim->Kurangi($kePersediaan);

        foreach ($terpilih as $i => $o) {
            NotaDebitPembelianDetail::query()->create([
                'IdNotaDebitPembelian' => $nota->Id,
                'Urutan' => $i + 1,
                'IdFakturPembelianDetail' => $o['Baris']->Id,
                'IdProduk' => $o['Baris']->IdProduk,
                'NamaProduk' => $o['Baris']->NamaProduk,
                'NilaiKlaim' => $alokasi[$i]->KeString(),
                'Pajak' => $pajak[$i]->KeString(),
                'KePersediaan' => $ke1[$i]->KeString(),
                'KeHpp' => $alokasi[$i]->Kurangi($ke1[$i])->KeString(),
            ]);
        }

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::NotaDebitPembelian,
            idSumber: $nota->Id,
            uuidSumber: $nota->Uuid,
            nomorSumber: $nota->Nomor,
            tanggal: $data->tanggal,
            keterangan: mb_substr("Nota debit pembelian {$nota->Nomor} atas faktur {$faktur->Nomor}", 0, 255),
            baris: $this->SusunBarisJurnal($faktur, $nota, $terpilih, $ke1, $produk, $keHpp, $totalPajak, $potongHutang, $refund, $idAkunRefund),
            idPengguna: $data->idPengguna,
        ));

        $nota->forceFill(['KePersediaan' => $kePersediaan->KeString(), 'KeHpp' => $keHpp->KeString(), 'IdJurnal' => $jurnal->idJurnal])->save();

        $asal = $faktur->Status;
        // Hanya bagian pemotong hutang: bagian refund sudah dibayar, jadi tidak ada sisa tagihan untuk dikurangi.
        $faktur->JumlahRetur = Uang::Dari($faktur->JumlahRetur)->Tambah($potongHutang)->KeString();
        $faktur->SelaraskanStatus();
        $faktur->save();

        if ($asal !== $faktur->Status) {
            $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $faktur->Id, $asal->value, $faktur->Status->value, $data->idPengguna);
        }

        $this->riwayat->Catat(NotaDebitPembelian::JENIS_DOKUMEN, $nota->Id, null, StatusDokumenTerposting::Diposting->value, $data->idPengguna);
        $this->audit->Catat('nota-debit.posting', $nota, nilaiBaru: [
            'Nomor' => $nota->Nomor,
            'NomorFaktur' => $faktur->Nomor,
            'Jenis' => $data->jenis->value,
            'NilaiKlaim' => $nota->NilaiKlaim,
            'Pajak' => $nota->Pajak,
            'PotongHutang' => $nota->PotongHutang,
            'NilaiRefund' => $nota->NilaiRefund,
            'KePersediaan' => $nota->KePersediaan,
            'KeHpp' => $nota->KeHpp,
            'Alasan' => $alasan,
            'NomorJurnal' => $jurnal->nomor,
        ], idPengguna: $data->idPengguna);

        return $nota;
    }

    /**
     * Nilai klaim & pajak nota debit aktif per baris faktur.
     *
     * @param  list<int>  $idBaris
     * @return array<int, array{NilaiKlaim: Uang, Pajak: Uang}>
     */
    public static function HitungSudahDinotakan(array $idBaris): array
    {
        if ($idBaris === []) {
            return [];
        }

        $hasil = [];

        foreach (NotaDebitPembelianDetail::query()
            ->whereIn('IdFakturPembelianDetail', $idBaris)
            ->whereIn('IdNotaDebitPembelian', NotaDebitPembelian::query()->where('Status', StatusDokumenTerposting::Diposting->value)->select('Id'))
            ->get(['IdFakturPembelianDetail', 'NilaiKlaim', 'Pajak']) as $d) {
            $id = $d->IdFakturPembelianDetail;
            $hasil[$id] = [
                'NilaiKlaim' => ($hasil[$id]['NilaiKlaim'] ?? Uang::Nol())->Tambah(Uang::Dari($d->NilaiKlaim)),
                'Pajak' => ($hasil[$id]['Pajak'] ?? Uang::Nol())->Tambah(Uang::Dari($d->Pajak)),
            ];
        }

        return $hasil;
    }

    /**
     * PPN masukan kontra per baris: nilai klaim × (PPN baris ÷ dasar baris faktur), dibatasi sisa PPN baris itu.
     *
     * @param  list<array{Baris: FakturPembelianDetail, Kapasitas: Uang}>  $terpilih
     * @param  list<Uang>  $alokasi
     * @param  array<int, array{NilaiKlaim: Uang, Pajak: Uang}>  $sudah
     * @return list<Uang>
     */
    private function HitungPajak(FakturPembelian $faktur, array $terpilih, array $alokasi, array $sudah): array
    {
        $hasil = [];

        foreach ($terpilih as $i => $o) {
            $b = $o['Baris'];
            $dasar = Uang::Dari($b->Subtotal)->Tambah(Uang::Dari($b->AlokasiOngkir));
            $sisaPajak = Uang::Dari($b->Pajak)->Kurangi(Uang::Dari($b->PajakDiretur))->Kurangi($sudah[$b->Id]['Pajak'] ?? Uang::Nol());

            if (! $faktur->PpnDikreditkan || $dasar->BernilaiNol() || $sisaPajak->Bandingkan(Uang::Nol()) <= 0) {
                $hasil[] = Uang::Nol();

                continue;
            }

            $nilai = Uang::Dari(BigRational::of($alokasi[$i]->KeString())->multipliedBy($b->Pajak)->dividedBy($dasar->KeString())->toScale(Uang::SKALA, RoundingMode::HalfUp));
            $hasil[] = $nilai->Bandingkan($sisaPajak) > 0 ? $sisaPajak : $nilai;
        }

        return $hasil;
    }

    /**
     * Porsi persediaan awal per baris (sebelum dibatasi nilai berjalan) dan kelompok penilaian ulang per produk × gudang.
     *
     * @param  array<int, FakturPembelianDetail>  $semuaBaris  seluruh baris faktur (dasar jumlah diterima bersih)
     * @param  list<array{Baris: FakturPembelianDetail, Kapasitas: Uang}>  $terpilih
     * @param  list<Uang>  $alokasi
     * @param  array<int, DataInfoProdukStok>  $produk
     * @param  array<int, PenerimaanBarang>  $grn
     * @return array{0: list<Uang>, 1: array<string, array{IdProduk: int, IdGudang: int, Saldo: Kuantitas, Baris: list<int>}>}
     */
    private function HitungPorsi(array $semuaBaris, array $terpilih, array $alokasi, array $produk, array $grn): array
    {
        $idDetailGrn = array_values(array_unique(array_map(fn (FakturPembelianDetail $b): int => $b->IdPenerimaanBarangDetail, $semuaBaris)));
        $gudangPerDetail = [];

        foreach (PenerimaanBarangDetail::query()->whereKey($idDetailGrn)->get(['Id', 'IdPenerimaanBarang']) as $d) {
            $gudangPerDetail[$d->Id] = $grn[$d->IdPenerimaanBarang]->IdGudang ?? null;
        }

        $diterima = [];

        foreach ($semuaBaris as $b) {
            $idGudang = $gudangPerDetail[$b->IdPenerimaanBarangDetail] ?? null;

            if ($idGudang === null) {
                continue;
            }

            $kunci = SaldoStok::BuatKunciPasangan($b->IdProduk, $idGudang);
            $diterima[$kunci] = ($diterima[$kunci] ?? Kuantitas::Nol())->Tambah(Kuantitas::Dari($b->JumlahDasar)->Kurangi(Kuantitas::Dari($b->JumlahDiretur)));
        }

        $pasangan = [];

        foreach ($terpilih as $o) {
            $idGudang = $gudangPerDetail[$o['Baris']->IdPenerimaanBarangDetail] ?? null;

            if ($idGudang !== null) {
                $pasangan[SaldoStok::BuatKunciPasangan($o['Baris']->IdProduk, $idGudang)] = [$o['Baris']->IdProduk, $idGudang];
            }
        }

        $saldo = $this->saldo->Ambil(array_values($pasangan));
        $porsi = [];
        $kelompok = [];

        foreach ($terpilih as $i => $o) {
            $b = $o['Baris'];
            $idGudang = $gudangPerDetail[$b->IdPenerimaanBarangDetail] ?? null;
            $info = $produk[$b->IdProduk] ?? null;
            $porsi[$i] = Uang::Nol();

            if ($idGudang === null || $info === null) {
                continue;
            }

            $kunci = SaldoStok::BuatKunciPasangan($b->IdProduk, $idGudang);
            $ada = $saldo[$kunci] ?? Kuantitas::Nol();
            $dasar = $diterima[$kunci] ?? Kuantitas::Nol();

            if ($info->pelacakan !== PelacakanProduk::Tidak || $info->jenis === JenisProduk::Konsinyasi || ! $ada->KeDesimal()->isPositive() || ! $dasar->KeDesimal()->isPositive()) {
                continue;
            }

            $bagian = BigRational::of((string) $ada)->dividedBy((string) $dasar);
            $bagian = $bagian->compareTo(1) > 0 ? BigRational::one() : $bagian;
            $porsi[$i] = Uang::Dari(BigRational::of($alokasi[$i]->KeString())->multipliedBy($bagian)->toScale(Uang::SKALA, RoundingMode::HalfUp));
            $kelompok[$kunci] ??= ['IdProduk' => $b->IdProduk, 'IdGudang' => $idGudang, 'Saldo' => $ada, 'Baris' => []];
            $kelompok[$kunci]['Baris'][] = $i;
        }

        return [array_values($porsi), array_filter($kelompok, fn (array $k): bool => array_reduce($k['Baris'], fn (Uang $t, int $i): Uang => $t->Tambah($porsi[$i]), Uang::Nol())->Bandingkan(Uang::Nol()) > 0)];
    }

    /**
     * Penilaian ulang per kelompok: seluruh saldo keluar pada nilai berjalan; porsi persediaan dibatasi nilai berjalan itu
     * (selebihnya jatuh ke HPP) lalu saldo masuk lagi pada nilai berjalan − porsi. Mengembalikan porsi akhir per baris.
     *
     * @param  list<Uang>  $porsi
     * @param  array<string, array{IdProduk: int, IdGudang: int, Saldo: Kuantitas, Baris: list<int>}>  $kelompok
     * @param  list<array{Baris: FakturPembelianDetail, Kapasitas: Uang}>  $terpilih
     * @return list<Uang>
     */
    private function NilaiUlang(NotaDebitPembelian $nota, DataNotaDebitPembelian $data, array $kelompok, array $porsi, array $terpilih): array
    {
        if ($kelompok === []) {
            return $porsi;
        }

        $kunci = array_keys($kelompok);
        $keluar = $this->catatMutasi->Jalankan(self::DokumenMutasi($nota, $data, array_map(fn (string $k): DataBarisMutasi => new DataBarisMutasi(
            kunciBaris: 'K/'.$k,
            idProduk: $kelompok[$k]['IdProduk'],
            idGudang: $kelompok[$k]['IdGudang'],
            jenisMutasi: JenisMutasi::RevaluasiKeluar,
            jumlah: $kelompok[$k]['Saldo']->Negasi(),
            modeNilai: ModeNilaiMutasi::Berjalan,
        ), $kunci)));

        $masuk = [];

        foreach ($kelompok as $k => $g) {
            $nilaiBerjalan = AritmetikaHpp::AmbilMutlak($keluar->baris['K/'.$k]->totalHpp);
            $diminta = array_reduce($g['Baris'], fn (Uang $t, int $i): Uang => $t->Tambah($porsi[$i]), Uang::Nol());

            if ($diminta->Bandingkan($nilaiBerjalan) > 0) {
                $dibagi = $this->pengalokasi->Alokasikan($nilaiBerjalan, array_map(fn (int $i): Uang => $porsi[$i], $g['Baris']));

                foreach ($g['Baris'] as $n => $i) {
                    $porsi[$i] = $dibagi[$n];
                }

                $diminta = $nilaiBerjalan;
            }

            $nilai = $nilaiBerjalan->Kurangi($diminta);
            $masuk[] = new DataBarisMutasi(
                kunciBaris: 'M/'.$k,
                idProduk: $g['IdProduk'],
                idGudang: $g['IdGudang'],
                jenisMutasi: JenisMutasi::RevaluasiMasuk,
                jumlah: $g['Saldo'],
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: $nilai,
                hppSatuan: BigDecimal::of($nilai->KeString())->dividedBy($g['Saldo']->KeDesimal(), 6, RoundingMode::HalfUp),
            );
        }

        $this->catatMutasi->Jalankan(self::DokumenMutasi($nota, $data, $masuk));

        return array_values($porsi);
    }

    /**
     * @param  list<DataBarisMutasi>  $baris
     */
    private static function DokumenMutasi(NotaDebitPembelian $nota, DataNotaDebitPembelian $data, array $baris): DataDokumenMutasi
    {
        return new DataDokumenMutasi(
            JenisReferensiMutasi::NotaDebitPembelian,
            $nota->Id,
            $nota->Uuid,
            $nota->Nomor,
            $data->tanggal,
            $data->idPengguna,
            null,
            $baris,
            abaikanBatasMinus: false,
        );
    }

    /**
     * @param  list<array{Baris: FakturPembelianDetail, Kapasitas: Uang}>  $terpilih
     * @param  list<Uang>  $ke1
     * @param  array<int, DataInfoProdukStok>  $produk
     * @return list<DataBarisJurnal>
     */
    private function SusunBarisJurnal(FakturPembelian $faktur, NotaDebitPembelian $nota, array $terpilih, array $ke1, array $produk, Uang $keHpp, Uang $pajak, Uang $potongHutang, Uang $refund, ?int $idAkunRefund): array
    {
        /** @var array<string, Uang> $perPeran */
        $perPeran = [];

        foreach ($terpilih as $i => $o) {
            $peran = $this->petaAkun->UntukJenis($produk[$o['Baris']->IdProduk]->jenis)->value;
            $perPeran[$peran] = ($perPeran[$peran] ?? Uang::Nol())->Tambah($ke1[$i]);
        }

        ksort($perPeran);
        $baris = [
            DataBarisJurnal::DariSelisih(PeranAkun::HutangUsaha, $potongHutang, $faktur->IdOutlet),
            $idAkunRefund === null ? null : PenyusunJurnalPembelian::BarisAkun($idAkunRefund, $refund, $faktur->IdOutlet, "Pengembalian uang nota debit {$nota->Nomor}"),
        ];

        foreach ($perPeran as $peran => $nilai) {
            $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::from($peran), Uang::Nol()->Kurangi($nilai), $faktur->IdOutlet);
        }

        $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::Hpp, Uang::Nol()->Kurangi($keHpp), $faktur->IdOutlet);
        $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::PpnMasukan, Uang::Nol()->Kurangi($pajak), $faktur->IdOutlet);

        return array_values(array_filter($baris, fn (?DataBarisJurnal $b): bool => $b !== null));
    }
}
