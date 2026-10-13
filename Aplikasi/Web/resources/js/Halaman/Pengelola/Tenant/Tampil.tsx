import { Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import {
    FormAktifkan,
    FormCabutOverride,
    FormCatatan,
    FormOverride,
    FormPenanda,
    FormPerpanjangTrial,
    FormTagihanManual,
    FormTangguhkan,
} from '@/Komponen/Pengelola/Tenant/FormTindakan';
import PanelAksesDukungan from '@/Komponen/Pengelola/Tenant/PanelAksesDukungan';
import PanelKesehatanTenant from '@/Komponen/Pengelola/Tenant/PanelKesehatanTenant';
import { TabPerangkatTenant, TabTagihanTenant, TabTiketTenant } from '@/Komponen/Pengelola/Tenant/TabAktivitasTenant';
import PanelAlatBantuDukungan from '@/Komponen/Pengelola/Tenant/PanelAlatBantuDukungan';
import PanelPendaftaranMerchant from '@/Komponen/Pengelola/Tenant/PanelPendaftaranMerchant';
import PanelPenghapusanData from '@/Komponen/Pengelola/Tenant/PanelPenghapusanData';
import { LabelPenanda, LabelStatusLangganan } from '@/Komponen/Pengelola/Tenant/LabelLangganan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import Panel from '@/Komponen/Kelola/Panel';
import { kelasDaftarTabPanel, kelasItemTabPanel } from '@/Komponen/Navigasi/TabTautan';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Komponen/Ui/tabs';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';
import { labelBatas, type AturanTenant, type PilihanTenant, type Tampilan360 } from '@/Tipe/TenantPengelola';

type PropsTampil = { Tenant: Tampilan360; Pilihan: PilihanTenant; Aturan: AturanTenant };

type Tindakan =
    | 'trial'
    | 'override'
    | 'tagihan-manual'
    | 'tangguhkan'
    | 'aktifkan'
    | 'penanda'
    | { cabut: string; kunci: string }
    | null;

const labelAksi: Record<string, string> = {
    'tenant.trial.perpanjang': 'Perpanjang trial',
    'tenant.override.buat': 'Buat override',
    'tenant.override.cabut': 'Cabut override',
    'tenant.tangguhkan': 'Tangguhkan',
    'tenant.aktifkan': 'Aktifkan kembali',
    'tenant.catatan.tulis': 'Tulis catatan',
    'tenant.penanda.ubah': 'Ubah penanda',
    'tenant.penghapusan-data-laksana': 'Laksanakan penghapusan data',
    'tenant.penghapusan-data-tolak': 'Tolak penghapusan data',
    'tenant.merchant.segarkan': 'Segarkan status merchant DOKU',
    'tenant.merchant.penampung-qris': 'Isi ID QRIS merchant',
};

const kolomPemakaian: KolomTabel<Tampilan360['Pemakaian'][number]>[] = [
    {
        id: 'Label',
        accessorKey: 'Label',
        header: 'Sumber daya',
        meta: { label: 'Sumber daya', prioritas: 'utama', wajib: true },
    },
    {
        id: 'Pakai',
        accessorKey: 'Pakai',
        header: 'Terpakai',
        meta: { label: 'Terpakai', angka: true, prioritas: 'penting' },
    },
    {
        id: 'Batas',
        header: 'Batas',
        enableSorting: false,
        meta: { label: 'Batas', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: baris } }) => (
            <>
                {baris.Batas === null ? 'Tak terbatas' : baris.Batas}
                {baris.Batas !== null && baris.Pakai > baris.Batas ? (
                    <span className="ml-2">
                        <LabelStatus jenis="peringatan" teks="Melebihi batas" />
                    </span>
                ) : null}
            </>
        ),
    },
];

const kolomOverride: KolomTabel<Tampilan360['Override'][number]>[] = [
    {
        id: 'Jenis',
        accessorKey: 'Jenis',
        header: 'Jenis',
        meta: { label: 'Jenis', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: baris } }) => (
            <>
                <span className="block">{baris.Jenis}</span>
                <LabelStatus jenis={baris.Aktif ? 'sukses' : 'netral'} teks={baris.Aktif ? 'Berlaku' : 'Berakhir'} />
            </>
        ),
    },
    {
        id: 'Isi',
        header: 'Isi',
        enableSorting: false,
        meta: { label: 'Isi', prioritas: 'penting' },
        cell: ({ row: { original: baris } }) =>
            baris.Jenis === 'Batas' ? (
                `${labelBatas[baris.Kunci] ?? baris.Kunci}: ${baris.Nilai ?? '—'}`
            ) : baris.Jenis === 'Trial' ? (
                `+${baris.Nilai ?? '?'} hari`
            ) : (
                <span className="font-mono text-label">{baris.Kunci}</span>
            ),
    },
    {
        id: 'BerakhirPada',
        accessorKey: 'BerakhirPada',
        header: 'Berakhir',
        meta: { label: 'Berakhir', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.BerakhirPada),
    },
    {
        id: 'Aktif',
        accessorKey: 'Aktif',
        header: 'Alasan',
        enableSorting: false,
        meta: { label: 'Alasan', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: baris } }) => (
            <>
                {baris.Alasan}
                <span className="block text-keterangan">
                    {baris.DibuatOleh} | {FormatTanggalWaktu(baris.DibuatPada)}
                </span>
            </>
        ),
    },
];

/** Tampilan 360° dasar tenant & tindakan pengelola (P-07). Semua tindakan tercatat di riwayat (BR-P07.3). */
export default function Tampil({ Tenant, Pilihan, Aturan }: PropsTampil) {
    const { Tagihan, Tiket, Perangkat } = Tenant;
    const { props } = usePage<PropsBersamaPengelola>();
    const pengguna = props.Pengguna;
    const [tindakan, AturTindakan] = useState<Tindakan>(null);
    const { Profil, Langganan } = Tenant;
    const Tutup = () => AturTindakan(null);
    const status = Langganan?.Status ?? null;

    const tombol: { kunci: Exclude<Tindakan, null | object>; label: string; boleh: boolean; varian?: 'bahaya' }[] = [
        {
            kunci: 'trial',
            label: 'Perpanjang trial',
            boleh:
                PunyaIzin(pengguna, IzinPengelola.TenantTrialPerpanjang) &&
                status === 'Trial' &&
                (Langganan?.SisaPerpanjanganTrial ?? 0) > 0,
        },
        {
            kunci: 'override',
            label: 'Override sementara',
            boleh: PunyaIzin(pengguna, IzinPengelola.TenantOverrideKelola),
        },
        {
            kunci: 'aktifkan',
            label: 'Aktifkan kembali',
            boleh: PunyaIzin(pengguna, IzinPengelola.TenantAktifkan) && Langganan?.BisaDiaktifkan === true,
        },
        {
            kunci: 'tagihan-manual',
            label: 'Terbitkan tagihan manual',
            boleh:
                PunyaIzin(pengguna, IzinPengelola.TagihanTerbitkan) &&
                Pilihan.PaketNegosiasi.length > 0 &&
                status !== null &&
                status !== 'Berhenti',
        },
        { kunci: 'penanda', label: 'Ubah penanda', boleh: PunyaIzin(pengguna, IzinPengelola.TenantPenandaUbah) },
        {
            kunci: 'tangguhkan',
            label: 'Tangguhkan',
            varian: 'bahaya',
            boleh:
                PunyaIzin(pengguna, IzinPengelola.TenantTangguhkan) &&
                status !== null &&
                !['Ditangguhkan', 'Berhenti'].includes(status),
        },
    ];
    const tombolTerlihat = tombol.filter((item) => item.boleh);

    return (
        <TataLetakPengelola judul={Profil.Nama} jejak={[{ label: 'Semua tenant', href: '/tenant' }]}>
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-label text-teks-sekunder">{Profil.Slug}</span>
                <LabelStatusLangganan status={status} />
                <LabelPenanda penanda={Profil.Penanda} />
            </div>

            {status === 'Ditangguhkan' ? (
                <Pemberitahuan jenis="peringatan" judul="Tenant ditangguhkan">
                    POS terkunci; Owner hanya bisa masuk, melihat laporan, mengekspor data, dan membayar. Alasan ada di
                    riwayat tindakan.
                    {Langganan?.StatusSebelumDitangguhkan === null ? (
                        <>
                            {' '}
                            Penangguhan ini karena tagihan belum dibayar: tenant aktif kembali otomatis saat
                            pembayarannya diterima di menu Tagihan.
                        </>
                    ) : null}
                </Pemberitahuan>
            ) : null}

            {Profil.Status === 'Ditutup' ? (
                <Pemberitahuan jenis="info" judul="Akun ditutup">
                    Data pribadi tenant ini sudah dihapus dan identitasnya dianonimkan atas permintaan Owner (UU PDP).
                    Transaksi dan pembukuan tetap disimpan sesuai ketentuan perpajakan. Akun tidak bisa dibuka kembali.
                </Pemberitahuan>
            ) : null}

            {tombolTerlihat.length > 0 ? (
                <div className="flex flex-wrap gap-2" role="group" aria-label="Tindakan pada tenant">
                    {tombolTerlihat.map((item) => (
                        <Tombol
                            key={item.kunci}
                            varian={item.varian ?? 'sekunder'}
                            onClick={() => AturTindakan(item.kunci)}
                        >
                            {item.label}
                        </Tombol>
                    ))}
                </div>
            ) : null}

            {tindakan === 'trial' && Langganan ? (
                <FormPerpanjangTrial uuid={Profil.Uuid} langganan={Langganan} aturan={Aturan} saatSelesai={Tutup} />
            ) : null}
            {tindakan === 'override' ? (
                <FormOverride
                    uuid={Profil.Uuid}
                    pilihanFitur={Pilihan.Fitur}
                    kolomBatas={Pilihan.KolomBatas}
                    aturan={Aturan}
                    saatSelesai={Tutup}
                />
            ) : null}
            {tindakan === 'tagihan-manual' ? (
                <FormTagihanManual uuid={Profil.Uuid} paket={Pilihan.PaketNegosiasi} saatSelesai={Tutup} />
            ) : null}
            {tindakan === 'tangguhkan' ? (
                <FormTangguhkan uuid={Profil.Uuid} pilihanKategori={Pilihan.KategoriPenangguhan} saatSelesai={Tutup} />
            ) : null}
            {tindakan === 'aktifkan' ? (
                <FormAktifkan
                    uuid={Profil.Uuid}
                    statusTujuan={Langganan?.StatusSetelahDiaktifkan ?? null}
                    saatSelesai={Tutup}
                />
            ) : null}
            {tindakan === 'penanda' ? (
                <FormPenanda
                    uuid={Profil.Uuid}
                    penandaSekarang={Profil.Penanda}
                    pilihanPenanda={Pilihan.Penanda}
                    saatSelesai={Tutup}
                />
            ) : null}
            {tindakan !== null && typeof tindakan === 'object' ? (
                <FormCabutOverride
                    uuid={Profil.Uuid}
                    uuidOverride={tindakan.cabut}
                    kunci={tindakan.kunci}
                    saatSelesai={Tutup}
                />
            ) : null}

            <Tabs defaultValue="ringkasan" className="gap-4">
                <div className="overflow-x-auto">
                    <TabsList aria-label="Bagian tampilan tenant" className={kelasDaftarTabPanel}>
                        <TabsTrigger value="ringkasan" className={kelasItemTabPanel}>
                            Ringkasan
                        </TabsTrigger>
                        <TabsTrigger value="override" className={kelasItemTabPanel}>
                            Override & trial ({Tenant.Override.length})
                        </TabsTrigger>
                        <TabsTrigger value="catatan" className={kelasItemTabPanel}>
                            Catatan & riwayat
                        </TabsTrigger>
                        {Tagihan !== null ? (
                            <TabsTrigger value="tagihan" className={kelasItemTabPanel}>
                                Tagihan ({Tagihan.JumlahTerbuka})
                            </TabsTrigger>
                        ) : null}
                        {Tiket !== null ? (
                            <TabsTrigger value="tiket" className={kelasItemTabPanel}>
                                Tiket ({Tiket.JumlahTerbuka})
                            </TabsTrigger>
                        ) : null}
                        <TabsTrigger value="perangkat" className={kelasItemTabPanel}>
                            Perangkat ({Perangkat.JumlahAktif})
                        </TabsTrigger>
                    </TabsList>
                </div>

                <TabsContent value="ringkasan" className="grid gap-4 lg:grid-cols-2">
                    <Panel judul="Profil usaha">
                        <DaftarNilai
                            isi={[
                                ['Nama usaha', Profil.Nama],
                                ['Slug', <span className="font-mono">{Profil.Slug}</span>],
                                [
                                    'NPWP',
                                    Profil.Npwp ? <span className="font-mono">{Profil.Npwp}</span> : 'Belum diisi',
                                ],
                                ['PKP', Profil.Pkp ? 'Ya' : 'Tidak'],
                                ['Zona waktu', Profil.ZonaWaktu],
                                [
                                    'Sektor',
                                    Profil.TemplateSektor.length > 0
                                        ? Profil.TemplateSektor.join(', ')
                                        : 'Belum memilih template sektor',
                                ],
                                ['Terdaftar', FormatTanggalWaktu(Profil.DibuatPada)],
                                [
                                    'Mitra perujuk',
                                    Tenant.MitraPerujuk === null ? (
                                        'Mendaftar langsung'
                                    ) : PunyaIzin(pengguna, IzinPengelola.MitraLihat) ? (
                                        <Link
                                            href={`/mitra/${Tenant.MitraPerujuk.Uuid}`}
                                            className="font-semibold text-brand underline"
                                        >
                                            {Tenant.MitraPerujuk.Nama} ({Tenant.MitraPerujuk.Kode})
                                        </Link>
                                    ) : (
                                        `${Tenant.MitraPerujuk.Nama} (${Tenant.MitraPerujuk.Kode})`
                                    ),
                                ],
                            ]}
                        />
                    </Panel>

                    <PanelKesehatanTenant kesehatan={Tenant.Kesehatan} />

                    <Panel judul="Paket & langganan">
                        {Langganan === null ? (
                            <p className="text-isi text-teks-sekunder">Tenant ini belum punya langganan.</p>
                        ) : (
                            <DaftarNilai
                                isi={[
                                    [
                                        'Paket',
                                        <>
                                            {Langganan.NamaPaket}{' '}
                                            <span className="font-mono">({Langganan.KodePaket})</span>
                                        </>,
                                    ],
                                    ['Status', Langganan.Status],
                                    ['Trial berakhir', FormatTanggalWaktu(Langganan.TrialBerakhirPada)],
                                    [
                                        'Perpanjangan trial',
                                        `${Langganan.PerpanjanganTrial} dari ${Aturan.MaksKaliTrial} kali`,
                                    ],
                                    [
                                        'Periode',
                                        `${FormatTanggalWaktu(Langganan.PeriodeMulai)} – ${FormatTanggalWaktu(Langganan.PeriodeSelesai)}`,
                                    ],
                                    ['Siklus tagihan', Langganan.SiklusTagihan],
                                    [
                                        'Add-on',
                                        Tenant.Addon.length === 0
                                            ? 'Tidak ada'
                                            : Tenant.Addon.map(
                                                  (a) =>
                                                      `${a.Nama}${a.Jumlah > 1 ? ` × ${a.Jumlah}` : ''} (${
                                                          a.Aktif ? (a.Berhenti ? 'berhenti' : 'aktif') : 'berakhir'
                                                      } ${FormatTanggalWaktu(a.SelesaiPada)})`,
                                              ).join('; '),
                                    ],
                                    ...(Langganan.StatusSebelumDitangguhkan
                                        ? ([['Status sebelum ditangguhkan', Langganan.StatusSebelumDitangguhkan]] as [
                                              string,
                                              ReactNode,
                                          ][])
                                        : []),
                                ]}
                            />
                        )}
                    </Panel>

                    <div className="lg:col-span-2">
                        <PanelPenghapusanData
                            uuidTenant={Profil.Uuid}
                            slug={Profil.Slug}
                            permintaan={Tenant.PenghapusanData}
                            boleh={PunyaIzin(pengguna, IzinPengelola.TenantPenghapusanDataKelola)}
                        />
                    </div>

                    <div className="lg:col-span-2">
                        <PanelAksesDukungan
                            uuidTenant={Profil.Uuid}
                            data={Tenant.AksesDukungan}
                            bolehMasuk={PunyaIzin(pengguna, IzinPengelola.DukunganAksesMasuk)}
                            bolehDarurat={PunyaIzin(pengguna, IzinPengelola.DukunganAksesDarurat)}
                        />
                    </div>

                    {PunyaIzin(pengguna, IzinPengelola.DukunganAlatPakai) ? (
                        <div className="lg:col-span-2">
                            <PanelAlatBantuDukungan uuidTenant={Profil.Uuid} data={Tenant.AksesDukungan} />
                        </div>
                    ) : null}

                    <PanelPendaftaranMerchant
                        uuidTenant={Profil.Uuid}
                        data={Tenant.PendaftaranMerchant}
                        bolehKelola={PunyaIzin(pengguna, IzinPengelola.IntegrasiKelola)}
                    />

                    <Panel judul="Pemakaian vs batas">
                        <TabelData
                            id="pengelola-tenant-pemakaian"
                            label="Pemakaian dibanding batas efektif (paket + override)"
                            kolom={kolomPemakaian}
                            sumber={{ mode: 'lokal', data: Tenant.Pemakaian }}
                            ambilIdBaris={(baris) => baris.Label}
                            kosong={{ judul: 'Belum ada data pemakaian.' }}
                        />
                    </Panel>

                    <Panel judul="Outlet & gudang">
                        <p className="text-isi text-teks-sekunder">
                            {Tenant.Organisasi.Outlet.length} outlet | {Tenant.Organisasi.JumlahGudang} gudang |{' '}
                            {Tenant.Organisasi.JumlahMerek} merek
                        </p>
                        {Tenant.Organisasi.Outlet.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">Belum ada outlet.</p>
                        ) : (
                            <ul className="flex flex-col gap-1 text-isi">
                                {Tenant.Organisasi.Outlet.map((outlet) => (
                                    <li key={outlet.Kode}>
                                        <span className="font-mono text-label">{outlet.Kode}</span> {outlet.Nama}
                                        {outlet.TemplateSektor ? (
                                            <span className="text-teks-sekunder"> | {outlet.TemplateSektor}</span>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel judul="Anggota">
                        {Tenant.Anggota.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">Belum ada anggota.</p>
                        ) : (
                            <ul className="flex flex-col gap-3 text-isi">
                                {Tenant.Anggota.map((anggota) => (
                                    <li key={anggota.Email} className="flex flex-col gap-1">
                                        <span className="flex flex-wrap items-center gap-2 font-semibold text-teks-utama">
                                            {anggota.Nama}
                                            {anggota.Pemilik ? <LabelStatus jenis="netral" teks="Owner" /> : null}
                                            {anggota.Status !== 'Aktif' ? (
                                                <LabelStatus jenis="netral" teks={anggota.Status} />
                                            ) : null}
                                        </span>
                                        <span className="break-all text-teks-sekunder">
                                            {anggota.Email} |{' '}
                                            {anggota.EmailTerverifikasi
                                                ? 'email terverifikasi'
                                                : 'email belum diverifikasi'}
                                        </span>
                                        {anggota.NoHp ? (
                                            <span className="font-mono text-label text-teks-sekunder">
                                                {anggota.NoHp}
                                            </span>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel judul="Persetujuan legal">
                        {Tenant.PersetujuanLegal.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">
                                Belum ada persetujuan dokumen legal yang tercatat.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-1 text-isi">
                                {Tenant.PersetujuanLegal.map((baris) => (
                                    <li key={`${baris.Jenis}-${String(baris.Versi)}-${baris.DisetujuiPada}`}>
                                        {baris.Jenis} versi {baris.Versi ?? '—'}
                                        <span className="text-teks-sekunder">
                                            {' '}
                                            | {baris.Pengguna} | {FormatTanggalWaktu(baris.DisetujuiPada)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </TabsContent>

                <TabsContent value="override">
                    <Panel judul="Override & perpanjangan trial">
                        <TabelData
                            id="pengelola-tenant-override"
                            label="Override tenant, terbaru di atas"
                            kolom={[
                                ...kolomOverride,
                                {
                                    id: 'Tindakan',
                                    header: () => <span className="sr-only">Tindakan</span>,
                                    enableSorting: false,
                                    meta: {
                                        label: 'Tindakan',
                                        prioritas: 'penting',
                                        wajib: true,
                                        kelasSel: 'text-right',
                                    },
                                    cell: ({ row: { original: baris } }) =>
                                        baris.Aktif &&
                                        baris.Jenis !== 'Trial' &&
                                        PunyaIzin(pengguna, IzinPengelola.TenantOverrideKelola) ? (
                                            <Tombol
                                                varian="sekunder"
                                                onClick={() => AturTindakan({ cabut: baris.Uuid, kunci: baris.Kunci })}
                                            >
                                                Cabut
                                            </Tombol>
                                        ) : null,
                                },
                            ]}
                            sumber={{ mode: 'lokal', data: Tenant.Override }}
                            ambilIdBaris={(baris) => baris.Uuid}
                            saring={[{ id: 'Aktif', label: 'Hanya yang berlaku', jenis: 'ya' }]}
                            kosong={{ judul: 'Belum pernah ada override atau perpanjangan trial.' }}
                        />
                    </Panel>
                </TabsContent>

                <TabsContent value="catatan" className="grid gap-4 lg:grid-cols-2">
                    <Panel judul="Catatan internal">
                        {PunyaIzin(pengguna, IzinPengelola.TenantCatatanTulis) ? (
                            <FormCatatan uuid={Profil.Uuid} />
                        ) : null}
                        {Tenant.Catatan.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">Belum ada catatan internal.</p>
                        ) : (
                            <ul className="flex flex-col gap-3 text-isi">
                                {Tenant.Catatan.map((catatan) => (
                                    <li key={catatan.Uuid} className="border-t border-garis pt-3">
                                        <p className="whitespace-pre-line text-teks-utama">{catatan.Isi}</p>
                                        <p className="text-keterangan text-teks-sekunder">
                                            {catatan.Penulis} | {FormatTanggalWaktu(catatan.DibuatPada)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel judul="Riwayat tindakan">
                        {Tenant.Riwayat.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">Belum ada tindakan pengelola pada tenant ini.</p>
                        ) : (
                            <ol className="flex flex-col gap-3 text-isi">
                                {Tenant.Riwayat.map((log) => (
                                    <li key={log.Id} className="border-t border-garis pt-3">
                                        <p className="font-semibold text-teks-utama">
                                            {labelAksi[log.Aksi] ?? log.Aksi}{' '}
                                            <span className="font-mono text-keterangan font-normal text-teks-sekunder">
                                                {log.Aksi}
                                            </span>
                                        </p>
                                        {log.Alasan ? <p className="text-teks-sekunder">Alasan: {log.Alasan}</p> : null}
                                        {log.NilaiBaru ? (
                                            <p className="break-all text-keterangan text-teks-sekunder">
                                                <code className="font-mono">{JSON.stringify(log.NilaiBaru)}</code>
                                            </p>
                                        ) : null}
                                        <p className="text-keterangan text-teks-sekunder">
                                            {log.Pelaku} | {FormatTanggalWaktu(log.DibuatPada)}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Panel>
                </TabsContent>

                {Tagihan !== null ? (
                    <TabsContent value="tagihan">
                        <TabTagihanTenant tagihan={Tagihan} />
                    </TabsContent>
                ) : null}
                {Tiket !== null ? (
                    <TabsContent value="tiket">
                        <TabTiketTenant tiket={Tiket} />
                    </TabsContent>
                ) : null}
                <TabsContent value="perangkat">
                    <TabPerangkatTenant perangkat={Perangkat} />
                </TabsContent>
            </Tabs>
        </TataLetakPengelola>
    );
}

function DaftarNilai({ isi }: { isi: [string, ReactNode][] }) {
    return (
        <dl className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-x-3 gap-y-2 text-isi">
            {isi.map(([label, nilai]) => (
                <div key={label} className="contents">
                    <dt className="text-teks-sekunder">{label}</dt>
                    <dd className="break-words text-teks-utama">{nilai}</dd>
                </div>
            ))}
        </dl>
    );
}
