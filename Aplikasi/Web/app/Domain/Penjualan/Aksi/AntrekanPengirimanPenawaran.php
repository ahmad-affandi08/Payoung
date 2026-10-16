<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PengirimanPenawaran;
use App\Domain\Penjualan\Tugas\KirimPenawaranTugas;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Illuminate\Support\Facades\DB;

/**
 * F-21 bagian 3: antrekan pengiriman satu penawaran ke pelanggan lewat WhatsApp (notifikasi tenant hanya lewat WhatsApp,
 * D-33). Syarat: penawaran sudah terbit dan masih menunggu keputusan dan masih berlaku, pelanggan punya nomor HP Indonesia
 * yang sah, WhatsApp aktif untuk usaha ini. Dibatasi sekali per `JEDA_MENIT` per penawaran supaya pelanggan tidak
 * dibanjiri. Pengiriman dilakukan tugas antrean setelah commit. Audit `penawaran.kirim` (tanpa nomor).
 */
final class AntrekanPengirimanPenawaran
{
    public const JEDA_MENIT = 10;

    public function __construct(
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly IdentitasPelanggan $identitas,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PenawaranBelumTerbit, PenawaranKedaluwarsa, KontakTidakTersedia, WhatsappTidakAktif, PenawaranBaruDikirim
     */
    public function Jalankan(int $idTenant, Penawaran $penawaran, ?int $idPengguna): PengirimanPenawaran
    {
        if ($penawaran->Status !== StatusPenawaran::Terbit) {
            throw new PelanggaranAturanBisnis('PenawaranBelumTerbit', 'Penawaran dikirim setelah diterbitkan dan selama masih menunggu keputusan.', 'Umum', 409);
        }

        if ($penawaran->BerlakuSampai->toDateString() < $this->tanggalBisnis->Hitung($penawaran->IdOutlet)->toDateString()) {
            throw new PelanggaranAturanBisnis('PenawaranKedaluwarsa', 'Masa berlaku penawaran ini sudah lewat, jadi tidak dikirim lagi. Buat penawaran baru (salin) dengan masa berlaku yang baru.', 'Umum', 409);
        }

        $kontak = $this->identitas->AmbilKontak($penawaran->IdPelanggan);
        $nomor = $kontak === null ? null : PesanWhatsapp::AmbilNomorSah($kontak['NoHp']);

        if ($nomor === null) {
            throw new PelanggaranAturanBisnis('KontakTidakTersedia', 'Pelanggan belum punya nomor HP yang sah. Lengkapi nomornya di data pelanggan (contoh 0812 3456 7890).', 'Umum', 409);
        }

        if ($this->whatsapp->AmbilAktif() === null || ! $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
            throw new PelanggaranAturanBisnis('WhatsappTidakAktif', 'Pengiriman WhatsApp belum aktif untuk usaha ini.', 'Umum', 409);
        }

        return DB::transaction(function () use ($idTenant, $penawaran, $idPengguna, $nomor): PengirimanPenawaran {
            Penawaran::query()->whereKey($penawaran->Id)->lockForUpdate()->first();

            $baru = PengirimanPenawaran::query()
                ->where('IdPenawaran', $penawaran->Id)
                ->whereIn('Status', [StatusPengirimanInvoice::Diantrekan->value, StatusPengirimanInvoice::Terkirim->value])
                ->where('DibuatPada', '>', now()->subMinutes(self::JEDA_MENIT))
                ->exists();

            if ($baru) {
                throw new PelanggaranAturanBisnis('PenawaranBaruDikirim', 'Penawaran ini baru saja dikirim ke pelanggan. Coba lagi beberapa menit lagi.', 'Umum', 429);
            }

            $pengiriman = PengirimanPenawaran::query()->create([
                'IdPenawaran' => $penawaran->Id,
                'Tujuan' => $nomor,
                'Status' => StatusPengirimanInvoice::Diantrekan,
                'DikirimOleh' => $idPengguna,
            ]);
            $this->audit->Catat('penawaran.kirim', $penawaran, nilaiBaru: ['Nomor' => $penawaran->Nomor], idPengguna: $idPengguna);
            KirimPenawaranTugas::dispatch($idTenant, $pengiriman->Id)->afterCommit();

            return $pengiriman;
        });
    }
}
