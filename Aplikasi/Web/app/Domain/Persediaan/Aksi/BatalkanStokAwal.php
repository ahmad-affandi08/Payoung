<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Data\HasilPostingJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PenyusunJurnalStokAwal;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\LapisanFifo;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan stok awal Diposting (F-05a, DesainF05a C.6.5, CLAUDE.md #8): dokumen tidak diedit, melainkan dibalik
 * penuh lewat mutasi pembalik (`B/{KunciBaris asli}`, jenis StokAwal bertanda negatif, `idMutasiAsal` terisi,
 * nilai = NilaiDiminta asli) dan jurnal pembalik (`KunciSumber = Pembatalan`, `IdJurnalDibalik` = jurnal posting)
 * bertanggal hari ini di outlet lokasi. Idempoten: dokumen yang sudah Dibatalkan dikembalikan apa adanya.
 *
 * Ditolak `StokSudahTerpakai` bila stok awal sudah berkurang: saldo pasangan < jumlah stok awal (rata-rata
 * bergerak), sisa batch < jumlah batch, nomor seri tidak lagi Tersedia di lokasi itu, atau lapisan FIFO-nya sudah
 * terpakai. Koreksinya lewat penyesuaian stok.
 *
 * Urutan kunci: L1 Tenant (S) → L2 dokumen → L3 SaldoStok → L4 BatchStok → L5 NomorSeri → L6 LapisanFifo → buku
 * stok (reentran) → nomor jurnal (L7). Alasan 5–255 karakter. Audit `stok-awal.batalkan`.
 */
final class BatalkanStokAwal
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturan,
        private readonly MutasiDokumen $mutasiDokumen,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalStokAwal $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(StokAwal $stokAwal, string $alasan, int $idPengguna): StokAwal
    {
        $alasan = trim($alasan);
        $panjang = mb_strlen($alasan);

        if ($panjang < 5 || $panjang > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(
            fn (): StokAwal => $this->Batalkan($stokAwal->Id, $alasan, $idPengguna),
            max(1, (int) config('persediaan.PercobaanTransaksi', 3)),
        );
    }

    private function Batalkan(int $idStokAwal, string $alasan, int $idPengguna): StokAwal
    {
        $pengaturan = $this->pengaturan->AmbilDenganKunciBaca();
        $dokumen = StokAwal::query()->whereKey($idStokAwal)->lockForUpdate()->firstOrFail();

        if ($dokumen->Status === StatusStokAwal::Dibatalkan) {
            return $dokumen;
        }

        if ($dokumen->Status !== StatusStokAwal::Diposting) {
            throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Stok awal berstatus {$dokumen->Status->AmbilLabel()} tidak bisa dibatalkan. Hanya stok awal Tercatat yang bisa dibatalkan.");
        }

        $asal = $this->mutasiDokumen->Ambil(JenisReferensiMutasi::StokAwal, $dokumen->Id, 'P/');
        $gudang = $this->infoGudang->AmbilBanyak([$dokumen->IdGudang])[$dokumen->IdGudang];
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (MutasiStok $m): int => $m->IdProduk, $asal))), denganTerhapus: true);

        $this->PastikanStokBelumTerpakai($asal, $produk, $gudang->nama, $pengaturan->metodeHpp);

        $tanggal = $this->tanggalBisnis->Hitung($dokumen->IdOutlet);
        $hasilMutasi = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::StokAwal,
            $dokumen->Id,
            $dokumen->Uuid,
            $dokumen->Nomor,
            $tanggal,
            $idPengguna,
            null,
            array_map(fn (MutasiStok $m): DataBarisMutasi => new DataBarisMutasi(
                kunciBaris: 'B/'.$m->KunciBaris,
                idProduk: $m->IdProduk,
                idGudang: $m->IdGudang,
                jenisMutasi: $m->JenisMutasi,
                jumlah: Kuantitas::Dari($m->Jumlah)->Negasi(),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: Uang::Dari($m->TotalHpp)->Kurangi(Uang::Dari($m->SelisihHpp)),
                hppSatuan: BigDecimal::of($m->HppSatuan),
                idReferensiDetail: $m->IdReferensiDetail,
                idBatchStok: $m->IdBatchStok,
                idNomorSeri: $m->IdNomorSeri,
                idMutasiAsal: $m->Id,
            ), $asal),
        ));

        $jurnal = $this->PostingJurnalPembatalan($dokumen, $tanggal, $this->penyusunJurnal->Susun($hasilMutasi, $produk, $gudang), $idPengguna);

        $dokumen->UbahStatus(StatusStokAwal::Dibatalkan);
        $dokumen->fill([
            'IdJurnalPembatalan' => $jurnal?->idJurnal,
            'AlasanBatal' => $alasan,
            'DibatalkanOleh' => $idPengguna,
            'DibatalkanPada' => now(),
            'DiubahOleh' => $idPengguna,
        ]);
        $dokumen->save();

        $this->riwayat->Catat(StokAwal::JENIS_DOKUMEN, $dokumen->Id, StatusStokAwal::Diposting->value, StatusStokAwal::Dibatalkan->value, $idPengguna, $alasan);
        $this->audit->Catat('stok-awal.batalkan', $dokumen, ['Status' => StatusStokAwal::Diposting->value], [
            'Status' => StatusStokAwal::Dibatalkan->value,
            'Nomor' => $dokumen->Nomor,
            'Alasan' => $alasan,
            'IdJurnalPembatalan' => $jurnal?->idJurnal,
            'NomorJurnalPembatalan' => $jurnal?->nomor,
            'TanggalBisnis' => $tanggal->format('Y-m-d'),
        ], idPengguna: $idPengguna);

        return $dokumen;
    }

    /**
     * @param  list<DataBarisJurnal>  $baris
     */
    private function PostingJurnalPembatalan(StokAwal $dokumen, CarbonImmutable $tanggal, array $baris, int $idPengguna): ?HasilPostingJurnal
    {
        if ($baris === []) {
            return null;
        }

        return $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::StokAwal,
            idSumber: $dokumen->Id,
            uuidSumber: $dokumen->Uuid,
            nomorSumber: $dokumen->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Pembatalan stok awal {$dokumen->Nomor}", 0, 255),
            baris: $baris,
            idPengguna: $idPengguna,
            kunciSumber: 'Pembatalan',
            idJurnalDibalik: $dokumen->IdJurnal,
        ));
    }

    /**
     * @param  list<MutasiStok>  $asal
     * @param  array<int, DataInfoProdukStok>  $produk
     */
    private function PastikanStokBelumTerpakai(array $asal, array $produk, string $namaGudang, MetodeHpp $metode): void
    {
        $perPasangan = [];

        foreach ($asal as $m) {
            $kunci = SaldoStok::BuatKunciPasangan($m->IdProduk, $m->IdGudang);
            $perPasangan[$kunci] = [
                $m->IdProduk,
                $m->IdGudang,
                ($perPasangan[$kunci][2] ?? Kuantitas::Nol())->Tambah(Kuantitas::Dari($m->Jumlah)),
            ];
        }

        uasort($perPasangan, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $saldo = $this->pengunciSaldo->Kunci(array_values(array_map(fn (array $p): array => [$p[0], $p[1]], $perPasangan)));

        foreach ($perPasangan as $kunci => [$idProduk, , $jumlah]) {
            $tersedia = Kuantitas::Dari($saldo[$kunci]->JumlahTersedia ?? '0');

            if ($tersedia->Bandingkan($jumlah) < 0) {
                throw self::GalatTerpakai($produk[$idProduk]->nama ?? 'Produk', $namaGudang, $tersedia, $jumlah);
            }
        }

        $this->PeriksaBatch($asal, $produk, $namaGudang);
        $this->PeriksaSeri($asal, $produk, $namaGudang);

        if ($metode === MetodeHpp::Fifo) {
            $this->PeriksaLapisanFifo($asal, $produk, $namaGudang);
        }
    }

    /**
     * @param  list<MutasiStok>  $asal
     * @param  array<int, DataInfoProdukStok>  $produk
     */
    private function PeriksaBatch(array $asal, array $produk, string $namaGudang): void
    {
        $perBatch = [];

        foreach ($asal as $m) {
            if ($m->IdBatchStok !== null) {
                $perBatch[$m->IdBatchStok] = ($perBatch[$m->IdBatchStok] ?? Kuantitas::Nol())->Tambah(Kuantitas::Dari($m->Jumlah));
            }
        }

        if ($perBatch === []) {
            return;
        }

        $batch = BatchStok::query()->whereKey(array_keys($perBatch))
            ->orderBy('IdProduk')->orderBy('IdGudang')->orderBy('NomorBatch')
            ->lockForUpdate()->get();

        foreach ($batch as $b) {
            $sisa = Kuantitas::Dari($b->JumlahSisa);

            if ($sisa->Bandingkan($perBatch[$b->Id]) < 0) {
                throw self::GalatTerpakai(($produk[$b->IdProduk]->nama ?? 'Produk')." batch {$b->NomorBatch}", $namaGudang, $sisa, $perBatch[$b->Id]);
            }
        }
    }

    /**
     * @param  list<MutasiStok>  $asal
     * @param  array<int, DataInfoProdukStok>  $produk
     */
    private function PeriksaSeri(array $asal, array $produk, string $namaGudang): void
    {
        $idSeri = array_values(array_filter(array_map(fn (MutasiStok $m): ?int => $m->IdNomorSeri, $asal), 'is_int'));

        if ($idSeri === []) {
            return;
        }

        $gudangAsal = [];

        foreach ($asal as $m) {
            if ($m->IdNomorSeri !== null) {
                $gudangAsal[$m->IdNomorSeri] = $m->IdGudang;
            }
        }

        $seri = NomorSeri::query()->whereKey($idSeri)->orderBy('IdProduk')->orderBy('Nomor')->lockForUpdate()->get();

        foreach ($seri as $s) {
            if ($s->Status !== StatusNomorSeri::Tersedia || $s->IdGudang !== $gudangAsal[$s->Id]) {
                throw new PelanggaranAturanBisnis(
                    'StokSudahTerpakai',
                    'Nomor seri '.$s->Nomor.' '.($produk[$s->IdProduk]->nama ?? '')." sudah tidak ada di {$namaGudang}. Stok awal tidak bisa dibatalkan. Koreksi lewat penyesuaian stok.",
                    detail: ['Nomor' => $s->Nomor],
                );
            }
        }
    }

    /**
     * @param  list<MutasiStok>  $asal
     * @param  array<int, DataInfoProdukStok>  $produk
     */
    private function PeriksaLapisanFifo(array $asal, array $produk, string $namaGudang): void
    {
        $lapisan = LapisanFifo::query()
            ->whereIn('IdMutasiSumber', array_map(fn (MutasiStok $m): int => $m->Id, $asal))
            ->orderBy('IdProduk')->orderBy('IdGudang')->orderBy('Id')
            ->lockForUpdate()->get();

        foreach ($lapisan as $l) {
            $sisa = Kuantitas::Dari($l->JumlahSisa);
            $awal = Kuantitas::Dari($l->JumlahAwal);

            if (! $sisa->SamaDengan($awal)) {
                throw self::GalatTerpakai($produk[$l->IdProduk]->nama ?? 'Produk', $namaGudang, $sisa, $awal);
            }
        }
    }

    private static function GalatTerpakai(string $nama, string $namaGudang, Kuantitas $tersedia, Kuantitas $jumlah): PelanggaranAturanBisnis
    {
        $format = fn (Kuantitas $k): string => str_replace('.', ',', (string) $k->KeDesimal()->strippedOfTrailingZeros());

        return new PelanggaranAturanBisnis(
            'StokSudahTerpakai',
            "Stok {$nama} di {$namaGudang} sudah berkurang (tersedia {$format($tersedia)}, stok awal {$format($jumlah)}). Koreksi lewat penyesuaian stok.",
            detail: ['Tersedia' => $tersedia->KeString(), 'StokAwal' => $jumlah->KeString()],
        );
    }
}
