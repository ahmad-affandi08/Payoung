import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import CangkangWizard from '@/Komponen/Formulir/Wizard/CangkangWizard';
import { useAlurWizard } from '@/Komponen/Formulir/Wizard/useAlurWizard';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PanduanRingkasanGalat from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import {
    AmbilGalatLangkahKampanye,
    HitungLangkahKampanyeBergalat,
    langkahKampanye,
    PetakanGalatKampanyeKeLangkah,
    PeriksaLangkahKampanye,
    SapaanAwalKampanye,
    urutanLangkahKampanye,
    type KunciLangkahKampanye,
} from '@/Komponen/Pelanggan/LangkahKampanye';
import RingkasanKampanye from '@/Komponen/Pelanggan/RingkasanKampanye';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { KanalKampanye, PratinjauKampanye, PropsFormKampanye, SegmenKampanye } from '@/Tipe/Kampanye';

import { AlamatKampanye } from './Daftar';

/** Tunda nilai yang sering berubah (pratinjau dihitung server, jangan tiap ketukan). */
function useTunda<T>(nilai: T, ms: number): T {
    const [tertunda, AturTertunda] = useState(nilai);

    useEffect(() => {
        const pewaktu = setTimeout(() => AturTertunda(nilai), ms);

        return () => clearTimeout(pewaktu);
    }, [nilai, ms]);

    return tertunda;
}

/**
 * CRM-07 formulir draf kampanye: kanal, segmen penerima (RFM, tier, tag, ulang tahun bulan ini), dan isi pesan dengan
 * `{nama}`/`{toko}`. Jumlah penerima dipratinjau server (hanya yang setuju promosi & punya kontak kanal itu).
 */
export default function HalamanFormKampanye({
    Kampanye,
    OpsiKanal,
    OpsiRfm,
    OpsiTier,
    OpsiTag,
    KanalAktif,
    MaksIsi,
}: PropsFormKampanye) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [nama, AturNama] = useState(Kampanye?.Nama ?? '');
    // D-33: kampanye hanya lewat WhatsApp; draf email lama diubah ke WhatsApp saat disimpan.
    const [kanal, AturKanal] = useState<KanalKampanye>('Whatsapp');
    const [isi, AturIsi] = useState(Kampanye?.Isi ?? SapaanAwalKampanye);
    const [rfm, AturRfm] = useState<string[]>(Kampanye?.Segmen.Rfm ?? []);
    const [tier, AturTier] = useState<string[]>(Kampanye?.Segmen.UuidTier ?? []);
    const [tag, AturTag] = useState<string[]>(Kampanye?.Segmen.Tag ?? []);
    const [ulangTahun, AturUlangTahun] = useState(Kampanye?.Segmen.UlangTahunBulanIni ?? false);
    const [memproses, AturMemproses] = useState(false);
    // Buat kampanye berupa wizard bertahap; Ubah kampanye tetap satu halaman (satu perubahan cepat pada draf).
    const bertahap = Kampanye === null;
    const [percobaan, AturPercobaan] = useState<KunciLangkahKampanye[]>([]);
    const [galatServer, AturGalatServer] = useState<Record<string, string | undefined>>({});
    const galatKlien: Record<string, string> = Object.assign(
        {},
        ...percobaan.map((kunci) => PeriksaLangkahKampanye(kunci, { Nama: nama, Isi: isi }, MaksIsi)),
    );
    const galat: Record<string, string | undefined> = bertahap ? { ...galatServer, ...galatKlien } : props.errors;

    const PeriksaLangkah = (kunci: KunciLangkahKampanye): boolean => {
        AturPercobaan((lama) => (lama.includes(kunci) ? lama : [...lama, kunci]));
        const valid = Object.keys(PeriksaLangkahKampanye(kunci, { Nama: nama, Isi: isi }, MaksIsi)).length === 0;

        if (valid) {
            // Langkah ini sudah diperbaiki pengguna: galat server lamanya tidak perlu terus tampil.
            AturGalatServer((lama) =>
                Object.fromEntries(
                    Object.entries(lama).filter(([isiKunci]) => PetakanGalatKampanyeKeLangkah(isiKunci) !== kunci),
                ),
            );
        }

        return valid;
    };
    const kontrol = useAlurWizard(langkahKampanye, PeriksaLangkah, { awal: 'Kampanye', urutan: urutanLangkahKampanye });
    const segmen: SegmenKampanye = { Rfm: rfm, UuidTier: tier, Tag: tag, UlangTahunBulanIni: ulangTahun };
    const kunciSegmen = useTunda(JSON.stringify(segmen), 400);
    const judulHalaman = Kampanye ? `Ubah ${Kampanye.Nama}` : 'Buat kampanye';

    const pratinjau = useQuery({
        queryKey: KunciKueri.PratinjauKampanye(kanal, kunciSegmen),
        queryFn: () =>
            KirimJson<PratinjauKampanye>(`${AlamatKampanye}/pratinjau`, {
                Kanal: kanal,
                Segmen: JSON.parse(kunciSegmen) as SegmenKampanye,
            }),
        placeholderData: keepPreviousData,
    });

    const SusunData = () => ({ Nama: nama, Kanal: kanal, Judul: null, Isi: isi, Segmen: segmen });
    const opsiKirim = {
        preserveScroll: true,
        onStart: () => AturMemproses(true),
        onFinish: () => AturMemproses(false),
    };

    /** Ubah kampanye: satu halaman, galat dari server tampil di bawah isiannya. */
    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (Kampanye) {
            router.put(`${AlamatKampanye}/${Kampanye.Uuid}`, SusunData(), opsiKirim);
        }
    };

    /** Buat kampanye: semua langkah diperiksa ulang; galat server membuka langkah pertama yang memuatnya. */
    const SimpanBaru = () => {
        const langkahSalah = langkahKampanye.find((item) => item.Kunci !== 'Tinjau' && !PeriksaLangkah(item.Kunci));

        if (langkahSalah) {
            kontrol.Paksa(langkahSalah.Kunci);

            return;
        }

        AturGalatServer({});
        router.post(AlamatKampanye, SusunData(), {
            ...opsiKirim,
            onError: (galatBaru: Record<string, string | undefined>) => {
                AturGalatServer(galatBaru);
                const [pertama] = HitungLangkahKampanyeBergalat(galatBaru);

                if (pertama) {
                    kontrol.Paksa(pertama);
                }
            },
        });
    };

    const bidangKampanye = (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <BidangTeks label="Nama kampanye" nilai={nama} saatBerubah={AturNama} galat={galat.Nama} required />
                <BidangPilihan
                    label="Kanal"
                    nilai={kanal}
                    opsi={OpsiKanal.map((o) => ({
                        Nilai: o.Nilai,
                        Label: KanalAktif[o.Nilai as KanalKampanye] ? o.Label : `${o.Label} (belum aktif)`,
                    }))}
                    saatBerubah={(nilai) => AturKanal(nilai as KanalKampanye)}
                    galat={galat.Kanal}
                    required
                />
            </div>
            {!KanalAktif[kanal] ? (
                <Pemberitahuan jenis="peringatan">
                    Pengiriman WhatsApp belum aktif untuk usaha ini. Draf tetap bisa disimpan, tetapi belum bisa
                    dikirim.
                </Pemberitahuan>
            ) : null}
        </>
    );
    const bidangPenerima = (
        <>
            <GrupCentang
                legenda="Segmen belanja (RFM)"
                opsi={OpsiRfm.map((o) => ({
                    nilai: o.Nilai,
                    label: `${o.Label} (${String(pratinjau.data?.PerSegmen[o.Nilai as keyof PratinjauKampanye['PerSegmen']] ?? '…')})`,
                }))}
                terpilih={rfm}
                saatBerubah={AturRfm}
            />
            <ul className="grid gap-1 text-keterangan text-teks-sekunder sm:grid-cols-2">
                {OpsiRfm.map((o) => (
                    <li key={o.Nilai}>
                        <span className="font-semibold text-teks-utama">{o.Label}:</span> {o.Keterangan}
                    </li>
                ))}
            </ul>
            {OpsiTier.length > 0 ? (
                <GrupCentang
                    legenda="Tier"
                    opsi={OpsiTier.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                    terpilih={tier}
                    saatBerubah={AturTier}
                />
            ) : null}
            {OpsiTag.length > 0 ? (
                <GrupCentang
                    legenda="Tag (salah satu)"
                    opsi={OpsiTag.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                    terpilih={tag}
                    saatBerubah={AturTag}
                />
            ) : null}
            <KotakCentang label="Hanya yang berulang tahun bulan ini" nilai={ulangTahun} saatBerubah={AturUlangTahun} />
            <div role="status" aria-live="polite" className="text-isi text-teks-utama">
                {pratinjau.isError ? (
                    <span className="text-bahaya">Jumlah penerima belum bisa dihitung. Coba lagi.</span>
                ) : pratinjau.data ? (
                    <>
                        <span className="font-semibold tabular-nums">
                            {String(pratinjau.data.JumlahPenerima)} pelanggan
                        </span>{' '}
                        akan menerima pesan ini
                        {pratinjau.data.TanpaKontak > 0
                            ? ` (${String(pratinjau.data.TanpaKontak)} lainnya cocok tetapi tidak punya nomor WhatsApp yang sah).`
                            : '.'}
                        {pratinjau.data.JumlahPenerima > pratinjau.data.MaksPenerima
                            ? ` Paling banyak ${String(pratinjau.data.MaksPenerima)} per kampanye; persempit segmennya.`
                            : null}
                    </>
                ) : (
                    'Menghitung penerima…'
                )}
            </div>
        </>
    );
    const bidangPesan = (
        <BidangTeksPanjang
            label="Isi pesan"
            nilai={isi}
            saatBerubah={AturIsi}
            galat={galat.Isi}
            baris={6}
            maksimal={MaksIsi}
            keterangan="Tulis {nama} untuk nama pelanggan dan {toko} untuk nama usaha. Nama usaha dan tautan berhenti berlangganan ditambahkan otomatis di akhir pesan."
            required
        />
    );

    return (
        <TataLetakAplikasi judul={judulHalaman} jejak={[{ label: 'Kampanye pesan', href: AlamatKampanye }]}>
            <DaftarGalatServer galat={galat} kecuali={['Nama', 'Kanal', 'Judul', 'Isi']} />
            {bertahap ? (
                <CangkangWizard
                    kontrol={kontrol}
                    kunciTinjau="Tinjau"
                    labelNavigasi="Langkah buat kampanye"
                    teksSimpan="Simpan draf kampanye"
                    bergalat={HitungLangkahKampanyeBergalat(galat)}
                    pesanGalat={<PanduanRingkasanGalat galat={AmbilGalatLangkahKampanye(galat, kontrol.aktif)} />}
                    saatSimpan={SimpanBaru}
                    memproses={memproses}
                    bolehSimpan
                    tautanBatal={AlamatKampanye}
                    panel={{
                        Kampanye: bidangKampanye,
                        Penerima: bidangPenerima,
                        Pesan: bidangPesan,
                        Tinjau: (
                            <RingkasanKampanye
                                isian={{ Nama: nama, Isi: isi }}
                                kanal={kanal}
                                labelKanal={OpsiKanal.find((o) => o.Nilai === kanal)?.Label ?? kanal}
                                kanalAktif={KanalAktif[kanal]}
                                segmen={segmen}
                                opsiRfm={OpsiRfm}
                                opsiTier={OpsiTier}
                                opsiTag={OpsiTag}
                                pratinjau={pratinjau.data}
                                langkah={langkahKampanye}
                                saatUbah={(kunci) => kontrol.Lompat(kunci)}
                            />
                        ),
                    }}
                />
            ) : (
                <form onSubmit={Simpan} noValidate aria-label={judulHalaman} className="flex flex-col gap-4">
                    <Panel judul="Kampanye" idJudul="judul-kampanye">
                        {bidangKampanye}
                    </Panel>

                    <Panel
                        judul="Penerima"
                        idJudul="judul-penerima-kampanye"
                        keterangan="Hanya pelanggan aktif yang menyetujui kabar promosi. Filter yang dikosongkan berarti semua."
                    >
                        {bidangPenerima}
                    </Panel>

                    <Panel judul="Isi pesan" idJudul="judul-isi-kampanye">
                        {bidangPesan}
                    </Panel>

                    <BilahAksiForm>
                        <Tombol type="submit" memproses={memproses}>
                            Simpan draf
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => router.visit(AlamatKampanye)}>
                            Batal
                        </Tombol>
                    </BilahAksiForm>
                </form>
            )}
        </TataLetakAplikasi>
    );
}
