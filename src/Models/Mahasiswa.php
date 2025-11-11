<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mahasiswa extends Model
{
//  use HasFactory;

    // Menambahkan kolom yang dapat diisi (mass assignment)
    protected $fillable = [
        'nim',
        'nama',
        'prodi',
        'alamat',
        'no_telepon',
        'fakultas_id',
        'user_id' // Jangan lupa untuk menambahkan user_id jika perlu
    ];

    // Relasi dengan tabel users
    public function user()
    {
        return $this->belongsTo(User::class);
    }
     public function fakultas()
    {
        return $this->belongsTo(Fakultas::class, 'fakultas_id');
    }
}
