import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import FormulirKaryawan, { AlamatKaryawan } from '@/Komponen/Karyawan/FormulirKaryawan';
import PanelAbsenHp, { JenisLabelWajah } from '@/Komponen/Karyawan/PanelAbsenHp';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisKaryawan, PropsDaftarKaryawan } from '@/Tipe/Karyawan';
import { IzinTenant, PunyaIzinTenant } from '@/Tipe/Organisasi';

function BuatKolom(lihatGaji: boolean): KolomTabel<BarisKaryawan>[] {
    return [
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Nama karyawan',
            meta: { label: 'Nama karyawan', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: k } }) => (
                <span className="flex flex-col">
                    <span className="font-semibold break-words">{k.Nama}</span>
                    {k.LevelStaf ? <span className="text-keterangan text-teks-sekunder">{k.LevelStaf}</span> : null}
                </span>
            ),
        },
        {
            id: 'Jabatan',
            accessorKey: 'Jabatan',
            header: 'Jabatan',
            meta: { label: 'Jabatan', prioritas: 'penting' },
            cell: ({ row }) => row.original.Jabatan ?? '—',
        },
        {
            id: 'Outlet',
            header: 'Outlet utama',
            enableSorting: false,
            meta: { label: 'Outlet utama', prioritas: 'rendah' },
            cell: ({ row }) => row.original.NamaOutlet ?? 'Semua outlet',
        },
        {
            id: 'Akun',
            header: 'Akun POS',
            enableSorting: false,
            meta: { label: 'Akun POS', prioritas: 'penting' },
            cell: ({ row }) =>
                row.original.NamaPengguna ?? (
                    <span className="text-teks-sekunder">Belum punya akun (absen lewat HP)</span>
                ),
        },
        ...(lihatGaji
            ? [
                  {
                      id: 'GajiPokok',
                      header: 'Gaji pokok',
                      enableSorting: false,
                      meta: { label: 'Gaji pokok', prioritas: 'rendah' as const, angka: true },
                      cell: ({ row }: { row: { original: BarisKaryawan } }) =>
                          row.original.GajiPokok ? FormatRupiah(row.original.GajiPokok) : '—',
                  },
              ]
            : []),
        {
            id: 'AbsenHp',
            header: 'Absen dari HP',
            enableSorting: false,
            meta: { label: 'Absen dari HP', prioritas: 'rendah' },
            cell: ({ row: { original: k } }) =>
                k.TautanAbsen || k.StatusWajah ? (
                    <LabelStatus
                        jenis={k.TautanAbsen ? JenisLabelWajah(k.StatusWajah) : 'netral'}
                        teks={
                            k.StatusWajah === 'Disetujui'
                                ? k.TautanAbsen
                                    ? 'Siap'
                                    : 'Wajah disetujui, tautan dicabut'
                                : k.StatusWajah === 'Menunggu'
                                  ? 'Wajah menunggu persetujuan'
                                  : k.StatusWajah === 'Ditolak'
                                    ? 'Wajah ditolak'
                                    : 'Belum daftar wajah'
                        }
                    />
                ) : (
                    <span className="text-teks-sekunder">Belum ada tautan</span>
                ),
        },
        {
            id: 'Status',
            header: 'Status',
            enableSorting: false,
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row }) => (
                <LabelStatus
                    jenis={row.original.Status === 'Aktif' ? 'sukses' : 'netral'}
                    teks={row.original.LabelStatus}
                />
            ),
        },
    ];
}

/** F-18 EMP-01: daftar karyawan; tambah (halaman penuh), ubah (panel), nonaktifkan & aktifkan. */
export default function HalamanDaftarKaryawan({ Karyawan, OpsiPengguna, OpsiOutlet, Izin }: PropsDaftarKaryawan) {
    const [ubah, AturUbah] = useState<BarisKaryawan | null>(null);
    const [absenHp, AturAbsenHp] = useState<BarisKaryawan | null>(null);
    const { props } = usePage<PropsBersamaAplikasi>();
    const bolehBuatAkun = PunyaIzinTenant(props.Akses, IzinTenant.PenggunaUndang);
    const tombol = Izin.Kelola ? (
        <Button asChild>
            <Link href={`${AlamatKaryawan}/buat`}>Tambah karyawan</Link>
        </Button>
    ) : null;

    return (
        <TataLetakAplikasi judul="Karyawan">
            <Pemberitahuan jenis="info" judul="Pengguna dan karyawan kini digabung di menu Tim">
                Satu daftar, satu formulir tambah, dan nonaktif sekaligus untuk akun &amp; jadwalnya.{' '}
                <Link href="/kelola/tim" className="font-semibold text-brand underline">
                    Buka Tim
                </Link>
                .
            </Pemberitahuan>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Karyawan dijadwalkan per outlet dan absen masuk/keluar di aplikasi kasir dengan PIN akunnya, atau dari
                HP pribadi lewat tautan absen (wajah + lokasi outlet), termasuk karyawan tanpa akun.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman>{tombol}</AksiHalaman>
            ) : (
                <PesanHanyaLihat izin="karyawan.kelola" objek="karyawan" />
            )}

            <TabelData
                id="karyawan"
                label="Daftar karyawan"
                kolom={BuatKolom(Izin.Kelola)}
                sumber={{ mode: 'server', alamat: AlamatKaryawan, awal: Karyawan }}
                ambilIdBaris={(k) => k.Uuid}
                urutBawaan="Nama"
                cari="Cari nama atau jabatan"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Nonaktif', label: 'Nonaktif' },
                        ],
                    },
                    {
                        id: 'Outlet',
                        label: 'Outlet utama',
                        jenis: 'pilihanBanyak',
                        opsi: OpsiOutlet.map((o) => ({ nilai: o.Uuid, label: o.Nama })),
                    },
                ]}
                labelBaris={(k) => `untuk karyawan ${k.Nama}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (k: BarisKaryawan) => (
                              <ItemAksiBaris
                                  aksi={[
                                      { label: 'Ubah karyawan', saatPilih: () => AturUbah(k) },
                                      { label: 'Absen dari HP', saatPilih: () => AturAbsenHp(k) },
                                      ...(k.UuidPengguna === null && k.Status === 'Aktif' && bolehBuatAkun
                                          ? [
                                                {
                                                    label: 'Buatkan akun',
                                                    saatPilih: () =>
                                                        router.visit(`/kelola/pengguna/buat?karyawan=${k.Uuid}`),
                                                },
                                            ]
                                          : []),
                                      k.Status === 'Aktif'
                                          ? {
                                                label: 'Nonaktifkan karyawan',
                                                bahaya: true,
                                                saatPilih: () =>
                                                    router.post(
                                                        `${AlamatKaryawan}/${k.Uuid}/nonaktifkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            }
                                          : {
                                                label: 'Aktifkan karyawan',
                                                saatPilih: () =>
                                                    router.post(
                                                        `${AlamatKaryawan}/${k.Uuid}/aktifkan`,
                                                        {},
                                                        { preserveScroll: true },
                                                    ),
                                            },
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada karyawan. Tambahkan di sini; staf yang absen di aplikasi kasir juga tercatat otomatis.',
                }}
            />

            {absenHp !== null ? <PanelAbsenHp karyawan={absenHp} saatTutup={() => AturAbsenHp(null)} /> : null}

            {ubah !== null ? (
                <FormulirKaryawan
                    karyawan={ubah}
                    opsiPengguna={OpsiPengguna}
                    opsiOutlet={OpsiOutlet}
                    saatTutup={() => AturUbah(null)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
