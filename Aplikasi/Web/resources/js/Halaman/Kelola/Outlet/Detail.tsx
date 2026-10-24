import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import BagianMeja, { type ModeMejaOutlet } from '@/Komponen/Kelola/BagianMeja';
import FormOutlet from '@/Komponen/Kelola/FormOutlet';
import JenisPesananOutlet, { type JenisPesananOutletData } from '@/Komponen/Kelola/JenisPesananOutlet';
import KiosOutlet, { type KiosOutletData } from '@/Komponen/Kelola/KiosOutlet';
import JamOperasionalOutlet, { type JamOperasionalOutletData } from '@/Komponen/Kelola/JamOperasionalOutlet';
import LokasiAbsensiOutlet, { type LokasiAbsensiOutletData } from '@/Komponen/Kelola/LokasiAbsensiOutlet';
import type { PesanSendiriOutlet } from '@/Komponen/Kelola/PesanSendiriMeja';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Card, CardContent } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { PakaiSektor } from '@/Pustaka/Sektor';
import { IzinTenant, PunyaIzinTenant, type Kota, type Pilihan, type StatusOrganisasi } from '@/Tipe/Organisasi';

type Outlet = {
    Uuid: string;
    Kode: string;
    Nama: string;
    UuidMerek: string | null;
    Alamat: string | null;
    Telepon: string | null;
    KodeKota: string | null;
    ZonaWaktu: string;
    JamTutupBuku: string;
    Pkp: boolean;
    Nitku: string | null;
    PungutPbjt: boolean;
    Status: StatusOrganisasi;
    KodeTerkunci: boolean;
    Kanvas: boolean;
    NomorKendaraan: string | null;
};

type Gudang = { Uuid: string; Kode: string; Nama: string; Jenis: string; Status: StatusOrganisasi };

type PropsDetail = {
    Outlet: Outlet;
    Gudang: Gudang[];
    Merek: Pilihan[];
    Kota: Kota[];
    JenisGudang: Pilihan[];
    ModeMeja: ModeMejaOutlet;
    BentukMeja: Pilihan[];
    PesanSendiri: PesanSendiriOutlet;
    JenisPesanan: JenisPesananOutletData;
    LokasiAbsensi: LokasiAbsensiOutletData;
    /** F-e: jam operasional per hari; opsional supaya halaman lama tanpa data jam tetap terbuka. */
    JamOperasional?: JamOperasionalOutletData;
    /** F-17 bagian 4: kios pesan sendiri; opsional supaya halaman lama tanpa data kios tetap terbuka. */
    Kios?: KiosOutletData;
};

/** Profil outlet, lokasi stok (F-02 langkah 1–2, BR-02.2, BR-02.4), meja (F-10a), dan QR pesan sendiri (F-17). */
export default function HalamanDetailOutlet({
    Outlet,
    Gudang,
    Merek,
    Kota,
    JenisGudang,
    ModeMeja,
    BentukMeja,
    PesanSendiri,
    JenisPesanan,
    LokasiAbsensi,
    JamOperasional,
    Kios,
}: PropsDetail) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const bolehKelola = PunyaIzinTenant(props.Akses, IzinTenant.OutletKelola);
    // D-183: meja, kios, dan jenis pesanan adalah fitur F&B; sektor lain tidak melihatnya kecuali outlet ini sudah memakainya.
    const sektorFnb = PakaiSektor(['FNB']);
    // Jam operasional hanya berdampak ke toko online (pesanan di luar jam ditolak); sektor yang tidak menerima pesanan online
    // (salon, bengkel, gym, cuci kendaraan, jasa profesional) tidak memerlukannya.
    const sektorTokoOnline = PakaiSektor(['FNB', 'RTL', 'WHS', 'SVC-LDR', 'SVC-GEN']);
    const alamat = `/kelola/outlet/${Outlet.Uuid}`;
    const namaKota = Kota.find((baris) => baris.Kode === Outlet.KodeKota)?.Nama ?? 'Belum diisi';

    return (
        // D-27: jalan kembali ke daftar ikut jejak halaman di tata letak; halaman tidak merender remah roti sendiri.
        <TataLetakAplikasi judul={Outlet.Nama} jejak={[{ label: 'Semua outlet', href: '/kelola/outlet' }]}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-label text-teks-utama">{Outlet.Kode}</span>
                    {Outlet.Status === 'Aktif' ? (
                        <LabelStatus jenis="sukses" teks="Aktif" />
                    ) : (
                        <LabelStatus jenis="netral" teks="Diarsipkan" />
                    )}
                </div>
                {bolehKelola ? (
                    Outlet.Status === 'Aktif' ? (
                        <Tombol
                            varian="bahaya"
                            onClick={() => router.post(`${alamat}/arsipkan`, {}, { preserveScroll: true })}
                        >
                            Arsipkan outlet
                        </Tombol>
                    ) : (
                        <Tombol onClick={() => router.post(`${alamat}/pulihkan`, {}, { preserveScroll: true })}>
                            Pulihkan outlet
                        </Tombol>
                    )
                ) : null}
            </div>

            {bolehKelola ? (
                <FormOutlet
                    uuid={Outlet.Uuid}
                    kodeTerkunci={Outlet.KodeTerkunci}
                    awal={{
                        Nama: Outlet.Nama,
                        Kode: Outlet.Kode,
                        Merek: Outlet.UuidMerek ?? '',
                        Alamat: Outlet.Alamat ?? '',
                        Telepon: Outlet.Telepon ?? '',
                        KodeKota: Outlet.KodeKota ?? '',
                        ZonaWaktu: Outlet.ZonaWaktu,
                        JamTutupBuku: Outlet.JamTutupBuku,
                        Pkp: Outlet.Pkp,
                        Nitku: Outlet.Nitku ?? '',
                        PungutPbjt: Outlet.PungutPbjt,
                        Kanvas: Outlet.Kanvas,
                        NomorKendaraan: Outlet.NomorKendaraan ?? '',
                    }}
                    merek={Merek}
                    kota={Kota}
                />
            ) : (
                <Card className="py-6 rounded-panel shadow-none">
                    <CardContent>
                        <dl className="grid grid-cols-1 gap-3 text-isi md:grid-cols-2">
                            <Rincian label="Alamat" nilai={Outlet.Alamat ?? 'Belum diisi'} />
                            <Rincian label="Telepon" nilai={Outlet.Telepon ?? 'Belum diisi'} />
                            <Rincian label="Kabupaten/kota" nilai={`${namaKota} | ${Outlet.ZonaWaktu}`} />
                            <Rincian label="Jam tutup buku" nilai={Outlet.JamTutupBuku} />
                            <Rincian label="PKP" nilai={Outlet.Pkp ? 'Ya' : 'Tidak'} />
                            {Outlet.Kanvas ? (
                                <Rincian
                                    label="Outlet kanvas"
                                    nilai={`Ya | ${Outlet.NomorKendaraan ?? 'nomor kendaraan belum diisi'}`}
                                />
                            ) : null}
                        </dl>
                    </CardContent>
                </Card>
            )}

            {sektorFnb || JenisPesanan.JenisPesanan.length > 0 ? (
                <JenisPesananOutlet
                    alamatOutlet={alamat}
                    data={JenisPesanan}
                    bolehKelola={bolehKelola && Outlet.Status === 'Aktif'}
                />
            ) : null}

            {JamOperasional && (sektorTokoOnline || JamOperasional.Diatur) ? (
                <JamOperasionalOutlet
                    alamatOutlet={alamat}
                    data={JamOperasional}
                    bolehKelola={bolehKelola && Outlet.Status === 'Aktif'}
                />
            ) : null}

            <LokasiAbsensiOutlet
                alamatOutlet={alamat}
                data={LokasiAbsensi}
                bolehKelola={bolehKelola && Outlet.Status === 'Aktif'}
            />

            <BagianGudang
                alamatOutlet={alamat}
                gudang={Gudang}
                jenis={JenisGudang}
                bolehKelola={bolehKelola && Outlet.Status === 'Aktif'}
            />

            {sektorFnb || ModeMeja.Aktif || ModeMeja.Meja.length > 0 ? (
                <BagianMeja
                    alamatOutlet={alamat}
                    modeMeja={ModeMeja}
                    bentuk={BentukMeja}
                    bolehKelola={bolehKelola && Outlet.Status === 'Aktif'}
                    pesanSendiri={PesanSendiri}
                />
            ) : null}

            {Kios && (sektorFnb || Kios.Aktif) ? (
                <KiosOutlet alamatOutlet={alamat} data={Kios} bolehKelola={bolehKelola && Outlet.Status === 'Aktif'} />
            ) : null}
        </TataLetakAplikasi>
    );
}

function Rincian({ label, nilai }: { label: string; nilai: string }) {
    return (
        <div>
            <dt className="text-label text-teks-sekunder">{label}</dt>
            <dd className="text-teks-utama">{nilai}</dd>
        </div>
    );
}

type PropsBagianGudang = { alamatOutlet: string; gudang: Gudang[]; jenis: Pilihan[]; bolehKelola: boolean };

function BagianGudang({ alamatOutlet, gudang, jenis, bolehKelola }: PropsBagianGudang) {
    const [sunting, AturSunting] = useState<Gudang | 'baru' | null>(null);
    // Lokasi stok tambahan butuh fitur Multi-gudang; server menolak penyimpanannya bila paket belum memuatnya, jadi
    // tombolnya diganti penjelasan di muka daripada menunggu penolakan.
    const penawaran = usePage<PropsBersamaAplikasi>().props.FiturPaket?.Terkunci['stok.multi-gudang'];
    const kolom = useMemo<KolomTabel<Gudang>[]>(() => {
        const labelJenis = new Map(jenis.map((baris) => [baris.Nilai, baris.Label]));

        return [
            {
                id: 'Kode',
                accessorKey: 'Kode',
                header: 'Kode',
                meta: {
                    label: 'Kode',
                    prioritas: 'utama',
                    wajib: true,
                    kelasSel: 'font-mono text-label text-teks-utama',
                },
            },
            { id: 'Nama', accessorKey: 'Nama', header: 'Nama', meta: { label: 'Nama', prioritas: 'penting' } },
            {
                id: 'Jenis',
                accessorFn: (baris) => labelJenis.get(baris.Jenis) ?? baris.Jenis,
                header: 'Jenis',
                meta: { label: 'Jenis', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
            },
            {
                id: 'Status',
                accessorKey: 'Status',
                header: 'Status',
                meta: { label: 'Status', prioritas: 'penting' },
                cell: ({ row }) =>
                    row.original.Status === 'Aktif' ? (
                        <LabelStatus jenis="sukses" teks="Aktif" />
                    ) : (
                        <LabelStatus jenis="netral" teks="Diarsipkan" />
                    ),
            },
        ];
    }, [jenis]);

    return (
        <section className="flex flex-col gap-2">
            <AksiHalaman keterangan={<h2 className="text-subjudul font-semibold text-teks-utama">Lokasi stok</h2>}>
                {bolehKelola && penawaran === undefined ? (
                    <Tombol varian="sekunder" onClick={() => AturSunting('baru')}>
                        Tambah lokasi stok
                    </Tombol>
                ) : null}
            </AksiHalaman>
            {bolehKelola && penawaran !== undefined ? (
                <Pemberitahuan jenis="info" judul="Lokasi stok tambahan belum termasuk di paket Anda">
                    <p>
                        Tiap outlet tetap punya lokasi stok bawaannya. Menambah lokasi lain (Dapur, Bar, Gudang
                        Belakang) butuh Multi-gudang
                        {penawaran.Paket !== null ? `, tersedia di paket ${penawaran.Paket.Nama}` : ''}
                        {penawaran.Addon !== null ? ` atau lewat add-on ${penawaran.Addon.Nama}` : ''}. Buka menu
                        Langganan untuk memilihnya.
                    </p>
                </Pemberitahuan>
            ) : null}
            <p className="text-keterangan text-teks-sekunder">
                Setiap outlet wajib punya minimal satu lokasi stok untuk barang jual. Tambah Dapur, Bar, atau Gudang
                Belakang bila stoknya dipisah.
            </p>
            {sunting !== null ? (
                <DialogFormulir
                    judul={sunting === 'baru' ? 'Tambah lokasi stok' : `Ubah lokasi stok ${sunting.Kode}`}
                    saatTutup={() => AturSunting(null)}
                >
                    <FormGudang
                        key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                        alamatOutlet={alamatOutlet}
                        gudang={sunting === 'baru' ? null : sunting}
                        jenis={jenis}
                        saatSelesai={() => AturSunting(null)}
                    />
                </DialogFormulir>
            ) : null}
            <TabelData
                id="organisasi-gudang-outlet"
                label="Lokasi stok outlet"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: gudang }}
                ambilIdBaris={(baris) => baris.Uuid}
                urutBawaan="Kode"
                labelBaris={(baris) => `lokasi stok ${baris.Nama}`}
                {...(bolehKelola
                    ? {
                          aksiBaris: (baris: Gudang) => (
                              <ItemAksiBaris
                                  aksi={[
                                      { label: 'Ubah', saatPilih: () => AturSunting(baris) },
                                      {
                                          label: baris.Status === 'Aktif' ? 'Arsipkan' : 'Pulihkan',
                                          bahaya: baris.Status === 'Aktif',
                                          saatPilih: () =>
                                              router.post(
                                                  `${alamatOutlet}/gudang/${baris.Uuid}/${baris.Status === 'Aktif' ? 'arsipkan' : 'pulihkan'}`,
                                                  {},
                                                  { preserveScroll: true },
                                              ),
                                      },
                                  ]}
                              />
                          ),
                      }
                    : {})}
                kosong={{ judul: 'Belum ada lokasi stok. Tambah lokasi stok untuk barang jual.' }}
            />
        </section>
    );
}

type PropsFormGudang = { alamatOutlet: string; gudang: Gudang | null; jenis: Pilihan[]; saatSelesai: () => void };

function FormGudang({ alamatOutlet, gudang, jenis, saatSelesai }: PropsFormGudang) {
    const formulir = useForm({ Nama: gudang?.Nama ?? '', Kode: gudang?.Kode ?? '', Jenis: gudang?.Jenis ?? 'Gudang' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (gudang === null) {
            formulir.post(`${alamatOutlet}/gudang`, opsi);
        } else {
            formulir.put(`${alamatOutlet}/gudang/${gudang.Uuid}`, opsi);
        }
    };

    return (
        <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
            <BidangTeks
                label="Nama lokasi"
                nilai={formulir.data.Nama}
                saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                galat={formulir.errors.Nama}
                maxLength={150}
                autoFocus
                required
            />
            <BidangTeks
                label="Kode"
                nilai={formulir.data.Kode}
                saatBerubah={(nilai) => formulir.setData('Kode', nilai.toUpperCase())}
                galat={formulir.errors.Kode}
                keterangan="Misal JKT1-DPR"
                maxLength={20}
                kode
                required
            />
            <BidangPilihan
                label="Jenis"
                nilai={formulir.data.Jenis}
                opsi={jenis}
                saatBerubah={(nilai) => formulir.setData('Jenis', nilai)}
                galat={formulir.errors.Jenis}
                required
            />
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan lokasi stok
                </Tombol>
                <Tombol varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}
