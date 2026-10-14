<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Kueri;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Bersama\Tabel\Layanan\PenerapKueriTabel;
use App\Domain\Pelanggan\Enum\StatusGiftCard;
use App\Domain\Pelanggan\Layanan\KodeGiftCard;
use App\Domain\Pelanggan\Model\GiftCard;
use App\Domain\Pelanggan\Model\MutasiGiftCard;
use Illuminate\Support\Collection;

/**
 * Daftar gift card untuk `TabelData` back-office (CRM-06): cari kode (lengkap, huruf kecil/tanpa tanda hubung boleh)
 * atau nama pemegang, saring status, urut tanggal/saldo/masa berlaku. Kode di daftar hanya samarannya; kode lengkap
 * tampil sekali saat kartu dijual (dialog rahasia) dan bisa dicari lagi lewat kolom cari oleh yang berizin.
 * `Rincian` memuat riwayat buku satu kartu untuk dialog.
 */
final class DaftarGiftCard
{
    public const KOLOM_URUT = ['DiterbitkanPada', 'Saldo', 'NilaiAwal', 'BerlakuSampai'];

    public const KOLOM_SARING = ['Status'];

    public const URUT_BAWAAN = '-DiterbitkanPada';

    /**
     * @return array{Data: list<array<string, mixed>>, Meta: array<string, mixed>}
     */
    public function AmbilTabel(DataPermintaanTabel $permintaan): array
    {
        $status = $permintaan->AmbilDaftar('Status', array_map(fn (StatusGiftCard $s): string => $s->value, StatusGiftCard::cases()));
        $kode = KodeGiftCard::Normalisasi($permintaan->cari);

        $kueri = GiftCard::query()
            ->when($status !== [], fn ($k) => $k->whereIn('Status', $status))
            ->when($permintaan->cari !== '', fn ($k) => $k->where(function ($d) use ($permintaan, $kode): void {
                $d->where('NamaPemegang', 'like', PenerapKueriTabel::PolaCari($permintaan->cari));

                if ($kode !== '') {
                    $d->orWhere('Kode', $kode);
                }
            }));

        return PenerapKueriTabel::Terapkan($kueri, $permintaan, [
            'DiterbitkanPada' => 'DiterbitkanPada',
            'Saldo' => 'Saldo',
            'NilaiAwal' => 'NilaiAwal',
            'BerlakuSampai' => 'BerlakuSampai',
        ], function (Collection $baris): array {
            /** @var Collection<int, GiftCard> $baris */
            return array_values($baris->map(fn (GiftCard $g): array => [
                'Uuid' => $g->Uuid,
                'KodeSamar' => KodeGiftCard::Samarkan($g->Kode),
                'NamaPemegang' => $g->NamaPemegang,
                'NilaiAwal' => $g->NilaiAwal,
                'Saldo' => $g->Saldo,
                'Status' => $g->Status->value,
                'LabelStatus' => $g->Status->AmbilLabel(),
                'BerlakuSampai' => $g->BerlakuSampai?->toDateString(),
                'DiterbitkanPada' => $g->DiterbitkanPada->toIso8601ZuluString(),
            ])->all());
        });
    }

    /**
     * Ringkasan di kepala halaman: jumlah kartu aktif dan Σ saldo beredar (kewajiban kepada pemegang kartu).
     *
     * @return array{KartuAktif: int, SaldoBeredar: string}
     */
    public function AmbilRingkasan(): array
    {
        return [
            'KartuAktif' => GiftCard::query()->where('Status', StatusGiftCard::Aktif->value)->count(),
            'SaldoBeredar' => (string) (GiftCard::query()->whereIn('Status', [StatusGiftCard::Aktif->value, StatusGiftCard::Habis->value])->selectRaw('CAST(COALESCE(SUM(Saldo), 0) AS DECIMAL(18,2)) AS Total')->value('Total') ?? '0.00'),
        ];
    }

    /**
     * @return array{Kartu: array<string, mixed>, Riwayat: list<array<string, mixed>>}|null
     */
    public function Rincian(string $uuid): ?array
    {
        $kartu = GiftCard::query()->where('Uuid', $uuid)->first();

        if ($kartu === null) {
            return null;
        }

        return [
            'Kartu' => [
                'Uuid' => $kartu->Uuid,
                'KodeSamar' => KodeGiftCard::Samarkan($kartu->Kode),
                'NamaPemegang' => $kartu->NamaPemegang,
                'Catatan' => $kartu->Catatan,
                'NilaiAwal' => $kartu->NilaiAwal,
                'Saldo' => $kartu->Saldo,
                'Status' => $kartu->Status->value,
                'LabelStatus' => $kartu->Status->AmbilLabel(),
                'BerlakuSampai' => $kartu->BerlakuSampai?->toDateString(),
                'DiterbitkanPada' => $kartu->DiterbitkanPada->toIso8601ZuluString(),
            ],
            'Riwayat' => array_values(MutasiGiftCard::query()->where('IdGiftCard', $kartu->Id)->orderBy('Id')->get()->map(fn (MutasiGiftCard $m): array => [
                'Jenis' => $m->Jenis->value,
                'LabelJenis' => $m->Jenis->AmbilLabel(),
                'Jumlah' => $m->Jumlah,
                'SaldoSetelah' => $m->SaldoSetelah,
                'NomorSumber' => $m->NomorSumber,
                'Keterangan' => $m->Keterangan,
                'Tanggal' => $m->Tanggal->toDateString(),
            ])->all()),
        ];
    }

    public function CariUuidDariMutasi(string $uuidMutasi): ?string
    {
        $mutasi = MutasiGiftCard::query()->where('Uuid', $uuidMutasi)->first(['IdGiftCard']);

        return $mutasi === null ? null : GiftCard::query()->whereKey($mutasi->IdGiftCard)->value('Uuid');
    }
}
