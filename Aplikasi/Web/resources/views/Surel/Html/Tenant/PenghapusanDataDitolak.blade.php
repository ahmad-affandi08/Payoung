@extends('Surel.TataLetak')

@section('Judul', 'Permintaan penghapusan data belum bisa kami proses')
@section('Pratinjau', 'Permintaan penghapusan data {{ $NamaUsaha }} belum bisa diproses. Akun Anda tetap berjalan.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Permintaan penghapusan data usaha <strong style="color:#1f3335;">{{ $NamaUsaha }}</strong> belum bisa kami proses. Akun dan data Anda tetap berjalan seperti biasa.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => ['Alasan' => $Alasan]])

    <p style="margin:0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Setelah hal di atas selesai, Anda bisa mengajukan permintaan lagi dari Pengaturan › Data saya.</p>
@endsection

@section('CatatanKaki', 'Pertanyaan? Balas email ini atau hubungi '.$EmailDukungan.'.')
