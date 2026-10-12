<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Organisasi\Kueri\ProfilPajakOutlet;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Kueri\RiwayatPoin;
use App\Domain\Pemenuhan\Kueri\StatusLaundryPublik;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Layanan\PenentuKonteksTokoOnline;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\PenjualanPajak;
use App\Domain\Penjualan\Model\PenjualanPembayaran;
use App\Domain\Penjualan\Model\ReturPenjualan;
use App\Domain\Tenant\Kueri\PengaturanStrukTenant;
use App\Domain\Tenant\Kueri\ProfilTenant;

/**
 * Isi struk digital publik (POS-11, `/s/{kodeStruk}`) untuk satu penjualan tenant aktif: hanya data yang juga tercetak
 * di struk (tanpa HPP, catatan internal, atau tinjauan), mengikuti pengaturan struk tenant (alamat, NPWP, kasir,
 * pelanggan, catatan kaki). Penjualan yang di-void ditandai; retur disebut total pengembaliannya. Laundry (§9.9):
 * penjualan bertiket laundry selalu bisa dilacak (QR label/nota) walau struk digital dimatikan, dengan status proses
 * cucian. Null bila tidak ada, atau struk digital dimatikan dan bukan tiket laundry.
 */
final class StrukDigitalPenjualan
{
    public function __construct(
        private readonly PengaturanStrukTenant $pengaturan,
        private readonly ProfilTenant $profil,
        private readonly AnggotaOutlet $anggota,
        private readonly IdentitasPelanggan $pelanggan,
        private readonly OutletPenjualan $outlet,
        private readonly ProfilPajakOutlet $pajakOutlet,
        private readonly RiwayatPoin $poin,
        private readonly StatusLaundryPublik $laundry,
        private readonly PenentuKonteksTokoOnline $tokoOnline,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function Ambil(int $idTenant, string $uuid): ?array
    {
        $p = Penjualan::query()->where('Uuid', $uuid)->first();
        $outlet = $p === null ? null : $this->outlet->Ambil($p->IdOutlet, $p->IdPerangkat);
        $struk = $this->pengaturan->Ambil($idTenant, $outlet?->idOutlet);
        $laundry = $p === null ? null : $this->laundry->AmbilUntukPenjualan($p->Id);

        if ($p === null || (! $struk->tampilkanStrukDigital && $laundry === null)) {
            return null;
        }

        $tenant = $this->profil->Ambil($idTenant);
        $pkp = $this->pajakOutlet->Ambil($p->IdOutlet)->pkp ?? false;
        $totalRetur = null;

        foreach (ReturPenjualan::query()->where('IdPenjualanAsal', $p->Id)->pluck('TotalRefund') as $refund) {
            $totalRetur = ($totalRetur ?? Uang::Nol())->Tambah(Uang::Dari((string) $refund));
        }

        return [
            'NamaUsaha' => $struk->namaDicetak ?? $outlet->namaOutlet ?? $tenant['Nama'],
            'TeksKepala' => $struk->teksKepala,
            'NamaOutlet' => $outlet?->namaOutlet,
            'Alamat' => $struk->tampilkanAlamat ? $outlet?->alamat : null,
            'Npwp' => $struk->tampilkanNpwp && $pkp ? $tenant['Npwp'] : null,
            'Nomor' => $p->Nomor,
            'Waktu' => $p->DibuatOfflinePada->toIso8601String(),
            'NamaKasir' => $struk->tampilkanKasir ? ($this->anggota->AmbilNama([$p->IdPengguna])[$p->IdPengguna]['Nama'] ?? null) : null,
            'NamaPelanggan' => $struk->tampilkanPelanggan ? ($this->pelanggan->AmbilRingkas($p->IdPelanggan)['Nama'] ?? null) : null,
            'Dibatalkan' => $p->Status === StatusPenjualan::Void,
            'Baris' => array_values(PenjualanDetail::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get()->map(fn (PenjualanDetail $d): array => [
                'NamaProduk' => $d->NamaProduk,
                'Pilihan' => array_values(array_map(fn (array $pilihan): string => $pilihan['Nama'], $d->Pilihan ?? [])),
                'Jumlah' => $d->Jumlah,
                'HargaSatuan' => $d->HargaSatuan,
                'Diskon' => $d->JumlahDiskon,
                'Total' => $d->Bruto,
                // F-05h: nomor seri/IMEI yang dijual & garansi sampai (tanggal bisnis + masa garansi), hanya produk bernomor seri.
                'NomorSeri' => $d->NomorSeri ?? [],
                'GaransiSampai' => $d->NomorSeri !== null && $d->MasaGaransiBulan !== null
                    ? $p->TanggalBisnis->copy()->addMonthsNoOverflow($d->MasaGaransiBulan)->toDateString()
                    : null,
            ])->all()),
            'Subtotal' => $p->Subtotal,
            'TotalDiskon' => $p->TotalDiskon,
            'BiayaLayanan' => $p->BiayaLayanan,
            // F-17 bagian 3: ongkir kotor & potongannya (promo gratis ongkir), terpisah dari subtotal barang.
            'BiayaKirim' => $p->BiayaKirim,
            'DiskonKirim' => $p->DiskonKirim,
            'Pajak' => array_values(PenjualanPajak::query()->where('IdPenjualan', $p->Id)->orderBy('Id')->get()->map(fn (PenjualanPajak $pajak): array => [
                'Kode' => $pajak->KodeJenisPajak,
                'Tarif' => $pajak->Tarif,
                'Jumlah' => $pajak->Jumlah,
            ])->all()),
            'Pembulatan' => $p->Pembulatan,
            'TotalAkhir' => $p->TotalAkhir,
            'Pembayaran' => array_values(PenjualanPembayaran::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get()->map(fn (PenjualanPembayaran $b): array => [
                'NamaMetode' => $b->NamaMetode,
                'Jumlah' => $b->Jumlah,
            ])->all()),
            'Kembalian' => $p->Kembalian,
            'TotalRetur' => $totalRetur?->KeString(),
            // F-16c bagian 4a: poin pasti dihitung server (termasuk pengali tier & promo poin berlipat).
            'PoinDiperoleh' => $p->IdPelanggan === null || $p->Status === StatusPenjualan::Void ? null : $this->poin->AmbilPerolehanPenjualan($p->Id),
            // D-110: tautan toko online outlet ini bila sedang menerima pesanan, supaya pembeli bisa memesan lagi dari struk.
            'TokoOnline' => $this->AmbilTautanTokoOnline($idTenant, $outlet?->uuidOutlet),
            'CatatanKaki' => $struk->catatanKaki,
            'TeksPenutup' => $struk->teksPenutup,
            'Laundry' => $laundry,
        ];
    }

    private function AmbilTautanTokoOnline(int $idTenant, ?string $uuidOutlet): ?string
    {
        if ($uuidOutlet === null || $this->tokoOnline->Cari($uuidOutlet)?->aktif !== true) {
            return null;
        }

        return url('/'.$this->profil->AmbilSlug($idTenant)).'?outlet='.$uuidOutlet;
    }
}
