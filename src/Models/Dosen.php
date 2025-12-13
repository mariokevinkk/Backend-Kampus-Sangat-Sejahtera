<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dosen extends Model
{
    // Specify the table name if it's not following Laravel's default naming convention
    protected $table = 'dosens';  // Adjust table name if necessary

    // Define the fields that can be mass-assigned
    protected $fillable = [
        'nip', 'nama', 'alamat', 'no_telepon', 'user_id', 'fakultas_id', 'created_at', 'updated_at'
    ];

    // Relationships
    public function fakultas()
    {
        return $this->belongsTo(Fakultas::class, 'fakultas_id'); // Dosen belongs to Fakultas
    }

    // If Dosen can have many Mahasiswa (students), you can define it like so
    public function mahasiswa()
    {
        return $this->hasMany(Mahasiswa::class, 'dosen_id');
    }
    
}
