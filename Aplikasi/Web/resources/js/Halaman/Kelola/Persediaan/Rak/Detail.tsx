import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import Panel from '@/Komponen/Kelola/Panel';
import { Keterangan } from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import PemilihProdukStok, { type ProdukStokTerpilih } from '@/Komponen/Persediaan/PemilihProdukStok';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisProdukRak, PropsDetailRakGudang } from '@/Tipe/DokumenPersediaan';

const alamat = '/kelola/persediaan/rak';

const kolom: KolomTabel<BarisProdukRak>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{p.Nama}</span>
                <span className="block font-mono text-keterangan text-teks-sekunder">{p.Sku ?? 'Tanpa SKU'}</span>
            </>
        ),
    },
    {
        id: 'SimbolSatuan',
        accessorKey: 'SimbolSatuan',
        header: 'Satuan',
        enableSorting: false,
        meta: { label: 'Satuan', prioritas: 'rendah' },
    },
];

function AksiLepas({ konteks, uuidRak }: { konteks: KonteksAksiMassal<BarisProdukRak>; uuidRak: string }) {
    const [memproses, AturMemproses] = useState(false);

    return (
        <Tombol
            varian="bahaya"
            memproses={memproses}
            onClick={() =>
                router.delete(`${alamat}/${uuidRak}/produk`, {
                    data: { UuidProduk: konteks.terpilih.map((p) => p.Uuid) },
                    preserveScroll: true,
                    onStart: () => AturMemproses(true),
                    onFinish: () => AturMemproses(false),
                    onSuccess: () => konteks.bersihkan(),
                })
            }
        >
            Lepas dari rak
        </Tombol>
    );
}

/**
 * F-05c INV-07: isi satu rak. Produk ditempatkan satu per satu (cari nama, SKU, atau pindai barcode) atau satu kategori
 * sekaligus; satu produk hanya di satu rak per lokasi stok, jadi menempatkan produk yang sudah di rak lain
 * memindahkannya. Isi rak dibekukan selama opname per rak itu berjalan.
 */
export default function HalamanDetailRakGudang({ Produk, Rak, OpsiKategori, Izin }: PropsDetailRakGudang) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [kategori, AturKategori] = useState('');
    const [memproses, AturMemproses] = useState(false);

    if (Rak === null) {
        return null;
    }

    const bolehUbah = Izin.Kelola && Rak.Aktif && Rak.OpnameBerjalan === null;
    const Tetapkan = (data: { UuidProduk?: string[]; UuidKategori?: string }) =>
        router.post(`${alamat}/${Rak.Uuid}/produk`, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturKategori(''),
        });

    return (
        <TataLetakAplikasi judul={`Rak ${Rak.Nama}`} jejak={[{ label: 'Rak gudang', href: alamat }]}>
            {!Izin.Kelola ? <PesanHanyaLihat izin="persediaan.kelola" objek="isi rak" /> : null}
            {Rak.OpnameBerjalan ? (
                <Pemberitahuan jenis="info">
                    Rak ini sedang diopname ({Rak.OpnameBerjalan}). Isi rak tidak bisa diubah sampai opname selesai atau
                    dibatalkan.
                </Pemberitahuan>
            ) : null}
            {!Rak.Aktif ? (
                <Pemberitahuan jenis="peringatan">Rak nonaktif. Aktifkan dulu untuk mengubah isinya.</Pemberitahuan>
            ) : null}
            <DaftarGalatServer galat={galat} />

            <Panel judul="Ringkasan" idJudul="judul-ringkasan-rak">
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Keterangan label="Kode">
                        <span className="font-mono">{Rak.Kode}</span>
                    </Keterangan>
                    <Keterangan label="Lokasi stok">
                        {Rak.NamaGudang}
                        {Rak.NamaOutlet ? (
                            <span className="block text-keterangan text-teks-sekunder">{Rak.NamaOutlet}</span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Produk">{Rak.JumlahProduk.toLocaleString('id-ID')}</Keterangan>
                    <Keterangan label="Status">
                        <LabelStatus jenis={Rak.Aktif ? 'sukses' : 'netral'} teks={Rak.Aktif ? 'Aktif' : 'Nonaktif'} />
                    </Keterangan>
                </dl>
            </Panel>

            {bolehUbah ? (
                <Panel judul="Tempatkan produk" idJudul="judul-tempatkan-produk">
                    <div className="grid gap-4 md:grid-cols-2">
                        <PemilihProdukStok
                            label="Cari produk"
                            uuidGudang={Rak.UuidGudang}
                            disabled={memproses}
                            keterangan="Produk yang sudah di rak lain dipindahkan ke rak ini."
                            saatPilih={(produk: ProdukStokTerpilih) => Tetapkan({ UuidProduk: [produk.Uuid] })}
                        />
                        <div className="flex flex-col gap-2">
                            <BidangPilihan
                                label="Atau satu kategori sekaligus"
                                nilai={kategori}
                                kosong="Pilih kategori"
                                opsi={OpsiKategori.map((k) => ({ Nilai: k.Uuid, Label: k.Jalur }))}
                                saatBerubah={AturKategori}
                            />
                            <div>
                                <Tombol
                                    varian="sekunder"
                                    disabled={kategori === ''}
                                    memproses={memproses}
                                    onClick={() => Tetapkan({ UuidKategori: kategori })}
                                >
                                    Tempatkan semua produk kategori
                                </Tombol>
                            </div>
                        </div>
                    </div>
                </Panel>
            ) : null}

            <Panel judul="Isi rak" idJudul="judul-isi-rak">
                <TabelData
                    id="persediaan-rak-produk"
                    label={`Produk di rak ${Rak.Nama}`}
                    kolom={kolom}
                    sumber={{ mode: 'server', alamat: `${alamat}/${Rak.Uuid}`, awal: Produk }}
                    ambilIdBaris={(p) => p.Uuid}
                    cari="Cari nama, SKU, atau barcode"
                    pindaiKamera
                    kosong={{ judul: 'Rak ini belum berisi produk.' }}
                    {...(bolehUbah
                        ? {
                              aksiMassal: (konteks: KonteksAksiMassal<BarisProdukRak>) => (
                                  <AksiLepas konteks={konteks} uuidRak={Rak.Uuid} />
                              ),
                          }
                        : {})}
                />
            </Panel>
        </TataLetakAplikasi>
    );
}
