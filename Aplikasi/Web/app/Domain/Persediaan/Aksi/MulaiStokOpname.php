<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\ProdukKategoriStok;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PenomorDokumenPersediaan;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\ProdukRak;
use App\Domain\Persediaan\Model\RakGudang;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Memulai stok opname (F-05b, BR-05.3): lokasi stok aktif (bukan dalam perjalanan), seluruh produk, satu kategori
 * (beserta sub-kategori), atau satu rak (F-05c INV-07, `RakGudang`; kategori dan rak tidak digabung). Cakupan dua opname
 * aktif di lokasi yang sama tidak boleh beririsan: opname seluruh produk bentrok dengan semua opname lain, selain itu
 * bentrok bila ada produk yang sama (`OpnameAktifSudahAda`). Rak berbeda tidak pernah beririsan (satu produk satu rak),
 * jadi beberapa penghitung dapat menghitung rak berbeda berbarengan. Snapshot = saldo sistem saat mulai (dibaca dengan kunci baca, sehingga
 * `IdMutasiSnapshot` = mutasi terakhir yang sudah masuk saldo): satu baris per produk bersaldo ≠ 0, per batch bersisa
 * untuk produk batch, per nomor seri tersedia untuk produk seri. Transaksi tetap berjalan selama opname.
 * Idempoten per Uuid klien. Audit `stok-opname.mulai`.
 */
final class MulaiStokOpname
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly ProdukKategoriStok $kategori,
        private readonly InfoProdukStok $infoProduk,
        private readonly PenomorDokumenPersediaan $penomor,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(int $idGudang, ?string $uuidKategori, bool $hitungButa, ?string $catatan, int $idPengguna, ?string $uuid = null, ?string $uuidRak = null): StokOpname
    {
        if ($uuid !== null) {
            $ada = StokOpname::query()->where('Uuid', $uuid)->first();

            if ($ada !== null) {
                return $ada;
            }
        }

        try {
            return DB::transaction(fn (): StokOpname => $this->Mulai($idGudang, $uuidKategori, $hitungButa, $catatan, $idPengguna, $uuid, $uuidRak), max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
        } catch (UniqueConstraintViolationException $galat) {
            if (str_contains($galat->getMessage(), 'UniqStokOpnameIdTenantKunciAktif')) {
                throw new PelanggaranAturanBisnis('OpnameAktifSudahAda', 'Masih ada stok opname yang berjalan untuk lokasi dan kategori ini. Selesaikan atau batalkan dulu.', 'UuidGudang');
            }

            throw $galat;
        }
    }

    private function Mulai(int $idGudang, ?string $uuidKategori, bool $hitungButa, ?string $catatan, int $idPengguna, ?string $uuid, ?string $uuidRak): StokOpname
    {
        $gudang = $this->pemeriksaLokasi->AmbilLokasi($idGudang);
        $kategori = null;
        $rak = null;

        if ($uuidKategori !== null && $uuidKategori !== '') {
            $kategori = $this->kategori->AmbilKategori($uuidKategori) ?? throw new PelanggaranAturanBisnis('KategoriTidakDikenal', 'Kategori tidak ditemukan.', 'UuidKategori');
        }

        if ($uuidRak !== null && $uuidRak !== '') {
            if ($kategori !== null) {
                throw new PelanggaranAturanBisnis('CakupanGanda', 'Pilih kategori atau rak, tidak keduanya. Opname per rak sudah membatasi produknya.', 'UuidRak');
            }

            $rak = RakGudang::query()->where('Uuid', $uuidRak)->where('IdGudang', $gudang->id)->first()
                ?? throw new PelanggaranAturanBisnis('RakTidakDikenal', "Rak tidak ditemukan di {$gudang->nama}.", 'UuidRak');

            if (! $rak->Aktif) {
                throw new PelanggaranAturanBisnis('RakNonaktif', "Rak {$rak->Nama} nonaktif. Aktifkan dulu untuk memulai opname.", 'UuidRak');
            }
        }

        $idProdukCakupan = match (true) {
            $kategori !== null => $this->kategori->AmbilIdProduk($kategori['Id']),
            $rak !== null => array_map('intval', ProdukRak::query()->where('IdRak', $rak->Id)->pluck('IdProduk')->all()),
            default => null,
        };

        if ($rak !== null && $idProdukCakupan === []) {
            throw new PelanggaranAturanBisnis('RakKosong', "Rak {$rak->Nama} belum berisi produk. Tempatkan produk di rak dulu.", 'UuidRak');
        }

        // Kunci rentang opname lokasi ini (next-key lock indeks IdTenant, IdGudang, Status) supaya dua mulai bersamaan berurutan.
        $aktif = StokOpname::query()
            ->where('IdGudang', $gudang->id)
            ->whereIn('Status', [StatusStokOpname::Berlangsung->value, StatusStokOpname::Ditinjau->value])
            ->lockForUpdate()
            ->get();

        foreach ($aktif as $lain) {
            if ($this->CekBeririsan($idProdukCakupan, $this->AmbilCakupan($lain))) {
                $cakupanLain = $lain->NamaRak !== null ? " (rak {$lain->NamaRak})" : ($lain->NamaKategori !== null ? " (kategori {$lain->NamaKategori})" : '');

                throw new PelanggaranAturanBisnis(
                    'OpnameAktifSudahAda',
                    "Stok opname {$lain->Nomor} masih berjalan di {$gudang->nama}{$cakupanLain}. Selesaikan atau batalkan dulu.",
                    'UuidGudang',
                );
            }
        }

        $tanggal = $this->pemeriksaLokasi->HariIni($gudang->idOutlet);
        $saldo = SaldoStok::query()
            ->where('IdGudang', $gudang->id)
            ->when($idProdukCakupan !== null, fn ($k) => $k->whereIn('IdProduk', $idProdukCakupan === [] ? [0] : $idProdukCakupan))
            ->orderBy('IdProduk')
            ->sharedLock()
            ->get()
            ->keyBy('IdProduk');
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_map('intval', $saldo->keys()->all())), true);
        $batch = BatchStok::query()->where('IdGudang', $gudang->id)->whereIn('IdProduk', $saldo->keys()->all())->where('JumlahSisa', '<>', 0)
            ->orderBy('IdProduk')->orderBy('NomorBatch')->sharedLock()->get()->groupBy('IdProduk');
        $seri = NomorSeri::query()->where('IdGudang', $gudang->id)->where('Status', StatusNomorSeri::Tersedia->value)->whereIn('IdProduk', $saldo->keys()->all())
            ->orderBy('IdProduk')->orderBy('Nomor')->sharedLock()->get()->groupBy('IdProduk');

        $penandaTenant = (int) MutasiStok::query()->max('Id');
        $nomor = $this->penomor->AmbilOpname($tanggal, $gudang->kode);
        $opname = new StokOpname;

        if ($uuid !== null) {
            $opname->Uuid = $uuid;
        }

        $catatan = $catatan === null ? null : trim($catatan);
        $opname->fill([
            'Nomor' => $nomor,
            'IdGudang' => $gudang->id,
            'IdOutlet' => $gudang->idOutlet,
            'IdKategori' => $kategori['Id'] ?? null,
            'NamaKategori' => $kategori === null ? null : mb_substr($kategori['Nama'], 0, 150),
            'IdRak' => $rak?->Id,
            'NamaRak' => $rak === null ? null : mb_substr($rak->Nama, 0, 130),
            'HitungButa' => $hitungButa,
            'Status' => StatusStokOpname::Berlangsung,
            'TanggalSnapshot' => $tanggal->format('Y-m-d'),
            'SnapshotPada' => now(),
            'IdMutasiSnapshot' => $penandaTenant,
            'Catatan' => $catatan === '' ? null : $catatan,
            'DibuatOleh' => $idPengguna,
            'DiubahOleh' => $idPengguna,
        ]);
        $opname->save();

        $idTenant = $this->konteks->Wajib();
        $waktu = now();
        $isi = [];
        $urutan = 0;
        $barisDasar = fn (int $idProduk, string $jumlah, int $penanda): array => [
            'IdTenant' => $idTenant,
            'IdStokOpname' => $opname->Id,
            'IdProduk' => $idProduk,
            'NamaProduk' => mb_substr($produk[$idProduk]->nama ?? '', 0, 150),
            'Sku' => $produk[$idProduk]->sku ?? null,
            'IdBatchStok' => null,
            'NomorBatch' => null,
            'TanggalKedaluwarsa' => null,
            'IdNomorSeri' => null,
            'NomorSeri' => null,
            'DariSnapshot' => true,
            'JumlahSistem' => $jumlah,
            'IdMutasiSnapshot' => $penanda,
            'DibuatPada' => $waktu,
            'DiubahPada' => $waktu,
        ];

        $urutProduk = $saldo->all();
        uasort($urutProduk, fn (SaldoStok $a, SaldoStok $b): int => [$produk[$a->IdProduk]->nama ?? '', $a->IdProduk] <=> [$produk[$b->IdProduk]->nama ?? '', $b->IdProduk]);

        foreach ($urutProduk as $s) {
            $info = $produk[$s->IdProduk] ?? null;
            $penanda = (int) ($s->IdMutasiStokTerakhir ?? 0);

            if ($info === null || ! $info->jenis->CekPunyaStok()) {
                continue;
            }

            if ($info->pelacakan === PelacakanProduk::Batch) {
                foreach ($batch->get($s->IdProduk, collect()) as $b) {
                    $isi[] = ['Urutan' => ++$urutan, ...$barisDasar($s->IdProduk, (string) $b->JumlahSisa, $penanda), 'IdBatchStok' => $b->Id, 'NomorBatch' => $b->NomorBatch, 'TanggalKedaluwarsa' => $b->TanggalKedaluwarsa?->format('Y-m-d')];
                }

                continue;
            }

            if ($info->pelacakan === PelacakanProduk::Seri) {
                foreach ($seri->get($s->IdProduk, collect()) as $n) {
                    $isi[] = ['Urutan' => ++$urutan, ...$barisDasar($s->IdProduk, '1.0000', $penanda), 'IdNomorSeri' => $n->Id, 'NomorSeri' => $n->Nomor];
                }

                continue;
            }

            if (! BigDecimal::of($s->JumlahTersedia)->isZero()) {
                $isi[] = ['Urutan' => ++$urutan, ...$barisDasar($s->IdProduk, (string) $s->JumlahTersedia, $penanda)];
            }
        }

        foreach (array_chunk($isi, 500) as $potongan) {
            StokOpnameDetail::query()->insert($potongan);
        }

        $opname->JumlahBaris = count($isi);
        $opname->save();

        $this->riwayat->Catat(StokOpname::JENIS_DOKUMEN, $opname->Id, null, StatusStokOpname::Berlangsung->value, $idPengguna);
        $this->audit->Catat('stok-opname.mulai', $opname, null, [
            'Nomor' => $nomor,
            'IdGudang' => $gudang->id,
            'NamaGudang' => $gudang->nama,
            'Kategori' => $opname->NamaKategori,
            'Rak' => $opname->NamaRak,
            'HitungButa' => $hitungButa,
            'JumlahBaris' => $opname->JumlahBaris,
        ], idPengguna: $idPengguna);

        return $opname;
    }

    /**
     * Cakupan produk opname aktif: null = seluruh produk lokasi, selain itu daftar Id produk (kategori atau rak).
     *
     * @return array<int>|null
     */
    private function AmbilCakupan(StokOpname $o): ?array
    {
        if ($o->IdRak !== null) {
            return array_map('intval', ProdukRak::query()->where('IdRak', $o->IdRak)->pluck('IdProduk')->all());
        }

        if ($o->IdKategori !== null) {
            return $this->kategori->AmbilIdProduk($o->IdKategori);
        }

        return null;
    }

    /**
     * @param  array<int>|null  $a
     * @param  array<int>|null  $b
     */
    private function CekBeririsan(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }

        return array_intersect($a, $b) !== [];
    }
}
