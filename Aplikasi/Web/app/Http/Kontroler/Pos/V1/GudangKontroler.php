<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pos\V1;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Pembelian\Aksi\TerimaBarang;
use App\Domain\Pembelian\Data\DataBarisPenerimaanBarang;
use App\Domain\Pembelian\Data\DataPenerimaanBarang;
use App\Domain\Pembelian\Kueri\PesananPembelianPos;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Persediaan\Aksi\SimpanHitungStokOpname;
use App\Domain\Persediaan\Aksi\TerimaTransferStok;
use App\Domain\Persediaan\Data\DataHitungOpname;
use App\Domain\Persediaan\Data\DataTerimaTransfer;
use App\Domain\Persediaan\Kueri\DokumenGudangPos;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\TransferStok;
use App\Http\Kontroler\Kontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modul Gudang di aplikasi (POS-25, PRD §13.5 "mode Gudang": online-first, posting saat online) untuk lokasi stok
 * outlet perangkat. Pelaku = staf yang masuk dengan PIN di perangkat (`UuidPengguna`), diperiksa keanggotaan outlet &
 * izinnya di server:
 *
 * - `GET gudang/pesanan-pembelian?kata=` PO siap diterima; `POST gudang/penerimaan` GRN dari PO yang sudah disetujui
 *   (izin `pembelian.kelola` atau `persediaan.kelola`: staf gudang hanya mengisi jumlah, harga & pemasok dari PO; F-04
 *   langkah 5: stok bertambah saat diposting, batch/kedaluwarsa & nomor seri per baris).
 * - `GET gudang/transfer` transfer masuk; `POST gudang/transfer/{uuid}/terima` terima (boleh sebagian; per barang `Jumlah`
 *   diterima, `Rusak` susut, `Ditolak` kembali ke asal, D-105) (izin `persediaan.kelola`, F-05b).
 * - `GET gudang/opname` opname berlangsung; `POST gudang/opname/{uuid}/hitung` simpan lembar hitung (izin
 *   `persediaan.kelola`); tinjau & setujui tetap di back-office.
 *
 * Tanggal GRN = satu hari sebelum tanggal PO; dokumen gudang lain memakai tanggal bisnis outlet saat ini. Kirim ulang
 * aman lewat `Idempotency-Key` yang sama (perangkat memakai kunci tetap per draf). Dokumen di luar lokasi outlet
 * perangkat = 404.
 */
final class GudangKontroler extends Kontroler
{
    private const POLA_JUMLAH = 'regex:/^\d{1,14}(\.\d{1,4})?$/';

    public function __construct(
        private readonly InfoGudang $infoGudang,
        private readonly AnggotaOutlet $anggota,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
    ) {}

    public function DaftarPesanan(Request $permintaan, PesananPembelianPos $kueri): JsonResponse
    {
        $valid = $permintaan->validate(['kata' => ['nullable', 'string', 'max:60']]);

        return response()->json([
            'Pesanan' => $kueri->DaftarTerbuka($this->AmbilIdGudang($permintaan), (string) ($valid['kata'] ?? '')),
        ]);
    }

    public function Terima(Request $permintaan, PesananPembelianPos $kueri, TerimaBarang $terima): JsonResponse
    {
        $valid = $permintaan->validate([
            'UuidPesananPembelian' => ['required', 'string', 'ulid'],
            'UuidPengguna' => ['required', 'string', 'ulid'],
            'NomorSuratJalan' => ['nullable', 'string', 'max:60'],
            'Catatan' => ['nullable', 'string', 'max:500'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.PemrosesPenerimaanBarang::MAKS_BARIS],
            'Baris.*.Urutan' => ['required', 'integer', 'min:1', 'distinct'],
            'Baris.*.Jumlah' => ['required', 'string', self::POLA_JUMLAH],
            'Baris.*.NomorBatch' => ['nullable', 'string', 'max:60'],
            'Baris.*.TanggalKedaluwarsa' => ['nullable', 'date_format:Y-m-d'],
            'Baris.*.NomorSeri' => ['nullable', 'array', 'max:'.PemrosesPenerimaanBarang::MAKS_NOMOR_SERI_PER_BARIS],
            'Baris.*.NomorSeri.*' => ['string', 'max:100'],
        ]);
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $pelaku = $this->AmbilPelaku($perangkat, (string) $valid['UuidPengguna'], [IzinTenant::PembelianKelola, IzinTenant::PersediaanKelola], 'menerima barang');
        $uuidPo = strtoupper((string) $valid['UuidPesananPembelian']);
        $isianPo = $kueri->AmbilUntukPenerimaan($uuidPo, $this->AmbilIdGudang($permintaan));
        abort_if($isianPo === null, 404);
        $idBaris = $isianPo['IdBaris'];

        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $valid['Baris']);
        $data = array_map(function (array $b) use ($idBaris): DataBarisPenerimaanBarang {
            $urutan = (int) $b['Urutan'];

            if (! isset($idBaris[$urutan])) {
                throw new PelanggaranAturanBisnis('BarisTidakDikenal', "Baris {$urutan} tidak ada di pesanan ini. Muat ulang pesanan.", 'Baris');
            }

            $kedaluwarsa = is_string($b['TanggalKedaluwarsa'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', $b['TanggalKedaluwarsa']) : null;

            return new DataBarisPenerimaanBarang(
                $idBaris[$urutan],
                null,
                null,
                Kuantitas::Dari((string) $b['Jumlah']),
                null,
                null,
                self::Teks($b['NomorBatch'] ?? null),
                $kedaluwarsa,
                array_values(array_map('strval', (array) ($b['NomorSeri'] ?? []))),
            );
        }, $baris);

        $grn = $terima->Jalankan(new DataPenerimaanBarang(
            $uuidPo,
            null,
            null,
            $isianPo['TanggalPenerimaan'],
            self::Teks($valid['NomorSuratJalan'] ?? null),
            Uang::Nol(),
            self::Teks($valid['Catatan'] ?? null),
            $data,
            null,
            $pelaku->id,
        ));

        $sisa = array_values(array_filter($kueri->DaftarTerbuka($this->AmbilIdGudang($permintaan)), fn (array $p): bool => $p['Uuid'] === $uuidPo));

        return response()->json([
            'Penerimaan' => ['Uuid' => $grn->Uuid, 'Nomor' => $grn->Nomor],
            'Pesanan' => $sisa[0] ?? null,
        ], 201);
    }

    public function DaftarTransfer(Request $permintaan, DokumenGudangPos $kueri): JsonResponse
    {
        return response()->json(['Transfer' => $kueri->TransferMasuk($this->AmbilIdGudang($permintaan))]);
    }

    public function TerimaTransfer(Request $permintaan, string $transfer, DokumenGudangPos $kueri, TerimaTransferStok $terima): JsonResponse
    {
        $valid = $permintaan->validate([
            'UuidPengguna' => ['required', 'string', 'ulid'],
            'Baris' => ['required', 'array', 'min:1', 'max:'.(int) config('persediaan.Dokumen.MaksimalBaris', 500)],
            'Baris.*.Urutan' => ['required', 'integer', 'min:1', 'distinct'],
            'Baris.*.Jumlah' => ['nullable', 'string', self::POLA_JUMLAH],
            // D-105: opsional, jadi aplikasi lama yang hanya mengirim Jumlah tetap berlaku.
            'Baris.*.Rusak' => ['nullable', 'string', self::POLA_JUMLAH],
            'Baris.*.Ditolak' => ['nullable', 'string', self::POLA_JUMLAH],
        ]);
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $pelaku = $this->AmbilPelaku($perangkat, (string) $valid['UuidPengguna'], [IzinTenant::PersediaanKelola], 'menerima transfer stok');
        $idGudang = $this->AmbilIdGudang($permintaan);
        $t = TransferStok::query()->where('Uuid', strtoupper($transfer))->whereIn('IdGudangTujuan', $idGudang)->first();
        abort_if($t === null, 404);

        /** @var list<array<string, mixed>> $baris */
        $baris = array_values((array) $valid['Baris']);
        $terima->Jalankan(
            $t,
            array_map(fn (array $b): DataTerimaTransfer => new DataTerimaTransfer(
                (int) $b['Urutan'],
                Kuantitas::Dari(($b['Jumlah'] ?? '') === '' ? '0' : (string) $b['Jumlah']),
                Kuantitas::Dari(($b['Rusak'] ?? '') === '' ? '0' : (string) $b['Rusak']),
                Kuantitas::Dari(($b['Ditolak'] ?? '') === '' ? '0' : (string) $b['Ditolak']),
            ), $baris),
            $this->tanggalBisnis->Hitung($perangkat->IdOutlet),
            $pelaku->id,
        );

        return response()->json(['Transfer' => $kueri->AmbilTransfer($t->Uuid, $idGudang)]);
    }

    public function DaftarOpname(Request $permintaan, DokumenGudangPos $kueri): JsonResponse
    {
        return response()->json(['Opname' => $kueri->OpnameBerlangsung($this->AmbilIdGudang($permintaan))]);
    }

    public function SimpanHitung(Request $permintaan, string $opname, DokumenGudangPos $kueri, InfoProdukStok $infoProduk, SimpanHitungStokOpname $simpan): JsonResponse
    {
        $valid = $permintaan->validate([
            'UuidPengguna' => ['required', 'string', 'ulid'],
            'Hitung' => ['required', 'array', 'min:1', 'max:'.(int) config('persediaan.Dokumen.MaksimalBarisOpname', 5000)],
            'Hitung.*.Urutan' => ['nullable', 'integer', 'min:1', 'required_without:Hitung.*.UuidProduk'],
            'Hitung.*.UuidProduk' => ['nullable', 'string', 'ulid'],
            'Hitung.*.JumlahFisik' => ['nullable', 'string', self::POLA_JUMLAH],
        ]);
        $perangkat = AutentikasiPerangkat::AmbilPerangkat($permintaan);
        $pelaku = $this->AmbilPelaku($perangkat, (string) $valid['UuidPengguna'], [IzinTenant::PersediaanKelola], 'menghitung stok opname');
        $idGudang = $this->AmbilIdGudang($permintaan);
        $o = StokOpname::query()->where('Uuid', strtoupper($opname))->whereIn('IdGudang', $idGudang)->first();
        abort_if($o === null, 404);

        /** @var list<array<string, mixed>> $hitung */
        $hitung = array_values((array) $valid['Hitung']);
        $uuidProduk = array_values(array_unique(array_map('strtoupper', array_filter(array_column($hitung, 'UuidProduk'), 'is_string'))));
        $produk = $infoProduk->AmbilDariUuid($uuidProduk);
        $simpan->Jalankan($o, array_map(function (array $h) use ($produk): DataHitungOpname {
            $uuid = is_string($h['UuidProduk'] ?? null) ? strtoupper($h['UuidProduk']) : null;

            if ($uuid !== null && ! isset($produk[$uuid])) {
                throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Produk hasil hitung tidak ditemukan. Perbarui katalog perangkat.', 'Hitung');
            }

            return new DataHitungOpname(
                urutan: isset($h['Urutan']) ? (int) $h['Urutan'] : null,
                idProduk: $uuid === null ? null : $produk[$uuid]->id,
                jumlahFisik: is_string($h['JumlahFisik'] ?? null) ? Kuantitas::Dari($h['JumlahFisik']) : null,
            );
        }, $hitung), $pelaku->id);

        return response()->json(['Opname' => $kueri->AmbilOpname($o->Uuid, $idGudang)]);
    }

    /**
     * Lokasi stok aktif outlet perangkat.
     *
     * @return list<int>
     */
    private function AmbilIdGudang(Request $permintaan): array
    {
        return array_map(fn ($g): int => $g->id, $this->infoGudang->AmbilBoleh([AutentikasiPerangkat::AmbilPerangkat($permintaan)->IdOutlet]));
    }

    /**
     * Staf anggota outlet perangkat yang punya salah satu [izin].
     *
     * @param  list<IzinTenant>  $izin
     */
    private function AmbilPelaku(Perangkat $perangkat, string $uuidPengguna, array $izin, string $tindakan): DataAnggotaOutlet
    {
        $pelaku = $this->anggota->Cari($perangkat->IdTenant, strtoupper($uuidPengguna), $perangkat->IdOutlet);

        if ($pelaku === null || array_filter($izin, fn (IzinTenant $i): bool => $pelaku->CekIzin($i->value)) === []) {
            throw new PelanggaranAturanBisnis('TanpaIzin', "Pengguna ini tidak boleh {$tindakan} di outlet ini.", 'UuidPengguna', 403);
        }

        return $pelaku;
    }

    private static function Teks(mixed $nilai): ?string
    {
        return is_string($nilai) && trim($nilai) !== '' ? trim($nilai) : null;
    }
}
