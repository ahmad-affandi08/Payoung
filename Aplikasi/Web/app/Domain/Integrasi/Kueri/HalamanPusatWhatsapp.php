<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Kueri;

use App\Domain\Integrasi\Aksi\SegarkanTopUpSaldoWhatsapp;
use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Enum\StatusTopUpSaldoWhatsapp;
use App\Domain\Integrasi\Layanan\PengaturPesanWhatsapp;
use App\Domain\Integrasi\Model\KreditBroadcastOutlet;
use App\Domain\Integrasi\Model\MutasiSaldoWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Model\PenerimaLaporanWhatsapp;
use App\Domain\Integrasi\Model\RiwayatPesanWhatsapp;
use App\Domain\Integrasi\Model\TopUpSaldoWhatsapp;
use App\Domain\Integrasi\Whatsapp\Saldo\BukuSaldoWhatsapp;
use App\Domain\Integrasi\Whatsapp\Sesi\PembuatGerbangSesiWhatsapp;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Tenant\Layanan\PengaturanSaldoWhatsapp;

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
        private readonly BukuSaldoWhatsapp $buku,
        private readonly PengaturanSaldoWhatsapp $hargaPesan,
        private readonly SegarkanTopUpSaldoWhatsapp $segarkanTopUp,
        private readonly PenyediaTagihanPlatform $penyediaBayar,
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
            'Saldo' => $this->AmbilSaldo($uuidOutlet, $namaOutlet),
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

    /**
     * @param  array<int, string>  $uuidOutlet
     * @param  array<int, string>  $namaOutlet
     * @return array<string, mixed>
     */
    private function AmbilSaldo(array $uuidOutlet, array $namaOutlet): array
    {
        // Top-up yang masih menunggu ditanyakan ke penyedia supaya saldo langsung bertambah saat pemilik kembali dari halaman bayar.
        foreach (TopUpSaldoWhatsapp::query()->where('Status', StatusTopUpSaldoWhatsapp::Menunggu->value)->orderByDesc('Id')->limit(3)->get() as $menunggu) {
            $this->segarkanTopUp->Jalankan($menunggu);
        }

        $kredit = KreditBroadcastOutlet::query()->orderBy('IdOutlet')->get()->map(fn (KreditBroadcastOutlet $k): array => [
            'UuidOutlet' => $uuidOutlet[$k->IdOutlet] ?? null, 'NamaOutlet' => $namaOutlet[$k->IdOutlet] ?? 'Outlet', 'Saldo' => $k->Saldo,
        ])->filter(fn (array $k): bool => $k['UuidOutlet'] !== null)->values()->all();

        return [
            'Berbayar' => $this->hargaPesan->BroadcastBerbayar(),
            'HargaPerPesan' => $this->hargaPesan->HargaPerPesan()->KeString(),
            'TopUpMinimal' => $this->hargaPesan->TopUpMinimal()->KeString(),
            'TopUpTersedia' => $this->penyediaBayar->CekCheckoutAktif(),
            'SaldoUtama' => $this->buku->AmbilSaldo()->KeString(),
            'KreditOutlet' => $kredit,
            'TopUp' => TopUpSaldoWhatsapp::query()->orderByDesc('Id')->limit(10)->get()->map(fn (TopUpSaldoWhatsapp $t): array => [
                'Uuid' => $t->Uuid, 'Jumlah' => $t->Jumlah, 'Status' => $t->Status->value, 'LabelStatus' => $t->Status->AmbilLabel(),
                'DibuatPada' => $t->DibuatPada?->toIso8601String(),
            ])->all(),
            'Mutasi' => MutasiSaldoWhatsapp::query()->orderByDesc('Id')->limit(30)->get()->map(fn (MutasiSaldoWhatsapp $m): array => [
                'Id' => $m->Id, 'Jenis' => $m->Jenis->AmbilLabel(), 'Jumlah' => $m->Jumlah, 'SaldoSetelah' => $m->SaldoSetelah,
                'Kolam' => $m->IdOutlet === null ? 'Saldo utama' : ($namaOutlet[$m->IdOutlet] ?? 'Outlet'), 'Keterangan' => $m->Keterangan,
                'DibuatPada' => $m->DibuatPada?->toIso8601String(),
            ])->all(),
        ];
    }
}
