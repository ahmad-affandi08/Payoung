<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pemenuhan\Enum\StatusPengirimanPesanan;
use App\Domain\Pemenuhan\Model\PengirimanPesanan;
use App\Domain\Penjualan\Enum\PeristiwaPesananOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Layanan\PemberitahuPesananOnline;
use App\Domain\Penjualan\Layanan\PemeriksaPenyelesaianPesananOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use Illuminate\Support\Facades\DB;

final class UbahStatusPengirimanPesanan
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly PemeriksaPenyelesaianPesananOnline $selesai,
        private readonly PemberitahuPesananOnline $pemberitahu,
    ) {}

    /**
     * `$idPengguna` null = pelaku di luar pengguna Payoung, yaitu kurir lewat portal kurir (v3.49); siapa kurirnya
     * tercatat di `IdKurir` pengiriman dan alasan riwayat status. `PathBukti` = foto bukti serah terima (Diterima).
     *
     * @param  array<string, mixed>  $data
     */
    public function Jalankan(PengirimanPesanan $pengiriman, PesananOnline $pesanan, StatusPengirimanPesanan $status, array $data, ?int $idPengguna): void
    {
        $punyaKurir = is_int($data['IdKurir'] ?? null);
        $punyaPenyedia = is_string($data['NamaPenyedia'] ?? null) && trim($data['NamaPenyedia']) !== '';
        $punyaResi = is_string($data['NomorResi'] ?? null) && trim($data['NomorResi']) !== '';

        if ($status === StatusPengirimanPesanan::Dikirim && ! $punyaKurir && ! ($punyaPenyedia && $punyaResi)) {
            throw new PelanggaranAturanBisnis('KurirWajib', 'Pilih kurir internal atau isi nama penyedia dan nomor resi.', 'IdKurir');
        }
        if ($status === StatusPengirimanPesanan::Diterima && (! is_string($data['NamaPenerima'] ?? null) || trim($data['NamaPenerima']) === '')) {
            throw new PelanggaranAturanBisnis('PenerimaWajib', 'Nama penerima wajib diisi.', 'NamaPenerima');
        }
        DB::transaction(function () use ($pengiriman, $pesanan, $status, $data, $idPengguna): void {
            // Pesanan dikunci lebih dulu, lalu pengiriman (urutan yang sama dengan `UbahStatusPesananOnline`), dan keduanya
            // dimuat ulang: tanpa itu kurir yang menekan Dikirim dari status basi menimpa pesanan yang baru dibatalkan staf.
            $pesananTerkunci = PesananOnline::query()->whereKey($pesanan->Id)->lockForUpdate()->firstOrFail();
            $pesanan->setRawAttributes($pesananTerkunci->getAttributes(), true);
            $pengirimanTerkunci = PengirimanPesanan::query()->whereKey($pengiriman->Id)->lockForUpdate()->firstOrFail();
            $pengiriman->setRawAttributes($pengirimanTerkunci->getAttributes(), true);

            if ($status === StatusPengirimanPesanan::Diterima && ! $this->selesai->CekSudahDibayar($pesanan)) {
                throw new PelanggaranAturanBisnis('PenjualanBelumLunas', 'Pengiriman baru dapat diterima setelah pesanan ditautkan ke penjualan yang lunas.', 'Status');
            }
            if ($status === StatusPengirimanPesanan::Diterima && $pesanan->Status !== StatusPesananOnline::Siap) {
                throw new PelanggaranAturanBisnis('PesananBelumSiap', 'Pesanan harus berstatus Siap sebelum pengiriman diselesaikan.', 'Status');
            }

            $dari = $pengiriman->Status;
            $pengiriman->UbahStatus($status);
            $pengiriman->fill(array_intersect_key($data, array_flip(['IdKurir', 'NamaPenyedia', 'NomorResi', 'NamaPenerima', 'Alasan', 'PathBukti'])));
            $pengiriman->DiubahOleh = $idPengguna;
            if ($status === StatusPengirimanPesanan::Dikemas) {
                $pengiriman->DikemasPada = now();
            }
            if ($status === StatusPengirimanPesanan::Dikirim) {
                $pengiriman->DikirimPada = now();
                // F-17 bagian 3 (v3.32): pembeli diberi tahu barangnya sudah berangkat.
                $this->pemberitahu->Antrekan($pesanan, PeristiwaPesananOnline::Dikirim);
            }
            if ($status === StatusPengirimanPesanan::Diterima) {
                $pengiriman->DiterimaPada = now();
                if ($pesanan->Status !== StatusPesananOnline::Selesai) {
                    $pesanan->UbahStatus(StatusPesananOnline::Selesai);
                    $pesanan->SelesaiPada = now();
                    $pesanan->save();
                }
            }
            $pengiriman->save();
            $this->riwayat->Catat('PengirimanPesanan', $pengiriman->Id, $dari->value, $status->value, $idPengguna, $data['Alasan'] ?? null);
            $this->audit->Catat('pengiriman.status', $pengiriman, ['Status' => $dari->value], ['Status' => $status->value], idPengguna: $idPengguna);
        });
    }
}
