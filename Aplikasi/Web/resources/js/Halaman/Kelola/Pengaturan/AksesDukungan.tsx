import { useForm } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { IzinAksesDukungan, RiwayatAksesDukunganTenant } from '@/Tipe/TenantPengelola';

type Pilihan = { Nilai: string; Label: string };

type PropsAksesDukungan = {
    Riwayat: RiwayatAksesDukunganTenant;
    PilihanTiket: Pilihan[];
    Aturan: { DurasiJam: number[] };
};

type AktivitasSesi = {
    Waktu: string;
    Metode: string;
    Rute: string | null;
    Jalur: string;
    StatusHttp: number;
    Ditolak: boolean;
    Alasan: string | null;
}[];

const jenisStatus = { Berlaku: 'sukses', Berakhir: 'netral', Dicabut: 'bahaya' } as const;

const opsiCakupan: Pilihan[] = [
    { Nilai: 'BacaSaja', Label: 'Baca saja (petugas hanya melihat)' },
    { Nilai: 'BacaUbah', Label: 'Baca & ubah (petugas boleh membantu mengubah data)' },
];

async function AmbilAktivitas(uuid: string, sinyal: AbortSignal): Promise<AktivitasSesi> {
    const respons = await fetch(`/kelola/pengaturan/akses-dukungan/sesi/${uuid}/aktivitas`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Jejak sesi gagal dimuat (${String(respons.status)})`);
    }

    return ((await respons.json()) as { Data: AktivitasSesi }).Data;
}

/**
 * Pengaturan › Akses dukungan (PGL-16, P-09; khusus Owner): izin berbatas waktu bagi tim Payoung untuk membantu langsung
 * di akun ini. Owner menentukan durasi dan cakupan, boleh mencabut kapan saja, dan melihat jejak setiap sesi. Data pribadi
 * pelanggan tetap tersamar kecuali Owner membukanya dengan sadar.
 */
export default function HalamanAksesDukungan({ Riwayat, PilihanTiket, Aturan }: PropsAksesDukungan) {
    const [sesiDibuka, AturSesiDibuka] = useState<string | null>(null);
    const [konfirmasiCabut, AturKonfirmasiCabut] = useState<IzinAksesDukungan | null>(null);
    const aktif = Riwayat.Aktif;

    return (
        <TataLetakAplikasi judul="Akses dukungan">
            <div className="grid gap-4">
                <Pemberitahuan jenis="info" judul="Yang perlu Anda tahu">
                    Tim dukungan Payoung tidak bisa melihat atau mengubah akun Anda tanpa izin ini. Setiap izin berbatas
                    waktu, bisa dicabut kapan saja, dan setiap permintaan petugas tercatat di bawah. Kata sandi, PIN,
                    dan pengaturan pembayaran tidak pernah bisa dibuka petugas, dan data pribadi pelanggan tersamar
                    kecuali Anda membukanya.
                </Pemberitahuan>

                {aktif ? (
                    <Panel judul="Izin yang berlaku" tingkat="h2">
                        <div className="grid gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <LabelStatus jenis="sukses" teks="Berlaku" />
                                {aktif.Jenis === 'Darurat' ? <LabelStatus jenis="bahaya" teks="Akses darurat" /> : null}
                                <span className="text-isi font-semibold text-teks-utama">{aktif.LabelCakupan}</span>
                            </div>
                            <p className="text-isi text-teks-sekunder">
                                Berakhir {FormatTanggalWaktu(aktif.BerakhirPada)}
                                {aktif.DataPribadiTerbuka
                                    ? ' | data pribadi pelanggan terbuka'
                                    : ' | data pribadi pelanggan tersamar'}
                                {aktif.NomorTiket ? ` | tiket ${aktif.NomorTiket}` : ''}
                            </p>
                            {aktif.Alasan ? (
                                <p className="text-isi text-teks-sekunder">Catatan: {aktif.Alasan}</p>
                            ) : null}
                            {aktif.SesiBerjalan.length > 0 ? (
                                <Pemberitahuan jenis="peringatan">
                                    Sedang berjalan: {aktif.SesiBerjalan.map((sesi) => sesi.NamaPetugas).join(', ')}.
                                    Mencabut izin mengakhiri sesi ini seketika.
                                </Pemberitahuan>
                            ) : null}
                            <div>
                                <Tombol varian="bahaya" onClick={() => AturKonfirmasiCabut(aktif)}>
                                    Cabut izin sekarang
                                </Tombol>
                            </div>
                        </div>
                    </Panel>
                ) : (
                    <FormBeriIzin pilihanTiket={PilihanTiket} durasi={Aturan.DurasiJam} />
                )}

                <Panel judul="Riwayat izin" tingkat="h2">
                    {Riwayat.Izin.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">
                            Belum pernah ada izin akses. Izin yang Anda berikan dan akses darurat akan tercatat di sini.
                        </p>
                    ) : (
                        <ul className="grid gap-2" aria-label="Riwayat izin akses dukungan">
                            {Riwayat.Izin.map((izin) => (
                                <li
                                    key={izin.Uuid}
                                    className="flex flex-wrap items-center gap-2 text-isi text-teks-sekunder"
                                >
                                    <LabelStatus jenis={jenisStatus[izin.Status]} teks={izin.Status} />
                                    <span className="font-semibold text-teks-utama">{izin.LabelJenis}</span>
                                    <span>
                                        {izin.LabelCakupan} | {FormatTanggalWaktu(izin.MulaiPada)} sampai{' '}
                                        {FormatTanggalWaktu(izin.BerakhirPada)}
                                        {izin.NamaPemberi ? ` | diberi ${izin.NamaPemberi}` : ''}
                                        {izin.KategoriDarurat ? ` | ${izin.KategoriDarurat}` : ''}
                                    </span>
                                    {izin.Jenis === 'Darurat' && izin.Alasan ? (
                                        <span className="basis-full">Alasan tim Payoung: {izin.Alasan}</span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel judul="Sesi petugas" tingkat="h2">
                    {Riwayat.Sesi.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">Belum ada petugas yang masuk.</p>
                    ) : (
                        <ul className="grid gap-2" aria-label="Sesi petugas dukungan">
                            {Riwayat.Sesi.map((sesi) => (
                                <li
                                    key={sesi.Uuid}
                                    className="flex flex-wrap items-center gap-2 text-isi text-teks-sekunder"
                                >
                                    <span className="font-semibold text-teks-utama">{sesi.NamaPetugas}</span>
                                    <span>
                                        {FormatTanggalWaktu(sesi.MulaiPada)} |{' '}
                                        {sesi.Berjalan ? 'sedang berjalan' : (sesi.AlasanAkhir ?? 'berakhir')} |{' '}
                                        {sesi.JumlahAktivitas} permintaan, {sesi.JumlahUbah} mengubah data,{' '}
                                        {sesi.JumlahDitolak} ditolak
                                    </span>
                                    <Tombol varian="sekunder" onClick={() => AturSesiDibuka(sesi.Uuid)}>
                                        Lihat jejak
                                    </Tombol>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            {konfirmasiCabut ? (
                <FormCabut izin={konfirmasiCabut} saatSelesai={() => AturKonfirmasiCabut(null)} />
            ) : null}
            {sesiDibuka ? <DialogJejak uuidSesi={sesiDibuka} saatTutup={() => AturSesiDibuka(null)} /> : null}
        </TataLetakAplikasi>
    );
}

function FormBeriIzin({ pilihanTiket, durasi }: { pilihanTiket: Pilihan[]; durasi: number[] }) {
    const formulir = useForm({
        DurasiJam: String(durasi[0] ?? 1),
        Cakupan: 'BacaSaja',
        DataPribadiTerbuka: false,
        UuidTiket: '',
        Catatan: '',
    });
    const galat = formulir.errors as Partial<Record<string, string>>;
    const opsiDurasi = durasi.map((jam) => ({ Nilai: String(jam), Label: jam === 1 ? '1 jam' : `${String(jam)} jam` }));
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/pengaturan/akses-dukungan', { preserveScroll: true, onSuccess: () => formulir.reset() });
    };

    return (
        <Panel
            judul="Beri izin akses"
            tingkat="h2"
            keterangan="Hanya satu izin yang berlaku pada satu waktu. Setelah waktunya habis, akses petugas otomatis berhenti."
        >
            <form onSubmit={Kirim} noValidate className="grid gap-4">
                <BidangPilihan
                    label="Berlaku selama"
                    nilai={formulir.data.DurasiJam}
                    opsi={opsiDurasi}
                    saatBerubah={(nilai) => formulir.setData('DurasiJam', nilai)}
                    galat={galat.DurasiJam}
                    required
                />
                <BidangPilihan
                    label="Cakupan"
                    nilai={formulir.data.Cakupan}
                    opsi={opsiCakupan}
                    saatBerubah={(nilai) => formulir.setData('Cakupan', nilai)}
                    galat={galat.Cakupan}
                    required
                />
                {pilihanTiket.length > 0 ? (
                    <BidangPilihan
                        label="Terkait tiket dukungan"
                        nilai={formulir.data.UuidTiket}
                        opsi={pilihanTiket}
                        kosong="Tanpa tiket"
                        saatBerubah={(nilai) => formulir.setData('UuidTiket', nilai)}
                        galat={galat.UuidTiket}
                    />
                ) : null}
                <BidangTeksPanjang
                    label="Catatan untuk petugas"
                    keterangan="Opsional. Misal: tolong bantu rapikan produk atau cek selisih stok."
                    nilai={formulir.data.Catatan}
                    baris={3}
                    maksimal={500}
                    saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                    galat={galat.Catatan}
                />
                <div className="grid gap-1">
                    <KotakCentang
                        label="Buka data pribadi pelanggan (nomor HP, email, alamat)"
                        nilai={formulir.data.DataPribadiTerbuka}
                        saatBerubah={(nilai) => formulir.setData('DataPribadiTerbuka', nilai)}
                    />
                    <p className="text-keterangan text-teks-sekunder">
                        Biarkan tidak dicentang kecuali petugas memang perlu melihatnya untuk membantu Anda.
                    </p>
                </div>
                {galat.Umum ? <Pemberitahuan jenis="bahaya">{galat.Umum}</Pemberitahuan> : null}
                <div>
                    <Tombol type="submit" memproses={formulir.processing}>
                        Beri izin akses
                    </Tombol>
                </div>
            </form>
        </Panel>
    );
}

function FormCabut({ izin, saatSelesai }: { izin: IzinAksesDukungan; saatSelesai: () => void }) {
    const formulir = useForm({});
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/kelola/pengaturan/akses-dukungan/${izin.Uuid}/cabut`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    return (
        <DialogFormulir
            jenis="konfirmasi"
            judul="Cabut izin akses dukungan?"
            keterangan={<p>Petugas yang sedang masuk langsung keluar dan tidak bisa masuk lagi dengan izin ini.</p>}
            galatUmum={(formulir.errors as Partial<Record<string, string>>).Umum}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} noValidate className="flex flex-wrap gap-2">
                <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                    Cabut izin
                </Tombol>
                <Tombol varian="sekunder" onClick={saatSelesai}>
                    Batal
                </Tombol>
            </form>
        </DialogFormulir>
    );
}

function DialogJejak({ uuidSesi, saatTutup }: { uuidSesi: string; saatTutup: () => void }) {
    const kueri = useQuery({
        queryKey: KunciKueri.Dukungan.AktivitasSesi(uuidSesi),
        queryFn: ({ signal }) => AmbilAktivitas(uuidSesi, signal),
    });

    return (
        <DialogFormulir
            judul="Jejak sesi petugas"
            keterangan={<p>Permintaan terbaru lebih dulu (paling banyak 200). Isi data tidak disimpan di jejak ini.</p>}
            lebar="lebar"
            saatTutup={saatTutup}
        >
            {kueri.isPending ? <p className="text-isi text-teks-sekunder">Memuat jejak…</p> : null}
            {kueri.isError ? (
                <Pemberitahuan jenis="bahaya">Jejak sesi gagal dimuat. Tutup lalu coba lagi.</Pemberitahuan>
            ) : null}
            {kueri.data && kueri.data.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Petugas belum membuka halaman apa pun.</p>
            ) : null}
            {kueri.data && kueri.data.length > 0 ? (
                <ul className="grid max-h-96 gap-1 overflow-y-auto" aria-label="Jejak permintaan petugas">
                    {kueri.data.map((baris, indeks) => (
                        <li key={`${baris.Waktu}-${String(indeks)}`} className="text-keterangan text-teks-sekunder">
                            {FormatTanggalWaktu(baris.Waktu)} |{' '}
                            <span className="font-mono">
                                {baris.Metode} {baris.Jalur}
                            </span>{' '}
                            | {baris.StatusHttp}
                            {baris.Ditolak ? (
                                <>
                                    {' '}
                                    <LabelStatus jenis="bahaya" teks="Ditolak" />
                                    {baris.Alasan ? ` ${baris.Alasan}` : ''}
                                </>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
            <div>
                <Tombol varian="sekunder" onClick={saatTutup}>
                    Tutup
                </Tombol>
            </div>
        </DialogFormulir>
    );
}
