<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pengumuman extends Model
{
    // Nama tabel di database
    protected $table = 'pengumuman'; 

    // Field yang diizinkan untuk diisi (fillable)
    protected $fillable = [
        'kelas_id', 
        'judul', 
        'isi', 
        'tanggal_pengumuman', 
        'user_id', // ID Dosen/Admin yang membuat pengumuman
    ];

    // Kolom untuk tanggal
    protected $casts = [
        'tanggal_pengumuman' => 'date', 
    ];

    // Relasi ke Kelas (One-to-Many terbalik)
    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }
}