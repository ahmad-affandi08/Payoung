import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisArmada, PropsDaftarArmada } from '@/Tipe/Grosir';

const alamat = '/kelola/grosir/armada';

const kolom: KolomTabel<BarisArmada>[] = [
    {
        id: 'Nama',
        accessorFn: (a) => `${a.Nama} ${a.NomorKendaraan}`,
        header: 'Armada',
        meta: { label: 'Armada', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: a } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{a.Nama}</span>
                <span className="block font-mono text-keterangan text-teks-sekunder">{a.NomorKendaraan}</span>
            </>
        ),
    },
    {
        id: 'NamaPengemudi',
        accessorKey: 'NamaPengemudi',
        header: 'Pengemudi bawaan',
        meta: { label: 'Pengemudi bawaan', prioritas: 'penting' },
        cell: ({ row }) => row.original.NamaPengemudi ?? '—',
    },
    {
        id: 'Keterangan',
        accessorKey: 'Keterangan',
        header: 'Keterangan',
        meta: { label: 'Keterangan', prioritas: 'rendah' },
        cell: ({ row }) => row.original.Keterangan ?? '—',
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: a } }) => (
            <LabelStatus jenis={a.Aktif ? 'sukses' : 'netral'} teks={a.Aktif ? 'Aktif' : 'Nonaktif'} />
        ),
    },
];

type IsianArmada = {
    Uuid: string | null;
    Nama: string;
    NomorKendaraan: string;
    NamaPengemudi: string;
    Keterangan: string;
};

/**
 * Armada pengiriman grosir (F-12 §9.7): kendaraan yang membawa surat jalan. Dipilih saat barang berangkat; armada yang
 * tidak dipakai lagi dinonaktifkan (bukan dihapus) supaya surat jalan lama tetap menunjuk armada yang benar. Halaman ini
 * hidup di Pengaturan (diatur sekali, jarang disentuh).
 */
export default function HalamanDaftarArmada({ Armada, Izin }: PropsDaftarArmada) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [isian, AturIsian] = useState<IsianArmada | null>(null);
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);

    const Simpan = () => {
        AturPeriksa(true);

        if (isian === null || isian.Nama.trim() === '' || isian.NomorKendaraan.trim() === '') {
            return;
        }

        const data = {
            Nama: isian.Nama.trim(),
            NomorKendaraan: isian.NomorKendaraan.trim(),
            NamaPengemudi: isian.NamaPengemudi.trim() === '' ? null : isian.NamaPengemudi.trim(),
            Keterangan: isian.Keterangan.trim() === '' ? null : isian.Keterangan.trim(),
        };
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturIsian(null),
        };

        if (isian.Uuid === null) {
            router.post(alamat, data, opsi);
        } else {
            router.put(`${alamat}/${isian.Uuid}`, data, opsi);
        }
    };

    const tombolTambah = Izin.Kelola ? (
        <Tombol
            onClick={() => {
                AturPeriksa(false);
                AturIsian({ Uuid: null, Nama: '', NomorKendaraan: '', NamaPengemudi: '', Keterangan: '' });
            }}
        >
            Tambah armada
        </Tombol>
    ) : null;

    return (
        <TataLetakAplikasi judul="Armada pengiriman">
            <p className="max-w-2xl text-isi text-teks-sekunder">
                Kendaraan yang membawa surat jalan grosir. Saat barang berangkat, pilih armadanya supaya nomor kendaraan
                dan pengemudinya ikut tercetak di surat jalan.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="grosir.kelola" objek="armada pengiriman" /> : null}
            {isian === null ? <DaftarGalatServer galat={galat} /> : null}
            <AksiHalaman>{tombolTambah}</AksiHalaman>
            <TabelData
                id="grosir-armada"
                label="Daftar armada pengiriman"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Armada }}
                ambilIdBaris={(a) => a.Uuid}
                cari="Cari nama atau nomor kendaraan"
                kosong={{ ilustrasi: true, judul: 'Belum ada armada.' }}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (a: BarisArmada) => (
                              <ItemAksiBaris
                                  aksi={[
                                      {
                                          label: 'Ubah armada',
                                          saatPilih: () => {
                                              AturPeriksa(false);
                                              AturIsian({
                                                  Uuid: a.Uuid,
                                                  Nama: a.Nama,
                                                  NomorKendaraan: a.NomorKendaraan,
                                                  NamaPengemudi: a.NamaPengemudi ?? '',
                                                  Keterangan: a.Keterangan ?? '',
                                              });
                                          },
                                      },
                                      a.Aktif
                                          ? {
                                                label: 'Nonaktifkan',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${alamat}/${a.Uuid}/nonaktifkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            }
                                          : {
                                                label: 'Aktifkan kembali',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${alamat}/${a.Uuid}/aktifkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            },
                                  ]}
                              />
                          ),
                      }
                    : {})}
            />

            {isian !== null ? (
                <DialogFormulir
                    judul={isian.Uuid === null ? 'Tambah armada' : 'Ubah armada'}
                    keterangan="Nomor kendaraan harus unik. Dipakai di surat jalan."
                    galatUmum={galat.Umum}
                    saatTutup={() => AturIsian(null)}
                >
                    <div className="flex flex-col gap-3">
                        <DaftarGalatServer
                            galat={galat}
                            kecuali={['Umum', 'Nama', 'NomorKendaraan', 'NamaPengemudi', 'Keterangan']}
                        />
                        <BidangTeks
                            label="Nama armada"
                            nilai={isian.Nama}
                            saatBerubah={(nilai) => AturIsian({ ...isian, Nama: nilai })}
                            maxLength={100}
                            required
                            galat={galat.Nama ?? (periksa && isian.Nama.trim() === '' ? 'Isi nama armada.' : undefined)}
                        />
                        <BidangTeks
                            label="Nomor kendaraan"
                            nilai={isian.NomorKendaraan}
                            saatBerubah={(nilai) => AturIsian({ ...isian, NomorKendaraan: nilai })}
                            kode
                            maxLength={30}
                            required
                            galat={
                                galat.NomorKendaraan ??
                                (periksa && isian.NomorKendaraan.trim() === '' ? 'Isi nomor kendaraan.' : undefined)
                            }
                        />
                        <BidangTeks
                            label="Pengemudi bawaan"
                            nilai={isian.NamaPengemudi}
                            saatBerubah={(nilai) => AturIsian({ ...isian, NamaPengemudi: nilai })}
                            maxLength={100}
                            galat={galat.NamaPengemudi}
                        />
                        <BidangTeks
                            label="Keterangan"
                            nilai={isian.Keterangan}
                            saatBerubah={(nilai) => AturIsian({ ...isian, Keterangan: nilai })}
                            maxLength={255}
                            galat={galat.Keterangan}
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol varian="sekunder" onClick={() => AturIsian(null)} disabled={memproses}>
                                Kembali
                            </Tombol>
                            <Tombol memproses={memproses} onClick={Simpan}>
                                Simpan armada
                            </Tombol>
                        </div>
                    </div>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
