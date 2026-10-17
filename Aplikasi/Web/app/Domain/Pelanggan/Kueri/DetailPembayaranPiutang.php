<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Kueri;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Kueri\JurnalSumber;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\DaftarAnggota;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\PembayaranPiutangAlokasi;
use App\Domain\Pelanggan\Model\Piutang;

/**
 * Props halaman detail pelunasan piutang (F-12; tipe FE di `Tipe/Piutang.ts`): dokumen, alokasi per piutang, jurnal
 * (`JurnalSumber`), dan riwayat status. Uang sebagai string desimal.
 */
final class DetailPembayaranPiutang
{
    public function __construct(
        private readonly DaftarAkunPilihan $akun,
        private readonly JurnalSumber $jurnal,
        private readonly DaftarAnggota $anggota,
        private readonly KonteksTenant $konteks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(PembayaranPiutang $p): array
    {
        $pelanggan = Pelanggan::query()->whereKey($p->IdPelanggan)->first(['Uuid', 'Nama']);
        $akun = $this->akun->AmbilBanyak([$p->IdAkun])[$p->IdAkun] ?? null;
        $riwayat = RiwayatStatusDokumen::query()->where('JenisDokumen', PembayaranPiutang::JENIS_DOKUMEN)->where('IdDokumen', $p->Id)->orderBy('Id')->get();
        $nama = $this->NamaPengguna([$p->DibuatOleh, ...$riwayat->pluck('DiubahOleh')->all()]);
        $alokasi = PembayaranPiutangAlokasi::query()->where('IdPembayaranPiutang', $p->Id)->orderBy('Id')->get();
        $piutang = Piutang::query()->whereIn('Id', $alokasi->pluck('IdPiutang')->all())->get()->keyBy('Id');

        return [
            'Pelunasan' => [
                'Uuid' => $p->Uuid,
                'Nomor' => $p->Nomor,
                'Tanggal' => $p->Tanggal->format('Y-m-d'),
                'Status' => $p->Status->value,
                'LabelStatus' => $p->Status->AmbilLabel(),
                'Pelanggan' => $pelanggan === null ? null : ['Uuid' => $pelanggan->Uuid, 'Nama' => $pelanggan->Nama],
                'Akun' => $akun === null ? null : "{$akun['Kode']} {$akun['Nama']}",
                'Jumlah' => (string) $p->Jumlah,
                'PotonganPph' => (string) $p->PotonganPph,
                'NomorBuktiPotong' => $p->NomorBuktiPotong,
                'Catatan' => $p->Catatan,
                'AlasanBatal' => $p->AlasanBatal,
                'DibuatOleh' => $p->DibuatOleh === null ? null : ($nama[$p->DibuatOleh] ?? null),
            ],
            'Alokasi' => array_values($alokasi->map(function (PembayaranPiutangAlokasi $a) use ($piutang): array {
                /** @var Piutang|null $x */
                $x = $piutang->get($a->IdPiutang);

                return [
                    'Nomor' => $x?->Nomor,
                    'JatuhTempo' => $x?->JatuhTempo->format('Y-m-d'),
                    'Sisa' => $x?->AmbilSisa()->KeString(),
                    'Jumlah' => (string) $a->Jumlah,
                ];
            })->all()),
            'Jurnal' => $this->jurnal->Ambil(JenisSumberJurnal::PembayaranPiutang, $p->Id),
            'Riwayat' => array_values($riwayat->map(fn (RiwayatStatusDokumen $r): array => [
                'StatusKe' => (string) $r->StatusKe,
                'Oleh' => $r->DiubahOleh === null ? null : ($nama[$r->DiubahOleh] ?? null),
                'Pada' => $r->DiubahPada?->toIso8601String() ?? '',
                'Alasan' => $r->Alasan,
            ])->all()),
        ];
    }

    /**
     * @param  array<mixed>  $id
     * @return array<int, string>
     */
    private function NamaPengguna(array $id): array
    {
        $id = array_values(array_unique(array_filter($id, 'is_int')));

        return $id === [] ? [] : $this->anggota->AmbilNamaPengguna($this->konteks->Wajib(), $id);
    }
}
