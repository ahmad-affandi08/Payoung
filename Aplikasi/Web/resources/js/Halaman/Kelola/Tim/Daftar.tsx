import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import FormulirKaryawan from '@/Komponen/Karyawan/FormulirKaryawan';
import PanelAbsenHp from '@/Komponen/Karyawan/PanelAbsenHp';
import FormAksesPengguna from '@/Komponen/Kelola/FormAksesPengguna';
import FormPin from '@/Komponen/Kelola/FormPin';
import AksiMassalPengguna from '@/Komponen/Organisasi/AksiMassalPengguna';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { ItemAksiBaris, type AksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import { Button } from '@/Komponen/Ui/button';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import { CekBatasPenuh, FormatBatas } from '@/Tipe/Organisasi';
import type { BarisTim, PropsDaftarTim, UndanganTim } from '@/Tipe/Tim';

type Pilihan =
    | { jenis: 'akses' | 'pin' | 'karyawan' | 'absenHp'; orang: BarisTim }
    // `nonaktifkan` ikut dibawa: orang berstatus Sebagian bisa dinonaktifkan penuh atau diaktifkan penuh.
    | { jenis: 'ubahStatus'; orang: BarisTim; nonaktifkan: boolean }
    | null;

/** Kolom daftar tim; kode outlet dari props halaman. */
function BuatKolom(namaOutlet: Map<string, string>, uuidSaya: string, lihatGaji: boolean): KolomTabel<BarisTim>[] {
    return [
        {
            id: 'Nama',
            accessorFn: (orang) => `${orang.Nama} ${orang.Email ?? ''} ${orang.Jabatan ?? ''}`,
            header: 'Nama',
            meta: { label: 'Nama', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: orang } }) => (
                <>
                    <span className="block font-semibold text-teks-utama">
                        {orang.Nama}
                        {orang.UuidPengguna === uuidSaya ? (
                            <span className="font-normal text-teks-sekunder"> (Anda)</span>
                        ) : null}
                    </span>
                    <span className="block text-keterangan break-all text-teks-sekunder">
                        {orang.UuidPengguna === null
                            ? 'Tanpa akun masuk'
                            : (orang.Email ?? 'Masuk aplikasi kasir dengan PIN')}
                    </span>
                </>
            ),
        },
        {
            id: 'Jabatan',
            accessorFn: (orang) => orang.Jabatan ?? '',
            header: 'Jabatan',
            meta: { label: 'Jabatan', prioritas: 'penting' },
            cell: ({ row }) => row.original.Jabatan ?? '—',
        },
        {
            id: 'Akses',
            accessorFn: (orang) => orang.NamaPeran ?? 'Tanpa akun',
            header: 'Peran',
            meta: { label: 'Peran', prioritas: 'penting' },
            cell: ({ row: { original: orang } }) =>
                orang.UuidPengguna === null ? (
                    <span className="text-teks-sekunder">Tanpa akun</span>
                ) : (
                    (orang.NamaPeran ?? 'Belum ada peran')
                ),
        },
        {
            id: 'Outlet',
            header: 'Outlet',
            enableSorting: false,
            meta: { label: 'Outlet', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
            cell: ({ row: { original: orang } }) => {
                if (orang.UuidPengguna === null) {
                    return orang.Karyawan?.NamaOutlet ?? 'Semua outlet';
                }

                return orang.SemuaOutlet
                    ? 'Semua outlet'
                    : orang.UuidOutlet.map((uuid) => namaOutlet.get(uuid) ?? 'Diarsipkan').join(', ') ||
                          'Belum ditugaskan';
            },
        },
        ...(lihatGaji
            ? [
                  {
                      id: 'GajiPokok',
                      header: 'Gaji pokok',
                      enableSorting: false,
                      meta: { label: 'Gaji pokok', prioritas: 'rendah' as const, angka: true },
                      cell: ({ row }: { row: { original: BarisTim } }) =>
                          row.original.Karyawan?.GajiPokok ? FormatRupiah(row.original.Karyawan.GajiPokok) : '—',
                  },
              ]
            : []),
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row: { original: orang } }) =>
                orang.Status === 'Aktif' ? (
                    <LabelStatus jenis="sukses" teks="Aktif" />
                ) : orang.Status === 'Sebagian' ? (
                    <LabelStatus
                        jenis="peringatan"
                        teks={
                            orang.StatusAkun === 'Aktif'
                                ? 'Akun aktif, karyawan nonaktif'
                                : 'Karyawan aktif, akun nonaktif'
                        }
                    />
                ) : (
                    <LabelStatus jenis="netral" teks="Nonaktif" />
                ),
        },
    ];
}

const kolomUndangan: KolomTabel<UndanganTim>[] = [
    {
        id: 'Email',
        accessorKey: 'Email',
        header: 'Email',
        meta: { label: 'Email', prioritas: 'utama', wajib: true },
        cell: ({ row }) => <span className="break-all">{row.original.Email}</span>,
    },
    {
        id: 'Peran',
        accessorFn: (u) => u.NamaPeran ?? '',
        header: 'Peran',
        meta: { label: 'Peran', prioritas: 'penting' },
    },
    {
        id: 'BerlakuSampai',
        accessorKey: 'BerlakuSampai',
        header: 'Berlaku sampai',
        meta: { label: 'Berlaku sampai', prioritas: 'penting' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.BerlakuSampai),
    },
];

/**
 * Tim (D-101): semua orang yang bekerja di usaha dalam satu daftar — akun masuk, jadwal, dan gaji. Satu tombol
 * "Tambah anggota tim"; aksi per orang menyesuaikan apa yang sudah dimilikinya (akun, data karyawan, atau keduanya).
 */
export default function HalamanDaftarTim({
    Tim,
    Undangan,
    Peran,
    Outlet,
    OpsiOutlet,
    OpsiPengguna,
    BatasPengguna,
    UuidSaya,
    Izin,
}: PropsDaftarTim) {
    const [pilihan, AturPilihan] = useState<Pilihan>(null);
    const penuh = CekBatasPenuh(BatasPengguna);
    const sayaPemilik = Tim.some((orang) => orang.UuidPengguna === UuidSaya && orang.Pemilik);
    const peranTerlihat = Peran.filter((peran) => sayaPemilik || !peran.Pemilik);
    const kolom = useMemo(
        () => BuatKolom(new Map(Outlet.map((outlet) => [outlet.Uuid, outlet.Kode])), UuidSaya, Izin.KelolaKaryawan),
        [Outlet, UuidSaya, Izin.KelolaKaryawan],
    );

    // Pemilik hanya bisa diubah Pemilik lain; akun sendiri tidak bisa diubah dari sini.
    const BolehSentuhAkun = (orang: BarisTim) =>
        orang.UuidPengguna !== null && orang.UuidPengguna !== UuidSaya && (sayaPemilik || !orang.Pemilik);

    const SusunAksi = (orang: BarisTim): AksiBaris[] => {
        const aksi: AksiBaris[] = [];
        const akunAktif = orang.StatusAkun === 'Aktif';
        const karyawan = orang.Karyawan;

        if (karyawan !== null && Izin.KelolaKaryawan) {
            aksi.push({ label: 'Ubah data & gaji', saatPilih: () => AturPilihan({ jenis: 'karyawan', orang }) });
            aksi.push({ label: 'Absen dari HP', saatPilih: () => AturPilihan({ jenis: 'absenHp', orang }) });
        }

        if (BolehSentuhAkun(orang) && akunAktif) {
            if (Izin.UbahAkses) {
                aksi.push({ label: 'Ubah peran & outlet', saatPilih: () => AturPilihan({ jenis: 'akses', orang }) });
            }
            if (Izin.AturPin) {
                aksi.push({ label: 'Atur ulang PIN', saatPilih: () => AturPilihan({ jenis: 'pin', orang }) });
            }
            if (karyawan === null && Izin.KelolaKaryawan) {
                aksi.push({
                    label: 'Catat sebagai karyawan',
                    saatPilih: () =>
                        router.post(`/kelola/pengguna/${orang.UuidPengguna}/karyawan`, {}, { preserveScroll: true }),
                });
            }
        }

        if (orang.UuidPengguna === null && karyawan?.Status === 'Aktif' && Izin.Undang && Izin.KelolaKaryawan) {
            aksi.push({
                label: 'Beri akses masuk',
                nonaktif: penuh,
                saatPilih: () => router.visit(`/kelola/tim/buat?karyawan=${karyawan.Uuid}`),
            });
        }

        // Status: bagian yang boleh disentuh saja (akun butuh pengguna.nonaktifkan, karyawan butuh karyawan.kelola).
        const bolehAkun = BolehSentuhAkun(orang) && Izin.Nonaktifkan;
        const bolehKaryawan = karyawan !== null && Izin.KelolaKaryawan;

        if (bolehAkun || bolehKaryawan) {
            if (orang.Status !== 'Nonaktif') {
                aksi.push({
                    label: 'Nonaktifkan',
                    bahaya: true,
                    saatPilih: () => AturPilihan({ jenis: 'ubahStatus', orang, nonaktifkan: true }),
                });
            }
            if (orang.Status !== 'Aktif') {
                aksi.push({
                    label: 'Aktifkan kembali',
                    nonaktif: penuh && bolehAkun,
                    saatPilih: () => AturPilihan({ jenis: 'ubahStatus', orang, nonaktifkan: false }),
                });
            }
        }

        return aksi;
    };

    return (
        <TataLetakAplikasi judul="Tim">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Semua orang yang bekerja di usaha Anda dalam satu daftar: siapa yang bisa masuk (kasir dengan PIN atau
                dashboard dengan email), jadwal kerjanya, dan gajinya. Tambah, ubah, dan nonaktifkan dari sini.
            </p>
            <AksiHalaman
                keterangan={
                    <p className="text-isi text-teks-sekunder">
                        Kursi akun masuk (anggota aktif + undangan menunggu):{' '}
                        <span className="font-semibold text-teks-utama">{FormatBatas(BatasPengguna, 'pengguna')}</span>.
                        Karyawan tanpa akun tidak memakai kursi.
                    </p>
                }
            >
                <Button variant="outline" asChild>
                    <Link href="/kelola/peran">Peran & izin</Link>
                </Button>
                {Izin.Tambah ? (
                    <Button asChild>
                        <Link href="/kelola/tim/buat">Tambah anggota tim</Link>
                    </Button>
                ) : null}
            </AksiHalaman>

            {Izin.Undang && penuh ? (
                <Pemberitahuan jenis="info" judul="Batas akun masuk paket sudah tercapai">
                    Anda masih bisa menambah karyawan tanpa akun. Untuk akun masuk baru, nonaktifkan yang tidak dipakai,
                    batalkan undangan, atau{' '}
                    <AjakanTambahBatas>
                        tingkatkan paket di{' '}
                        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
                            menu Langganan
                        </Link>
                    </AjakanTambahBatas>
                    .
                </Pemberitahuan>
            ) : null}

            {pilihan?.jenis === 'akses' && pilihan.orang.UuidPengguna !== null ? (
                <DialogFormulir
                    jenis="panel"
                    judul={`Peran & outlet ${pilihan.orang.Nama}`}
                    saatTutup={() => AturPilihan(null)}
                >
                    <FormAksesPengguna
                        key={pilihan.orang.Kunci}
                        alamat={`/kelola/pengguna/${pilihan.orang.UuidPengguna}/akses`}
                        metode="put"
                        awal={{
                            Email: pilihan.orang.Email ?? '',
                            Peran: pilihan.orang.UuidPeran ?? '',
                            SemuaOutlet: pilihan.orang.SemuaOutlet,
                            Outlet: pilihan.orang.UuidOutlet,
                        }}
                        peran={peranTerlihat}
                        outlet={Outlet}
                        tombol="Simpan akses"
                        saatSelesai={() => AturPilihan(null)}
                        saatBatal={() => AturPilihan(null)}
                    />
                </DialogFormulir>
            ) : null}
            {pilihan?.jenis === 'pin' && pilihan.orang.UuidPengguna !== null ? (
                <DialogFormulir
                    judul={`Atur ulang PIN ${pilihan.orang.Nama}`}
                    keterangan="PIN lama tidak bisa dilihat. Isi PIN baru 6 angka, lalu beritahukan langsung kepada yang bersangkutan. PIN baru berlaku di perangkat kasir setelah perangkat tersinkron."
                    saatTutup={() => AturPilihan(null)}
                >
                    <FormPin
                        key={pilihan.orang.Kunci}
                        alamat={`/kelola/pengguna/${pilihan.orang.UuidPengguna}/pin`}
                        labelTombol="Simpan PIN baru"
                        saatSelesai={() => AturPilihan(null)}
                    />
                </DialogFormulir>
            ) : null}
            {pilihan?.jenis === 'karyawan' && pilihan.orang.Karyawan !== null ? (
                <FormulirKaryawan
                    key={pilihan.orang.Kunci}
                    karyawan={pilihan.orang.Karyawan}
                    opsiPengguna={OpsiPengguna}
                    opsiOutlet={OpsiOutlet}
                    saatTutup={() => AturPilihan(null)}
                />
            ) : null}
            {pilihan?.jenis === 'absenHp' && pilihan.orang.Karyawan !== null ? (
                <PanelAbsenHp karyawan={pilihan.orang.Karyawan} saatTutup={() => AturPilihan(null)} />
            ) : null}
            {pilihan?.jenis === 'ubahStatus' ? (
                <KonfirmasiStatus
                    key={pilihan.orang.Kunci}
                    orang={pilihan.orang}
                    nonaktifkan={pilihan.nonaktifkan}
                    sertakanAkun={BolehSentuhAkun(pilihan.orang) && Izin.Nonaktifkan}
                    sertakanKaryawan={pilihan.orang.Karyawan !== null && Izin.KelolaKaryawan}
                    saatSelesai={() => AturPilihan(null)}
                />
            ) : null}

            <TabelData
                id="tim"
                label="Daftar tim"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Tim }}
                ambilIdBaris={(orang) => orang.Kunci}
                cari="Cari nama, jabatan, atau email"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihan',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Sebagian', label: 'Sebagian aktif' },
                            { nilai: 'Nonaktif', label: 'Nonaktif' },
                        ],
                    },
                ]}
                labelBaris={(orang) => `untuk ${orang.Nama}`}
                {...(Izin.UbahAkses
                    ? {
                          // Aksi massal bekerja pada akun (ganti peran); karyawan tanpa akun dilewati. Nonaktif massal
                          // sengaja tidak ditawarkan di sini: nonaktif per baris menghentikan akun & karyawan bersama.
                          aksiMassal: (konteks: KonteksAksiMassal<BarisTim>) => (
                              <AksiMassalPengguna
                                  konteks={{
                                      ...konteks,
                                      terpilih: konteks.terpilih
                                          .filter((orang) => orang.UuidPengguna !== null)
                                          .map((orang) => ({
                                              Uuid: orang.UuidPengguna as string,
                                              Nama: orang.Nama,
                                              Pemilik: orang.Pemilik,
                                          })),
                                  }}
                                  peran={peranTerlihat}
                                  bolehSentuh={(akun) => akun.Uuid !== UuidSaya && (sayaPemilik || !akun.Pemilik)}
                                  bolehUbah={Izin.UbahAkses}
                                  bolehNonaktifkan={false}
                              />
                          ),
                      }
                    : {})}
                aksiBaris={(orang) => {
                    const aksi = SusunAksi(orang);

                    return aksi.length === 0 ? null : <ItemAksiBaris aksi={aksi} />;
                }}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada anggota tim. Tambahkan kasir atau staf agar tim bisa ikut bekerja.',
                }}
            />

            {Undangan.length > 0 ? (
                <section className="flex flex-col gap-2" aria-labelledby="judul-undangan">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="judul-undangan" className="text-subjudul font-semibold text-teks-utama">
                            Undangan menunggu
                        </h2>
                        {Izin.Undang && !penuh ? (
                            <Button variant="outline" asChild>
                                <Link href="/kelola/pengguna/undangan/buat">Undang lewat email</Link>
                            </Button>
                        ) : null}
                    </div>
                    <TabelData
                        id="tim-undangan"
                        label="Undangan menunggu"
                        kolom={kolomUndangan}
                        sumber={{ mode: 'lokal', data: Undangan }}
                        ambilIdBaris={(undangan) => undangan.Uuid}
                        labelBaris={(undangan) => `undangan ${undangan.Email}`}
                        {...(Izin.Undang
                            ? {
                                  aksiBaris: (undangan: UndanganTim) => (
                                      <DropdownMenuItem
                                          variant="destructive"
                                          onSelect={() =>
                                              router.post(
                                                  `/kelola/pengguna/undangan/${undangan.Uuid}/batalkan`,
                                                  {},
                                                  { preserveScroll: true },
                                              )
                                          }
                                      >
                                          Batalkan undangan
                                      </DropdownMenuItem>
                                  ),
                              }
                            : {})}
                        kosong={{ ilustrasi: true, judul: 'Tidak ada undangan yang menunggu diterima.' }}
                    />
                </section>
            ) : null}
        </TataLetakAplikasi>
    );
}

/** Nonaktifkan atau aktifkan kembali satu orang: akun & data karyawan bersama (hanya bagian yang boleh disentuh). */
function KonfirmasiStatus({
    orang,
    nonaktifkan,
    sertakanAkun,
    sertakanKaryawan,
    saatSelesai,
}: {
    orang: BarisTim;
    nonaktifkan: boolean;
    sertakanAkun: boolean;
    sertakanKaryawan: boolean;
    saatSelesai: () => void;
}) {
    const [memproses, AturMemproses] = useState(false);
    // Hanya bagian yang masih berstatus lawan yang diubah; sisanya sudah sesuai.
    const akun = sertakanAkun && orang.UuidPengguna !== null && (orang.StatusAkun === 'Aktif') === nonaktifkan;
    const karyawan = sertakanKaryawan && orang.Karyawan !== null && (orang.Karyawan.Status === 'Aktif') === nonaktifkan;

    return (
        <DialogKonfirmasi
            judul={nonaktifkan ? `Nonaktifkan ${orang.Nama}?` : `Aktifkan kembali ${orang.Nama}?`}
            labelAksi={nonaktifkan ? 'Nonaktifkan' : 'Aktifkan kembali'}
            memproses={memproses}
            saatBatal={saatSelesai}
            saatKonfirmasi={() =>
                router.post(
                    '/kelola/tim/status',
                    {
                        Aksi: nonaktifkan ? 'Nonaktifkan' : 'Aktifkan',
                        UuidPengguna: akun ? orang.UuidPengguna : null,
                        UuidKaryawan: karyawan ? (orang.Karyawan?.Uuid ?? null) : null,
                    },
                    {
                        preserveScroll: true,
                        onStart: () => AturMemproses(true),
                        onFinish: () => AturMemproses(false),
                        onSuccess: saatSelesai,
                    },
                )
            }
        >
            <p>
                {nonaktifkan
                    ? `${orang.Nama} berhenti dari usaha ini${akun ? ': akun masuknya langsung keluar' : ''}${
                          karyawan ? ', tidak dijadwalkan dan tidak bisa absen lagi' : ''
                      }. Riwayat absensi, gaji, dan transaksinya tetap tersimpan dan bisa diaktifkan kembali kapan saja.`
                    : `${orang.Nama} bisa bekerja lagi${akun ? ' dan masuk dengan akunnya' : ''}${
                          karyawan ? ', dijadwalkan, dan absen' : ''
                      }.`}
            </p>
        </DialogKonfirmasi>
    );
}
