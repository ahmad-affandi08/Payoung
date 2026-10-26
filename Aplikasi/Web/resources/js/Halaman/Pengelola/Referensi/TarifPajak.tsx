import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangTanggal from '@/Komponen/Pengelola/BidangTanggal';
import TabReferensi from '@/Komponen/Pengelola/TabReferensi';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogTinjauan from '@/Komponen/Tindakan/DialogTinjauan';
import { Button } from '@/Komponen/Ui/button';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, CekBebasTinjauan, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type Persetujuan = { Peninjau: string; IdPeninjau: number; Keputusan: 'Setuju' | 'Tolak'; Catatan: string | null };

type Tarif = {
    Uuid: string;
    KodeJenisPajak: string;
    NamaJenisPajak: string;
    Tarif: string;
    PengaliDppPembilang: number;
    PengaliDppPenyebut: number;
    KodeWilayah: string | null;
    BiayaLayananMasukDpp: boolean;
    BerlakuMulai: string;
    BerlakuSampai: string | null;
    Status: 'Draf' | 'MenungguTinjauan' | 'Terbit' | 'Berakhir';
    NomorDasarHukum: string | null;
    TautanDasarHukum: string | null;
    IdPengaju: number | null;
    DaftarIdPenyusun: number[];
    PersetujuanDibutuhkan: number;
    Persetujuan: Persetujuan[];
    JumlahSetuju: number;
};

type JenisPajak = { Kode: string; Nama: string; Cakupan: 'Nasional' | 'Daerah' | 'Kustom' };

type PropsTarifPajak = {
    Tarif: HasilTabel<Tarif>;
    JenisPajak: JenisPajak[];
    IdPengguna: number;
};

const labelStatus = {
    Draf: { jenis: 'netral', teks: 'Draf' },
    MenungguTinjauan: { jenis: 'peringatan', teks: 'Menunggu tinjauan' },
    Terbit: { jenis: 'sukses', teks: 'Terbit' },
    Berakhir: { jenis: 'netral', teks: 'Berakhir' },
} as const;

const kolom: KolomTabel<Tarif>[] = [
    {
        id: 'Pajak',
        header: 'Pajak',
        meta: { label: 'Pajak', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: tarif } }) => (
            <>
                <span className="block font-semibold text-teks-utama">{tarif.NamaJenisPajak}</span>
                <span className="block text-keterangan font-normal text-teks-sekunder">
                    {tarif.KodeWilayah ? `Wilayah ${tarif.KodeWilayah}` : 'Nasional'}
                    {tarif.BiayaLayananMasukDpp ? ' | biaya layanan masuk DPP' : ''}
                </span>
            </>
        ),
    },
    {
        id: 'Tarif',
        accessorKey: 'Tarif',
        header: 'Tarif',
        meta: { label: 'Tarif', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: tarif } }) => (
            <>
                <span className="block text-teks-utama">{FormatPersen(tarif.Tarif)}%</span>
                <span className="block text-keterangan text-teks-sekunder">
                    DPP {tarif.PengaliDppPembilang}/{tarif.PengaliDppPenyebut}
                </span>
            </>
        ),
    },
    {
        id: 'BerlakuMulai',
        accessorKey: 'BerlakuMulai',
        header: 'Berlaku',
        meta: { label: 'Berlaku', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: tarif } }) =>
            `${FormatTanggal(tarif.BerlakuMulai)} – ${tarif.BerlakuSampai ? FormatTanggal(tarif.BerlakuSampai) : 'seterusnya'}`,
    },
    {
        id: 'DasarHukum',
        header: 'Dasar hukum',
        enableSorting: false,
        meta: { label: 'Dasar hukum', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: tarif } }) =>
            tarif.TautanDasarHukum ? (
                <Button asChild variant="link" className="h-auto p-0 text-isi">
                    <a href={tarif.TautanDasarHukum} target="_blank" rel="noreferrer">
                        {tarif.NomorDasarHukum ?? 'Dokumen'}
                    </a>
                </Button>
            ) : (
                (tarif.NomorDasarHukum ?? '—')
            ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: tarif } }) => (
            <>
                <LabelStatus jenis={labelStatus[tarif.Status].jenis} teks={labelStatus[tarif.Status].teks} />
                {tarif.Status === 'MenungguTinjauan' ? (
                    <span className="mt-1 block text-keterangan text-teks-sekunder">
                        {tarif.JumlahSetuju} dari {tarif.PersetujuanDibutuhkan} persetujuan
                    </span>
                ) : null}
                {tarif.Persetujuan.map((item) => (
                    <span key={item.IdPeninjau} className="block text-keterangan text-teks-sekunder">
                        {item.Keputusan === 'Setuju' ? 'Disetujui' : 'Ditolak'} {item.Peninjau}
                        {item.Catatan ? `: ${item.Catatan}` : ''}
                    </span>
                ))}
            </>
        ),
    },
];

/** Tarif pajak master bertanggal dengan persetujuan four-eyes (P-02, BR-P02.1, BR-P02.2), TabelData D-16. */
export default function HalamanTarifPajak({ Tarif, JenisPajak, IdPengguna }: PropsTarifPajak) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehAjukan = PunyaIzin(props.Pengguna, IzinPengelola.ReferensiTarifPajakAjukan);
    const bolehSetujui = PunyaIzin(props.Pengguna, IzinPengelola.ReferensiTarifPajakSetujui);
    const [sunting, AturSunting] = useState<Tarif | 'baru' | null>(null);
    const [ditinjau, AturDitinjau] = useState<Tarif | null>(null);
    const Ajukan = (tarif: Tarif) =>
        router.post(`/referensi/tarif-pajak/${tarif.Uuid}/ajukan`, {}, { preserveScroll: true });
    const bebasTinjauan = CekBebasTinjauan(props.Pengguna);
    const BisaTinjau = (tarif: Tarif) =>
        bolehSetujui &&
        tarif.Status === 'MenungguTinjauan' &&
        (bebasTinjauan ||
            (tarif.IdPengaju !== IdPengguna &&
                !tarif.DaftarIdPenyusun.includes(IdPengguna) &&
                !tarif.Persetujuan.some((item) => item.IdPeninjau === IdPengguna)));

    return (
        <TataLetakPengelola judul="Referensi">
            <TabReferensi />
            <Pemberitahuan jenis="info" judul="Aturan tarif pajak">
                Tarif terbit tidak pernah diubah atau dihapus. Koreksi dibuat sebagai tarif baru dengan tanggal berlaku
                baru. Tarif nasional butuh 2 penyetuju, tarif daerah 1 penyetuju, dan pengaju tidak boleh menyetujui
                drafnya sendiri. Super Admin tidak terikat aturan ini: ajuannya langsung terbit.
            </Pemberitahuan>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}

            {sunting !== null ? (
                <FormTarif
                    key={sunting === 'baru' ? 'baru' : sunting.Uuid}
                    tarif={sunting === 'baru' ? null : sunting}
                    jenisPajak={JenisPajak}
                    saatSelesai={() => AturSunting(null)}
                />
            ) : null}
            {ditinjau !== null ? (
                <FormTinjau key={ditinjau.Uuid} tarif={ditinjau} saatSelesai={() => AturDitinjau(null)} />
            ) : null}

            <AksiHalaman>
                {bolehAjukan && sunting === null ? (
                    <Tombol onClick={() => AturSunting('baru')}>Buat draf tarif</Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-referensi-tarif-pajak"
                label="Daftar tarif pajak"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: '/referensi/tarif-pajak', awal: Tarif }}
                ambilIdBaris={(tarif) => tarif.Uuid}
                urutBawaan="Pajak,-BerlakuMulai"
                cari="Cari jenis pajak, kode wilayah, atau dasar hukum"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: (['Draf', 'MenungguTinjauan', 'Terbit', 'Berakhir'] as const).map((status) => ({
                            nilai: status,
                            label: labelStatus[status].teks,
                        })),
                    },
                    {
                        id: 'KodeJenisPajak',
                        label: 'Jenis pajak',
                        jenis: 'pilihanBanyak',
                        opsi: JenisPajak.map((jenis) => ({ nilai: jenis.Kode, label: jenis.Nama })),
                    },
                ]}
                {...(bolehAjukan || bolehSetujui
                    ? {
                          aksiBaris: (tarif: Tarif) => {
                              const bisaUbah = bolehAjukan && tarif.Status === 'Draf';
                              const bisaTinjau = BisaTinjau(tarif);

                              return (
                                  <>
                                      {bisaUbah ? (
                                          <>
                                              <DropdownMenuItem onSelect={() => AturSunting(tarif)}>
                                                  Ubah draf
                                              </DropdownMenuItem>
                                              <DropdownMenuItem onSelect={() => Ajukan(tarif)}>
                                                  {bebasTinjauan ? 'Terbitkan tarif' : 'Ajukan untuk ditinjau'}
                                              </DropdownMenuItem>
                                          </>
                                      ) : null}
                                      {bisaTinjau ? (
                                          <DropdownMenuItem onSelect={() => AturDitinjau(tarif)}>
                                              Tinjau tarif
                                          </DropdownMenuItem>
                                      ) : null}
                                      {!bisaUbah && !bisaTinjau ? (
                                          <DropdownMenuItem disabled>Tidak ada aksi untuk tarif ini</DropdownMenuItem>
                                      ) : null}
                                  </>
                              );
                          },
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada tarif pajak. Buat draf tarif pertama, lalu ajukan untuk ditinjau.',
                }}
            />
        </TataLetakPengelola>
    );
}

function FormTarif({
    tarif,
    jenisPajak,
    saatSelesai,
}: {
    tarif: Tarif | null;
    jenisPajak: JenisPajak[];
    saatSelesai: () => void;
}) {
    const formulir = useForm({
        KodeJenisPajak: tarif?.KodeJenisPajak ?? jenisPajak[0]?.Kode ?? '',
        Tarif: tarif ? FormatPersen(tarif.Tarif).replace(',', '.') : '',
        PengaliDppPembilang: String(tarif?.PengaliDppPembilang ?? 1),
        PengaliDppPenyebut: String(tarif?.PengaliDppPenyebut ?? 1),
        KodeWilayah: tarif?.KodeWilayah ?? '',
        BiayaLayananMasukDpp: tarif?.BiayaLayananMasukDpp ?? false,
        BerlakuMulai: tarif?.BerlakuMulai ?? '',
        NomorDasarHukum: tarif?.NomorDasarHukum ?? '',
        TautanDasarHukum: tarif?.TautanDasarHukum ?? '',
    });
    const cakupan = jenisPajak.find((jenis) => jenis.Kode === formulir.data.KodeJenisPajak)?.Cakupan;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (tarif === null) {
            formulir.post('/referensi/tarif-pajak', opsi);
        } else {
            formulir.put(`/referensi/tarif-pajak/${tarif.Uuid}`, opsi);
        }
    };

    return (
        <DialogFormulir
            judul={tarif === null ? 'Buat draf tarif pajak' : 'Ubah draf tarif pajak'}
            saatTutup={saatSelesai}
            lebar="lebar"
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangPilihan
                    label="Jenis pajak"
                    nilai={formulir.data.KodeJenisPajak}
                    opsi={jenisPajak.map((jenis) => ({ Nilai: jenis.Kode, Label: jenis.Nama }))}
                    saatBerubah={(nilai) => formulir.setData('KodeJenisPajak', nilai)}
                    galat={formulir.errors.KodeJenisPajak}
                    required
                />
                <BidangTeks
                    label="Tarif (persen)"
                    inputMode="decimal"
                    keterangan="Pakai titik untuk desimal, misal 10 atau 10.5."
                    nilai={formulir.data.Tarif}
                    saatBerubah={(nilai) => formulir.setData('Tarif', nilai)}
                    galat={formulir.errors.Tarif}
                    required
                />
                <div className="grid grid-cols-2 gap-2">
                    <BidangTeks
                        label="Pengali DPP: pembilang"
                        inputMode="numeric"
                        nilai={formulir.data.PengaliDppPembilang}
                        saatBerubah={(nilai) => formulir.setData('PengaliDppPembilang', nilai)}
                        galat={formulir.errors.PengaliDppPembilang}
                        required
                    />
                    <BidangTeks
                        label="Penyebut"
                        inputMode="numeric"
                        keterangan="PPN non-mewah: 11/12. Penuh: 1/1."
                        nilai={formulir.data.PengaliDppPenyebut}
                        saatBerubah={(nilai) => formulir.setData('PengaliDppPenyebut', nilai)}
                        galat={formulir.errors.PengaliDppPenyebut}
                        required
                    />
                </div>
                {cakupan === 'Daerah' ? (
                    <BidangTeks
                        label="Kode kabupaten/kota"
                        kode
                        keterangan="Misal 33.74 untuk Kota Semarang."
                        nilai={formulir.data.KodeWilayah}
                        saatBerubah={(nilai) => formulir.setData('KodeWilayah', nilai)}
                        galat={formulir.errors.KodeWilayah}
                        required
                    />
                ) : null}
                <BidangTanggal
                    label="Berlaku mulai"
                    nilai={formulir.data.BerlakuMulai}
                    saatBerubah={(nilai) => formulir.setData('BerlakuMulai', nilai)}
                    galat={formulir.errors.BerlakuMulai}
                    required
                />
                <BidangTeks
                    label="Nomor dasar hukum"
                    keterangan="Nomor PMK atau Perda. Wajib sebelum diajukan."
                    nilai={formulir.data.NomorDasarHukum}
                    saatBerubah={(nilai) => formulir.setData('NomorDasarHukum', nilai)}
                    galat={formulir.errors.NomorDasarHukum}
                />
                <BidangTeks
                    label="Tautan dokumen (opsional)"
                    nilai={formulir.data.TautanDasarHukum}
                    saatBerubah={(nilai) => formulir.setData('TautanDasarHukum', nilai)}
                    galat={formulir.errors.TautanDasarHukum}
                />
                {cakupan === 'Daerah' ? (
                    <KotakCentang
                        label="Biaya layanan masuk dasar pengenaan pajak"
                        nilai={formulir.data.BiayaLayananMasukDpp}
                        saatBerubah={(nilai) => formulir.setData('BiayaLayananMasukDpp', nilai)}
                    />
                ) : null}
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

function FormTinjau({ tarif, saatSelesai }: { tarif: Tarif; saatSelesai: () => void }) {
    const formulir = useForm({ Keputusan: 'Setuju', Catatan: '' });

    const Kirim = (keputusan: 'Setuju' | 'Tolak') => {
        formulir.transform((data) => ({ ...data, Keputusan: keputusan }));
        formulir.post(`/referensi/tarif-pajak/${tarif.Uuid}/tinjau`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogTinjauan
            judul={`Tinjau ${tarif.NamaJenisPajak} ${FormatPersen(tarif.Tarif)}% mulai ${FormatTanggal(tarif.BerlakuMulai)}`}
            deskripsi={`Periksa tarif, pengali DPP ${tarif.PengaliDppPembilang}/${tarif.PengaliDppPenyebut}, tanggal berlaku, dan dasar hukum ${tarif.NomorDasarHukum ?? ''}. Setelah terbit, tarif tidak bisa diubah.`}
            saatTutup={saatSelesai}
            galatUmum={(formulir.errors as Record<string, string | undefined>).Umum}
            aksi={
                <>
                    <Tombol memproses={formulir.processing} onClick={() => Kirim('Setuju')}>
                        Setujui tarif
                    </Tombol>
                    <Tombol varian="bahaya" disabled={formulir.processing} onClick={() => Kirim('Tolak')}>
                        Tolak tarif
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
