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
use App\Domain\Pembelian\Data\DataBiayaTambahanPembelian;
use App\Domain\Pembelian\Enum\DasarAlokasiBiaya;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PengalokasiNilai;
use App\Domain\Pembelian\Layanan\PenomorPembelian;
use App\Domain\Pembelian\Layanan\PenyusunJurnalPembelian;
use App\Domain\Pembelian\Model\BiayaTambahanPembelian;
use App\Domain\Pembelian\Model\BiayaTambahanPembelianDetail;
use App\Domain\Pembelian\Model\Pemasok;
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
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Biaya tambahan pembelian (v3.41, INV-14 landed cost; izin `pembelian.kelola`): biaya pihak ketiga atas satu penerimaan
 * barang yang dicatat, dibayar dari kas/bank.
 *
 * 1. Dialokasikan ke baris GRN yang masih punya sisa (jumlah dasar − diretur) sebanding nilai atau jumlah
 *    (`PengalokasiNilai`, Σ tepat sama dengan biaya).
 * 2. Per produk: bagian untuk stok yang **masih ada** di lokasi GRN = alokasi × min(saldo, diterima bersih) ÷ diterima
 *    bersih → nilai persediaan naik lewat penilaian ulang (seluruh saldo keluar `RevaluasiKeluar` pada nilai berjalan,
 *    lalu masuk `RevaluasiMasuk` pada nilai + biaya; jumlah bersih nol). Sisanya (barang yang sudah terjual/dipakai)
 *    ke HPP (BR-04.4). Produk berpelacakan batch/seri dan saldo ≤ 0 seluruhnya ke HPP.
 * 3. Jurnal: Dr persediaan (peran per jenis produk, outlet GRN) + Dr HPP, Cr kas/bank.
 *
 * Tanggal tidak boleh sebelum tanggal GRN, di masa depan, atau di periode terkunci. Audit `biaya-tambahan.posting`.
 */
final class CatatBiayaTambahanPembelian
{
    public function __construct(
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
     * @throws PelanggaranAturanBisnis AkunKasBankWajib, JumlahTidakValid, PenerimaanTidakValid, TanggalSebelumPenerimaan, …
     */
    public function Jalankan(DataBiayaTambahanPembelian $data): BiayaTambahanPembelian
    {
        $akun = $this->akun->CariKasBankDariUuid($data->uuidAkun);

        if ($akun === null) {
            throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas/bank aktif yang membayar biaya ini.', 'UuidAkun');
        }

        if ($data->jumlah->Bandingkan(Uang::Nol()) <= 0) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah biaya harus lebih dari Rp 0.', 'Jumlah');
        }

        $idPemasok = null;

        if ($data->uuidPemasok !== null && $data->uuidPemasok !== '') {
            $idPemasok = Pemasok::query()->where('Uuid', $data->uuidPemasok)->value('Id');

            if (! is_int($idPemasok)) {
                throw new PelanggaranAturanBisnis('PemasokTidakDikenal', 'Pihak penagih tidak ditemukan.', 'UuidPemasok');
            }
        }

        return DB::transaction(function () use ($data, $akun, $idPemasok): BiayaTambahanPembelian {
            $grn = PenerimaanBarang::query()->whereKey($data->idPenerimaanBarang)->lockForUpdate()->firstOrFail();

            if ($grn->Status !== StatusDokumenTerposting::Diposting) {
                throw new PelanggaranAturanBisnis('PenerimaanTidakValid', "{$grn->Nomor} sudah dibatalkan.", 'UuidPenerimaan');
            }

            if ($data->tanggal->toDateString() < $grn->Tanggal->toDateString()) {
                throw new PelanggaranAturanBisnis('TanggalSebelumPenerimaan', 'Tanggal biaya tidak boleh sebelum tanggal penerimaan ('.$grn->Tanggal->format('d/m/Y').').', 'Tanggal');
            }

            $this->pemroses->PastikanTanggal($data->tanggal, $grn->IdOutlet);

            /** @var list<PenerimaanBarangDetail> $baris */
            $baris = array_values(PenerimaanBarangDetail::query()->where('IdPenerimaanBarang', $grn->Id)->orderBy('Urutan')->orderBy('Id')->get()
                ->filter(fn (PenerimaanBarangDetail $d): bool => Kuantitas::Dari($d->JumlahDasar)->Kurangi(Kuantitas::Dari($d->JumlahDiretur))->KeDesimal()->isPositive())
                ->all());

            if ($baris === []) {
                throw new PelanggaranAturanBisnis('PenerimaanTidakValid', "Semua barang {$grn->Nomor} sudah diretur; tidak ada yang bisa dibebani biaya.", 'UuidPenerimaan');
            }

            $alokasi = $this->pengalokasi->Alokasikan($data->jumlah, array_map(fn (PenerimaanBarangDetail $d): Uang => self::Bobot($d, $data->dasarAlokasi), $baris));
            $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (PenerimaanBarangDetail $d): int => $d->IdProduk, $baris))), denganTerhapus: true);
            [$porsi, $perProduk] = $this->HitungPorsi($baris, $alokasi, $produk, $grn->IdGudang);

            $dokumen = BiayaTambahanPembelian::query()->create([
                'Nomor' => $this->penomor->AmbilNomorTenant(JenisDokumenBernomor::BiayaTambahanPembelian, $data->tanggal),
                'IdPenerimaanBarang' => $grn->Id,
                'IdOutlet' => $grn->IdOutlet,
                'IdPemasok' => $idPemasok,
                'Jenis' => $data->jenis,
                'DasarAlokasi' => $data->dasarAlokasi,
                'Tanggal' => $data->tanggal->toDateString(),
                'Jumlah' => $data->jumlah->KeString(),
                'IdAkunKasBank' => $akun['Id'],
                'Status' => StatusDokumenTerposting::Diposting,
                'Catatan' => self::Bersihkan($data->catatan),
                'DibuatOleh' => $data->idPengguna,
            ]);

            $kePersediaan = Uang::Nol();
            $keHpp = Uang::Nol();

            foreach ($baris as $i => $d) {
                [$ke1, $ke2] = $porsi[$i];
                $kePersediaan = $kePersediaan->Tambah($ke1);
                $keHpp = $keHpp->Tambah($ke2);
                BiayaTambahanPembelianDetail::query()->create([
                    'IdBiayaTambahanPembelian' => $dokumen->Id,
                    'IdPenerimaanBarangDetail' => $d->Id,
                    'IdProduk' => $d->IdProduk,
                    'Alokasi' => $alokasi[$i]->KeString(),
                    'KePersediaan' => $ke1->KeString(),
                    'KeHpp' => $ke2->KeString(),
                ]);
            }

            $this->NilaiUlang($dokumen, $grn->IdGudang, $perProduk, $data);

            $peranPersediaan = [];

            foreach ($perProduk as $idProduk => $p) {
                $peran = $this->petaAkun->UntukJenis($produk[$idProduk]->jenis)->value;
                $peranPersediaan[$peran] = ($peranPersediaan[$peran] ?? Uang::Nol())->Tambah($p['KePersediaan']);
            }

            $barisJurnal = [];

            foreach ($peranPersediaan as $peran => $nilai) {
                if (! $nilai->BernilaiNol()) {
                    $barisJurnal[] = DataBarisJurnal::Debit(PeranAkun::from($peran), $nilai, $grn->IdOutlet, $grn->Nomor);
                }
            }

            if (! $keHpp->BernilaiNol()) {
                $barisJurnal[] = DataBarisJurnal::Debit(PeranAkun::Hpp, $keHpp, $grn->IdOutlet, "{$grn->Nomor} (barang sudah terjual)");
            }

            $barisJurnal[] = PenyusunJurnalPembelian::BarisAkun($akun['Id'], Uang::Nol()->Kurangi($data->jumlah), $grn->IdOutlet, $dokumen->Nomor);
            $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
                jenisSumber: JenisSumberJurnal::BiayaTambahanPembelian,
                idSumber: $dokumen->Id,
                uuidSumber: $dokumen->Uuid,
                nomorSumber: $dokumen->Nomor,
                tanggal: $data->tanggal,
                keterangan: mb_substr("{$data->jenis->AmbilLabel()} {$grn->Nomor} ({$dokumen->Nomor})", 0, 255),
                baris: array_values(array_filter($barisJurnal, fn (?DataBarisJurnal $b): bool => $b !== null)),
                idPengguna: $data->idPengguna,
            ));

            $dokumen->forceFill(['KePersediaan' => $kePersediaan->KeString(), 'KeHpp' => $keHpp->KeString(), 'IdJurnal' => $jurnal->idJurnal])->save();
            $this->riwayat->Catat(BiayaTambahanPembelian::JENIS_DOKUMEN, $dokumen->Id, null, StatusDokumenTerposting::Diposting->value, $data->idPengguna);
            $this->audit->Catat('biaya-tambahan.posting', $dokumen, nilaiBaru: [
                'Nomor' => $dokumen->Nomor,
                'Penerimaan' => $grn->Nomor,
                'Jenis' => $data->jenis->value,
                'Jumlah' => $dokumen->Jumlah,
                'KePersediaan' => $dokumen->KePersediaan,
                'KeHpp' => $dokumen->KeHpp,
                'NomorJurnal' => $jurnal->nomor,
            ], idPengguna: $data->idPengguna);

            return $dokumen;
        }, 3);
    }

    private static function Bobot(PenerimaanBarangDetail $d, DasarAlokasiBiaya $dasar): Uang
    {
        if ($dasar === DasarAlokasiBiaya::Nilai) {
            $nilai = Uang::Dari($d->Nilai)->Kurangi(Uang::Dari($d->NilaiDiretur));

            return $nilai->BernilaiNegatif() ? Uang::Nol() : $nilai;
        }

        // Jumlah 4 desimal dikali 100 supaya muat skala uang tanpa mengubah perbandingan.
        $sisa = Kuantitas::Dari($d->JumlahDasar)->Kurangi(Kuantitas::Dari($d->JumlahDiretur))->KeDesimal();

        return Uang::Dari($sisa->multipliedBy(100)->toScale(2, RoundingMode::HalfUp));
    }

    /**
     * Porsi persediaan/HPP per baris dan ringkasan per produk.
     *
     * @param  list<PenerimaanBarangDetail>  $baris
     * @param  list<Uang>  $alokasi
     * @param  array<int, DataInfoProdukStok>  $produk
     * @return array{0: array<int, array{0: Uang, 1: Uang}>, 1: array<int, array{KePersediaan: Uang, Saldo: Kuantitas}>}
     */
    private function HitungPorsi(array $baris, array $alokasi, array $produk, int $idGudang): array
    {
        $diterima = [];

        foreach ($baris as $d) {
            $diterima[$d->IdProduk] = ($diterima[$d->IdProduk] ?? Kuantitas::Nol())->Tambah(Kuantitas::Dari($d->JumlahDasar)->Kurangi(Kuantitas::Dari($d->JumlahDiretur)));
        }

        $saldo = $this->saldo->Ambil(array_map(fn (int $id): array => [$id, $idGudang], array_keys($diterima)));
        $porsi = [];
        $perProduk = [];

        foreach ($baris as $i => $d) {
            $p = $produk[$d->IdProduk] ?? null;
            $ada = $saldo[SaldoStok::BuatKunciPasangan($d->IdProduk, $idGudang)] ?? Kuantitas::Nol();
            $bisaDinilai = $p !== null && $p->pelacakan === PelacakanProduk::Tidak && $p->jenis !== JenisProduk::Konsinyasi && $ada->KeDesimal()->isPositive();
            $ke1 = Uang::Nol();

            if ($bisaDinilai) {
                $bagian = BigRational::of((string) $ada)->dividedBy((string) $diterima[$d->IdProduk]);
                $bagian = $bagian->compareTo(1) > 0 ? BigRational::one() : $bagian;
                $ke1 = Uang::Dari(BigRational::of($alokasi[$i]->KeString())->multipliedBy($bagian)->toScale(Uang::SKALA, RoundingMode::HalfUp));
                $perProduk[$d->IdProduk] = [
                    'KePersediaan' => ($perProduk[$d->IdProduk]['KePersediaan'] ?? Uang::Nol())->Tambah($ke1),
                    'Saldo' => $ada,
                ];
            }

            $porsi[$i] = [$ke1, $alokasi[$i]->Kurangi($ke1)];
        }

        return [$porsi, array_filter($perProduk, fn (array $x): bool => ! $x['KePersediaan']->BernilaiNol())];
    }

    /**
     * Penilaian ulang per produk: seluruh saldo keluar pada nilai berjalan (panggilan pertama), lalu masuk lagi pada
     * nilai keluar + biaya (panggilan kedua, supaya keluar selalu dinilai sebelum masuk).
     *
     * @param  array<int, array{KePersediaan: Uang, Saldo: Kuantitas}>  $perProduk
     */
    private function NilaiUlang(BiayaTambahanPembelian $dokumen, int $idGudang, array $perProduk, DataBiayaTambahanPembelian $data): void
    {
        if ($perProduk === []) {
            return;
        }

        $keluar = $this->catatMutasi->Jalankan(self::DokumenMutasi($dokumen, $data, array_values(array_map(fn (int $id, array $p): DataBarisMutasi => new DataBarisMutasi(
            kunciBaris: 'K/'.$id,
            idProduk: $id,
            idGudang: $idGudang,
            jenisMutasi: JenisMutasi::RevaluasiKeluar,
            jumlah: $p['Saldo']->Negasi(),
            modeNilai: ModeNilaiMutasi::Berjalan,
        ), array_keys($perProduk), $perProduk))));

        $this->catatMutasi->Jalankan(self::DokumenMutasi($dokumen, $data, array_values(array_map(function (int $id, array $p) use ($keluar, $idGudang): DataBarisMutasi {
            $nilai = AritmetikaHpp::AmbilMutlak($keluar->baris['K/'.$id]->totalHpp)->Tambah($p['KePersediaan']);

            return new DataBarisMutasi(
                kunciBaris: 'M/'.$id,
                idProduk: $id,
                idGudang: $idGudang,
                jenisMutasi: JenisMutasi::RevaluasiMasuk,
                jumlah: $p['Saldo'],
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: $nilai,
                hppSatuan: BigDecimal::of($nilai->KeString())->dividedBy($p['Saldo']->KeDesimal(), 6, RoundingMode::HalfUp),
            );
        }, array_keys($perProduk), $perProduk))));
    }

    /**
     * @param  list<DataBarisMutasi>  $baris
     */
    private static function DokumenMutasi(BiayaTambahanPembelian $dokumen, DataBiayaTambahanPembelian $data, array $baris): DataDokumenMutasi
    {
        return new DataDokumenMutasi(
            JenisReferensiMutasi::BiayaTambahanPembelian,
            $dokumen->Id,
            $dokumen->Uuid,
            $dokumen->Nomor,
            $data->tanggal,
            $data->idPengguna,
            null,
            $baris,
            abaikanBatasMinus: false,
        );
    }

    private static function Bersihkan(?string $teks): ?string
    {
        $teks = $teks === null ? '' : trim($teks);

        return $teks === '' ? null : mb_substr($teks, 0, 500);
    }
}
