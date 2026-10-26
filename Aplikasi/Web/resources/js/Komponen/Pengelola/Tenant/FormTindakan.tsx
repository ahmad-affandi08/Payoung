import { useForm } from '@inertiajs/react';
import { useId, type FormEvent, type ReactNode } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { Pilihan } from '@/Tipe/Pengelola';
import { labelBatas, type AturanTenant, type LanggananTenant } from '@/Tipe/TenantPengelola';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';

type PropsKerangka = {
    judul: string;
    keterangan: ReactNode;
    labelKirim: string;
    varianKirim?: 'utama' | 'bahaya';
    memproses: boolean;
    galatUmum: string | undefined;
    saatKirim: (peristiwa: FormEvent) => void;
    saatBatal: () => void;
    children: ReactNode;
};

/**
 * Kerangka formulir tindakan dalam dialog: judul, penjelasan akibat, galat aturan bisnis, tombol kirim & batal.
 * Tindakan berisiko (varian bahaya: tangguhkan, cabut override) memakai AlertDialog.
 */
export function KerangkaForm({
    judul,
    keterangan,
    labelKirim,
    varianKirim = 'utama',
    memproses,
    galatUmum,
    saatKirim,
    saatBatal,
    children,
}: PropsKerangka) {
    return (
        <DialogFormulir
            jenis={varianKirim === 'bahaya' ? 'konfirmasi' : 'dialog'}
            judul={judul}
            keterangan={keterangan}
            saatTutup={saatBatal}
        >
            <form onSubmit={saatKirim} noValidate className="flex flex-col gap-4">
                {galatUmum ? <Pemberitahuan jenis="bahaya">{galatUmum}</Pemberitahuan> : null}
                {children}
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" varian={varianKirim} memproses={memproses}>
                        {labelKirim}
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatBatal}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

/** Pelanggaran aturan bisnis dari server dikirim di kunci `Umum` (PelanggaranAturanBisnis). */
export function AmbilGalatUmum(galat: Partial<Record<string, string>>): string | undefined {
    return galat.Umum;
}

type PropsDasar = { uuid: string; saatSelesai: () => void };

export function FormPerpanjangTrial({
    uuid,
    langganan,
    aturan,
    saatSelesai,
}: PropsDasar & { langganan: LanggananTenant; aturan: AturanTenant }) {
    const formulir = useForm({ Hari: '7', Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuid}/trial/perpanjang`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Perpanjang trial"
            keterangan={
                <p>
                    Trial sekarang berakhir {FormatTanggalWaktu(langganan.TrialBerakhirPada)}. Maksimal{' '}
                    {aturan.MaksHariTrial} hari per perpanjangan; sisa {langganan.SisaPerpanjanganTrial} dari{' '}
                    {aturan.MaksKaliTrial} kali.
                </p>
            }
            labelKirim="Perpanjang trial"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeks
                label="Tambahan hari"
                inputMode="numeric"
                nilai={formulir.data.Hari}
                saatBerubah={(nilai) => formulir.setData('Hari', nilai)}
                galat={formulir.errors.Hari}
                required
            />
            <BidangTeksPanjang
                label="Alasan"
                keterangan="Misal: Owner masih menunggu printer struk datang, butuh uji coba 1 minggu lagi."
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}

export function FormOverride({
    uuid,
    pilihanFitur,
    kolomBatas,
    aturan,
    saatSelesai,
}: PropsDasar & { pilihanFitur: Pilihan[]; kolomBatas: string[]; aturan: AturanTenant }) {
    const idTanggal = useId();
    const formulir = useForm({ Jenis: 'Batas', Kunci: kolomBatas[0] ?? '', Nilai: '', BerakhirPada: '', Alasan: '' });
    const opsiKunci =
        formulir.data.Jenis === 'Batas'
            ? kolomBatas.map((kolom) => ({ Nilai: kolom, Label: labelBatas[kolom] ?? kolom }))
            : pilihanFitur;
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuid}/override`, { preserveScroll: true, onSuccess: saatSelesai });
    };
    const GantiJenis = (jenis: string) => {
        formulir.setData({
            ...formulir.data,
            Jenis: jenis,
            Kunci: jenis === 'Batas' ? (kolomBatas[0] ?? '') : (pilihanFitur[0]?.Nilai ?? ''),
            Nilai: '',
        });
    };

    return (
        <KerangkaForm
            judul="Override sementara"
            keterangan={
                <p>
                    Menimpa batas paket atau membuka fitur di luar paket sampai tanggal berakhir (paling lama{' '}
                    {aturan.MaksHariOverride} hari), lalu kembali otomatis ke isi paket.
                </p>
            }
            labelKirim="Simpan override"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <BidangPilihan
                    label="Jenis"
                    nilai={formulir.data.Jenis}
                    opsi={[
                        { Nilai: 'Batas', Label: 'Batas (angka)' },
                        { Nilai: 'Fitur', Label: 'Fitur' },
                    ]}
                    saatBerubah={GantiJenis}
                    galat={formulir.errors.Jenis}
                    required
                />
                {opsiKunci.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Katalog fitur masih kosong. Isi dulu di menu Katalog.</p>
                ) : (
                    <BidangPilihan
                        label={formulir.data.Jenis === 'Batas' ? 'Batas' : 'Fitur'}
                        nilai={formulir.data.Kunci}
                        opsi={opsiKunci}
                        saatBerubah={(nilai) => formulir.setData('Kunci', nilai)}
                        galat={formulir.errors.Kunci}
                        required
                    />
                )}
                {formulir.data.Jenis === 'Batas' ? (
                    <BidangTeks
                        label="Batas selama override"
                        inputMode="numeric"
                        nilai={formulir.data.Nilai}
                        saatBerubah={(nilai) => formulir.setData('Nilai', nilai)}
                        galat={formulir.errors.Nilai}
                        required
                    />
                ) : null}
                <PemilihTanggal
                    id={idTanggal}
                    label="Berlaku sampai (akhir hari, WIB)"
                    nilai={formulir.data.BerakhirPada}
                    saatBerubah={(nilai) => formulir.setData('BerakhirPada', nilai)}
                    galat={formulir.errors.BerakhirPada}
                    required
                />
            </div>
            <BidangTeksPanjang
                label="Alasan"
                keterangan="Misal: pembukaan cabang ke-3 sambil menunggu proses upgrade paket."
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}

export function FormTangguhkan({ uuid, pilihanKategori, saatSelesai }: PropsDasar & { pilihanKategori: Pilihan[] }) {
    const formulir = useForm({ Kategori: pilihanKategori[0]?.Nilai ?? '', Catatan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuid}/tangguhkan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Tangguhkan tenant"
            keterangan={
                <p>
                    Owner hanya bisa masuk, melihat laporan, mengekspor data, dan membayar tagihan. Aplikasi kasir
                    terkunci. Tidak ada data yang dihapus. Owner menerima email berisi kategori alasan, bukan catatan
                    internal.
                </p>
            }
            labelKirim="Tangguhkan tenant"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangPilihan
                label="Kategori alasan"
                nilai={formulir.data.Kategori}
                opsi={pilihanKategori}
                saatBerubah={(nilai) => formulir.setData('Kategori', nilai)}
                galat={formulir.errors.Kategori}
                required
            />
            <BidangTeksPanjang
                label="Catatan internal"
                keterangan="Apa yang terjadi dan dasar keputusannya, misal nomor surat permintaan hukum."
                nilai={formulir.data.Catatan}
                baris={3}
                maksimal={450}
                saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                galat={formulir.errors.Catatan}
                required
            />
        </KerangkaForm>
    );
}

export function FormAktifkan({ uuid, statusTujuan, saatSelesai }: PropsDasar & { statusTujuan: string | null }) {
    const formulir = useForm({ Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuid}/aktifkan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Aktifkan kembali"
            keterangan={
                statusTujuan === 'Ditangguhkan' ? (
                    <p>
                        Penangguhan manual dicabut, tetapi tenant <strong>tetap ditangguhkan</strong> karena tagihannya
                        sudah lewat masa tenggang. Tenant aktif kembali saat pembayarannya diterima. Tulis keputusan
                        tertulisnya, misal hasil investigasi.
                    </p>
                ) : (
                    <p>
                        Status langganan kembali menjadi <strong>{statusTujuan ?? 'Aktif'}</strong>. Tulis keputusan
                        tertulisnya, misal tagihan sudah lunas atau hasil investigasi.
                    </p>
                )
            }
            labelKirim="Aktifkan kembali"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeksPanjang
                label="Keputusan tertulis"
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}

export function FormPenanda({
    uuid,
    penandaSekarang,
    pilihanPenanda,
    saatSelesai,
}: PropsDasar & { penandaSekarang: string | null; pilihanPenanda: Pilihan[] }) {
    const formulir = useForm({ Penanda: penandaSekarang ?? '', Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(`/tenant/${uuid}/penanda`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Ubah penanda"
            keterangan={<p>Tenant Uji, Demo, dan Internal dikecualikan dari metrik bisnis dan tagihan.</p>}
            labelKirim="Simpan penanda"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangPilihan
                label="Penanda"
                nilai={formulir.data.Penanda}
                opsi={pilihanPenanda}
                kosong="Tanpa penanda (tenant biasa)"
                saatBerubah={(nilai) => formulir.setData('Penanda', nilai)}
                galat={formulir.errors.Penanda}
            />
            <BidangTeksPanjang
                label="Alasan"
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}

/**
 * Hapus permanen tenant uji (D-213). Hanya ditawarkan untuk tenant berpenanda Uji/Demo/Internal; server tetap memeriksa
 * penanda, tagihan berbayar, dan slug. Mengetik slug adalah konfirmasi yang disengaja karena tidak bisa dibalik.
 */
export function FormHapusPermanen({ uuid, nama, slug, saatSelesai }: PropsDasar & { nama: string; slug: string }) {
    const formulir = useForm({ KonfirmasiSlug: '', Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.delete(`/tenant/${uuid}/hapus-permanen`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul={`Hapus permanen ${nama}`}
            keterangan={
                <div className="flex flex-col gap-2">
                    <p>
                        Tenant uji ini dihapus <strong>beserta semua datanya</strong>: pengguna dan akunnya, outlet,
                        produk, transaksi, jurnal, tagihan, perangkat, dan berkas. Tidak bisa dibatalkan.
                    </p>
                    <p>Catatan siapa yang menghapus dan alasannya tetap tersimpan di log audit konsol.</p>
                </div>
            }
            labelKirim="Hapus permanen"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeksPanjang
                label="Alasan"
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
            <BidangTeks
                label={`Ketik slug "${slug}" untuk memastikan`}
                nilai={formulir.data.KonfirmasiSlug}
                saatBerubah={(nilai) => formulir.setData('KonfirmasiSlug', nilai)}
                galat={formulir.errors.KonfirmasiSlug}
                autoComplete="off"
                required
            />
        </KerangkaForm>
    );
}

export function FormCabutOverride({
    uuid,
    uuidOverride,
    kunci,
    saatSelesai,
}: PropsDasar & { uuidOverride: string; kunci: string }) {
    const formulir = useForm({ Alasan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuid}/override/${uuidOverride}/cabut`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    return (
        <KerangkaForm
            judul={`Cabut override ${kunci}`}
            keterangan={<p>Override berakhir sekarang dan tenant kembali memakai isi paketnya.</p>}
            labelKirim="Cabut override"
            varianKirim="bahaya"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangTeksPanjang
                label="Alasan"
                nilai={formulir.data.Alasan}
                baris={3}
                maksimal={500}
                saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                galat={formulir.errors.Alasan}
                required
            />
        </KerangkaForm>
    );
}

export function FormCatatan({ uuid }: { uuid: string }) {
    const formulir = useForm({ Isi: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuid}/catatan`, { preserveScroll: true, onSuccess: () => formulir.reset() });
    };

    return (
        <form onSubmit={Kirim} noValidate className="flex flex-col gap-3">
            <BidangTeksPanjang
                label="Catatan baru"
                keterangan="Hanya terlihat oleh tim internal. Catatan tidak bisa diubah atau dihapus."
                nilai={formulir.data.Isi}
                baris={3}
                maksimal={2000}
                saatBerubah={(nilai) => formulir.setData('Isi', nilai)}
                galat={formulir.errors.Isi ?? AmbilGalatUmum(formulir.errors)}
                required
            />
            <div>
                <Tombol type="submit" varian="sekunder" memproses={formulir.processing}>
                    Simpan catatan
                </Tombol>
            </div>
        </form>
    );
}

const OPSI_SIKLUS_TAGIHAN = [
    { Nilai: 'Bulanan', Label: 'Bulanan' },
    { Nilai: 'Tahunan', Label: 'Tahunan' },
];

/** D-112: Keuangan menerbitkan tagihan manual untuk paket harga negosiasi (mis. Enterprise). */
export function FormTagihanManual({ uuid, paket, saatSelesai }: PropsDasar & { paket: Pilihan[] }) {
    const formulir = useForm({ KodePaket: paket[0]?.Nilai ?? '', Siklus: 'Tahunan', Subtotal: '', Keterangan: '' });
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`/tenant/${uuid}/tagihan-manual`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul="Terbitkan tagihan manual"
            keterangan={
                <p>
                    Untuk paket harga negosiasi. Isi harga kesepakatan <strong>sebelum PPN</strong> untuk satu siklus;
                    PPN dan nomor tagihan dihitung otomatis. Pemilik usaha membayar dengan transfer dan mengunggah
                    buktinya, atau Anda mencatat pembayaran yang sudah masuk dari halaman tagihan.
                </p>
            }
            labelKirim="Terbitkan tagihan"
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors)}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            <BidangPilihan
                label="Paket"
                nilai={formulir.data.KodePaket}
                opsi={paket}
                saatBerubah={(nilai) => formulir.setData('KodePaket', nilai)}
                galat={formulir.errors.KodePaket}
                required
            />
            <BidangPilihan
                label="Siklus tagihan"
                nilai={formulir.data.Siklus}
                opsi={OPSI_SIKLUS_TAGIHAN}
                saatBerubah={(nilai) => formulir.setData('Siklus', nilai)}
                galat={formulir.errors.Siklus}
                required
            />
            <BidangUang
                label="Harga kesepakatan (sebelum PPN)"
                nilai={formulir.data.Subtotal}
                saatBerubah={(nilai) => formulir.setData('Subtotal', nilai)}
                galat={formulir.errors.Subtotal}
                required
            />
            <BidangTeksPanjang
                label="Keterangan kontrak"
                nilai={formulir.data.Keterangan}
                baris={3}
                maksimal={300}
                saatBerubah={(nilai) => formulir.setData('Keterangan', nilai)}
                galat={formulir.errors.Keterangan}
                keterangan="Tampil di tagihan, misal nomor kontrak atau cakupan layanan."
                required
            />
        </KerangkaForm>
    );
}
