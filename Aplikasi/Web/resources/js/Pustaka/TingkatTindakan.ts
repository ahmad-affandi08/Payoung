import type { TingkatTindakan } from '@/Tipe/Tindakan';

/** Jenis label status untuk tingkat butir Kotak Tindakan (halaman /kelola/tindakan). */
export const JenisLabelTingkat: Record<TingkatTindakan, 'bahaya' | 'peringatan' | 'netral'> = {
    Penting: 'bahaya',
    Perhatian: 'peringatan',
    Info: 'netral',
};
