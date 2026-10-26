import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangTanggal from '@/Komponen/Pengelola/BidangTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogTinjauan from '@/Komponen/Tindakan/DialogTinjauan';
import TabReferensi from '@/Komponen/Pengelola/TabReferensi';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem, DropdownMenuSeparator } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, CekBebasTinjauan, PunyaIzin, type Pilihan, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type HariLibur = {
    Uuid: string;
    Tanggal: string;
    Nama: string;
    Jenis: string;
    Status: 'Draf' | 'MenungguTinjauan' | 'Terbit' | 'Dibatalkan';
    NomorDasarHukum: string | null;
    PembatalanMenunggu: boolean;
    AlasanPembatalan: string | null;
    IdPengajuBatal: number | null;
    DibatalkanPada: string | null;
};

type PropsHariLibur = {
    Tahun: number;
    HariLibur: HariLibur[];
    IdPengajuMenunggu: number[];
    PeninjauMenunggu: number[];
    IdPengguna: number;
    PilihanJenis: Pilihan[];
};

const labelStatus = {
    Draf: { jenis: 'netral', teks: 'Draf' },
    MenungguTinjauan: { jenis: 'peringatan', teks: 'Menunggu tinjauan' },
    Terbit: { jenis: 'sukses', teks: 'Terbit' },
    Dibatalkan: { jenis: 'bahaya', teks: 'Dibatalkan' },
} as const;

const formatTanggal = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
});

function BuatKolom(labelJenis: Map<string, string>): KolomTabel<HariLibur>[] {
    return [
        {
            id: 'Tanggal',
            accessorKey: 'Tanggal',
            header: 'Tanggal',
            meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap text-teks-utama' },
            cell: ({ row }) => formatTanggal.format(new Date(`${row.original.Tanggal}T00:00:00Z`)),
        },
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Nama',
            meta: { label: 'Nama', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama' },
        },
        {
            id: 'Jenis',
            accessorKey: 'Jenis',
            header: 'Jenis',
            meta: { label: 'Jenis', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
            cell: ({ row }) => labelJenis.get(row.original.Jenis) ?? row.original.Jenis,
        },
        {
            id: 'NomorDasarHukum',
            accessorKey: 'NomorDasarHukum',
            header: 'Dasar hukum',
            enableSorting: false,
            meta: { label: 'Dasar hukum', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
            cell: ({ row }) => row.original.NomorDasarHukum ?? '—',
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row: { original: hari } }) => (
                <>
                    <LabelStatus jenis={labelStatus[hari.Status].jenis} teks={labelStatus[hari.Status].teks} />
                    {hari.PembatalanMenunggu ? (
                        <span className="mt-1 block text-keterangan text-peringatan">
                            Pembatalan menunggu tinjauan: {hari.AlasanPembatalan}
                        </span>
                    ) : null}
                </>
            ),
        },
    ];
}

/** Hari libur nasional & cuti bersama per tahun (P-02), TabelData D-16. Wajib terbit paling lambat 1 Desember (BR-P02.4). */
export default function HalamanHariLibur({
    Tahun,
    HariLibur,
    IdPengajuMenunggu,
    PeninjauMenunggu,
    IdPengguna,
    PilihanJenis,
}: PropsHariLibur) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehAjukan = PunyaIzin(props.Pengguna, IzinPengelola.ReferensiHariLiburAjukan);
    const bolehSetujui = PunyaIzin(props.Pengguna, IzinPengelola.ReferensiHariLiburSetujui);
    const [sunting, AturSunting] = useState<HariLibur | 'baru' | null>(null);
    const [meninjau, AturMeninjau] = useState(false);
    const [pembatalan, AturPembatalan] = useState<{ jenis: 'ajukan' | 'tinjau'; hari: HariLibur } | null>(null);
    const kolom = useMemo(
        () => BuatKolom(new Map(PilihanJenis.map((item) => [item.Nilai, item.Label]))),
        [PilihanJenis],
    );
    const adaDraf = HariLibur.some((hari) => hari.Status === 'Draf');
    const adaMenunggu = HariLibur.some((hari) => hari.Status === 'MenungguTinjauan');
    const bebasTinjauan = CekBebasTinjauan(props.Pengguna);
    const bisaTinjau =
        bolehSetujui &&
        adaMenunggu &&
        (bebasTinjauan || (!IdPengajuMenunggu.includes(IdPengguna) && !PeninjauMenunggu.includes(IdPengguna)));

    const PilihTahun = (tahun: string) =>
        router.get('/referensi/hari-libur', { saring: { Tahun: tahun } }, { preserveState: false });
    const AjukanTahun = () => router.post(`/referensi/hari-libur/tahun/${Tahun}/ajukan`, {}, { preserveScroll: true });
    const HapusDraf = (hari: HariLibur) =>
        router.delete(`/referensi/hari-libur/${hari.Uuid}`, { preserveScroll: true });
    const pilihanTahun = [Tahun - 1, Tahun, Tahun + 1].map((tahun) => ({ Nilai: String(tahun), Label: String(tahun) }));

    return (
        <TataLetakPengelola judul="Referensi">
            <TabReferensi />
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}

            <AksiHalaman
                keterangan={
                    <div className="w-40">
                        <BidangPilihan
                            label="Tahun"
                            nilai={String(Tahun)}
                            opsi={pilihanTahun}
                            saatBerubah={PilihTahun}
                        />
                    </div>
                }
            >
                {bolehAjukan && adaDraf ? (
                    <Tombol onClick={AjukanTahun}>
                        {bebasTinjauan ? `Terbitkan semua draf ${Tahun}` : `Ajukan semua draf ${Tahun}`}
                    </Tombol>
                ) : null}
                {bisaTinjau && !meninjau ? (
                    <Tombol onClick={() => AturMeninjau(true)}>Tinjau hari libur {Tahun}</Tombol>
                ) : null}
                {bolehAjukan && sunting === null ? (
                    <Tombol onClick={() => AturSunting('baru')}>Tambah hari libur</Tombol>
                ) : null}
            </AksiHalaman>

            {sunting !== null ? (
                <FormHariLibur
                    key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                    hariLibur={sunting === 'baru' ? null : sunting}
                    tahun={Tahun}
                    pilihanJenis={PilihanJenis}
                    saatSelesai={() => AturSunting(null)}
                />
            ) : null}
            {pembatalan !== null ? (
                <FormPembatalan
                    key={`${pembatalan.jenis}-${pembatalan.hari.Uuid}`}
                    jenis={pembatalan.jenis}
                    hari={pembatalan.hari}
                    saatSelesai={() => AturPembatalan(null)}
                />
            ) : null}
            {meninjau ? (
                <FormTinjauTahun
                    tahun={Tahun}
                    jumlah={HariLibur.filter((hari) => hari.Status === 'MenungguTinjauan').length}
                    saatSelesai={() => AturMeninjau(false)}
                />
            ) : null}

            {HariLibur.length === 0 ? (
                <Pemberitahuan jenis="peringatan" judul={`Belum ada hari libur ${Tahun}`}>
                    Masukkan libur nasional & cuti bersama sesuai SKB terbaru. Hari libur tahun berikutnya wajib terbit
                    paling lambat 1 Desember.
                </Pemberitahuan>
            ) : (
                <TabelData
                    id="pengelola-referensi-hari-libur"
                    label={`Hari libur tahun ${Tahun}`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: HariLibur }}
                    ambilIdBaris={(hari) => hari.Uuid}
                    urutBawaan="Tanggal"
                    cari="Cari nama hari libur"
                    saring={[
                        {
                            id: 'Jenis',
                            label: 'Jenis',
                            jenis: 'pilihanBanyak',
                            opsi: PilihanJenis.map((item) => ({ nilai: item.Nilai, label: item.Label })),
                        },
                        {
                            id: 'Status',
                            label: 'Status',
                            jenis: 'pilihanBanyak',
                            opsi: (['Draf', 'MenungguTinjauan', 'Terbit', 'Dibatalkan'] as const).map((status) => ({
                                nilai: status,
                                label: labelStatus[status].teks,
                            })),
                        },
                    ]}
                    {...(bolehAjukan || bolehSetujui
                        ? {
                              aksiBaris: (hari: HariLibur) => {
                                  const bisaUbah = bolehAjukan && hari.Status === 'Draf';
                                  const bisaAjukanBatal =
                                      bolehAjukan && hari.Status === 'Terbit' && !hari.PembatalanMenunggu;
                                  const bisaTinjauBatal =
                                      bolehSetujui &&
                                      hari.PembatalanMenunggu &&
                                      (bebasTinjauan || hari.IdPengajuBatal !== IdPengguna);

                                  return (
                                      <>
                                          {bisaUbah ? (
                                              <>
                                                  <DropdownMenuItem onSelect={() => AturSunting(hari)}>
                                                      Ubah draf
                                                  </DropdownMenuItem>
                                                  <DropdownMenuSeparator />
                                                  <DropdownMenuItem
                                                      variant="destructive"
                                                      onSelect={() => HapusDraf(hari)}
                                                  >
                                                      Hapus draf
                                                  </DropdownMenuItem>
                                              </>
                                          ) : null}
                                          {bisaTinjauBatal ? (
                                              <DropdownMenuItem
                                                  onSelect={() => AturPembatalan({ jenis: 'tinjau', hari })}
                                              >
                                                  Tinjau pembatalan
                                              </DropdownMenuItem>
                                          ) : null}
                                          {bisaAjukanBatal ? (
                                              <DropdownMenuItem
                                                  variant="destructive"
                                                  onSelect={() => AturPembatalan({ jenis: 'ajukan', hari })}
                                              >
                                                  Ajukan pembatalan
                                              </DropdownMenuItem>
                                          ) : null}
                                          {!bisaUbah && !bisaAjukanBatal && !bisaTinjauBatal ? (
                                              <DropdownMenuItem disabled>
                                                  Tidak ada aksi untuk hari libur ini
                                              </DropdownMenuItem>
                                          ) : null}
                                      </>
                                  );
                              },
                          }
                        : {})}
                    kosong={{ ilustrasi: true, judul: `Belum ada hari libur ${Tahun}.` }}
                />
            )}
        </TataLetakPengelola>
    );
}

type PropsFormHariLibur = {
    hariLibur: HariLibur | null;
    tahun: number;
    pilihanJenis: Pilihan[];
    saatSelesai: () => void;
};

function FormHariLibur({ hariLibur, tahun, pilihanJenis, saatSelesai }: PropsFormHariLibur) {
    const formulir = useForm({
        Tanggal: hariLibur?.Tanggal ?? `${tahun}-`,
        Nama: hariLibur?.Nama ?? '',
        Jenis: hariLibur?.Jenis ?? 'Nasional',
        NomorDasarHukum: hariLibur?.NomorDasarHukum ?? '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (hariLibur === null) {
            formulir.post('/referensi/hari-libur', opsi);
        } else {
            formulir.put(`/referensi/hari-libur/${hariLibur.Uuid}`, opsi);
        }
    };

    return (
        <DialogFormulir
            judul={hariLibur === null ? 'Tambah hari libur' : `Ubah ${hariLibur.Nama}`}
            saatTutup={saatSelesai}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangTanggal
                    label="Tanggal"
                    nilai={formulir.data.Tanggal}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    required
                />
                <BidangTeks
                    label="Nama"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    required
                />
                <BidangPilihan
                    label="Jenis"
                    nilai={formulir.data.Jenis}
                    opsi={pilihanJenis}
                    saatBerubah={(nilai) => formulir.setData('Jenis', nilai)}
                    galat={formulir.errors.Jenis}
                    required
                />
                <BidangTeks
                    label="Nomor dasar hukum"
                    keterangan="Misal SKB 3 Menteri. Wajib sebelum diajukan."
                    nilai={formulir.data.NomorDasarHukum}
                    saatBerubah={(nilai) => formulir.setData('NomorDasarHukum', nilai)}
                    galat={formulir.errors.NomorDasarHukum}
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan draf
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormTinjauTahun({ tahun, jumlah, saatSelesai }: { tahun: number; jumlah: number; saatSelesai: () => void }) {
    const formulir = useForm({ Keputusan: 'Setuju', Catatan: '' });

    const Kirim = (keputusan: 'Setuju' | 'Tolak') => {
        formulir.transform((data) => ({ ...data, Keputusan: keputusan }));
        formulir.post(`/referensi/hari-libur/tahun/${tahun}/tinjau`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogTinjauan
            judul={`Tinjau ${jumlah} hari libur tahun ${tahun}`}
            deskripsi="Cocokkan setiap tanggal dengan SKB. Setelah terbit, data tidak bisa diubah."
            saatTutup={saatSelesai}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
            aksi={
                <>
                    <Tombol memproses={formulir.processing} onClick={() => Kirim('Setuju')}>
                        Terbitkan hari libur
                    </Tombol>
                    <Tombol varian="bahaya" disabled={formulir.processing} onClick={() => Kirim('Tolak')}>
                        Tolak pengajuan
                    </Tombol>
                </>
            }
        >
            <BidangTeks
                label="Catatan (wajib bila menolak)"
                nilai={formulir.data.Catatan}
                saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                galat={formulir.errors.Catatan}
                maxLength={500}
            />
        </DialogTinjauan>
    );
}

type PropsFormPembatalan = { jenis: 'ajukan' | 'tinjau'; hari: HariLibur; saatSelesai: () => void };

function FormPembatalan({ jenis, hari, saatSelesai }: PropsFormPembatalan) {
    const formulir = useForm({ Alasan: '', Keputusan: 'Setuju', Catatan: '' });

    const Ajukan = () =>
        formulir.post(`/referensi/hari-libur/${hari.Uuid}/pembatalan`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    const Tinjau = (keputusan: 'Setuju' | 'Tolak') => {
        formulir.transform((data) => ({ Keputusan: keputusan, Catatan: data.Catatan }));
        formulir.post(`/referensi/hari-libur/${hari.Uuid}/pembatalan/tinjau`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    const galatUmum = (formulir.errors as Record<string, string | undefined>).Umum;

    if (jenis === 'ajukan') {
        // Mengajukan butuh alasan yang langsung diketik: dialog biasa agar fokus awal di isian alasan.
        return (
            <DialogFormulir
                judul={`Ajukan pembatalan ${hari.Nama}`}
                keterangan="Hari libur tetap berlaku sampai pembatalan disetujui anggota lain. Untuk menggeser tanggal, batalkan lalu tambahkan hari libur baru."
                saatTutup={saatSelesai}
                galatUmum={galatUmum}
            >
                <div className="flex flex-col gap-4">
                    <BidangTeks
                        label="Alasan pembatalan"
                        keterangan="Misal nomor SKB perubahan."
                        nilai={formulir.data.Alasan}
                        saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                        galat={formulir.errors.Alasan}
                        required
                        maxLength={500}
                        autoFocus
                    />
                    <DialogFooter className="sm:justify-start">
                        <Tombol varian="bahaya" memproses={formulir.processing} onClick={Ajukan}>
                            Ajukan pembatalan
                        </Tombol>
                        <Tombol varian="sekunder" onClick={saatSelesai}>
                            Batal
                        </Tombol>
                    </DialogFooter>
                </div>
            </DialogFormulir>
        );
    }

    return (
        <DialogTinjauan
            judul={`Tinjau pembatalan ${hari.Nama}`}
            deskripsi={`Alasan: ${hari.AlasanPembatalan ?? '—'}. Bila disetujui, hari libur tidak lagi dipakai tenant. Datanya tetap tersimpan.`}
            saatTutup={saatSelesai}
            galatUmum={galatUmum}
            aksi={
                <>
                    <Tombol varian="bahaya" memproses={formulir.processing} onClick={() => Tinjau('Setuju')}>
                        Setujui pembatalan
                    </Tombol>
                    <Tombol varian="sekunder" disabled={formulir.processing} onClick={() => Tinjau('Tolak')}>
                        Tolak pembatalan
                    </Tombol>
                </>
            }
        >
            <BidangTeks
                label="Catatan (wajib bila menolak)"
                nilai={formulir.data.Catatan}
                saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                galat={formulir.errors.Catatan}
                maxLength={500}
            />
        </DialogTinjauan>
    );
}
