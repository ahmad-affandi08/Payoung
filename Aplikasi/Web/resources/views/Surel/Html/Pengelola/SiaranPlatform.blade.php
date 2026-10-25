@extends('Surel.TataLetak')

@section('Judul', $Judul)
@section('Pratinjau', $Paragraf[0] ?? $Judul)

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    @foreach ($Paragraf as $paragraf)
        <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">{!! nl2br(e($paragraf)) !!}</p>
    @endforeach
@endsection

@section('CatatanKaki', 'Pertanyaan? Balas email ini atau hubungi '.$EmailDukungan.'.')
