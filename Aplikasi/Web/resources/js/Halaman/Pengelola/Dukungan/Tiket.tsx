import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import PercakapanTiket, { type PesanTiket } from '@/Komponen/Dukungan/PercakapanTiket';
import { jenisLabelStatusTiket, type BatasLampiran, type StatusTiket } from '@/Komponen/Dukungan/StatusTiket';
import BidangBerkas from '@/Komponen/Formulir/BidangBerkas';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type Pilihan, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type DetailTiket = {
    Uuid: string;
    Nomor: string;
    Judul: string;
    LabelKategori: string;
    Prioritas: string;
    LabelPrioritas: string;
    Status: StatusTiket;
    LabelStatus: string;
    Kanal: string;
    JamSla: number;
    SlaJamKalender: boolean;
    TingkatEskalasi: 'L1' | 'L2' | 'Teknis';
    LabelEskalasi: string;
    DieskalasiPada: string | null;
    BatasSlaPada: string;
    ResponsPertamaPada: string | null;
    LewatSla: boolean;
    BisaDibukaLagi: boolean;
    DibuatPada: string;
    Konteks: Record<string, string>;
    Tenant: { Nama: string; Slug: string } | null;
    Pelapor: { Nama: string; Email: string } | null;
    UuidPenanggungJawab: string | null;
    Pesan: PesanTiket[];
};

type PropsTiket = {
    Tiket: DetailTiket;
    Penangan: { Uuid: string; Nama: string }[];
    PilihanStatus: Pilihan[];
    PilihanPrioritas: Pilihan[];
    Lampiran: BatasLampiran;
};

type IsianBalasan = { Isi: string; CatatanInternal: boolean; Status: string; Lampiran: File[] };

const terbuka: StatusTiket[] = ['Baru', 'Ditangani', 'MenungguPelanggan'];

/** Penanganan satu tiket dukungan oleh tim internal (P-09). */
export default function TiketDukungan({ Tiket, Penangan, PilihanStatus, PilihanPrioritas, Lampiran }: PropsTiket) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehTangani = PunyaIzin(props.Pengguna, IzinPengelola.DukunganTiketTangani);
    const masihTerbuka = terbuka.includes(Tiket.Status);
    const alamat = `/dukungan/tiket/${Tiket.Uuid}`;
    const namaPenanggungJawab = Penangan.find((anggota) => anggota.Uuid === Tiket.UuidPenanggungJawab)?.Nama;

    return (
        <TataLetakPengelola judul={`${Tiket.Nomor} | ${Tiket.Judul}`}>
            <div className="flex flex-wrap items-center gap-2 text-label text-teks-sekunder">
                <Link href="/dukungan/tiket" className="font-semibold underline">
                    Antrean tiket
                </Link>
                <LabelStatus jenis={jenisLabelStatusTiket[Tiket.Status]} teks={Tiket.LabelStatus} />
                {Tiket.LewatSla ? <LabelStatus jenis="bahaya" teks="Lewat SLA" /> : null}
                {Tiket.TingkatEskalasi !== 'L1' ? (
                    <LabelStatus jenis="peringatan" teks={`Eskalasi ${Tiket.LabelEskalasi}`} />
                ) : null}
            </div>
            {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="flex flex-col gap-4 lg:col-span-2">
                    <PercakapanTiket pesan={Tiket.Pesan} tautanLampiran={(uuid) => `${alamat}/lampiran/${uuid}`} />
                    {bolehTangani ? (
                        <FormBalasan alamat={alamat} masihTerbuka={masihTerbuka} lampiran={Lampiran} />
                    ) : null}
                </div>

                <aside className="flex flex-col gap-4">
                    <Card className="gap-2 px-4 py-4 rounded-panel shadow-none">
                        <h2 className="text-subjudul font-semibold text-teks-utama">Rincian</h2>
                        <dl className="grid grid-cols-1 gap-2 text-label">
                            <Rincian
                                label="Tenant"
                                nilai={Tiket.Tenant ? `${Tiket.Tenant.Nama} (${Tiket.Tenant.Slug})` : '—'}
                            />
                            <Rincian
                                label="Pelapor"
                                nilai={Tiket.Pelapor ? `${Tiket.Pelapor.Nama} | ${Tiket.Pelapor.Email}` : '—'}
                            />
                            <Rincian label="Kategori" nilai={Tiket.LabelKategori} />
                            <Rincian label="Prioritas" nilai={Tiket.LabelPrioritas} />
                            <Rincian label="Kanal" nilai={Tiket.Kanal} />
                            <Rincian label="Dibuat" nilai={FormatTanggalWaktu(Tiket.DibuatPada)} />
                            <Rincian
                                label={`Batas respons pertama (SLA ${String(Tiket.JamSla)} ${Tiket.SlaJamKalender ? 'jam kalender' : 'jam kerja'})`}
                                nilai={FormatTanggalWaktu(Tiket.BatasSlaPada)}
                            />
                            <Rincian label="Respons pertama" nilai={FormatTanggalWaktu(Tiket.ResponsPertamaPada)} />
                            <Rincian label="Penanggung jawab" nilai={namaPenanggungJawab ?? 'Belum ada'} />
                            <Rincian
                                label="Tingkat penanganan"
                                nilai={
                                    Tiket.DieskalasiPada
                                        ? `${Tiket.LabelEskalasi} | sejak ${FormatTanggalWaktu(Tiket.DieskalasiPada)}`
                                        : Tiket.LabelEskalasi
                                }
                            />
                            {Object.entries(Tiket.Konteks).map(([kunci, nilai]) => (
                                <Rincian key={kunci} label={kunci} nilai={nilai} />
                            ))}
                        </dl>
                    </Card>
                    {bolehTangani ? (
                        <PanelAksi
                            tiket={Tiket}
                            alamat={alamat}
                            masihTerbuka={masihTerbuka}
                            penangan={Penangan}
                            pilihanStatus={PilihanStatus}
                            pilihanPrioritas={PilihanPrioritas}
                        />
                    ) : null}
                </aside>
            </div>
        </TataLetakPengelola>
    );
}

function Rincian({ label, nilai }: { label: string; nilai: string }) {
    return (
        <div className="flex flex-col">
            <dt className="text-teks-sekunder">{label}</dt>
            <dd className="break-words whitespace-pre-line text-teks-utama">{nilai}</dd>
        </div>
    );
}

function FormBalasan({
    alamat,
    masihTerbuka,
    lampiran,
}: {
    alamat: string;
    masihTerbuka: boolean;
    lampiran: BatasLampiran;
}) {
    const formulir = useForm<IsianBalasan>({ Isi: '', CatatanInternal: !masihTerbuka, Status: '', Lampiran: [] });
    const galatLampiran = Object.entries(formulir.errors).find(([kunci]) => kunci.startsWith('Lampiran'))?.[1];
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/balasan`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => formulir.reset(),
        });
    };

    return (
        <Card className="px-4 py-4 rounded-panel shadow-none">
            <form onSubmit={Kirim} className="flex flex-col gap-3" noValidate>
                {!masihTerbuka ? (
                    <Pemberitahuan jenis="info">
                        Tiket tidak terbuka. Hanya catatan internal yang bisa ditulis. Buka lagi tiket untuk membalas
                        tenant.
                    </Pemberitahuan>
                ) : null}
                <BidangTeksPanjang
                    label={formulir.data.CatatanInternal ? 'Catatan internal' : 'Balasan ke tenant'}
                    nilai={formulir.data.Isi}
                    maksimal={10000}
                    saatBerubah={(nilai) => formulir.setData('Isi', nilai)}
                    galat={formulir.errors.Isi}
                    required
                />
                <BidangBerkas
                    label="Lampiran"
                    berkas={formulir.data.Lampiran}
                    ekstensi={lampiran.Ekstensi}
                    maksimal={lampiran.Maksimal}
                    ukuranMaksimalKb={lampiran.UkuranMaksimalKb}
                    saatBerubah={(berkas) => formulir.setData('Lampiran', berkas)}
                    galat={galatLampiran}
                />
                <KotakCentang
                    label="Catatan internal (tidak terlihat tenant, tidak dikirim email)"
                    nilai={formulir.data.CatatanInternal}
                    saatBerubah={(nilai) =>
                        formulir.setData({ ...formulir.data, CatatanInternal: nilai || !masihTerbuka, Status: '' })
                    }
                />
                {!formulir.data.CatatanInternal ? (
                    <BidangPilihan
                        label="Status setelah dibalas"
                        nilai={formulir.data.Status}
                        kosong="Tetap (Baru menjadi Ditangani)"
                        opsi={[
                            { Nilai: 'Ditangani', Label: 'Ditangani' },
                            { Nilai: 'MenungguPelanggan', Label: 'Menunggu pelanggan' },
                            { Nilai: 'Selesai', Label: 'Selesai' },
                        ]}
                        saatBerubah={(nilai) => formulir.setData('Status', nilai)}
                        galat={formulir.errors.Status}
                    />
                ) : null}
                <div>
                    <Tombol type="submit" memproses={formulir.processing}>
                        {formulir.data.CatatanInternal ? 'Simpan catatan internal' : 'Kirim balasan ke tenant'}
                    </Tombol>
                </div>
            </form>
        </Card>
    );
}

type PropsPanelAksi = {
    tiket: DetailTiket;
    alamat: string;
    masihTerbuka: boolean;
    penangan: { Uuid: string; Nama: string }[];
    pilihanStatus: Pilihan[];
    pilihanPrioritas: Pilihan[];
};

function PanelAksi({ tiket, alamat, masihTerbuka, penangan, pilihanStatus, pilihanPrioritas }: PropsPanelAksi) {
    const { props } = usePage<PropsBersamaPengelola>();
    const [penanggungJawab, AturPenanggungJawab] = useState(tiket.UuidPenanggungJawab ?? '');
    const [status, AturStatus] = useState('');
    const [alasan, AturAlasan] = useState('');
    const [prioritas, AturPrioritas] = useState(tiket.Prioritas);
    const [tingkat, AturTingkat] = useState('');
    const [alasanEskalasi, AturAlasanEskalasi] = useState('');
    const [memproses, AturMemproses] = useState(false);
    // Eskalasi hanya naik: tujuan yang lebih tinggi dari tingkat sekarang.
    const tujuanEskalasi = [
        { Nilai: 'L2', Label: 'L2 | Dukungan senior' },
        { Nilai: 'Teknis', Label: 'Teknis' },
    ].filter((pilihan) => pilihan.Nilai === 'Teknis' || tiket.TingkatEskalasi === 'L1');
    const bisaDieskalasi = tiket.TingkatEskalasi !== 'Teknis';
    const opsi = { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) };
    const statusTujuan = pilihanStatus.filter(
        (pilihan) =>
            pilihan.Nilai !== tiket.Status &&
            pilihan.Nilai !== 'Baru' &&
            (masihTerbuka || pilihan.Nilai === 'Ditutup' || (pilihan.Nilai === 'Ditangani' && tiket.BisaDibukaLagi)),
    );

    return (
        <Card className="gap-4 px-4 py-4 rounded-panel shadow-none">
            <h2 className="text-subjudul font-semibold text-teks-utama">Tindakan</h2>
            {masihTerbuka ? (
                <div className="flex flex-col gap-2">
                    {tiket.UuidPenanggungJawab !== props.Pengguna?.Uuid ? (
                        <Tombol
                            varian="sekunder"
                            memproses={memproses}
                            onClick={() => router.post(`${alamat}/ambil`, {}, opsi)}
                        >
                            Ambil tiket ini
                        </Tombol>
                    ) : null}
                    <BidangPilihan
                        label="Tugaskan ke"
                        nilai={penanggungJawab}
                        kosong="Pilih anggota tim"
                        opsi={penangan.map((anggota) => ({ Nilai: anggota.Uuid, Label: anggota.Nama }))}
                        saatBerubah={AturPenanggungJawab}
                        galat={props.errors.UuidPenanggungJawab}
                        required
                    />
                    <Tombol
                        varian="sekunder"
                        memproses={memproses}
                        disabled={penanggungJawab === '' || penanggungJawab === tiket.UuidPenanggungJawab}
                        onClick={() =>
                            router.post(`${alamat}/tugaskan`, { UuidPenanggungJawab: penanggungJawab }, opsi)
                        }
                    >
                        Tugaskan
                    </Tombol>
                    <BidangPilihan
                        label="Prioritas"
                        nilai={prioritas}
                        opsi={pilihanPrioritas}
                        saatBerubah={AturPrioritas}
                        galat={props.errors.Prioritas}
                        required
                    />
                    <Tombol
                        varian="sekunder"
                        memproses={memproses}
                        disabled={prioritas === tiket.Prioritas}
                        onClick={() => router.put(`${alamat}/prioritas`, { Prioritas: prioritas }, opsi)}
                    >
                        Ubah prioritas
                    </Tombol>
                    {bisaDieskalasi ? (
                        <>
                            <BidangPilihan
                                label="Eskalasikan ke"
                                nilai={tingkat}
                                kosong="Pilih tingkat"
                                opsi={tujuanEskalasi}
                                saatBerubah={AturTingkat}
                                galat={props.errors.Tingkat}
                            />
                            <BidangTeks
                                label="Alasan eskalasi (wajib, internal)"
                                nilai={alasanEskalasi}
                                maxLength={500}
                                saatBerubah={AturAlasanEskalasi}
                                galat={props.errors.Alasan}
                            />
                            <Tombol
                                varian="sekunder"
                                memproses={memproses}
                                disabled={tingkat === '' || alasanEskalasi.trim().length < 5}
                                onClick={() =>
                                    router.post(
                                        `${alamat}/eskalasi`,
                                        { Tingkat: tingkat, Alasan: alasanEskalasi },
                                        {
                                            ...opsi,
                                            onSuccess: () => {
                                                AturTingkat('');
                                                AturAlasanEskalasi('');
                                            },
                                        },
                                    )
                                }
                            >
                                Eskalasikan tiket
                            </Tombol>
                        </>
                    ) : null}
                </div>
            ) : null}
            {statusTujuan.length > 0 ? (
                <div className="flex flex-col gap-2">
                    <BidangPilihan
                        label="Ubah status"
                        nilai={status}
                        kosong="Pilih status"
                        opsi={statusTujuan}
                        saatBerubah={AturStatus}
                        galat={props.errors.Status}
                        required
                    />
                    <BidangTeks
                        label={status === 'Ditutup' ? 'Alasan menutup (wajib)' : 'Alasan (opsional, internal)'}
                        nilai={alasan}
                        maxLength={500}
                        saatBerubah={AturAlasan}
                        galat={props.errors.Alasan}
                        required={status === 'Ditutup'}
                    />
                    <Tombol
                        varian={status === 'Ditutup' ? 'bahaya' : 'sekunder'}
                        memproses={memproses}
                        disabled={status === ''}
                        onClick={() =>
                            router.put(
                                `${alamat}/status`,
                                { Status: status, Alasan: alasan },
                                { ...opsi, onSuccess: () => AturAlasan('') },
                            )
                        }
                    >
                        Simpan status
                    </Tombol>
                </div>
            ) : (
                <p className="text-keterangan text-teks-sekunder">Tiket ditutup. Tidak ada perubahan status lagi.</p>
            )}
        </Card>
    );
}
