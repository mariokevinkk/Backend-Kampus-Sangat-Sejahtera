<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';  // Adjust the table name if necessary
    protected $fillable = ['email', 'password', 'role'];

    public function mahasiswa()
    {
        return $this->hasOne(Mahasiswa::class); // Assuming a one-to-one relationship for 'mahasiswa'
    }

    public function dosen()
    {
        return $this->hasOne(Dosen::class); // Assuming a one-to-one relationship for 'dosen'
    }
}
