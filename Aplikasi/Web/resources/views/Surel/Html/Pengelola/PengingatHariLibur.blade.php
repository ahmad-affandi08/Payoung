@extends('Surel.TataLetak')

@section('Judul', 'Hari libur '.$Tahun.' belum terbit')
@section('Pratinjau', $Terlambat ? 'Sudah lewat batas 1 Desember.' : 'Batasnya 1 Desember.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Hari libur nasional &amp; cuti bersama tahun <strong style="color:#1f3335;">{{ $Tahun }}</strong> belum terbit.
        @if ($Terlambat)
            <strong style="color:#1f3335;">Batasnya 1 Desember dan sudah lewat.</strong>
        @else
            Batasnya 1 Desember.
        @endif
    </p>

    <p style="margin:0 0 16px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Masukkan datanya di Platform Pengelola &rsaquo; Referensi &rsaquo; Hari libur, lalu ajukan untuk ditinjau.</p>

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Data ini dipakai tenant untuk jadwal kerja, forecast restock, dan laporan.</p>
@endsection
