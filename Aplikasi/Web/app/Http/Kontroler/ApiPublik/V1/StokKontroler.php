<?php

declare(strict_types=1);

namespace App\Http\Kontroler\ApiPublik\V1;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Aksi\AjukanPenyesuaianStok;
use App\Domain\Persediaan\Aksi\SimpanPenyesuaianStok;
use App\Domain\Persediaan\Data\DataBarisDokumenStok;
use App\Domain\Persediaan\Data\DataPenyesuaianStok;
use App\Domain\Persediaan\Enum\AlasanPenyesuaian;
use App\Domain\Persediaan\Enum\StatusPenyesuaianStok;
use App\Domain\Persediaan\Kueri\PenyesuaianStokUntukApiPublik;
use App\Http\Perantara\AutentikasiTokenApi;
use App\Http\Permintaan\ApiPublik\BuatPenyesuaianStokApiPermintaan;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * X7 bagian 4 — endpoint tulis Open API v1 (PRD §16.1 cakupan `stok:tulis`): sistem lain (WMS, marketplace, ERP)
 * membuat **penyesuaian stok** lewat aksi yang sama dengan back-office (`SimpanPenyesuaianStok` + `AjukanPenyesuaianStok`):
 * validasi baris, HPP masuk, batch, batas persetujuan nilai (di atas batas = `MenungguPersetujuan`, disetujui orang di
 * back-office), mutasi & jurnal di transaksi yang sama. Pelaku = Owner pembuat token; audit mencatat asalnya. Idempoten
 * per `Uuid` dokumen. Produk bernomor seri belum didukung lewat API (pilih unit di back-office).
 */
final class StokKontroler
{
    public function BuatPenyesuaian(
        BuatPenyesuaianStokApiPermintaan $permintaan,
        InfoGudang $gudang,
        InfoProdukStok $infoProduk,
        PenyesuaianStokUntukApiPublik $kueri,
        SimpanPenyesuaianStok $simpan,
        AjukanPenyesuaianStok $ajukan,
        PencatatAudit $audit,
    ): JsonResponse {
        $token = AutentikasiTokenApi::AmbilToken($permintaan);
        $audit->AturKonteks($token->DibuatOleh, $permintaan->ip(), mb_substr("Token API {$token->Prefiks} ({$token->Nama})", 0, 255));
        $uuid = strtoupper((string) $permintaan->validated('Uuid'));
        $ada = $kueri->Satu($uuid);

        if ($ada !== null && $ada['Status'] !== StatusPenyesuaianStok::Draf->value) {
            return $this->JawabUlang($permintaan, $ada);
        }

        // Draf dan pengajuan satu transaksi, jadi API tidak pernah meninggalkan Draf. Draf ber-Uuid sama berarti milik
        // back-office: jangan diajukan dengan isi lamanya.
        if ($ada !== null) {
            throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Uuid ini sudah dipakai draf penyesuaian lain. Pakai Uuid baru.', 'Uuid');
        }

        $uuidGudang = strtoupper((string) $permintaan->validated('UuidGudang'));
        $lokasi = $gudang->AmbilDariUuid([$uuidGudang])[$uuidGudang] ?? throw new PelanggaranAturanBisnis('GudangTidakDikenal', 'Lokasi stok tidak ditemukan.', 'UuidGudang');
        /** @var list<array<string, mixed>> $barisMasuk */
        $barisMasuk = array_values((array) $permintaan->validated('Baris'));
        $produk = $infoProduk->AmbilDariUuid(array_values(array_map(fn (array $b): string => strtoupper((string) $b['UuidProduk']), $barisMasuk)));
        $baris = [];

        foreach ($barisMasuk as $i => $b) {
            $p = $produk[strtoupper((string) $b['UuidProduk'])] ?? throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Produk baris ke-'.($i + 1).' tidak ditemukan.', "Baris.{$i}.UuidProduk");

            if ($p->pelacakan === PelacakanProduk::Seri) {
                throw new PelanggaranAturanBisnis('PelacakanBelumDidukung', "{$p->nama} bernomor seri. Sesuaikan per unit dari back-office.", "Baris.{$i}.UuidProduk");
            }

            $jumlah = Kuantitas::Dari((string) $b['Jumlah']);
            $nomorBatch = isset($b['NomorBatch']) && is_string($b['NomorBatch']) && trim($b['NomorBatch']) !== '' ? trim($b['NomorBatch']) : null;
            $keluar = $jumlah->KeDesimal()->isNegative();
            $idBatch = null;

            if ($keluar && $nomorBatch !== null) {
                $idBatch = $kueri->CariIdBatch($p->id, $lokasi->id, $nomorBatch)
                    ?? throw new PelanggaranAturanBisnis('BatchTidakDikenal', "Batch {$nomorBatch} {$p->nama} tidak ada di lokasi ini.", "Baris.{$i}.NomorBatch");
            }

            $baris[] = new DataBarisDokumenStok(
                idProduk: $p->id,
                jumlah: $jumlah,
                hppSatuan: isset($b['HppSatuan']) && is_string($b['HppSatuan']) ? BigDecimal::of($b['HppSatuan']) : null,
                idBatchStok: $idBatch,
                nomorBatch: $keluar ? null : $nomorBatch,
                tanggalKedaluwarsa: ! $keluar && isset($b['TanggalKedaluwarsa']) && is_string($b['TanggalKedaluwarsa']) ? CarbonImmutable::parse($b['TanggalKedaluwarsa']) : null,
            );
        }

        try {
            DB::transaction(function () use ($simpan, $ajukan, $uuid, $lokasi, $permintaan, $baris, $token): void {
                $draf = $simpan->Jalankan(new DataPenyesuaianStok(
                    uuid: $uuid,
                    idGudang: $lokasi->id,
                    tanggal: CarbonImmutable::parse((string) $permintaan->validated('Tanggal')),
                    alasan: AlasanPenyesuaian::from((string) $permintaan->validated('Alasan')),
                    keterangan: is_string($permintaan->validated('Keterangan')) ? $permintaan->validated('Keterangan') : null,
                    baris: $baris,
                ), null);
                $ajukan->Jalankan($draf, $token->DibuatOleh, bolehLangsung: false);
            });
        } catch (QueryException $galat) {
            // Dua POST pertama bersamaan dengan Uuid sama: yang kalah membaca hasil pemenang, bukan HTTP 500.
            $pemenang = ($galat->errorInfo[1] ?? null) === 1062 ? $kueri->Satu($uuid) : null;

            if ($pemenang === null) {
                throw $galat;
            }

            return $this->JawabUlang($permintaan, $pemenang);
        }

        return response()->json(['Data' => $kueri->Satu($uuid)], 201);
    }

    /**
     * Kirim ulang Uuid yang sudah diproses: isi sama (lokasi + baris produk & jumlah) = 200 dengan dokumen lama
     * (idempoten); isi berbeda = 409 `UuidSudahDipakai`, supaya integrator tidak mengira penyesuaian barunya tercatat.
     *
     * @param  array<string, mixed>  $ada
     */
    private function JawabUlang(BuatPenyesuaianStokApiPermintaan $permintaan, array $ada): JsonResponse
    {
        $susun = fn (iterable $baris): array => collect($baris)
            ->map(fn (array $b): string => strtoupper((string) $b['UuidProduk']).'|'.Kuantitas::Dari((string) $b['Jumlah'])->KeString())
            ->sort()->values()->all();
        /** @var list<array<string, mixed>> $barisMasuk */
        $barisMasuk = array_values((array) $permintaan->validated('Baris'));
        /** @var list<array<string, mixed>> $barisLama */
        $barisLama = is_array($ada['Baris'] ?? null) ? array_values($ada['Baris']) : [];
        $sama = strtoupper((string) $permintaan->validated('UuidGudang')) === strtoupper((string) ($ada['UuidGudang'] ?? ''))
            && $susun($barisMasuk) === $susun($barisLama);

        if (! $sama) {
            throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Uuid ini sudah dipakai penyesuaian lain dengan isi berbeda. Pakai Uuid baru.', 'Uuid', 409);
        }

        return response()->json(['Data' => $ada]);
    }
}
