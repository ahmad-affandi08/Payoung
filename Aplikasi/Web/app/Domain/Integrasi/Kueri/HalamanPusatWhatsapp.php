<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Kueri;

use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Layanan\PengaturPesanWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Model\PenerimaLaporanWhatsapp;
use App\Domain\Integrasi\Model\RiwayatPesanWhatsapp;
use App\Domain\Integrasi\Whatsapp\Sesi\PembuatGerbangSesiWhatsapp;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;

/**
 * Data halaman Pusat WhatsApp (D-299): nomor pengirim tenant beserta outlet dan statusnya. Token perangkat tidak
 * pernah ikut, hanya status sambungan.
 */
final class HalamanPusatWhatsapp
{
    public function __construct(
        private readonly PetaUuidOutlet $outlet,
        private readonly PembuatGerbangSesiWhatsapp $gerbang,
        private readonly PengaturPesanWhatsapp $pengatur,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function Ambil(): array
    {
        $outlet = $this->outlet->AmbilRingkas();
        $uuidOutlet = array_column($outlet, 'Uuid', 'Id');
        $namaOutlet = array_column($outlet, 'Nama', 'Id');

        $nomor = NomorPengirimWhatsapp::query()->orderBy('IdOutlet')->orderBy('Id')->get()
            ->map(fn (NomorPengirimWhatsapp $n): array => [
                'Uuid' => $n->Uuid,
                'Label' => $n->Label,
                'Fungsi' => $n->Fungsi->value,
                'LabelFungsi' => $n->Fungsi->AmbilLabel(),
                'NomorTelepon' => $n->NomorTelepon,
                'UuidOutlet' => $n->IdOutlet === null ? null : ($uuidOutlet[$n->IdOutlet] ?? null),
                'NamaOutlet' => $n->IdOutlet === null ? 'Semua outlet' : ($namaOutlet[$n->IdOutlet] ?? 'Outlet'),
                'Status' => $n->Status->value,
                'LabelStatus' => $n->Status->AmbilLabel(),
                'Aktif' => $n->Aktif,
                'PesanStatus' => $n->PesanStatus,
                'TerhubungPada' => $n->TerhubungPada?->toIso8601String(),
            ])->values()->all();

        $penerima = PenerimaLaporanWhatsapp::query()->orderBy('IdOutlet')->orderBy('Id')->get()
            ->map(fn (PenerimaLaporanWhatsapp $p): array => [
                'Uuid' => $p->Uuid, 'Nama' => $p->Nama, 'NomorTelepon' => $p->NomorTelepon,
                'UuidOutlet' => $uuidOutlet[$p->IdOutlet] ?? null, 'NamaOutlet' => $namaOutlet[$p->IdOutlet] ?? 'Outlet',
            ])->values()->all();
        $riwayat = RiwayatPesanWhatsapp::query()->orderByDesc('Id')->limit(50)->get()
            ->map(fn (RiwayatPesanWhatsapp $r): array => [
                'Id' => $r->Id, 'Jenis' => $r->Jenis->AmbilLabel(), 'Tujuan' => $r->Tujuan,
                'NamaOutlet' => $r->IdOutlet === null ? '-' : ($namaOutlet[$r->IdOutlet] ?? 'Outlet'),
                'Status' => $r->Status->value, 'PesanGalat' => $r->PesanGalat, 'DibuatPada' => $r->DibuatPada?->toIso8601String(),
            ])->values()->all();

        return [
            'PesanOtomatis' => $this->pengatur->AmbilSemua(),
            'Penerima' => $penerima,
            'MaksPenerima' => PenerimaLaporanWhatsapp::MAKS_PER_OUTLET,
            'Riwayat' => $riwayat,
            'Nomor' => $nomor,
            'Outlet' => array_map(fn (array $o): array => ['Uuid' => $o['Uuid'], 'Nama' => $o['Nama']], $outlet),
            'OpsiFungsi' => array_map(fn (FungsiNomorWhatsapp $f): array => ['Nilai' => $f->value, 'Label' => $f->AmbilLabel()], FungsiNomorWhatsapp::cases()),
            'MaksPerOutlet' => NomorPengirimWhatsapp::MAKS_PER_OUTLET,
            'SesiSiap' => $this->gerbang->Tersedia(),
        ];
    }
}
