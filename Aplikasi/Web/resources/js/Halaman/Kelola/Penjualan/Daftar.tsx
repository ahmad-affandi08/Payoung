import { Link, usePage } from '@inertiajs/react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import { kolomPenjualan } from '@/Komponen/Penjualan/KolomPenjualan';
import AksiMassalEkspor from '@/Komponen/TabelData/AksiMassalEkspor';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { PakaiSektor } from '@/Pustaka/Sektor';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { IzinTenant, PunyaIzinTenant } from '@/Tipe/Organisasi';
import type { BarisPenjualan, PropsDaftarPenjualan } from '@/Tipe/Penjualan';

const alamat = '/kelola/penjualan';

/** F-07b: daftar penjualan dari aplikasi POS (baca saja). Koreksi lewat void/retur menyusul (F-09). */
export default function HalamanDaftarPenjualan({ Penjualan, OpsiOutlet, OpsiStatus, OpsiKanal }: PropsDaftarPenjualan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    // Bengkel (§9.10): grup menu ini sudah di batas 7 sub-menu (D-27), jadi perintah kerja dibuka dari sini & Ctrl+K.
    // Hanya untuk bengkel (SVC-WRK) dan toko elektronik yang menerima servis (RTL-ELC): kafe, kelontong, dsb. tidak perlu melihatnya.
    const bengkel = PunyaIzinTenant(props.Akses, IzinTenant.BengkelKelola) && PakaiSektor(['SVC-WRK', 'RTL-ELC']);
    const saring: DefinisiSaring[] = [
        ...(OpsiOutlet.length > 1
            ? [
                  {
                      id: 'Outlet',
                      label: 'Outlet',
                      jenis: 'pilihanBanyak' as const,
                      opsi: OpsiOutlet.map((o) => ({ nilai: o.Uuid, label: o.Nama })),
                  },
              ]
            : []),
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Kanal',
            label: 'Kanal',
            jenis: 'pilihanBanyak',
            opsi: OpsiKanal.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        { id: 'TanggalBisnis', label: 'Hari bisnis', jenis: 'rentangTanggal' },
        { id: 'PerluTinjauan', label: 'Perlu ditinjau', jenis: 'ya', labelAktif: 'Hanya yang perlu ditinjau' },
    ];

    return (
        <TataLetakAplikasi judul="Penjualan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Penjualan dibuat kasir di aplikasi POS, termasuk saat offline, lalu terkirim ke sini begitu perangkat
                online. Stok dan jurnal tercatat otomatis saat penjualan diterima.
            </p>
            {bengkel ? (
                <AksiHalaman>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href="/kelola/bengkel/perintah-kerja">Perintah kerja bengkel</Link>
                    </Button>
                </AksiHalaman>
            ) : null}

            <TabelData
                id="penjualan"
                label="Daftar penjualan"
                kolom={kolomPenjualan}
                sumber={{ mode: 'server', alamat, awal: Penjualan }}
                ambilIdBaris={(baris) => baris.Uuid}
                urutBawaan="-DibuatOfflinePada"
                cari="Cari nomor atau nama kasir"
                saring={saring}
                ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor', laporan: true, eksporBesar: true }}
                aksiMassal={(konteks: KonteksAksiMassal<BarisPenjualan>) => (
                    <AksiMassalEkspor konteks={konteks} alamat={`${alamat}/ekspor`} ambilUuid={(b) => b.Uuid} />
                )}
                alamatDetail={(baris) => `${alamat}/${baris.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada penjualan. Penjualan muncul di sini setelah kasir berjualan di aplikasi POS dan kasirnya tersambung internet.',
                }}
            />
        </TataLetakAplikasi>
    );
}
